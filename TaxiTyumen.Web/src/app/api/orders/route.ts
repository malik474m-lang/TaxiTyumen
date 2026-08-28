// POST /api/orders — CreateOrderAsync | GET /api/orders — списки (active/available/history)
import { NextResponse } from "next/server";
import { db } from "@/db";
import { orders, tariffs, drivers, users } from "@/db/schema";
import { eq, and, desc, inArray, or, isNull, gte } from "drizzle-orm";
import {
  calculatePriceEstimate,
  generateOrderNumber,
  geocodeAddress,
  ACTIVE_STATUSES,
  getDistanceKm,
} from "@/lib/taxi";
import { serializeOrder } from "@/lib/serialize";
import { ensureSeeded } from "@/lib/seed";

export async function POST(req: Request) {
  try {
    await ensureSeeded();
    const body = await req.json();
    const clientId = String(body.clientId ?? "");
    if (!clientId)
      return NextResponse.json({ error: "clientId обязателен" }, { status: 400 });

    const pickupAddress = String(body.pickupAddress ?? "").trim();
    let pickupLat = Number(body.pickupLatitude);
    let pickupLng = Number(body.pickupLongitude);
    if (!pickupAddress)
      return NextResponse.json({ error: "Укажите адрес подачи" }, { status: 400 });
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

    // Расчёт цены (PricingService.CalculatePriceAsync)
    let estimatedPrice = 0;
    let estimatedDistance: number | null = null;
    let estimatedDuration: number | null = null;
    if (destinationAddress && Number.isFinite(destLat)) {
      const est = await calculatePriceEstimate(pickupLat, pickupLng, destLat, destLng, tariff);
      estimatedPrice = est.price;
      estimatedDistance = est.distanceKm;
      estimatedDuration = est.durationMinutes;
    } else {
      const [t] = await db.select().from(tariffs).where(eq(tariffs.type, tariff as never));
      estimatedPrice = t?.minimumFare ?? 99;
    }

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
        destinationLatitude: Number.isFinite(destLat) ? destLat : null,
        destinationLongitude: Number.isFinite(destLng) ? destLng : null,
        tariff: tariff as never,
        estimatedPrice,
        estimatedDistance,
        estimatedDuration,
        comment: body.comment ?? null,
        passengerCount: Number(body.passengerCount ?? 1) || 1,
        paymentMethod: (body.paymentMethod as never) ?? "cash",
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

export async function GET(req: Request) {
  try {
    await ensureSeeded();
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
      const rows = await db
        .select()
        .from(orders)
        .where(
          and(
            or(eq(orders.status, "searching"), eq(orders.status, "no_driver_found")),
            isNull(orders.driverId)
          )
        )
        .orderBy(orders.createdAt)
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
