using System.Net.Http;
using System.Net.Http.Json;
using System.Text.Json.Serialization;

namespace TaxiOperator.Services;

/// Ключ Яндекс Карт берётся с сервера (общая настройка сервиса),
/// чтобы не хранить его в дистрибутиве пульта.
public static class MapConfig
{
    private sealed class MapConfigDto
    {
        [JsonPropertyName("apiKey")] public string? ApiKey { get; set; }
        /// Центр карты из админки «Бренд сервиса» (город, координаты).
        [JsonPropertyName("center")] public double[]? Center { get; set; }
        [JsonPropertyName("city")] public string? City { get; set; }
    }

    private static string? _apiKey;
    private static double[]? _center;
    private static string? _city;

    /// Ключ Яндекс Карт (кэшируется на время работы пульта).
    public static async Task<string> GetApiKeyAsync()
    {
        await EnsureLoadedAsync();
        return _apiKey ?? "";
    }

    /// Центр карты из настроек сервиса; по умолчанию — Тюмень.
    public static async Task<double[]> GetCenterAsync()
    {
        await EnsureLoadedAsync();
        return _center is { Length: 2 } ? _center : new[] { 57.1522, 65.5272 };
    }

    public static async Task<string> GetCityAsync()
    {
        await EnsureLoadedAsync();
        return _city ?? "";
    }

    private static bool _loaded;

    private static async Task EnsureLoadedAsync()
    {
        if (_loaded) return;
        try
        {
            using var http = new HttpClient
            {
                BaseAddress = new Uri("https://taxi.event72.ru/api/"),
                Timeout = TimeSpan.FromSeconds(8)
            };
            var cfg = await http.GetFromJsonAsync<MapConfigDto>("map-config.php");
            _apiKey = cfg?.ApiKey ?? "";
            _center = cfg?.Center;
            _city = cfg?.City;
        }
        catch
        {
            _apiKey = "";
        }
        finally
        {
            _loaded = true;
        }
    }
}
