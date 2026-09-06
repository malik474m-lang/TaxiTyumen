using System.Net.Http;
using System.Net.Http.Json;
using System.Text.Json.Serialization;

namespace TaxiOperator.Services;

/// Ключ Яндекс Карт берётся с сервера (общая настройка сервиса),
/// чтобы не хранить его в дистрибутиве пульта.
public static class MapConfig
{
    private static string? _apiKey;

    private sealed class MapConfigDto
    {
        [JsonPropertyName("apiKey")] public string? ApiKey { get; set; }
    }

    public static async Task<string> GetApiKeyAsync()
    {
        if (_apiKey != null) return _apiKey;
        try
        {
            using var http = new HttpClient
            {
                BaseAddress = new Uri("https://taxi.event72.ru/api/"),
                Timeout = TimeSpan.FromSeconds(8)
            };
            var cfg = await http.GetFromJsonAsync<MapConfigDto>("map-config.php");
            _apiKey = cfg?.ApiKey ?? "";
        }
        catch
        {
            _apiKey = "";
        }
        return _apiKey;
    }
}
