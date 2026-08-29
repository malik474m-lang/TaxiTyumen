// GET /api/autocall — настройки (оператор/админ) | PUT — изменение (админ)
import { NextResponse } from "next/server";
import { db } from "@/db";
import { autoCallSettings } from "@/db/schema";
import { eq } from "drizzle-orm";
import { getAutoCallSettings } from "@/lib/autocall";
import { readClaims, forbidden } from "@/lib/session";
import { publishEvent } from "@/lib/bus";

export async function GET(req: Request) {
  const claims = readClaims(req);
  if (!claims || (claims.role !== "operator" && claims.role !== "admin")) {
    return forbidden("Настройки автодозвона доступны персоналу");
  }
  return NextResponse.json(await getAutoCallSettings());
}

export async function PUT(req: Request) {
  const claims = readClaims(req);
  if (!claims || claims.role !== "admin") {
    return forbidden("Настройки автодозвона меняет только администратор");
  }

  const body = await req.json();
  const settings = await getAutoCallSettings();

  const fields = {
    enabled: Boolean(body.enabled ?? settings.enabled),
    escalateAfterMinutes: Math.max(
      1,
      Math.min(60, Number(body.escalateAfterMinutes ?? settings.escalateAfterMinutes) || settings.escalateAfterMinutes)
    ),
    autoAssignEnabled: Boolean(body.autoAssignEnabled ?? settings.autoAssignEnabled),
    autoAssignRadiusKm: Math.max(
      1,
      Math.min(30, Number(body.autoAssignRadiusKm ?? settings.autoAssignRadiusKm) || settings.autoAssignRadiusKm)
    ),
  };

  await db.update(autoCallSettings).set(fields).where(eq(autoCallSettings.id, settings.id));
  publishEvent("autocall");
  return NextResponse.json(await getAutoCallSettings());
}
