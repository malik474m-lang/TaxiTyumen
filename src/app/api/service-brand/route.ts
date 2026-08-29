// GET /api/service-brand — публичный бренд сервиса | PUT — изменение (admin)
import { NextResponse } from "next/server";
import { db } from "@/db";
import { serviceBrand } from "@/db/schema";
import { eq } from "drizzle-orm";
import { getServiceBrand, ensureServiceBrandSeeded, SERVICE_BRAND_ID } from "@/lib/branding";
import { readClaims, forbidden } from "@/lib/session";
import { publishEvent } from "@/lib/bus";

export async function GET() {
  return NextResponse.json(await getServiceBrand());
}

export async function PUT(req: Request) {
  const claims = readClaims(req);
  if (!claims || claims.role !== "admin") {
    return forbidden("Бренд сервиса меняет только администратор");
  }
  await ensureServiceBrandSeeded();
  const [existing] = await db
    .select()
    .from(serviceBrand)
    .where(eq(serviceBrand.id, SERVICE_BRAND_ID))
    .limit(1);
  if (!existing) return NextResponse.json({ error: "Не найдено" }, { status: 404 });

  const body = await req.json();
  const clamp = (v: number, min: number, max: number) =>
    Math.max(min, Math.min(max, Number.isFinite(v) ? v : min));

  await db
    .update(serviceBrand)
    .set({
      serviceName: String(body.serviceName ?? existing.serviceName).slice(0, 80) || existing.serviceName,
      city: String(body.city ?? existing.city).slice(0, 80) || existing.city,
      region: String(body.region ?? existing.region).slice(0, 120),
      regionCode: String(body.regionCode ?? existing.regionCode).slice(0, 10),
      supportPhone:
        body.supportPhone === null || body.supportPhone === ""
          ? null
          : String(body.supportPhone ?? existing.supportPhone ?? "").slice(0, 30) || null,
      centerLat: clamp(Number(body.centerLat ?? existing.centerLat), -90, 90),
      centerLng: clamp(Number(body.centerLng ?? existing.centerLng), -180, 180),
      utcOffset: clamp(Math.round(Number(body.utcOffset ?? existing.utcOffset)), -11, 13),
      smsSenderName: String(body.smsSenderName ?? existing.smsSenderName).slice(0, 80) || existing.serviceName,
      updatedAt: new Date(),
    })
    .where(eq(serviceBrand.id, existing.id));

  publishEvent("branding");
  return NextResponse.json(await getServiceBrand());
}
