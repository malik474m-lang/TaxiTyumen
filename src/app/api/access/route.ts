// GET /api/access — разделы и их видимость | PUT — изменение (только superadmin)
import { NextResponse } from "next/server";
import { readClaims, forbidden, unauthorized, hasAdminRole } from "@/lib/session";
import {
  ADMIN_SECTIONS,
  visibleSections,
  setSectionVisibility,
  ensureSectionsSeeded,
  ensureSuperadmin,
  adminAccounts,
} from "@/lib/access";
import { db } from "@/db";
import { adminSections } from "@/db/schema";
import { publishEvent } from "@/lib/bus";

export async function GET(req: Request) {
  const claims = readClaims(req);
  if (!claims) return unauthorized();
  if (!hasAdminRole(claims.role)) return forbidden("Доступно только администраторам");

  await ensureSectionsSeeded();
  const rows = await db.select().from(adminSections);
  const map = new Map(rows.map((r) => [r.sectionKey, r.visibleForAdmin]));

  return NextResponse.json({
    role: claims.role,
    integrity: await ensureSuperadmin(),
    sections: ADMIN_SECTIONS.map((s) => ({
      ...s,
      visibleForAdmin: s.superadminOnly ? false : s.locked ? true : (map.get(s.key) ?? true),
    })),
    visibleForMe: await visibleSections(claims.role),
    accounts: claims.role === "superadmin" ? await adminAccounts() : undefined,
  });
}

export async function PUT(req: Request) {
  const claims = readClaims(req);
  if (!claims || claims.role !== "superadmin") {
    return forbidden("Видимость разделов настраивает только супер-администратор");
  }
  const body = await req.json();
  const enabled: string[] = Array.isArray(body.visibleForAdmin)
    ? body.visibleForAdmin.filter((x: unknown): x is string => typeof x === "string")
    : [];
  await setSectionVisibility(enabled);
  publishEvent("access");
  return NextResponse.json({ ok: true, visibleForAdmin: await visibleSections("admin") });
}
