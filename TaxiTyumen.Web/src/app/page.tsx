"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { CarTaxiFront, ArrowUpRight, UserRound, Car, Headset, Gauge } from "lucide-react";
import { getSession } from "@/lib/client";
import { ROLE_PATHS, ROLE_THEMES } from "@/components/LoginScreen";

const APPS: { role: keyof typeof ROLE_PATHS; icon: typeof UserRound; demo: string }[] = [
  { role: "client", icon: UserRound, demo: "+79221112233" },
  { role: "driver", icon: Car, demo: "+79221000001" },
  { role: "operator", icon: Headset, demo: "+79001234568" },
  { role: "admin", icon: Gauge, demo: "+79001234567" },
];

export default function PortalPage() {
  const router = useRouter();
  const [ready, setReady] = useState(false);

  // Уже вошли — сразу в своё приложение
  useEffect(() => {
    const s = getSession();
    if (s) {
      router.replace(ROLE_PATHS[s.role]);
    } else {
      setReady(true);
    }
  }, [router]);

  if (!ready) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <CarTaxiFront className="h-10 w-10 animate-pulse text-amber-400" />
      </div>
    );
  }

  return (
    <div className="relative min-h-screen overflow-hidden">
      <div className="pointer-events-none absolute -right-24 top-1/2 hidden -translate-y-1/2 rotate-90 select-none text-[180px] font-black tracking-tighter text-white/[0.02] lg:block">
        ТЮМЕНЬ
      </div>

      <div className="relative z-10 mx-auto flex min-h-screen max-w-6xl flex-col px-6 py-10">
        {/* Шапка */}
        <div className="animate-rise flex items-center gap-3">
          <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-400 text-zinc-950 shadow-lg shadow-amber-400/30">
            <CarTaxiFront className="h-7 w-7" strokeWidth={2.4} />
          </div>
          <div>
            <div className="text-lg font-black tracking-tight">ТАКСИ ТЮМЕНЬ</div>
            <div className="text-xs font-semibold uppercase tracking-[0.25em] text-amber-400/80">
              портал системы · 72 регион
            </div>
          </div>
        </div>

        {/* Заголовок */}
        <div className="mt-14 animate-rise" style={{ animationDelay: "0.05s" }}>
          <h1 className="max-w-3xl text-4xl font-black leading-[1.02] tracking-tighter sm:text-6xl">
            Один город.
            <br />
            Четыре <span className="text-amber-400">приложения.</span>
          </h1>
          <p className="mt-5 max-w-xl text-base leading-relaxed text-zinc-400">
            Экосистема заказа такси: у каждой роли — своё приложение и свой вход.
            Интерфейсы не пересекаются: чужой аккаунт система отклонит.
          </p>
        </div>

        {/* Карточки приложений */}
        <div className="mt-12 grid gap-4 sm:grid-cols-2">
          {APPS.map((app, i) => {
            const theme = ROLE_THEMES[app.role];
            return (
              <a
                key={app.role}
                href={ROLE_PATHS[app.role]}
                className="group card animate-rise relative overflow-hidden p-6 transition-all hover:-translate-y-1 hover:border-white/20"
                style={{ animationDelay: `${0.1 + i * 0.06}s` }}
              >
                <div className="flex items-start justify-between">
                  <div className={`flex h-13 w-13 shrink-0 items-center justify-center rounded-2xl p-3 shadow-lg ${theme.tileClass}`}>
                    <app.icon className="h-7 w-7" strokeWidth={2.2} />
                  </div>
                  <ArrowUpRight className="h-5 w-5 text-zinc-600 transition-all group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:text-amber-400" />
                </div>

                <div className={`mt-5 text-[10px] font-black uppercase tracking-[0.3em] ${theme.accentText}`}>
                  {theme.appCode}
                </div>
                <h2 className="mt-1.5 text-2xl font-black tracking-tight">{theme.appName}</h2>
                <p className="mt-2 text-sm leading-relaxed text-zinc-400">{theme.description}</p>

                <div className="mt-5 flex items-center justify-between border-t border-white/8 pt-4">
                  <span className="text-xs text-zinc-600">
                    демо: <code className="text-zinc-400">{app.demo}</code>
                  </span>
                  <span className={`text-xs font-bold ${theme.accentText}`}>Открыть →</span>
                </div>
              </a>
            );
          })}
        </div>

        {/* Подвал */}
        <div className="mt-auto animate-rise pt-14" style={{ animationDelay: "0.4s" }}>
          <div className="checker h-2 w-40 rounded-full opacity-70" />
          <p className="mt-4 text-xs leading-relaxed text-zinc-600">
            TaxiTyumen — веб-порт полного цикла: клиент · водитель · диспетчерская · администрирование.
            <br />
            Тарифы Тюмени: Эконом от 99 ₽ · Комфорт от 179 ₽ · Бизнес от 349 ₽ · Минивэн от 249 ₽
          </p>
        </div>
      </div>
    </div>
  );
}
