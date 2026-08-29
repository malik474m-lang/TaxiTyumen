"use client";

// Общий чат водителей автопарка: плавающая кнопка + выдвижная панель.
// Обновления — мгновенно по SSE (событие fleet) с резервным polling 4 с;
// непрочитанные считаются относительно отметки localStorage.
import { useCallback, useEffect, useRef, useState } from "react";
import { MessageSquare, SendHorizonal, Users, X } from "lucide-react";
import { api, fmtTime, type FleetMsgDto, type SessionUser } from "@/lib/client";
import { useEvents } from "@/lib/use-events";

const SEEN_KEY = (uid: string) => `tt_fleet_seen_${uid}`;

export default function FleetChat({ user }: { user: SessionUser }) {
  const [open, setOpen] = useState(false);
  const [messages, setMessages] = useState<FleetMsgDto[]>([]);
  const [unread, setUnread] = useState(0);
  const [text, setText] = useState("");
  const [sending, setSending] = useState(false);
  const [error, setError] = useState("");
  const bottomRef = useRef<HTMLDivElement>(null);
  const stickRef = useRef(true);

  const readSeen = () => {
    try {
      return Number(localStorage.getItem(SEEN_KEY(user.id)) ?? 0);
    } catch {
      return 0;
    }
  };
  const markSeen = useCallback(() => {
    try {
      localStorage.setItem(SEEN_KEY(user.id), String(Date.now()));
    } catch {
      /* ignore */
    }
    setUnread(0);
  }, [user.id]);

  const load = useCallback(
    async (isOpen: boolean) => {
      try {
        const list = await api<FleetMsgDto[]>("/api/fleet-chat");
        setMessages(list);
        if (!isOpen) {
          const seen = readSeen();
          setUnread(list.filter((m) => m.senderId !== user.id && new Date(m.createdAt).getTime() > seen).length);
        }
      } catch {
        /* сеть/авторизация — молча */
      }
    },
    [user.id]
  );

  // Первичная загрузка + polling-резерв; realtime — через SSE ниже
  useEffect(() => {
    load(open);
    const t = setInterval(() => load(open), 4000);
    return () => clearInterval(t);
  }, [load, open]);

  useEvents(() => load(open));

  // Открытие панели — сразу помечаем прочитанным
  useEffect(() => {
    if (open) {
      markSeen();
      stickRef.current = true;
    }
  }, [open, markSeen]);

  useEffect(() => {
    if (open && stickRef.current) bottomRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages.length, open]);

  async function send(e: React.FormEvent) {
    e.preventDefault();
    const body = text.trim();
    if (!body || sending) return;
    setSending(true);
    setError("");
    try {
      await api("/api/fleet-chat", { method: "POST", body: JSON.stringify({ text: body }) });
      setText("");
      stickRef.current = true;
      await load(true);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Ошибка отправки");
    } finally {
      setSending(false);
    }
  }

  function onScroll(e: React.UIEvent<HTMLDivElement>) {
    const el = e.currentTarget;
    stickRef.current = el.scrollHeight - el.scrollTop - el.clientHeight < 60;
    if (stickRef.current) markSeen();
  }

  if (!open) {
    return (
      <button
        onClick={() => setOpen(true)}
        aria-label="Чат автопарка"
        className="fixed bottom-5 right-5 z-40 flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-400 text-zinc-950 shadow-xl shadow-amber-400/25 transition hover:scale-105 active:scale-95"
        style={{ background: "var(--brand, #fbbf24)", color: "var(--brand-ink, #09090b)" }}
      >
        <MessageSquare className="h-6 w-6" />
        {unread > 0 && (
          <span className="absolute -right-1.5 -top-1.5 flex h-6 min-w-6 items-center justify-center rounded-full bg-red-500 px-1.5 text-xs font-black text-white shadow">
            {unread > 99 ? "99+" : unread}
          </span>
        )}
      </button>
    );
  }

  return (
    <div className="fixed bottom-5 right-5 z-40 flex h-[70vh] max-h-[560px] w-[min(400px,calc(100vw-2.5rem))] flex-col overflow-hidden rounded-2xl border border-white/12 bg-zinc-900 shadow-2xl shadow-black/60 animate-rise">
      <div className="flex items-center justify-between border-b border-white/10 bg-white/[0.04] px-4 py-3">
        <div className="flex items-center gap-2 text-sm font-black">
          <Users className="h-4 w-4 text-amber-400" />
          Чат автопарка
        </div>
        <button onClick={() => setOpen(false)} aria-label="Закрыть" className="text-zinc-500 transition hover:text-zinc-200">
          <X className="h-4 w-4" />
        </button>
      </div>

      <div onScroll={onScroll} className="flex-1 space-y-2.5 overflow-y-auto p-3.5">
        {messages.length === 0 && (
          <div className="flex h-full flex-col items-center justify-center gap-2 text-center text-xs text-zinc-500">
            <MessageSquare className="h-7 w-7" />
            В чате тихо — напишите коллегам первым
          </div>
        )}
        {messages.map((m, i) => {
          const mine = m.senderId === user.id;
          const prev = messages[i - 1];
          const showAuthor = !mine && (!prev || prev.senderId !== m.senderId);
          return (
            <div key={m.id} className={`flex ${mine ? "justify-end" : "justify-start"}`}>
              <div
                className={`max-w-[82%] rounded-2xl px-3.5 py-2 text-sm ${
                  mine
                    ? "rounded-br-md font-medium"
                    : "rounded-bl-md bg-white/8 text-zinc-200"
                }`}
                style={
                  mine
                    ? { background: "var(--brand, #fbbf24)", color: "var(--brand-ink, #09090b)" }
                    : undefined
                }
              >
                {showAuthor && (
                  <div className="mb-1 text-[11px] font-bold text-amber-300/90">
                    {m.senderName}
                    <span className="ml-1.5 font-medium text-zinc-500">{m.carInfo}</span>
                  </div>
                )}
                <div className="break-words">{m.text}</div>
                <div className={`mt-0.5 text-right text-[10px] ${mine ? "opacity-60" : "text-zinc-500"}`}>
                  {fmtTime(m.createdAt)}
                </div>
              </div>
            </div>
          );
        })}
        <div ref={bottomRef} />
      </div>

      {error && <div className="px-3.5 pb-1 text-xs font-semibold text-red-300">{error}</div>}
      <form onSubmit={send} className="flex gap-2 border-t border-white/10 p-3">
        <input
          className="input-dark !py-2.5"
          placeholder="Сообщение коллегам…"
          value={text}
          maxLength={500}
          onChange={(e) => setText(e.target.value)}
        />
        <button
          type="submit"
          disabled={sending || !text.trim()}
          aria-label="Отправить"
          className="btn-taxi !px-4 !py-2.5 disabled:opacity-50"
        >
          <SendHorizonal className="h-4 w-4" />
        </button>
      </form>
    </div>
  );
}
