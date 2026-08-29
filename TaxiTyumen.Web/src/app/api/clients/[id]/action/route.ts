// POST /api/clients/[id]/action — модерация клиента (только администратор):
// block (с причиной) / unblock / verify / unverify телефона.
// Блокировка действует сразу: логин и SMS-вход уже проверяют isBlocked;
// активные заказы клиента при этом не трогаем (как в .NET-версии).
import { NextResponse } from "next/server";
import { db } from "@/db";
import { users } from "@/db/schema";
import { eq } from "drizzle-orm";
import { publishEvent } from "@/lib/bus";
import { readClaims, unauthorized, forbidden, hasAdminRole } from "@/lib/session";

export async function POST(
  req: Request,
  ctx: { params: Promise<{ id: string }> }
) {
  try {
    const claims = readClaims(req);
    if (!claims) return unauthorized();
    if (!hasAdminRole(claims.role)) {
      return forbidden("Модерация клиентов доступна только администратору");
    }

    const { id } = await ctx.params;
    const [user] = await db.select().from(users).where(eq(users.id, id)).limit(1);
    if (!user) return NextResponse.json({ error: "Клиент не найден" }, { status: 404 });
    if (user.role !== "client") {
      return forbidden("Действие применимо только к клиентам");
    }

    const body = await req.json();
    const action = String(body.action ?? "");

    if (action === "block") {
      const reason = String(body.reason ?? "").trim().slice(0, 200) || "Нарушение правил сервиса";
      if (claims.role !== "superadmin" && user.isPhoneVerified && user.totalTrips > 50) {
        return forbidden("Клиента с большой историей может блокировать только супер-администратор");
      }
      await db.update(users).set({ isBlocked: true, blockReason: reason }).where(eq(users.id, id));
      publishEvent("clients");
      return NextResponse.json({ ok: true, isBlocked: true, blockReason: reason });
    }

    if (action === "unblock") {
      await db.update(users).set({ isBlocked: false, blockReason: null }).where(eq(users.id, id));
      publishEvent("clients");
      return NextResponse.json({ ok: true, isBlocked: false });
    }

    if (action === "verify" || action === "unverify") {
      await db
        .update(users)
        .set({ isPhoneVerified: action === "verify" })
        .where(eq(users.id, id));
      publishEvent("clients");
      return NextResponse.json({ ok: true, isPhoneVerified: action === "verify" });
    }

    return NextResponse.json({ error: `Неизвестное действие: ${action}` }, { status: 400 });
  } catch (e) {
    return NextResponse.json(
      { error: e instanceof Error ? e.message : "Ошибка модерации клиента" },
      { status: 500 }
    );
  }
}
