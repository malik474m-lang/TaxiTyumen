// GET /api/places — популярные адреса города (только если сервис в Тюмени)
import { NextResponse } from "next/server";
import { PLACES } from "@/lib/taxi";
import { getServiceBrand } from "@/lib/branding";

export async function GET() {
  const service = await getServiceBrand();
  // Встроенный справочник содержит только тюменские адреса: для другого города
  // список пуст — подсказки берутся из внешнего геокодинга
  if (service.city.trim().toLowerCase() !== "тюмень") {
    return NextResponse.json([]);
  }
  return NextResponse.json(PLACES);
}
