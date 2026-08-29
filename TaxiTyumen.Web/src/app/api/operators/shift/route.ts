// Порт OperatorsController: смены операторов
// POST {action: "start"|"end"} | GET — текущая смена + выработка
import { NextResponse } from "next/server";
import { db } from "@/db";
import { operatorShifts, orders } from "@/db/schema";
import { eq, and, isNull, gte, sql, desc } from "drizzle-orm";
import { readClaims, forbidden, unauthorized, hasAdminRole } from "@/lib/session";

async function shiftStats(operatorId: string, since: Date) {
  const [created] = await db
    .select({ count: sql<number>`count(*)::int` })
    .from(orders)
    .where(and(eq(orders.operatorId, operatorId), gte(orders.createdAt, since)));
  const [completed] = await db
    .select({ count: sql<number>`count(*)::int` })
    .from(orders)
    .where(
      and(
        eq(orders.operatorId, operatorId),
        gte(orders.createdAt, since),
        eq(orders.status, "completed")
      )
    );
  return { ordersCreated: created.count, ordersCompleted: completed.count };
}

export async function GET(req: Request) {
  const claims = readClaims(req);
  if (!claims) return unauthorized();
  if (claims.role !== "operator" && !hasAdminRole(claims.role)) {
    return forbidden("Смены доступны только оператору");
  }

  const [shift] = await db
    .select()
    .from(operatorShifts)
    .where(and(eq(operatorShifts.operatorId, claims.uid), isNull(operatorShifts.endedAt)))
    .orderBy(desc(operatorShifts.startedAt))
    .limit(1);

  if (!shift) return NextResponse.json({ active: false });
  const stats = await shiftStats(claims.uid, shift.startedAt);
  return NextResponse.json({ active: true, shift, ...stats });
}

export async function POST(req: Request) {
  const claims = readClaims(req);
  if (!claims) return unauthorized();
  if (claims.role !== "operator" && !hasAdminRole(claims.role)) {
    return forbidden("Управлять сменой может только оператор");
  }

  const body = await req.json();
  const action = String(body.action ?? "");

  const [open] = await db
    .select()
    .from(operatorShifts)
    .where(and(eq(operatorShifts.operatorId, claims.uid), isNull(operatorShifts.endedAt)))
    .limit(1);

  if (action === "start") {
    if (open) {
      const stats = await shiftStats(claims.uid, open.startedAt);
      return NextResponse.json({ active: true, shift: open, ...stats, already: true });
    }
    const [shift] = await db
      .insert(operatorShifts)
      .values({ operatorId: claims.uid })
      .returning();
    return NextResponse.json({ active: true, shift, ordersCreated: 0, ordersCompleted: 0 });
  }

  if (action === "end") {
    if (!open) return NextResponse.json({ active: false });
    await db
      .update(operatorShifts)
      .set({ endedAt: new Date() })
      .where(eq(operatorShifts.id, open.id));
    const stats = await shiftStats(claims.uid, open.startedAt);
    return NextResponse.json({
      active: false,
      startedAt: open.startedAt,
      endedAt: new Date(),
      ...stats,
    });
  }

  return NextResponse.json({ error: `Неизвестный action: ${action}` }, { status: 400 });
}
