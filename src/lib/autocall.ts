// Порт AutoCallService: обзвон «застывших» заказов.
// В вебе: tick при запросах — заказы долго без водителя эскалируются операторам,
// опционально автоназначаются ближайшему свободному водителю.
import { db } from "@/db";
import { orders, drivers, orderRejections, autoCallSettings } from "@/db/schema";
import { eq, and, or, isNull, lt } from "drizzle-orm";
import { getDistanceKm } from "@/lib/city";
import { publishEvent } from "@/lib/bus";

const g = globalThis as typeof globalThis & { __autoCallLastTick?: number };
const TICK_INTERVAL_MS = 10000;

export interface AutoCallConfig {
  id: string;
  enabled: boolean;
  escalateAfterMinutes: number;
  autoAssignEnabled: boolean;
  autoAssignRadiusKm: number;
}

export async function getAutoCallSettings(): Promise<AutoCallConfig> {
  const [row] = await db.select().from(autoCallSettings).limit(1);
  if (row) return row;
  const [created] = await db.insert(autoCallSettings).values({}).returning();
  return created;
}

export async function runAutoCallTick() {
  const now = Date.now();
  if (g.__autoCallLastTick && now - g.__autoCallLastTick < TICK_INTERVAL_MS) return;
  g.__autoCallLastTick = now;

  try {
    const settings = await getAutoCallSettings();
    if (!settings.enabled) return;

    const deadline = new Date(now - settings.escalateAfterMinutes * 60_000);
    const stuck = await db
      .select()
      .from(orders)
      .where(
        and(
          or(eq(orders.status, "searching"), eq(orders.status, "no_driver_found")),
          isNull(orders.driverId),
          isNull(orders.escalatedAt),
          lt(orders.createdAt, deadline)
        )
      )
      .limit(10);

    for (const order of stuck) {
      // Автоназначение ближайшему свободному водителю (если включено)
      if (settings.autoAssignEnabled) {
        const free = await db
          .select()
          .from(drivers)
          .where(and(eq(drivers.status, "available"), eq(drivers.isVerified, true)));

        const rejected = await db
          .select({ driverId: orderRejections.driverId })
          .from(orderRejections)
          .where(eq(orderRejections.orderId, order.id));
        const rejectedIds = new Set(rejected.map((r) => r.driverId));

        const best = free
          .filter((d) => !rejectedIds.has(d.id) && d.balance >= d.minBalanceForOrders)
          .map((d) => ({
            d,
            dist: getDistanceKm(order.pickupLatitude, order.pickupLongitude, d.latitude, d.longitude),
          }))
          .filter((x) => x.dist <= settings.autoAssignRadiusKm)
          .sort((a, b) => a.dist - b.dist)[0];

        if (best) {
          await db
            .update(orders)
            .set({ driverId: best.d.id, status: "driver_assigned", acceptedAt: new Date() })
            .where(eq(orders.id, order.id));
          await db
            .update(drivers)
            .set({ status: "on_route", currentOrderId: order.id })
            .where(eq(drivers.id, best.d.id));
          publishEvent("orders");
          continue;
        }
      }

      // Эскалация — подсветка операторам
      await db
        .update(orders)
        .set({ escalatedAt: new Date() })
        .where(eq(orders.id, order.id));
      publishEvent("orders");
    }
  } catch {
    /* автодозвон — best effort */
  }
}
