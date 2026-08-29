"use client";

// Редактор «Бренд сервиса» (только супер-админ): имя сервиса, город/регион,
// телефон поддержки, центр карты и часовой пояс, подпись в SMS.
// GET /api/service-brand — публичный, PUT — только superadmin.
import { useEffect, useState } from "react";
import { Building, Loader2, MapPin, MessageSquareText, Phone, Save } from "lucide-react";
import { api, type ServiceBrandDto } from "@/lib/client";

export default function ServiceBrandEditor() {
  const [brand, setBrand] = useState<ServiceBrandDto | null>(null);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  useEffect(() => {
    api<ServiceBrandDto>("/api/service-brand").then(setBrand).catch((e) => setError(e.message));
  }, []);

  async function save() {
    if (!brand) return;
    setSaving(true);
    setMessage("");
    setError("");
    try {
      const updated = await api<ServiceBrandDto>("/api/service-brand", {
        method: "PUT",
        body: JSON.stringify(brand),
      });
      setBrand(updated);
      setMessage("Бренд сервиса сохранён — обновился на портале и в приложениях");
    } catch (e) {
      setError(e instanceof Error ? e.message : "Ошибка сохранения");
    } finally {
      setSaving(false);
    }
  }

  if (!brand) {
    return (
      <div className="flex items-center justify-center gap-2 py-16 text-zinc-500">
        <Loader2 className="h-5 w-5 animate-spin" /> Загрузка бренда…
      </div>
    );
  }

  const set = <K extends keyof ServiceBrandDto>(key: K, value: ServiceBrandDto[K]) =>
    setBrand({ ...brand, [key]: value });

  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_360px]">
      <div className="card p-6">
        <h3 className="flex items-center gap-2 text-lg font-black">
          <Building className="h-5 w-5 text-amber-300" /> Параметры сервиса
        </h3>
        <p className="mt-1 text-xs text-zinc-500">
          Эти значения используются порталом, экранами входа, картой и SMS-подписью.
        </p>

        <div className="mt-5 grid gap-4 sm:grid-cols-2">
          <div>
            <label className="text-xs font-bold uppercase tracking-wider text-zinc-500">Название сервиса</label>
            <input className="input-dark mt-1.5" value={brand.serviceName} maxLength={80}
              onChange={(e) => set("serviceName", e.target.value)} />
          </div>
          <div>
            <label className="text-xs font-bold uppercase tracking-wider text-zinc-500">Подпись в SMS</label>
            <input className="input-dark mt-1.5" value={brand.smsSenderName} maxLength={80}
              onChange={(e) => set("smsSenderName", e.target.value)} />
          </div>
          <div>
            <label className="text-xs font-bold uppercase tracking-wider text-zinc-500">Город</label>
            <input className="input-dark mt-1.5" value={brand.city} maxLength={80}
              onChange={(e) => set("city", e.target.value)} />
          </div>
          <div>
            <label className="text-xs font-bold uppercase tracking-wider text-zinc-500">Регион</label>
            <input className="input-dark mt-1.5" value={brand.region} maxLength={120}
              onChange={(e) => set("region", e.target.value)} />
          </div>
          <div>
            <label className="text-xs font-bold uppercase tracking-wider text-zinc-500">Код региона</label>
            <input className="input-dark mt-1.5" value={brand.regionCode} maxLength={10}
              onChange={(e) => set("regionCode", e.target.value)} />
          </div>
          <div>
            <label className="text-xs font-bold uppercase tracking-wider text-zinc-500">Телефон поддержки</label>
            <input className="input-dark mt-1.5" value={brand.supportPhone ?? ""} maxLength={30}
              placeholder="+7 (3452) 000-000"
              onChange={(e) => set("supportPhone", e.target.value || null)} />
          </div>
          <div>
            <label className="text-xs font-bold uppercase tracking-wider text-zinc-500">Центр карты · широта</label>
            <input type="number" step="0.0001" className="input-dark mt-1.5" value={brand.centerLat}
              onChange={(e) => set("centerLat", Number(e.target.value))} />
          </div>
          <div>
            <label className="text-xs font-bold uppercase tracking-wider text-zinc-500">Центр карты · долгота</label>
            <input type="number" step="0.0001" className="input-dark mt-1.5" value={brand.centerLng}
              onChange={(e) => set("centerLng", Number(e.target.value))} />
          </div>
          <div>
            <label className="text-xs font-bold uppercase tracking-wider text-zinc-500">Часовой пояс (UTC±)</label>
            <input type="number" min={-11} max={13} className="input-dark mt-1.5" value={brand.utcOffset}
              onChange={(e) => set("utcOffset", Number(e.target.value))} />
          </div>
        </div>

        <div className="mt-6 flex items-center gap-3">
          <button onClick={save} disabled={saving} className="btn-taxi !px-6 !py-2.5 !text-xs">
            {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
            Сохранить
          </button>
          {message && <span className="text-xs font-semibold text-emerald-300">{message}</span>}
          {error && <span className="text-xs font-semibold text-red-300">{error}</span>}
        </div>
      </div>

      {/* живое превью */}
      <div className="space-y-4">
        <div className="card overflow-hidden p-0">
          <div className="bg-gradient-to-br from-amber-400 to-amber-500 p-5 text-zinc-950">
            <div className="text-[11px] font-bold uppercase tracking-widest opacity-70">Портал</div>
            <div className="mt-1 text-xl font-black">{brand.serviceName || "—"}</div>
            <div className="mt-0.5 flex items-center gap-1 text-xs font-semibold opacity-80">
              <MapPin className="h-3.5 w-3.5" />
              {brand.city}, {brand.region} · {brand.regionCode}
            </div>
          </div>
          <div className="space-y-2.5 p-5 text-sm">
            <div className="flex items-center gap-2 text-zinc-300">
              <Phone className="h-4 w-4 text-amber-300" />
              {brand.supportPhone || "поддержка не указана"}
            </div>
            <div className="flex items-center gap-2 text-zinc-300">
              <MessageSquareText className="h-4 w-4 text-amber-300" />
              SMS от имени «{brand.smsSenderName}»
            </div>
            <div className="flex items-center gap-2 text-zinc-300">
              <MapPin className="h-4 w-4 text-amber-300" />
              {brand.centerLat.toFixed(4)}, {brand.centerLng.toFixed(4)} · UTC+{brand.utcOffset}
            </div>
          </div>
        </div>
        <div className="rounded-xl border border-white/8 bg-white/[0.03] p-4 text-xs leading-relaxed text-zinc-500">
          Смена названия и города мгновенно перекрашивает витрину портала и все экраны входа —
          данные подтягиваются из базы при каждом запросе (SSR).
        </div>
      </div>
    </div>
  );
}
