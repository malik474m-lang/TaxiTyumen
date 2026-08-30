using System.Net.Http;
using System.Net.Http.Json;

namespace TaxiOperator.Services;

// Серверный DaData + Nominatim. API-ключ хранится только на PHP-хостинге.
public class DadataService
{
    private readonly HttpClient _http = new()
    {
        BaseAddress = new Uri("https://taxi.event72.ru/api/"),
        Timeout = TimeSpan.FromSeconds(12)
    };

    public async Task<List<AddressSuggestion>> SearchAsync(string query)
    {
        if (string.IsNullOrWhiteSpace(query) || query.Length < 2) return new();
        try
        {
            return await _http.GetFromJsonAsync<List<AddressSuggestion>>(
                "geocoding.php?q=" + Uri.EscapeDataString(query)) ?? new();
        }
        catch { return new(); }
    }
}

public class AddressSuggestion
{
    public string DisplayName { get; set; } = "";
    public string FullAddress { get; set; } = "";
    public double Latitude { get; set; }
    public double Longitude { get; set; }
    public string Source { get; set; } = "";
}
