import type { Metadata } from "next";
import type { ReactNode } from "react";
import "./globals.css";
import { getServiceBrand } from "@/lib/branding";

export async function generateMetadata(): Promise<Metadata> {
  const service = await getServiceBrand();
  return {
    title: `${service.serviceName} — заказ такси в ${service.city}`,
    description: `Веб-порт такси-сервиса ${service.serviceName} (${service.city}): заказ такси, кабинет водителя, диспетчерская и админ-панель.`,
  };
}

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="ru">
      <body className="min-h-screen bg-[#0a0a0c] text-zinc-100 antialiased">
        {children}
      </body>
    </html>
  );
}
