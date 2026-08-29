// Клиентские константы города и чистая геоматематика (без серверных зависимостей)
export const CITY = {
  name: "Тюмень",
  centerLat: 57.1522,
  centerLng: 65.5272,
  utcOffsetHours: 5,
} as const;

export function getDistanceKm(lat1: number, lng1: number, lat2: number, lng2: number): number {
  const R = 6371;
  const toRad = (deg: number) => (deg * Math.PI) / 180;
  const dLat = toRad(lat2 - lat1);
  const dLng = toRad(lng2 - lng1);
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

// ETA в минутах при средней скорости по городу
export function estimateEtaMinutes(lat1: number, lng1: number, lat2: number, lng2: number, avgSpeedKmh = 25): number {
  return Math.ceil((getDistanceKm(lat1, lng1, lat2, lng2) / avgSpeedKmh) * 60);
}
