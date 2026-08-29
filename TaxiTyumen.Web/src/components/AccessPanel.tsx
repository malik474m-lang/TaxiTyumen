"use client";

// Панель «Доступ и роли» (только супер-админ):
//  · переключатели видимости разделов админки для роли admin (PUT /api/access)
//  · учётные записи администраторов и мониторинг целостности ролей
import { useCallback, useEffect, useState } from "react";
import {
  Eye,
  EyeOff,
  Loader2,
  Lock,
  Save,
  ShieldAlert,
  ShieldCheck,
  UserCog,
} from "lucide-react";
import { api, fmtDate, type AccessInfoDto } from "@/lib/client";

export default function AccessPanel() {
  const [info, setInfo] = useState<AccessInfoDto | null>(null);
  const [enabled, setEnabled] = useState<string[]>([]);
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    try {
      const data = await api<AccessInfoDto>("/api/access");
      setInfo(data);
      setEnabled(data.sections.filter((s) => s.visibleForAdmin).map((s) => s.key));
      setDirty(false);
      setError("");
    } catch (e) {
      setError(e instanceof Error ? e.message : "Ошибка загрузки доступа");
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  function toggle(key: string) {
    setEnabled((prev) =>
      prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]
    );
    setDirty(true);
  }

  async function save() {
    setSaving(true);
    setMessage("");
    setError("");
    try {
      await api("/api/access", {
        method: "PUT",
        body: JSON.stringify({ visibleForAdmin: enabled }),
      });
      await load();
      setMessage("Видимость разделов обновлена для всех администраторов");
    } catch (e) {
      setError(e instanceof Error ? e.message : "Ошибка сохранения");
    } finally {
      setSaving(false);
    }
  }

  if (!info) {
    return (
      <div className="flex items-center justify-center gap-2 py-16 text-zinc-500">
        <Loader2 className="h-5 w-5 animate-spin" /> Загрузка настроек доступа…
      </div>
    );
  }

  const editable = info.sections.filter((s) => !s.superadminOnly && !s.locked);
  const lockedSections = info.sections.filter((s) => s.locked && !s.superadminOnly);

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      {/* видимость разделов */}
      <div className="card p-6">
        <h3 className="flex items-center gap-2 text-lg font-black">
          <ShieldCheck className="h-5 w-5 text-amber-300" /> Разделы для администраторов
        </h3>
        <p className="mt-1 text-xs text-zinc-500">
          Выключенный раздел исчезает из админки у роли admin. Супер-администратор видит всё.
        </p>

        {info.integrity.ok === false && (
          <div className="mt-4 flex items-start gap-2 rounded-xl border border-red-400/30 bg-red-400/10 p-3 text-xs font-semibold text-red-300">
            <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" />
            {info.integrity.message ?? "Нарушена целостность ролей"}
          </div>
        )}

        <div className="mt-5 space-y-2">
          {lockedSections.map((s) => (
            <div key={s.key} className="flex items-center justify-between rounded-xl border border-white/8 bg-white/[0.03] px-4 py-3">
              <span className="text-sm font-bold text-zinc-300">{s.label}</span>
              <span className="chip bg-white/6 text-zinc-500">
                <Lock className="h-3 w-3" /> всегда
              </span>
            </div>
          ))}
          {editable.map((s) => {
            const on = enabled.includes(s.key);
            return (
              <button
                key={s.key}
                onClick={() => toggle(s.key)}
                className={`flex w-full items-center justify-between rounded-xl border px-4 py-3 text-left transition ${
                  on
                    ? "border-emerald-400/30 bg-emerald-400/[0.07]"
                    : "border-white/8 bg-white/[0.02] opacity-60 hover:opacity-90"
                }`}
              >
                <span className="text-sm font-bold text-zinc-200">{s.label}</span>
                <span className={`chip ${on ? "bg-emerald-400/10 text-emerald-300" : "bg-white/6 text-zinc-500"}`}>
                  {on ? <Eye className="h-3 w-3" /> : <EyeOff className="h-3 w-3" />}
                  {on ? "виден" : "скрыт"}
                </span>
              </button>
            );
          })}
        </div>

        <div className="mt-5 flex items-center gap-3">
          <button onClick={save} disabled={saving || !dirty} className="btn-taxi !px-6 !py-2.5 !text-xs disabled:opacity-50">
            {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
            Сохранить
          </button>
          {message && <span className="text-xs font-semibold text-emerald-300">{message}</span>}
          {error && <span className="text-xs font-semibold text-red-300">{error}</span>}
        </div>
      </div>

      {/* учётные записи администраторов */}
      <div className="card p-6">
        <h3 className="flex items-center gap-2 text-lg font-black">
          <UserCog className="h-5 w-5 text-amber-300" /> Учётные записи администрирования
        </h3>
        <p className="mt-1 text-xs text-zinc-500">
          Все пользователи с правами admin / superadmin и их последний вход.
        </p>

        <div className="mt-5 space-y-2">
          {(info.accounts ?? []).map((a) => (
            <div key={a.id} className="flex items-center justify-between gap-3 rounded-xl border border-white/8 bg-white/[0.03] px-4 py-3">
              <div className="min-w-0">
                <div className="truncate text-sm font-bold text-zinc-100">
                  {a.firstName} {a.lastName}
                  {a.username && <span className="ml-2 font-mono text-xs text-zinc-500">@{a.username}</span>}
                </div>
                <div className="mt-0.5 text-xs text-zinc-500">
                  {a.phone} · вход: {a.lastLoginAt ? fmtDate(a.lastLoginAt) : "ещё не входил"}
                </div>
              </div>
              <span
                className={`chip shrink-0 ${
                  a.role === "superadmin"
                    ? "bg-amber-400/10 text-amber-300"
                    : "bg-sky-400/10 text-sky-300"
                }`}
              >
                {a.role === "superadmin" ? "супер-админ" : "админ"}
              </span>
            </div>
          ))}
          {(info.accounts ?? []).length === 0 && (
            <div className="rounded-xl border border-white/8 bg-white/[0.02] p-4 text-center text-xs text-zinc-500">
              Учётные записи не найдены
            </div>
          )}
        </div>

        <div className="mt-5 rounded-xl border border-white/8 bg-white/[0.03] p-4 text-xs leading-relaxed text-zinc-500">
          Учётная запись супер-администратора защищена контролем целостности: при её удалении из
          базы система блокирует админку до восстановления (SUPERADMIN_RECOVERY=true).
        </div>
      </div>
    </div>
  );
}
