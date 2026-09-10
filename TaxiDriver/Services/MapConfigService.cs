using System.Net.Http.Json;
using System.Text.Json.Serialization;

namespace TaxiDriver.Services;

/// <summary>
/// Публичная картографическая конфигурация сервера (api/map-config.php).
/// Отсюда приложение узнаёт URL растрового слоя пробок (TomTom Traffic):
/// ключ задаётся в админке («API-ключи»). Ответ кешируется на время процесса.
/// </summary>
public static class MapConfigService
{
    private sealed class TrafficDto
    {
        [JsonPropertyName("configured")] public bool Configured { get; set; }
        [JsonPropertyName("tileUrl")] public string? TileUrl { get; set; }
    }

    private sealed class MapConfigDto
    {
        [JsonPropertyName("traffic")] public TrafficDto? Traffic { get; set; }
    }

    private static MapConfigDto? _cache;

    /// Сброс кеша — следующий запрос пойдёт на сервер (повтор после ошибки сети).
    public static void Reset() => _cache = null;

    /// URL тайлов пробок или null (ключ не задан / сервер недоступен).
    public static async Task<string?> GetTrafficTileUrlAsync()
    {
        if (_cache == null)
        {
            try
            {
                using var http = new HttpClient
                {
                    BaseAddress = new Uri("https://taxi.event72.ru/api/"),
                    Timeout = TimeSpan.FromSeconds(8)
                };
                _cache = await http.GetFromJsonAsync<MapConfigDto>("map-config.php");
            }
            catch
            {
                _cache = null;   // не запоминаем промах — дадим шанс сети
            }
        }
        var traffic = _cache?.Traffic;
        return traffic is { Configured: true } && !string.IsNullOrEmpty(traffic.TileUrl)
            ? traffic.TileUrl
            : null;
    }
}
