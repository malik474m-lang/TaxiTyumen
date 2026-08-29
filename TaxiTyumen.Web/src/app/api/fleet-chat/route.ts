// GET /api/fleet-chat — общий чат водителей автопарка (последние 150)
// Водители читают и пишут; оператор/админ читают и модерируют (DELETE ?id=).
// POST — только водитель, от своего имени (берём профиль из токена).
import { NextResponse } from "next/server";
import { db } from "@/db";
import { drivers, fleetMessages, users } from "@/db/schema";
import { asc, desc, eq, gt } from "drizzle-orm";
import { publishEvent } from "@/lib/bus";
import { readClaims, unauthorized, forbidden } from "@/lib/session";
import { ensureSeeded } from "@/lib/seed";

const MAX_TEXT = 500;
const HISTORY_LIMIT = 150;
const MIN_INTERVAL_MS = 1500; // анти-спам: не чаще 1 сообщения в 1.5 с

// In-memory троттлинг отправителей (процесс песочницы один — достаточно)
const g = globalThis as typeof globalThis & { __fleetLastSent?: Map<string, number> };
const lastSent = (g.__fleetLastSent ??= new Map<string, number>());

function canRead(role?: string | null) {
  return role === "driver" || role === "operator" || role === "admin" || role === "superadmin";
}

export async function GET(req: Request) {
  const claims = readClaims(req);
  if (!claims) return unauthorized();
  if (!canRead(claims.role)) return forbidden("Чат автопарка доступен водителям, диспетчерам и администраторам");

  await ensureSeeded();
  const url = new URL(req.url);
  const afterTs = Number(url.searchParams.get("after") ?? 0);

  // Инкрементальная выборка (?after=ms) либо последние N сообщений
  const rows =
    afterTs > 0
      ? await db
          .select()
          .from(fleetMessages)
          .where(gt(fleetMessages.createdAt, new Date(afterTs)))
          .orderBy(asc(fleetMessages.createdAt))
          .limit(HISTORY_LIMIT)
      : (
          await db
            .select()
            .from(fleetMessages)
            .orderBy(desc(fleetMessages.createdAt))
            .limit(HISTORY_LIMIT)
        ).reverse();

  return NextResponse.json(
    rows.map((m) => ({
      id: m.id,
      senderId: m.senderId,
      senderName: m.senderName,
      carInfo: m.carInfo,
      text: m.text,
      createdAt: m.createdAt.toISOString(),
    }))
  );
}

export async function POST(req: Request) {
  try {
    const claims = readClaims(req);
    if (!claims) return unauthorized();
    if (claims.role !== "driver" || !claims.driverId) {
      return forbidden("Писать в чат автопарка могут только водители");
    }

    const now = Date.now();
    const last = lastSent.get(claims.uid) ?? 0;
    if (now - last < MIN_INTERVAL_MS) {
      return NextResponse.json({ error: "Слишком часто — подождите секунду" }, { status: 429 });
    }

    const body = await req.json();
    const text = String(body.text ?? "").trim().slice(0, MAX_TEXT);
    if (!text) return NextResponse.json({ error: "Пустое сообщение" }, { status: 400 });

    const [driver] = await db.select().from(drivers).where(eq(drivers.id, claims.driverId)).limit(1);
    const [sender] = await db.select().from(users).where(eq(users.id, claims.uid)).limit(1);
    if (!driver || !sender) return NextResponse.json({ error: "Профиль водителя не найден" }, { status: 404 });
    if (sender.isBlocked) return forbidden("Аккаунт заблокирован");

    const carInfo = `${driver.carBrand} ${driver.carModel} · ${driver.licensePlate}`;
    const [message] = await db
      .insert(fleetMessages)
      .values({
        senderId: sender.id,
        senderName: `${sender.firstName} ${sender.lastName}`.trim(),
        carInfo,
        text,
      })
      .returning();

    lastSent.set(claims.uid, now);
    publishEvent("fleet");

    return NextResponse.json(
      {
        id: message.id,
        senderId: message.senderId,
        senderName: message.senderName,
        carInfo: message.carInfo,
        text: message.text,
        createdAt: message.createdAt.toISOString(),
      },
      { status: 201 }
    );
  } catch (e) {
    return NextResponse.json(
      { error: e instanceof Error ? e.message : "Ошибка отправки" },
      { status: 500 }
    );
  }
}

export async function DELETE(req: Request) {
  const claims = readClaims(req);
  if (!claims) return unauthorized();
  if (claims.role !== "operator" && claims.role !== "admin" && claims.role !== "superadmin") {
    return forbidden("Удалять сообщения могут только диспетчер и администратор");
  }

  const id = new URL(req.url).searchParams.get("id");
  if (!id) return NextResponse.json({ error: "id обязателен" }, { status: 400 });

  const [deleted] = await db.delete(fleetMessages).where(eq(fleetMessages.id, id)).returning();
  if (!deleted) return NextResponse.json({ error: "Сообщение не найдено" }, { status: 404 });

  publishEvent("fleet");
  return NextResponse.json({ ok: true });
}
