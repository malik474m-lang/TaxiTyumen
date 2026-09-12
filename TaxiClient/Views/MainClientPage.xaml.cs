using System.Globalization;
using TaxiClient.Models;
using TaxiClient.Services;

namespace TaxiClient.Views;

public partial class MainClientPage : ContentPage
{
    private readonly ApiService _api;
    private readonly SignalRService _signalR;
    private readonly GeocodingService? _geo;

    private string _selectedTariff = "Economy";
    private OrderResponse? _activeOrder;
    private List<PriceEstimate> _prices = new();

    private readonly List<Entry> _stopEntries = new();
    private int _stopCount = 0;

    // 0 — адрес ещё не выбран (карта центрируется по конфигурации сервиса)
    private double _pickupLat = 0;
    private double _pickupLng = 0;
    private double _destLat = 0;
    private double _destLng = 0;

    private bool _suppressPickup;
    private bool _suppressDest;
    private string _paymentMethod = "Cash";

    private CancellationTokenSource? _pickupCts;
    private CancellationTokenSource? _destCts;

    public MainClientPage(ApiService api, SignalRService signalR)
    {
        InitializeComponent();

        _api = api;
        _signalR = signalR;

        // Бренд сервиса из админки: заголовок следует за названием сервиса
        Title = BrandingService.Current.ServiceName;
        BrandingService.Updated += b =>
            MainThread.BeginInvokeOnMainThread(() => Title = b.ServiceName);

        try
        {
            _geo = new GeocodingService();
        }
        catch
        {
            _geo = null;
        }

        try
        {
            _signalR.OrderStatusChanged += OnOrderStatusChanged;
            _signalR.DriverLocationUpdated += OnDriverLocationUpdated;
            _signalR.ChatMessageReceived += OnChatMessageOnMainPage;
            _signalR.DriverArrivedNotification += OnDriverArrivedNotification;
        }
        catch { }

        SafeLoadMap();
        BuildTariffButtons();
        _ = LoadOrderOptionsAsync();
        _ = LoadPaymentConfigAsync();
        _ = ResumePendingSberPaymentAsync();

        // Поля адресов при старте пустые: раньше подставлялся демо-адрес,
        // и пассажиру приходилось сначала его стирать.
        ClearAddressFields();

        // Адрес подачи определяется сам по GPS телефона
        _ = DetectMyLocationAsync(silent: true);
    }

    // =========================
    // АВТООПРЕДЕЛЕНИЕ МЕСТОПОЛОЖЕНИЯ
    // =========================
    private bool _locating;

    private async void OnLocateClicked(object? sender, EventArgs e)
        => await DetectMyLocationAsync(silent: false);

    /// <summary>
    /// Определяет адрес подачи по GPS телефона и подставляет его в поле «Откуда».
    /// silent = true — автоматический запуск при открытии экрана: молча
    /// пропускаем отказ в разрешении и выключенный GPS, пассажир просто
    /// введёт адрес руками. silent = false — нажата кнопка «📍»,
    /// показываем понятные подсказки.
    /// </summary>
    private async Task DetectMyLocationAsync(bool silent)
    {
        if (_locating) return;

        // Не затираем адрес, который пассажир уже выбрал сам
        if (silent && !string.IsNullOrWhiteSpace(PickupEntry.Text)) return;

        _locating = true;
        try
        {
            MainThread.BeginInvokeOnMainThread(() =>
            {
                LocateBtn.IsEnabled = false;
                LocateHintLabel.Text = "Определяем ваше местоположение…";
                LocateHintLabel.TextColor = Color.FromArgb("#8FBF9F");
                LocateHintLabel.IsVisible = true;
            });

            // Разрешение запрашиваем в рантайме (Android 6+)
            var status = await Permissions.CheckStatusAsync<Permissions.LocationWhenInUse>();
            if (status != PermissionStatus.Granted)
            {
                if (silent)
                {
                    // При автозапуске системное окно показываем только один раз,
                    // чтобы не раздражать отказавшегося пассажира
                    if (Preferences.Get("location_asked", false)) { HideLocateHint(); return; }
                    Preferences.Set("location_asked", true);
                }
                status = await Permissions.RequestAsync<Permissions.LocationWhenInUse>();
            }

            if (status != PermissionStatus.Granted)
            {
                ShowLocateProblem(silent,
                    "Нет доступа к геолокации — укажите адрес вручную",
                    "Доступ к геолокации",
                    "Разрешите приложению доступ к местоположению в настройках телефона "
                    + "или введите адрес подачи вручную.");
                return;
            }

            // Сначала последняя известная точка — она приходит мгновенно,
            // затем уточняем свежим запросом к GPS
            Microsoft.Maui.Devices.Sensors.Location? location = null;
            try { location = await Geolocation.GetLastKnownLocationAsync(); } catch { }

            try
            {
                var fresh = await Geolocation.GetLocationAsync(
                    new GeolocationRequest(GeolocationAccuracy.Medium, TimeSpan.FromSeconds(12)));
                if (fresh != null) location = fresh;
            }
            catch (FeatureNotEnabledException)
            {
                if (location == null)
                {
                    ShowLocateProblem(silent,
                        "Геолокация выключена — укажите адрес вручную",
                        "Геолокация выключена",
                        "Включите определение местоположения (GPS) в настройках телефона.");
                    return;
                }
            }
            catch { /* таймаут: останется последняя известная точка */ }

            if (location == null)
            {
                ShowLocateProblem(silent,
                    "Не удалось определить местоположение",
                    "Местоположение",
                    "Не удалось получить координаты. Проверьте, включён ли GPS, "
                    + "или введите адрес подачи вручную.");
                return;
            }

            // Координаты → адрес (сервер: DaData / Яндекс / OSM)
            AddressSuggestion? address = null;
            try
            {
                if (_geo != null)
                    address = await _geo.ReverseGeocodeAsync(location.Latitude, location.Longitude);
            }
            catch { }

            var lat = location.Latitude;
            var lng = location.Longitude;
            var display = !string.IsNullOrWhiteSpace(address?.DisplayName)
                ? address!.DisplayName
                : $"{lat:F5}, {lng:F5}";

            MainThread.BeginInvokeOnMainThread(() =>
            {
                _pickupLat = lat;
                _pickupLng = lng;

                // Флаг гасит автоподсказки: подставленный адрес не должен
                // запускать поиск и сбрасывать только что полученные координаты
                _suppressPickup = true;
                PickupEntry.Text = display;
                _suppressPickup = false;

                PickupSuggestions.IsVisible = false;
                PickupSuggestions.Children.Clear();

                LocateHintLabel.Text = "Адрес подачи определён автоматически — проверьте его";
                LocateHintLabel.TextColor = Color.FromArgb("#4ADE80");
                LocateHintLabel.IsVisible = true;
            });

            var latStr = lat.ToString(CultureInfo.InvariantCulture);
            var lngStr = lng.ToString(CultureInfo.InvariantCulture);
            try { await MapWebView.EvaluateJavaScriptAsync($"setPickup({latStr},{lngStr})"); } catch { }

            SafeDrawRoute();
            await SafeLoadPricesAsync();
        }
        catch (Exception ex)
        {
            ShowLocateProblem(silent, "Не удалось определить местоположение",
                "Местоположение", ex.Message);
        }
        finally
        {
            _locating = false;
            MainThread.BeginInvokeOnMainThread(() => LocateBtn.IsEnabled = true);
        }
    }

    /// Сообщение о проблеме: тихо подсказкой при автозапуске, окном — по кнопке.
    private async void ShowLocateProblem(bool silent, string hint, string title, string message)
    {
        MainThread.BeginInvokeOnMainThread(() =>
        {
            LocateHintLabel.Text = hint;
            LocateHintLabel.TextColor = Color.FromArgb("#FCA5A5");
            LocateHintLabel.IsVisible = true;
        });
        if (!silent)
        {
            try { await DisplayAlert(title, message, "OK"); } catch { }
        }
    }

    private void HideLocateHint() => MainThread.BeginInvokeOnMainThread(() =>
        LocateHintLabel.IsVisible = false);

    /// Очистка адресов, координат и оценки цены — состояние «новый заказ».
    private void ClearAddressFields()
    {
        try
        {
            // Флаги гасят автоподсказки: очистка не должна запускать поиск
            _suppressPickup = true;
            _suppressDest = true;
            PickupEntry.Text = string.Empty;
            DestEntry.Text = string.Empty;
            EntranceEntry.Text = string.Empty;
            _suppressPickup = false;
            _suppressDest = false;

            PickupSuggestions.IsVisible = false;
            PickupSuggestions.Children.Clear();
            DestSuggestions.IsVisible = false;
            DestSuggestions.Children.Clear();

            // Координаты «не заданы» — цена считается только по новым адресам
            _pickupLat = 0;
            _pickupLng = 0;
            _destLat = 0;
            _destLng = 0;

            PriceLabel.Text = "—";
            DistLabel.Text = "Укажите адреса подачи и назначения";
            LocateHintLabel.IsVisible = false;
        }
        catch { }
    }

    // =========================
    // КАРТА
    // Провайдер берём с сервера (api/map-config.php): в админке выбраны
    // Яндекс Карты — их и показываем. Без ключа/провайдера или при сбое —
    // автоматический запасной вариант Leaflet + OpenStreetMap.
    // =========================
    private async void SafeLoadMap()
    {
        try
        {
            var cfg = await _api.GetMapConfigAsync();

            var centerLat = cfg?.CenterLat ?? 57.1522;
            var centerLng = cfg?.CenterLng ?? 65.5272;
            var html = cfg is { Configured: true, Provider: "yandex" }
                ? BuildYandexMapHtml(cfg.ApiKey ?? string.Empty, centerLat, centerLng)
                : BuildLeafletMapHtml(centerLat, centerLng);

            MapWebView.Source = new HtmlWebViewSource { Html = html };

            MapWebView.Navigating += (s, e) =>
            {
                try
                {
                    if (e.Url.StartsWith("callback://"))
                    {
                        e.Cancel = true;
                        HandleMapCallback(e.Url);
                    }
                }
                catch { }
            };
        }
        catch
        {
            MapWebView.Source = new HtmlWebViewSource
            {
                Html = "<html><body style='background:#1E1E2E;color:white;text-align:center;padding-top:100px'>" +
                       "<h2>Ошибка загрузки карты</h2><p>Проверьте подключение к интернету</p></body></html>"
            };
        }
    }

    // Ключ и центр города подставляются из конфига сервера. В JS один и тот
    // же фасад (setPickup/setDest/...) работает поверх двух движков.
    private static string Inject(string template, string apiKey, double lat, double lng)
        => template
            .Replace("__APIKEY__", apiKey)
            .Replace("__CLAT__", lat.ToString("R", CultureInfo.InvariantCulture))
            .Replace("__CLNG__", lng.ToString("R", CultureInfo.InvariantCulture));

    private static string BuildYandexMapHtml(string apiKey, double lat, double lng)
        => Inject(YandexHtml, apiKey, lat, lng);

    private static string BuildLeafletMapHtml(double lat, double lng)
        => Inject(LeafletHtml, string.Empty, lat, lng);

    // ── Общий каркас: очередь вызовов до готовности движка + fallback ──────
    private const string SharedJs = @"
var queue = [];
function api(name, args){
  if (window.__impl) window.__impl[name].apply(null, args);
  else queue.push([name, args]);
}
function setPickup(lat, lng){ api('setPickup', [lat, lng]); }
function setDest(lat, lng){ api('setDest', [lat, lng]); }
function drawRoute(a, b, c, d){ api('drawRoute', [a, b, c, d]); }
function setDriver(lat, lng){ api('setDriver', [lat, lng]); }
function clearDriver(){ api('clearDriver', []); }
function clearRoute(){ api('clearRoute', []); }
function clearPickup(){ api('clearPickup', []); }
function clearDest(){ api('clearDest', []); }
/* Показать машину и точку подачи в одном кадре: пассажир всегда видит,
   где сейчас такси относительно него */
function fitTwo(lat1, lng1, lat2, lng2){ api('fitTwo', [lat1, lng1, lat2, lng2]); }
function flushQ(){
  try{ queue.forEach(function(it){ window.__impl[it[0]].apply(null, it[1]); }); }catch(e){}
  queue = [];
}

var carSvg = '<svg viewBox=""0 0 44 44"" width=""30"" height=""30"">'
  + '<circle cx=""22"" cy=""22"" r=""14"" fill=""rgba(250,204,21,.22)"" stroke=""#1b1b1b"" stroke-width=""2""/>'
  + '<path d=""M22 5 L31 33 L22 27 L13 33 Z"" fill=""#FACC15"" stroke=""#1b1b1b"" stroke-width=""2"" stroke-linejoin=""round""/>'
  + '</svg>';

// Геометрия маршрута берётся с СЕРВЕРА такси — ровно тот же маршрутизатор,
// который считает цену (TomTom с пробками, если включён, иначе OSRM).
function fetchRoute(lat1, lng1, lat2, lng2, ok, fail){
  var points = lat1 + ',' + lng1 + ';' + lat2 + ',' + lng2;
  var url = 'https://taxi.event72.ru/api/route.php?points=' + encodeURIComponent(points);
  fetch(url)
    .then(function(r){ if (!r.ok) throw new Error('route ' + r.status); return r.json(); })
    .then(function(d){
      if (d.geometry && d.geometry.length > 1) ok(d.geometry);
      else fail();
    })
    .catch(fail);
}

// Запасной движок: Leaflet + OSM — если Яндекс не ответил (сеть, лимит ключа)
function initLeaflet(){
  var css = document.createElement('link');
  css.rel = 'stylesheet';
  css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
  document.head.appendChild(css);
  var s = document.createElement('script');
  s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
  s.onload = function(){
    // attributionControl:false — штатный контрол Leaflet рисует внизу карты
    // флаг Украины и ссылку «Leaflet»; пассажиру они не нужны.
    // Копирайт OpenStreetMap оставляем вручную — он обязателен по лицензии ODbL.
    var map = L.map('map', { attributionControl: false }).setView([__CLAT__, __CLNG__], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
    L.control.attribution({ prefix: false }).addAttribution('© OpenStreetMap').addTo(map);
    // Пока адрес не выбран — никаких ложных маркеров в центре карты
    var pickupM = null, destM = null, driverM = null, routeLine = null;
    function makePickup(lat, lng){
      pickupM = L.marker([lat, lng], { draggable: true }).addTo(map).bindPopup('Подача');
      pickupM.on('dragend', function(e){
        var p = e.target.getLatLng();
        window.location = 'callback://pickup/' + p.lat + '/' + p.lng;
      });
    }
    window.__impl = {
      setPickup: function(lat, lng){
        if (!pickupM) makePickup(lat, lng); else pickupM.setLatLng([lat, lng]);
        map.setView([lat, lng], 15);
      },
      setDest: function(lat, lng){
        if (!destM){
          destM = L.marker([lat, lng], { draggable: true }).addTo(map).bindPopup('Назначение');
          destM.on('dragend', function(e){
            var p = e.target.getLatLng();
            window.location = 'callback://dest/' + p.lat + '/' + p.lng;
          });
        } else destM.setLatLng([lat, lng]);
        try{ if (pickupM) map.fitBounds([pickupM.getLatLng(), destM.getLatLng()], { padding: [40, 40] }); }catch(e){}
      },
      clearPickup: function(){ if (pickupM){ map.removeLayer(pickupM); pickupM = null; } this.clearRoute(); },
      clearDest: function(){ if (destM){ map.removeLayer(destM); destM = null; } this.clearRoute(); },
      drawRoute: function(lat1, lng1, lat2, lng2){
        this.clearRoute();
        fetchRoute(lat1, lng1, lat2, lng2, function(coords){
          routeLine = L.polyline(coords, { color: '#FFD700', weight: 5, opacity: .9 }).addTo(map);
          try{ map.fitBounds(routeLine.getBounds(), { padding: [40, 40] }); }catch(e){}
        }, function(){
          routeLine = L.polyline([[lat1, lng1], [lat2, lng2]], { color: '#FFD700', weight: 4, dashArray: '8,8' }).addTo(map);
        });
      },
      setDriver: function(lat, lng){
        if (!driverM){
          driverM = L.marker([lat, lng], { icon: L.divIcon({ className: '', html: carSvg, iconSize: [30, 30] }) })
            .addTo(map).bindPopup('Ваше такси');
        } else driverM.setLatLng([lat, lng]);
      },
      fitTwo: function(lat1, lng1, lat2, lng2){
        try{ map.fitBounds([[lat1, lng1], [lat2, lng2]], { padding: [60, 60], maxZoom: 16 }); }catch(e){}
      },
      clearDriver: function(){ if (driverM){ map.removeLayer(driverM); driverM = null; } },
      clearRoute: function(){ if (routeLine){ map.removeLayer(routeLine); routeLine = null; } }
    };
    flushQ();
  };
  s.onerror = function(){
    document.getElementById('map').innerHTML =
      '<div style=""color:#888;font:14px sans-serif;padding:24px;text-align:center"">Карта недоступна — проверьте интернет</div>';
  };
  document.head.appendChild(s);
}
";

    // ── Движок Яндекс Карт JS API 2.1 (ключ из админки, api/map-config) ──────
    private const string YandexHtml = @"<!DOCTYPE html>
<html>
<head>
<meta name='viewport' content='width=device-width,initial-scale=1.0'>
<script src='https://api-maps.yandex.ru/2.1/?apikey=__APIKEY__&lang=ru_RU'></script>
<style>html,body,#map{margin:0;padding:0;width:100%;height:100%}</style>
</head>
<body>
<div id='map'></div>
<script>
" + SharedJs + @"
function initYandex(){
  ymaps.ready(function(){
    var map = new ymaps.Map('map', { center: [__CLAT__, __CLNG__], zoom: 13, controls: ['zoomControl'] });
    // Пока адрес не выбран — никаких ложных маркеров в центре карты
    var pickupPlacemark = null, destPlacemark = null, driverPlacemark = null, routeObject = null;
    function makePickup(lat, lng){
      pickupPlacemark = new ymaps.Placemark([lat, lng],
        { balloonContent: 'Подача' }, { preset: 'islands#orangeDotIcon', draggable: true });
      map.geoObjects.add(pickupPlacemark);
      pickupPlacemark.events.add('dragend', function(){
        var c = pickupPlacemark.geometry.getCoordinates();
        window.location = 'callback://pickup/' + c[0] + '/' + c[1];
      });
    }
    window.__impl = {
      setPickup: function(lat, lng){
        if (!pickupPlacemark) makePickup(lat, lng);
        else pickupPlacemark.geometry.setCoordinates([lat, lng]);
        map.setCenter([lat, lng], 15);
      },
      setDest: function(lat, lng){
        if (!destPlacemark){
          destPlacemark = new ymaps.Placemark([lat, lng],
            { balloonContent: 'Назначение' }, { preset: 'islands#darkGreenDotIcon', draggable: true });
          map.geoObjects.add(destPlacemark);
          destPlacemark.events.add('dragend', function(){
            var c = destPlacemark.geometry.getCoordinates();
            window.location = 'callback://dest/' + c[0] + '/' + c[1];
          });
        } else destPlacemark.geometry.setCoordinates([lat, lng]);
        try{
          if (pickupPlacemark) map.setBounds(
            [pickupPlacemark.geometry.getCoordinates(), destPlacemark.geometry.getCoordinates()],
            { checkZoomRange: true, zoomMargin: [50, 50, 50, 50] });
        }catch(e){}
      },
      clearPickup: function(){
        if (pickupPlacemark){ map.geoObjects.remove(pickupPlacemark); pickupPlacemark = null; }
        this.clearRoute();
      },
      clearDest: function(){
        if (destPlacemark){ map.geoObjects.remove(destPlacemark); destPlacemark = null; }
        this.clearRoute();
      },
      drawRoute: function(lat1, lng1, lat2, lng2){
        this.clearRoute();
        fetchRoute(lat1, lng1, lat2, lng2, function(coords){
          routeObject = new ymaps.GeoObject(
            { geometry: { type: 'LineString', coordinates: coords } },
            { strokeColor: '#FFD700', strokeWidth: 5 });
          map.geoObjects.add(routeObject);
          try{ map.setBounds(routeObject.geometry.getBounds(), { checkZoomRange: true, zoomMargin: [50, 50, 50, 50] }); }catch(e){}
        }, function(){
          routeObject = new ymaps.GeoObject(
            { geometry: { type: 'LineString', coordinates: [[lat1, lng1], [lat2, lng2]] } },
            { strokeColor: '#FFD700', strokeWidth: 4, strokeStyle: 'dash' });
          map.geoObjects.add(routeObject);
          try{ map.setBounds([[lat1, lng1], [lat2, lng2]], { checkZoomRange: true, zoomMargin: [50, 50, 50, 50] }); }catch(e){}
        });
      },
      setDriver: function(lat, lng){
        if (!driverPlacemark){
          driverPlacemark = new ymaps.Placemark([lat, lng], { hintContent: 'Ваше такси' }, {
            iconLayout: ymaps.templateLayoutFactory.createClass(
              '<div style=""margin-left:-15px;margin-top:-15px"">' + carSvg + '</div>'),
            hideIconOnBalloonOpen: false
          });
          map.geoObjects.add(driverPlacemark);
        } else driverPlacemark.geometry.setCoordinates([lat, lng]);
      },
      fitTwo: function(lat1, lng1, lat2, lng2){
        try{
          map.setBounds([[lat1, lng1], [lat2, lng2]],
            { checkZoomRange: true, zoomMargin: [60, 60, 60, 60] });
        }catch(e){}
      },
      clearDriver: function(){ if (driverPlacemark){ map.geoObjects.remove(driverPlacemark); driverPlacemark = null; } },
      clearRoute: function(){ if (routeObject){ map.geoObjects.remove(routeObject); routeObject = null; } }
    };
    flushQ();
  });
}

// Яндекс грузится до 10 секунд; нет ответа — запускаем запасной движок
if (window.ymaps) initYandex();
else {
  var tried = 0;
  var boot = setInterval(function(){
    if (window.ymaps){ clearInterval(boot); initYandex(); }
    else if (++tried > 25){ clearInterval(boot); initLeaflet(); }
  }, 400);
}
</script>
</body>
</html>";

    // ── Движок Leaflet + OSM (когда провайдер на сервере не выбран) ─────────
    private const string LeafletHtml = @"<!DOCTYPE html>
<html>
<head>
<meta name='viewport' content='width=device-width,initial-scale=1.0'>
<style>html,body,#map{margin:0;padding:0;width:100%;height:100%}</style>
</head>
<body>
<div id='map'></div>
<script>
" + SharedJs + @"
initLeaflet();
</script>
</body>
</html>";

    private async void HandleMapCallback(string url)
    {
        try
        {
            var parts = url.Replace("callback://", "").Split('/');
            if (parts.Length < 3) return;

            var type = parts[0];

            if (!double.TryParse(parts[1], CultureInfo.InvariantCulture, out var lat))
                return;

            if (!double.TryParse(parts[2], CultureInfo.InvariantCulture, out var lng))
                return;

            AddressSuggestion? address = null;
            try
            {
                if (_geo != null)
                    address = await _geo.ReverseGeocodeAsync(lat, lng);
            }
            catch { }

            MainThread.BeginInvokeOnMainThread(() =>
            {
                var display = address?.DisplayName ?? $"{lat:F4}, {lng:F4}";

                if (type == "pickup")
                {
                    _pickupLat = lat;
                    _pickupLng = lng;
                    _suppressPickup = true;
                    PickupEntry.Text = display;
                    _suppressPickup = false;
                }
                else if (type == "dest")
                {
                    _destLat = lat;
                    _destLng = lng;
                    _suppressDest = true;
                    DestEntry.Text = display;
                    _suppressDest = false;
                    SafeDrawRoute();
                }
            });

            await SafeLoadPricesAsync();
        }
        catch { }
    }

    private void SafeDrawRoute()
    {
        try
        {
            // Маршрут строится только после выбора ОБЕИХ точек.
            // Раньше при выбранном «Куда» и пустом «Откуда» линия могла
            // начинаться в координатах 0,0 либо от старого адреса.
            if (_pickupLat == 0 || _pickupLng == 0 || _destLat == 0 || _destLng == 0)
            {
                MapWebView.EvaluateJavaScriptAsync("clearRoute()");
                return;
            }

            var p1 = _pickupLat.ToString(CultureInfo.InvariantCulture);
            var p2 = _pickupLng.ToString(CultureInfo.InvariantCulture);
            var p3 = _destLat.ToString(CultureInfo.InvariantCulture);
            var p4 = _destLng.ToString(CultureInfo.InvariantCulture);

            MapWebView.EvaluateJavaScriptAsync($"drawRoute({p1},{p2},{p3},{p4})");
        }
        catch { }
    }

    // =========================
    // ПОДСКАЗКИ АДРЕСОВ
    // =========================
    private async void OnPickupTextChanged(object? sender, TextChangedEventArgs e)
    {
        if (_suppressPickup || _geo == null)
            return;

        try
        {
            // Пользователь меняет текст — старая геоточка больше не относится
            // к новому адресу. Сбрасываем координаты, маркер и старый маршрут.
            _pickupLat = 0;
            _pickupLng = 0;
            LocateHintLabel.IsVisible = false;
            await MapWebView.EvaluateJavaScriptAsync("clearPickup()");

            _pickupCts?.Cancel();
            _pickupCts = new CancellationTokenSource();
            var token = _pickupCts.Token;

            await Task.Delay(400, token);
            if (token.IsCancellationRequested) return;

            var suggestions = await _geo.SearchAsync(e.NewTextValue);
            MainThread.BeginInvokeOnMainThread(() =>
                ShowSuggestions(PickupSuggestions, suggestions, true));
        }
        catch
        {
            PickupSuggestions.IsVisible = false;
        }
    }

    private async void OnDestTextChanged(object? sender, TextChangedEventArgs e)
    {
        if (_suppressDest || _geo == null)
            return;

        try
        {
            // То же для адреса назначения: не оставляем старые координаты
            // под новым текстом — это было причиной неверных маршрутов.
            _destLat = 0;
            _destLng = 0;
            await MapWebView.EvaluateJavaScriptAsync("clearDest()");

            _destCts?.Cancel();
            _destCts = new CancellationTokenSource();
            var token = _destCts.Token;

            await Task.Delay(400, token);
            if (token.IsCancellationRequested) return;

            var suggestions = await _geo.SearchAsync(e.NewTextValue);
            MainThread.BeginInvokeOnMainThread(() =>
                ShowSuggestions(DestSuggestions, suggestions, false));
        }
        catch
        {
            DestSuggestions.IsVisible = false;
        }
    }

    private void ShowSuggestions(StackLayout panel, List<AddressSuggestion> items, bool isPickup)
    {
        try
        {
            panel.Children.Clear();

            if (items == null || items.Count == 0)
            {
                panel.IsVisible = false;
                return;
            }

            foreach (var item in items)
            {
                var label = new Label
                {
                    Text = item.DisplayName,
                    TextColor = Colors.White,
                    FontSize = 13,
                    Padding = new Thickness(10, 8),
                    BackgroundColor = Color.FromArgb("#363650")
                };

                var captured = item;
                var tap = new TapGestureRecognizer();
                tap.Tapped += async (s, e) =>
                {
                    try
                    {
                        var latStr = captured.Latitude.ToString(CultureInfo.InvariantCulture);
                        var lngStr = captured.Longitude.ToString(CultureInfo.InvariantCulture);

                        if (isPickup)
                        {
                            _pickupLat = captured.Latitude;
                            _pickupLng = captured.Longitude;
                            _suppressPickup = true;
                            PickupEntry.Text = captured.DisplayName;
                            _suppressPickup = false;
                            PickupSuggestions.IsVisible = false;

                            await MapWebView.EvaluateJavaScriptAsync($"setPickup({latStr},{lngStr})");
                            SafeDrawRoute();
                        }
                        else
                        {
                            _destLat = captured.Latitude;
                            _destLng = captured.Longitude;
                            _suppressDest = true;
                            DestEntry.Text = captured.DisplayName;
                            _suppressDest = false;
                            DestSuggestions.IsVisible = false;

                            await MapWebView.EvaluateJavaScriptAsync($"setDest({latStr},{lngStr})");
                            SafeDrawRoute();
                        }

                        await SafeLoadPricesAsync();
                    }
                    catch { }
                };

                label.GestureRecognizers.Add(tap);
                panel.Children.Add(label);
            }

            panel.IsVisible = true;
        }
        catch
        {
            panel.IsVisible = false;
        }
    }

    // =========================
    // ТАРИФЫ
    // =========================
    private void BuildTariffButtons()
    {
        try
        {
            var tariffs = new[]
            {
                ("Economy", " Эконом"),
                ("Comfort", " Комфорт"),
                ("Business", " Бизнес"),
                ("Minivan", " Минивэн")
            };

            foreach (var (id, name) in tariffs)
            {
                var button = new Button
                {
                    Text = name,
                    BackgroundColor = id == _selectedTariff
                        ? Color.FromArgb("#FFD700")
                        : Color.FromArgb("#333"),
                    TextColor = id == _selectedTariff
                        ? Color.FromArgb("#1E1E2E")
                        : Colors.White,
                    CornerRadius = 20,
                    Padding = new Thickness(14, 6),
                    FontSize = 13,
                    HeightRequest = 36
                };

                var capturedId = id;
                button.Clicked += (s, e) =>
                {
                    _selectedTariff = capturedId;
                    RefreshTariffButtons();
                    UpdatePriceDisplay();
                };

                TariffPanel.Children.Add(button);
            }
        }
        catch { }
    }

    private void RefreshTariffButtons()
    {
        try
        {
            var ids = new[] { "Economy", "Comfort", "Business", "Minivan" };
            for (int i = 0; i < TariffPanel.Children.Count; i++)
            {
                if (TariffPanel.Children[i] is Button button)
                {
                    var selected = ids[i] == _selectedTariff;
                    button.BackgroundColor = selected
                        ? Color.FromArgb("#FFD700")
                        : Color.FromArgb("#333");
                    button.TextColor = selected
                        ? Color.FromArgb("#1E1E2E")
                        : Colors.White;
                }
            }
        }
        catch { }
    }

    // =========================
    // ОСТАНОВКИ
    // =========================
    private void OnAddStopClicked(object? sender, EventArgs e)
    {
        try
        {
            if (_stopCount >= 3) return;

            _stopCount++;

            var grid = new Grid
            {
                ColumnDefinitions =
                {
                    new ColumnDefinition { Width = GridLength.Star },
                    new ColumnDefinition { Width = GridLength.Auto }
                }
            };

            var border = new Border
            {
                BackgroundColor = Color.FromArgb("#2D2D3F"),
                Stroke = Color.FromArgb("#555"),
                StrokeThickness = 1,
                Padding = new Thickness(6, 2),
                StrokeShape = new Microsoft.Maui.Controls.Shapes.RoundRectangle
                {
                    CornerRadius = 10
                }
            };

            var entry = new Entry
            {
                Placeholder = "Остановка " + _stopCount,
                PlaceholderColor = Color.FromArgb("#888"),
                TextColor = Colors.White,
                FontSize = 14
            };

            _stopEntries.Add(entry);
            border.Content = entry;
            grid.Children.Add(border);

            var removeButton = new Button
            {
                Text = "",
                BackgroundColor = Color.FromArgb("#F44336"),
                TextColor = Colors.White,
                CornerRadius = 6,
                WidthRequest = 36,
                HeightRequest = 36,
                FontSize = 14,
                Padding = 0,
                Margin = new Thickness(6, 0, 0, 0)
            };

            Grid.SetColumn(removeButton, 1);

            var capturedGrid = grid;
            var capturedEntry = entry;

            removeButton.Clicked += (s, e2) =>
            {
                try
                {
                    StopsPanel.Children.Remove(capturedGrid);
                    _stopEntries.Remove(capturedEntry);
                    _stopCount--;
                    if (_stopCount < 3)
                        AddStopBtn.IsVisible = true;
                }
                catch { }
            };

            grid.Children.Add(removeButton);
            StopsPanel.Children.Add(grid);

            if (_stopCount >= 3)
                AddStopBtn.IsVisible = false;
        }
        catch { }
    }

    // =========================
    // ГЕОКОДИРОВАНИЕ И ЦЕНА
    // =========================

    /// Включает оплату картой только когда сервер Сбера действительно
    /// настроен. До получения реквизитов банка клиент не сможет случайно
    /// завершить неоплаченную безналичную поездку.
    private async Task LoadPaymentConfigAsync()
    {
        try
        {
            var cfg = await _api.GetSberConfigAsync();
            MainThread.BeginInvokeOnMainThread(() =>
            {
                var enabled = cfg?.Enabled == true;
                PayCardBtn.IsEnabled = enabled;
                PayCardBtn.Opacity = enabled ? 1 : 0.55;
                PayCardBtn.Text = enabled
                    ? (cfg!.SbpEnabled ? " Карта / СБП" : " Карта")
                    : " Карта (недоступна)";
                if (!enabled && _paymentMethod == "Card") OnPayCash(null, EventArgs.Empty);
            });
        }
        catch
        {
            MainThread.BeginInvokeOnMainThread(() =>
            {
                PayCardBtn.IsEnabled = false;
                PayCardBtn.Opacity = 0.55;
                PayCardBtn.Text = " Карта (недоступна)";
            });
        }
    }

    private void OnPayCash(object? sender, EventArgs e)
    {
        _paymentMethod = "Cash";
        PayCashBtn.BackgroundColor = Color.FromArgb("#FFD700");
        PayCashBtn.TextColor = Color.FromArgb("#1E1E2E");
        PayCardBtn.BackgroundColor = Color.FromArgb("#333");
        PayCardBtn.TextColor = Colors.White;
    }

    private void OnPayCard(object? sender, EventArgs e)
    {
        if (!PayCardBtn.IsEnabled) return;
        _paymentMethod = "Card";
        PayCardBtn.BackgroundColor = Color.FromArgb("#FFD700");
        PayCardBtn.TextColor = Color.FromArgb("#1E1E2E");
        PayCashBtn.BackgroundColor = Color.FromArgb("#333");
        PayCashBtn.TextColor = Colors.White;
    }
        private async void OnCalcPriceClicked(object? sender, EventArgs e)
    {
        try
        {
            PriceLabel.Text = "...";
            DistLabel.Text = "Проверяем адрес...";
            await SafeLoadPricesAsync();
        }
        catch
        {
            PriceLabel.Text = "Ошибка";
            DistLabel.Text = "Ошибка расчёта";
        }
    }

    private async Task GeocodeIfNeededAsync()
    {
        if (_geo == null)
            return;

        try
        {
            // Если адрес выбран из подсказки/карты, координаты уже точные —
            // повторный поиск мог заменить их центром одноимённого населённого пункта.
            if ((_pickupLat == 0 || _pickupLng == 0)
                && !string.IsNullOrWhiteSpace(PickupEntry.Text))
            {
                var pickupResults = await _geo.SearchAsync(PickupEntry.Text);
                if (pickupResults.Count > 0)
                {
                    _pickupLat = pickupResults[0].Latitude;
                    _pickupLng = pickupResults[0].Longitude;

                    var latStr = _pickupLat.ToString(CultureInfo.InvariantCulture);
                    var lngStr = _pickupLng.ToString(CultureInfo.InvariantCulture);

                    try
                    {
                        await MapWebView.EvaluateJavaScriptAsync($"setPickup({latStr},{lngStr})");
                    }
                    catch { }
                }
            }

            if ((_destLat == 0 || _destLng == 0)
                && !string.IsNullOrWhiteSpace(DestEntry.Text))
            {
                var destResults = await _geo.SearchAsync(DestEntry.Text);
                if (destResults.Count > 0)
                {
                    _destLat = destResults[0].Latitude;
                    _destLng = destResults[0].Longitude;

                    var latStr = _destLat.ToString(CultureInfo.InvariantCulture);
                    var lngStr = _destLng.ToString(CultureInfo.InvariantCulture);

                    try
                    {
                        await MapWebView.EvaluateJavaScriptAsync($"setDest({latStr},{lngStr})");
                    }
                    catch { }

                    SafeDrawRoute();
                }
            }
        }
        catch { }
    }

    /// Опции заказа: чекбоксы строятся из серверного справочника
    /// (цены редактируются в админке «Опции заказа»). Fallback — дефолт.
    private readonly Dictionary<string, CheckBox> _optionChecks = new();

    private List<string> SelectedOptionCodes() => _optionChecks
        .Where(kv => kv.Value.IsChecked)
        .Select(kv => kv.Key)
        .ToList();

    private async Task LoadOrderOptionsAsync()
    {
        try
        {
            var items = await _api.GetOrderOptionsAsync();
            if (items.Count == 0)
            {
                items = new List<OrderOptionInfo>
                {
                    new() { Code = "child_seat",    Name = "Кресло",    Price = 50 },
                    new() { Code = "pet",           Name = "Животное",  Price = 70 },
                    new() { Code = "extra_luggage", Name = "Багаж",     Price = 30 },
                    new() { Code = "non_smoking",   Name = "Некурящий", Price = 0 },
                };
            }
            MainThread.BeginInvokeOnMainThread(() => BuildOptionChecks(items));
        }
        catch { }
    }

    private void BuildOptionChecks(List<OrderOptionInfo> items)
    {
        OptionsContainer.Children.Clear();
        _optionChecks.Clear();
        foreach (var opt in items)
        {
            var check = new CheckBox { Color = BrandingService.ParseColor(BrandingService.Current.PrimaryColor, "#FACC15") };
            check.CheckedChanged += OnOptionChanged;
            _optionChecks[opt.Code] = check;
            var label = new Label
            {
                Text = opt.Price > 0 ? $" {opt.Name} +{opt.Price:F0}₽" : $" {opt.Name}",
                TextColor = Microsoft.Maui.Graphics.Colors.White,
                FontSize = 13,
                VerticalOptions = LayoutOptions.Center
            };
            // Каждая опция — своя строка (колонка), метка тянется на всю ширину
            label.HorizontalOptions = LayoutOptions.Fill;
            OptionsContainer.Children.Add(new HorizontalStackLayout
            {
                Spacing = 6,
                Padding = new Thickness(0, 2),
                Children = { check, label }
            });
        }
    }

    private void OnOptionChanged(object? sender, CheckedChangedEventArgs e)
        => _ = SafeLoadPricesAsync();

    private async Task SafeLoadPricesAsync()
    {
        try
        {
            await GeocodeIfNeededAsync();

            if (_destLat == 0 || _destLng == 0)
            {
                // Пустые поля — это норма (начало заказа), а не ошибка:
                // подсказываем следующий шаг вместо «Ошибка геокодирования»
                var pickupEmpty = string.IsNullOrWhiteSpace(PickupEntry.Text);
                var destEmpty = string.IsNullOrWhiteSpace(DestEntry.Text);
                MainThread.BeginInvokeOnMainThread(() =>
                {
                    PriceLabel.Text = "—";
                    DistLabel.Text = pickupEmpty || destEmpty
                        ? "Укажите адреса подачи и назначения"
                        : "Не удалось определить адрес назначения — выберите из подсказок";
                });
                return;
            }

            _prices = await _api.GetAllPricesAsync(_pickupLat, _pickupLng, _destLat, _destLng,
                SelectedOptionCodes());

            MainThread.BeginInvokeOnMainThread(() =>
            {
                if (_prices == null || _prices.Count == 0)
                {
                    PriceLabel.Text = "Ошибка";
                    DistLabel.Text = "Нет данных по цене";
                }
                else
                {
                    UpdatePriceDisplay();
                }
            });
        }
        catch
        {
            MainThread.BeginInvokeOnMainThread(() =>
            {
                PriceLabel.Text = "Ошибка";
                DistLabel.Text = "Ошибка расчёта";
            });
        }
    }

    private void UpdatePriceDisplay()
    {
        try
        {
            var tariffName = _selectedTariff switch
            {
                "Economy" => "Эконом",
                "Comfort" => "Комфорт",
                "Business" => "Бизнес",
                "Minivan" => "Минивэн",
                _ => "Эконом"
            };

            var price = _prices.FirstOrDefault(x => x.TariffName == tariffName);
            if (price != null)
            {
                PriceLabel.Text = price.Price.ToString("F0") + " ";
                DistLabel.Text = price.DistanceKm.ToString("F1") + " км  " + price.DurationMinutes + " мин";
            }
            else
            {
                PriceLabel.Text = "";
                DistLabel.Text = "";
            }
        }
        catch
        {
            PriceLabel.Text = "Ошибка";
            DistLabel.Text = "";
        }
    }

    // =========================
    // СОЗДАНИЕ ЗАКАЗА
    // =========================
    private async void OnOrderClicked(object? sender, EventArgs e)
    {
        if (string.IsNullOrWhiteSpace(PickupEntry.Text))
        {
            await DisplayAlert("Адрес подачи", "Укажите, откуда вас забрать", "OK");
            return;
        }
        if (string.IsNullOrWhiteSpace(DestEntry.Text))
        {
            await DisplayAlert("Адрес назначения", "Укажите, куда вас отвезти", "OK");
            return;
        }

        OrderBtn.IsEnabled = false;
        OrderBtn.Text = " Ищем водителя...";

        try
        {
            if (_prices.Count == 0)
                await SafeLoadPricesAsync();

            if (_pickupLat == 0 || _pickupLng == 0)
            {
                await DisplayAlert(
                    "Адрес подачи",
                    "Не удалось определить адрес подачи. Выберите его из подсказок или уточните.",
                    "OK");

                OrderBtn.IsEnabled = true;
                OrderBtn.Text = "  Заказать такси";
                return;
            }

            if (_destLat == 0 || _destLng == 0)
            {
                await DisplayAlert(
                    "Ошибка геокодирования",
                    "Не удалось определить адрес назначения. Выберите адрес из подсказок или уточните его.",
                    "OK");

                OrderBtn.IsEnabled = true;
                OrderBtn.Text = "  Заказать такси";
                return;
            }

            var comment = CommentEntry.Text ?? "";

            foreach (var stop in _stopEntries)
            {
                if (!string.IsNullOrWhiteSpace(stop.Text))
                    comment += " [Остановка: " + stop.Text.Trim() + "]";
            }

            var passengers = 1;
            if (int.TryParse(PassengerEntry.Text, out int pp) && pp >= 1 && pp <= 8)
                passengers = pp;

            var order = await _api.CreateOrderAsync(new CreateOrderRequest
            {
                ClientId = _api.CurrentUser!.UserId,
                PickupAddress = PickupEntry.Text.Trim(),
                PickupLatitude = _pickupLat,
                PickupLongitude = _pickupLng,
                PickupEntrance = string.IsNullOrWhiteSpace(EntranceEntry.Text) ? null : EntranceEntry.Text.Trim(),
                DestinationAddress = DestEntry.Text?.Trim(),
                DestinationLatitude = _destLat == 0 ? null : _destLat,
                DestinationLongitude = _destLng == 0 ? null : _destLng,
                Tariff = _selectedTariff,
                Comment = comment.Trim(),
                PassengerCount = passengers,
                PaymentMethod = _paymentMethod,
                Options = SelectedOptionCodes()
            });

            if (order != null)
            {
                _activeOrder = order;

                try
                {
                    await _signalR.SubscribeToOrderAsync(order.Id.ToString());
                }
                catch { }

                ShowActiveOrder(order);
            }
        }
        catch (Exception ex)
        {
            await DisplayAlert("Ошибка", ex.Message, "OK");
            OrderBtn.IsEnabled = true;
            OrderBtn.Text = "  Заказать такси";
        }
    }

    // =========================
    // АКТИВНЫЙ ЗАКАЗ
    // =========================
    private void ShowActiveOrder(OrderResponse order)
    {
        try
        {
            OrderPanel.IsVisible = false;
            ActivePanel.IsVisible = true;

            ActiveStatusLabel.Text = " Ищем водителя...";
            ActiveOrderNum.Text = "Заказ " + order.OrderNumber;
            ActivePriceLabel.Text = order.EstimatedPrice.ToString("F0") + " ";
            ActivePickupLabel.Text = " " + order.PickupAddress
                + (string.IsNullOrWhiteSpace(order.PickupEntrance) ? "" : ", подъезд " + order.PickupEntrance);
            ActiveDestLabel.Text = " " + (order.DestinationAddress ?? "не указано");

            if (order.Driver != null)
            {
                ShowDriverInfo(order.Driver);

                // Машина на карте сразу после назначения, не дожидаясь тика GPS
                if (order.Driver.Latitude is { } dlat && order.Driver.Longitude is { } dlng
                    && dlat != 0 && dlng != 0)
                    OnDriverLocationUpdated(dlat, dlng);

                EnsureEtaTimer();
            }
            else
            {
                ActiveDriverLabel.Text = "Ищем водителя...";
                ActiveCarLabel.Text = "";
                ActivePlateLabel.Text = "";
                ActiveEtaPanel.IsVisible = false;
            }
        }
        catch { }
    }

    private void ShowDriverInfo(DriverInfo driver)
    {
        try
        {
            ActiveDriverLabel.Text = driver.FullName + "   " + driver.Rating.ToString("F1");

            // Марка, модель и цвет — без госномера в скобках: номер выводим
            // отдельной крупной строкой (по нему пассажир и ищет машину)
            var car = !string.IsNullOrWhiteSpace(driver.CarDisplay)
                ? driver.CarDisplay
                : $"{driver.CarColor} {driver.CarBrand} {driver.CarModel}";
            if (!string.IsNullOrWhiteSpace(driver.LicensePlate))
                car = car.Replace("(" + driver.LicensePlate + ")", string.Empty).Trim();
            ActiveCarLabel.Text = car;

            // Телефон водителя пассажиру не показываем — связь через чат
            ActivePlateLabel.Text = string.IsNullOrWhiteSpace(driver.LicensePlate)
                ? string.Empty
                : driver.LicensePlate.ToUpperInvariant();
        }
        catch { }
    }

    // =========================
    // SIGNALR
    // =========================
    private void OnOrderStatusChanged(string method, object? data)
    {
        MainThread.BeginInvokeOnMainThread(async () =>
        {
            try
            {
                if (_activeOrder == null)
                    return;

                var updated = await _api.GetOrderAsync(_activeOrder.Id);
                if (updated == null)
                    return;

                _activeOrder = updated;

                ActiveStatusLabel.Text = updated.Status switch
                {
                    "Searching" => " Ищем водителя...",
                    "DriverAssigned" => " Водитель найден!",
                    "DriverEnRoute" => " Водитель едет к вам",
                    "DriverArrived" => " Водитель на месте!",
                    "InProgress" => " Поездка началась",
                    "Completed" => " Поездка завершена!",
                    "Cancelled" => " Отменён",
                    "NoDriverFound" => " Водитель не найден",
                    _ => updated.StatusText
                };

                // Клиент видит, что идёт платное ожидание и сколько уже набежало.
                if (updated.WaitingActive)
                {
                    var waitMinutes = Math.Max(1, updated.WaitingSeconds / 60);
                    ActiveStatusLabel.Text += $"\nПлатное ожидание: {waitMinutes} мин · {updated.WaitingCost:F0} ₽";
                }
                else if (updated.Status == "DriverArrived" && updated.FreeWaitingLeftSeconds > 0)
                {
                    var left = updated.FreeWaitingLeftSeconds;
                    ActiveStatusLabel.Text += $"\nБесплатное ожидание: {left / 60:00}:{left % 60:00}";
                }

                if (updated.Driver != null)
                {
                    ShowDriverInfo(updated.Driver);

                    try
                    {
                        await _signalR.SubscribeToDriverAsync(updated.Driver.DriverId.ToString());
                    }
                    catch { }
                }

                if (updated.Status is "Completed" or "Cancelled" or "NoDriverFound")
                {
                    CancelBtn.IsVisible = false;

                    if (updated.Status == "Completed")
                    {
                        // Итоговый чек с разбивкой: поездка по тарифу + простой.
                        var waitLine = updated.WaitingCost > 0
                            ? $"\nОжидание: {updated.WaitingCost:F0} ₽ ({Math.Max(1, updated.WaitingSeconds / 60)} мин)"
                            : "";
                        await DisplayAlert("Поездка завершена",
                            $"По тарифу: {updated.TariffPrice:F0} ₽{waitLine}\n" +
                            $"Итого: {updated.TotalPrice:F0} ₽",
                            "OK");

                        // Карта/СБП: деньги поступают на внутренний счёт системы
                        // через защищённую платёжную страницу Сбера, а не прямым
                        // переводом на телефон водителя.
                        if (updated.Payment != null && updated.Payment.Method == "Card")
                            await PayCompletedOrderBySberAsync(updated);

                        await Task.Delay(1000);
                        await SafeShowRatingAsync(updated);
                    }

                    await Task.Delay(2000);
                    ResetToOrderScreen();
                }
            }
            catch { }
        });
    }

    // ── Где водитель и когда приедет ───────────────────────────────────────
    private double _driverLat, _driverLng;
    private DateTime _lastEtaAt = DateTime.MinValue;
    private bool _etaTimerStarted;
    private bool _arrivalNotified;

    private void OnDriverLocationUpdated(double lat, double lng)
    {
        _driverLat = lat;
        _driverLng = lng;

        MainThread.BeginInvokeOnMainThread(() =>
        {
            try
            {
                var la = lat.ToString(CultureInfo.InvariantCulture);
                var lo = lng.ToString(CultureInfo.InvariantCulture);
                MapWebView.EvaluateJavaScriptAsync($"setDriver({la},{lo})");

                // Показываем машину и точку подачи в одном кадре: иначе водитель
                // «уезжал» за край экрана и было непонятно, где он едет
                if (_activeOrder != null && _pickupLat != 0 && _pickupLng != 0)
                {
                    var pla = _pickupLat.ToString(CultureInfo.InvariantCulture);
                    var plo = _pickupLng.ToString(CultureInfo.InvariantCulture);
                    MapWebView.EvaluateJavaScriptAsync($"fitTwo({la},{lo},{pla},{plo})");
                }
            }
            catch { }
        });

        _ = UpdateEtaAsync();
    }

    /// «Водитель приедет через N минут»: считаем по дорогам от текущей позиции
    /// машины до точки подачи (после посадки — до точки назначения).
    private async Task UpdateEtaAsync(bool force = false)
    {
        try
        {
            if (_activeOrder == null || _driverLat == 0 || _driverLng == 0) return;
            if (!force && (DateTime.UtcNow - _lastEtaAt).TotalSeconds < 15) return;
            _lastEtaAt = DateTime.UtcNow;

            var status = (_activeOrder.Status ?? string.Empty)
                .Replace("_", string.Empty).ToLowerInvariant();

            // Водитель на месте — время подачи больше не нужно
            if (status == "driverarrived")
            {
                MainThread.BeginInvokeOnMainThread(() =>
                {
                    ActiveEtaPanel.IsVisible = true;
                    ActiveEtaLabel.Text = "Водитель на месте — выходите";
                    ActiveEtaHintLabel.Text = "Бесплатное ожидание уже идёт";
                });
                await NotifyArrivalOnceAsync();
                return;
            }

            var inTrip = status == "inprogress";
            var toLat = inTrip ? (_activeOrder.DestinationLatitude ?? 0) : _pickupLat;
            var toLng = inTrip ? (_activeOrder.DestinationLongitude ?? 0) : _pickupLng;
            if (toLat == 0 || toLng == 0) return;

            var minutes = await _api.GetEtaMinutesAsync(_driverLat, _driverLng, toLat, toLng);

            MainThread.BeginInvokeOnMainThread(() =>
            {
                ActiveEtaPanel.IsVisible = true;
                if (minutes == null)
                {
                    ActiveEtaLabel.Text = inTrip ? "В пути" : "Водитель едет к вам";
                    ActiveEtaHintLabel.Text = "Время в пути уточняется";
                    return;
                }

                var m = Math.Max(1, minutes.Value);
                ActiveEtaLabel.Text = inTrip
                    ? $"До места назначения ≈ {m} мин"
                    : (m <= 1 ? "Водитель подъезжает" : $"Водитель приедет через ≈ {m} мин");
                ActiveEtaHintLabel.Text = "Машина на карте отмечена жёлтой стрелкой";
            });

            // Заранее предупреждаем пассажира, чтобы он успел выйти
            if (!inTrip && minutes is <= 2) await NotifyArrivalOnceAsync(soon: true);
        }
        catch { }
    }

    /// Одноразовое уведомление о прибытии (звук/вибрация + окно).
    private async Task NotifyArrivalOnceAsync(bool soon = false)
    {
        if (_arrivalNotified) return;
        _arrivalNotified = true;
        try
        {
            try { Vibration.Vibrate(TimeSpan.FromMilliseconds(600)); } catch { }
            await DisplayAlert(
                soon ? "Такси почти на месте" : "Такси подъехало",
                soon
                    ? "Водитель будет у вас примерно через минуту — выходите."
                    : "Водитель ждёт вас на месте подачи.",
                "OK");
        }
        catch { }
    }

    /// Таймер обновления ETA: позиция водителя приходит не всегда регулярно,
    /// поэтому пересчитываем время и по расписанию.
    private void EnsureEtaTimer()
    {
        if (_etaTimerStarted) return;
        _etaTimerStarted = true;
        Dispatcher.StartTimer(TimeSpan.FromSeconds(15), () =>
        {
            if (_activeOrder == null)
            {
                _etaTimerStarted = false;
                return false;
            }
            _ = UpdateEtaAsync(force: true);
            return true;
        });
    }

    // =========================
    // ОТМЕНА / ОЦЕНКА
    // =========================

    private async void OnOpenChat(object? sender, EventArgs e)
    {
        try
        {
            if (_activeOrder == null) return;

            await Navigation.PushAsync(new ChatPage(
                _api, _signalR,
                _activeOrder.Id,
                _api.CurrentUser!.UserId,
                "Client"));
        }
        catch (Exception ex)
        {
            await DisplayAlert("Ошибка", ex.Message, "OK");
        }
    }
        private async void OnCancelClicked(object? sender, EventArgs e)
    {
        try
        {
            if (_activeOrder == null)
                return;

            if (!await DisplayAlert("Отмена", "Отменить заказ?", "Да", "Нет"))
                return;

            var userId = _api.CurrentUser?.UserId ?? Guid.Empty;
            CancelBtn.IsEnabled = false;
            try
            {
                var (ok, error) = await _api.CancelOrderAsync(
                    _activeOrder.Id, userId, "Отменён клиентом");

                if (!ok)
                {
                    // Экран НЕ сбрасываем: заказ на сервере остался активным,
                    // иначе клиент думает, что отменил, а водитель уже едет
                    await DisplayAlert("Заказ не отменён",
                        (error ?? "Сервер не подтвердил отмену.")
                        + "\n\nПопробуйте ещё раз или позвоните диспетчеру.", "OK");
                    return;
                }

                await DisplayAlert("Заказ отменён", "Заказ отменён. Водитель уведомлён.", "OK");
                ResetToOrderScreen();
            }
            catch (Exception ex)
            {
                await DisplayAlert("Заказ не отменён",
                    "Нет связи с сервером: " + ex.Message
                    + "\n\nЗаказ остался активным — попробуйте ещё раз.", "OK");
            }
            finally
            {
                CancelBtn.IsEnabled = true;
            }
        }
        catch { }
    }

    private async Task SafeShowRatingAsync(OrderResponse order)
    {
        try
        {
            var ratingText = await DisplayPromptAsync(
                " Оцените поездку",
                "Оценка от 1 до 5",
                "Отправить",
                "Пропустить",
                maxLength: 1,
                keyboard: Keyboard.Numeric);

            if (int.TryParse(ratingText, out int rating) && rating >= 1 && rating <= 5)
            {
                try
                {
                    await _api.RateOrderAsync(order.Id, rating, null);
                }
                catch { }
            }
        }
        catch { }
    }

    private void ResetToOrderScreen()
    {
        try
        {
            _activeOrder = null;
            OrderPanel.IsVisible = true;
            ActivePanel.IsVisible = false;
            OrderBtn.IsEnabled = true;
            OrderBtn.Text = "  Заказать такси";
            CancelBtn.IsVisible = true;

            // Следующий заказ начинается с чистых полей
            ClearAddressFields();

            // Следующий заказ снова начинается с адреса «где я сейчас»
            _ = DetectMyLocationAsync(silent: true);

            // Сбрасываем состояние подачи: ETA, метка машины, разовые уведомления
            ActiveEtaPanel.IsVisible = false;
            _driverLat = 0;
            _driverLng = 0;
            _arrivalNotified = false;

            try { MapWebView.EvaluateJavaScriptAsync("clearDriver()"); } catch { }
            try { MapWebView.EvaluateJavaScriptAsync("clearRoute()"); } catch { }
        }
        catch { }
    }

    // =========================
    // ИСТОРИЯ
    // =========================

    private void OnChatMessageOnMainPage(object data)
    {
        MainThread.BeginInvokeOnMainThread(() =>
        {
            try
            {
                if (_activeOrder == null) return;

                var json = System.Text.Json.JsonDocument.Parse(data.ToString()!);
                var senderId = json.RootElement.GetProperty("senderId").GetString();
                var senderRole = json.RootElement.GetProperty("senderRole").GetString();
                var text = json.RootElement.GetProperty("text").GetString();

                // Не показываем свои же сообщения
                if (senderId == _api.CurrentUser?.UserId.ToString())
                    return;

                var senderName = senderRole == "Driver" ? "Водитель" : "Клиент";

                ChatBannerText.Text = " " + text;
                ChatBannerSender.Text = senderName;
                ChatBanner.IsVisible = true;

                // Скрываем через 10 секунд
                _ = Task.Run(async () =>
                {
                    await Task.Delay(10000);
                    MainThread.BeginInvokeOnMainThread(() =>
                    {
                        ChatBanner.IsVisible = false;
                    });
                });
            }
            catch { }
        });
    }

    private async void OnChatBannerTapped(object? sender, EventArgs e)
    {
        try
        {
            ChatBanner.IsVisible = false;

            if (_activeOrder == null) return;

            await Navigation.PushAsync(new ChatPage(
                _api, _signalR,
                _activeOrder.Id,
                _api.CurrentUser!.UserId,
                "Client"));
        }
        catch { }
    }
    
    private void OnDriverArrivedNotification(object data)
    {
        MainThread.BeginInvokeOnMainThread(async () =>
        {
            try
            {
                var json = System.Text.Json.JsonDocument.Parse(data.ToString()!);
                var message = json.RootElement.GetProperty("message").GetString();
                var carInfo = json.RootElement.GetProperty("carInfo").GetString();
                var freeMin = json.RootElement.GetProperty("freeWaitingMinutes").GetInt32();

                await DisplayAlert(
                    " Такси прибыло!",
                    $"{message}\n\nБесплатное ожидание: {freeMin} минут.",
                    "OK");
            }
            catch { }
        });
    }
        
    /// При новом запуске напоминаем о завершённой, но не оплаченной
    /// карточной поездке. Без этого закрытие приложения в момент завершения
    /// оставляло транзакцию pending без способа продолжить оплату.
    private async Task ResumePendingSberPaymentAsync()
    {
        try
        {
            await Task.Delay(1200); // сначала загрузить основной экран/конфигурацию
            var order = await _api.GetPendingSberOrderAsync();
            if (order == null) return;
            var pay = await DisplayAlert("Неоплаченная поездка",
                $"Заказ {order.OrderNumber} завершён. К оплате {order.TotalPrice:F0} ₽. Продолжить оплату через Сбер?",
                "Оплатить", "Позже");
            if (pay) await PayCompletedOrderBySberAsync(order);
        }
        catch { }
    }

    /// Оплата завершённой поездки на странице Сбера. Приложение никогда
    /// не получает номер карты/CVC: Сбер сам показывает сохранённые связки
    /// (если рекуррентные платежи одобрены) или форму новой карты/СБП.
    private async Task PayCompletedOrderBySberAsync(OrderResponse order)
    {
        try
        {
            var config = await _api.GetSberConfigAsync();
            if (config?.Enabled != true)
            {
                await DisplayAlert("Безналичная оплата недоступна",
                    "Эквайринг Сбера ещё не настроен администратором. "
                    + "Свяжитесь с диспетчером и выберите другой способ оплаты.", "OK");
                return;
            }

            var start = await _api.StartSberOrderPaymentAsync(order.Id, "card");
            if (start.Status == "paid")
            {
                await DisplayAlert("Оплачено", $"Оплата {order.TotalPrice:F0} ₽ подтверждена.", "OK");
                return;
            }
            if (string.IsNullOrWhiteSpace(start.FormUrl))
                throw new Exception("Сбер не вернул ссылку на оплату");

            Preferences.Set("pending_sber_payment", start.Id);
            Preferences.Set("pending_sber_order", order.Id.ToString());

            await Browser.Default.OpenAsync(start.FormUrl, BrowserLaunchMode.SystemPreferred);

            // После открытия формы проверяем подтверждение до 2 минут.
            // Если Android приостановит приложение, id сохранён и проверка
            // может быть повторена сервером/администратором без двойного начисления.
            for (var i = 0; i < 24; i++)
            {
                await Task.Delay(TimeSpan.FromSeconds(5));
                var status = await _api.CheckSberPaymentAsync(start.Id);
                if (status.Status == "paid")
                {
                    Preferences.Remove("pending_sber_payment");
                    Preferences.Remove("pending_sber_order");
                    await DisplayAlert("Оплата прошла",
                        $"Сбер подтвердил оплату {status.Amount:F0} ₽. Спасибо!", "OK");
                    return;
                }
                if (status.Status is "failed" or "cancelled" or "refunded")
                {
                    await DisplayAlert("Оплата не прошла",
                        status.Error ?? "Повторите оплату или свяжитесь с диспетчером.", "OK");
                    return;
                }
            }
            await DisplayAlert("Платёж проверяется",
                "Статус ещё не подтверждён. Не оплачивайте повторно — система продолжит проверку по номеру операции.", "OK");
        }
        catch (Exception ex)
        {
            await DisplayAlert("Ошибка оплаты", ex.Message, "OK");
        }
    }

    private async void OnHistoryClicked(object? sender, EventArgs e)
    {
        try
        {
            await Navigation.PushAsync(new HistoryPage(_api));
        }
        catch (Exception ex)
        {
            await DisplayAlert("Ошибка истории поездок", ex.Message, "OK");
        }
    }

    /// Выход из аккаунта: чистим сохранённую сессию и возвращаемся на экран входа
    private async void OnLogoutClicked(object? sender, EventArgs e)
    {
        try
        {
            if (!await DisplayAlert("Выход", "Выйти из аккаунта?", "Выйти", "Отмена"))
                return;

            SecureStorage.Remove("token");
            SecureStorage.Remove("user_id");
            SecureStorage.Remove("user_name");
            SecureStorage.Remove("role");
            // last_phone оставляем, чтобы при следующем входе телефон уже был заполнен

            Application.Current!.MainPage = new NavigationPage(
                new LoginPage(_api, _signalR));
        }
        catch { }
    }
}