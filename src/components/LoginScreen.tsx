"use client";

import { useEffect, useState } from "react";
import {
  Phone,
  Lock,
  User,
  KeyRound,
  ArrowRight,
  ArrowLeft,
  UserRound,
  Car,
  Headset,
  Gauge,
  AlertTriangle,
  Timer,
  Sparkles,
  Radio,
  BarChart3,
  Wallet,
  MapPinned,
  MessageSquare,
  ListOrdered,
} from "lucide-react";
import { api, getSession, setSession, type SessionUser } from "@/lib/client";
import type { BrandingData } from "@/lib/branding";
import { LOGO_ICONS } from "@/components/AppHeader";

export const ROLE_PATHS: Record<SessionUser["role"], string> = {
  client: "/client",
  driver: "/driver",
  operator: "/operator",
  admin: "/admin",
};

interface RoleTheme {
  appCode: string;
  appName: string;
  icon: typeof UserRound;
  tileClass: string;
  accentText: string;
  description: string;
  features: { icon: typeof Timer; text: string }[];
  demo: { phone: string; password: string; note: string };
  allowRegister: boolean;
  registerRole?: "client" | "driver";
}

export const ROLE_THEMES: Record<SessionUser["role"], RoleTheme> = {
  client: {
    appCode: "TaxiClient · Web",
    appName: "Приложение клиента",
    icon: UserRound,
    tileClass: "bg-amber-400 text-zinc-950 shadow-amber-400/30",
    accentText: "text-amber-400",
    description: "Заказ такси по Тюмени с живой оценкой цены, отслеживанием водителя и чатом.",
    features: [
      { icon: Timer, text: "Заказ за пару минут" },
      { icon: MapPinned, text: "Реальные тарифы и маршрут" },
      { icon: MessageSquare, text: "Чат с водителем" },
    ],
    demo: { phone: "+79221112233", password: "Client123!", note: "Демо-клиент" },
    allowRegister: true,
    registerRole: "client",
  },
  driver: {
    appCode: "TaxiDriver · Web",
    appName: "Приложение водителя",
    icon: Car,
    tileClass: "bg-emerald-400 text-emerald-950 shadow-emerald-400/25",
    accentText: "text-emerald-400",
    description: "Выход на линию, лента заказов, управление поездкой, баланс и комиссии.",
    features: [
      { icon: ListOrdered, text: "Лента доступных заказов" },
      { icon: Car, text: "Этапы поездки одной кнопкой" },
      { icon: Wallet, text: "Баланс, комиссии, заработок" },
    ],
    demo: { phone: "+79221000001", password: "Driver123!", note: "Алексей, Kia Rio" },
    allowRegister: true,
    registerRole: "driver",
  },
  operator: {
    appCode: "TaxiOperator · Web",
    appName: "Диспетчерская",
    icon: Headset,
    tileClass: "bg-sky-400 text-sky-950 shadow-sky-400/25",
    accentText: "text-sky-400",
    description: "Приём звонков, создание заказов по телефону и распределение по водителям.",
    features: [
      { icon: Radio, text: "Табло активных заказов" },
      { icon: Headset, text: "Приём заказа со звонка" },
      { icon: UserRound, text: "Назначение водителей вручную" },
    ],
    demo: { phone: "+79001234568", password: "Operator123!", note: "Мария, диспетчер" },
    allowRegister: false,
  },
  admin: {
    appCode: "TaxiAdmin · Web",
    appName: "Панель администратора",
    icon: Gauge,
    tileClass: "bg-violet-400 text-violet-950 shadow-violet-400/25",
    accentText: "text-violet-400",
    description: "Выручка и статистика сервиса, тарифы города, верификация и балансы водителей.",
    features: [
      { icon: BarChart3, text: "Выручка и аналитика" },
      { icon: Sparkles, text: "Редактор тарифов" },
      { icon: Wallet, text: "Пополнение балансов" },
    ],
    demo: { phone: "+79001234567", password: "Admin123!", note: "Админ системы" },
    allowRegister: false,
  },
};

export default function LoginScreen({
  role,
  onAuthed,
  branding,
}: {
  role: SessionUser["role"];
  onAuthed: (user: SessionUser) => void;
  branding?: BrandingData | null;
}) {
  const staticTheme = ROLE_THEMES[role];
  // Серверный брендинг переопределяет статичную тему приложения
  const brandColor = branding?.primaryColor;
  const brandInk = branding?.primaryTextColor ?? "#0a0a0c";
  const appName = branding?.appName ?? staticTheme.appName;
  const appCode = branding?.appCode ?? staticTheme.appCode;
  const heroTitle = branding?.heroTitle || appName;
  const heroSubtitle = branding?.heroSubtitle || staticTheme.description;
  const FeatureIcons = [Timer, MapPinned, MessageSquare];
  const features = branding?.features?.length
    ? branding.features.map((text, i) => ({
        icon: FeatureIcons[i % FeatureIcons.length],
        text,
      }))
    : staticTheme.features;
  const Icon = LOGO_ICONS[branding?.logoIcon ?? ""] ?? staticTheme.icon;
  const theme = {
    ...staticTheme,
    appName,
    appCode,
    description: heroSubtitle,
    features,
  };

  const [view, setView] = useState<"checking" | "conflict" | "login" | "register">("checking");
  const [existing, setExisting] = useState<SessionUser | null>(null);
  // SMS-вход (SendSmsCodeAsync / VerifySmsCodeAsync из оригинала)
  const [authMethod, setAuthMethod] = useState<"password" | "sms">("password");
  const [smsSent, setSmsSent] = useState(false);
  const [devCode, setDevCode] = useState<string | null>(null);
  const [code, setCode] = useState("");
  const [phone, setPhone] = useState("");
  const [password, setPassword] = useState("");
  const [firstName, setFirstName] = useState("");
  const [lastName, setLastName] = useState("");
  const [car, setCar] = useState({ carBrand: "", carModel: "", carColor: "Белый", licensePlate: "", carYear: 2022 });
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    const s = getSession();
    // Сессия без серверного токена — недействительна, входим заново
    if (s && !s.token) {
      setSession(null);
      setExisting(null);
      setView("login");
      return;
    }
    setExisting(s);
    setView(s && s.role !== role ? "conflict" : "login");
  }, [role]);

  function fillDemo() {
    setPhone(theme.demo.phone);
    setPassword(theme.demo.password);
  }

  async function handleLogin(e: React.FormEvent) {
    e.preventDefault();
    setError("");
    setLoading(true);
    try {
      const res = await api<{ user: SessionUser }>("/api/auth/login", {
        method: "POST",
        body: JSON.stringify({ phone, password }),
      });
      // Жёсткое разделение ролей: чужой аккаунт сюда не пускаем
      if (res.user.role !== role) {
        const their = ROLE_THEMES[res.user.role];
        setError(
          `Это аккаунт роли «${their.appName}». Войдите через приложение «${their.appName}» — роли в системе не пересекаются.`
        );
        return;
      }
      setSession(res.user);
      onAuthed(res.user);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Ошибка входа");
    } finally {
      setLoading(false);
    }
  }

  async function sendSmsCode() {
    if (!phone || phone.replace(/\D/g, "").length < 10) {
      setError("Укажите корректный телефон");
      return;
    }
    setError("");
    setLoading(true);
    try {
      const res = await api<{ devCode?: string; smsProvider?: string | null }>("/api/auth/sms", {
        method: "POST",
        body: JSON.stringify({ action: "send", phone }),
      });
      setSmsSent(true);
      setDevCode(res.devCode ?? null);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Не удалось отправить код");
    } finally {
      setLoading(false);
    }
  }

  async function verifySmsCode(e: React.FormEvent) {
    e.preventDefault();
    setError("");
    setLoading(true);
    try {
      const res = await api<{ user: SessionUser }>("/api/auth/sms", {
        method: "POST",
        body: JSON.stringify({ action: "verify", phone, code }),
      });
      // Роли не пересекаются — проверка и для SMS-входа
      if (res.user.role !== role) {
        const their = ROLE_THEMES[res.user.role];
        setError(
          `Это аккаунт роли «${their.appName}». Войдите через приложение «${their.appName}» — роли в системе не пересекаются.`
        );
        return;
      }
      setSession(res.user);
      onAuthed(res.user);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Неверный код");
    } finally {
      setLoading(false);
    }
  }

  async function handleRegister(e: React.FormEvent) {
    e.preventDefault();
    setError("");
    setLoading(true);
    try {
      const registerRole = theme.registerRole ?? "client";
      const res = await api<{ user: SessionUser }>("/api/auth/register", {
        method: "POST",
        body: JSON.stringify({
          phone,
          password,
          firstName,
          lastName,
          role: registerRole,
          ...(registerRole === "driver" ? car : {}),
        }),
      });
      setSession(res.user);
      onAuthed(res.user);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Ошибка регистрации");
    } finally {
      setLoading(false);
    }
  }

  return (
    <div
      className="relative min-h-screen overflow-hidden"
      style={
        brandColor
          ? ({ "--brand": brandColor, "--brand-ink": brandInk } as React.CSSProperties)
          : undefined
      }
    >
      {/* Брендовое свечение */}
      {brandColor && (
        <div
          className="pointer-events-none absolute inset-0"
          style={{
            background: `radial-gradient(900px 500px at 85% -10%, ${brandColor}14, transparent 60%)`,
          }}
        />
      )}
      <div className="pointer-events-none absolute -left-24 top-1/2 hidden -translate-y-1/2 -rotate-90 select-none text-[160px] font-black tracking-tighter text-white/[0.02] lg:block">
        {theme.appCode.split(" ")[0].toUpperCase()}
      </div>

      <div className="relative z-10 mx-auto grid min-h-screen max-w-6xl grid-cols-1 items-center gap-10 px-6 py-10 lg:grid-cols-[1fr_1.1fr]">
        {/* Бренд приложения */}
        <div className="animate-rise">
          <a href="/" className="mb-8 inline-flex items-center gap-2 text-xs font-bold uppercase tracking-[0.2em] text-zinc-500 transition hover:text-amber-400">
            <ArrowLeft className="h-3.5 w-3.5" />
            Портал TaxiTyumen
          </a>

          <div
            className={`flex h-14 w-14 items-center justify-center overflow-hidden rounded-2xl shadow-lg ${brandColor ? "" : theme.tileClass}`}
            style={
              brandColor
                ? { background: brandColor, color: brandInk, boxShadow: `0 10px 30px ${brandColor}55` }
                : undefined
            }
          >
            {branding?.logoUrl ? (
              <img src={branding.logoUrl} alt={appName} className="h-full w-full bg-white object-contain p-1" />
            ) : (
              <Icon className="h-8 w-8" strokeWidth={2.2} />
            )}
          </div>
          <div
            className={`mt-5 text-xs font-black uppercase tracking-[0.3em] ${brandColor ? "" : theme.accentText}`}
            style={brandColor ? { color: brandColor } : undefined}
          >
            {theme.appCode}
          </div>
          <h1 className="mt-2 text-4xl font-black tracking-tighter sm:text-5xl">{heroTitle}</h1>
          <p className="mt-4 max-w-md text-sm leading-relaxed text-zinc-400">{theme.description}</p>

          <div className="mt-8 max-w-sm space-y-2.5">
            {theme.features.map((f) => (
              <div key={f.text} className="flex items-center gap-3 rounded-xl border border-white/8 bg-white/[0.03] px-4 py-3">
                <f.icon
                  className={`h-4 w-4 shrink-0 ${brandColor ? "" : theme.accentText}`}
                  style={brandColor ? { color: brandColor } : undefined}
                />
                <span className="text-sm font-semibold text-zinc-300">{f.text}</span>
              </div>
            ))}
          </div>

          <p className="mt-8 flex items-center gap-2 text-xs text-zinc-600">
            <span className="checker inline-block h-2 w-16 rounded-full opacity-70" />
            каждое приложение — только для своей роли
          </p>
        </div>

        {/* Форма */}
        <div className="card animate-rise p-6 sm:p-8" style={{ animationDelay: "0.08s" }}>
          {view === "checking" && (
            <div className="py-20 text-center text-sm text-zinc-500">Проверяем сессию…</div>
          )}

          {/* Конфликт ролей: вошли, но не в своё приложение */}
          {view === "conflict" && existing && (
            <div className="py-4 text-center">
              <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl border border-amber-400/30 bg-amber-400/10">
                <AlertTriangle className="h-7 w-7 text-amber-400" />
              </div>
              <h2 className="mt-5 text-xl font-black tracking-tight">Это не ваше приложение</h2>
              <p className="mx-auto mt-3 max-w-sm text-sm leading-relaxed text-zinc-400">
                Вы вошли как <b className="text-zinc-100">{existing.name}</b> — роль
                «<b className={ROLE_THEMES[existing.role].accentText}>{ROLE_THEMES[existing.role].appName}</b>».
                Приложение «{theme.appName}» доступно только своей роли.
              </p>
              <div className="mt-7 grid gap-2.5">
                <a href={ROLE_PATHS[existing.role]} className="btn-taxi w-full">
                  Перейти в «{ROLE_THEMES[existing.role].appName}»
                  <ArrowRight className="h-4 w-4" />
                </a>
                <button
                  onClick={() => {
                    setSession(null);
                    setExisting(null);
                    setView("login");
                  }}
                  className="btn-ghost w-full"
                >
                  Выйти из аккаунта и войти сюда
                </button>
              </div>
            </div>
          )}

          {/* Вход */}
          {view === "login" && (
            <>
              <h2 className="text-xl font-black tracking-tight">Вход в «{theme.appName}»</h2>

              {theme.allowRegister && (
                <div className="mt-4 grid grid-cols-2 gap-2 rounded-2xl bg-zinc-950/60 p-1.5">
                  {(
                    [
                      ["password", "По паролю"],
                      ["sms", "По SMS-коду"],
                    ] as const
                  ).map(([m, label]) => (
                    <button
                      type="button"
                      key={m}
                      onClick={() => {
                        setAuthMethod(m);
                        setError("");
                      }}
                      className={`rounded-xl py-2.5 text-sm font-bold transition-all ${
                        authMethod === m
                          ? "bg-amber-400 text-zinc-950 shadow"
                          : "text-zinc-400 hover:text-zinc-200"
                      }`}
                    >
                      {label}
                    </button>
                  ))}
                </div>
              )}

              {authMethod === "password" ? (
              <form onSubmit={handleLogin} className="mt-5 space-y-4">
                <div className="relative">
                  <Phone className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
                  <input className="input-dark pl-11" placeholder="+7 (___) ___-__-__" value={phone} onChange={(e) => setPhone(e.target.value)} required />
                </div>
                <div className="relative">
                  <Lock className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
                  <input className="input-dark pl-11" type="password" placeholder="Пароль" value={password} onChange={(e) => setPassword(e.target.value)} required />
                </div>

                {error && (
                  <div className="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm font-medium leading-relaxed text-red-300">
                    {error}
                  </div>
                )}

                <button type="submit" disabled={loading} className="btn-taxi w-full">
                  {loading ? "Проверяем…" : "Войти"}
                  <ArrowRight className="h-4 w-4" />
                </button>
              </form>
              ) : (
              <form onSubmit={verifySmsCode} className="mt-5 space-y-4">
                <div className="relative">
                  <Phone className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
                  <input
                    className="input-dark pl-11"
                    placeholder="+7 (___) ___-__-__"
                    value={phone}
                    onChange={(e) => {
                      setPhone(e.target.value);
                      setSmsSent(false);
                      setDevCode(null);
                    }}
                    required
                  />
                </div>

                {!smsSent ? (
                  <button type="button" onClick={sendSmsCode} disabled={loading} className="btn-taxi w-full">
                    {loading ? "Отправляем…" : "Получить код по SMS"}
                    <KeyRound className="h-4 w-4" />
                  </button>
                ) : (
                  <>
                    <div className="relative">
                      <KeyRound className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
                      <input
                        className="input-dark pl-11 text-center !text-lg !font-black tracking-[0.5em]"
                        placeholder="••••"
                        value={code}
                        onChange={(e) => setCode(e.target.value.replace(/\D/g, "").slice(0, 4))}
                        inputMode="numeric"
                        maxLength={4}
                        required
                      />
                    </div>
                    {devCode && (
                      <div className="rounded-xl border border-amber-400/30 bg-amber-400/10 px-4 py-3 text-sm font-semibold text-amber-300">
                        SMS-провайдер не настроен — демо-режим. Ваш код: <b className="text-base tracking-widest">{devCode}</b>
                      </div>
                    )}
                    <button type="submit" disabled={loading || code.length < 4} className="btn-taxi w-full">
                      {loading ? "Проверяем…" : "Войти по коду"}
                      <ArrowRight className="h-4 w-4" />
                    </button>
                    <button
                      type="button"
                      onClick={sendSmsCode}
                      disabled={loading}
                      className="w-full text-center text-xs font-semibold text-zinc-500 transition hover:text-amber-400"
                    >
                      Отправить код повторно
                    </button>
                  </>
                )}

                {error && (
                  <div className="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm font-medium leading-relaxed text-red-300">
                    {error}
                  </div>
                )}
              </form>
              )}

              <div className="mt-5 rounded-xl border border-white/10 bg-white/[0.03] p-3.5">
                <div className="mb-2 text-[10px] font-bold uppercase tracking-[0.2em] text-zinc-600">
                  Демо-доступ · {theme.demo.note}
                </div>
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <code className="text-xs text-zinc-400">
                    {theme.demo.phone} · {theme.demo.password}
                  </code>
                  <button onClick={fillDemo} className="btn-ghost !rounded-lg !px-3 !py-1.5 !text-xs">
                    Подставить
                  </button>
                </div>
              </div>

              {theme.allowRegister && (
                <button onClick={() => { setError(""); setView("register"); }} className="mt-4 w-full text-center text-sm font-semibold text-zinc-400 transition hover:text-amber-400">
                  Нет аккаунта? Зарегистрироваться
                </button>
              )}

              {!theme.allowRegister && (
                <p className="mt-4 text-center text-xs text-zinc-600">
                  Учётные записи персонала выдаёт администратор системы
                </p>
              )}
            </>
          )}

          {/* Регистрация (только клиент и водитель) */}
          {view === "register" && theme.allowRegister && (
            <>
              <div className="flex items-center justify-between">
                <h2 className="text-xl font-black tracking-tight">Регистрация</h2>
                <button onClick={() => { setError(""); setView("login"); }} className="text-xs font-semibold text-zinc-500 hover:text-zinc-300">
                  ← ко входу
                </button>
              </div>
              <form onSubmit={handleRegister} className="mt-5 space-y-4">
                <div className="grid grid-cols-2 gap-3">
                  <div className="relative">
                    <User className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
                    <input className="input-dark pl-11" placeholder="Имя" value={firstName} onChange={(e) => setFirstName(e.target.value)} required />
                  </div>
                  <input className="input-dark" placeholder="Фамилия" value={lastName} onChange={(e) => setLastName(e.target.value)} />
                </div>

                {theme.registerRole === "driver" && (
                  <div className="grid grid-cols-2 gap-3 rounded-2xl border border-white/10 bg-zinc-950/40 p-4">
                    <div className="col-span-2 text-[10px] font-bold uppercase tracking-[0.2em] text-zinc-500">
                      Данные автомобиля
                    </div>
                    <input className="input-dark" placeholder="Марка" value={car.carBrand} onChange={(e) => setCar({ ...car, carBrand: e.target.value })} required />
                    <input className="input-dark" placeholder="Модель" value={car.carModel} onChange={(e) => setCar({ ...car, carModel: e.target.value })} required />
                    <select className="input-dark" value={car.carColor} onChange={(e) => setCar({ ...car, carColor: e.target.value })}>
                      {["Белый", "Чёрный", "Серебристый", "Серый", "Синий", "Красный", "Жёлтый"].map((c) => (
                        <option key={c}>{c}</option>
                      ))}
                    </select>
                    <input className="input-dark" placeholder="Госномер" value={car.licensePlate} onChange={(e) => setCar({ ...car, licensePlate: e.target.value })} required />
                  </div>
                )}

                <div className="relative">
                  <Phone className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
                  <input className="input-dark pl-11" placeholder="+7 (___) ___-__-__" value={phone} onChange={(e) => setPhone(e.target.value)} required />
                </div>
                <div className="relative">
                  <Lock className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
                  <input className="input-dark pl-11" type="password" placeholder="Пароль (мин. 6 символов)" value={password} onChange={(e) => setPassword(e.target.value)} required />
                </div>

                {error && (
                  <div className="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm font-medium text-red-300">
                    {error}
                  </div>
                )}

                <button type="submit" disabled={loading} className="btn-taxi w-full">
                  {loading ? "Создаём…" : "Создать аккаунт"}
                  <ArrowRight className="h-4 w-4" />
                </button>
              </form>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
