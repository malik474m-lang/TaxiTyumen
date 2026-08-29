// Портал: серверная загрузка брендингов всех приложений
import type { Metadata } from "next";
import { getAllBranding, getServiceBrand } from "@/lib/branding";
import PortalClient from "./PortalClient";

export const dynamic = "force-dynamic";

export async function generateMetadata(): Promise<Metadata> {
  const service = await getServiceBrand();
  return {
    title: `${service.serviceName} — портал системы`,
    description: `Приложения такси-сервиса ${service.serviceName} (${service.city}): клиент, водитель, диспетчерская, админ-панель.`,
  };
}

export default async function Page() {
  const [brandings, service] = await Promise.all([getAllBranding(), getServiceBrand()]);
  return <PortalClient brandings={brandings} service={service} />;
}
