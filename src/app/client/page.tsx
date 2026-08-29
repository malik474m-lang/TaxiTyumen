// Серверная страница клиентского приложения: брендинг грузится на сервере
import type { Metadata } from "next";
import { getBranding } from "@/lib/branding";
import ClientApp from "./ClientApp";

export const dynamic = "force-dynamic";

export async function generateMetadata(): Promise<Metadata> {
  const brand = await getBranding("client");
  return {
    title: `${brand.appName} — ${brand.appCode}`,
    description: brand.heroSubtitle,
  };
}

export default async function Page() {
  const branding = await getBranding("client");
  return <ClientApp branding={branding} />;
}
