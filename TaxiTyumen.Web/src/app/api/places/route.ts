// GET /api/places — популярные адреса Тюмени для подсказок
import { NextResponse } from "next/server";
import { PLACES } from "@/lib/taxi";

export async function GET() {
  return NextResponse.json(PLACES);
}
