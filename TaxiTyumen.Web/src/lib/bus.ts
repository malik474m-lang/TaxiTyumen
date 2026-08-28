// In-memory шина событий — аналог SignalR групп из TaxiHub
// (один процесс Node.js в песочнице → globalThis singleton)
type Listener = (event: string) => void;

const g = globalThis as typeof globalThis & {
  __taxiEventBus?: { listeners: Set<Listener> };
};

const bus = (g.__taxiEventBus ??= { listeners: new Set<Listener>() });

export function publishEvent(event: string) {
  for (const listener of [...bus.listeners]) {
    try {
      listener(event);
    } catch {
      /* клиент отключился */
    }
  }
}

export function subscribeEvent(listener: Listener): () => void {
  bus.listeners.add(listener);
  return () => {
    bus.listeners.delete(listener);
  };
}
