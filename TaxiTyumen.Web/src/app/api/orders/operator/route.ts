// POST /api/orders/operator — CreateOrderByOperatorAsync
import { NextResponse } from "next/server";
import { db } from "@/db";
import { orders, tariffs } from "@/db/schema";
import { eq } from "drizzle-orm";
import {
  calculatePriceEstimate,
  generateOrderNumber,
  geocodeAddress,
} from "@/lib/taxi";
import { serializeOrder } from "@/lib/serialize";
import { normalizePhone } from "@/lib/auth";
import { ensureSeeded } from "@/lib/seed";

export async function POST(req: Request) {
  try {
    await ensureSeeded();
    const body = await req.json();
    const operatorId = String(body.operatorId ?? "");
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
      const g = geocodeAddress(pickupAddress);
      pickupLat = g.lat;
      pickupLng = g.lng;
    }

    const destinationAddress = body.destinationAddress
      ? String(body.destinationAddress).trim()
      : null;
    let destLat = Number(body.destinationLatitude);
    let destLng = Number(body.destinationLongitude);
    if (destinationAddress && (!Number.isFinite(destLat) || !Number.isFinite(destLng))) {
      const g = geocodeAddress(destinationAddress);
      destLat = g.lat;
      destLng = g.lng;
    }

    const tariff = String(body.tariff ?? "economy");
    let estimatedPrice = 0;
    let estimatedDistance: number | null = null;
    let estimatedDuration: number | null = null;

    if (destinationAddress && Number.isFinite(destLat)) {
      const est = await calculatePriceEstimate(pickupLat, pickupLng, destLat, destLng, tariff);
      estimatedPrice = est.price;
      estimatedDistance = est.distanceKm;
      estimatedDuration = est.durationMinutes;
    }
    // Если координаты назначения не указаны — минимальная цена тарифа
    if (estimatedPrice === 0) {
      const [t] = await db.select().from(tariffs).where(eq(tariffs.type, tariff as never));
      if (t) estimatedPrice = t.minimumFare;
    }

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
        destinationLatitude: Number.isFinite(destLat) ? destLat : null,
        destinationLongitude: Number.isFinite(destLng) ? destLng : null,
        tariff: tariff as never,
        estimatedPrice,
        estimatedDistance,
        estimatedDuration,
        comment: body.comment ?? null,
        passengerCount: Number(body.passengerCount ?? 1) || 1,
        status: "searching",
      })
      .returning();

    return NextResponse.json(await serializeOrder(order), { status: 201 });
  } catch (e) {
    return NextResponse.json(
      { error: e instanceof Error ? e.message : "Не удалось создать заказ" },
      { status: 500 }
    );
  }
}
