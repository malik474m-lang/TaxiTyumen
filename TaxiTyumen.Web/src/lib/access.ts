// Роли администрирования и видимость разделов админки (веб-порт)
import { db } from "@/db";
import { adminSections, systemState, users } from "@/db/schema";
import { eq, inArray } from "drizzle-orm";
import { hashPassword } from "@/lib/auth";

export const SUPERADMIN_LOGIN = "Rudakov";
const SUPERADMIN_MARKER = "superadmin_installed";
const SUPERADMIN_DEFAULT_PASSWORD =
  process.env.SUPERADMIN_PASSWORD ?? "Malik9091868294";

export interface SectionMeta {
  key: string;
  label: string;
  superadminOnly: boolean;
  locked: boolean;
}

export const ADMIN_SECTIONS: SectionMeta[] = [
  { key: "overview", label: "Обзор", superadminOnly: false, locked: true },
  { key: "orders", label: "Заказы", superadminOnly: false, locked: false },
  { key: "drivers", label: "Водители", superadminOnly: false, locked: false },
  { key: "tariffs", label: "Тарифы", superadminOnly: false, locked: false },
  { key: "branding", label: "Приложения", superadminOnly: false, locked: false },
  { key: "settings", label: "Настройки", superadminOnly: false, locked: false },
  { key: "service", label: "Бренд сервиса", superadminOnly: true, locked: false },
  { key: "access", label: "Доступ и роли", superadminOnly: true, locked: false },
];

export function isAdminRole(role?: string | null): boolean {
  return role === "admin" || role === "superadmin";
}

export async function ensureSuperadmin(): Promise<{ ok: boolean; message?: string }> {
  const supers = await db
    .select({ id: users.id })
    .from(users)
    .where(eq(users.role, "superadmin"))
    .limit(1);

  const [marker] = await db
    .select()
    .from(systemState)
    .where(eq(systemState.stateKey, SUPERADMIN_MARKER))
    .limit(1);

  if (supers.length > 0) return { ok: true };

  const recovery = process.env.SUPERADMIN_RECOVERY === "true";
  if (!marker || recovery) {
    const [created] = await db
      .insert(users)
      .values({
        phone: "+70000000001",
        username: SUPERADMIN_LOGIN,
        firstName: "Супер",
        lastName: "Администратор",
        passwordHash: hashPassword(SUPERADMIN_DEFAULT_PASSWORD),
        role: "superadmin",
        isPhoneVerified: true,
      })
      .onConflictDoNothing()
      .returning();
    await db
      .insert(systemState)
      .values({ stateKey: SUPERADMIN_MARKER, stateValue: created?.id ?? SUPERADMIN_LOGIN })
      .onConflictDoNothing();
    return { ok: true };
  }

  return {
    ok: false,
    message:
      "Учётная запись супер-администратора удалена из базы данных. Система заблокирована.",
  };
}

export async function ensureSectionsSeeded(): Promise<void> {
  await db
    .insert(adminSections)
    .values(ADMIN_SECTIONS.map((s) => ({ sectionKey: s.key, visibleForAdmin: true })))
    .onConflictDoNothing();
}

export async function visibleSections(role: string): Promise<string[]> {
  await ensureSectionsSeeded();
  if (role === "superadmin") return ADMIN_SECTIONS.map((s) => s.key);
  const rows = await db.select().from(adminSections);
  const map = new Map(rows.map((r) => [r.sectionKey, r.visibleForAdmin]));
  return ADMIN_SECTIONS.filter(
    (s) => !s.superadminOnly && (s.locked || (map.get(s.key) ?? true))
  ).map((s) => s.key);
}

export async function setSectionVisibility(enabled: string[]): Promise<void> {
  await ensureSectionsSeeded();
  const editable = ADMIN_SECTIONS.filter((s) => !s.superadminOnly && !s.locked);
  for (const section of editable) {
    await db
      .update(adminSections)
      .set({ visibleForAdmin: enabled.includes(section.key), updatedAt: new Date() })
      .where(eq(adminSections.sectionKey, section.key));
  }
}

export async function adminAccounts() {
  return db
    .select({
      id: users.id,
      username: users.username,
      phone: users.phone,
      firstName: users.firstName,
      lastName: users.lastName,
      role: users.role,
      isBlocked: users.isBlocked,
      lastLoginAt: users.lastLoginAt,
    })
    .from(users)
    .where(inArray(users.role, ["admin", "superadmin"]));
}
