// POST /api/orders/operator — CreateOrderByOperatorAsync
// Поддерживает промежуточные адреса, «туда и обратно», подъезд назначения
// и предзаказ — общий расчёт computeOrderRoutePricing (как клиентский POST)
import { NextResponse } from "next/server";
import { db } from "@/db";
import { orders } from "@/db/schema";
import { generateOrderNumber, geocodeAddress } from "@/lib/taxi";
import { serializeOrder } from "@/lib/serialize";
import { normalizePhone } from "@/lib/auth";
import { ensureSeeded } from "@/lib/seed";
import { getServiceBrand } from "@/lib/branding";
import { publishEvent } from "@/lib/bus";
import { readClaims, forbidden, hasAdminRole } from "@/lib/session";
import { orderOptions, routePoints } from "@/db/schema";
import { resolveOptions, optionsTotal } from "@/lib/options";
import { computeOrderRoutePricing } from "@/lib/order-pricing";
import {
  parseRoundTrip,
  parseScheduledAt,
  resolveStopPoints,
} from "@/lib/stops";

export async function POST(req: Request) {
  try {
    await ensureSeeded();
    const service = await getServiceBrand();

    // Авторизация: заказы по телефону создают только оператор и админ
    const claims = readClaims(req);
    if (!claims || (claims.role !== "operator" && !hasAdminRole(claims.role))) {
      return forbidden("Создание операторского заказа доступно только диспетчерской");
    }

    const body = await req.json();
    const operatorId = String(body.operatorId ?? claims.uid);
    const clientPhone = normalizePhone(String(body.clientPhone ?? ""));
    const clientName = String(body.clientName ?? "").trim();
    const pickupAddress = String(body.pickupAddress ?? "").trim();
    if (!clientPhone || !pickupAddress)
      return NextResponse.json(
        { error: "Телефон клиента и адрес подачи обязательны" },
        { status: 400 }
      );

    let pickupLat = Number(body.pickupLatitude);
    let pickupLng = Number(body.pickupLongitude);
    if (!Number.isFinite(pickupLat) || !Number.isFinite(pickupLng)) {
      const g = geocodeAddress(pickupAddress, service.centerLat, service.centerLng);
      pickupLat = g.lat;
      pickupLng = g.lng;
    }

    const destinationAddress = body.destinationAddress
      ? String(body.destinationAddress).trim()
      : null;
    let destLat = Number(body.destinationLatitude);
    let destLng = Number(body.destinationLongitude);
    if (destinationAddress && (!Number.isFinite(destLat) || !Number.isFinite(destLng))) {
      const g = geocodeAddress(destinationAddress, service.centerLat, service.centerLng);
      destLat = g.lat;
      destLng = g.lng;
    }

    const tariff = String(body.tariff ?? "economy");

    // Промежуточные адреса, «туда и обратно», предзаказ, подъезд назначения
    const stops = resolveStopPoints(body.intermediatePoints, service.centerLat, service.centerLng);
    const roundTrip = parseRoundTrip(body.roundTrip);
    const scheduledAt = parseScheduledAt(body.scheduledAt);
    const isPreorder = body.isPreorder === true || scheduledAt !== null;
    const destinationEntrance = body.destinationEntrance
      ? String(body.destinationEntrance).slice(0, 20)
      : null;

    // Общий расчёт (как в клиентском POST): маршрут через точки, зоны, наценки
    const pr = await computeOrderRoutePricing({
      pickupLat,
      pickupLng,
      destLat: destinationAddress && Number.isFinite(destLat) ? destLat : null,
      destLng: destinationAddress && Number.isFinite(destLng) ? destLng : null,
      destinationAddress,
      stops,
      roundTrip,
      isPreorder,
      tariff,
      utcOffset: service.utcOffset,
    });
    let estimatedPrice = pr.estimatedPrice;

    // Опции заказа
    const optionCodes: string[] = Array.isArray(body.options)
      ? body.options.filter((x: unknown): x is string => typeof x === "string")
      : [];
    const chosenOptions = resolveOptions(optionCodes);
    estimatedPrice += optionsTotal(optionCodes);

    const [order] = await db
      .insert(orders)
      .values({
        orderNumber: generateOrderNumber(),
        operatorId: operatorId || null,
        source: "operator_app",
        clientPhone,
        clientName: clientName || "Клиент",
        pickupAddress,
        pickupLatitude: pickupLat,
        pickupLongitude: pickupLng,
        pickupEntrance: body.pickupEntrance ?? null,
        destinationAddress,
        destinationEntrance,
        destinationLatitude: Number.isFinite(destLat) ? destLat : null,
        destinationLongitude: Number.isFinite(destLng) ? destLng : null,
        roundTrip,
        tariff: tariff as never,
        estimatedPrice,
        estimatedDistance: pr.estimatedDistance,
        estimatedDuration: pr.estimatedDuration,
        routeGeometry: pr.routeGeometry,
        pricingMode: pr.pricingMode,
        fromZoneId: pr.fromZoneId,
        toZoneId: pr.toZoneId,
        stopsSurcharge: pr.stopsSurcharge,
        scheduledAt,
        preorderSurcharge: pr.preorderSurcharge,
        comment: body.comment ?? null,
        passengerCount: Number(body.passengerCount ?? 1) || 1,
        status: "searching",
      })
      .returning();

    if (chosenOptions.length > 0) {
      await db.insert(orderOptions).values(
        chosenOptions.map((o) => ({
          orderId: order.id,
          code: o.code,
          name: o.name,
          price: o.price,
        }))
      );
    }

    // Промежуточные точки маршрута (RoutePoint.cs)
    if (stops.length > 0) {
      await db.insert(routePoints).values(
        stops.map((s, index) => ({
          orderId: order.id,
          address: s.address,
          latitude: s.latitude,
          longitude: s.longitude,
          sortOrder: index,
        }))
      );
    }

    publishEvent("orders");
    return NextResponse.json(await serializeOrder(order), { status: 201 });
  } catch (e) {
    return NextResponse.json(
      { error: e instanceof Error ? e.message : "Не удалось создать заказ" },
      { status: 500 }
    );
  }
}
