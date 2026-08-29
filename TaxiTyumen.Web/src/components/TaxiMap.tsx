"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { MapPinned, KeyRound, Loader2 } from "lucide-react";
import { CITY } from "@/lib/city";

export interface MapMarker {
  id: string;
  lat: number;
  lng: number;
  kind: "driver" | "pickup" | "dest";
  label?: string;
}

interface MapConfig {
  provider: "yandex";
  apiKey: string;
  configured: boolean;
  lang: string;
  center: [number, number];
  city: string;
}

interface YMapCollection {
  add(object: unknown): YMapCollection;
  removeAll(): void;
  getBounds(): number[][] | null;
}

interface YMapInstance {
  geoObjects: YMapCollection;
  setCenter(center: [number, number], zoom?: number, options?: object): void;
  setBounds(bounds: number[][], options?: object): void;
  destroy(): void;
  container: { fitToViewport(): void };
}

interface YMapsNamespace {
  ready(callback: () => void): void;
  Map: new (
    container: HTMLElement,
    state: { center: [number, number]; zoom: number; controls?: string[]; type?: string },
    options?: object
  ) => YMapInstance;
  Placemark: new (geometry: [number, number], properties?: object, options?: object) => unknown;
  Polyline: new (geometry: [number, number][], properties?: object, options?: object) => unknown;
}

declare global {
  interface Window {
    ymaps?: YMapsNamespace;
    __taxiYandexMapsPromise?: Promise<YMapsNamespace>;
    __taxiYandexMapsKey?: string;
  }
}

function loadYandexMaps(apiKey: string, lang: string): Promise<YMapsNamespace> {
  if (window.ymaps) {
    return new Promise((resolve) => window.ymaps!.ready(() => resolve(window.ymaps!)));
  }
  if (window.__taxiYandexMapsPromise) return window.__taxiYandexMapsPromise;

  window.__taxiYandexMapsKey = apiKey;
  window.__taxiYandexMapsPromise = new Promise((resolve, reject) => {
    const script = document.createElement("script");
    script.src = `https://api-maps.yandex.ru/2.1/?apikey=${encodeURIComponent(apiKey)}&lang=${encodeURIComponent(lang)}&coordorder=latlong`;
    script.async = true;
    script.onload = () => {
      if (!window.ymaps) {
        reject(new Error("Яндекс Карты не инициализировались"));
        return;
      }
      window.ymaps.ready(() => resolve(window.ymaps!));
    };
    script.onerror = () => reject(new Error("Не удалось загрузить Яндекс Карты"));
    document.head.appendChild(script);
  });
  return window.__taxiYandexMapsPromise;
}

const PRESET: Record<MapMarker["kind"], string> = {
  driver: "islands#yellowAutoIcon",
  pickup: "islands#greenCircleDotIcon",
  dest: "islands#redIcon",
};

export default function TaxiMap({
  center,
  zoom = 12,
  markers = [],
  polyline = null,
  className = "h-72 w-full",
  followBounds = true,
}: {
  center?: [number, number];
  zoom?: number;
  markers?: MapMarker[];
  polyline?: [number, number][] | null;
  className?: string;
  followBounds?: boolean;
}) {
  const containerRef = useRef<HTMLDivElement>(null);
  const mapRef = useRef<YMapInstance | null>(null);
  const ymapsRef = useRef<YMapsNamespace | null>(null);
  const [config, setConfig] = useState<MapConfig | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);

  const mapCenter = center ?? config?.center ?? [CITY.centerLat, CITY.centerLng];
  const markersKey = useMemo(
    () => JSON.stringify(markers.map((m) => [m.id, m.lat, m.lng, m.kind, m.label])),
    [markers]
  );
  const lineKey = useMemo(() => JSON.stringify(polyline), [polyline]);

  // Конфигурация приходит с сервера: ключ не хардкодится в приложении.
  useEffect(() => {
    let cancelled = false;
    fetch("/api/map-config")
      .then(async (res) => {
        if (!res.ok) throw new Error("Конфигурация карт недоступна");
        return (await res.json()) as MapConfig;
      })
      .then((value) => {
        if (!cancelled) setConfig(value);
      })
      .catch((e: unknown) => {
        if (!cancelled) {
          setError(e instanceof Error ? e.message : "Ошибка конфигурации карт");
          setLoading(false);
        }
      });
    return () => {
      cancelled = true;
    };
  }, []);

  // Загрузка Яндекс JS API и создание карты.
  useEffect(() => {
    if (!config || !containerRef.current) return;
    if (!config.configured || !config.apiKey) {
      setError("API-ключ Яндекс Карт не настроен");
      setLoading(false);
      return;
    }

    let cancelled = false;
    loadYandexMaps(config.apiKey, config.lang)
      .then((ymaps) => {
        if (cancelled || !containerRef.current) return;
        ymapsRef.current = ymaps;
        if (!mapRef.current) {
          mapRef.current = new ymaps.Map(
            containerRef.current,
            {
              center: mapCenter as [number, number],
              zoom,
              type: "yandex#map",
              controls: ["zoomControl", "geolocationControl", "typeSelector"],
            },
            {
              suppressMapOpenBlock: true,
              yandexMapDisablePoiInteractivity: true,
            }
          );
          setTimeout(() => mapRef.current?.container.fitToViewport(), 200);
        }
        setError("");
        setLoading(false);
      })
      .catch((e: unknown) => {
        if (!cancelled) {
          setError(e instanceof Error ? e.message : "Яндекс Карты недоступны");
          setLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [config, mapCenter, zoom]);

  // Маркеры и линия обновляются без пересоздания карты.
  useEffect(() => {
    const map = mapRef.current;
    const ymaps = ymapsRef.current;
    if (!map || !ymaps) return;

    map.geoObjects.removeAll();

    for (const marker of markers) {
      const label = marker.label ?? (marker.kind === "driver" ? "Водитель" : marker.kind === "pickup" ? "Подача" : "Финиш");
      const placemark = new ymaps.Placemark(
        [marker.lat, marker.lng],
        {
          hintContent: label,
          balloonContent: `<strong>${label.replace(/[<>&"']/g, "")}</strong>`,
          iconCaption: marker.kind === "driver" ? marker.label : undefined,
        },
        {
          preset: PRESET[marker.kind],
          hideIconOnBalloonOpen: false,
        }
      );
      map.geoObjects.add(placemark);
    }

    if (polyline && polyline.length > 1) {
      map.geoObjects.add(
        new ymaps.Polyline(
          polyline,
          { hintContent: "Маршрут" },
          {
            strokeColor: "#facc15",
            strokeWidth: 5,
            strokeOpacity: 0.9,
          }
        )
      );
    }

    if (followBounds) {
      const bounds = map.geoObjects.getBounds();
      if (bounds) {
        map.setBounds(bounds, { checkZoomRange: true, zoomMargin: 42 });
      } else {
        map.setCenter(mapCenter as [number, number], zoom, { duration: 250 });
      }
    }
  }, [markersKey, lineKey, followBounds, mapCenter, zoom, markers, polyline]);

  useEffect(() => {
    return () => {
      mapRef.current?.destroy();
      mapRef.current = null;
      ymapsRef.current = null;
    };
  }, []);

  if (error) {
    return (
      <div className={`${className} flex items-center justify-center bg-[#111116] p-6 text-center`}>
        <div>
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-red-400/10 text-red-300">
            <KeyRound className="h-5 w-5" />
          </div>
          <div className="mt-3 text-sm font-bold text-zinc-300">{error}</div>
          <div className="mt-1 max-w-xs text-xs leading-relaxed text-zinc-500">
            Добавьте <code>YANDEX_MAPS_API_KEY</code> на сервере и ограничьте ключ доменом.
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className={`${className} relative overflow-hidden bg-[#111116]`}>
      <div ref={containerRef} className="h-full w-full" />
      {loading && (
        <div className="absolute inset-0 flex items-center justify-center bg-[#111116]">
          <div className="text-center text-zinc-500">
            <Loader2 className="mx-auto h-6 w-6 animate-spin text-amber-400" />
            <div className="mt-2 flex items-center gap-1.5 text-xs font-semibold">
              <MapPinned className="h-3.5 w-3.5" /> Яндекс Карты
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
