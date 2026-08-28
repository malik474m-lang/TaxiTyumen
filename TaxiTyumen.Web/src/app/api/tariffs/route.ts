// GET /api/tariffs — список тарифов | PUT /api/tariffs — обновление (админка)
import { NextResponse } from "next/server";
import { db } from "@/db";
import { tariffs } from "@/db/schema";
import { eq } from "drizzle-orm";
import { ensureSeeded } from "@/lib/seed";

export async function GET() {
  await ensureSeeded();
  const rows = await db.select().from(tariffs).orderBy(tariffs.baseFare);
  return NextResponse.json(rows);
}

export async function PUT(req: Request) {
  try {
    const body = await req.json();
    const id = String(body.id ?? "");
    const [existing] = await db.select().from(tariffs).where(eq(tariffs.id, id));
    if (!existing) return NextResponse.json({ error: "Тариф не найден" }, { status: 404 });

    const fields = {
      name: body.name !== undefined ? String(body.name) : existing.name,
      description:
        body.description !== undefined ? String(body.description) : existing.description,
      baseFare: Number(body.baseFare ?? existing.baseFare),
      pricePerKm: Number(body.pricePerKm ?? existing.pricePerKm),
      pricePerMinute: Number(body.pricePerMinute ?? existing.pricePerMinute),
      minimumFare: Number(body.minimumFare ?? existing.minimumFare),
      nightMultiplier: Number(body.nightMultiplier ?? existing.nightMultiplier),
      peakMultiplier: Number(body.peakMultiplier ?? existing.peakMultiplier),
      commissionPercent: Number(body.commissionPercent ?? existing.commissionPercent),
      paidWaitingPerMinute: Number(body.paidWaitingPerMinute ?? existing.paidWaitingPerMinute),
      isActive: Boolean(body.isActive ?? existing.isActive),
      updatedAt: new Date(),
    };

    await db.update(tariffs).set(fields).where(eq(tariffs.id, id));
    const [updated] = await db.select().from(tariffs).where(eq(tariffs.id, id));
    return NextResponse.json(updated);
  } catch (e) {
    return NextResponse.json(
      { error: e instanceof Error ? e.message : "Ошибка обновления тарифа" },
      { status: 500 }
    );
  }
}
