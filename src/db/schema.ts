import {
  pgTable,
  pgEnum,
  uuid,
  text,
  integer,
  doublePrecision,
  boolean,
  timestamp,
} from "drizzle-orm/pg-core";

// ── Enums (порт TaxiService.Domain.Enums) ────────────────────────────────────

export const userRoleEnum = pgEnum("user_role", [
  "client",
  "driver",
  "operator",
  "admin",
  "superadmin",
]);

export const driverStatusEnum = pgEnum("driver_status", [
  "offline",
  "available",
  "on_route",
  "in_trip",
  "busy",
]);

export const orderStatusEnum = pgEnum("order_status", [
  "created",
  "searching",
  "driver_assigned",
  "driver_en_route",
  "driver_arrived",
  "in_progress",
  "completed",
  "cancelled",
  "no_driver_found",
]);

export const orderSourceEnum = pgEnum("order_source", [
  "client_app",
  "operator_app",
]);

export const tariffTypeEnum = pgEnum("tariff_type", [
  "economy",
  "comfort",
  "business",
  "minivan",
]);

export const paymentMethodEnum = pgEnum("payment_method", [
  "cash",
  "card",
  "bonus",
]);

export const transactionTypeEnum = pgEnum("transaction_type", [
  "topup",
  "commission",
  "penalty",
]);

// ── Users (User.cs) ──────────────────────────────────────────────────────────

export const users = pgTable("users", {
  id: uuid("id").defaultRandom().primaryKey(),
  phone: text("phone").notNull().unique(),
  username: text("username").unique(),
  firstName: text("first_name").notNull(),
  lastName: text("last_name").notNull(),
  email: text("email"),
  passwordHash: text("password_hash").notNull(),
  role: userRoleEnum("role").notNull().default("client"),
  isActive: boolean("is_active").notNull().default(true),
  isBlocked: boolean("is_blocked").notNull().default(false),
  blockReason: text("block_reason"),
  rating: doublePrecision("rating").notNull().default(5),
  totalTrips: integer("total_trips").notNull().default(0),
  isPhoneVerified: boolean("is_phone_verified").notNull().default(false),
  smsCode: text("sms_code"),
  smsCodeExpiry: timestamp("sms_code_expiry", { withTimezone: true }),
  lastLoginAt: timestamp("last_login_at", { withTimezone: true }),
  createdAt: timestamp("created_at", { withTimezone: true })
    .notNull()
    .defaultNow(),
});

// ── Drivers (Driver.cs) ──────────────────────────────────────────────────────

export const drivers = pgTable("drivers", {
  id: uuid("id").defaultRandom().primaryKey(),
  userId: uuid("user_id")
    .notNull()
    .unique()
    .references(() => users.id),
  carBrand: text("car_brand").notNull(),
  carModel: text("car_model").notNull(),
  carColor: text("car_color").notNull(),
  licensePlate: text("license_plate").notNull(),
  carYear: integer("car_year").notNull().default(2020),
  driverLicense: text("driver_license").default(""),
  isVerified: boolean("is_verified").notNull().default(false),
  status: driverStatusEnum("status").notNull().default("offline"),
  latitude: doublePrecision("latitude").notNull().default(57.1522),
  longitude: doublePrecision("longitude").notNull().default(65.5272),
  lastLocationUpdate: timestamp("last_location_update", {
    withTimezone: true,
  }),
  completedTrips: integer("completed_trips").notNull().default(0),
  cancelledTrips: integer("cancelled_trips").notNull().default(0),
  totalEarnings: doublePrecision("total_earnings").notNull().default(0),
  todayEarnings: doublePrecision("today_earnings").notNull().default(0),
  balance: doublePrecision("balance").notNull().default(0),
  minBalanceForOrders: doublePrecision("min_balance_for_orders")
    .notNull()
    .default(100),
  rejectionPenalty: doublePrecision("rejection_penalty").notNull().default(0),
  currentOrderId: uuid("current_order_id"),
});

// ── Tariffs (Tariff.cs) ──────────────────────────────────────────────────────

export const tariffs = pgTable("tariffs", {
  id: uuid("id").defaultRandom().primaryKey(),
  type: tariffTypeEnum("type").notNull().unique(),
  name: text("name").notNull(),
  description: text("description").notNull().default(""),
  baseFare: doublePrecision("base_fare").notNull(),
  pricePerKm: doublePrecision("price_per_km").notNull(),
  pricePerMinute: doublePrecision("price_per_minute").notNull().default(0),
  minimumFare: doublePrecision("minimum_fare").notNull(),
  freeWaitingMinutes: doublePrecision("free_waiting_minutes")
    .notNull()
    .default(3),
  paidWaitingPerMinute: doublePrecision("paid_waiting_per_minute")
    .notNull()
    .default(0),
  nightMultiplier: doublePrecision("night_multiplier").notNull().default(1),
  peakMultiplier: doublePrecision("peak_multiplier").notNull().default(1),
  commissionPercent: doublePrecision("commission_percent")
    .notNull()
    .default(15),
  isActive: boolean("is_active").notNull().default(true),
  updatedAt: timestamp("updated_at", { withTimezone: true }),
});

// ── Orders (Order.cs) ────────────────────────────────────────────────────────

export const orders = pgTable("orders", {
  id: uuid("id").defaultRandom().primaryKey(),
  orderNumber: text("order_number").notNull().unique(),
  clientId: uuid("client_id").references(() => users.id),
  operatorId: uuid("operator_id").references(() => users.id),
  source: orderSourceEnum("source").notNull().default("client_app"),
  clientPhone: text("client_phone"),
  clientName: text("client_name"),
  driverId: uuid("driver_id").references(() => drivers.id),
  pickupAddress: text("pickup_address").notNull(),
  pickupLatitude: doublePrecision("pickup_latitude").notNull(),
  pickupLongitude: doublePrecision("pickup_longitude").notNull(),
  pickupEntrance: text("pickup_entrance"),
  destinationAddress: text("destination_address"),
  destinationLatitude: doublePrecision("destination_latitude"),
  destinationLongitude: doublePrecision("destination_longitude"),
  tariff: tariffTypeEnum("tariff").notNull().default("economy"),
  estimatedPrice: doublePrecision("estimated_price").notNull().default(0),
  finalPrice: doublePrecision("final_price"),
  estimatedDistance: doublePrecision("estimated_distance"),
  estimatedDuration: integer("estimated_duration"),
  routeGeometry: text("route_geometry"),
  paymentMethod: paymentMethodEnum("payment_method")
    .notNull()
    .default("cash"),
  status: orderStatusEnum("status").notNull().default("searching"),
  escalatedAt: timestamp("escalated_at", { withTimezone: true }),
  comment: text("comment"),
  cancellationReason: text("cancellation_reason"),
  passengerCount: integer("passenger_count").notNull().default(1),
  clientRating: integer("client_rating"),
  driverRating: integer("driver_rating"),
  createdAt: timestamp("created_at", { withTimezone: true })
    .notNull()
    .defaultNow(),
  acceptedAt: timestamp("accepted_at", { withTimezone: true }),
  driverArrivedAt: timestamp("driver_arrived_at", { withTimezone: true }),
  tripStartedAt: timestamp("trip_started_at", { withTimezone: true }),
  completedAt: timestamp("completed_at", { withTimezone: true }),
  cancelledAt: timestamp("cancelled_at", { withTimezone: true }),
});

// ── Order options (OrderOption.cs) ───────────────────────────────────────────

export const orderOptions = pgTable("order_options", {
  id: uuid("id").defaultRandom().primaryKey(),
  orderId: uuid("order_id")
    .notNull()
    .references(() => orders.id),
  code: text("code").notNull(),
  name: text("name").notNull(),
  price: doublePrecision("price").notNull().default(0),
  createdAt: timestamp("created_at", { withTimezone: true })
    .notNull()
    .defaultNow(),
});

// ── Operator shifts (OperatorShift.cs) ───────────────────────────────────────

export const operatorShifts = pgTable("operator_shifts", {
  id: uuid("id").defaultRandom().primaryKey(),
  operatorId: uuid("operator_id")
    .notNull()
    .references(() => users.id),
  startedAt: timestamp("started_at", { withTimezone: true })
    .notNull()
    .defaultNow(),
  endedAt: timestamp("ended_at", { withTimezone: true }),
});

// ── Admin sections visibility (управление доступом супер-админом) ───────────

export const adminSections = pgTable("admin_sections", {
  sectionKey: text("section_key").primaryKey(),
  visibleForAdmin: boolean("visible_for_admin").notNull().default(true),
  updatedAt: timestamp("updated_at", { withTimezone: true }),
});

export const systemState = pgTable("system_state", {
  stateKey: text("state_key").primaryKey(),
  stateValue: text("state_value").notNull(),
  updatedAt: timestamp("updated_at", { withTimezone: true }).notNull().defaultNow(),
});

// ── Service brand (единый бренд сервиса: название, город, центр, TZ) ────────

export const serviceBrand = pgTable("service_brand", {
  id: uuid("id").defaultRandom().primaryKey(),
  serviceName: text("service_name").notNull(),
  city: text("city").notNull(),
  region: text("region").notNull(),
  regionCode: text("region_code").notNull().default(""),
  supportPhone: text("support_phone"),
  centerLat: doublePrecision("center_lat").notNull().default(57.1522),
  centerLng: doublePrecision("center_lng").notNull().default(65.5272),
  utcOffset: integer("utc_offset").notNull().default(5),
  smsSenderName: text("sms_sender_name").notNull(),
  updatedAt: timestamp("updated_at", { withTimezone: true }),
});

// ── Branding settings (серверный брендинг приложений) ───────────────────────

export const brandingSettings = pgTable("branding_settings", {
  id: uuid("id").defaultRandom().primaryKey(),
  app: text("app").notNull().unique(), // client | driver | operator
  appName: text("app_name").notNull(),
  appCode: text("app_code").notNull(),
  heroTitle: text("hero_title").notNull().default(""),
  heroSubtitle: text("hero_subtitle").notNull().default(""),
  logoIcon: text("logo_icon").notNull().default("taxi"),
  primaryColor: text("primary_color").notNull().default("#facc15"),
  primaryTextColor: text("primary_text_color").notNull().default("#0a0a0c"),
  supportPhone: text("support_phone"),
  features: text("features").notNull().default("[]"), // JSON string[]
  updatedAt: timestamp("updated_at", { withTimezone: true }),
});

// ── AutoCall settings (AutoCallSettings.cs) ──────────────────────────────────

export const autoCallSettings = pgTable("auto_call_settings", {
  id: uuid("id").defaultRandom().primaryKey(),
  enabled: boolean("enabled").notNull().default(true),
  escalateAfterMinutes: integer("escalate_after_minutes").notNull().default(3),
  autoAssignEnabled: boolean("auto_assign_enabled").notNull().default(false),
  autoAssignRadiusKm: doublePrecision("auto_assign_radius_km")
    .notNull()
    .default(5),
});

// ── Order rejections (OrderRejection.cs) ─────────────────────────────────────

export const orderRejections = pgTable("order_rejections", {
  id: uuid("id").defaultRandom().primaryKey(),
  orderId: uuid("order_id")
    .notNull()
    .references(() => orders.id),
  driverId: uuid("driver_id")
    .notNull()
    .references(() => drivers.id),
  reason: text("reason"),
  createdAt: timestamp("created_at", { withTimezone: true })
    .notNull()
    .defaultNow(),
});

// ── Balance transactions (BalanceTransaction.cs) ─────────────────────────────

export const balanceTransactions = pgTable("balance_transactions", {
  id: uuid("id").defaultRandom().primaryKey(),
  driverId: uuid("driver_id")
    .notNull()
    .references(() => drivers.id),
  orderId: uuid("order_id"),
  type: transactionTypeEnum("type").notNull(),
  amount: doublePrecision("amount").notNull(),
  balanceAfter: doublePrecision("balance_after").notNull(),
  description: text("description").notNull().default(""),
  createdBy: text("created_by"),
  createdAt: timestamp("created_at", { withTimezone: true })
    .notNull()
    .defaultNow(),
});

// ── Chat messages (ChatMessage.cs) ───────────────────────────────────────────

export const chatMessages = pgTable("chat_messages", {
  id: uuid("id").defaultRandom().primaryKey(),
  orderId: uuid("order_id")
    .notNull()
    .references(() => orders.id),
  senderId: uuid("sender_id").notNull(),
  senderName: text("sender_name").notNull().default(""),
  senderRole: userRoleEnum("sender_role").notNull().default("client"),
  text: text("text").notNull(),
  createdAt: timestamp("created_at", { withTimezone: true })
    .notNull()
    .defaultNow(),
});

// ── Types ────────────────────────────────────────────────────────────────────

export type ServiceBrand = typeof serviceBrand.$inferSelect;
export type User = typeof users.$inferSelect;
export type Driver = typeof drivers.$inferSelect;
export type Order = typeof orders.$inferSelect;
export type Tariff = typeof tariffs.$inferSelect;
export type BalanceTransaction = typeof balanceTransactions.$inferSelect;
export type ChatMessage = typeof chatMessages.$inferSelect;
