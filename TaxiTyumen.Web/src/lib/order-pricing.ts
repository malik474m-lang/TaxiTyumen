// Общий расчёт поездки для создания заказа (клиентский и операторский POST):
// многоточечный маршрут подача → промежуточные → назначение (+ обратный путь),
// зонная цена поверх километража, наценки за остановки (А→Б) и предзаказ.
// Порт логики PHP api/orders/index.php + api/pricing.php.
import { db } from "@/db";
import { tariffs, type Tariff } from "@/db/schema";
import { eq } from "drizzle-orm";
import {
  computePrice,
  getRouteThrough,
  getRouteGeometryThrough,
  stopsSurcharge as feeStop,
} from "@/lib/taxi";
import { fixedZonePrice, getZoneSettings } from "@/lib/zones";
import type { ResolvedStop } from "@/lib/stops";

export interface OrderRoutePricing {
  estimatedPrice: number;
  estimatedDistance: number | null;
  estimatedDuration: number | null;
  routeGeometry: string | null;
  pricingMode: "tariff" | "zone";
  fromZoneId: string | null;
  toZoneId: string | null;
  stopsSurcharge: number;
  preorderSurcharge: number;
  tariffRow: Tariff | null;
}

export async function computeOrderRoutePricing(input: {
  pickupLat: number;
  pickupLng: number;
  destLat: number | null;
  destLng: number | null;
  destinationAddress: string | null;
  stops: ResolvedStop[];
  roundTrip: boolean;
  isPreorder: boolean;
  tariff: string;
  utcOffset?: number;
}): Promise<OrderRoutePricing> {
  const [tariffRow] = await db
    .select()
    .from(tariffs)
    .where(eq(tariffs.type, input.tariff as never));

  const base: OrderRoutePricing = {
    estimatedPrice: 0,
    estimatedDistance: null,
    estimatedDuration: null,
    routeGeometry: null,
    pricingMode: "tariff",
    fromZoneId: null,
    toZoneId: null,
    stopsSurcharge: 0,
    preorderSurcharge: 0,
    tariffRow: tariffRow ?? null,
  };

  const { destinationAddress: destAddr, stops, roundTrip } = input;
  if (!destAddr || input.destLat == null || input.destLng == null) {
    // Назначение не указано — минимальная цена тарифа (как в PHP)
    base.estimatedPrice = tariffRow?.minimumFare ?? 99;
    return base;
  }

  const path: [number, number][] = [
    [input.pickupLat, input.pickupLng],
    ...stops.map((s): [number, number] => [s.latitude, s.longitude]),
    [input.destLat, input.destLng],
  ];
  // Возврат выполняется напрямую к точке подачи, без повторного объезда остановок
  if (roundTrip) path.push([input.pickupLat, input.pickupLng]);

  const route = await getRouteThrough(path);
  const p = computePrice(
    tariffRow ?? ({ baseFare: 0, pricePerKm: 0, pricePerMinute: 0, minimumFare: 99, nightMultiplier: 1, peakMultiplier: 1 } as Tariff),
    route.distanceKm,
    route.durationMinutes,
    input.utcOffset
  );
  let price = p.price;

  // Фиксированная зональная цена имеет приоритет; путь до промежуточных
  // точек (А→Б) оплачивается дополнительно по километражу тарифа
  const zonePrice = await fixedZonePrice(
    input.pickupLat,
    input.pickupLng,
    input.destLat,
    input.destLng,
    input.tariff
  );
  if (zonePrice) {
    price = zonePrice.applyMultipliers
      ? Math.round(zonePrice.price * p.multiplier)
      : zonePrice.price;
    if (stops.length > 0 && tariffRow) {
      const zs = await getZoneSettings();
      const stopRoute = await getRouteThrough([
        [input.pickupLat, input.pickupLng],
        ...stops.map((s): [number, number] => [s.latitude, s.longitude]),
      ]);
      base.stopsSurcharge = feeStop(
        tariffRow,
        stopRoute.distanceKm,
        stops.length,
        zs.stopMinPrice,
        zs.stopPriceMode === "plus" ? "plus" : "max"
      );
      price += base.stopsSurcharge;
    }
    base.pricingMode = "zone";
    base.fromZoneId = zonePrice.fromZone.id;
    base.toZoneId = zonePrice.toZone.id;
  }

  // Наценка за предварительный заказ прибавляется поверх тарифа или зоны
  base.preorderSurcharge =
    input.isPreorder && tariffRow ? Math.max(0, tariffRow.preorderSurcharge) : 0;
  price += base.preorderSurcharge;

  const geometry = await getRouteGeometryThrough(path);

  base.estimatedPrice = price;
  base.estimatedDistance = route.distanceKm;
  base.estimatedDuration = route.durationMinutes;
  base.routeGeometry = JSON.stringify(geometry);
  return base;
}
