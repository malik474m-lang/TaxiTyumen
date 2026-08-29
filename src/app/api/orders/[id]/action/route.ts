// POST /api/orders/[id]/action — весь жизненный цикл заказа
// (AcceptOrderAsync / RejectOrderAsync / UpdateOrderStatusAsync / CompleteOrderAsync /
//  CancelOrderAsync / ForceAssignOrderAsync + BalanceService)
import { NextResponse } from "next/server";
import { db } from "@/db";
import {
  orders,
  drivers,
  tariffs,
  users,
  orderRejections,
  balanceTransactions,
} from "@/db/schema";
import { eq, sql, and, isNotNull } from "drizzle-orm";
import { serializeOrder } from "@/lib/serialize";
import { publishEvent } from "@/lib/bus";
import { readClaims, unauthorized, forbidden } from "@/lib/session";

type Ctx = { params: Promise<{ id: string }> };

async function loadOrder(id: string) {
  const [order] = await db.select().from(orders).where(eq(orders.id, id));
  return order ?? null;
}

async function chargeCommission(driverId: string, orderId: string, price: number, percent: number) {
  const [driver] = await db.select().from(drivers).where(eq(drivers.id, driverId));
  if (!driver) return;
  const commission = Math.round(((price * percent) / 100) * 100) / 100;
  const newBalance = driver.balance - commission;
  await db.update(drivers).set({ balance: newBalance }).where(eq(drivers.id, driverId));
  await db.insert(balanceTransactions).values({
    driverId,
    orderId,
    type: "commission",
    amount: -commission,
    balanceAfter: newBalance,
    description: `Комиссия ${percent}% (${price.toFixed(0)} руб.)`,
  });
}

async function chargePenalty(driverId: string, orderId: string, penalty: number, description: string) {
  const [driver] = await db.select().from(drivers).where(eq(drivers.id, driverId));
  if (!driver) return;
  const newBalance = driver.balance - penalty;
  await db.update(drivers).set({ balance: newBalance }).where(eq(drivers.id, driverId));
  await db.insert(balanceTransactions).values({
    driverId,
    orderId,
    type: "penalty",
    amount: -penalty,
    balanceAfter: newBalance,
    description,
  });
}

export async function POST(req: Request, ctx: Ctx) {
  try {
    const { id } = await ctx.params;
    const body = await req.json();
    const action = String(body.action ?? "");
    const order = await loadOrder(id);
    if (!order) return NextResponse.json({ error: "Заказ не найден" }, { status: 404 });

    // ── Авторизация действий по ролям (роли не пересекаются) ─────────────
    const claims = readClaims(req);
    if (!claims) return unauthorized();

    if (["accept", "reject", "arrived", "start", "complete"].includes(action)) {
      const requestedDriverId = String(body.driverId ?? order.driverId ?? "");
      if (claims.role !== "driver" || claims.driverId !== requestedDriverId) {
        return forbidden("Действие доступно только водителю от своего имени");
      }
      if (
        ["arrived", "start", "complete"].includes(action) &&
        order.driverId !== claims.driverId
      ) {
        return forbidden("Этот заказ принадлежит другому водителю");
      }
    } else if (action === "assign") {
      if (claims.role !== "operator" && claims.role !== "admin") {
        return forbidden("Назначать водителя может только диспетчерская");
      }
    } else if (action === "cancel") {
      const isOwner = order.clientId !== null && order.clientId === claims.uid;
      if (!isOwner && claims.role !== "operator" && claims.role !== "admin") {
        return forbidden("Отменить заказ может клиент-владелец или диспетчерская");
      }
    } else if (action === "rate") {
      if (order.clientId !== claims.uid) {
        return forbidden("Оценить поездку может только клиент заказа");
      }
    }

    // ── AcceptOrderAsync ──────────────────────────────────────────────────
    if (action === "accept") {
      const driverId = String(body.driverId ?? "");
      if (order.status !== "searching" && order.status !== "no_driver_found")
        return NextResponse.json({ error: "Заказ уже принят или недоступен" }, { status: 409 });

      const [driver] = await db.select().from(drivers).where(eq(drivers.id, driverId));
      if (!driver) return NextResponse.json({ error: "Водитель не найден" }, { status: 404 });
      if (driver.balance < driver.minBalanceForOrders)
        return NextResponse.json(
          {
            error: `Недостаточно средств на балансе. Баланс: ${driver.balance.toFixed(0)} руб., минимум: ${driver.minBalanceForOrders.toFixed(0)} руб.`,
          },
          { status: 400 }
        );

      await db
        .update(orders)
        .set({ driverId, status: "driver_assigned", acceptedAt: new Date() })
        .where(eq(orders.id, id));
      await db
        .update(drivers)
        .set({ status: "on_route", currentOrderId: id })
        .where(eq(drivers.id, driverId));

      publishEvent("orders");
      return NextResponse.json(await serializeOrder((await loadOrder(id))!));
    }

    // ── RejectOrderAsync ──────────────────────────────────────────────────
    if (action === "reject") {
      const driverId = String(body.driverId ?? "");
      const reason = body.reason ? String(body.reason) : null;

      await db.insert(orderRejections).values({ orderId: id, driverId, reason });

      // Если заказ был назначен на этого водителя — возвращаем в поиск
      if (order.driverId === driverId) {
        await db
          .update(orders)
          .set({ driverId: null, status: "searching", acceptedAt: null })
          .where(eq(orders.id, id));
        const [d] = await db.select().from(drivers).where(eq(drivers.id, driverId));
        await db
          .update(drivers)
          .set({
            status: "available",
            currentOrderId: null,
            cancelledTrips: (d?.cancelledTrips ?? 0) + 1,
          })
          .where(eq(drivers.id, driverId));
      }

      // Штраф за отказ
      const [driver] = await db.select().from(drivers).where(eq(drivers.id, driverId));
      if (driver && driver.rejectionPenalty > 0) {
        await chargePenalty(driverId, id, driver.rejectionPenalty, "Штраф за отказ от заказа");
      }

      publishEvent("orders");
      return NextResponse.json(await serializeOrder((await loadOrder(id))!));
    }

    // ── UpdateOrderStatusAsync: arrived / start ───────────────────────────
    if (action === "arrived") {
      await db
        .update(orders)
        .set({ status: "driver_arrived", driverArrivedAt: new Date() })
        .where(eq(orders.id, id));
      publishEvent("orders");
      return NextResponse.json(await serializeOrder((await loadOrder(id))!));
    }

    if (action === "start") {
      await db
        .update(orders)
        .set({ status: "in_progress", tripStartedAt: new Date() })
        .where(eq(orders.id, id));
      if (order.driverId) {
        await db
          .update(drivers)
          .set({ status: "in_trip" })
          .where(eq(drivers.id, order.driverId));
      }
      publishEvent("orders");
      return NextResponse.json(await serializeOrder((await loadOrder(id))!));
    }

    // ── CompleteOrderAsync ────────────────────────────────────────────────
    if (action === "complete") {
      const finalPrice = Number(body.finalPrice ?? order.estimatedPrice) || order.estimatedPrice;
      await db
        .update(orders)
        .set({ status: "completed", completedAt: new Date(), finalPrice })
        .where(eq(orders.id, id));

      if (order.driverId) {
        const [driver] = await db.select().from(drivers).where(eq(drivers.id, order.driverId));
        if (driver) {
          await db
            .update(drivers)
            .set({
              status: "available",
              currentOrderId: null,
              completedTrips: driver.completedTrips + 1,
              totalEarnings: driver.totalEarnings + finalPrice,
              todayEarnings: driver.todayEarnings + finalPrice,
            })
            .where(eq(drivers.id, driver.id));

          const [tariff] = await db
            .select()
            .from(tariffs)
            .where(eq(tariffs.type, order.tariff));
          const commissionPercent = tariff?.commissionPercent ?? 15;
          await chargeCommission(driver.id, id, finalPrice, commissionPercent);
        }
      }
      publishEvent("orders");
      return NextResponse.json(await serializeOrder((await loadOrder(id))!));
    }

    // ── CancelOrderAsync ──────────────────────────────────────────────────
    if (action === "cancel") {
      await db
        .update(orders)
        .set({
          status: "cancelled",
          cancelledAt: new Date(),
          cancellationReason: body.reason ? String(body.reason) : null,
        })
        .where(eq(orders.id, id));
      if (order.driverId) {
        await db
          .update(drivers)
          .set({ status: "available", currentOrderId: null })
          .where(eq(drivers.id, order.driverId));
      }
      publishEvent("orders");
      return NextResponse.json(await serializeOrder((await loadOrder(id))!));
    }

    // ── ForceAssignOrderAsync (оператор) ──────────────────────────────────
    if (action === "assign") {
      const driverId = String(body.driverId ?? "");
      if (order.status === "completed" || order.status === "cancelled")
        return NextResponse.json({ error: "Заказ уже завершён или отменён" }, { status: 409 });

      const [newDriver] = await db.select().from(drivers).where(eq(drivers.id, driverId));
      if (!newDriver) return NextResponse.json({ error: "Водитель не найден" }, { status: 404 });

      // Освобождаем прежнего водителя
      if (order.driverId && order.driverId !== driverId) {
        await db
          .update(drivers)
          .set({ status: "available", currentOrderId: null })
          .where(eq(drivers.id, order.driverId));
      }

      await db
        .update(orders)
        .set({ driverId, status: "driver_assigned", acceptedAt: new Date() })
        .where(eq(orders.id, id));
      await db
        .update(drivers)
        .set({ status: "on_route", currentOrderId: id })
        .where(eq(drivers.id, driverId));

      publishEvent("orders");
      return NextResponse.json(await serializeOrder((await loadOrder(id))!));
    }

    // ── Оценка поездки клиентом ───────────────────────────────────────────
    if (action === "rate") {
      const rating = Math.min(5, Math.max(1, Number(body.rating ?? 5)));
      await db
        .update(orders)
        .set({ clientRating: rating })
        .where(eq(orders.id, id));

      // Пересчёт рейтинга водителя по всем оценённым поездкам (как Rating в оригинале)
      if (order.driverId) {
        const [agg] = await db
          .select({ avg: sql<number>`avg(${orders.clientRating})::float` })
          .from(orders)
          .where(
            and(eq(orders.driverId, order.driverId), isNotNull(orders.clientRating))
          );
        const [drv] = await db.select().from(drivers).where(eq(drivers.id, order.driverId));
        if (drv && agg?.avg != null) {
          await db
            .update(users)
            .set({ rating: Math.round(agg.avg * 10) / 10 })
            .where(eq(users.id, drv.userId));
        }
      }
      publishEvent("orders");
      return NextResponse.json(await serializeOrder((await loadOrder(id))!));
    }

    return NextResponse.json({ error: `Неизвестный action: ${action}` }, { status: 400 });
  } catch (e) {
    return NextResponse.json(
      { error: e instanceof Error ? e.message : "Ошибка операции" },
      { status: 500 }
    );
  }
}
