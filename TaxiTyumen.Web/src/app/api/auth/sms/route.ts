// POST /api/auth/sms — SendSmsCodeAsync / VerifySmsCodeAsync из AuthService.cs
// action=send: генерирует 4-значный код (5 мин). Если задан SMS_API_ID (sms.ru) —
//   отправка реальная, иначе демо-режим: код возвращается в ответе (как Console.WriteLine в .NET).
// action=verify: проверка кода → вход (авто-регистрация клиента, если номера нет).
import { NextResponse } from "next/server";
import { db } from "@/db";
import { users, drivers } from "@/db/schema";
import { eq } from "drizzle-orm";
import { normalizePhone, hashPassword } from "@/lib/auth";
import { serializeUser } from "@/lib/serialize";
import { ensureSeeded } from "@/lib/seed";
import { getServiceBrand } from "@/lib/branding";
import { signToken } from "@/lib/session";
import { randomBytes } from "node:crypto";

export async function POST(req: Request) {
  try {
    await ensureSeeded();
    const service = await getServiceBrand();
    const body = await req.json();
    const action = String(body.action ?? "");
    const phone = normalizePhone(String(body.phone ?? ""));
    if (!phone || phone.length < 11) {
      return NextResponse.json({ error: "Укажите корректный телефон" }, { status: 400 });
    }

    if (action === "send") {
      const code = String(Math.floor(1000 + Math.random() * 9000));
      const expiry = new Date(Date.now() + 5 * 60 * 1000);

      const [user] = await db.select().from(users).where(eq(users.phone, phone));
      if (user) {
        if (user.isBlocked)
          return NextResponse.json(
            { error: `Аккаунт заблокирован: ${user.blockReason ?? ""}` },
            { status: 403 }
          );
        await db
          .update(users)
          .set({ smsCode: code, smsCodeExpiry: expiry })
          .where(eq(users.id, user.id));
      } else {
        // Авто-регистрация клиента при входе по SMS (как SMS-флоу в оригинале)
        await db.insert(users).values({
          phone,
          firstName: "Клиент",
          lastName: phone.slice(-4),
          passwordHash: hashPassword(randomBytes(16).toString("hex")),
          role: "client",
          smsCode: code,
          smsCodeExpiry: expiry,
        });
      }

      // Реальная отправка через sms.ru, если настроен ключ
      let sent = false;
      const smsApiId = process.env.SMS_API_ID;
      if (smsApiId) {
        try {
          const url = `https://sms.ru/sms/send?api_id=${encodeURIComponent(smsApiId)}&to=${encodeURIComponent(
            phone
          )}&msg=${encodeURIComponent(`${code} — ваш код ${service.smsSenderName}`)}&json=1`;
          const res = await fetch(url, { signal: AbortSignal.timeout(5000) });
          sent = res.ok;
        } catch {
          sent = false;
        }
      }

      console.log(`[SMS] Код для ${phone}: ${code}`); // как в оригинале
      return NextResponse.json({
        ok: true,
        expiresIn: 300,
        smsProvider: sent ? "sms.ru" : null,
        // Демо-режим без провайдера: показываем код в интерфейсе
        devCode: sent ? undefined : code,
      });
    }

    if (action === "verify") {
      const code = String(body.code ?? "").trim();
      const [user] = await db.select().from(users).where(eq(users.phone, phone));
      if (!user) return NextResponse.json({ error: "Пользователь не найден" }, { status: 404 });
      if (!user.smsCode || user.smsCode !== code)
        return NextResponse.json({ error: "Неверный код" }, { status: 401 });
      if (user.smsCodeExpiry && user.smsCodeExpiry < new Date())
        return NextResponse.json({ error: "Код истёк, запросите новый" }, { status: 410 });
      if (user.isBlocked)
        return NextResponse.json(
          { error: `Аккаунт заблокирован: ${user.blockReason ?? ""}` },
          { status: 403 }
        );

      await db
        .update(users)
        .set({
          smsCode: null,
          smsCodeExpiry: null,
          isPhoneVerified: true,
          lastLoginAt: new Date(),
        })
        .where(eq(users.id, user.id));

      let driverId: string | null = null;
      if (user.role === "driver") {
        const [dp] = await db.select().from(drivers).where(eq(drivers.userId, user.id));
        driverId = dp?.id ?? null;
      }
      const token = signToken({ uid: user.id, role: user.role, driverId });
      return NextResponse.json({
        user: { ...serializeUser(user, driverId), token },
      });
    }

    return NextResponse.json({ error: `Неизвестный action: ${action}` }, { status: 400 });
  } catch (e) {
    return NextResponse.json(
      { error: e instanceof Error ? e.message : "Ошибка SMS-сервиса" },
      { status: 500 }
    );
  }
}
