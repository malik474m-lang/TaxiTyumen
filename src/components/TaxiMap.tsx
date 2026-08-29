"use client";

import { useEffect, useRef } from "react";
import type { Map as LeafletMap, LayerGroup } from "leaflet";
import "leaflet/dist/leaflet.css";
import { CITY } from "@/lib/city";

export interface MapMarker {
  id: string;
  lat: number;
  lng: number;
  kind: "driver" | "pickup" | "dest";
  label?: string;
}

const ICON_HTML: Record<MapMarker["kind"], string> = {
  driver: `<div style="width:32px;height:32px;border-radius:12px;background:#facc15;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 4px rgba(250,204,21,.25),0 4px 14px rgba(0,0,0,.55);">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#0a0a0c" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
      <path d="M4 16.5 5.2 11a2 2 0 0 1 2-1.5h9.6a2 2 0 0 1 2 1.5L20 16.5"/><rect x="3" y="16" width="18" height="4" rx="1.4"/>
      <circle cx="7.5" cy="20" r=".6" fill="#0a0a0c"/><circle cx="16.5" cy="20" r=".6" fill="#0a0a0c"/>
    </svg></div>`,
  pickup: `<div style="width:18px;height:18px;border-radius:50%;background:#10b981;border:3px solid #06281c;box-shadow:0 0 0 3px rgba(16,185,129,.3),0 2px 8px rgba(0,0,0,.5);"></div>`,
  dest: `<div style="width:17px;height:17px;background:#facc15;border:3px solid #453a05;box-shadow:0 0 0 3px rgba(250,204,21,.3),0 2px 8px rgba(0,0,0,.5);transform:rotate(45deg);border-radius:4px;"></div>`,
};

const ICON_SIZE: Record<MapMarker["kind"], [number, number]> = {
  driver: [32, 32],
  pickup: [18, 18],
  dest: [17, 17],
};

export default function TaxiMap({
  center = [CITY.centerLat, CITY.centerLng],
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
  const mapRef = useRef<LeafletMap | null>(null);
  const layerRef = useRef<LayerGroup | null>(null);
  const readyRef = useRef(false);

  // Инициализация карты один раз
  useEffect(() => {
    let disposed = false;
    (async () => {
      const L = await import("leaflet");
      if (disposed || !containerRef.current || mapRef.current) return;
      const map = L.map(containerRef.current, {
        zoomControl: false,
        attributionControl: false,
      }).setView(center, zoom);
      L.tileLayer("https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png", {
        maxZoom: 19,
        subdomains: "abcd",
      }).addTo(map);
      L.control.zoom({ position: "bottomright" }).addTo(map);
      L.control
        .attribution({ position: "bottomleft", prefix: false })
        .addAttribution("© OpenStreetMap · © CARTO")
        .addTo(map);
      layerRef.current = L.layerGroup().addTo(map);
      mapRef.current = map;
      readyRef.current = true;
      // Тайлы подгружаются асинхронно — пересчёт размера
      setTimeout(() => map.invalidateSize(), 250);
    })();
    return () => {
      disposed = true;
      mapRef.current?.remove();
      mapRef.current = null;
      readyRef.current = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const markersKey = JSON.stringify(markers.map((m) => [m.id, m.lat, m.lng, m.kind, m.label]));
  const polylineKey = JSON.stringify(polyline);

  // Обновление маркеров и линии
  useEffect(() => {
    (async () => {
      const L = await import("leaflet");
      const group = layerRef.current;
      const map = mapRef.current;
      if (!group || !map) return;
      group.clearLayers();

      for (const m of markers) {
        const icon = L.divIcon({
          html: ICON_HTML[m.kind],
          className: "taxi-div-icon",
          iconSize: ICON_SIZE[m.kind],
          iconAnchor: [ICON_SIZE[m.kind][0] / 2, ICON_SIZE[m.kind][1] / 2],
        });
        const marker = L.marker([m.lat, m.lng], { icon }).addTo(group);
        if (m.label) {
          marker.bindTooltip(m.label, {
            permanent: true,
            direction: "bottom",
            offset: [0, 12],
            className: "taxi-tooltip",
          });
        }
      }

      if (polyline && polyline.length > 1) {
        L.polyline(polyline, {
          color: "#facc15",
          weight: 4,
          opacity: 0.85,
          dashArray: "2 10",
          lineCap: "round",
        }).addTo(group);
      }

      if (followBounds) {
        const pts: [number, number][] = [
          ...markers.map((m) => [m.lat, m.lng] as [number, number]),
          ...(polyline ?? []),
        ];
        if (pts.length > 1) {
          map.fitBounds(L.latLngBounds(pts).pad(0.3), { animate: true, duration: 0.5 });
        } else if (pts.length === 1) {
          map.setView(pts[0], 14, { animate: true });
        }
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [markersKey, polylineKey, followBounds]);

  return <div ref={containerRef} className={className} style={{ background: "#0a0a0c" }} />;
}
