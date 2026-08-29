// Серверная страница диспетчерской
import type { Metadata } from "next";
import { getBranding } from "@/lib/branding";
import OperatorApp from "./OperatorApp";

export const dynamic = "force-dynamic";

export async function generateMetadata(): Promise<Metadata> {
  const brand = await getBranding("operator");
  return {
    title: `${brand.appName} — ${brand.appCode}`,
    description: brand.heroSubtitle,
  };
}

export default async function Page() {
  const branding = await getBranding("operator");
  return <OperatorApp branding={branding} />;
}
