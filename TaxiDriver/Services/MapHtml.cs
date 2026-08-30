using System.Globalization;
using System.Net.Http.Json;

namespace TaxiDriver.Services;

/// Карта Яндекса внутри приложения (WebView): позиция водителя, точки подачи
/// и назначения, маршрут по дорогам. Ключ API берётся с сервера (map-config).
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

    /// HTML карты: маршрут «водитель → точка», при наличии финиша — вся поездка.
    public static string Build(
        string apiKey,
        double driverLat, double driverLng,
        double? toLat, double? toLng, string toLabel,
        double? finishLat = null, double? finishLng = null)
    {
        var key = string.IsNullOrWhiteSpace(apiKey) ? "" : "&apikey=" + apiKey;

        var routePoints = $"[{N(driverLat)}, {N(driverLng)}]";
        if (toLat.HasValue && toLng.HasValue)
            routePoints += $", [{N(toLat.Value)}, {N(toLng.Value)}]";
        if (finishLat.HasValue && finishLng.HasValue)
            routePoints += $", [{N(finishLat.Value)}, {N(finishLng.Value)}]";

        return $$"""
<!DOCTYPE html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,user-scalable=no">
<style>html,body,#map{margin:0;padding:0;height:100%;width:100%;background:#1E1E2E}
.err{color:#aaa;font:14px sans-serif;padding:20px;text-align:center}</style>
<script src="https://api-maps.yandex.ru/2.1/?lang=ru_RU{{key}}"></script>
</head><body><div id="map"></div>
<script>
function start(){
  try{
    var map = new ymaps.Map('map', {
      center: [{{N(driverLat)}}, {{N(driverLng)}}],
      zoom: 14, controls: ['zoomControl','geolocationControl']
    }, { suppressMapOpenBlock: true });

    var me = new ymaps.Placemark([{{N(driverLat)}}, {{N(driverLng)}}],
      { iconCaption: 'Вы' },
      { preset: 'islands#yellowAutoCircleIcon' });
    map.geoObjects.add(me);

    var pts = [{{routePoints}}];
    if (pts.length > 1) {
      var route = new ymaps.multiRouter.MultiRoute({
        referencePoints: pts,
        params: { routingMode: 'auto' }
      }, {
        boundsAutoApply: true,
        routeActiveStrokeColor: '#FFD700',
        routeActiveStrokeWidth: 6,
        wayPointStartIconColor: '#FFD700',
        wayPointFinishIconColor: '#4CAF50'
      });
      map.geoObjects.add(route);
    }
  }catch(e){
    document.getElementById('map').innerHTML =
      '<div class="err">Карта недоступна: ' + e.message + '</div>';
  }
}
if (window.ymaps) { ymaps.ready(start); }
else { document.getElementById('map').innerHTML =
  '<div class="err">Нет соединения с картами</div>'; }
</script></body></html>
""";
    }
}
