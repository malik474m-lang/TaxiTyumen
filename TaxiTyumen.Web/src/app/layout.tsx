import type { Metadata } from "next";
import type { ReactNode } from "react";
import "./globals.css";

export const metadata: Metadata = {
  title: "Такси Тюмень — Сервис заказа такси",
  description:
    "Веб-порт системы TaxiTyumen: заказ такси, кабинет водителя, диспетчерская и админ-панель.",
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="ru">
      <body className="min-h-screen bg-[#0a0a0c] text-zinc-100 antialiased">
        {children}
      </body>
    </html>
  );
}
