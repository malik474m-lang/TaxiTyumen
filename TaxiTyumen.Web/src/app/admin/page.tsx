"use client";

import { useCallback, useEffect, useState } from "react";
import LoginScreen from "@/components/LoginScreen";
import {
  Gauge,
  Route,
  Banknote,
  CarFront,
  Users,
  ListOrdered,
  CirclePercent,
  BadgeCheck,
  Wallet,
  Plus,
  X,
  Loader2,
  TrendingUp,
  CheckCircle2,
  XCircle,
  FileDown,
  Settings2,
  PhoneCall,
} from "lucide-react";
import AppHeader from "@/components/AppHeader";
import MiniBars from "@/components/MiniBars";
import {
  api,
  getSession,
  fmtPrice,
  fmtDate,
  type SessionUser,
  type OrderDto,
  type DriverDto,
  type TariffDto,
} from "@/lib/client";

interface Stats {
  totalOrders: number;
  todayOrders: number;
  todayRevenue: number;
  activeOrders: number;
  onlineDrivers: number;
  totalDrivers: number;
  totalClients: number;
  completedToday: number;
  cancelledToday: number;
  avgCheck: number;
  topRoutes: { to: string; count: number }[];
  byTariff: { tariff: string; count: number; revenue: number }[];
  revenueByDay: { day: string; revenue: number; count: number }[];
  ordersByHour: { hour: number; count: number }[];
}

interface AutoCallCfg {
  id: string;
  enabled: boolean;
  escalateAfterMinutes: number;
  autoAssignEnabled: boolean;
  autoAssignRadiusKm: number;
}

const TABS = [
  { key: "overview", label: "Обзор", icon: Gauge },
  { key: "orders", label: "Заказы", icon: ListOrdered },
  { key: "drivers", label: "Водители", icon: CarFront },
  { key: "tariffs", label: "Тарифы", icon: CirclePercent },
  { key: "settings", label: "Настройки", icon: Settings2 },
] as const;

export default function AdminPage() {
  const [user, setUser] = useState<SessionUser | null>(null);
  const [loaded, setLoaded] = useState(false);
  const [tab, setTab] = useState<(typeof TABS)[number]["key"]>("overview");

  const [stats, setStats] = useState<Stats | null>(null);
  const [orders, setOrders] = useState<OrderDto[]>([]);
  const [drivers, setDrivers] = useState<DriverDto[]>([]);
  const [tariffs, setTariffs] = useState<TariffDto[]>([]);
  const [topupFor, setTopupFor] = useState<string | null>(null);
  const [topupAmount, setTopupAmount] = useState(500);
  const [editTariff, setEditTariff] = useState<TariffDto | null>(null);
  const [autocall, setAutocall] = useState<AutoCallCfg | null>(null);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState("");

  useEffect(() => {
    setUser(getSession());
    setLoaded(true);
  }, []);

  const refresh = useCallback(async () => {
    try {
      const [s, o, d, t] = await Promise.all([
        api<Stats>("/api/stats"),
        api<OrderDto[]>("/api/orders?view=all"),
        api<DriverDto[]>("/api/drivers"),
        api<TariffDto[]>("/api/tariffs"),
      ]);
      setStats(s);
      setOrders(o);
      setDrivers(d);
      setTariffs(t);
    } catch {
      /* ignore */
    }
  }, []);

  useEffect(() => {
    if (!user) return;
    refresh();
    api<AutoCallCfg>("/api/autocall").then(setAutocall).catch(() => {});
    const t = setInterval(refresh, 6000);
    return () => clearInterval(t);
  }, [user, refresh]);

  async function saveAutocall(cfg: AutoCallCfg) {
    setSaving(true);
    try {
      const updated = await api<AutoCallCfg>("/api/autocall", {
        method: "PUT",
        body: JSON.stringify(cfg),
      });
      setAutocall(updated);
      setMessage("Настройки автодозвона сохранены");
    } catch (e) {
      setMessage(e instanceof Error ? e.message : "Ошибка сохранения");
    } finally {
      setSaving(false);
    }
  }

  async function topup(driverId: string) {
    setSaving(true);
    try {
      await api(`/api/drivers/${driverId}/action`, {
        method: "POST",
        body: JSON.stringify({ action: "topup", amount: topupAmount, createdBy: user?.name ?? "admin" }),
      });
      setTopupFor(null);
      setMessage("Баланс пополнен");
      await refresh();
    } finally {
      setSaving(false);
    }
  }

  async function toggleVerify(driverId: string, current: boolean) {
    await api(`/api/drivers/${driverId}/action`, {
      method: "POST",
      body: JSON.stringify({ action: "verify", isVerified: !current }),
    });
    await refresh();
  }

  async function saveTariff() {
    if (!editTariff) return;
    setSaving(true);
    try {
      await api("/api/tariffs", { method: "PUT", body: JSON.stringify(editTariff) });
      setEditTariff(null);
      setMessage("Тариф сохранён");
      await refresh();
    } finally {
      setSaving(false);
    }
  }

  useEffect(() => {
    if (!message) return;
    const t = setTimeout(() => setMessage(""), 3000);
    return () => clearTimeout(t);
  }, [message]);

  // Экспорт заказов в CSV (порт Export.razor)
  async function downloadCsv() {
    try {
      const res = await fetch("/api/export/orders", {
        headers: user?.token ? { Authorization: `Bearer ${user.token}` } : {},
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data?.error ?? "Экспорт недоступен");
      }
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `taxi-tyumen-orders-${new Date().toISOString().slice(0, 10)}.csv`;
      a.click();
      URL.revokeObjectURL(url);
      setMessage("CSV выгружен");
    } catch (e) {
      setMessage(e instanceof Error ? e.message : "Ошибка экспорта");
    }
  }

  if (!loaded) return null;
  if (!user || user.role !== "admin" || !user.token) {
    return <LoginScreen role="admin" onAuthed={setUser} />;
  }

  return (
    <div className="min-h-screen">
      <AppHeader user={user} subtitle="Администрирование" />

      <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6">
        {/* Табы */}
        <div className="mb-6 flex flex-wrap gap-2">
          {TABS.map((t) => (
            <button
              key={t.key}
              onClick={() => setTab(t.key)}
              className={`flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-bold transition ${
                tab === t.key
                  ? "bg-amber-400 text-zinc-950 shadow-lg shadow-amber-400/20"
                  : "border border-white/10 bg-white/[0.03] text-zinc-400 hover:border-white/20 hover:text-zinc-200"
              }`}
            >
              <t.icon className="h-4 w-4" />
              {t.label}
            </button>
          ))}
          {message && (
            <span className="ml-auto flex items-center gap-1.5 rounded-xl border border-emerald-400/30 bg-emerald-400/10 px-4 py-2 text-sm font-semibold text-emerald-300">
              <CheckCircle2 className="h-4 w-4" /> {message}
            </span>
          )}
        </div>

        {/* ── ОБЗОР ─────────────────────────────────────────────────── */}
        {tab === "overview" && stats && (
          <div className="space-y-6">
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
              {[
                { icon: Banknote, label: "Выручка сегодня", value: fmtPrice(stats.todayRevenue), accent: true },
                { icon: Route, label: "Заказов сегодня", value: String(stats.todayOrders), sub: `всего ${stats.totalOrders}` },
                { icon: Loader2, label: "Активных сейчас", value: String(stats.activeOrders), sub: `${stats.completedToday} завершено` },
                { icon: CarFront, label: "Водителей на линии", value: String(stats.onlineDrivers), sub: `всего ${stats.totalDrivers}` },
                { icon: Users, label: "Клиентов", value: String(stats.totalClients) },
                { icon: TrendingUp, label: "Средний чек", value: fmtPrice(stats.avgCheck) },
                { icon: CheckCircle2, label: "Завершено сегодня", value: String(stats.completedToday) },
                { icon: XCircle, label: "Отменено сегодня", value: String(stats.cancelledToday) },
              ].map((c, i) => (
                <div key={c.label} className="card animate-rise p-5" style={{ animationDelay: `${i * 0.04}s` }}>
                  <div className="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.15em] text-zinc-500">
                    <c.icon className="h-4 w-4" /> {c.label}
                  </div>
                  <div className={`mt-2 text-3xl font-black tracking-tight ${c.accent ? "text-amber-300" : ""}`}>
                    {c.value}
                  </div>
                  {c.sub && <div className="mt-1 text-xs text-zinc-600">{c.sub}</div>}
                </div>
              ))}
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
              <div className="card p-6">
                <h3 className="mb-4 text-sm font-black uppercase tracking-[0.15em] text-zinc-400">
                  Топ направлений недели
                </h3>
                {stats.topRoutes.length === 0 ? (
                  <p className="text-sm text-zinc-600">Данных пока нет</p>
                ) : (
                  <div className="space-y-3">
                    {stats.topRoutes.map((r, i) => (
                      <div key={r.to ?? i} className="flex items-center gap-3">
                        <span className="w-5 text-sm font-black text-zinc-600">{i + 1}</span>
                        <div className="min-w-0 flex-1">
                          <div className="truncate text-sm font-semibold">{r.to}</div>
                          <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-white/8">
                            <div
                              className="h-full rounded-full bg-amber-400"
                              style={{ width: `${(r.count / (stats.topRoutes[0]?.count || 1)) * 100}%` }}
                            />
                          </div>
                        </div>
                        <span className="text-sm font-black text-amber-300">{r.count}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              <div className="card p-6">
                <h3 className="mb-4 text-sm font-black uppercase tracking-[0.15em] text-zinc-400">
                  По тарифам
                </h3>
                <div className="space-y-3">
                  {stats.byTariff.length === 0 ? (
                    <p className="text-sm text-zinc-600">Данных пока нет</p>
                  ) : (
                    stats.byTariff.map((t) => (
                      <div key={t.tariff} className="flex items-center justify-between rounded-xl border border-white/8 bg-zinc-950/40 px-4 py-3">
                        <div>
                          <div className="text-sm font-bold capitalize">{
                            { economy: "Эконом", comfort: "Комфорт", business: "Бизнес", minivan: "Минивэн" }[t.tariff as "economy"] ?? t.tariff
                          }</div>
                          <div className="text-xs text-zinc-600">{t.count} заказов</div>
                        </div>
                        <div className="text-base font-black text-amber-300">{fmtPrice(t.revenue)}</div>
                      </div>
                    ))
                  )}
                </div>
              </div>
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
              {/* Выручка по дням */}
              <div className="card p-6">
                <h3 className="mb-1 text-sm font-black uppercase tracking-[0.15em] text-zinc-400">
                  Выручка по дням
                </h3>
                <p className="mb-4 text-xs text-zinc-600">завершённые поездки, последние 7 суток</p>
                <MiniBars
                  data={stats.revenueByDay.map((d) => ({
                    label: new Date(d.day).toLocaleDateString("ru-RU", { weekday: "short" }),
                    value: d.revenue,
                    title: `${fmtPrice(d.revenue)} · ${d.count} заказ(ов)`,
                  }))}
                  formatValue={(v) => fmtPrice(v)}
                />
              </div>

              {/* Заказы по часам */}
              <div className="card p-6">
                <h3 className="mb-1 text-sm font-black uppercase tracking-[0.15em] text-zinc-400">
                  Заказы по часам
                </h3>
                <p className="mb-4 text-xs text-zinc-600">время Тюмени (UTC+5), за всё время</p>
                <MiniBars
                  data={stats.ordersByHour.map((h) => ({
                    label: h.hour % 4 === 0 ? String(h.hour).padStart(2, "0") : "",
                    value: h.count,
                    title: `${h.hour}:00 — ${h.count} заказ(ов)`,
                  }))}
                  accent="#38bdf8"
                />
              </div>
            </div>
          </div>
        )}

        {/* ── ЗАКАЗЫ ────────────────────────────────────────────────── */}
        {tab === "orders" && (
          <div className="space-y-3">
            <div className="flex items-center justify-between">
              <p className="text-sm font-semibold text-zinc-500">
                Последние заказы: <b className="text-zinc-200">{orders.length}</b>
              </p>
              <button onClick={downloadCsv} className="btn-ghost !px-3.5 !py-2 !text-xs">
                <FileDown className="h-3.5 w-3.5" />
                Экспорт CSV
              </button>
            </div>
            <div className="card overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full min-w-[860px] text-sm">
                <thead>
                  <tr className="border-b border-white/8 text-left text-[11px] font-bold uppercase tracking-wider text-zinc-500">
                    <th className="px-4 py-3">Заказ</th>
                    <th className="px-4 py-3">Статус</th>
                    <th className="px-4 py-3">Маршрут</th>
                    <th className="px-4 py-3">Клиент</th>
                    <th className="px-4 py-3">Водитель</th>
                    <th className="px-4 py-3 text-right">Цена</th>
                  </tr>
                </thead>
                <tbody>
                  {orders.map((o) => (
                    <tr key={o.id} className="border-b border-white/5 transition hover:bg-white/[0.02]">
                      <td className="px-4 py-3">
                        <div className="font-mono text-xs text-zinc-400">{o.orderNumber.slice(-14)}</div>
                        <div className="text-xs text-zinc-600">{fmtDate(o.createdAt)}</div>
                      </td>
                      <td className="px-4 py-3">
                        <span className={`chip ${
                          o.status === "completed" ? "bg-emerald-400/10 text-emerald-300"
                          : o.status === "cancelled" ? "bg-red-400/10 text-red-300"
                          : "bg-amber-400/10 text-amber-300"
                        }`}>
                          {o.statusText}
                        </span>
                        <div className="mt-1 text-[10px] text-zinc-600">
                          {o.source === "operator_app" ? "от оператора" : "из приложения"}
                        </div>
                      </td>
                      <td className="max-w-[260px] px-4 py-3">
                        <div className="truncate font-semibold">{o.pickupAddress}</div>
                        <div className="truncate text-xs text-zinc-500">→ {o.destinationAddress ?? "—"}</div>
                      </td>
                      <td className="px-4 py-3">
                        <div className="font-semibold">{o.clientName ?? "—"}</div>
                        <div className="text-xs text-zinc-600">{o.clientPhone}</div>
                      </td>
                      <td className="px-4 py-3">
                        {o.driver ? (
                          <>
                            <div className="font-semibold">{o.driver.name}</div>
                            <div className="text-xs text-zinc-600">{o.driver.licensePlate}</div>
                          </>
                        ) : (
                          <span className="text-zinc-600">—</span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-right">
                        <div className="font-black">{fmtPrice(o.finalPrice ?? o.estimatedPrice)}</div>
                        <div className="text-xs text-zinc-600">{o.tariffName}</div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {orders.length === 0 && (
                <p className="py-16 text-center text-sm text-zinc-600">Заказов пока нет</p>
              )}
            </div>
          </div>
          </div>
        )}

        {/* ── ВОДИТЕЛИ ──────────────────────────────────────────────── */}
        {tab === "drivers" && (
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {drivers.map((d, i) => (
              <div key={d.id} className="card animate-rise p-5" style={{ animationDelay: `${i * 0.03}s` }}>
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <div className="flex items-center gap-2 font-extrabold">
                      {d.name}
                      {d.isVerified && <BadgeCheck className="h-4 w-4 text-sky-400" />}
                    </div>
                    <div className="mt-0.5 text-xs text-zinc-500">
                      {d.carDisplay} · <b className="text-zinc-300">{d.licensePlate}</b>
                    </div>
                  </div>
                  <span className={`chip ${d.status !== "offline" ? "bg-emerald-400/10 text-emerald-300" : "bg-white/6 text-zinc-500"}`}>
                    {d.statusText}
                  </span>
                </div>

                <div className="mt-4 grid grid-cols-3 gap-2 text-center">
                  <div className="rounded-xl bg-white/[0.04] p-2.5">
                    <div className="text-[10px] font-semibold text-zinc-500">Баланс</div>
                    <div className={`text-sm font-black ${d.balance < d.minBalanceForOrders ? "text-red-300" : "text-amber-300"}`}>
                      {fmtPrice(d.balance)}
                    </div>
                  </div>
                  <div className="rounded-xl bg-white/[0.04] p-2.5">
                    <div className="text-[10px] font-semibold text-zinc-500">Поездки</div>
                    <div className="text-sm font-black">{d.completedTrips}</div>
                  </div>
                  <div className="rounded-xl bg-white/[0.04] p-2.5">
                    <div className="text-[10px] font-semibold text-zinc-500">Заработано</div>
                    <div className="text-sm font-black text-emerald-300">{fmtPrice(d.totalEarnings)}</div>
                  </div>
                </div>

                {topupFor === d.id ? (
                  <div className="mt-3 flex gap-2">
                    <select className="input-dark !py-2 !text-xs" value={topupAmount} onChange={(e) => setTopupAmount(Number(e.target.value))}>
                      {[300, 500, 1000, 2000].map((a) => (
                        <option key={a} value={a}>+{a} ₽</option>
                      ))}
                    </select>
                    <button onClick={() => topup(d.id)} disabled={saving} className="btn-taxi !px-3.5 !py-2 !text-xs">
                      {saving ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : "ОК"}
                    </button>
                    <button onClick={() => setTopupFor(null)} className="btn-ghost !px-3 !py-2 !text-xs">
                      <X className="h-3.5 w-3.5" />
                    </button>
                  </div>
                ) : (
                  <div className="mt-3 flex gap-2">
                    <button onClick={() => setTopupFor(d.id)} className="btn-ghost flex-1 !px-3 !py-2 !text-xs">
                      <Plus className="h-3.5 w-3.5" /> Пополнить
                    </button>
                    <button onClick={() => toggleVerify(d.id, d.isVerified)} className="btn-ghost flex-1 !px-3 !py-2 !text-xs">
                      <BadgeCheck className={`h-3.5 w-3.5 ${d.isVerified ? "text-sky-400" : "text-zinc-500"}`} />
                      {d.isVerified ? "Снять вериф." : "Верифицировать"}
                    </button>
                  </div>
                )}
              </div>
            ))}
          </div>
        )}

        {/* ── НАСТРОЙКИ ─────────────────────────────────────────────── */}
        {tab === "settings" && autocall && (
          <div className="max-w-2xl">
            <div className="card animate-rise p-6">
              <div className="flex items-center gap-3">
                <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-sky-400/15 text-sky-300">
                  <PhoneCall className="h-5 w-5" />
                </div>
                <div>
                  <h3 className="text-lg font-black tracking-tight">Автодозвон</h3>
                  <p className="text-xs text-zinc-500">
                    Логика AutoCallService: заказы без водителя эскалируются операторам
                  </p>
                </div>
                <span className={`chip ml-auto ${autocall.enabled ? "bg-emerald-400/10 text-emerald-300" : "bg-white/6 text-zinc-500"}`}>
                  {autocall.enabled ? "Включён" : "Выключен"}
                </span>
              </div>

              <div className="mt-6 space-y-4">
                <label className="flex items-center justify-between rounded-xl border border-white/8 bg-zinc-950/40 px-4 py-3">
                  <span className="text-sm font-semibold text-zinc-300">Сервис работает</span>
                  <input
                    type="checkbox"
                    checked={autocall.enabled}
                    onChange={(e) => setAutocall({ ...autocall, enabled: e.target.checked })}
                    className="h-5 w-5 accent-amber-400"
                  />
                </label>

                <div className="grid grid-cols-[1fr_120px] items-center gap-3 rounded-xl border border-white/8 bg-zinc-950/40 px-4 py-3">
                  <span className="text-sm text-zinc-300">
                    Эскалация, если водителя нет дольше (мин)
                  </span>
                  <input
                    type="number"
                    min={1}
                    max={60}
                    className="input-dark !py-2 !text-sm"
                    value={autocall.escalateAfterMinutes}
                    onChange={(e) =>
                      setAutocall({ ...autocall, escalateAfterMinutes: Number(e.target.value) })
                    }
                  />
                </div>

                <label className="flex items-center justify-between rounded-xl border border-white/8 bg-zinc-950/40 px-4 py-3">
                  <span className="text-sm font-semibold text-zinc-300">
                    Автоназначение ближайшему водителю
                  </span>
                  <input
                    type="checkbox"
                    checked={autocall.autoAssignEnabled}
                    onChange={(e) =>
                      setAutocall({ ...autocall, autoAssignEnabled: e.target.checked })
                    }
                    className="h-5 w-5 accent-amber-400"
                  />
                </label>

                {autocall.autoAssignEnabled && (
                  <div className="grid grid-cols-[1fr_120px] items-center gap-3 rounded-xl border border-white/8 bg-zinc-950/40 px-4 py-3">
                    <span className="text-sm text-zinc-300">Радиус поиска (км)</span>
                    <input
                      type="number"
                      min={1}
                      max={30}
                      className="input-dark !py-2 !text-sm"
                      value={autocall.autoAssignRadiusKm}
                      onChange={(e) =>
                        setAutocall({ ...autocall, autoAssignRadiusKm: Number(e.target.value) })
                      }
                    />
                  </div>
                )}

                <button onClick={() => saveAutocall(autocall)} disabled={saving} className="btn-taxi w-full">
                  {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}
                  Сохранить настройки
                </button>
              </div>
            </div>
          </div>
        )}

        {/* ── ТАРИФЫ ────────────────────────────────────────────────── */}
        {tab === "tariffs" && (
          <div className="grid gap-4 md:grid-cols-2">
            {tariffs.map((t, i) => (
              <div key={t.id} className="card animate-rise p-6" style={{ animationDelay: `${i * 0.04}s` }}>
                {editTariff?.id === t.id ? (
                  <div className="space-y-3">
                    <div className="flex items-center justify-between">
                      <h3 className="text-lg font-black">{t.name}</h3>
                      <label className="flex items-center gap-2 text-xs font-semibold text-zinc-400">
                        <input
                          type="checkbox"
                          checked={editTariff.isActive}
                          onChange={(e) => setEditTariff({ ...editTariff, isActive: e.target.checked })}
                          className="h-4 w-4 accent-amber-400"
                        />
                        Активен
                      </label>
                    </div>
                    {(
                      [
                        ["baseFare", "Базовый тариф, ₽"],
                        ["pricePerKm", "За км, ₽"],
                        ["pricePerMinute", "За минуту, ₽"],
                        ["minimumFare", "Минимум, ₽"],
                        ["nightMultiplier", "Ночной ×"],
                        ["peakMultiplier", "Час пик ×"],
                        ["commissionPercent", "Комиссия, %"],
                        ["paidWaitingPerMinute", "Ожидание, ₽/мин"],
                      ] as const
                    ).map(([key, label]) => (
                      <div key={key} className="grid grid-cols-[1fr_110px] items-center gap-3">
                        <label className="text-sm text-zinc-400">{label}</label>
                        <input
                          type="number"
                          step="0.1"
                          className="input-dark !py-2 !text-sm"
                          value={editTariff[key]}
                          onChange={(e) => setEditTariff({ ...editTariff, [key]: Number(e.target.value) })}
                        />
                      </div>
                    ))}
                    <div className="flex gap-2 pt-2">
                      <button onClick={saveTariff} disabled={saving} className="btn-taxi flex-1 !py-2.5 !text-xs">
                        {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : "Сохранить"}
                      </button>
                      <button onClick={() => setEditTariff(null)} className="btn-ghost !py-2.5 !text-xs">Отмена</button>
                    </div>
                  </div>
                ) : (
                  <>
                    <div className="flex items-start justify-between">
                      <div>
                        <h3 className="text-lg font-black">{t.name}</h3>
                        <p className="mt-0.5 text-xs text-zinc-500">{t.description}</p>
                      </div>
                      <span className={`chip ${t.isActive ? "bg-emerald-400/10 text-emerald-300" : "bg-white/6 text-zinc-500"}`}>
                        {t.isActive ? "Активен" : "Выключен"}
                      </span>
                    </div>
                    <div className="mt-4 grid grid-cols-4 gap-2 text-center">
                      {[
                        ["Посадка", `${t.baseFare} ₽`],
                        ["км", `${t.pricePerKm} ₽`],
                        ["мин", `${t.pricePerMinute} ₽`],
                        ["Минимум", `${t.minimumFare} ₽`],
                      ].map(([l, v]) => (
                        <div key={l} className="rounded-xl bg-white/[0.04] p-2.5">
                          <div className="text-[10px] font-semibold text-zinc-500">{l}</div>
                          <div className="text-sm font-black">{v}</div>
                        </div>
                      ))}
                    </div>
                    <div className="mt-2 grid grid-cols-3 gap-2 text-center">
                      <div className="rounded-xl bg-white/[0.04] p-2 text-xs text-zinc-400">
                        Ночь ×<b className="text-zinc-100">{t.nightMultiplier}</b>
                      </div>
                      <div className="rounded-xl bg-white/[0.04] p-2 text-xs text-zinc-400">
                        Пик ×<b className="text-zinc-100">{t.peakMultiplier}</b>
                      </div>
                      <div className="rounded-xl bg-white/[0.04] p-2 text-xs text-zinc-400">
                        Комиссия <b className="text-zinc-100">{t.commissionPercent}%</b>
                      </div>
                    </div>
                    <button onClick={() => setEditTariff(t)} className="btn-ghost mt-4 w-full !py-2.5 !text-xs">
                      <Wallet className="h-3.5 w-3.5" /> Редактировать тариф
                    </button>
                  </>
                )}
              </div>
            ))}
          </div>
        )}
      </main>
    </div>
  );
}
