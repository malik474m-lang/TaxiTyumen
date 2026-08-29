// Серверная страница приложения водителя
import type { Metadata } from "next";
import { getBranding } from "@/lib/branding";
import DriverApp from "./DriverApp";

export const dynamic = "force-dynamic";

export async function generateMetadata(): Promise<Metadata> {
  const brand = await getBranding("driver");
  return {
    title: `${brand.appName} — ${brand.appCode}`,
    description: brand.heroSubtitle,
  };
}

export default async function Page() {
  const branding = await getBranding("driver");
  return <DriverApp branding={branding} />;
}
