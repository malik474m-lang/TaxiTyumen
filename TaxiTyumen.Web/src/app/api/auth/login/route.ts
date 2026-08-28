// POST /api/auth/login — порт AuthService.LoginAsync
import { NextResponse } from "next/server";
import { db } from "@/db";
import { users, drivers } from "@/db/schema";
import { eq } from "drizzle-orm";
import { verifyPassword, normalizePhone } from "@/lib/auth";
import { serializeUser } from "@/lib/serialize";
import { ensureSeeded } from "@/lib/seed";

export async function POST(req: Request) {
  try {
    await ensureSeeded();
    const body = await req.json();
    const phone = normalizePhone(String(body.phone ?? ""));
    const password = String(body.password ?? "");

    const [user] = await db.select().from(users).where(eq(users.phone, phone));
    if (!user) {
      return NextResponse.json({ error: "Пользователь не найден" }, { status: 404 });
    }
    if (!verifyPassword(password, user.passwordHash)) {
      return NextResponse.json({ error: "Неверный пароль" }, { status: 401 });
    }
    if (user.isBlocked) {
      return NextResponse.json(
        { error: `Аккаунт заблокирован: ${user.blockReason ?? ""}` },
        { status: 403 }
      );
    }
    if (!user.isActive) {
      return NextResponse.json({ error: "Аккаунт деактивирован" }, { status: 403 });
    }

    await db
      .update(users)
      .set({ lastLoginAt: new Date() })
      .where(eq(users.id, user.id));

    let driverId: string | null = null;
    if (user.role === "driver") {
      const [dp] = await db.select().from(drivers).where(eq(drivers.userId, user.id));
      driverId = dp?.id ?? null;
    }

    return NextResponse.json({ user: serializeUser(user, driverId) });
  } catch (e) {
    return NextResponse.json(
      { error: e instanceof Error ? e.message : "Ошибка входа" },
      { status: 500 }
    );
  }
}
