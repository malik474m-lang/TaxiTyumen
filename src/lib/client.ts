// Общие клиентские типы и хелперы
export interface SessionUser {
  id: string;
  phone: string;
  firstName: string;
  lastName: string;
  name: string;
  email?: string | null;
  role: "client" | "driver" | "operator" | "admin" | "superadmin";
  rating: number;
  driverId?: string | null;
  /** HMAC-токен сессии (выдаётся сервером при логине) */
  token?: string;
}

export interface OrderDriverInfo {
  id: string;
  name: string;
  phone: string;
  carBrand: string;
  carModel: string;
  carColor: string;
  licensePlate: string;
  carDisplay: string;
  rating: number;
  latitude: number;
  longitude: number;
  balance?: number;
  status?: string;
}

export interface OrderDto {
  id: string;
  orderNumber: string;
  status: string;
  statusText: string;
  source: string;
  clientId: string | null;
  clientName: string | null;
  clientPhone: string | null;
  pickupAddress: string;
  pickupLatitude: number;
  pickupLongitude: number;
  pickupEntrance: string | null;
  destinationAddress: string | null;
  destinationLatitude: number | null;
  destinationLongitude: number | null;
  tariff: string;
  tariffName: string;
  estimatedPrice: number;
  finalPrice: number | null;
  estimatedDistance: number | null;
  estimatedDuration: number | null;
  routePoints?: [number, number][] | null;
  options?: { code: string; name: string; price: number }[];
  escalatedAt?: string | null;
  paymentMethod: string;
  paymentMethodName: string;
  comment: string | null;
  cancellationReason: string | null;
  passengerCount: number;
  clientRating: number | null;
  createdAt: string;
  acceptedAt: string | null;
  completedAt: string | null;
  driver: OrderDriverInfo | null;
  distanceToPickup?: number;
}

export interface DriverDto {
  id: string;
  userId: string;
  name: string;
  phone: string;
  rating: number;
  carDisplay: string;
  licensePlate: string;
  status: string;
  statusText: string;
  isVerified: boolean;
  latitude: number;
  longitude: number;
  balance: number;
  minBalanceForOrders: number;
  completedTrips: number;
  totalEarnings: number;
  todayEarnings: number;
  currentOrderId: string | null;
}

export interface TariffDto {
  id: string;
  type: string;
  name: string;
  description: string;
  baseFare: number;
  pricePerKm: number;
  pricePerMinute: number;
  minimumFare: number;
  nightMultiplier: number;
  peakMultiplier: number;
  commissionPercent: number;
  paidWaitingPerMinute: number;
  isActive: boolean;
}

export interface EstimateDto {
  tariffType: string;
  tariffName: string;
  description: string;
  price: number;
  distanceKm: number;
  durationMinutes: number;
  isNightRate: boolean;
  isPeakRate: boolean;
  multiplier: number;
  minimumFare: number;
}

export interface ChatMsg {
  id: string;
  orderId: string;
  senderId: string;
  senderName: string;
  senderRole: string;
  text: string;
  createdAt: string;
}

const KEY = "tt_user";

export function getSession(): SessionUser | null {
  if (typeof window === "undefined") return null;
  try {
    const raw = localStorage.getItem(KEY);
    return raw ? (JSON.parse(raw) as SessionUser) : null;
  } catch {
    return null;
  }
}

export function setSession(user: SessionUser | null) {
  if (typeof window === "undefined") return;
  if (user) localStorage.setItem(KEY, JSON.stringify(user));
  else localStorage.removeItem(KEY);
}

export async function api<T>(url: string, options?: RequestInit): Promise<T> {
  const session = getSession();
  const res = await fetch(url, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      ...(session?.token ? { Authorization: `Bearer ${session.token}` } : {}),
      ...(options?.headers ?? {}),
    },
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(data?.error ?? `Ошибка ${res.status}`);
  }
  return data as T;
}

export function fmtPrice(v?: number | null): string {
  if (v === undefined || v === null) return "—";
  return `${Math.round(v).toLocaleString("ru-RU")} ₽`;
}

export function fmtTime(iso?: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleTimeString("ru-RU", { hour: "2-digit", minute: "2-digit" });
}

export function fmtDate(iso?: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleString("ru-RU", {
    day: "2-digit",
    month: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  });
}

export const ORDER_STEPS = [
  { key: "searching", label: "Поиск водителя" },
  { key: "driver_assigned", label: "Водитель назначен" },
  { key: "driver_arrived", label: "Водитель на месте" },
  { key: "in_progress", label: "Поездка" },
  { key: "completed", label: "Завершён" },
];

export function statusIndex(status: string): number {
  const map: Record<string, number> = {
    created: 0,
    searching: 0,
    no_driver_found: 0,
    driver_assigned: 1,
    driver_en_route: 1,
    driver_arrived: 2,
    in_progress: 3,
    completed: 4,
    cancelled: -1,
  };
  return map[status] ?? 0;
}
