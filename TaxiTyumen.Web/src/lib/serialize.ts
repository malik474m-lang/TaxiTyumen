// Сериализация заказа → OrderResponse (порт MapToResponseAsync)
import { db } from "@/db";
import { orders, drivers, users, orderOptions, routePoints, type Order } from "@/db/schema";
import { eq, asc } from "drizzle-orm";
import { STATUS_TEXT, TARIFF_NAMES, PAYMENT_NAMES } from "@/lib/taxi";

export async function serializeOrder(order: Order) {
  // Опции заказа (OrderOption.cs)
  const opts = await db
    .select({ code: orderOptions.code, name: orderOptions.name, price: orderOptions.price })
    .from(orderOptions)
    .where(eq(orderOptions.orderId, order.id));

  // Промежуточные адреса (RoutePoint.cs → intermediatePoints в DTO, как в PHP)
  const stops = await db
    .select()
    .from(routePoints)
    .where(eq(routePoints.orderId, order.id))
    .orderBy(asc(routePoints.sortOrder));

  let driverInfo = null;
  if (order.driverId) {
    const [row] = await db
      .select({ driver: drivers, user: users })
      .from(drivers)
      .innerJoin(users, eq(drivers.userId, users.id))
      .where(eq(drivers.id, order.driverId));
    if (row) {
      driverInfo = {
        id: row.driver.id,
        name: `${row.user.firstName} ${row.user.lastName}`,
        phone: row.user.phone,
        carBrand: row.driver.carBrand,
        carModel: row.driver.carModel,
        carColor: row.driver.carColor,
        licensePlate: row.driver.licensePlate,
        carDisplay: `${row.driver.carColor} ${row.driver.carBrand} ${row.driver.carModel}`,
        rating: row.user.rating,
        latitude: row.driver.latitude,
        longitude: row.driver.longitude,
        balance: row.driver.balance,
        status: row.driver.status,
      };
    }
  }

  let clientName = order.clientName;
  let clientPhone = order.clientPhone;
  if (!clientName && order.clientId) {
    const [client] = await db
      .select()
      .from(users)
      .where(eq(users.id, order.clientId));
    if (client) {
      clientName = `${client.firstName} ${client.lastName}`;
      clientPhone = client.phone;
    }
  }

  return {
    id: order.id,
    orderNumber: order.orderNumber,
    status: order.status,
    statusText: STATUS_TEXT[order.status] ?? order.status,
    source: order.source,
    clientId: order.clientId,
    clientName,
    clientPhone,
    operatorId: order.operatorId,
    pickupAddress: order.pickupAddress,
    pickupLatitude: order.pickupLatitude,
    pickupLongitude: order.pickupLongitude,
    pickupEntrance: order.pickupEntrance,
    destinationAddress: order.destinationAddress,
    destinationEntrance: order.destinationEntrance ?? null,
    destinationLatitude: order.destinationLatitude,
    destinationLongitude: order.destinationLongitude,
    roundTrip: order.roundTrip ?? false,
    scheduledAt: order.scheduledAt ?? null,
    isPreorder: order.scheduledAt != null,
    preorderSurcharge: order.preorderSurcharge ?? 0,
    stopsSurcharge: order.stopsSurcharge ?? 0,
    intermediatePoints: stops.map((p) => ({
      id: p.id,
      address: p.address,
      latitude: p.latitude,
      longitude: p.longitude,
      sortOrder: p.sortOrder,
    })),
    tariff: order.tariff,
    tariffName: TARIFF_NAMES[order.tariff] ?? order.tariff,
    estimatedPrice: order.estimatedPrice,
    finalPrice: order.finalPrice,
    estimatedDistance: order.estimatedDistance,
    estimatedDuration: order.estimatedDuration,
    pricingMode: order.pricingMode ?? "tariff",
    isFixedPrice: (order.pricingMode ?? "tariff") === "zone",
    fromZoneId: order.fromZoneId ?? null,
    toZoneId: order.toZoneId ?? null,
    routePoints: order.routeGeometry
      ? (JSON.parse(order.routeGeometry) as [number, number][])
      : null,
    paymentMethod: order.paymentMethod,
    options: opts,
    escalatedAt: order.escalatedAt,
    paymentMethodName: PAYMENT_NAMES[order.paymentMethod] ?? order.paymentMethod,
    comment: order.comment,
    cancellationReason: order.cancellationReason,
    passengerCount: order.passengerCount,
    clientRating: order.clientRating,
    waitingStartedAt: order.waitingStartedAt,
    waitingSeconds: order.waitingSeconds,
    waitingCost: order.waitingCost,
    waitingActive: order.waitingStartedAt != null,
    createdAt: order.createdAt,
    acceptedAt: order.acceptedAt,
    driverArrivedAt: order.driverArrivedAt,
    tripStartedAt: order.tripStartedAt,
    completedAt: order.completedAt,
    cancelledAt: order.cancelledAt,
    driver: driverInfo,
  };
}

export function serializeUser(user: typeof users.$inferSelect, driverId?: string | null) {
  return {
    id: user.id,
    phone: user.phone,
    firstName: user.firstName,
    lastName: user.lastName,
    name: `${user.firstName} ${user.lastName}`,
    email: user.email,
    role: user.role,
    rating: user.rating,
    totalTrips: user.totalTrips,
    driverId: driverId ?? null,
  };
}
