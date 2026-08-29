"use client";

import { CarTaxiFront, LogOut, Star, Car, Headset, Zap, Crown, ShieldCheck, Phone } from "lucide-react";
import { useRouter } from "next/navigation";
import { setSession, type SessionUser } from "@/lib/client";
import type { BrandingData } from "@/lib/branding";

export const LOGO_ICONS: Record<string, typeof CarTaxiFront> = {
  taxi: CarTaxiFront,
  car: Car,
  headset: Headset,
  zap: Zap,
  crown: Crown,
  shield: ShieldCheck,
};

export default function AppHeader({
  user,
  subtitle,
  branding,
}: {
  user: SessionUser;
  subtitle: string;
  branding?: BrandingData | null;
}) {
  const router = useRouter();
  const LogoIcon = LOGO_ICONS[branding?.logoIcon ?? "taxi"] ?? CarTaxiFront;
  const brandColor = branding?.primaryColor ?? "#facc15";
  const brandInk = branding?.primaryTextColor ?? "#0a0a0c";
  const appName = branding?.appName ?? "ТАКСИ ТЮМЕНЬ";

  return (
    <header
      className="sticky top-0 z-40 border-b border-white/10 bg-[#0a0a0c]/85 backdrop-blur-xl"
      style={branding ? ({ "--brand": brandColor, "--brand-ink": brandInk } as React.CSSProperties) : undefined}
    >
      <div className="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6">
        <div className="flex items-center gap-3">
          <div
            className="flex h-10 w-10 items-center justify-center overflow-hidden rounded-xl shadow-lg"
            style={{ background: brandColor, color: brandInk, boxShadow: `0 8px 24px ${brandColor}40` }}
          >
            {branding?.logoUrl ? (
              <img
                src={branding.logoUrl}
                alt={appName}
                className="h-full w-full bg-white object-contain p-1"
              />
            ) : (
              <LogoIcon className="h-6 w-6" strokeWidth={2.4} />
            )}
          </div>
          <div>
            <div className="text-sm font-black tracking-tight">{appName}</div>
            <div
              className="text-[10px] font-bold uppercase tracking-[0.2em]"
              style={{ color: brandColor }}
            >
              {subtitle}
            </div>
          </div>
        </div>
        <div className="flex items-center gap-3">
          {branding?.supportPhone && (
            <a
              href={`tel:${branding.supportPhone.replace(/\D/g, "")}`}
              className="hidden items-center gap-1.5 rounded-xl border border-white/10 px-3 py-2 text-xs font-semibold text-zinc-400 transition hover:text-zinc-100 md:flex"
            >
              <Phone className="h-3.5 w-3.5" style={{ color: brandColor }} />
              {branding.supportPhone}
            </a>
          )}
          <div className="hidden text-right sm:block">
            <div className="text-sm font-bold">{user.name}</div>
            <div className="flex items-center justify-end gap-1 text-[11px] text-zinc-500">
              <Star className="h-3 w-3" style={{ color: brandColor, fill: brandColor }} />
              {user.rating?.toFixed(1) ?? "5.0"} · {user.phone}
            </div>
          </div>
          <button
            onClick={() => {
              setSession(null);
              router.push("/");
            }}
            className="btn-ghost !px-3 !py-2.5"
            title="Выйти"
          >
            <LogOut className="h-4 w-4" />
          </button>
        </div>
      </div>
    </header>
  );
}
