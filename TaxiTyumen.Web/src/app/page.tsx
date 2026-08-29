// Портал: серверная загрузка брендингов всех приложений
import type { Metadata } from "next";
import { getAllBranding } from "@/lib/branding";
import PortalClient from "./PortalClient";

export const dynamic = "force-dynamic";

export const metadata: Metadata = {
  title: "Такси Тюмень — портал системы",
  description: "Четыре приложения такси-сервиса: клиент, водитель, диспетчерская, админ-панель.",
};

export default async function Page() {
  const brandings = await getAllBranding();
  return <PortalClient brandings={brandings} />;
}
