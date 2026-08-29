"use client";

import { Baby, PawPrint, ClipboardList, Luggage, CigaretteOff, Check } from "lucide-react";
import { ORDER_OPTIONS } from "@/lib/options";

const ICONS: Record<string, typeof Baby> = {
  child_seat: Baby,
  pet: PawPrint,
  meeting_sign: ClipboardList,
  extra_luggage: Luggage,
  non_smoking: CigaretteOff,
};

export default function OptionPicker({
  value,
  onChange,
}: {
  value: string[];
  onChange: (codes: string[]) => void;
}) {
  function toggle(code: string) {
    onChange(value.includes(code) ? value.filter((c) => c !== code) : [...value, code]);
  }

  return (
    <div>
      <div className="mb-2 text-[11px] font-bold uppercase tracking-wider text-zinc-500">
        Опции поездки
      </div>
      <div className="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
        {ORDER_OPTIONS.map((o) => {
          const active = value.includes(o.code);
          const Icon = ICONS[o.code] ?? ClipboardList;
          return (
            <button
              key={o.code}
              type="button"
              onClick={() => toggle(o.code)}
              className={`flex items-center gap-2.5 rounded-xl border px-3 py-2.5 text-left text-sm font-semibold transition ${
                active
                  ? "border-amber-400/60 bg-amber-400/10 text-amber-200"
                  : "border-white/10 bg-white/[0.02] text-zinc-400 hover:border-white/25"
              }`}
            >
              <Icon className={`h-4 w-4 shrink-0 ${active ? "text-amber-300" : "text-zinc-600"}`} />
              <span className="min-w-0 flex-1 truncate text-xs">{o.name}</span>
              <span className={`text-xs font-black ${active ? "text-amber-300" : "text-zinc-600"}`}>
                {o.price > 0 ? `+${o.price} ₽` : "0 ₽"}
              </span>
              {active && <Check className="h-3.5 w-3.5 shrink-0 text-amber-400" />}
            </button>
          );
        })}
      </div>
    </div>
  );
}
