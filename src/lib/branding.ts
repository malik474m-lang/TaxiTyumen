// Серверное брендирование приложений: данные в БД, редактор в админке,
// рендер — на сервере (SSR + CSS-переменные)
import { db } from "@/db";
import { brandingSettings, serviceBrand } from "@/db/schema";
import { eq } from "drizzle-orm";

export interface ServiceBrandData {
  serviceName: string;
  city: string;
  region: string;
  regionCode: string;
  supportPhone: string | null;
  centerLat: number;
  centerLng: number;
  utcOffset: number;
  smsSenderName: string;
}

export const SERVICE_BRAND_DEFAULTS: ServiceBrandData = {
  serviceName: "Такси Тюмень",
  city: "Тюмень",
  region: "Тюменская область",
  regionCode: "72",
  supportPhone: "+7 (3452) 000-000",
  centerLat: 57.1522,
  centerLng: 65.5272,
  utcOffset: 5,
  smsSenderName: "Такси Тюмень",
};

// Детерминированный id — гарантия единственной строки бренда
export const SERVICE_BRAND_ID = "11111111-1111-4111-8111-111111111111";

export async function ensureServiceBrandSeeded(): Promise<void> {
  await db
    .insert(serviceBrand)
    .values({ ...SERVICE_BRAND_DEFAULTS, id: SERVICE_BRAND_ID })
    .onConflictDoNothing({ target: serviceBrand.id });
  // Чистим возможные дубли старых установок
  const rows = await db.select({ id: serviceBrand.id }).from(serviceBrand);
  for (const row of rows) {
    if (row.id !== SERVICE_BRAND_ID) {
      await db.delete(serviceBrand).where(eq(serviceBrand.id, row.id));
    }
  }
}

export async function getServiceBrand(): Promise<ServiceBrandData> {
  try {
    await ensureServiceBrandSeeded();
    const [row] = await db.select().from(serviceBrand).limit(1);
    if (!row) return SERVICE_BRAND_DEFAULTS;
    return {
      serviceName: row.serviceName,
      city: row.city,
      region: row.region,
      regionCode: row.regionCode,
      supportPhone: row.supportPhone,
      centerLat: row.centerLat,
      centerLng: row.centerLng,
      utcOffset: row.utcOffset,
      smsSenderName: row.smsSenderName,
    };
  } catch {
    return SERVICE_BRAND_DEFAULTS;
  }
}

export type BrandApp = "client" | "driver" | "operator";

export interface BrandingData {
  app: BrandApp;
  appName: string;
  appCode: string;
  heroTitle: string;
  heroSubtitle: string;
  logoIcon: string;
  /** URL собственного логотипа от серверного branding API */
  logoUrl?: string | null;
  primaryColor: string;
  primaryTextColor: string;
  supportPhone: string | null;
  features: string[];
}

export const BRAND_APPS: BrandApp[] = ["client", "driver", "operator"];

export const BRAND_DEFAULTS: Record<BrandApp, BrandingData> = {
  client: {
    app: "client",
    appName: "Приложение клиента",
    appCode: "TaxiClient · Web",
    heroTitle: "Заказ такси по Тюмени",
    heroSubtitle:
      "Живая оценка цены по реальным дорогам, отслеживание водителя на карте и чат с ним.",
    logoIcon: "taxi",
    primaryColor: "#facc15",
    primaryTextColor: "#0a0a0c",
    supportPhone: "+7 (3452) 000-000",
    features: ["Заказ за пару минут", "Реальные тарифы и маршрут", "Чат с водителем"],
  },
  driver: {
    app: "driver",
    appName: "Приложение водителя",
    appCode: "TaxiDriver · Web",
    heroTitle: "Работа на линии",
    heroSubtitle:
      "Лента заказов рядом с вами, управление поездкой в одну кнопку, баланс и комиссии прозрачно.",
    logoIcon: "car",
    primaryColor: "#34d399",
    primaryTextColor: "#052e22",
    supportPhone: "+7 (3452) 000-001",
    features: ["Лента доступных заказов", "Этапы поездки одной кнопкой", "Баланс, комиссии, заработок"],
  },
  operator: {
    app: "operator",
    appName: "Диспетчерская",
    appCode: "TaxiOperator · Web",
    heroTitle: "Пульт оператора",
    heroSubtitle:
      "Приём заказов со звонка, карта автопарка в реальном времени и ручное распределение водителей.",
    logoIcon: "headset",
    primaryColor: "#38bdf8",
    primaryTextColor: "#082231",
    supportPhone: "+7 (3452) 000-002",
    features: ["Табло активных заказов", "Приём заказа со звонка", "Назначение водителей вручную"],
  },
};

function rowToData(row: typeof brandingSettings.$inferSelect): BrandingData {
  return {
    app: row.app as BrandApp,
    appName: row.appName,
    appCode: row.appCode,
    heroTitle: row.heroTitle,
    heroSubtitle: row.heroSubtitle,
    logoIcon: row.logoIcon,
    primaryColor: row.primaryColor,
    primaryTextColor: row.primaryTextColor,
    supportPhone: row.supportPhone,
    features: JSON.parse(row.features || "[]") as string[],
  };
}

// Вставляем дефолтные бренды, если их нет (self-healing, как DataSeeder)
export async function ensureBrandingSeeded() {
  const rows = await db.select({ app: brandingSettings.app }).from(brandingSettings);
  const existing = new Set(rows.map((r) => r.app));
  const missing = BRAND_APPS.filter((a) => !existing.has(a));
  for (const app of missing) {
    const d = BRAND_DEFAULTS[app];
    await db.insert(brandingSettings).values({
      app,
      appName: d.appName,
      appCode: d.appCode,
      heroTitle: d.heroTitle,
      heroSubtitle: d.heroSubtitle,
      logoIcon: d.logoIcon,
      primaryColor: d.primaryColor,
      primaryTextColor: d.primaryTextColor,
      supportPhone: d.supportPhone,
      features: JSON.stringify(d.features),
    });
  }
}

export async function getBranding(app: BrandApp): Promise<BrandingData> {
  try {
    await ensureBrandingSeeded();
    const [row] = await db
      .select()
      .from(brandingSettings)
      .where(eq(brandingSettings.app, app));
    return row ? rowToData(row) : BRAND_DEFAULTS[app];
  } catch {
    return BRAND_DEFAULTS[app];
  }
}

export async function getAllBranding(): Promise<BrandingData[]> {
  await ensureBrandingSeeded();
  const rows = await db.select().from(brandingSettings);
  const map = new Map(rows.map((r) => [r.app, rowToData(r)]));
  return BRAND_APPS.map((a) => map.get(a) ?? BRAND_DEFAULTS[a]);
}
