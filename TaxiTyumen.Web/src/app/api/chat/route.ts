// GET /api/chat?orderId= — история | POST /api/chat — SendMessage (порт ChatController)
import { NextResponse } from "next/server";
import { db } from "@/db";
import { chatMessages, orders, users } from "@/db/schema";
import { eq, asc, desc } from "drizzle-orm";
import { publishEvent } from "@/lib/bus";
import { readClaims, unauthorized, forbidden } from "@/lib/session";

export async function GET(req: Request) {
  const url = new URL(req.url);
  const orderId = url.searchParams.get("orderId");
  if (!orderId) return NextResponse.json({ error: "orderId обязателен" }, { status: 400 });

  const rows = await db
    .select()
    .from(chatMessages)
    .where(eq(chatMessages.orderId, orderId))
    .orderBy(asc(chatMessages.createdAt))
    .limit(100);
  return NextResponse.json(rows);
}

export async function POST(req: Request) {
  try {
    const body = await req.json();
    const orderId = String(body.orderId ?? "");
    const senderId = String(body.senderId ?? "");

    // Сообщение можно отправить только от своего имени
    const claims = readClaims(req);
    if (!claims) return unauthorized();
    if (claims.uid !== senderId) {
      return forbidden("Нельзя писать от чужого имени");
    }

    const text = String(body.text ?? "").trim();
    if (!orderId || !senderId || !text)
      return NextResponse.json(
        { error: "orderId, senderId и text обязательны" },
        { status: 400 }
      );

    const [order] = await db.select().from(orders).where(eq(orders.id, orderId));
    if (!order) return NextResponse.json({ error: "Заказ не найден" }, { status: 404 });

    const [sender] = await db.select().from(users).where(eq(users.id, senderId));
    const senderName = sender ? `${sender.firstName} ${sender.lastName}` : "—";
    const senderRole = (sender?.role ?? "client") as "client" | "driver" | "operator" | "admin";

    const [message] = await db
      .insert(chatMessages)
      .values({ orderId, senderId, senderName, senderRole, text })
      .returning();

    publishEvent("chat");
    return NextResponse.json(message, { status: 201 });
  } catch (e) {
    return NextResponse.json(
      { error: e instanceof Error ? e.message : "Ошибка отправки" },
      { status: 500 }
    );
  }
}
