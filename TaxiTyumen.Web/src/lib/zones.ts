// Зональная тарификация: полигоны и фиксированные цены (веб-порт)
import { db } from "@/db";
import { zones, zonePrices, zoneSettings } from "@/db/schema";
import { and, eq } from "drizzle-orm";

export interface ZonePoint {
  lat: number;
  lng: number;
}

export interface ZoneRecord {
  id: string;
  name: string;
  color: string;
  priority: number;
  isActive: boolean;
  points: [number, number][];
}

export interface FixedPriceResult {
  price: number;
  fromZone: { id: string; name: string };
  toZone: { id: string; name: string };
  applyMultipliers: boolean;
  addOptions: boolean;
}

export function decodePolygon(raw: string): [number, number][] {
  try {
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    return parsed
      .filter((p) => Array.isArray(p) && p.length >= 2)
      .map((p) => [Number(p[0]), Number(p[1])] as [number, number]);
  } catch {
    return [];
  }
}

export function normalizePolygon(raw: unknown): [number, number][] {
  const source = typeof raw === "string" ? JSON.parse(raw) : raw;
  if (!Array.isArray(source)) {
    throw new Error("Полигон зоны должен быть массивом точек [lat, lng]");
  }
  const points = source.map((p) => {
    const lat = Array.isArray(p) ? Number(p[0]) : Number((p as ZonePoint)?.lat);
    const lng = Array.isArray(p) ? Number(p[1]) : Number((p as ZonePoint)?.lng);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
      throw new Error("Некорректная точка полигона");
    }
    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) {
      throw new Error("Координаты зоны вне допустимого диапазона");
    }
    return [Number(lat.toFixed(6)), Number(lng.toFixed(6))] as [number, number];
  });
  if (points.length < 3) {
    throw new Error("Зона должна содержать минимум 3 точки");
  }
  return points;
}

/** Ray casting — та же логика, что в PHP-версии. */
export function pointInPolygon(lat: number, lng: number, points: [number, number][]): boolean {
  let inside = false;
  for (let i = 0, j = points.length - 1; i < points.length; j = i++) {
    const [latI, lngI] = points[i];
    const [latJ, lngJ] = points[j];
    const intersects =
      lngI > lng !== lngJ > lng &&
      lat < ((latJ - latI) * (lng - lngI)) / (lngJ - lngI || 1e-12) + latI;
    if (intersects) inside = !inside;
  }
  return inside;
}

export async function getZoneSettings() {
  const [row] = await db.select().from(zoneSettings).limit(1);
  if (row) return row;
  const [created] = await db.insert(zoneSettings).values({}).returning();
  return created;
}

export async function activeZones(): Promise<ZoneRecord[]> {
  const rows = await db.select().from(zones).where(eq(zones.isActive, true));
  return rows
    .map((z) => ({
      id: z.id,
      name: z.name,
      color: z.color,
      priority: z.priority,
      isActive: z.isActive,
      points: decodePolygon(z.polygon),
    }))
    .sort((a, b) => b.priority - a.priority || a.name.localeCompare(b.name));
}

export function findZone(
  lat: number,
  lng: number,
  list: ZoneRecord[]
): ZoneRecord | null {
  return list.find((z) => z.points.length > 0 && pointInPolygon(lat, lng, z.points)) ?? null;
}

/** Фиксированная цена между зонами или null, если неприменима. */
export async function fixedZonePrice(
  fromLat: number,
  fromLng: number,
  toLat: number,
  toLng: number,
  tariff: string
): Promise<FixedPriceResult | null> {
  const settings = await getZoneSettings();
  if (!settings?.enabled) return null;

  const list = await activeZones();
  if (list.length === 0) return null;

  const from = findZone(fromLat, fromLng, list);
  const to = findZone(toLat, toLng, list);
  if (!from || !to) return null;

  const lookup = async (a: string, b: string) => {
    const [row] = await db
      .select()
      .from(zonePrices)
      .where(
        and(
          eq(zonePrices.fromZoneId, a),
          eq(zonePrices.toZoneId, b),
          eq(zonePrices.tariff, tariff as never),
          eq(zonePrices.isActive, true)
        )
      )
      .limit(1);
    return row;
  };

  const direct = (await lookup(from.id, to.id)) ?? (await lookup(to.id, from.id));
  if (!direct) return null;

  return {
    price: direct.price,
    fromZone: { id: from.id, name: from.name },
    toZone: { id: to.id, name: to.name },
    applyMultipliers: settings.applyMultipliers,
    addOptions: settings.addOptions,
  };
}
