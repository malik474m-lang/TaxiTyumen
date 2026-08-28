// Порт TaxiService.Core: DistanceCalculator + PricingService + OrderNumberGenerator
import { db } from "@/db";
import { tariffs, type Tariff } from "@/db/schema";
import { eq } from "drizzle-orm";
import { CITY } from "@/lib/city";

export { CITY };

// ── Координаты популярных мест Тюмени (для подсказок адресов) ───────────────
export const PLACES: { name: string; lat: number; lng: number }[] = [
  { name: "Аэропорт Рощино", lat: 57.1896, lng: 65.3243 },
  { name: "Ж/д вокзал Тюмень", lat: 57.1459, lng: 65.5271 },
  { name: "Набережная реки Туры", lat: 57.1588, lng: 65.526 },
  { name: "Цветной бульвар", lat: 57.1486, lng: 65.5349 },
  { name: "Мост влюблённых", lat: 57.1555, lng: 65.528 },
  { name: "Площадь 400-летия Тюмени", lat: 57.158, lng: 65.5345 },
  { name: "ТюмГУ, главный корпус", lat: 57.1526, lng: 65.5365 },
  { name: "Театр драмы", lat: 57.1542, lng: 65.5269 },
  { name: "ТРЦ «Гудвин»", lat: 57.1378, lng: 65.5825 },
  { name: "ТРЦ «Кристалл»", lat: 57.1262, lng: 65.591 },
  { name: "ТРЦ «Олимп»", lat: 57.11, lng: 65.544 },
  { name: "ДК «Нефтяник»", lat: 57.1241, lng: 65.5922 },
  { name: "Гилёвская роща", lat: 57.1576, lng: 65.4766 },
  { name: "ЖК «Европейский»", lat: 57.0954, lng: 65.5699 },
  { name: "мкр. Patрушево", lat: 57.115, lng: 65.535 },
  { name: "ул. Республики, 1", lat: 57.1534, lng: 65.5214 },
  { name: "ул. 8 Марта, 2", lat: 57.1609, lng: 65.5197 },
  { name: "ул. Мельникайте, 103", lat: 57.1654, lng: 65.5412 },
  { name: "ул. Пермякова, 74", lat: 57.1063, lng: 65.5757 },
  { name: "ул. Широтная, 154", lat: 57.1744, lng: 65.5748 },
];

// Детерминированная «геокодировка» произвольного адреса рядом с центром
export function geocodeAddress(address: string): { lat: number; lng: number } {
  const q = address.trim().toLowerCase();
  const place = PLACES.find((p) =>
    q.includes(p.name.toLowerCase().slice(0, 6))
  );
  if (place) return { lat: place.lat, lng: place.lng };
  let hash = 0;
  for (let i = 0; i < q.length; i++) hash = (hash * 31 + q.charCodeAt(i)) | 0;
  const latJ = (((hash % 1000) / 1000) - 0.5) * 0.06;
  const lngJ = (((((hash >> 10) % 1000) / 1000) - 0.5) * 0.1);
  return { lat: CITY.centerLat + latJ, lng: CITY.centerLng + lngJ };
}

// ── DistanceCalculator.cs ────────────────────────────────────────────────────

function toRadians(deg: number) {
  return (deg * Math.PI) / 180;
}

export function getDistanceKm(
  lat1: number,
  lng1: number,
  lat2: number,
  lng2: number
): number {
  const R = 6371;
  const dLat = toRadians(lat2 - lat1);
  const dLng = toRadians(lng2 - lng1);
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(toRadians(lat1)) * Math.cos(toRadians(lat2)) * Math.sin(dLng / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

export function getRoadDistanceKm(
  lat1: number,
  lng1: number,
  lat2: number,
  lng2: number,
  roadFactor = 1.3
): number {
  return getDistanceKm(lat1, lng1, lat2, lng2) * roadFactor;
}

export function estimateDurationMinutes(
  distanceKm: number,
  avgSpeedKmh = 25
): number {
  return Math.ceil((distanceKm / avgSpeedKmh) * 60);
}

// Реальный маршрут через OSRM, fallback — Haversine × 1.3
export async function getRealRoute(
  lat1: number,
  lng1: number,
  lat2: number,
  lng2: number
): Promise<{ distanceKm: number; durationMinutes: number }> {
  try {
    const url = `https://router.project-osrm.org/route/v1/driving/${lng1},${lat1};${lng2},${lat2}?overview=false`;
    const res = await fetch(url, { signal: AbortSignal.timeout(4000) });
    if (res.ok) {
      const json = await res.json();
      const route = json?.routes?.[0];
      if (route) {
        return {
          distanceKm: Math.round((route.distance / 1000) * 10) / 10,
          durationMinutes: Math.ceil(route.duration / 60),
        };
      }
    }
  } catch {
    /* fallback */
  }
  const dist = getRoadDistanceKm(lat1, lng1, lat2, lng2);
  return {
    distanceKm: Math.round(dist * 10) / 10,
    durationMinutes: estimateDurationMinutes(dist),
  };
}

// ── PricingService.cs ────────────────────────────────────────────────────────

export interface PriceEstimate {
  tariffType: string;
  tariffName: string;
  description: string;
  price: number;
  distanceKm: number;
  durationMinutes: number;
  isNightRate: boolean;
  isPeakRate: boolean;
  multiplier: number;
}

export function tyumenNow(): Date {
  const now = new Date();
  return new Date(
    now.getTime() + (CITY.utcOffsetHours + now.getTimezoneOffset() / 60) * 3600_000
  );
}

export function computePrice(
  tariff: Tariff,
  distanceKm: number,
  durationMinutes: number
): Omit<PriceEstimate, "tariffType" | "tariffName" | "description" | "distanceKm" | "durationMinutes"> {
  let price = tariff.baseFare + distanceKm * tariff.pricePerKm;
  const hour = tyumenNow().getHours();
  const isNight = hour >= 23 || hour < 6;
  const isPeak = (hour >= 7 && hour < 9) || (hour >= 17 && hour < 19);
  let multiplier = 1;
  if (isNight) {
    multiplier = tariff.nightMultiplier;
    price *= multiplier;
  } else if (isPeak) {
    multiplier = tariff.peakMultiplier;
    price *= multiplier;
  }
  price = Math.max(price, tariff.minimumFare);
  return {
    price: Math.round(price),
    isNightRate: isNight,
    isPeakRate: isPeak,
    multiplier,
  };
}

export async function calculatePriceEstimate(
  fromLat: number,
  fromLng: number,
  toLat: number,
  toLng: number,
  tariffType: string
): Promise<PriceEstimate> {
  const [tariff] = await db
    .select()
    .from(tariffs)
    .where(eq(tariffs.type, tariffType as never));
  if (!tariff) throw new Error(`Тариф ${tariffType} не найден`);
  const route = await getRealRoute(fromLat, fromLng, toLat, toLng);
  const p = computePrice(tariff, route.distanceKm, route.durationMinutes);
  return {
    tariffType,
    tariffName: tariff.name,
    description: tariff.description,
    price: p.price,
    distanceKm: route.distanceKm,
    durationMinutes: route.durationMinutes,
    isNightRate: p.isNightRate,
    isPeakRate: p.isPeakRate,
    multiplier: p.multiplier,
  };
}

// ── OrderNumberGenerator.cs ──────────────────────────────────────────────────

export function generateOrderNumber(): string {
  const now = new Date();
  const pad = (n: number, l = 2) => String(n).padStart(l, "0");
  const date = `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}`;
  const time = `${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}${pad(
    now.getMilliseconds(),
    3
  )}`;
  const rand = Math.floor(10000 + Math.random() * 89999);
  return `TX-${date}-${time}-${rand}`;
}

// ── Локализация статусов (OrderResponse.StatusText) ─────────────────────────

export const STATUS_TEXT: Record<string, string> = {
  created: "Создан",
  searching: "Поиск водителя",
  driver_assigned: "Водитель назначен",
  driver_en_route: "Водитель в пути",
  driver_arrived: "Водитель на месте",
  in_progress: "Поездка",
  completed: "Завершён",
  cancelled: "Отменён",
  no_driver_found: "Водитель не найден",
};

export const TARIFF_NAMES: Record<string, string> = {
  economy: "Эконом",
  comfort: "Комфорт",
  business: "Бизнес",
  minivan: "Минивэн",
};

export const PAYMENT_NAMES: Record<string, string> = {
  cash: "Наличные",
  card: "Карта",
  bonus: "Бонусы",
};

export const DRIVER_STATUS_TEXT: Record<string, string> = {
  offline: "Офлайн",
  available: "На линии",
  on_route: "Едет к клиенту",
  in_trip: "В поездке",
  busy: "Занят",
};

export const ACTIVE_STATUSES = [
  "created",
  "searching",
  "driver_assigned",
  "driver_en_route",
  "driver_arrived",
  "in_progress",
  "no_driver_found",
] as const;
