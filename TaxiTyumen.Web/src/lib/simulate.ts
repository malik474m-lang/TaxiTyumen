// Серверная симуляция GPS водителей (в оригинале координаты шлёт MAUI-приложение).
// Водитель плавно едет к точке подачи, потом — к пункту назначения.
import { db } from "@/db";
import { drivers, orders } from "@/db/schema";
import { eq, isNotNull } from "drizzle-orm";
import { getDistanceKm } from "@/lib/taxi";

// Эффективная скорость симуляции: ускоренная, чтобы движение было наглядным
const SPEED_KM_PER_SEC = 0.09; // ~324 км/ч
const MIN_INTERVAL_MS = 2500;

export async function advanceDriversGps() {
  try {
    const rows = await db
      .select()
      .from(drivers)
      .where(isNotNull(drivers.currentOrderId));

    const now = Date.now();
    for (const d of rows) {
      const last = d.lastLocationUpdate?.getTime() ?? 0;
      const elapsedSec = (now - last) / 1000;
      if (elapsedSec * 1000 < MIN_INTERVAL_MS) continue;

      const [order] = await db
        .select()
        .from(orders)
        .where(eq(orders.id, d.currentOrderId!));
      if (!order) continue;

      let target: [number, number] | null = null;
      if (order.status === "driver_assigned" || order.status === "driver_en_route") {
        target = [order.pickupLatitude, order.pickupLongitude];
      } else if (order.status === "in_progress" && order.destinationLatitude != null) {
        target = [order.destinationLatitude, order.destinationLongitude!];
      }
      if (!target) continue; // driver_arrived — стоит у клиента

      const dist = getDistanceKm(d.latitude, d.longitude, target[0], target[1]);
      if (dist < 0.02) continue;

      const step = Math.min(dist, Math.max(elapsedSec * SPEED_KM_PER_SEC, 0.05));
      const ratio = step / dist;
      await db
        .update(drivers)
        .set({
          latitude: d.latitude + (target[0] - d.latitude) * ratio,
          longitude: d.longitude + (target[1] - d.longitude) * ratio,
          lastLocationUpdate: new Date(),
        })
        .where(eq(drivers.id, d.id));
    }
  } catch {
    /* симуляция — best effort */
  }
}
