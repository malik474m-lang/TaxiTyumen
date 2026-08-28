// GET /api/drivers — GetOnlineDrivers / все водители (для оператора и админки)
import { NextResponse } from "next/server";
import { db } from "@/db";
import { drivers, users } from "@/db/schema";
import { eq, ne, and } from "drizzle-orm";
import { DRIVER_STATUS_TEXT } from "@/lib/taxi";
import { ensureSeeded } from "@/lib/seed";
import { advanceDriversGps } from "@/lib/simulate";

export async function GET(req: Request) {
  await ensureSeeded();
  await advanceDriversGps();
  const url = new URL(req.url);
  const onlineOnly = url.searchParams.get("online") === "1";

  const conditions = onlineOnly ? and(ne(drivers.status, "offline")) : undefined;
  const rows = await db
    .select({ driver: drivers, user: users })
    .from(drivers)
    .innerJoin(users, eq(drivers.userId, users.id))
    .where(conditions)
    .orderBy(drivers.status);

  return NextResponse.json(
    rows.map((r) => ({
      id: r.driver.id,
      userId: r.user.id,
      name: `${r.user.firstName} ${r.user.lastName}`,
      phone: r.user.phone,
      rating: r.user.rating,
      carBrand: r.driver.carBrand,
      carModel: r.driver.carModel,
      carColor: r.driver.carColor,
      carDisplay: `${r.driver.carColor} ${r.driver.carBrand} ${r.driver.carModel}`,
      licensePlate: r.driver.licensePlate,
      carYear: r.driver.carYear,
      status: r.driver.status,
      statusText: DRIVER_STATUS_TEXT[r.driver.status] ?? r.driver.status,
      isVerified: r.driver.isVerified,
      latitude: r.driver.latitude,
      longitude: r.driver.longitude,
      balance: r.driver.balance,
      minBalanceForOrders: r.driver.minBalanceForOrders,
      rejectionPenalty: r.driver.rejectionPenalty,
      completedTrips: r.driver.completedTrips,
      cancelledTrips: r.driver.cancelledTrips,
      totalEarnings: r.driver.totalEarnings,
      todayEarnings: r.driver.todayEarnings,
      currentOrderId: r.driver.currentOrderId,
      lastLocationUpdate: r.driver.lastLocationUpdate,
    }))
  );
}
