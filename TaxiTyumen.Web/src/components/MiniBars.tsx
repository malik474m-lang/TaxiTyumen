"use client";

// Лёгкий бар-чарт без зависимостей (для Stats-графиков админки)
export default function MiniBars({
  data,
  formatValue = (v: number) => String(v),
  accent = "#facc15",
}: {
  data: { label: string; value: number; title?: string }[];
  formatValue?: (v: number) => string;
  accent?: string;
}) {
  const max = Math.max(1, ...data.map((d) => d.value));

  return (
    <div className="flex h-36 items-end gap-1.5">
      {data.map((d, i) => (
        <div key={i} className="group relative flex h-full min-w-0 flex-1 flex-col justify-end">
          <div className="pointer-events-none absolute -top-9 left-1/2 z-10 -translate-x-1/2 whitespace-nowrap rounded-lg border border-white/10 bg-zinc-950 px-2 py-1 text-[10px] font-bold opacity-0 shadow-xl transition group-hover:opacity-100">
            {d.title ?? formatValue(d.value)}
          </div>
          <div
            className="w-full rounded-t-md transition-all duration-300"
            style={{
              height: `${Math.max(2, (d.value / max) * 100)}%`,
              background: d.value > 0 ? `linear-gradient(180deg, ${accent}, ${accent}55)` : "rgba(255,255,255,0.06)",
            }}
          />
          <div className="mt-1.5 truncate text-center text-[9px] font-semibold text-zinc-600">
            {d.label}
          </div>
        </div>
      ))}
    </div>
  );
}
