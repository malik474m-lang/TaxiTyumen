"use client";

import { useEffect, useRef, useState } from "react";
import { MessageSquare, X, SendHorizonal } from "lucide-react";
import { api, fmtTime, type ChatMsg, type SessionUser } from "@/lib/client";

export default function OrderChat({
  orderId,
  user,
  withName,
}: {
  orderId: string;
  user: SessionUser;
  withName: string;
}) {
  const [open, setOpen] = useState(false);
  const [messages, setMessages] = useState<ChatMsg[]>([]);
  const [text, setText] = useState("");
  const [sending, setSending] = useState(false);
  const bottomRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    let alive = true;
    const load = () =>
      api<ChatMsg[]>(`/api/chat?orderId=${orderId}`)
        .then((m) => alive && setMessages(m))
        .catch(() => {});
    load();
    const t = setInterval(load, 3000);
    return () => {
      alive = false;
      clearInterval(t);
    };
  }, [open, orderId]);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages.length]);

  async function send(e: React.FormEvent) {
    e.preventDefault();
    if (!text.trim() || sending) return;
    setSending(true);
    try {
      await api("/api/chat", {
        method: "POST",
        body: JSON.stringify({ orderId, senderId: user.id, text: text.trim() }),
      });
      setText("");
      const m = await api<ChatMsg[]>(`/api/chat?orderId=${orderId}`);
      setMessages(m);
    } catch {
      /* ignore */
    } finally {
      setSending(false);
    }
  }

  if (!open) {
    return (
      <button onClick={() => setOpen(true)} className="btn-ghost w-full">
        <MessageSquare className="h-4 w-4" />
        Чат · {withName}
      </button>
    );
  }

  return (
    <div className="card overflow-hidden">
      <div className="flex items-center justify-between border-b border-white/10 px-4 py-3">
        <div className="flex items-center gap-2 text-sm font-bold">
          <MessageSquare className="h-4 w-4 text-amber-400" />
          Чат · {withName}
        </div>
        <button onClick={() => setOpen(false)} className="text-zinc-500 hover:text-zinc-300">
          <X className="h-4 w-4" />
        </button>
      </div>
      <div className="max-h-64 space-y-2 overflow-y-auto p-3">
        {messages.length === 0 && (
          <div className="py-6 text-center text-xs text-zinc-500">
            Сообщений пока нет — напишите первым
          </div>
        )}
        {messages.map((m) => {
          const mine = m.senderId === user.id;
          return (
            <div key={m.id} className={`flex ${mine ? "justify-end" : "justify-start"}`}>
              <div
                className={`max-w-[80%] rounded-2xl px-3.5 py-2 text-sm ${
                  mine
                    ? "rounded-br-md bg-amber-400 font-medium text-zinc-950"
                    : "rounded-bl-md bg-white/8 text-zinc-200"
                }`}
              >
                <div className="break-words">{m.text}</div>
                <div className={`mt-0.5 text-right text-[10px] ${mine ? "text-zinc-800/70" : "text-zinc-500"}`}>
                  {fmtTime(m.createdAt)}
                </div>
              </div>
            </div>
          );
        })}
        <div ref={bottomRef} />
      </div>
      <form onSubmit={send} className="flex gap-2 border-t border-white/10 p-3">
        <input
          className="input-dark !py-2.5"
          placeholder="Сообщение…"
          value={text}
          onChange={(e) => setText(e.target.value)}
        />
        <button type="submit" disabled={sending} className="btn-taxi !px-4 !py-2.5">
          <SendHorizonal className="h-4 w-4" />
        </button>
      </form>
    </div>
  );
}
