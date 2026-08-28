"use client";

import { useEffect, useRef } from "react";

// Подписка на SSE-поток /api/events (realtime-обновления вместо SignalR)
export function useEvents(handler: () => void) {
  const ref = useRef(handler);
  ref.current = handler;

  useEffect(() => {
    let es: EventSource | null = null;
    try {
      es = new EventSource("/api/events");
      es.onmessage = (e) => {
        if (e.data !== "connected") ref.current();
      };
    } catch {
      /* EventSource недоступен — останется polling */
    }
    return () => es?.close();
  }, []);
}
