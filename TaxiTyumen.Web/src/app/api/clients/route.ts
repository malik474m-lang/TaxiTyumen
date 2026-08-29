// GET /api/clients — список клиентов с метриками поездок (порт Clients.razor из TaxiAdmin)
// Доступно только администраторам. Поддерживает поиск ?q= по имени/телефону.
import { NextResponse } from "next/server";
import { db } from "@/db";
import { orders, users } from "@/db/schema";
import { and, desc, eq, inArray, sql } from "drizzle-orm";
import { readClaims, unauthorized, forbidden, hasAdminRole } from "@/lib/session";
import { ensureSeeded } from "@/lib/seed";

export async function GET(req: Request) {
  const claims = readClaims(req);
  if (!claims) return unauthorized();
  if (!hasAdminRole(claims.role)) return forbidden("Список клиентов доступен только администратору");

  await ensureSeeded();

  const q = new URL(req.url).searchParams.get("q")?.trim().toLowerCase() ?? "";

  const rows = await db
    .select()
    .from(users)
    .where(and(eq(users.role, "client"), eq(users.isArchived, false)))
    .orderBy(desc(users.createdAt))
    .limit(1000);

  const filtered = q
    ? rows.filter(
        (u) =>
          u.phone.toLowerCase().includes(q) ||
          `${u.firstName} ${u.lastName}`.toLowerCase().includes(q)
      )
    : rows;
  const slice = filtered.slice(0, 200);

  const ids = slice.map((u) => u.id);
  const metrics = ids.length
    ? await db
        .select({
          clientId: orders.clientId,
          trips: sql<number>`count(*)::int`,
          completed: sql<number>`count(*) filter (where ${orders.status} = 'completed')::int`,
          cancelled: sql<number>`count(*) filter (where ${orders.status} = 'cancelled')::int`,
          spent: sql<number>`coalesce(sum(${orders.finalPrice}) filter (where ${orders.status} = 'completed'), 0)::float8`,
          lastTripAt: sql<string | null>`max(${orders.createdAt})`,
        })
        .from(orders)
        .where(inArray(orders.clientId, ids))
        .groupBy(orders.clientId)
    : [];
  const byId = new Map(metrics.map((m) => [m.clientId, m]));

  return NextResponse.json(
    slice.map((u) => {
      const m = byId.get(u.id);
      return {
        id: u.id,
        phone: u.phone,
        firstName: u.firstName,
        lastName: u.lastName,
        name: `${u.firstName} ${u.lastName}`.trim(),
        email: u.email,
        rating: u.rating,
        isPhoneVerified: u.isPhoneVerified,
        isBlocked: u.isBlocked,
        blockReason: u.blockReason,
        trips: m?.trips ?? 0,
        completedTrips: m?.completed ?? 0,
        cancelledTrips: m?.cancelled ?? 0,
        totalSpent: m?.spent ?? 0,
        lastTripAt: m?.lastTripAt ?? null,
        createdAt: u.createdAt.toISOString(),
        lastLoginAt: u.lastLoginAt?.toISOString() ?? null,
      };
    })
  );
}
