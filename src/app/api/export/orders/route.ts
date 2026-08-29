// GET /api/export/orders — выгрузка заказов в CSV (порт Export.razor). Только админ.
import { NextResponse } from "next/server";
import { db } from "@/db";
import { orders } from "@/db/schema";
import { desc } from "drizzle-orm";
import { readClaims, forbidden, hasAdminRole } from "@/lib/session";
import { STATUS_TEXT, TARIFF_NAMES, PAYMENT_NAMES } from "@/lib/taxi";

function csvEscape(v: string | number | null | undefined): string {
  const s = v === null || v === undefined ? "" : String(v);
  return `"${s.replace(/"/g, '""')}"`;
}

export async function GET(req: Request) {
  const claims = readClaims(req);
  if (!claims || !hasAdminRole(claims.role)) {
    return forbidden("Экспорт доступен только администратору");
  }

  const rows = await db.select().from(orders).orderBy(desc(orders.createdAt)).limit(1000);

  const header = [
    "Номер заказа",
    "Дата создания",
    "Статус",
    "Откуда",
    "Куда",
    "Тариф",
    "Оценка цены, руб",
    "Итог, руб",
    "Оплата",
    "Клиент",
    "Телефон",
    "Источник",
  ]
    .map(csvEscape)
    .join(";");

  const fmt = (d: Date | null) =>
    d
      ? d.toLocaleString("ru-RU", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" })
      : "";

  const lines = rows.map((o) =>
    [
      o.orderNumber,
      fmt(o.createdAt),
      STATUS_TEXT[o.status] ?? o.status,
      o.pickupAddress,
      o.destinationAddress ?? "",
      TARIFF_NAMES[o.tariff] ?? o.tariff,
      Math.round(o.estimatedPrice),
      o.finalPrice !== null ? Math.round(o.finalPrice) : "",
      PAYMENT_NAMES[o.paymentMethod] ?? o.paymentMethod,
      o.clientName ?? "",
      o.clientPhone ?? "",
      o.source === "operator_app" ? "Диспетчерская" : "Приложение",
    ]
      .map(csvEscape)
      .join(";")
  );

  // UTF-8 BOM — чтобы Excel корректно открыл кириллицу
  const csv = "﻿" + [header, ...lines].join("\r\n");
  const stamp = new Date().toISOString().slice(0, 10);

  return new NextResponse(csv, {
    headers: {
      "Content-Type": "text/csv; charset=utf-8",
      "Content-Disposition": `attachment; filename="taxi-tyumen-orders-${stamp}.csv"`,
    },
  });
}
