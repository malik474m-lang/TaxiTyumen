"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import LoginScreen from "@/components/LoginScreen";
import {
  MapPin,
  Navigation,
  MessageSquareText,
  Users,
  Banknote,
  CreditCard,
  Gift,
  Star,
  XCircle,
  LocateFixed,
  Moon,
  Zap,
  Route,
  Clock,
  CarFront,
  Phone,
  DoorOpen,
  Loader2,
  Car,
} from "lucide-react";
import AppHeader from "@/components/AppHeader";
import OrderChat from "@/components/OrderChat";
import TaxiMap, { type MapMarker } from "@/components/TaxiMap";
import { useEvents } from "@/lib/use-events";
import {
  api,
  getSession,
  fmtPrice,
  fmtDate,
  ORDER_STEPS,
  statusIndex,
  type SessionUser,
  type OrderDto,
  type EstimateDto,
} from "@/lib/client";

const TARIFF_ICONS: Record<string, typeof Car> = {
  economy: Car,
  comfort: CarFront,
  business: Star,
  minivan: Users,
};

export default function ClientPage() {
  const [user, setUser] = useState<SessionUser | null>(null);
  const [loaded, setLoaded] = useState(false);

  // Форма заказа
  const [pickup, setPickup] = useState("");
  const [entrance, setEntrance] = useState("");
  const [destination, setDestination] = useState("");
  const [comment, setComment] = useState("");
  const [passengers, setPassengers] = useState(1);
  const [payment, setPayment] = useState<"cash" | "card" | "bonus">("cash");
  const [tariff, setTariff] = useState("economy");
  const [places, setPlaces] = useState<{ name: string }[]>([]);

  // Цены и заказы
  const [estimates, setEstimates] = useState<EstimateDto[]>([]);
  const [geo, setGeo] = useState<{
    from?: { lat: number; lng: number };
    to?: { lat: number; lng: number };
  }>({});
  const [estimating, setEstimating] = useState(false);
  const [active, setActive] = useState<OrderDto | null>(null);
  const [history, setHistory] = useState<OrderDto[]>([]);
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState("");
  const [ratingDone, setRatingDone] = useState<Record<string, number>>({});
  const estimateTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Сессия — только роль клиента
  useEffect(() => {
    setUser(getSession());
    setLoaded(true);
  }, []);

  useEffect(() => {
    api<{ name: string }[]>("/api/places").then(setPlaces).catch(() => {});
  }, []);

  const refresh = useCallback(async (u: SessionUser) => {
    try {
      const act = await api<OrderDto[]>(`/api/orders?view=clientActive&clientId=${u.id}`);
      setActive(act[0] ?? null);
      const hist = await api<OrderDto[]>(`/api/orders?view=history&clientId=${u.id}`);
      setHistory(hist.filter((h) => h.status === "completed" || h.status === "cancelled").slice(0, 8));
    } catch {
      /* ignore */
    }
  }, []);

  useEffect(() => {
    if (!user) return;
    refresh(user);
    const t = setInterval(() => refresh(user), 4000);
    return () => clearInterval(t);
  }, [user, refresh]);

  // Realtime (SSE) — мгновенные обновления поверх polling
  useEvents(() => {
    const u = getSession();
    if (u?.role === "client") refresh(u);
  });

  // Живой расчёт цены при вводе адресов (debounce)
  useEffect(() => {
    if (estimateTimer.current) clearTimeout(estimateTimer.current);
    if (pickup.trim().length > 3 && destination.trim().length > 3) {
      estimateTimer.current = setTimeout(async () => {
        setEstimating(true);
        try {
          const res = await api<{
            estimates: EstimateDto[];
            from?: { lat: number; lng: number };
            to?: { lat: number; lng: number };
          }>("/api/pricing", {
            method: "POST",
            body: JSON.stringify({ fromAddress: pickup, toAddress: destination }),
          });
          setEstimates(res.estimates);
          setGeo({ from: res.from, to: res.to });
        } catch {
          setEstimates([]);
          setGeo({});
        } finally {
          setEstimating(false);
        }
      }, 550);
    } else {
      setEstimates([]);
      setGeo({});
    }
  }, [pickup, destination]);

  async function createOrder(e: React.FormEvent) {
    e.preventDefault();
    if (!user) return;
    setError("");
    setCreating(true);
    try {
      await api<OrderDto>("/api/orders", {
        method: "POST",
        body: JSON.stringify({
          clientId: user.id,
          pickupAddress: pickup,
          pickupEntrance: entrance || null,
          destinationAddress: destination || null,
          tariff,
          comment: comment || null,
          passengerCount: passengers,
          paymentMethod: payment,
        }),
      });
      setComment("");
      await refresh(user);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Не удалось создать заказ");
    } finally {
      setCreating(false);
    }
  }

  async function cancelOrder() {
    if (!active || !user) return;
    await api(`/api/orders/${active.id}/action`, {
      method: "POST",
      body: JSON.stringify({ action: "cancel", reason: "Отменено клиентом" }),
    }).catch(() => {});
    await refresh(user);
  }

  async function rateOrder(orderId: string, rating: number) {
    await api(`/api/orders/${orderId}/action`, {
      method: "POST",
      body: JSON.stringify({ action: "rate", rating }),
    }).catch(() => {});
    setRatingDone((s) => ({ ...s, [orderId]: rating }));
    if (user) refresh(user);
  }

  if (!loaded) return null;
  if (!user || user.role !== "client") {
    return <LoginScreen role="client" onAuthed={setUser} />;
  }
  const step = active ? statusIndex(active.status) : -1;

  // Маркеры карты: режим отслеживания поездки
  const trackMarkers: MapMarker[] = active
    ? [
        {
          id: "pickup",
          lat: active.pickupLatitude,
          lng: active.pickupLongitude,
          kind: "pickup" as const,
          label: "Подача",
        },
        ...(active.destinationLatitude != null
          ? [
              {
                id: "dest",
                lat: active.destinationLatitude,
                lng: active.destinationLongitude ?? 0,
                kind: "dest" as const,
                label: "Финиш",
              },
            ]
          : []),
        ...(active.driver
          ? [
              {
                id: "driver",
                lat: active.driver.latitude,
                lng: active.driver.longitude,
                kind: "driver" as const,
                label: active.driver.licensePlate,
              },
            ]
          : []),
      ]
    : [];
  const liveLine: [number, number][] | null =
    active?.driver
      ? active.status === "in_progress" && active.destinationLatitude != null
        ? [
            [active.driver.latitude, active.driver.longitude],
            [active.destinationLatitude, active.destinationLongitude ?? 0],
          ]
        : [
            [active.driver.latitude, active.driver.longitude],
            [active.pickupLatitude, active.pickupLongitude],
          ]
      : null;

  // Маркеры карты: режим выбора маршрута
  const formMarkers: MapMarker[] = [
    ...(geo.from
      ? [{ id: "from", lat: geo.from.lat, lng: geo.from.lng, kind: "pickup" as const, label: "Подача" }]
      : []),
    ...(geo.to
      ? [{ id: "to", lat: geo.to.lat, lng: geo.to.lng, kind: "dest" as const, label: "Финиш" }]
      : []),
  ];
  const formLine: [number, number][] | null =
    geo.from && geo.to
      ? [
          [geo.from.lat, geo.from.lng],
          [geo.to.lat, geo.to.lng],
        ]
      : null;

  return (
    <div className="min-h-screen">
      <AppHeader user={user} subtitle="Клиент" />

      <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6">
        {active ? (
          /* ── Отслеживание активного заказа ─────────────────────────── */
          <div className="grid gap-6 lg:grid-cols-[1.2fr_1fr]">
            <div className="space-y-6">
              <div className="card animate-rise p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <div className="text-xs font-bold uppercase tracking-[0.2em] text-zinc-500">
                      Заказ {active.orderNumber.split("-").slice(0, 2).join("-")}
                    </div>
                    <div className="mt-1 text-2xl font-black tracking-tight">{active.statusText}</div>
                  </div>
                  {active.status === "searching" || active.status === "no_driver_found" ? (
                    <div className="relative flex h-12 w-12 items-center justify-center">
                      <span className="animate-ping-slow absolute h-10 w-10 rounded-full bg-amber-400/40" />
                      <span className="relative flex h-10 w-10 items-center justify-center rounded-full bg-amber-400 text-zinc-950">
                        <LocateFixed className="h-5 w-5" />
                      </span>
                    </div>
                  ) : (
                    <div className="chip bg-emerald-400/10 text-emerald-300">
                      <Clock className="h-3.5 w-3.5" />
                      {fmtDate(active.createdAt)}
                    </div>
                  )}
                </div>

                {/* Таймлайн статусов */}
                <div className="mt-6 flex items-center">
                  {ORDER_STEPS.slice(0, 4).map((s, i) => (
                    <div key={s.key} className="flex flex-1 items-center last:flex-none">
                      <div className="flex flex-col items-center gap-1.5">
                        <div
                          className={`flex h-8 w-8 items-center justify-center rounded-full border-2 text-xs font-black transition-colors ${
                            i <= step
                              ? "border-amber-400 bg-amber-400 text-zinc-950"
                              : "border-white/15 text-zinc-500"
                          }`}
                        >
                          {i + 1}
                        </div>
                        <span className={`text-center text-[10px] font-semibold leading-tight ${i <= step ? "text-amber-300" : "text-zinc-600"}`}>
                          {s.label}
                        </span>
                      </div>
                      {i < 3 && (
                        <div className={`mx-1 mb-5 h-0.5 flex-1 rounded ${i < step ? "bg-amber-400" : "bg-white/10"}`} />
                      )}
                    </div>
                  ))}
                </div>

                {/* Маршрут */}
                <div className="mt-6 space-y-3 rounded-2xl border border-white/8 bg-zinc-950/50 p-4">
                  <div className="flex items-start gap-3">
                    <div className="mt-0.5 h-3 w-3 rounded-full border-2 border-emerald-400" />
                    <div>
                      <div className="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Подача</div>
                      <div className="font-bold">
                        {active.pickupAddress}
                        {active.pickupEntrance && <span className="ml-2 text-sm font-normal text-zinc-400">подъезд {active.pickupEntrance}</span>}
                      </div>
                    </div>
                  </div>
                  <div className="ml-1.5 h-4 w-px bg-white/15" />
                  <div className="flex items-start gap-3">
                    <div className="mt-0.5 h-3 w-3 rounded-sm bg-amber-400" />
                    <div>
                      <div className="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Пункт назначения</div>
                      <div className="font-bold">{active.destinationAddress ?? "По указанию водителя"}</div>
                    </div>
                  </div>
                </div>

                <div className="mt-4 flex flex-wrap gap-2">
                  <span className="chip bg-white/6 text-zinc-300">{active.tariffName}</span>
                  <span className="chip bg-white/6 text-zinc-300">{active.paymentMethodName}</span>
                  {active.estimatedDistance && (
                    <span className="chip bg-white/6 text-zinc-300">
                      <Route className="h-3 w-3" />
                      {active.estimatedDistance} км · ~{active.estimatedDuration} мин
                    </span>
                  )}
                  <span className="chip bg-amber-400/15 text-amber-300">{fmtPrice(active.finalPrice ?? active.estimatedPrice)}</span>
                </div>

                {(active.status === "searching" || active.status === "no_driver_found" || active.status === "driver_assigned") && (
                  <button onClick={cancelOrder} className="btn-danger mt-5">
                    <XCircle className="h-4 w-4" />
                    Отменить заказ
                  </button>
                )}
              </div>

              {/* Чат с водителем */}
              {active.driver && <OrderChat orderId={active.id} user={user} withName={active.driver.name} />}
            </div>

            {/* Карточка водителя */}
            <div className="space-y-6">
              {active.driver ? (
                <div className="card animate-rise p-6">
                  <div className="mb-4 text-xs font-bold uppercase tracking-[0.2em] text-zinc-500">Ваш водитель</div>
                  <div className="flex items-center gap-4">
                    <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-400/15 text-xl font-black text-amber-300">
                      {active.driver.name[0]}
                    </div>
                    <div>
                      <div className="font-extrabold">{active.driver.name}</div>
                      <div className="flex items-center gap-1 text-sm text-zinc-400">
                        <Star className="h-3.5 w-3.5 fill-amber-400 text-amber-400" />
                        {active.driver.rating.toFixed(1)}
                        <a href={`tel:${active.driver.phone}`} className="ml-2 flex items-center gap-1 text-amber-300 hover:underline">
                          <Phone className="h-3.5 w-3.5" />
                          {active.driver.phone}
                        </a>
                      </div>
                    </div>
                  </div>
                  <div className="mt-5 rounded-xl border border-white/8 bg-zinc-950/50 p-4 text-center">
                    <div className="text-xs font-bold uppercase tracking-wider text-zinc-500">
                      {active.driver.carColor} {active.driver.carBrand} {active.driver.carModel}
                    </div>
                    <div className="mt-2 inline-block rounded-lg border-2 border-zinc-600 bg-zinc-800 px-4 py-1.5 text-lg font-black tracking-[0.15em] text-white">
                      {active.driver.licensePlate}
                    </div>
                  </div>
                </div>
              ) : (
                <div className="card animate-rise p-6 text-center">
                  <div className="animate-ticker text-sm font-semibold text-zinc-400">
                    Ищем ближайшего свободного водителя…
                  </div>
                  <div className="mx-auto mt-4 h-1.5 w-full max-w-xs overflow-hidden rounded-full bg-white/8">
                    <div className="h-full w-1/3 animate-pulse rounded-full bg-amber-400" />
                  </div>
                  <p className="mt-3 text-xs text-zinc-600">Радиус поиска расширяется автоматически</p>
                </div>
              )}

              {/* Живая карта поездки */}
              <div className="card animate-rise overflow-hidden" style={{ animationDelay: "0.1s" }}>
                <TaxiMap markers={trackMarkers} polyline={liveLine} className="h-64 w-full" />
                <div className="flex items-center justify-between px-4 py-2.5 text-[11px] font-semibold text-zinc-500">
                  <span className="flex items-center gap-1.5">
                    <span className="h-2 w-2 rounded-full bg-emerald-400" /> подача
                    <span className="mx-1 inline-block h-2 w-2 rotate-45 rounded-[3px] bg-amber-400" /> финиш
                  </span>
                  <span>{active.driver ? `${active.driver.carColor} · ${active.driver.carModel}` : "поиск водителя"}</span>
                </div>
              </div>
            </div>
          </div>
        ) : (
          /* ── Новая поездка + история ───────────────────────────────── */
          <div className="grid gap-6 lg:grid-cols-[1.2fr_1fr]">
            <form onSubmit={createOrder} className="space-y-5">
              <div className="card animate-rise p-6">
                <h2 className="text-xl font-black tracking-tight">Куда едем?</h2>

                <div className="mt-5 space-y-4">
                  <div className="relative">
                    <MapPin className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-emerald-400" />
                    <input
                      className="input-dark pl-11"
                      placeholder="Откуда — улица, дом"
                      value={pickup}
                      onChange={(e) => setPickup(e.target.value)}
                      list="places"
                      required
                    />
                  </div>
                  <div className="relative">
                    <DoorOpen className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
                    <input
                      className="input-dark pl-11"
                      placeholder="Подъезд (необязательно)"
                      value={entrance}
                      onChange={(e) => setEntrance(e.target.value)}
                      inputMode="numeric"
                    />
                  </div>
                  <div className="relative">
                    <Navigation className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-amber-400" />
                    <input
                      className="input-dark pl-11"
                      placeholder="Куда — улица, дом"
                      value={destination}
                      onChange={(e) => setDestination(e.target.value)}
                      list="places"
                    />
                  </div>
                  <datalist id="places">
                    {places.map((p) => (
                      <option key={p.name} value={p.name} />
                    ))}
                  </datalist>

                  <div className="relative">
                    <MessageSquareText className="pointer-events-none absolute left-4 top-4 h-4 w-4 text-zinc-500" />
                    <textarea
                      className="input-dark min-h-[68px] pl-11"
                      placeholder="Комментарий водителю (необязательно)"
                      value={comment}
                      onChange={(e) => setComment(e.target.value)}
                    />
                  </div>

                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="mb-1.5 flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-zinc-500">
                        <Users className="h-3.5 w-3.5" /> Пассажиров
                      </label>
                      <select className="input-dark" value={passengers} onChange={(e) => setPassengers(Number(e.target.value))}>
                        {[1, 2, 3, 4, 5, 6].map((n) => (
                          <option key={n} value={n}>{n}</option>
                        ))}
                      </select>
                    </div>
                    <div>
                      <label className="mb-1.5 flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-zinc-500">
                        <Banknote className="h-3.5 w-3.5" /> Оплата
                      </label>
                      <div className="grid grid-cols-3 gap-1.5">
                        {(
                          [
                            { v: "cash", icon: Banknote },
                            { v: "card", icon: CreditCard },
                            { v: "bonus", icon: Gift },
                          ] as const
                        ).map((p) => (
                          <button
                            type="button"
                            key={p.v}
                            onClick={() => setPayment(p.v)}
                            className={`flex items-center justify-center rounded-xl border py-3 transition ${
                              payment === p.v
                                ? "border-amber-400/60 bg-amber-400/10 text-amber-300"
                                : "border-white/10 text-zinc-500 hover:border-white/20"
                            }`}
                            title={p.v === "cash" ? "Наличные" : p.v === "card" ? "Карта" : "Бонусы"}
                          >
                            <p.icon className="h-4 w-4" />
                          </button>
                        ))}
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              {/* Тарифы */}
              <div className="card animate-rise p-6" style={{ animationDelay: "0.06s" }}>
                <div className="mb-4 flex items-center justify-between">
                  <h3 className="text-sm font-black uppercase tracking-[0.15em] text-zinc-400">Тариф</h3>
                  {estimating && (
                    <span className="flex items-center gap-1.5 text-xs font-semibold text-amber-300">
                      <Loader2 className="h-3.5 w-3.5 animate-spin" /> Считаем…
                    </span>
                  )}
                  {!estimating && estimates.some((e) => e.isNightRate || e.isPeakRate) && (
                    <span className="chip bg-amber-400/12 text-amber-300">
                      {estimates[0]?.isNightRate ? <Moon className="h-3 w-3" /> : <Zap className="h-3 w-3" />}
                      {estimates[0]?.isNightRate ? "Ночной тариф" : "Час пик"}
                    </span>
                  )}
                </div>
                <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                  {(
                    estimates.length > 0
                      ? estimates
                      : [
                          { tariffType: "economy", tariffName: "Эконом", description: "Бюджетные поездки", price: 0, distanceKm: 0, durationMinutes: 0, minimumFare: 99 },
                          { tariffType: "comfort", tariffName: "Комфорт", description: "Комфортные авто", price: 0, distanceKm: 0, durationMinutes: 0, minimumFare: 179 },
                          { tariffType: "business", tariffName: "Бизнес", description: "Бизнес-класс", price: 0, distanceKm: 0, durationMinutes: 0, minimumFare: 349 },
                          { tariffType: "minivan", tariffName: "Минивэн", description: "6+ мест", price: 0, distanceKm: 0, durationMinutes: 0, minimumFare: 249 },
                        ]
                  ).map((e) => {
                    const Icon = TARIFF_ICONS[e.tariffType] ?? Car;
                    const selected = tariff === e.tariffType;
                    return (
                      <button
                        type="button"
                        key={e.tariffType}
                        onClick={() => setTariff(e.tariffType)}
                        className={`rounded-2xl border p-3.5 text-left transition ${
                          selected
                            ? "border-amber-400/70 bg-amber-400/10 shadow-lg shadow-amber-400/10"
                            : "border-white/10 bg-white/[0.02] hover:border-white/25"
                        }`}
                      >
                        <Icon className={`mb-2 h-5 w-5 ${selected ? "text-amber-300" : "text-zinc-500"}`} />
                        <div className="text-sm font-extrabold">{e.tariffName}</div>
                        <div className={`mt-0.5 text-base font-black ${selected ? "text-amber-300" : "text-zinc-300"}`}>
                          {e.price > 0 ? fmtPrice(e.price) : `от ${fmtPrice(e.minimumFare)}`}
                        </div>
                        {e.distanceKm > 0 && (
                          <div className="mt-0.5 text-[10px] text-zinc-500">{e.distanceKm} км · {e.durationMinutes} мин</div>
                        )}
                      </button>
                    );
                  })}
                </div>
              </div>

              {error && (
                <div className="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm font-medium text-red-300">
                  {error}
                </div>
              )}

              <button type="submit" disabled={creating || pickup.trim().length < 3} className="btn-taxi w-full !py-4 !text-base">
                {creating ? "Создаём заказ…" : "Заказать такси"}
                {estimates.find((e) => e.tariffType === tariff)?.price
                  ? ` · ${fmtPrice(estimates.find((e) => e.tariffType === tariff)!.price)}`
                  : ""}
              </button>
            </form>

            {/* Карта маршрута + история */}
            <div className="space-y-6">
              <div className="card animate-rise overflow-hidden" style={{ animationDelay: "0.08s" }}>
                <TaxiMap
                  markers={formMarkers}
                  polyline={formLine}
                  className="h-56 w-full"
                  followBounds={formMarkers.length > 0}
                />
                <div className="flex items-center justify-between px-4 py-2.5">
                  <span className="text-[11px] font-bold uppercase tracking-[0.15em] text-zinc-500">
                    Тюмень · маршрут
                  </span>
                  <span className="text-[11px] text-zinc-500">
                    {geo.from && geo.to
                      ? `${estimates[0]?.distanceKm ?? "—"} км по дорогам`
                      : "введите адреса — покажем маршрут"}
                  </span>
                </div>
              </div>

              <div className="card h-fit animate-rise p-6" style={{ animationDelay: "0.12s" }}>
              <h3 className="mb-4 text-sm font-black uppercase tracking-[0.15em] text-zinc-400">
                История поездок
              </h3>
              {history.length === 0 ? (
                <p className="py-8 text-center text-sm text-zinc-600">
                  Здесь появятся ваши поездки
                </p>
              ) : (
                <div className="space-y-2.5">
                  {history.map((h) => (
                    <div key={h.id} className="rounded-2xl border border-white/8 bg-zinc-950/40 p-4">
                      <div className="flex items-center justify-between gap-2">
                        <span className={`chip ${h.status === "completed" ? "bg-emerald-400/10 text-emerald-300" : "bg-red-400/10 text-red-300"}`}>
                          {h.statusText}
                        </span>
                        <span className="text-sm font-black">{fmtPrice(h.finalPrice ?? h.estimatedPrice)}</span>
                      </div>
                      <div className="mt-2 space-y-1 text-sm">
                        <div className="flex items-center gap-2 text-zinc-300">
                          <span className="h-2 w-2 shrink-0 rounded-full border-2 border-emerald-400" />
                          <span className="truncate">{h.pickupAddress}</span>
                        </div>
                        <div className="flex items-center gap-2 text-zinc-400">
                          <span className="h-2 w-2 shrink-0 rounded-sm bg-amber-400" />
                          <span className="truncate">{h.destinationAddress ?? "—"}</span>
                        </div>
                      </div>
                      <div className="mt-2.5 flex items-center justify-between text-xs text-zinc-600">
                        <span>{fmtDate(h.createdAt)}</span>
                        {h.status === "completed" && !h.clientRating && !ratingDone[h.id] ? (
                          <div className="flex items-center gap-0.5">
                            {[1, 2, 3, 4, 5].map((r) => (
                              <button key={r} onClick={() => rateOrder(h.id, r)} className="transition hover:scale-125" title={`Оценить на ${r}`}>
                                <Star className="h-4 w-4 text-zinc-600 hover:fill-amber-400 hover:text-amber-400" />
                              </button>
                            ))}
                          </div>
                        ) : h.clientRating || ratingDone[h.id] ? (
                          <span className="flex items-center gap-1 text-amber-400">
                            <Star className="h-3.5 w-3.5 fill-amber-400" />
                            {h.clientRating ?? ratingDone[h.id]}
                          </span>
                        ) : null}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
            </div>
          </div>
        )}
      </main>
    </div>
  );
}
