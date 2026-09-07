// POST /api/pricing — CalculateAllTariffsAsync (оценка цены по всем тарифам)
// Порт PHP pricing.php: тот же маршрут, что и при создании заказа —
// подача → промежуточные → назначение (+ обратный путь для «туда и обратно»),
// иначе предварительная и итоговая цены разойдутся.
import { NextResponse } from "next/server";
import { db } from "@/db";
import { tariffs } from "@/db/schema";
import { eq } from "drizzle-orm";
import {
  computePrice,
  geocodeAddress,
  getRouteThrough,
  getRouteGeometryThrough,
  stopsSurcharge,
} from "@/lib/taxi";
import { ensureSeeded } from "@/lib/seed";
import { getServiceBrand } from "@/lib/branding";
import { fixedZonePrice, getZoneSettings } from "@/lib/zones";
import { parseRoundTrip, parseScheduledAt, resolveStopPoints } from "@/lib/stops";

export async function POST(req: Request) {
  try {
    await ensureSeeded();
    const service = await getServiceBrand();
    const body = await req.json();

    let fromLat = Number(body.fromLat);
    let fromLng = Number(body.fromLng);
    let toLat = Number(body.toLat);
    let toLng = Number(body.toLng);

    if (body.fromAddress && (!Number.isFinite(fromLat) || !Number.isFinite(fromLng))) {
      const g = geocodeAddress(String(body.fromAddress), service.centerLat, service.centerLng);
      fromLat = g.lat;
      fromLng = g.lng;
    }
    if (body.toAddress && (!Number.isFinite(toLat) || !Number.isFinite(toLng))) {
      const g = geocodeAddress(String(body.toAddress), service.centerLat, service.centerLng);
      toLat = g.lat;
      toLng = g.lng;
    }

    if (!Number.isFinite(fromLat) || !Number.isFinite(fromLng) ||
        !Number.isFinite(toLat) || !Number.isFinite(toLng)) {
      return NextResponse.json({ error: "Укажите адреса подачи и назначения" }, { status: 400 });
    }

    // Промежуточные адреса + «туда и обратно» (как в PHP-версии)
    const stops = resolveStopPoints(body.intermediatePoints, service.centerLat, service.centerLng);
    const roundTrip = parseRoundTrip(body.roundTrip);
    const scheduledAt = parseScheduledAt(body.scheduledAt);
    const isPreorder = body.isPreorder === true || scheduledAt !== null;

    const path: [number, number][] = [
      [fromLat, fromLng],
      ...stops.map((s): [number, number] => [s.latitude, s.longitude]),
      [toLat, toLng],
    ];
    // Возврат выполняется напрямую к точке подачи, без повторного объезда остановок
    if (roundTrip) path.push([fromLat, fromLng]);

    const route = await getRouteThrough(path);
    const geometry = await getRouteGeometryThrough(path);
    const activeTariffs = await db.select().from(tariffs).where(eq(tariffs.isActive, true));
    const zs = await getZoneSettings();

    // Путь до промежуточных точек (А→Б) — база наценки при зонной цене
    const stopPath: [number, number][] = [
      [fromLat, fromLng],
      ...stops.map((s): [number, number] => [s.latitude, s.longitude]),
    ];
    const stopRoute =
      stops.length > 0 ? await getRouteThrough(stopPath) : { distanceKm: 0 };

    const estimates = await Promise.all(activeTariffs.map(async (t) => {
        const p = computePrice(t, route.distanceKm, route.durationMinutes, service.utcOffset);
        const zonePrice = await fixedZonePrice(fromLat, fromLng, toLat, toLng, t.type);

        // Наценка за промежуточные адреса при зонном ценообразовании:
        // цена зоны остаётся базой, путь до каждой точки — по километражу тарифа
        const stopsFee =
          zonePrice && stops.length > 0
            ? stopsSurcharge(
                t,
                stopRoute.distanceKm,
                stops.length,
                zs.stopMinPrice,
                (zs.stopPriceMode === "plus" ? "plus" : "max")
              )
            : 0;

        let finalPrice = p.price;
        if (zonePrice) {
          finalPrice = zonePrice.applyMultipliers
            ? Math.round(zonePrice.price * p.multiplier)
            : zonePrice.price;
          finalPrice += stopsFee;
        }
        // Наценка за предварительный заказ прибавляется поверх тарифа или зоны
        const preorderFee = isPreorder ? Math.max(0, t.preorderSurcharge) : 0;
        finalPrice += preorderFee;

        return {
          tariffType: t.type,
          tariffName: t.name,
          description: t.description,
          price: finalPrice,
          isFixedPrice: Boolean(zonePrice),
          pricingMode: zonePrice ? "zone" : "tariff",
          fromZone: zonePrice?.fromZone.name ?? null,
          toZone: zonePrice?.toZone.name ?? null,
          distanceKm: route.distanceKm,
          durationMinutes: route.durationMinutes,
          isNightRate: p.isNightRate,
          isPeakRate: p.isPeakRate,
          multiplier: p.multiplier,
          minimumFare: t.minimumFare,
          preorderSurcharge: preorderFee,
          isPreorder,
          stopsSurcharge: stopsFee,
        };
      }));
    estimates.sort((a, b) => a.price - b.price);

    return NextResponse.json({
      from: { lat: fromLat, lng: fromLng },
      to: { lat: toLat, lng: toLng },
      stopPoints: stops,
      roundTrip,
      isPreorder,
      scheduledAt: scheduledAt?.toISOString() ?? null,
      geometry,
      estimates,
    });
  } catch (e) {
    return NextResponse.json(
      { error: e instanceof Error ? e.message : "Ошибка расчёта" },
      { status: 500 }
    );
  }
}
