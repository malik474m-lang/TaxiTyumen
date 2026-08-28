using System.Net.Http;
using System.Net.Http.Json;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace TaxiOperator.Services;

public class DadataService
{
    private readonly HttpClient _http;
    private const string API_KEY = "994b61b9333273832464311fefa618f0f07dfb39";
    private const string SUGGEST_URL = "https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address";

    public DadataService()
    {
        _http = new HttpClient();
        _http.DefaultRequestHeaders.Add("Authorization", "Token " + API_KEY);
        _http.DefaultRequestHeaders.Add("Accept", "application/json");
    }

    public async Task<List<AddressSuggestion>> SearchAsync(string query)
    {
        if (string.IsNullOrWhiteSpace(query) || query.Length < 2)
            return new();

        try
        {
            var body = new
            {
                query = query,
                count = 5,
                locations = new object[]
                {
                    new { region = "Тюменская", city = "Тюмень" },
                    new { region = "Тюменская", area = "Тюменский" }
                },
                restrict_value = false
            };

            var json = JsonSerializer.Serialize(body);
            var content = new StringContent(json, Encoding.UTF8, "application/json");
            var response = await _http.PostAsync(SUGGEST_URL, content);

            if (!response.IsSuccessStatusCode) return new();

            var result = await response.Content.ReadFromJsonAsync<DadataResponse>(
                new JsonSerializerOptions { PropertyNameCaseInsensitive = true });

            if (result?.Suggestions == null) return new();

            var list = new List<AddressSuggestion>();

            foreach (var s in result.Suggestions)
            {
                if (s.Data == null) continue;

                double lat = 0, lng = 0;
                if (!string.IsNullOrEmpty(s.Data.GeoLat))
                    double.TryParse(s.Data.GeoLat, System.Globalization.CultureInfo.InvariantCulture, out lat);
                if (!string.IsNullOrEmpty(s.Data.GeoLon))
                    double.TryParse(s.Data.GeoLon, System.Globalization.CultureInfo.InvariantCulture, out lng);

                if (lat == 0 && lng == 0) continue;

                list.Add(new AddressSuggestion
                {
                    DisplayName = s.Value ?? "",
                    Latitude = lat,
                    Longitude = lng
                });
            }

            return list;
        }
        catch { return new(); }
    }
}

public class AddressSuggestion
{
    public string DisplayName { get; set; } = "";
    public double Latitude { get; set; }
    public double Longitude { get; set; }
}

public class DadataResponse
{
    public List<DadataSuggestionItem>? Suggestions { get; set; }
}

public class DadataSuggestionItem
{
    public string? Value { get; set; }
    public DadataItemData? Data { get; set; }
}

public class DadataItemData
{
    [JsonPropertyName("geo_lat")]
    public string? GeoLat { get; set; }

    [JsonPropertyName("geo_lon")]
    public string? GeoLon { get; set; }
}