using System.Globalization;
using System.Text.Json;
using TaxiDriver.Models;

namespace TaxiDriver.Services;

/// <summary>
/// Карта маршрута на MapLibre GL (лицензия BSD, бесплатно и без лимитов).
///
/// Файлы карты лежат в приложении и копируются в кеш при первом запуске,
/// поэтому движок карты работает БЕЗ интернета. Тайлы OpenStreetMap
/// кешируются в IndexedDB: скачанные один раз квадраты города доступны офлайн.
/// Линия маршрута приходит вместе с заказом и рисуется в любом случае.
///
/// Страница загружается ОДИН раз: дальше приложение только вызывает
/// JS-функции (setRoute/updateDriver), поэтому WebView не перезагружается
/// и карта не моргает.
/// </summary>
public static class MapAssets
{
    private static readonly string[] Files = { "map.html", "maplibre-gl.js", "maplibre-gl.css" };
    private static string? _indexPath;

    /// URL страницы карты (локальный HTTP-сервер). Ассеты копируются один раз.
    public static async Task<string> EnsureAsync()
    {
        if (_indexPath != null) return _indexPath;

        var dir = Path.Combine(FileSystem.CacheDirectory, "map");
        Directory.CreateDirectory(dir);

        foreach (var name in Files)
        {
            var target = Path.Combine(dir, name);
            // Перезаписываем при обновлении приложения: сравниваем размер
            var needCopy = !File.Exists(target);
            using var src = await FileSystem.OpenAppPackageFileAsync($"map/{name}");
            if (!needCopy)
            {
                try { needCopy = new FileInfo(target).Length != src.Length; }
                catch { needCopy = true; }
            }
            if (needCopy)
            {
                using var dst = File.Create(target);
                await src.CopyToAsync(dst);
            }
        }

        // Android 10+ не даёт WebView грузить скрипты с file:// — отдаём страницу
        // с собственного сервера на 127.0.0.1 (заодно работает IndexedDB-кеш тайлов)
        _indexPath = LocalWebServer.Start(dir);
        return _indexPath;
    }

    private static object? Point(double? lat, double? lng, string label)
        => lat.HasValue && lng.HasValue && lat.Value != 0 && lng.Value != 0
            ? new { lat = lat.Value, lng = lng.Value, label }
            : null;

    /// JSON для window.setRoute(): точки, геометрия дороги, позиция водителя.
    public static string BuildRouteJson(
        OrderResponse order, double driverLat, double driverLng, bool toPickup,
        List<List<double>>? roadGeometry = null, long tilesVersion = 0)
    {
        var payload = new
        {
            tilesVersion,
            driver = new { lat = driverLat, lng = driverLng },
            target = toPickup
                ? Point(order.PickupLatitude, order.PickupLongitude, "Подача")
                : Point(order.DestinationLatitude, order.DestinationLongitude, "Назначение"),
            finish = toPickup
                ? Point(order.DestinationLatitude, order.DestinationLongitude, "Финиш")
                : null,
            stops = order.IntermediatePoints
                .OrderBy(p => p.SortOrder)
                .Where(p => p.Latitude != 0 && p.Longitude != 0)
                .Select(p => new { lat = p.Latitude, lng = p.Longitude, label = p.Address })
                .ToList(),
            // Приоритет — дорожная геометрия от текущей позиции водителя;
            // геометрия из заказа только как запасной вариант.
            geometry = roadGeometry ?? order.RouteGeometry ?? new List<List<double>>(),
        };
        return JsonSerializer.Serialize(payload);
    }

    /// Экранирование JSON для передачи в WebView.Eval.
    public static string JsArg(string json)
        => json.Replace("\\", "\\\\").Replace("'", "\\'").Replace("\n", "").Replace("\r", "");

    public static string N(double v) => v.ToString("F6", CultureInfo.InvariantCulture);
}
