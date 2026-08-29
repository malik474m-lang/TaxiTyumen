"use client";

import { useEffect, useState } from "react";
import { CheckCircle2, Loader2, UserRound, Car, Headset, Phone } from "lucide-react";
import { api } from "@/lib/client";
import { LOGO_ICONS } from "@/components/AppHeader";
import type { BrandingData, BrandApp } from "@/lib/branding";

const APP_TITLES: Record<BrandApp, string> = {
  client: "Клиентское приложение",
  driver: "Приложение водителя",
  operator: "Диспетчерская",
};

const APP_ICONS: Record<BrandApp, typeof UserRound> = {
  client: UserRound,
  driver: Car,
  operator: Headset,
};

export default function BrandingEditor() {
  const [items, setItems] = useState<BrandingData[]>([]);
  const [activeIdx, setActiveIdx] = useState(0);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState("");

  useEffect(() => {
    api<BrandingData[]>("/api/branding").then(setItems).catch(() => {});
  }, []);

  useEffect(() => {
    if (!message) return;
    const t = setTimeout(() => setMessage(""), 3000);
    return () => clearTimeout(t);
  }, [message]);

  const brand = items[activeIdx];
  if (!brand) {
    return (
      <div className="flex py-16 items-center justify-center">
        <Loader2 className="h-6 w-6 animate-spin text-amber-400" />
      </div>
    );
  }

  function patch(field: Partial<BrandingData>) {
    setItems((arr) => arr.map((b, i) => (i === activeIdx ? { ...b, ...field } : b)));
  }

  async function save() {
    setSaving(true);
    try {
      const updated = await api<BrandingData>("/api/branding", {
        method: "PUT",
        body: JSON.stringify(brand),
      });
      setItems((arr) => arr.map((b, i) => (i === activeIdx ? updated : b)));
      setMessage(`Брендинг «${updated.appName}» сохранён`);
    } catch (e) {
      setMessage(e instanceof Error ? e.message : "Ошибка сохранения");
    } finally {
      setSaving(false);
    }
  }

  const LogoIcon = LOGO_ICONS[brand.logoIcon] ?? UserRound;

  return (
    <div className="grid gap-6 xl:grid-cols-[1fr_420px]">
      <div className="space-y-4">
        {/* Переключатель приложений */}
        <div className="flex flex-wrap gap-2">
          {items.map((b, i) => {
            const AIcon = APP_ICONS[b.app];
            return (
              <button
                key={b.app}
                onClick={() => setActiveIdx(i)}
                className={`flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-bold transition ${
                  i === activeIdx
                    ? "bg-amber-400 text-zinc-950 shadow-lg shadow-amber-400/20"
                    : "border border-white/10 bg-white/[0.03] text-zinc-400 hover:border-white/20 hover:text-zinc-200"
                }`}
              >
                <AIcon className="h-4 w-4" />
                {APP_TITLES[b.app]}
              </button>
            );
          })}
          {message && (
            <span className="ml-auto flex items-center gap-1.5 rounded-xl border border-emerald-400/30 bg-emerald-400/10 px-4 py-2 text-sm font-semibold text-emerald-300">
              <CheckCircle2 className="h-4 w-4" /> {message}
            </span>
          )}
        </div>

        {/* Форма */}
        <div className="card animate-rise space-y-4 p-6">
          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <label className="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-zinc-500">
                Название приложения
              </label>
              <input
                className="input-dark"
                value={brand.appName}
                onChange={(e) => patch({ appName: e.target.value })}
              />
            </div>
            <div>
              <label className="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-zinc-500">
                Кодовое имя (в шапке)
              </label>
              <input
                className="input-dark"
                value={brand.appCode}
                onChange={(e) => patch({ appCode: e.target.value })}
              />
            </div>
          </div>

          <div>
            <label className="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-zinc-500">
              Заголовок экрана входа
            </label>
            <input
              className="input-dark"
              value={brand.heroTitle}
              onChange={(e) => patch({ heroTitle: e.target.value })}
            />
          </div>
          <div>
            <label className="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-zinc-500">
              Подзаголовок / описание
            </label>
            <textarea
              className="input-dark min-h-[70px]"
              value={brand.heroSubtitle}
              onChange={(e) => patch({ heroSubtitle: e.target.value })}
            />
          </div>

          <div className="grid gap-3 sm:grid-cols-3">
            <div>
              <label className="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-zinc-500">
                Логотип
              </label>
              <select
                className="input-dark"
                value={brand.logoIcon}
                onChange={(e) => patch({ logoIcon: e.target.value })}
              >
                {Object.keys(LOGO_ICONS).map((k) => (
                  <option key={k} value={k}>
                    {k}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-zinc-500">
                Основной цвет
              </label>
              <div className="flex gap-2">
                <input
                  type="color"
                  value={brand.primaryColor}
                  onChange={(e) => patch({ primaryColor: e.target.value })}
                  className="h-[46px] w-14 cursor-pointer rounded-xl border border-white/10 bg-zinc-950 p-1"
                />
                <input
                  className="input-dark"
                  value={brand.primaryColor}
                  onChange={(e) => patch({ primaryColor: e.target.value })}
                />
              </div>
            </div>
            <div>
              <label className="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-zinc-500">
                Текст на фоне цвета
              </label>
              <div className="flex gap-2">
                <input
                  type="color"
                  value={brand.primaryTextColor}
                  onChange={(e) => patch({ primaryTextColor: e.target.value })}
                  className="h-[46px] w-14 cursor-pointer rounded-xl border border-white/10 p-1"
                  style={{ background: "#0f0f13" }}
                />
                <input
                  className="input-dark"
                  value={brand.primaryTextColor}
                  onChange={(e) => patch({ primaryTextColor: e.target.value })}
                />
              </div>
            </div>
          </div>

          <div>
            <label className="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-zinc-500">
              Телефон поддержки (пусто — скрыть)
            </label>
            <input
              className="input-dark"
              value={brand.supportPhone ?? ""}
              onChange={(e) => patch({ supportPhone: e.target.value || null })}
              placeholder="+7 (___) ___-__-__"
            />
          </div>

          <div>
            <label className="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-zinc-500">
              Преимущества (до 3-х строк на экране входа)
            </label>
            <div className="space-y-2">
              {[0, 1, 2].map((i) => (
                <input
                  key={i}
                  className="input-dark"
                  value={brand.features[i] ?? ""}
                  placeholder={`Строка ${i + 1}`}
                  onChange={(e) => {
                    const features = [...brand.features];
                    features[i] = e.target.value;
                    patch({ features: features.filter(Boolean) });
                  }}
                />
              ))}
            </div>
          </div>

          <button onClick={save} disabled={saving} className="btn-taxi w-full">
            {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}
            Сохранить и применить на всех экранах
          </button>
        </div>
      </div>

      {/* Живое превью экрана входа */}
      <div className="h-fit">
        <div className="mb-2 text-[11px] font-bold uppercase tracking-[0.2em] text-zinc-500">
          Предпросмотр экрана входа
        </div>
        <div
          className="card overflow-hidden p-6"
          style={{ "--brand": brand.primaryColor, "--brand-ink": brand.primaryTextColor } as React.CSSProperties}
        >
          <div className="flex items-center gap-3">
            <div
              className="flex h-12 w-12 items-center justify-center rounded-2xl shadow-lg"
              style={{
                background: brand.primaryColor,
                color: brand.primaryTextColor,
                boxShadow: `0 10px 30px ${brand.primaryColor}50`,
              }}
            >
              <LogoIcon className="h-6 w-6" strokeWidth={2.3} />
            </div>
            <div>
              <div className="text-base font-black tracking-tight">{brand.appName}</div>
              <div
                className="text-[10px] font-bold uppercase tracking-[0.25em]"
                style={{ color: brand.primaryColor }}
              >
                {brand.appCode}
              </div>
            </div>
          </div>
          <h3 className="mt-5 text-2xl font-black tracking-tighter">{brand.heroTitle}</h3>
          <p className="mt-2 text-xs leading-relaxed text-zinc-400">{brand.heroSubtitle}</p>
          <div className="mt-4 space-y-1.5">
            {brand.features.slice(0, 3).map((f) => (
              <div key={f} className="flex items-center gap-2 rounded-lg border border-white/8 bg-white/[0.03] px-3 py-2">
                <span className="h-1.5 w-1.5 rounded-full" style={{ background: brand.primaryColor }} />
                <span className="text-xs font-semibold text-zinc-300">{f}</span>
              </div>
            ))}
          </div>
          <button
            className="mt-5 w-full rounded-xl py-3 text-sm font-extrabold transition hover:brightness-110"
            style={{
              background: brand.primaryColor,
              color: brand.primaryTextColor,
              boxShadow: `0 4px 24px ${brand.primaryColor}40`,
            }}
          >
            Войти
          </button>
          <div className="checker mt-5 h-2 w-28 rounded-full opacity-70" />
          {brand.supportPhone && (
            <p className="mt-3 flex items-center gap-1.5 text-[11px] text-zinc-500">
              <Phone className="h-3 w-3" style={{ color: brand.primaryColor }} />
              Поддержка: {brand.supportPhone}
            </p>
          )}
        </div>
      </div>
    </div>
  );
}
