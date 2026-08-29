"use client";

// Панель «Клиенты» в админке — порт Clients.razor из TaxiAdmin:
// поиск, метрики поездок, блокировка/разблокировка, верификация телефона.
import { useCallback, useEffect, useState } from "react";
import {
  BadgeCheck,
  Ban,
  Loader2,
  Lock,
  LockOpen,
  Search,
  Star,
  Users,
  X,
} from "lucide-react";
import { api, fmtDate, fmtPrice, type ClientDto } from "@/lib/client";

export default function ClientsPanel() {
  const [clients, setClients] = useState<ClientDto[]>([]);
  const [q, setQ] = useState("");
  const [loading, setLoading] = useState(true);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [blockFor, setBlockFor] = useState<ClientDto | null>(null);
  const [reason, setReason] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async (query: string) => {
    try {
      const list = await api<ClientDto[]>(`/api/clients${query ? `?q=${encodeURIComponent(query)}` : ""}`);
      setClients(list);
      setError("");
    } catch (e) {
      setError(e instanceof Error ? e.message : "Ошибка загрузки клиентов");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const t = setTimeout(() => load(q), 250);
    return () => clearTimeout(t);
  }, [q, load]);

  async function act(client: ClientDto, action: string, blockReason?: string) {
    setBusyId(client.id);
    setError("");
    try {
      await api(`/api/clients/${client.id}/action`, {
        method: "POST",
        body: JSON.stringify({ action, reason: blockReason }),
      });
      await load(q);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Ошибка действия");
    } finally {
      setBusyId(null);
    }
  }

  const totals = clients.reduce(
    (acc, c) => ({
      trips: acc.trips + c.trips,
      spent: acc.spent + c.totalSpent,
      blocked: acc.blocked + (c.isBlocked ? 1 : 0),
    }),
    { trips: 0, spent: 0, blocked: 0 }
  );

  return (
    <div className="space-y-4">
      {/* поиск + сводка */}
      <div className="flex flex-wrap items-center gap-3">
        <div className="relative min-w-56 flex-1">
          <Search className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
          <input
            className="input-dark !pl-10"
            placeholder="Поиск по имени или телефону…"
            value={q}
            onChange={(e) => setQ(e.target.value)}
          />
        </div>
        <div className="chip bg-white/6 text-zinc-300">
          <Users className="h-3.5 w-3.5" /> {clients.length}
        </div>
        <div className="chip bg-white/6 text-zinc-300">поездок: {totals.trips}</div>
        <div className="chip bg-emerald-400/10 text-emerald-300">выручка: {fmtPrice(totals.spent)}</div>
        {totals.blocked > 0 && (
          <div className="chip bg-red-400/10 text-red-300">заблокировано: {totals.blocked}</div>
        )}
      </div>

      {error && (
        <div className="rounded-xl border border-red-400/30 bg-red-400/10 px-4 py-2.5 text-sm font-semibold text-red-300">
          {error}
        </div>
      )}

      {loading ? (
        <div className="flex items-center justify-center gap-2 py-16 text-zinc-500">
          <Loader2 className="h-5 w-5 animate-spin" /> Загрузка клиентов…
        </div>
      ) : clients.length === 0 ? (
        <div className="card flex flex-col items-center gap-2 py-16 text-zinc-500">
          <Users className="h-8 w-8" />
          <div className="text-sm font-semibold">Клиенты не найдены</div>
        </div>
      ) : (
        <div className="card overflow-x-auto p-0">
          <table className="w-full min-w-[900px] text-sm">
            <thead>
              <tr className="border-b border-white/8 text-left text-xs uppercase tracking-wider text-zinc-500">
                <th className="px-4 py-3 font-bold">Клиент</th>
                <th className="px-4 py-3 font-bold">Телефон</th>
                <th className="px-4 py-3 text-center font-bold">Рейтинг</th>
                <th className="px-4 py-3 text-center font-bold">Поездки</th>
                <th className="px-4 py-3 text-center font-bold">Отмены</th>
                <th className="px-4 py-3 text-right font-bold">Сумма</th>
                <th className="px-4 py-3 font-bold">Последняя</th>
                <th className="px-4 py-3 font-bold">Статус</th>
                <th className="px-4 py-3 text-right font-bold">Действия</th>
              </tr>
            </thead>
            <tbody>
              {clients.map((c) => (
                <tr key={c.id} className="border-b border-white/5 transition hover:bg-white/[0.03]">
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2 font-semibold">
                      {c.name}
                      {c.isPhoneVerified && <BadgeCheck className="h-3.5 w-3.5 text-sky-400" />}
                    </div>
                    <div className="text-[11px] text-zinc-600">с {fmtDate(c.createdAt).split(",")[0]}</div>
                  </td>
                  <td className="px-4 py-3 font-mono text-xs text-zinc-400">{c.phone}</td>
                  <td className="px-4 py-3 text-center">
                    <span className="inline-flex items-center gap-1 font-bold text-amber-300">
                      <Star className="h-3.5 w-3.5 fill-amber-300" />
                      {c.rating.toFixed(1)}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-center font-bold">{c.trips}</td>
                  <td className="px-4 py-3 text-center text-zinc-400">{c.cancelledTrips}</td>
                  <td className="px-4 py-3 text-right font-bold text-emerald-300">{fmtPrice(c.totalSpent)}</td>
                  <td className="px-4 py-3 text-xs text-zinc-400">
                    {c.lastTripAt ? fmtDate(c.lastTripAt) : "—"}
                  </td>
                  <td className="px-4 py-3">
                    {c.isBlocked ? (
                      <span className="chip bg-red-400/10 text-red-300" title={c.blockReason ?? ""}>
                        <Ban className="h-3 w-3" /> Блок
                      </span>
                    ) : (
                      <span className="chip bg-emerald-400/10 text-emerald-300">Активен</span>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex justify-end gap-1.5">
                      <button
                        disabled={busyId === c.id}
                        onClick={() => act(c, c.isPhoneVerified ? "unverify" : "verify")}
                        className="btn-ghost !rounded-lg !px-2.5 !py-1.5 !text-[11px]"
                        title={c.isPhoneVerified ? "Снять верификацию телефона" : "Верифицировать телефон"}
                      >
                        {busyId === c.id ? (
                          <Loader2 className="h-3.5 w-3.5 animate-spin" />
                        ) : (
                          <BadgeCheck className={`h-3.5 w-3.5 ${c.isPhoneVerified ? "text-sky-400" : "text-zinc-500"}`} />
                        )}
                      </button>
                      {c.isBlocked ? (
                        <button
                          disabled={busyId === c.id}
                          onClick={() => act(c, "unblock")}
                          className="btn-ghost !rounded-lg !px-2.5 !py-1.5 !text-[11px] !text-emerald-300"
                        >
                          <LockOpen className="h-3.5 w-3.5" /> Разблок.
                        </button>
                      ) : (
                        <button
                          disabled={busyId === c.id}
                          onClick={() => {
                            setBlockFor(c);
                            setReason("");
                          }}
                          className="btn-ghost !rounded-lg !px-2.5 !py-1.5 !text-[11px] !text-red-300"
                        >
                          <Lock className="h-3.5 w-3.5" /> Блок
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* модал блокировки */}
      {blockFor && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm">
          <div className="card w-full max-w-md p-6">
            <div className="flex items-center justify-between">
              <h3 className="text-lg font-black">Блокировка клиента</h3>
              <button onClick={() => setBlockFor(null)} className="btn-ghost !rounded-lg !p-2">
                <X className="h-4 w-4" />
              </button>
            </div>
            <p className="mt-1 text-sm text-zinc-400">
              {blockFor.name} · <span className="font-mono text-xs">{blockFor.phone}</span>
            </p>
            <label className="mt-4 block text-xs font-bold uppercase tracking-wider text-zinc-500">
              Причина блокировки
            </label>
            <textarea
              className="input-dark mt-1.5 min-h-20 resize-none"
              placeholder="Например: спам-заказы, оскорбление водителя…"
              value={reason}
              maxLength={200}
              onChange={(e) => setReason(e.target.value)}
            />
            <div className="mt-4 flex gap-2">
              <button
                disabled={busyId === blockFor.id}
                onClick={async () => {
                  await act(blockFor, "block", reason);
                  setBlockFor(null);
                }}
                className="btn-taxi flex-1 !bg-red-400 !py-2.5 !text-xs"
              >
                {busyId === blockFor.id ? <Loader2 className="h-4 w-4 animate-spin" /> : "Заблокировать"}
              </button>
              <button onClick={() => setBlockFor(null)} className="btn-ghost !py-2.5 !text-xs">
                Отмена
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
