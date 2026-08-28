// POST /api/drivers/[id]/action — UpdateStatus / UpdateLocation / topup / verify (BalanceService.TopUpAsync)
import { NextResponse } from "next/server";
import { db } from "@/db";
import { drivers, balanceTransactions } from "@/db/schema";
import { eq, desc } from "drizzle-orm";

export async function POST(
  req: Request,
  ctx: { params: Promise<{ id: string }> }
) {
  try {
    const { id } = await ctx.params;
    const body = await req.json();
    const action = String(body.action ?? "");
    const [driver] = await db.select().from(drivers).where(eq(drivers.id, id));
    if (!driver) return NextResponse.json({ error: "Водитель не найден" }, { status: 404 });

    if (action === "status") {
      const status = String(body.status ?? "offline");
      const goingOffline = status === "offline";
      await db
        .update(drivers)
        .set({
          status: status as never,
          ...(goingOffline ? { currentOrderId: null } : {}),
          lastLocationUpdate: new Date(),
        })
        .where(eq(drivers.id, id));
      return NextResponse.json({ ok: true, status });
    }

    if (action === "location") {
      const lat = Number(body.latitude);
      const lng = Number(body.longitude);
      if (!Number.isFinite(lat) || !Number.isFinite(lng))
        return NextResponse.json({ error: "Некорректные координаты" }, { status: 400 });
      await db
        .update(drivers)
        .set({ latitude: lat, longitude: lng, lastLocationUpdate: new Date() })
        .where(eq(drivers.id, id));
      return NextResponse.json({ ok: true });
    }

    // TopUpAsync
    if (action === "topup") {
      const amount = Number(body.amount);
      if (!Number.isFinite(amount) || amount <= 0)
        return NextResponse.json({ error: "Сумма должна быть больше нуля" }, { status: 400 });
      const newBalance = driver.balance + amount;
      await db.update(drivers).set({ balance: newBalance }).where(eq(drivers.id, id));
      await db.insert(balanceTransactions).values({
        driverId: id,
        type: "topup",
        amount,
        balanceAfter: newBalance,
        description: `Пополнение +${amount.toFixed(0)} руб.`,
        createdBy: String(body.createdBy ?? "system"),
      });
      return NextResponse.json({ ok: true, balance: newBalance });
    }

    if (action === "verify") {
      await db
        .update(drivers)
        .set({ isVerified: Boolean(body.isVerified ?? true) })
        .where(eq(drivers.id, id));
      return NextResponse.json({ ok: true });
    }

    return NextResponse.json({ error: `Неизвестный action: ${action}` }, { status: 400 });
  } catch (e) {
    return NextResponse.json(
      { error: e instanceof Error ? e.message : "Ошибка операции" },
      { status: 500 }
    );
  }
}

// GET /api/drivers/[id]/action?view=balance|history — баланс и история транзакций
export async function GET(
  req: Request,
  ctx: { params: Promise<{ id: string }> }
) {
  const { id } = await ctx.params;
  const url = new URL(req.url);
  const view = url.searchParams.get("view") ?? "balance";

  const [driver] = await db.select().from(drivers).where(eq(drivers.id, id));
  if (!driver) return NextResponse.json({ error: "Водитель не найден" }, { status: 404 });

  if (view === "history") {
    const rows = await db
      .select()
      .from(balanceTransactions)
      .where(eq(balanceTransactions.driverId, id))
      .orderBy(desc(balanceTransactions.createdAt))
      .limit(30);
    return NextResponse.json({ balance: driver.balance, transactions: rows });
  }

  return NextResponse.json({
    balance: driver.balance,
    minBalanceForOrders: driver.minBalanceForOrders,
    todayEarnings: driver.todayEarnings,
    totalEarnings: driver.totalEarnings,
    completedTrips: driver.completedTrips,
    status: driver.status,
    hasSufficientBalance: driver.balance >= driver.minBalanceForOrders,
  });
}
