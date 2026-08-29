// GET /api/branding?app=client — публично (рендер входа) | GET (все) — админ
// PUT /api/branding — обновление, только админ. Всё на сервере.
import { NextResponse } from "next/server";
import { db } from "@/db";
import { brandingSettings } from "@/db/schema";
import { eq } from "drizzle-orm";
import {
  getBranding,
  getAllBranding,
  BRAND_APPS,
  type BrandApp,
} from "@/lib/branding";
import { readClaims, forbidden } from "@/lib/session";
import { publishEvent } from "@/lib/bus";

export async function GET(req: Request) {
  const url = new URL(req.url);
  const app = url.searchParams.get("app") as BrandApp | null;

  if (app && BRAND_APPS.includes(app)) {
    // Публичный одиночный бренд — нужен экрану входа до авторизации
    return NextResponse.json(await getBranding(app));
  }

  const claims = readClaims(req);
  if (!claims || claims.role !== "admin") {
    return forbidden("Список брендов доступен только администратору");
  }
  return NextResponse.json(await getAllBranding());
}

export async function PUT(req: Request) {
  const claims = readClaims(req);
  if (!claims || claims.role !== "admin") {
    return forbidden("Брендинг меняет только администратор");
  }

  const body = await req.json();
  const app = String(body.app ?? "") as BrandApp;
  if (!BRAND_APPS.includes(app)) {
    return NextResponse.json({ error: "Неизвестное приложение" }, { status: 400 });
  }

  const existing = await getBranding(app);
  const features: string[] = Array.isArray(body.features)
    ? body.features.filter((x: unknown): x is string => typeof x === "string").slice(0, 5)
    : existing.features;

  const fields = {
    appName: String(body.appName ?? existing.appName).slice(0, 60),
    appCode: String(body.appCode ?? existing.appCode).slice(0, 60),
    heroTitle: String(body.heroTitle ?? existing.heroTitle).slice(0, 120),
    heroSubtitle: String(body.heroSubtitle ?? existing.heroSubtitle).slice(0, 300),
    logoIcon: String(body.logoIcon ?? existing.logoIcon).slice(0, 40),
    primaryColor: String(body.primaryColor ?? existing.primaryColor),
    primaryTextColor: String(body.primaryTextColor ?? existing.primaryTextColor),
    supportPhone: body.supportPhone === null ? null : String(body.supportPhone ?? existing.supportPhone ?? "").slice(0, 30),
    features: JSON.stringify(features),
    updatedAt: new Date(),
  };

  await db.update(brandingSettings).set(fields).where(eq(brandingSettings.app, app));
  publishEvent("branding");
  return NextResponse.json(await getBranding(app));
}
