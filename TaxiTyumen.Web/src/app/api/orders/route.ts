// POST /api/orders — CreateOrderAsync | GET /api/orders — списки (active/available/history)
import { NextResponse } from "next/server";
import { db } from "@/db";
import { orders, drivers, users } from "@/db/schema";
import { eq, and, desc, inArray, or, isNull, gte, lte, sql } from "drizzle-orm";
import {
  generateOrderNumber,
  geocodeAddress,
  ACTIVE_STATUSES,
  getDistanceKm,
} from "@/lib/taxi";
import { serializeOrder } from "@/lib/serialize";
import { ensureSeeded } from "@/lib/seed";
import { getServiceBrand } from "@/lib/branding";
import { advanceDriversGps } from "@/lib/simulate";
import { runAutoCallTick } from "@/lib/autocall";
import { publishEvent } from "@/lib/bus";
import { readClaims, forbidden } from "@/lib/session";
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
    const body = await req.json();
    const clientId = String(body.clientId ?? "");
    if (!clientId)
      return NextResponse.json({ error: "clientId обязателен" }, { status: 400 });

    // Авторизация: клиент создаёт заказ только от своего имени
    const claims = readClaims(req);
    if (!claims || claims.role !== "client" || claims.uid !== clientId) {
      return forbidden("Создать заказ может только его клиент");
    }

    const pickupAddress = String(body.pickupAddress ?? "").trim();
    let pickupLat = Number(body.pickupLatitude);
    let pickupLng = Number(body.pickupLongitude);
    if (!pickupAddress)
      return NextResponse.json({ error: "Укажите адрес подачи" }, { status: 400 });
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

    // Расчёт цены (PricingService.CalculatePriceAsync) + многоточечный маршрут
    // (порт PHP orders/index.php): общий помощник для клиентского и
    // операторского создания заказа — computeOrderRoutePricing
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
    const {
      estimatedDistance,
      estimatedDuration,
      routeGeometry,
      pricingMode,
      fromZoneId,
      toZoneId,
      stopsSurcharge: stopsFee,
      preorderSurcharge: preorderFee,
    } = pr;
    let estimatedPrice = pr.estimatedPrice;

    // Опции заказа (OrderOption.cs) — надбавка к цене
    const optionCodes: string[] = Array.isArray(body.options)
      ? body.options.filter((x: unknown): x is string => typeof x === "string")
      : [];
    const chosenOptions = resolveOptions(optionCodes);
    estimatedPrice += optionsTotal(optionCodes);

    const [order] = await db
      .insert(orders)
      .values({
        orderNumber: generateOrderNumber(),
        clientId,
        source: "client_app",
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
        estimatedDistance,
        estimatedDuration,
        routeGeometry,
        pricingMode,
        fromZoneId,
        toZoneId,
        stopsSurcharge: stopsFee,
        scheduledAt,
        preorderSurcharge: preorderFee,
        comment: body.comment ?? null,
        passengerCount: Number(body.passengerCount ?? 1) || 1,
        paymentMethod: (body.paymentMethod as never) ?? "cash",
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

export async function GET(req: Request) {
  try {
    await ensureSeeded();
    await advanceDriversGps();
    await runAutoCallTick();
    const url = new URL(req.url);
    const view = url.searchParams.get("view") ?? "active";

    if (view === "active") {
      const rows = await db
        .select()
        .from(orders)
        .where(inArray(orders.status, [...ACTIVE_STATUSES]))
        .orderBy(desc(orders.createdAt))
        .limit(100);
      return NextResponse.json(await Promise.all(rows.map(serializeOrder)));
    }

    if (view === "available") {
      // GetAvailableOrdersForDriverAsync + обновление локации водителя
      const driverId = url.searchParams.get("driverId");
      const lat = Number(url.searchParams.get("lat"));
      const lng = Number(url.searchParams.get("lng"));
      if (driverId && Number.isFinite(lat) && Number.isFinite(lng)) {
        await db
          .update(drivers)
          .set({ latitude: lat, longitude: lng, lastLocationUpdate: new Date() })
          .where(eq(drivers.id, driverId));
      }
      // Предварительные заказы попадают в ленту за 30 минут до подачи,
      // чтобы не занимать водителей задолго до времени клиента (как в PHP)
      const preorderCutoff = new Date(Date.now() + 30 * 60 * 1000);
      const rows = await db
        .select()
        .from(orders)
        .where(
          and(
            or(eq(orders.status, "searching"), eq(orders.status, "no_driver_found")),
            isNull(orders.driverId),
            or(isNull(orders.scheduledAt), lte(orders.scheduledAt, preorderCutoff))
          )
        )
        .orderBy(sql`${orders.scheduledAt} IS NULL DESC`, orders.scheduledAt, orders.createdAt)
        .limit(50);
      let serialized = await Promise.all(rows.map(serializeOrder));
      if (driverId && Number.isFinite(lat) && Number.isFinite(lng)) {
        serialized = serialized.map((o) => ({
          ...o,
          distanceToPickup: Math.round(getDistanceKm(lat, lng, o.pickupLatitude, o.pickupLongitude) * 10) / 10,
        }));
      }
      return NextResponse.json(serialized);
    }

    if (view === "history") {
      const clientId = url.searchParams.get("clientId");
      const driverId = url.searchParams.get("driverId");
      if (clientId) {
        const rows = await db
          .select()
          .from(orders)
          .where(eq(orders.clientId, clientId))
          .orderBy(desc(orders.createdAt))
          .limit(50);
        return NextResponse.json(await Promise.all(rows.map(serializeOrder)));
      }
      if (driverId) {
        const rows = await db
          .select()
          .from(orders)
          .where(eq(orders.driverId, driverId))
          .orderBy(desc(orders.createdAt))
          .limit(50);
        return NextResponse.json(await Promise.all(rows.map(serializeOrder)));
      }
      return NextResponse.json({ error: "clientId или driverId обязателен" }, { status: 400 });
    }

    if (view === "all") {
      const rows = await db
        .select()
        .from(orders)
        .orderBy(desc(orders.createdAt))
        .limit(200);
      return NextResponse.json(await Promise.all(rows.map(serializeOrder)));
    }

    if (view === "clientActive") {
      const clientId = String(url.searchParams.get("clientId") ?? "");
      const rows = await db
        .select()
        .from(orders)
        .where(
          and(eq(orders.clientId, clientId), inArray(orders.status, [...ACTIVE_STATUSES]))
        )
        .orderBy(desc(orders.createdAt))
        .limit(5);
      return NextResponse.json(await Promise.all(rows.map(serializeOrder)));
    }

    if (view === "driverCurrent") {
      const driverId = String(url.searchParams.get("driverId") ?? "");
      const [d] = await db.select().from(drivers).where(eq(drivers.id, driverId));
      if (!d?.currentOrderId) return NextResponse.json(null);
      const [order] = await db.select().from(orders).where(eq(orders.id, d.currentOrderId));
      if (!order || !ACTIVE_STATUSES.includes(order.status as never)) {
        return NextResponse.json(null);
      }
      return NextResponse.json(await serializeOrder(order));
    }

    // Дневная выборка для админки
    if (view === "today") {
      const since = new Date();
      since.setHours(0, 0, 0, 0);
      const rows = await db
        .select({ order: orders, driverUser: users })
        .from(orders)
        .leftJoin(drivers, eq(orders.driverId, drivers.id))
        .leftJoin(users, eq(drivers.userId, users.id))
        .where(gte(orders.createdAt, since))
        .orderBy(desc(orders.createdAt))
        .limit(200);
      return NextResponse.json(
        await Promise.all(rows.map((r) => serializeOrder(r.order)))
      );
    }

    return NextResponse.json({ error: "Неизвестный view" }, { status: 400 });
  } catch (e) {
    return NextResponse.json(
      { error: e instanceof Error ? e.message : "Ошибка загрузки заказов" },
      { status: 500 }
    );
  }
}
