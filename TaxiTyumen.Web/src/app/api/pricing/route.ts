// POST /api/pricing — CalculateAllTariffsAsync (оценка цены по всем тарифам)
import { NextResponse } from "next/server";
import { db } from "@/db";
import { tariffs } from "@/db/schema";
import { eq } from "drizzle-orm";
import { getRealRoute, getRouteGeometry, computePrice, geocodeAddress } from "@/lib/taxi";
import { ensureSeeded } from "@/lib/seed";
import { getServiceBrand } from "@/lib/branding";
import { fixedZonePrice } from "@/lib/zones";

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

    const route = await getRealRoute(fromLat, fromLng, toLat, toLng);
    const geometry = await getRouteGeometry(fromLat, fromLng, toLat, toLng);
    const activeTariffs = await db.select().from(tariffs).where(eq(tariffs.isActive, true));

    const estimates = await Promise.all(activeTariffs.map(async (t) => {
        const p = computePrice(t, route.distanceKm, route.durationMinutes, service.utcOffset);
        const zonePrice = await fixedZonePrice(fromLat, fromLng, toLat, toLng, t.type);
        const finalPrice = zonePrice
          ? (zonePrice.applyMultipliers ? Math.round(zonePrice.price * p.multiplier) : zonePrice.price)
          : p.price;
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
        };
      }));
    estimates.sort((a, b) => a.price - b.price);

    return NextResponse.json({
      from: { lat: fromLat, lng: fromLng },
      to: { lat: toLat, lng: toLng },
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
