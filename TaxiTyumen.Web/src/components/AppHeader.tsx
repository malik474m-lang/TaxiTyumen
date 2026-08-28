"use client";

import { CarTaxiFront, LogOut, Star } from "lucide-react";
import { useRouter } from "next/navigation";
import { setSession, type SessionUser } from "@/lib/client";

export default function AppHeader({ user, subtitle }: { user: SessionUser; subtitle: string }) {
  const router = useRouter();
  return (
    <header className="sticky top-0 z-40 border-b border-white/10 bg-[#0a0a0c]/85 backdrop-blur-xl">
      <div className="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6">
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-400 text-zinc-950 shadow-lg shadow-amber-400/25">
            <CarTaxiFront className="h-6 w-6" strokeWidth={2.4} />
          </div>
          <div>
            <div className="text-sm font-black tracking-tight">ТАКСИ ТЮМЕНЬ</div>
            <div className="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-400/80">
              {subtitle}
            </div>
          </div>
        </div>
        <div className="flex items-center gap-3">
          <div className="hidden text-right sm:block">
            <div className="text-sm font-bold">{user.name}</div>
            <div className="flex items-center justify-end gap-1 text-[11px] text-zinc-500">
              <Star className="h-3 w-3 fill-amber-400 text-amber-400" />
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
