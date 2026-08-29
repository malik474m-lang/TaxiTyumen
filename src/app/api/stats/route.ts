// GET /api/stats — сводная статистика для админ-панели (порт Stats.razor)
import { NextResponse } from "next/server";
import { db } from "@/db";
import { orders, drivers, users } from "@/db/schema";
import { sql, gte, eq, and } from "drizzle-orm";
import { ACTIVE_STATUSES } from "@/lib/taxi";
import { inArray } from "drizzle-orm";
import { readClaims, forbidden, hasAdminRole } from "@/lib/session";

export async function GET(req: Request) {
  try {
    // Статистика сервиса — только админ
    const claims = readClaims(req);
    if (!claims || !hasAdminRole(claims.role)) {
      return forbidden("Статистика доступна только администратору");
    }

    const dayStart = new Date();
    dayStart.setHours(0, 0, 0, 0);
    const weekStart = new Date();
    weekStart.setDate(weekStart.getDate() - 7);

    const [
      [{ totalOrders }],
      [{ todayOrders }],
      [{ todayRevenue }],
      [{ activeOrders }],
      [{ onlineDrivers }],
      [{ totalDrivers }],
      [{ totalClients }],
      [{ completedToday }],
      [{ cancelledToday }],
      [{ avgCheck }],
    ] = await Promise.all([
      db.select({ totalOrders: sql<number>`count(*)::int` }).from(orders),
      db.select({ todayOrders: sql<number>`count(*)::int` }).from(orders).where(gte(orders.createdAt, dayStart)),
      db.select({ todayRevenue: sql<number>`coalesce(sum(${orders.finalPrice}), 0)::float` }).from(orders)
        .where(and(gte(orders.createdAt, dayStart), eq(orders.status, "completed"))),
      db.select({ activeOrders: sql<number>`count(*)::int` }).from(orders)
        .where(inArray(orders.status, [...ACTIVE_STATUSES])),
      db.select({ onlineDrivers: sql<number>`count(*)::int` }).from(drivers)
        .where(sql`${drivers.status} != 'offline'`),
      db.select({ totalDrivers: sql<number>`count(*)::int` }).from(drivers),
      db.select({ totalClients: sql<number>`count(*)::int` }).from(users).where(eq(users.role, "client")),
      db.select({ completedToday: sql<number>`count(*)::int` }).from(orders)
        .where(and(gte(orders.createdAt, dayStart), eq(orders.status, "completed"))),
      db.select({ cancelledToday: sql<number>`count(*)::int` }).from(orders)
        .where(and(gte(orders.createdAt, dayStart), eq(orders.status, "cancelled"))),
      db.select({ avgCheck: sql<number>`coalesce(avg(${orders.finalPrice}), 0)::float` }).from(orders)
        .where(eq(orders.status, "completed")),
    ]);

    // Популярные направления недели
    const topRoutes = await db
      .select({
        to: orders.destinationAddress,
        count: sql<number>`count(*)::int`,
      })
      .from(orders)
      .where(and(gte(orders.createdAt, weekStart), sql`${orders.destinationAddress} is not null`))
      .groupBy(orders.destinationAddress)
      .orderBy(sql`count(*) desc`)
      .limit(5);

    // Распределение по тарифам
    const byTariff = await db
      .select({
        tariff: orders.tariff,
        count: sql<number>`count(*)::int`,
        revenue: sql<number>`coalesce(sum(${orders.finalPrice}), 0)::float`,
      })
      .from(orders)
      .groupBy(orders.tariff);

    // Выручка по дням (7 суток включая сегодня)
    const dailyRows = await db
      .select({
        day: sql<string>`to_char(${orders.completedAt}, 'YYYY-MM-DD')`,
        revenue: sql<number>`coalesce(sum(${orders.finalPrice}), 0)::float`,
        count: sql<number>`count(*)::int`,
      })
      .from(orders)
      .where(and(eq(orders.status, "completed"), gte(orders.completedAt, weekStart)))
      .groupBy(sql`to_char(${orders.completedAt}, 'YYYY-MM-DD')`)
      .orderBy(sql`to_char(${orders.completedAt}, 'YYYY-MM-DD')`);

    const revenueByDay: { day: string; revenue: number; count: number }[] = [];
    for (let i = 6; i >= 0; i--) {
      const d = new Date();
      d.setDate(d.getDate() - i);
      const key = d.toISOString().slice(0, 10);
      const row = dailyRows.find((r) => r.day === key);
      revenueByDay.push({
        day: key,
        revenue: Math.round(row?.revenue ?? 0),
        count: row?.count ?? 0,
      });
    }

    // Заказы по часам суток (по времени Тюмени UTC+5)
    const hourlyRows = await db
      .select({
        hour: sql<number>`extract(hour from ${orders.createdAt} + interval '5 hours')::int`,
        count: sql<number>`count(*)::int`,
      })
      .from(orders)
      .groupBy(sql`extract(hour from ${orders.createdAt} + interval '5 hours')`);

    const ordersByHour = Array.from({ length: 24 }, (_, h) => ({
      hour: h,
      count: hourlyRows.find((r) => r.hour === h)?.count ?? 0,
    }));

    return NextResponse.json({
      totalOrders,
      todayOrders,
      todayRevenue: Math.round(todayRevenue),
      activeOrders,
      onlineDrivers,
      totalDrivers,
      totalClients,
      completedToday,
      cancelledToday,
      avgCheck: Math.round(avgCheck),
      topRoutes,
      byTariff,
      revenueByDay,
      ordersByHour,
    });
  } catch (e) {
    return NextResponse.json(
      { error: e instanceof Error ? e.message : "Ошибка статистики" },
      { status: 500 }
    );
  }
}
