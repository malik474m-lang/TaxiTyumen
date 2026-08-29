// GET /api/zones — зоны, цены, настройки | PUT — настройки | POST — CRUD (admin)
import { NextResponse } from "next/server";
import { db } from "@/db";
import { zones, zonePrices, zoneSettings } from "@/db/schema";
import { and, eq } from "drizzle-orm";
import { readClaims, forbidden, unauthorized, hasAdminRole } from "@/lib/session";
import { activeZones, decodePolygon, findZone, getZoneSettings, normalizePolygon } from "@/lib/zones";
import { publishEvent } from "@/lib/bus";

export async function GET(req: Request) {
  const claims = readClaims(req);
  if (!claims) return unauthorized();
  if (claims.role !== "operator" && !hasAdminRole(claims.role)) {
    return forbidden("Зоны доступны персоналу");
  }

  const url = new URL(req.url);
  const lat = Number(url.searchParams.get("lat"));
  const lng = Number(url.searchParams.get("lng"));
  if (Number.isFinite(lat) && Number.isFinite(lng)) {
    const zone = findZone(lat, lng, await activeZones());
    return NextResponse.json({ zone: zone ? { id: zone.id, name: zone.name, color: zone.color } : null });
  }

  const settings = await getZoneSettings();
  const all = await db.select().from(zones);
  const prices = await db.select().from(zonePrices);

  const matrix: Record<string, Record<string, Record<string, number>>> = {};
  for (const p of prices) {
    matrix[p.fromZoneId] ??= {};
    matrix[p.fromZoneId][p.toZoneId] ??= {};
    matrix[p.fromZoneId][p.toZoneId][p.tariff] = p.price;
  }

  return NextResponse.json({
    enabled: settings.enabled,
    applyMultipliers: settings.applyMultipliers,
    addOptions: settings.addOptions,
    fallbackToTariff: settings.fallbackToTariff,
    zones: all
      .map((z) => ({
        id: z.id,
        name: z.name,
        color: z.color,
        priority: z.priority,
        isActive: z.isActive,
        points: decodePolygon(z.polygon),
      }))
      .sort((a, b) => b.priority - a.priority || a.name.localeCompare(b.name)),
    prices: matrix,
  });
}

export async function PUT(req: Request) {
  const claims = readClaims(req);
  if (!claims || !hasAdminRole(claims.role)) {
    return forbidden("Настройки зон меняет только администратор");
  }
  const settings = await getZoneSettings();
  const body = await req.json();
  await db
    .update(zoneSettings)
    .set({
      enabled: Boolean(body.enabled ?? settings.enabled),
      applyMultipliers: Boolean(body.applyMultipliers ?? settings.applyMultipliers),
      addOptions: Boolean(body.addOptions ?? settings.addOptions),
      fallbackToTariff: Boolean(body.fallbackToTariff ?? settings.fallbackToTariff),
      updatedAt: new Date(),
    })
    .where(eq(zoneSettings.id, settings.id));
  publishEvent("zones");
  return NextResponse.json(await getZoneSettings());
}

export async function POST(req: Request) {
  const claims = readClaims(req);
  if (!claims || !hasAdminRole(claims.role)) {
    return forbidden("Управление зонами доступно администратору");
  }
  const body = await req.json();
  const action = String(body.action ?? "");

  try {
    if (action === "create" || action === "update") {
      const name = String(body.name ?? "").trim();
      if (!name) return NextResponse.json({ error: "Укажите название зоны" }, { status: 400 });
      const points = normalizePolygon(body.points ?? body.polygon ?? []);
      const values = {
        name: name.slice(0, 80),
        color: String(body.color ?? "#38bdf8").slice(0, 9),
        polygon: JSON.stringify(points),
        priority: Number(body.priority ?? 0) || 0,
        isActive: body.isActive === undefined ? true : Boolean(body.isActive),
      };
      if (action === "create") {
        const [created] = await db.insert(zones).values(values).returning();
        publishEvent("zones");
        return NextResponse.json({ ok: true, id: created.id });
      }
      const id = String(body.id ?? "");
      await db.update(zones).set({ ...values, updatedAt: new Date() }).where(eq(zones.id, id));
      publishEvent("zones");
      return NextResponse.json({ ok: true, id });
    }

    if (action === "delete") {
      const id = String(body.id ?? "");
      await db.delete(zonePrices).where(eq(zonePrices.fromZoneId, id));
      await db.delete(zonePrices).where(eq(zonePrices.toZoneId, id));
      await db.delete(zones).where(eq(zones.id, id));
      publishEvent("zones");
      return NextResponse.json({ ok: true });
    }

    if (action === "set_price") {
      const fromZoneId = String(body.fromZoneId ?? "");
      const toZoneId = String(body.toZoneId ?? "");
      const tariff = String(body.tariff ?? "economy");
      const raw = body.price;
      const price = raw === null || raw === "" ? null : Number(raw);

      const where = and(
        eq(zonePrices.fromZoneId, fromZoneId),
        eq(zonePrices.toZoneId, toZoneId),
        eq(zonePrices.tariff, tariff as never)
      );
      await db.delete(zonePrices).where(where);
      if (price !== null && Number.isFinite(price) && price > 0) {
        await db.insert(zonePrices).values({
          fromZoneId,
          toZoneId,
          tariff: tariff as never,
          price: Math.round(price * 100) / 100,
          updatedAt: new Date(),
        });
      }
      publishEvent("zones");
      return NextResponse.json({ ok: true });
    }
  } catch (e) {
    return NextResponse.json(
      { error: e instanceof Error ? e.message : "Ошибка обработки зоны" },
      { status: 422 }
    );
  }

  return NextResponse.json({ error: `Неизвестный action: ${action}` }, { status: 400 });
}
