// GET /api/map-config — публичная конфигурация Яндекс Карт JS API 2.1
// Ключ предназначен для браузера; ограничьте его доменом в кабинете разработчика Яндекса.
import { NextResponse } from "next/server";
import { getServiceBrand } from "@/lib/branding";

export async function GET() {
  const service = await getServiceBrand();
  const apiKey =
    process.env.YANDEX_MAPS_API_KEY ?? process.env.NEXT_PUBLIC_YANDEX_MAPS_API_KEY ?? "";

  return NextResponse.json({
    provider: "yandex",
    apiKey,
    configured: Boolean(apiKey),
    lang: "ru_RU",
    center: [service.centerLat, service.centerLng],
    city: service.city,
  });
}
