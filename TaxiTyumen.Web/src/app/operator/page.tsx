"use client";

import { useCallback, useEffect, useState } from "react";
import LoginScreen from "@/components/LoginScreen";
import {
  Phone,
  UserRound,
  MapPin,
  Navigation,
  MessageSquareText,
  Users,
  PlusCircle,
  Radio,
  CarFront,
  XCircle,
  UserCheck,
  Loader2,
  Route,
  DoorOpen,
} from "lucide-react";
import AppHeader from "@/components/AppHeader";
import {
  api,
  getSession,
  fmtPrice,
  fmtDate,
  type SessionUser,
  type OrderDto,
  type DriverDto,
} from "@/lib/client";

const STATUS_COLORS: Record<string, string> = {
  searching: "bg-amber-400/12 text-amber-300",
  no_driver_found: "bg-orange-400/12 text-orange-300",
  driver_assigned: "bg-sky-400/12 text-sky-300",
  driver_en_route: "bg-sky-400/12 text-sky-300",
  driver_arrived: "bg-emerald-400/12 text-emerald-300",
  in_progress: "bg-violet-400/12 text-violet-300",
};

export default function OperatorPage() {
  const [user, setUser] = useState<SessionUser | null>(null);
  const [loaded, setLoaded] = useState(false);

  // Форма приёма звонка
  const [clientPhone, setClientPhone] = useState("");
  const [clientName, setClientName] = useState("");
  const [pickup, setPickup] = useState("");
  const [entrance, setEntrance] = useState("");
  const [destination, setDestination] = useState("");
  const [tariff, setTariff] = useState("economy");
  const [passengers, setPassengers] = useState(1);
  const [comment, setComment] = useState("");
  const [places, setPlaces] = useState<{ name: string }[]>([]);

  const [active, setActive] = useState<OrderDto[]>([]);
  const [drivers, setDrivers] = useState<DriverDto[]>([]);
  const [creating, setCreating] = useState(false);
  const [assignFor, setAssignFor] = useState<string | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    setUser(getSession());
    setLoaded(true);
  }, []);

  useEffect(() => {
    api<{ name: string }[]>("/api/places").then(setPlaces).catch(() => {});
  }, []);

  const refresh = useCallback(async () => {
    try {
      const [orders, drs] = await Promise.all([
        api<OrderDto[]>("/api/orders?view=active"),
        api<DriverDto[]>("/api/drivers"),
      ]);
      setActive(orders);
      setDrivers(drs);
    } catch {
      /* ignore */
    }
  }, []);

  useEffect(() => {
    if (!user) return;
    refresh();
    const t = setInterval(refresh, 4000);
    return () => clearInterval(t);
  }, [user, refresh]);

  async function createOrder(e: React.FormEvent) {
    e.preventDefault();
    if (!user) return;
    setError("");
    setCreating(true);
    try {
      await api("/api/orders/operator", {
        method: "POST",
        body: JSON.stringify({
          operatorId: user.id,
          clientPhone,
          clientName,
          pickupAddress: pickup,
          pickupEntrance: entrance || null,
          destinationAddress: destination || null,
          tariff,
          passengerCount: passengers,
          comment: comment || null,
        }),
      });
      setPickup("");
      setEntrance("");
      setDestination("");
      setComment("");
      setClientName("");
      await refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Не удалось создать заказ");
    } finally {
      setCreating(false);
    }
  }

  async function assign(orderId: string, driverId: string) {
    setAssignFor(null);
    await api(`/api/orders/${orderId}/action`, {
      method: "POST",
      body: JSON.stringify({ action: "assign", driverId }),
    })
      .then(refresh)
      .catch((e) => setError(e.message));
  }

  async function cancel(orderId: string) {
    await api(`/api/orders/${orderId}/action`, {
      method: "POST",
      body: JSON.stringify({ action: "cancel", reason: "Отменено диспетчером" }),
    })
      .then(refresh)
      .catch(() => {});
  }

  if (!loaded) return null;
  // Диспетчерская — только операторам (даже админ сюда не входит)
  if (!user || user.role !== "operator") {
    return <LoginScreen role="operator" onAuthed={setUser} />;
  }
  const onlineDrivers = drivers.filter((d) => d.status !== "offline");

  return (
    <div className="min-h-screen">
      <AppHeader user={user} subtitle="Диспетчерская" />

      <main className="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6">
        {error && (
          <div className="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm font-medium text-red-300">
            {error}
          </div>
        )}

        <div className="grid gap-6 lg:grid-cols-[420px_1fr]">
          {/* Приём звонка */}
          <form onSubmit={createOrder} className="card h-fit animate-rise space-y-4 p-6">
            <div className="flex items-center justify-between">
              <h2 className="text-lg font-black tracking-tight">Новый заказ</h2>
              <span className="chip bg-emerald-400/10 text-emerald-300">
                <Radio className="h-3.5 w-3.5" />
                {onlineDrivers.length} на линии
              </span>
            </div>

            <div className="grid grid-cols-[1.2fr_1fr] gap-3">
              <div className="relative">
                <Phone className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
                <input className="input-dark pl-11" placeholder="Телефон клиента" value={clientPhone} onChange={(e) => setClientPhone(e.target.value)} required />
              </div>
              <div className="relative">
                <UserRound className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
                <input className="input-dark pl-11" placeholder="Имя" value={clientName} onChange={(e) => setClientName(e.target.value)} />
              </div>
            </div>

            <div className="relative">
              <MapPin className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-emerald-400" />
              <input className="input-dark pl-11" placeholder="Адрес подачи" value={pickup} onChange={(e) => setPickup(e.target.value)} list="places-op" required />
            </div>
            <div className="relative">
              <DoorOpen className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
              <input className="input-dark pl-11" placeholder="Подъезд" value={entrance} onChange={(e) => setEntrance(e.target.value)} inputMode="numeric" />
            </div>
            <div className="relative">
              <Navigation className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-amber-400" />
              <input className="input-dark pl-11" placeholder="Куда (необязательно)" value={destination} onChange={(e) => setDestination(e.target.value)} list="places-op" />
            </div>
            <datalist id="places-op">
              {places.map((p) => (
                <option key={p.name} value={p.name} />
              ))}
            </datalist>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-zinc-500">Тариф</label>
                <select className="input-dark" value={tariff} onChange={(e) => setTariff(e.target.value)}>
                  <option value="economy">Эконом</option>
                  <option value="comfort">Комфорт</option>
                  <option value="business">Бизнес</option>
                  <option value="minivan">Минивэн</option>
                </select>
              </div>
              <div>
                <label className="mb-1.5 flex items-center gap-1 text-[11px] font-bold uppercase tracking-wider text-zinc-500">
                  <Users className="h-3.5 w-3.5" /> Пассажиры
                </label>
                <select className="input-dark" value={passengers} onChange={(e) => setPassengers(Number(e.target.value))}>
                  {[1, 2, 3, 4, 5, 6].map((n) => (
                    <option key={n} value={n}>{n}</option>
                  ))}
                </select>
              </div>
            </div>

            <div className="relative">
              <MessageSquareText className="pointer-events-none absolute left-4 top-4 h-4 w-4 text-zinc-500" />
              <textarea className="input-dark min-h-[60px] pl-11" placeholder="Комментарий" value={comment} onChange={(e) => setComment(e.target.value)} />
            </div>

            <button type="submit" disabled={creating} className="btn-taxi w-full">
              {creating ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <PlusCircle className="h-4 w-4" />
              )}
              Создать заказ
            </button>
          </form>

          {/* Активные заказы */}
          <div className="card animate-rise p-6" style={{ animationDelay: "0.06s" }}>
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-black tracking-tight">Активные заказы</h2>
              <span className="chip bg-white/6 text-zinc-300">{active.length}</span>
            </div>

            {active.length === 0 ? (
              <div className="py-16 text-center">
                <Radio className="mx-auto mb-3 h-8 w-8 text-zinc-700" />
                <p className="text-sm text-zinc-500">Активных заказов нет — примите звонок слева</p>
              </div>
            ) : (
              <div className="space-y-2.5">
                {active.map((o) => (
                  <div key={o.id} className="rounded-2xl border border-white/8 bg-zinc-950/40 p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <div className="flex items-center gap-2.5">
                        <span className={`chip ${STATUS_COLORS[o.status] ?? "bg-white/6 text-zinc-300"}`}>
                          {o.statusText}
                        </span>
                        <span className="text-xs font-semibold text-zinc-500">
                          {o.orderNumber.slice(-9)} · {fmtDate(o.createdAt)}
                        </span>
                      </div>
                      <span className="text-base font-black text-amber-300">{fmtPrice(o.estimatedPrice)}</span>
                    </div>

                    <div className="mt-2.5 grid gap-2 text-sm sm:grid-cols-2">
                      <div className="min-w-0">
                        <div className="flex items-center gap-2">
                          <MapPin className="h-3.5 w-3.5 shrink-0 text-emerald-400" />
                          <span className="truncate font-semibold">
                            {o.pickupAddress}
                            {o.pickupEntrance && <span className="ml-1.5 font-normal text-zinc-500">п. {o.pickupEntrance}</span>}
                          </span>
                        </div>
                        <div className="mt-1.5 flex items-center gap-2 text-zinc-400">
                          <Navigation className="h-3.5 w-3.5 shrink-0 text-amber-400" />
                          <span className="truncate">{o.destinationAddress ?? "Назовёт клиент"}</span>
                        </div>
                        {o.estimatedDistance && (
                          <div className="mt-1.5 flex items-center gap-2 text-xs text-zinc-600">
                            <Route className="h-3.5 w-3.5" />
                            {o.estimatedDistance} км · ~{o.estimatedDuration} мин · {o.tariffName}
                          </div>
                        )}
                      </div>
                      <div className="space-y-1 text-sm">
                        <div className="flex items-center gap-2">
                          <UserRound className="h-3.5 w-3.5 text-zinc-500" />
                          <span className="font-semibold">{o.clientName ?? "Клиент"}</span>
                          <a href={`tel:${o.clientPhone}`} className="text-xs text-amber-300 hover:underline">{o.clientPhone}</a>
                        </div>
                        {o.driver ? (
                          <div className="flex items-center gap-2 text-zinc-400">
                            <CarFront className="h-3.5 w-3.5 text-zinc-500" />
                            <span className="truncate">
                              {o.driver.name} · {o.driver.carColor} {o.driver.carModel} · <b className="text-zinc-200">{o.driver.licensePlate}</b>
                            </span>
                          </div>
                        ) : (
                          <div className="text-xs text-zinc-600">Водитель не назначен</div>
                        )}
                      </div>
                    </div>

                    <div className="relative mt-3 flex flex-wrap items-center gap-2 border-t border-white/6 pt-3">
                      <button onClick={() => setAssignFor(assignFor === o.id ? null : o.id)} className="btn-ghost !px-3.5 !py-2 !text-xs">
                        <UserCheck className="h-3.5 w-3.5" />
                        {o.driver ? "Переназначить" : "Назначить водителя"}
                      </button>
                      <button onClick={() => cancel(o.id)} className="btn-danger !px-3.5 !py-2 !text-xs">
                        <XCircle className="h-3.5 w-3.5" /> Отменить
                      </button>

                      {/* Выбор водителя */}
                      {assignFor === o.id && (
                        <div className="absolute left-0 top-full z-20 mt-2 w-full max-w-md rounded-2xl border border-white/10 bg-[#15151a] p-2 shadow-2xl">
                          <div className="px-2 py-1.5 text-[11px] font-bold uppercase tracking-wider text-zinc-500">
                            Водители на линии
                          </div>
                          {onlineDrivers.length === 0 && (
                            <div className="px-2 py-3 text-sm text-zinc-500">Нет водителей на линии</div>
                          )}
                          {onlineDrivers.map((d) => (
                            <button
                              key={d.id}
                              onClick={() => assign(o.id, d.id)}
                              className="flex w-full items-center justify-between gap-2 rounded-xl px-3 py-2.5 text-left text-sm transition hover:bg-amber-400/10"
                            >
                              <span>
                                <b>{d.name}</b>
                                <span className="ml-2 text-zinc-500">{d.carDisplay} · {d.licensePlate}</span>
                              </span>
                              <span className={`chip ${d.status === "available" ? "bg-emerald-400/10 text-emerald-300" : "bg-amber-400/10 text-amber-300"}`}>
                                {d.statusText}
                              </span>
                            </button>
                          ))}
                        </div>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </main>
    </div>
  );
}
