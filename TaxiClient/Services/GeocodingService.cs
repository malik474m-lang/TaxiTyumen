using System.Globalization;
using System.Net.Http.Json;

namespace TaxiClient.Services;

// DaData/Nominatim выполняет сервер: ключи не попадают в приложение.
public class GeocodingService
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

    public async Task<AddressSuggestion?> ReverseGeocodeAsync(double lat, double lng)
    {
        try
        {
            var url = string.Format(CultureInfo.InvariantCulture,
                "geocoding.php?lat={0}&lng={1}", lat, lng);
            return await _http.GetFromJsonAsync<AddressSuggestion>(url);
        }
        catch
        {
            return new AddressSuggestion
            {
                DisplayName = $"{lat:F4}, {lng:F4}",
                Latitude = lat,
                Longitude = lng,
                Source = "coordinates"
            };
        }
    }
}

public class AddressSuggestion
{
    public string DisplayName { get; set; } = string.Empty;
    public string FullAddress { get; set; } = string.Empty;
    public double Latitude { get; set; }
    public double Longitude { get; set; }
    public string Source { get; set; } = string.Empty;
}
