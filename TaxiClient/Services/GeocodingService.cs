using System.Globalization;
using System.Net.Http.Json;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace TaxiClient.Services;

public class GeocodingService
{
    private readonly HttpClient _dadataHttp;
    private readonly HttpClient _nominatimHttp;

    private const string DADATA_API_KEY = "994b61b9333273832464311fefa618f0f07dfb39";
    private const string DADATA_URL =
        "https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address";
    private const string NOMINATIM_URL =
        "https://nominatim.openstreetmap.org/search";
    private const string NOMINATIM_REVERSE_URL =
        "https://nominatim.openstreetmap.org/reverse";

    private static readonly JsonSerializerOptions _json = new()
    {
        PropertyNameCaseInsensitive = true
    };

    public GeocodingService()
    {
        _dadataHttp = new HttpClient();
        _dadataHttp.DefaultRequestHeaders.Add("Authorization", "Token " + DADATA_API_KEY);
        _dadataHttp.DefaultRequestHeaders.Add("Accept", "application/json");

        _nominatimHttp = new HttpClient();
        _nominatimHttp.DefaultRequestHeaders.Add("User-Agent", "TaxiTyumenApp/1.0");
        _nominatimHttp.DefaultRequestHeaders.Add("Accept-Language", "ru");
    }

    /// <summary>
    /// Подсказки адресов: DaData + Nominatim
    /// </summary>
    public async Task<List<AddressSuggestion>> SearchAsync(string query)
    {
        if (string.IsNullOrWhiteSpace(query) || query.Length < 2)
            return new();

        var results = new List<AddressSuggestion>();

        // Шаг 1: DaData  лучше для городских адресов
        try
        {
            var dadataResults = await SearchDadataAsync(query);
            results.AddRange(dadataResults);
        }
        catch { }

        // Шаг 2: если DaData дала мало  добавляем Nominatim
        if (results.Count < 3)
        {
            try
            {
                var nominatimResults = await SearchNominatimAsync(query);

                foreach (var nr in nominatimResults)
                {
                    var isDuplicate = results.Any(r =>
                        Math.Abs(r.Latitude - nr.Latitude) < 0.001 &&
                        Math.Abs(r.Longitude - nr.Longitude) < 0.001);

                    if (!isDuplicate)
                        results.Add(nr);
                }
            }
            catch { }
        }

        return results.Take(7).ToList();
    }

    /// <summary>
    /// DaData  городские адреса Тюмени
    /// </summary>
    private async Task<List<AddressSuggestion>> SearchDadataAsync(string query)
    {
        var body = new
        {
            query = query,
            count = 5,
            locations = new object[]
            {
                new { region = "Тюменская", city = "Тюмень" },
                new { region = "Тюменская", area = "Тюменский" },
                new { region = "Тюменская" }
            },
            restrict_value = false
        };

        var content = new StringContent(
            JsonSerializer.Serialize(body),
            Encoding.UTF8, "application/json");

        var response = await _dadataHttp.PostAsync(DADATA_URL, content);
        if (!response.IsSuccessStatusCode)
            return new();

        var result = await response.Content.ReadFromJsonAsync<DadataResponse>(_json);
        if (result?.Suggestions == null)
            return new();

        var list = new List<AddressSuggestion>();

        foreach (var s in result.Suggestions)
        {
            if (s.Data == null) continue;

            double lat = 0, lng = 0;

            if (!string.IsNullOrEmpty(s.Data.GeoLat))
                double.TryParse(s.Data.GeoLat, CultureInfo.InvariantCulture, out lat);

            if (!string.IsNullOrEmpty(s.Data.GeoLon))
                double.TryParse(s.Data.GeoLon, CultureInfo.InvariantCulture, out lng);

            if (lat == 0 && lng == 0) continue;

            list.Add(new AddressSuggestion
            {
                DisplayName = FormatDadataAddress(s),
                FullAddress = s.Value ?? "",
                Latitude = lat,
                Longitude = lng,
                Source = "dadata"
            });
        }

        return list;
    }

    /// <summary>
    /// Nominatim  посёлки и сёла Тюменского района
    /// </summary>
    private async Task<List<AddressSuggestion>> SearchNominatimAsync(string query)
    {
        var searchQuery = query;
        if (!query.Contains("Тюмень", StringComparison.OrdinalIgnoreCase) &&
            !query.Contains("Тюменск", StringComparison.OrdinalIgnoreCase))
        {
            searchQuery = query + ", Тюменская область";
        }

        var url = string.Format(CultureInfo.InvariantCulture,
            "{0}?q={1}&format=json&addressdetails=1&limit=5" +
            "&countrycodes=ru&accept-language=ru" +
            "&viewbox=64.8,57.8,66.6,56.4&bounded=1",
            NOMINATIM_URL,
            Uri.EscapeDataString(searchQuery));

        var results = await _nominatimHttp.GetFromJsonAsync<List<NominatimResult>>(url, _json);
        if (results == null) return new();

        var list = new List<AddressSuggestion>();

        foreach (var r in results)
        {
            if (!double.TryParse(r.Lat, CultureInfo.InvariantCulture, out var lat))
                continue;
            if (!double.TryParse(r.Lon, CultureInfo.InvariantCulture, out var lng))
                continue;

            var displayName = FormatNominatimAddress(r);
            if (string.IsNullOrWhiteSpace(displayName)) continue;

            list.Add(new AddressSuggestion
            {
                DisplayName = displayName,
                FullAddress = r.DisplayName ?? "",
                Latitude = lat,
                Longitude = lng,
                Source = "nominatim"
            });
        }

        return list;
    }

    /// <summary>
    /// Обратное геокодирование  координаты  адрес
    /// Сначала пробуем DaData, потом Nominatim
    /// </summary>
    public async Task<AddressSuggestion?> ReverseGeocodeAsync(double lat, double lng)
    {
        // Пробуем через DaData
        try
        {
            var result = await ReverseGeocodeDadataAsync(lat, lng);
            if (result != null) return result;
        }
        catch { }

        // Fallback: Nominatim
        try
        {
            return await ReverseGeocodeNominatimAsync(lat, lng);
        }
        catch
        {
            return new AddressSuggestion
            {
                DisplayName = $"{lat:F4}, {lng:F4}",
                FullAddress = "",
                Latitude = lat,
                Longitude = lng
            };
        }
    }

    private async Task<AddressSuggestion?> ReverseGeocodeDadataAsync(double lat, double lng)
    {
        var body = new
        {
            lat = lat.ToString(CultureInfo.InvariantCulture),
            lon = lng.ToString(CultureInfo.InvariantCulture),
            count = 1
        };

        var content = new StringContent(
            JsonSerializer.Serialize(body),
            Encoding.UTF8, "application/json");

        var url = "https://suggestions.dadata.ru/suggestions/api/4_1/rs/geolocate/address";
        var response = await _dadataHttp.PostAsync(url, content);

        if (!response.IsSuccessStatusCode) return null;

        var result = await response.Content.ReadFromJsonAsync<DadataResponse>(_json);

        var first = result?.Suggestions?.FirstOrDefault();
        if (first?.Data == null) return null;

        double rLat = 0, rLng = 0;
        if (!string.IsNullOrEmpty(first.Data.GeoLat))
            double.TryParse(first.Data.GeoLat, CultureInfo.InvariantCulture, out rLat);
        if (!string.IsNullOrEmpty(first.Data.GeoLon))
            double.TryParse(first.Data.GeoLon, CultureInfo.InvariantCulture, out rLng);

        return new AddressSuggestion
        {
            DisplayName = FormatDadataAddress(first),
            FullAddress = first.Value ?? "",
            Latitude = rLat != 0 ? rLat : lat,
            Longitude = rLng != 0 ? rLng : lng,
            Source = "dadata"
        };
    }

    private async Task<AddressSuggestion?> ReverseGeocodeNominatimAsync(double lat, double lng)
    {
        var url = string.Format(CultureInfo.InvariantCulture,
            "{0}?lat={1}&lon={2}&format=json&addressdetails=1&accept-language=ru",
            NOMINATIM_REVERSE_URL, lat, lng);

        var result = await _nominatimHttp.GetFromJsonAsync<NominatimResult>(url, _json);
        if (result == null) return null;

        if (!double.TryParse(result.Lat, CultureInfo.InvariantCulture, out var rLat))
            rLat = lat;
        if (!double.TryParse(result.Lon, CultureInfo.InvariantCulture, out var rLng))
            rLng = lng;

        return new AddressSuggestion
        {
            DisplayName = FormatNominatimAddress(result),
            FullAddress = result.DisplayName ?? "",
            Latitude = rLat,
            Longitude = rLng,
            Source = "nominatim"
        };
    }

    // ===== Форматирование =====

    private static string FormatDadataAddress(DadataSuggestion s)
    {
        var d = s.Data;
        if (d == null) return s.Value ?? "";

        var parts = new List<string>();

        // Населённый пункт
        if (!string.IsNullOrWhiteSpace(d.City))
            parts.Add("г. " + d.City);
        else if (!string.IsNullOrWhiteSpace(d.Settlement))
            parts.Add((d.SettlementType ?? "п.") + " " + d.Settlement);

        // Улица
        if (!string.IsNullOrWhiteSpace(d.Street))
            parts.Add((d.StreetType ?? "ул.") + " " + d.Street);

        // Дом
        if (!string.IsNullOrWhiteSpace(d.House))
        {
            var house = (d.HouseType ?? "д.") + " " + d.House;
            if (!string.IsNullOrWhiteSpace(d.Block))
                house += " " + (d.BlockType ?? "к.") + d.Block;
            parts.Add(house);
        }

        return parts.Count > 0
            ? string.Join(", ", parts)
            : s.Value ?? "";
    }

    private static string FormatNominatimAddress(NominatimResult r)
    {
        var a = r.Address;
        if (a == null) return r.DisplayName ?? "";

        var parts = new List<string>();

        var locality =
            a.City ?? a.Town ?? a.Village ?? a.Hamlet ?? a.Suburb;

        if (!string.IsNullOrWhiteSpace(locality))
        {
            parts.Add(string.Equals(locality, "Тюмень", StringComparison.OrdinalIgnoreCase)
                ? "г. Тюмень"
                : locality);
        }

        if (!string.IsNullOrWhiteSpace(a.Road))
        {
            parts.Add(!string.IsNullOrWhiteSpace(a.HouseNumber)
                ? $"{a.Road}, {a.HouseNumber}"
                : a.Road);
        }

        return parts.Count > 0
            ? string.Join(", ", parts)
            : r.DisplayName ?? "";
    }
}

// ===== Модели =====

public class AddressSuggestion
{
    public string DisplayName { get; set; } = string.Empty;
    public string FullAddress { get; set; } = string.Empty;
    public double Latitude { get; set; }
    public double Longitude { get; set; }
    public string Source { get; set; } = string.Empty;
}

public class DadataResponse
{
    public List<DadataSuggestion>? Suggestions { get; set; }
}

public class DadataSuggestion
{
    public string? Value { get; set; }
    public DadataData? Data { get; set; }
}

public class DadataData
{
    [JsonPropertyName("geo_lat")] public string? GeoLat { get; set; }
    [JsonPropertyName("geo_lon")] public string? GeoLon { get; set; }
    public string? City { get; set; }
    [JsonPropertyName("city_type")] public string? CityType { get; set; }
    public string? Settlement { get; set; }
    [JsonPropertyName("settlement_type")] public string? SettlementType { get; set; }
    public string? Street { get; set; }
    [JsonPropertyName("street_type")] public string? StreetType { get; set; }
    public string? House { get; set; }
    [JsonPropertyName("house_type")] public string? HouseType { get; set; }
    public string? Block { get; set; }
    [JsonPropertyName("block_type")] public string? BlockType { get; set; }
}

public class NominatimResult
{
    public string? Lat { get; set; }
    public string? Lon { get; set; }
    public string? DisplayName { get; set; }
    public NominatimAddress? Address { get; set; }
}

public class NominatimAddress
{
    public string? Road { get; set; }
    public string? HouseNumber { get; set; }
    public string? Suburb { get; set; }
    public string? City { get; set; }
    public string? Town { get; set; }
    public string? Village { get; set; }
    public string? Hamlet { get; set; }
    public string? County { get; set; }
}