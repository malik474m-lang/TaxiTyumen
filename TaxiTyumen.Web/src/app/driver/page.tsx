"use client";

import { useCallback, useEffect, useState } from "react";
import LoginScreen from "@/components/LoginScreen";
import {
  Wallet,
  Power,
  MapPin,
  Navigation,
  CheckCircle2,
  XCircle,
  UserRound,
  Phone,
  Banknote,
  Flag,
  Rocket,
  CarFront,
  Loader2,
  TrendingUp,
  AlertTriangle,
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
  type SessionUser,
  type OrderDto,
  type DriverDto,
} from "@/lib/client";

export default function DriverPage() {
  const [user, setUser] = useState<SessionUser | null>(null);
  const [loaded, setLoaded] = useState(false);
  const [me, setMe] = useState<DriverDto | null>(null);
  const [available, setAvailable] = useState<OrderDto[]>([]);
  const [current, setCurrent] = useState<OrderDto | null>(null);
  const [history, setHistory] = useState<OrderDto[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    setUser(getSession());
    setLoaded(true);
  }, []);

  const refresh = useCallback(async (u: SessionUser) => {
    if (!u.driverId) return;
    try {
      const [all, cur, hist] = await Promise.all([
        api<DriverDto[]>("/api/drivers"),
        api<OrderDto | null>(`/api/orders?view=driverCurrent&driverId=${u.driverId}`),
        api<OrderDto[]>(`/api/orders?view=history&driverId=${u.driverId}`),
      ]);
      const mine = all.find((d) => d.id === u.driverId) ?? null;
      setMe(mine);
      setCurrent(cur);
      setHistory(hist.slice(0, 8));

      if (mine && mine.status !== "offline") {
        const av = await api<OrderDto[]>(
          `/api/orders?view=available&driverId=${mine.id}&lat=${mine.latitude}&lng=${mine.longitude}`
        );
        setAvailable(av);
      } else {
        setAvailable([]);
      }
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

  useEvents(() => {
    const u = getSession();
    if (u?.role === "driver") refresh(u);
  });

  async function toggleOnline() {
    if (!me || !user) return;
    setBusy(true);
    try {
      await api(`/api/drivers/${me.id}/action`, {
        method: "POST",
        body: JSON.stringify({
          action: "status",
          status: me.status === "offline" ? "available" : "offline",
        }),
      });
      await refresh(user);
    } finally {
      setBusy(false);
    }
  }

  async function orderAction(orderId: string, action: string, extra: Record<string, unknown> = {}) {
    if (!me || !user) return;
    setError("");
    setBusy(true);
    try {
      await api(`/api/orders/${orderId}/action`, {
        method: "POST",
        body: JSON.stringify({ action, driverId: me.id, ...extra }),
      });
      await refresh(user);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Ошибка");
    } finally {
      setBusy(false);
    }
  }

  if (!loaded) return null;
  if (!user || user.role !== "driver" || !user.driverId || !user.token) {
    return <LoginScreen role="driver" onAuthed={setUser} />;
  }
  if (!me) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-amber-400" />
      </div>
    );
  }

  const online = me.status !== "offline";
  const lowBalance = me.balance < me.minBalanceForOrders;

  // Маркеры карты: я + доступные заказы / текущая цель
  const mapMarkers: MapMarker[] = [
    { id: "me", lat: me.latitude, lng: me.longitude, kind: "driver", label: me.licensePlate },
    ...(current
      ? [
          {
            id: "pickup",
            lat: current.pickupLatitude,
            lng: current.pickupLongitude,
            kind: "pickup" as const,
            label: "Подача",
          },
          ...(current.destinationLatitude != null
            ? [
                {
                  id: "dest",
                  lat: current.destinationLatitude,
                  lng: current.destinationLongitude ?? 0,
                  kind: "dest" as const,
                  label: "Финиш",
                },
              ]
            : []),
        ]
      : available.map((o) => ({
          id: o.id,
          lat: o.pickupLatitude,
          lng: o.pickupLongitude,
          kind: "pickup" as const,
          label: `${Math.round(o.estimatedPrice)} ₽`,
        }))),
  ];
  const mapLine: [number, number][] | null = current
    ? (current.routePoints && current.routePoints.length > 1
        ? current.routePoints
        : current.status === "in_progress" && current.destinationLatitude != null
          ? [
              [me.latitude, me.longitude],
              [current.destinationLatitude, current.destinationLongitude ?? 0],
            ]
          : [
              [me.latitude, me.longitude],
              [current.pickupLatitude, current.pickupLongitude],
            ])
    : null;

  return (
    <div className="min-h-screen">
      <AppHeader user={{ ...user, driverId: me.id }} subtitle="Водитель" />

      <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6">
        {error && (
          <div className="mb-4 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm font-medium text-red-300">
            {error}
          </div>
        )}

        <div className="grid gap-6 lg:grid-cols-[340px_1fr]">
          {/* Левая колонка: статус + финансы */}
          <div className="space-y-5">
            {/* Переключатель на линии */}
            <div className="card animate-rise p-6">
              <div className="flex items-center justify-between">
                <div>
                  <div className="text-xs font-bold uppercase tracking-[0.2em] text-zinc-500">Статус</div>
                  <div className={`mt-1 text-xl font-black ${online ? "text-emerald-400" : "text-zinc-400"}`}>
                    {me.statusText}
                  </div>
                </div>
                <button
                  onClick={toggleOnline}
                  disabled={busy || (online && !!current)}
                  className={`relative h-12 w-24 rounded-full p-1.5 transition-colors ${
                    online ? "bg-emerald-400" : "bg-zinc-700"
                  } ${online && current ? "cursor-not-allowed opacity-60" : "cursor-pointer"}`}
                >
                  <span
                    className={`flex h-9 w-9 items-center justify-center rounded-full bg-zinc-950 text-white shadow transition-transform ${
                      online ? "translate-x-12" : "translate-x-0"
                    }`}
                  >
                    <Power className="h-4 w-4" />
                  </span>
                </button>
              </div>
              <p className="mt-3 text-xs text-zinc-600">
                {online
                  ? current
                    ? "Завершите текущий заказ, чтобы уйти с линии"
                    : "Вы на линии — заказы появятся автоматически"
                  : "Включите статус «На линии», чтобы получать заказы"}
              </p>
            </div>

            {/* Авто */}
            <div className="card animate-rise p-6" style={{ animationDelay: "0.05s" }}>
              <div className="mb-3 flex items-center gap-2 text-xs font-bold uppercase tracking-[0.2em] text-zinc-500">
                <CarFront className="h-4 w-4" /> Автомобиль
              </div>
              <div className="font-extrabold">{me.carDisplay}</div>
              <div className="mt-2 inline-block rounded-lg border-2 border-zinc-600 bg-zinc-800 px-3 py-1 text-sm font-black tracking-[0.12em]">
                {me.licensePlate}
              </div>
            </div>

            {/* Финансы */}
            <div className="card animate-rise p-6" style={{ animationDelay: "0.1s" }}>
              <div className="mb-4 flex items-center gap-2 text-xs font-bold uppercase tracking-[0.2em] text-zinc-500">
                <Wallet className="h-4 w-4" /> Финансы
              </div>
              <div className={`rounded-2xl border p-4 ${lowBalance ? "border-red-400/40 bg-red-400/5" : "border-amber-400/25 bg-amber-400/5"}`}>
                <div className="flex items-center justify-between">
                  <span className="text-sm font-semibold text-zinc-400">Баланс</span>
                  <span className={`text-2xl font-black ${lowBalance ? "text-red-300" : "text-amber-300"}`}>
                    {fmtPrice(me.balance)}
                  </span>
                </div>
                {lowBalance && (
                  <div className="mt-2 flex items-start gap-1.5 text-xs font-medium text-red-300">
                    <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    Пополните баланс — минимум для приёма заказов {fmtPrice(me.minBalanceForOrders)}
                  </div>
                )}
              </div>
              <div className="mt-3 grid grid-cols-2 gap-2.5">
                <div className="rounded-xl bg-white/[0.04] p-3">
                  <div className="text-[11px] font-semibold text-zinc-500">Сегодня</div>
                  <div className="text-lg font-black text-emerald-300">{fmtPrice(me.todayEarnings)}</div>
                </div>
                <div className="rounded-xl bg-white/[0.04] p-3">
                  <div className="text-[11px] font-semibold text-zinc-500">Всего</div>
                  <div className="flex items-center gap-1 text-lg font-black">
                    <TrendingUp className="h-4 w-4 text-zinc-500" />
                    {fmtPrice(me.totalEarnings)}
                  </div>
                </div>
              </div>
              <div className="mt-2.5 rounded-xl bg-white/[0.04] p-3 text-center text-xs text-zinc-500">
                Поездок завершено: <b className="text-zinc-200">{me.completedTrips}</b>
              </div>
            </div>
          </div>

          {/* Правая колонка */}
          <div className="space-y-5">
            {/* Карта */}
            <div className="card animate-rise overflow-hidden">
              <TaxiMap
                markers={mapMarkers}
                polyline={mapLine}
                className="h-56 w-full"
                followBounds={mapMarkers.length > 1}
              />
              <div className="flex items-center justify-between px-4 py-2.5 text-[11px] font-semibold text-zinc-500">
                <span>{current ? "маршрут текущего заказа" : online ? `${available.length} заказ(ов) рядом` : "вы офлайн"}</span>
                <span className="flex items-center gap-1.5">
                  <span className="h-2 w-2 rounded-full bg-emerald-400" /> заказ
                  <span className="ml-1 inline-block h-3 w-3 rounded-md bg-amber-400" /> вы
                </span>
              </div>
            </div>

            {/* Текущий заказ */}
            {current && (
              <div className="card animate-rise border-amber-400/30 p-6">
                <div className="mb-4 flex items-center justify-between">
                  <span className="chip bg-amber-400/15 text-amber-300">Текущий заказ</span>
                  <span className="text-lg font-black">{fmtPrice(current.estimatedPrice)}</span>
                </div>
                <h3 className="text-xl font-black">{current.statusText}</h3>

                <div className="mt-4 space-y-3 rounded-2xl border border-white/8 bg-zinc-950/50 p-4">
                  <div className="flex items-start gap-3">
                    <div className="mt-0.5 h-3 w-3 shrink-0 rounded-full border-2 border-emerald-400" />
                    <div className="min-w-0">
                      <div className="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Подача</div>
                      <div className="truncate font-bold">
                        {current.pickupAddress}
                        {current.pickupEntrance && <span className="ml-2 text-sm font-normal text-zinc-400">подъезд {current.pickupEntrance}</span>}
                      </div>
                    </div>
                  </div>
                  <div className="ml-1.5 h-4 w-px bg-white/15" />
                  <div className="flex items-start gap-3">
                    <div className="mt-0.5 h-3 w-3 shrink-0 rounded-sm bg-amber-400" />
                    <div className="min-w-0">
                      <div className="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Куда</div>
                      <div className="truncate font-bold">{current.destinationAddress ?? "Назовёт клиент"}</div>
                    </div>
                  </div>
                </div>

                <div className="mt-3 flex flex-wrap items-center gap-2 text-sm text-zinc-400">
                  <span className="flex items-center gap-1.5">
                    <UserRound className="h-4 w-4" /> {current.clientName ?? "Клиент"}
                  </span>
                  {current.clientPhone && (
                    <a href={`tel:${current.clientPhone}`} className="flex items-center gap-1.5 text-amber-300 hover:underline">
                      <Phone className="h-4 w-4" /> {current.clientPhone}
                    </a>
                  )}
                  <span className="chip bg-white/6 text-zinc-300">{current.tariffName}</span>
                  <span className="chip bg-white/6 text-zinc-300">
                    <Banknote className="h-3 w-3" /> {current.paymentMethodName}
                  </span>
                  {(current.options ?? []).map((o) => (
                    <span key={o.code} className="chip bg-sky-400/10 text-sky-300">
                      {o.name}
                    </span>
                  ))}
                </div>

                {current.comment && (
                  <div className="mt-3 rounded-xl border border-white/8 bg-white/[0.03] p-3 text-sm text-zinc-300">
                    «{current.comment}»
                  </div>
                )}

                {/* Кнопки этапов */}
                <div className="mt-5 grid gap-2.5">
                  {current.status === "driver_assigned" && (
                    <button onClick={() => orderAction(current.id, "arrived")} disabled={busy} className="btn-taxi w-full">
                      <Flag className="h-4 w-4" /> Я на месте
                    </button>
                  )}
                  {current.status === "driver_arrived" && (
                    <button onClick={() => orderAction(current.id, "start")} disabled={busy} className="btn-taxi w-full">
                      <Rocket className="h-4 w-4" /> Начать поездку
                    </button>
                  )}
                  {current.status === "in_progress" && (
                    <button onClick={() => orderAction(current.id, "complete")} disabled={busy} className="btn-taxi w-full">
                      <CheckCircle2 className="h-4 w-4" /> Завершить · {fmtPrice(current.estimatedPrice)}
                    </button>
                  )}
                  {(current.status === "driver_assigned" || current.status === "driver_arrived") && (
                    <button
                      onClick={() => orderAction(current.id, "reject", { reason: "Не могу выполнить заказ" })}
                      disabled={busy}
                      className="btn-danger w-full"
                    >
                      <XCircle className="h-4 w-4" /> Отказаться (штраф {fmtPrice(50)})
                    </button>
                  )}
                </div>

                <div className="mt-4">
                  <OrderChat orderId={current.id} user={user} withName={current.clientName ?? "Клиент"} />
                </div>
              </div>
            )}

            {/* Доступные заказы */}
            <div className="card animate-rise p-6" style={{ animationDelay: "0.08s" }}>
              <div className="mb-4 flex items-center justify-between">
                <h3 className="text-sm font-black uppercase tracking-[0.15em] text-zinc-400">
                  Доступные заказы
                </h3>
                <span className={`chip ${online ? "bg-emerald-400/10 text-emerald-300" : "bg-white/6 text-zinc-500"}`}>
                  {online ? "поиск активен" : "вы офлайн"}
                </span>
              </div>

              {!online ? (
                <p className="py-8 text-center text-sm text-zinc-600">
                  Включите статус «На линии», чтобы видеть заказы
                </p>
              ) : current ? (
                <p className="py-6 text-center text-sm text-zinc-600">
                  Выполните текущий заказ — новые выдадим автоматически
                </p>
              ) : available.length === 0 ? (
                <div className="py-8 text-center">
                  <div className="animate-ticker text-sm font-semibold text-zinc-400">
                    Следим за новыми заказами…
                  </div>
                  <div className="mx-auto mt-4 h-1.5 w-full max-w-xs overflow-hidden rounded-full bg-white/8">
                    <div className="h-full w-1/3 animate-pulse rounded-full bg-amber-400" />
                  </div>
                </div>
              ) : (
                <div className="space-y-2.5">
                  {available.map((o) => (
                    <div key={o.id} className="rounded-2xl border border-white/8 bg-zinc-950/40 p-4 transition hover:border-amber-400/30">
                      <div className="flex items-center justify-between gap-2">
                        <span className="text-lg font-black text-amber-300">{fmtPrice(o.estimatedPrice)}</span>
                        <span className="chip bg-white/6 text-zinc-400">{o.tariffName}</span>
                      </div>
                      <div className="mt-2.5 space-y-1.5 text-sm">
                        <div className="flex items-center gap-2">
                          <MapPin className="h-3.5 w-3.5 shrink-0 text-emerald-400" />
                          <span className="truncate font-semibold">{o.pickupAddress}</span>
                          {o.distanceToPickup !== undefined && (
                            <span className="shrink-0 text-xs text-zinc-500">{o.distanceToPickup} км от вас</span>
                          )}
                        </div>
                        <div className="flex items-center gap-2 text-zinc-400">
                          <Navigation className="h-3.5 w-3.5 shrink-0 text-amber-400" />
                          <span className="truncate">{o.destinationAddress ?? "Назовёт клиент"}</span>
                          {o.estimatedDistance && <span className="shrink-0 text-xs text-zinc-500">{o.estimatedDistance} км</span>}
                        </div>
                      </div>
                      {o.comment && (
                        <div className="mt-2 text-xs italic text-zinc-500">«{o.comment}»</div>
                      )}
                      <div className="mt-3 flex items-center justify-between">
                        <span className="text-xs text-zinc-600">{fmtDate(o.createdAt)}</span>
                        <div className="flex gap-2">
                          <button
                            onClick={() => orderAction(o.id, "reject", { reason: null })}
                            disabled={busy}
                            className="btn-ghost !rounded-xl !px-3.5 !py-2 !text-xs"
                          >
                            Пропустить
                          </button>
                          <button
                            onClick={() => orderAction(o.id, "accept")}
                            disabled={busy}
                            className="btn-taxi !rounded-xl !px-4 !py-2 !text-xs"
                          >
                            <CheckCircle2 className="h-3.5 w-3.5" /> Принять
                          </button>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>

            {/* История */}
            {history.length > 0 && (
              <div className="card animate-rise p-6" style={{ animationDelay: "0.12s" }}>
                <h3 className="mb-4 text-sm font-black uppercase tracking-[0.15em] text-zinc-400">
                  Последние поездки
                </h3>
                <div className="space-y-2">
                  {history.map((h) => (
                    <div key={h.id} className="flex items-center justify-between gap-3 rounded-xl border border-white/6 bg-zinc-950/30 px-4 py-3 text-sm">
                      <div className="min-w-0">
                        <div className="truncate font-semibold">
                          {h.pickupAddress} → {h.destinationAddress ?? "—"}
                        </div>
                        <div className="text-xs text-zinc-600">{fmtDate(h.completedAt ?? h.createdAt)} · {h.statusText}</div>
                      </div>
                      <span className={`shrink-0 font-black ${h.status === "completed" ? "text-emerald-300" : "text-zinc-500"}`}>
                        {fmtPrice(h.finalPrice ?? h.estimatedPrice)}
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        </div>
      </main>
    </div>
  );
}
