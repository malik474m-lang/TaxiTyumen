// POST /api/pricing — CalculateAllTariffsAsync (оценка цены по всем тарифам)
import { NextResponse } from "next/server";
import { db } from "@/db";
import { tariffs } from "@/db/schema";
import { eq } from "drizzle-orm";
import { getRealRoute, getRouteGeometry, computePrice, geocodeAddress } from "@/lib/taxi";
import { ensureSeeded } from "@/lib/seed";

export async function POST(req: Request) {
  try {
    await ensureSeeded();
    const body = await req.json();

    let fromLat = Number(body.fromLat);
    let fromLng = Number(body.fromLng);
    let toLat = Number(body.toLat);
    let toLng = Number(body.toLng);

    if (body.fromAddress && (!Number.isFinite(fromLat) || !Number.isFinite(fromLng))) {
      const g = geocodeAddress(String(body.fromAddress));
      fromLat = g.lat;
      fromLng = g.lng;
    }
    if (body.toAddress && (!Number.isFinite(toLat) || !Number.isFinite(toLng))) {
      const g = geocodeAddress(String(body.toAddress));
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

    const estimates = activeTariffs
      .map((t) => {
        const p = computePrice(t, route.distanceKm, route.durationMinutes);
        return {
          tariffType: t.type,
          tariffName: t.name,
          description: t.description,
          price: p.price,
          distanceKm: route.distanceKm,
          durationMinutes: route.durationMinutes,
          isNightRate: p.isNightRate,
          isPeakRate: p.isPeakRate,
          multiplier: p.multiplier,
          minimumFare: t.minimumFare,
        };
      })
      .sort((a, b) => a.price - b.price);

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
