// Разбор промежуточных адресов из тела запроса (порт PHP pricing.php/orders/index.php):
// точки вида {address, latitude?, longitude?} — координаты геокодируются при отсутствии.
import { geocodeAddress } from "@/lib/taxi";

export interface StopPointInput {
  address: string;
  latitude?: number | string | null;
  longitude?: number | string | null;
}

export interface ResolvedStop {
  address: string;
  latitude: number;
  longitude: number;
}

export function resolveStopPoints(
  raw: unknown,
  centerLat: number,
  centerLng: number,
  maxStops = 3
): ResolvedStop[] {
  if (!Array.isArray(raw)) return [];
  const stops: ResolvedStop[] = [];
  for (const item of raw as StopPointInput[]) {
    if (!item || typeof item !== "object") continue;
    const address = String(item.address ?? "").trim().slice(0, 500);
    if (!address) continue;
    let lat = Number(item.latitude);
    let lng = Number(item.longitude);
    if (!Number.isFinite(lat) || !Number.isFinite(lng) || lat === 0 || lng === 0) {
      const g = geocodeAddress(address, centerLat, centerLng);
      lat = g.lat;
      lng = g.lng;
    }
    stops.push({ address, latitude: lat, longitude: lng });
    if (stops.length >= maxStops) break;
  }
  return stops;
}

/** Правдивый флаг «туда и обратно» из тела запроса. */
export function parseRoundTrip(raw: unknown): boolean {
  return raw === true || raw === "true" || raw === 1;
}

/** Дата подачи предзаказа из тела (ISO или «YYYY-MM-DDTHH:mm»), null если не задана/невалидна. */
export function parseScheduledAt(raw: unknown): Date | null {
  const s = String(raw ?? "").trim();
  if (!s) return null;
  const d = new Date(s);
  if (Number.isNaN(d.getTime())) return null;
  // Предзаказ имеет смысл только в будущем; прошлое время = поездка сейчас
  return d.getTime() > Date.now() + 60_000 ? d : null;
}
