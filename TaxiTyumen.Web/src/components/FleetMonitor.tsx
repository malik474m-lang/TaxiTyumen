"use client";

// Монитор чата автопарка для диспетчерской: живой поток сообщений водителей
// с возможностью модерации (удаление — operator/admin/superadmin).
import { useCallback, useEffect, useRef, useState } from "react";
import { Loader2, MessagesSquare, Trash2 } from "lucide-react";
import { api, fmtTime, type FleetMsgDto } from "@/lib/client";
import { useEvents } from "@/lib/use-events";

export default function FleetMonitor() {
  const [messages, setMessages] = useState<FleetMsgDto[]>([]);
  const [busyId, setBusyId] = useState<string | null>(null);
  const bottomRef = useRef<HTMLDivElement>(null);
  const stickRef = useRef(true);

  const load = useCallback(async () => {
    try {
      setMessages(await api<FleetMsgDto[]>("/api/fleet-chat"));
    } catch {
      /* молча */
    }
  }, []);

  useEffect(() => {
    load();
    const t = setInterval(load, 4000);
    return () => clearInterval(t);
  }, [load]);

  useEvents(load);

  useEffect(() => {
    if (stickRef.current) bottomRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages.length]);

  async function remove(id: string) {
    setBusyId(id);
    try {
      await api(`/api/fleet-chat?id=${encodeURIComponent(id)}`, { method: "DELETE" });
      await load();
    } catch {
      /* молча */
    } finally {
      setBusyId(null);
    }
  }

  return (
    <div className="card animate-rise p-0" style={{ animationDelay: "0.03s" }}>
      <div className="flex items-center justify-between border-b border-white/8 px-5 py-3.5">
        <h2 className="flex items-center gap-2 text-sm font-black tracking-tight">
          <MessagesSquare className="h-4 w-4 text-amber-400" />
          Чат водителей
        </h2>
        <span className="chip bg-white/6 text-zinc-300">{messages.length}</span>
      </div>

      <div
        onScroll={(e) => {
          const el = e.currentTarget;
          stickRef.current = el.scrollHeight - el.scrollTop - el.clientHeight < 60;
        }}
        className="max-h-72 space-y-2 overflow-y-auto p-4"
      >
        {messages.length === 0 && (
          <div className="py-8 text-center text-xs text-zinc-500">Водители пока молчат</div>
        )}
        {messages.map((m) => (
          <div key={m.id} className="group flex items-start gap-2.5 rounded-xl border border-white/5 bg-white/[0.03] px-3 py-2">
            <div className="min-w-0 flex-1">
              <div className="flex items-baseline justify-between gap-2">
                <span className="truncate text-[11px] font-bold text-amber-300/90">
                  {m.senderName}
                  <span className="ml-1.5 font-medium text-zinc-500">{m.carInfo}</span>
                </span>
                <span className="shrink-0 text-[10px] text-zinc-600">{fmtTime(m.createdAt)}</span>
              </div>
              <div className="mt-0.5 break-words text-sm text-zinc-200">{m.text}</div>
            </div>
            <button
              onClick={() => remove(m.id)}
              disabled={busyId === m.id}
              aria-label="Удалить сообщение"
              title="Удалить сообщение"
              className="mt-0.5 shrink-0 text-zinc-600 opacity-0 transition hover:text-red-300 group-hover:opacity-100"
            >
              {busyId === m.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Trash2 className="h-3.5 w-3.5" />}
            </button>
          </div>
        ))}
        <div ref={bottomRef} />
      </div>
    </div>
  );
}
