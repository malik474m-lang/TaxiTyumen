using System.Globalization;
using System.Net.Http.Json;
using System.Text;

namespace TaxiDriver.Services;

/// Карта маршрута внутри приложения (WebView).
///
/// ОНЛАЙН: тайлы Яндекс Карт + маршрутизация по дорогам.
/// ОФЛАЙН: если интернета нет, автоматически включается собственный
/// рендерер: он рисует сохранённую геометрию дороги, точки подачи,
/// промежуточные адреса, финиш и живую позицию водителя. Тайлы для этого
/// не нужны — данные маршрута уже загружены вместе с заказом.
public static class MapHtml
{
    private static string? _apiKey;

    public static async Task<string> GetApiKeyAsync()
    {
        if (_apiKey != null) return _apiKey;
        try
        {
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(8) };
            var cfg = await http.GetFromJsonAsync<MapConfig>("https://taxi.event72.ru/api/map-config.php");
            _apiKey = cfg?.ApiKey ?? "";
        }
        catch
        {
            _apiKey = "";
        }
        return _apiKey;
    }

    private class MapConfig
    {
        public string? ApiKey { get; set; }
    }

    private static string N(double v) => v.ToString("F6", CultureInfo.InvariantCulture);

    /// JS-массив [[lat,lng], ...] из геометрии маршрута.
    private static string Geometry(IEnumerable<IReadOnlyList<double>>? geometry)
    {
        if (geometry == null) return "[]";
        var sb = new StringBuilder("[");
        var first = true;
        foreach (var p in geometry)
        {
            if (p is not { Count: >= 2 }) continue;
            if (!first) sb.Append(',');
            sb.Append('[').Append(N(p[0])).Append(',').Append(N(p[1])).Append(']');
            first = false;
        }
        return sb.Append(']').ToString();
    }

    /// JS-массив промежуточных точек с подписями.
    private static string Stops(IEnumerable<(double Lat, double Lng, string Label)>? stops)
    {
        if (stops == null) return "[]";
        var sb = new StringBuilder("[");
        var first = true;
        foreach (var s in stops)
        {
            if (s.Lat == 0 || s.Lng == 0) continue;
            if (!first) sb.Append(',');
            var label = (s.Label ?? "").Replace("\\", "\\\\").Replace("'", "\\'");
            sb.Append("{lat:").Append(N(s.Lat)).Append(",lng:").Append(N(s.Lng))
              .Append(",label:'").Append(label).Append("'}");
            first = false;
        }
        return sb.Append(']').ToString();
    }

    public static string Build(
        string apiKey,
        double driverLat, double driverLng,
        double? toLat, double? toLng, string toLabel,
        double? finishLat = null, double? finishLng = null,
        IEnumerable<IReadOnlyList<double>>? routeGeometry = null,
        IEnumerable<(double Lat, double Lng, string Label)>? stops = null)
    {
        var key = string.IsNullOrWhiteSpace(apiKey) ? "" : "&apikey=" + apiKey;

        var routePoints = $"[{N(driverLat)}, {N(driverLng)}]";
        if (toLat.HasValue && toLng.HasValue)
            routePoints += $", [{N(toLat.Value)}, {N(toLng.Value)}]";
        if (finishLat.HasValue && finishLng.HasValue)
            routePoints += $", [{N(finishLat.Value)}, {N(finishLng.Value)}]";

        var target = toLat.HasValue && toLng.HasValue
            ? $"{{lat:{N(toLat.Value)},lng:{N(toLng.Value)},label:'{(toLabel ?? "").Replace("'", "\\'")}'}}"
            : "null";
        var finish = finishLat.HasValue && finishLng.HasValue
            ? $"{{lat:{N(finishLat.Value)},lng:{N(finishLng.Value)},label:'Финиш'}}"
            : "null";

        return $$"""
<!DOCTYPE html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,user-scalable=no">
<style>
html,body{margin:0;padding:0;height:100%;width:100%;background:#1E1E2E;overflow:hidden}
#map,#offline{position:absolute;inset:0}
#offline{display:none;touch-action:none}
#offcv{width:100%;height:100%;display:block}
#badge{position:absolute;left:8px;top:8px;z-index:5;display:none;
  background:rgba(220,38,38,.92);color:#fff;font:600 12px sans-serif;
  padding:6px 10px;border-radius:8px}
.zoom{position:absolute;right:10px;z-index:6;width:42px;height:42px;border:none;
  border-radius:10px;background:rgba(37,37,54,.92);color:#fff;font-size:22px}
#zin{bottom:104px}#zout{bottom:56px}
</style>
<script src="https://api-maps.yandex.ru/2.1/?lang=ru_RU{{key}}"></script>
</head><body>
<div id="map"></div>
<div id="offline">
  <canvas id="offcv"></canvas>
  <button class="zoom" id="zin"  onclick="offZoom(1.35)">+</button>
  <button class="zoom" id="zout" onclick="offZoom(0.74)">−</button>
</div>
<div id="badge">Офлайн-режим · маршрут из памяти</div>
<script>
var DRIVER = {lat: {{N(driverLat)}}, lng: {{N(driverLng)}}};
var TARGET = {{target}};
var FINISH = {{finish}};
var STOPS  = {{Stops(stops)}};
var GEOM   = {{Geometry(routeGeometry)}};

var map, me, route, routePoints, offlineMode = false;

/* ---------- ОФЛАЙН-КАРТА: рисуем маршрут без интернета ---------- */
var cv, ctx, zoom = 1, panX = 0, panY = 0, bounds = null;

function collectPoints(){
  var pts = GEOM.slice();
  if (!pts.length) {
    pts.push([DRIVER.lat, DRIVER.lng]);
    STOPS.forEach(function(s){ pts.push([s.lat, s.lng]); });
    if (TARGET) pts.push([TARGET.lat, TARGET.lng]);
    if (FINISH) pts.push([FINISH.lat, FINISH.lng]);
  } else {
    pts.push([DRIVER.lat, DRIVER.lng]);
  }
  return pts.filter(function(p){ return p && p[0] && p[1]; });
}

function computeBounds(){
  var pts = collectPoints();
  if (!pts.length) return null;
  var b = {minLat: pts[0][0], maxLat: pts[0][0], minLng: pts[0][1], maxLng: pts[0][1]};
  pts.forEach(function(p){
    b.minLat = Math.min(b.minLat, p[0]); b.maxLat = Math.max(b.maxLat, p[0]);
    b.minLng = Math.min(b.minLng, p[1]); b.maxLng = Math.max(b.maxLng, p[1]);
  });
  if (b.maxLat - b.minLat < 0.004){ b.minLat -= 0.002; b.maxLat += 0.002; }
  if (b.maxLng - b.minLng < 0.004){ b.minLng -= 0.002; b.maxLng += 0.002; }
  return b;
}

function project(lat, lng){
  var w = cv.width, h = cv.height, pad = 40 * (window.devicePixelRatio || 1);
  var x = (lng - bounds.minLng) / (bounds.maxLng - bounds.minLng);
  var y = 1 - (lat - bounds.minLat) / (bounds.maxLat - bounds.minLat);
  return [pad + x * (w - pad * 2) * zoom + panX, pad + y * (h - pad * 2) * zoom + panY];
}

function marker(p, color, text){
  ctx.beginPath(); ctx.arc(p[0], p[1], 9, 0, Math.PI * 2);
  ctx.fillStyle = color; ctx.fill();
  ctx.lineWidth = 3; ctx.strokeStyle = '#111'; ctx.stroke();
  if (text){
    ctx.font = '600 13px sans-serif'; ctx.fillStyle = '#fff';
    ctx.fillText(text, p[0] + 13, p[1] + 5);
  }
}

function drawOffline(){
  if (!cv || !bounds) return;
  var dpr = window.devicePixelRatio || 1;
  cv.width = cv.clientWidth * dpr; cv.height = cv.clientHeight * dpr;
  ctx = cv.getContext('2d');
  ctx.fillStyle = '#141420'; ctx.fillRect(0, 0, cv.width, cv.height);

  /* сетка-ориентир */
  ctx.strokeStyle = 'rgba(255,255,255,.05)'; ctx.lineWidth = 1;
  for (var i = 1; i < 6; i++){
    var gx = cv.width / 6 * i, gy = cv.height / 6 * i;
    ctx.beginPath(); ctx.moveTo(gx, 0); ctx.lineTo(gx, cv.height); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(0, gy); ctx.lineTo(cv.width, gy); ctx.stroke();
  }

  /* линия маршрута по дорогам */
  if (GEOM.length > 1){
    ctx.beginPath();
    GEOM.forEach(function(p, i){
      var q = project(p[0], p[1]);
      if (i === 0) ctx.moveTo(q[0], q[1]); else ctx.lineTo(q[0], q[1]);
    });
    ctx.strokeStyle = '#FFD700'; ctx.lineWidth = 6;
    ctx.lineJoin = 'round'; ctx.lineCap = 'round'; ctx.stroke();
  } else if (TARGET){
    var a = project(DRIVER.lat, DRIVER.lng), b = project(TARGET.lat, TARGET.lng);
    ctx.beginPath(); ctx.moveTo(a[0], a[1]); ctx.lineTo(b[0], b[1]);
    ctx.strokeStyle = '#FFD700'; ctx.lineWidth = 5; ctx.setLineDash([12, 8]);
    ctx.stroke(); ctx.setLineDash([]);
  }

  STOPS.forEach(function(s, i){ marker(project(s.lat, s.lng), '#38BDF8', String(i + 1)); });
  if (TARGET) marker(project(TARGET.lat, TARGET.lng), '#4CAF50', TARGET.label || 'Цель');
  if (FINISH) marker(project(FINISH.lat, FINISH.lng), '#4CAF50', 'Финиш');
  marker(project(DRIVER.lat, DRIVER.lng), '#FFD700', 'Вы');
}

function offZoom(k){ zoom = Math.max(0.5, Math.min(8, zoom * k)); drawOffline(); }

function enableOffline(){
  offlineMode = true;
  document.getElementById('map').style.display = 'none';
  document.getElementById('offline').style.display = 'block';
  document.getElementById('badge').style.display = 'block';
  cv = document.getElementById('offcv');
  bounds = computeBounds();
  if (!bounds) return;

  var drag = false, lx = 0, ly = 0;
  cv.addEventListener('touchstart', function(e){
    drag = true; lx = e.touches[0].clientX; ly = e.touches[0].clientY;
  });
  cv.addEventListener('touchmove', function(e){
    if (!drag) return;
    var dpr = window.devicePixelRatio || 1;
    panX += (e.touches[0].clientX - lx) * dpr;
    panY += (e.touches[0].clientY - ly) * dpr;
    lx = e.touches[0].clientX; ly = e.touches[0].clientY;
    drawOffline(); e.preventDefault();
  }, {passive: false});
  cv.addEventListener('touchend', function(){ drag = false; });
  window.addEventListener('resize', drawOffline);
  drawOffline();
}

/* ---------- ОНЛАЙН-КАРТА ---------- */
function start(){
  try{
    map = new ymaps.Map('map', {
      center: [DRIVER.lat, DRIVER.lng],
      zoom: 15, controls: ['zoomControl','geolocationControl']
    }, { suppressMapOpenBlock: true });

    me = new ymaps.Placemark([DRIVER.lat, DRIVER.lng],
      { iconCaption: 'Вы' }, { preset: 'islands#yellowAutoCircleIcon' });
    map.geoObjects.add(me);

    routePoints = [{{routePoints}}];
    if (routePoints.length > 1) {
      route = new ymaps.multiRouter.MultiRoute({
        referencePoints: routePoints, params: { routingMode: 'auto' }
      }, {
        boundsAutoApply: true,
        routeActiveStrokeColor: '#FFD700', routeActiveStrokeWidth: 6,
        wayPointStartIconColor: '#FFD700', wayPointFinishIconColor: '#4CAF50'
      });
      map.geoObjects.add(route);
    }
    window.mapReady = true;
  }catch(e){ enableOffline(); }
}

/* Единый интерфейс для приложения: работает в обоих режимах */
window.updateDriver = function(lat, lng, follow){
  try{
    DRIVER = {lat: Number(lat), lng: Number(lng)};
    if (offlineMode){ drawOffline(); return 'offline-ok'; }
    if (!window.mapReady || !me) return 'no-map';
    var point = [DRIVER.lat, DRIVER.lng];
    me.geometry.setCoordinates(point);
    if (route && routePoints && routePoints.length > 1) {
      routePoints[0] = point;
      route.model.setReferencePoints(routePoints);
    }
    if (follow) map.panTo(point, {flying: false, duration: 200});
    return 'ok';
  }catch(e){ return 'err:' + e.message; }
};
window.mapCenter = function(lat, lng){
  try{
    if (offlineMode){ panX = 0; panY = 0; drawOffline(); return; }
    if (map) map.setCenter([Number(lat), Number(lng)], 15, {duration: 200});
  }catch(e){}
};

/* Нет интернета или API не загрузился — сразу офлайн-режим */
if (window.ymaps && navigator.onLine !== false) {
  var failsafe = setTimeout(function(){ if (!window.mapReady) enableOffline(); }, 6000);
  ymaps.ready(function(){ clearTimeout(failsafe); start(); });
} else {
  enableOffline();
}
window.addEventListener('offline', function(){ if (!offlineMode) enableOffline(); });
</script></body></html>
""";
    }
}
