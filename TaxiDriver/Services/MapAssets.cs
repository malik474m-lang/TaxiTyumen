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
    private static readonly string[] Files =
        { "map.html", "maplibre-gl.js", "maplibre-gl.css", "style-vector.json" };

    /// Шрифты подписей векторной карты. В пакете приложения лежат «плоско»
    /// (ограничение MauiAsset), а карте нужны по пути {fontstack}/{range}.pbf.
    private static readonly (string Asset, string Stack, string Range)[] FontFiles =
    {
        ("font-NotoSansRegular-0-255.pbf",     "Noto Sans Regular", "0-255"),
        ("font-NotoSansRegular-256-511.pbf",   "Noto Sans Regular", "256-511"),
        ("font-NotoSansRegular-1024-1279.pbf", "Noto Sans Regular", "1024-1279"),
        ("font-NotoSansRegular-8192-8447.pbf", "Noto Sans Regular", "8192-8447"),
    };

    /// Имя офлайн-пакета векторных тайлов (собирается TaxiDriver/maps/build-pmtiles).
    public const string VectorPackAsset = "tyumen.pmtiles";

    private static string? _indexPath;
    private static bool _vectorReady;
    private static readonly SemaphoreSlim VectorGate = new(1, 1);

    /// URL страницы карты (локальный HTTP-сервер). Ассеты копируются один раз.
    public static async Task<string> EnsureAsync()
    {
        if (_indexPath != null) return _indexPath;

        var dir = Path.Combine(FileSystem.CacheDirectory, "map");
        Directory.CreateDirectory(dir);

        foreach (var name in Files)
        {
            var target = Path.Combine(dir, name);
            var needCopy = !File.Exists(target);
            using var src = await FileSystem.OpenAppPackageFileAsync($"map/{name}");
            if (!needCopy)
            {
                if (name == "maplibre-gl.js")
                {
                    // Движок ~1 МБ: версия библиотеки всегда меняет размер
                    try { needCopy = new FileInfo(target).Length != src.Length; }
                    catch { needCopy = true; }
                }
                else
                {
                    // map.html и css весят килобайты: перезаписываем ВСЕГДА.
                    // Раньше сравнивали размер — при совпадении размеров в кеше
                    // оставалась прежняя страница, и карта выглядела «старой»
                    // даже после обновления приложения.
                    needCopy = true;
                }
            }
            if (needCopy)
            {
                using var dst = File.Create(target);
                await src.CopyToAsync(dst);
            }
        }

        await CopyFontsAsync(dir);

        // Android 10+ не даёт WebView грузить скрипты с file:// — отдаём страницу
        // с собственного сервера на 127.0.0.1 (заодно работает IndexedDB-кеш тайлов)
        _indexPath = LocalWebServer.Start(dir);

        // Пакет карты весит десятки МБ: готовим его в фоне, карта тем временем
        // уже показана на растровых тайлах и переключится сама
        _ = Task.Run(EnsureVectorPackAsync);
        return _indexPath;
    }

    /// Шрифты подписей: раскладываем в {fontstack}/{range}.pbf для MapLibre.
    private static async Task CopyFontsAsync(string mapDir)
    {
        foreach (var (asset, stack, range) in FontFiles)
        {
            try
            {
                var target = Path.Combine(mapDir, "fonts", stack, range + ".pbf");
                using var src = await FileSystem.OpenAppPackageFileAsync($"map/{asset}");
                // Файлы небольшие: перезаписываем всегда — так обновление
                // приложения гарантированно приносит свежие шрифты
                Directory.CreateDirectory(Path.GetDirectoryName(target)!);
                using var dst = File.Create(target);
                await src.CopyToAsync(dst);
            }
            catch
            {
                // Шрифты не собраны — векторные подписи просто не появятся
            }
        }
    }

    /// <summary>
    /// Офлайн-пакет векторных тайлов (OpenStreetMap → PMTiles) из APK.
    /// Для чтения нужен произвольный доступ к файлу, поэтому пакет один раз
    /// копируется в память приложения. Пакета нет — работаем на растре.
    /// </summary>
    public static async Task<bool> EnsureVectorPackAsync()
    {
        if (_vectorReady) return true;
        await VectorGate.WaitAsync();
        try
        {
            if (_vectorReady) return true;

            var dir = Path.Combine(FileSystem.AppDataDirectory, "map-pack");
            Directory.CreateDirectory(dir);

            // Версия сборки в имени файла: на Android длина asset-потока
            // недоступна, и без этого обновлённый пакет не заменил бы старый
            var build = new string((AppInfo.Current.BuildString ?? "0")
                .Where(char.IsLetterOrDigit).ToArray());
            if (string.IsNullOrEmpty(build)) build = "0";
            var target = Path.Combine(dir, $"tyumen-{build}.pmtiles");

            using (var src = await FileSystem.OpenAppPackageFileAsync($"map/{VectorPackAsset}"))
            {
                long srcLength = 0;
                try { srcLength = src.Length; } catch { srcLength = 0; }

                var fresh = File.Exists(target)
                    && (srcLength == 0 || new FileInfo(target).Length == srcLength);
                if (!fresh)
                {
                    // Пишем во временный файл: прерванное копирование не оставит
                    // «половину» пакета, которую потом не отличить от целого
                    var tmp = target + ".tmp";
                    using (var dst = File.Create(tmp)) await src.CopyToAsync(dst);
                    if (File.Exists(target)) File.Delete(target);
                    File.Move(tmp, target);

                    // Пакеты прошлых версий приложения больше не нужны
                    foreach (var stale in Directory.GetFiles(dir, "*.pmtiles*"))
                    {
                        if (string.Equals(stale, target, StringComparison.Ordinal)) continue;
                        try { File.Delete(stale); } catch { }
                    }
                }
            }

            _vectorReady = LocalWebServer.SetVectorPack(target);
            return _vectorReady;
        }
        catch
        {
            return false;   // пакет не собран — тихо остаёмся на растровых тайлах
        }
        finally { VectorGate.Release(); }
    }

    private static object? Point(double? lat, double? lng, string label)
        => lat.HasValue && lng.HasValue && lat.Value != 0 && lng.Value != 0
            ? new { lat = lat.Value, lng = lng.Value, label }
            : null;

    /// JSON для window.setRoute(): точки, геометрия дороги, позиция водителя.
    public static string BuildRouteJson(
        OrderResponse order, double driverLat, double driverLng, bool toPickup,
        List<List<double>>? roadGeometry = null, long tilesVersion = 0,
        double? bearing = null, bool follow = false)
    {
        var payload = new
        {
            tilesVersion,
            // follow — камера едет за машиной (включается в полноэкранном режиме)
            follow,
            driver = new { lat = driverLat, lng = driverLng, bearing },
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
