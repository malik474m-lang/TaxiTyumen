// POST /api/auth/register — порт AuthService.RegisterAsync (+ профиль водителя)
import { NextResponse } from "next/server";
import { db } from "@/db";
import { users, drivers } from "@/db/schema";
import { eq } from "drizzle-orm";
import { hashPassword, normalizePhone } from "@/lib/auth";
import { serializeUser } from "@/lib/serialize";
import { ensureSeeded } from "@/lib/seed";
import { CITY } from "@/lib/taxi";
import { signToken } from "@/lib/session";

export async function POST(req: Request) {
  try {
    await ensureSeeded();
    const body = await req.json();
    const phone = normalizePhone(String(body.phone ?? ""));
    const password = String(body.password ?? "");
    const firstName = String(body.firstName ?? "").trim();
    const lastName = String(body.lastName ?? "").trim();
    const role = body.role === "driver" ? "driver" : "client";

    if (!phone || phone.length < 11)
      return NextResponse.json({ error: "Укажите корректный телефон" }, { status: 400 });
    if (password.length < 6)
      return NextResponse.json({ error: "Пароль — минимум 6 символов" }, { status: 400 });
    if (!firstName)
      return NextResponse.json({ error: "Укажите имя" }, { status: 400 });

    const [existing] = await db.select().from(users).where(eq(users.phone, phone));
    if (existing) {
      return NextResponse.json(
        { error: "Пользователь с таким номером уже существует" },
        { status: 409 }
      );
    }

    const [user] = await db
      .insert(users)
      .values({
        phone,
        firstName,
        lastName: lastName || firstName,
        email: body.email ?? null,
        passwordHash: hashPassword(password),
        role,
      })
      .returning();

    let driverId: string | null = null;
    if (role === "driver") {
      const jitter = () => (Math.random() - 0.5) * 0.05;
      const [dp] = await db
        .insert(drivers)
        .values({
          userId: user.id,
          carBrand: String(body.carBrand ?? "Авто"),
          carModel: String(body.carModel ?? ""),
          carColor: String(body.carColor ?? "Белый"),
          licensePlate: String(body.licensePlate ?? "—"),
          carYear: Number(body.carYear ?? 2020) || 2020,
          latitude: CITY.centerLat + jitter(),
          longitude: CITY.centerLng + jitter(),
          balance: 500, // стартовый баланс для приёма заказов
          rejectionPenalty: 50,
        })
        .returning();
      driverId = dp.id;
    }

    const token = signToken({ uid: user.id, role: user.role, driverId });
    return NextResponse.json(
      { user: { ...serializeUser(user, driverId), token } },
      { status: 201 }
    );
  } catch (e) {
    return NextResponse.json(
      { error: e instanceof Error ? e.message : "Ошибка регистрации" },
      { status: 500 }
    );
  }
}
