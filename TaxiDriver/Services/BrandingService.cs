using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace TaxiDriver.Services;

/// Брендинг приложения водителя: название сервиса, цвета и логотип приходят
/// с сервера из админки («Брендинг» app=driver и «Бренд сервиса»).
/// Значения кэшируются локально — офлайн-старт показывает последний бренд.
public class BrandingData
{
    [JsonPropertyName("appName")] public string AppName { get; set; } = "Приложение водителя";
    [JsonPropertyName("appCode")] public string AppCode { get; set; } = "TaxiDriver";
    [JsonPropertyName("heroTitle")] public string HeroTitle { get; set; } = "";
    [JsonPropertyName("heroSubtitle")] public string HeroSubtitle { get; set; } = "";
    [JsonPropertyName("logoUrl")] public string? LogoUrl { get; set; }
    [JsonPropertyName("primaryColor")] public string PrimaryColor { get; set; } = "#FFD700";
    [JsonPropertyName("primaryTextColor")] public string PrimaryTextColor { get; set; } = "#1E1E2E";
    [JsonPropertyName("supportPhone")] public string? SupportPhone { get; set; }

    /// Название сервиса целиком (из «Бренд сервиса»), например «НАШЕ Такси»
    public string ServiceName { get; set; } = "Такси Тюмень";
    public string City { get; set; } = "";
}

public class ServiceBrandDto
{
    [JsonPropertyName("serviceName")] public string ServiceName { get; set; } = "";
    [JsonPropertyName("city")] public string City { get; set; } = "";
    [JsonPropertyName("supportPhone")] public string? SupportPhone { get; set; }
}

public static class BrandingService
{
    private static readonly HttpClient Http = new()
    {
        BaseAddress = new Uri("https://taxi.event72.ru/api/"),
        Timeout = TimeSpan.FromSeconds(10)
    };

    /// Актуальный бренд (обновляется при старте и при показе экрана входа)
    public static BrandingData Current { get; private set; } = new();

    public static event Action<BrandingData>? Updated;

    private static string CachePath =>
        Path.Combine(FileSystem.AppDataDirectory, "branding-driver.json");

    /// Загрузка бренда с сервера; при недоступности — локальный кэш.
    public static async Task<BrandingData> LoadAsync()
    {
        try
        {
            var brand = await Http.GetFromJsonAsync<BrandingData>("branding.php?app=driver");
            if (brand != null)
            {
                // Название сервиса и город — из «Бренд сервиса» (общий для всех приложений)
                try
                {
                    var service = await Http.GetFromJsonAsync<ServiceBrandDto>("service-settings.php");
                    if (service != null)
                    {
                        if (!string.IsNullOrWhiteSpace(service.ServiceName))
                            brand.ServiceName = service.ServiceName;
                        brand.City = service.City;
                        brand.SupportPhone ??= service.SupportPhone;
                    }
                }
                catch { /* блок сервиса не критичен: бренд приложения уже получен */ }

                Current = brand;
                try { File.WriteAllText(CachePath, JsonSerializer.Serialize(brand)); } catch { }
                Updated?.Invoke(brand);
                return brand;
            }
        }
        catch { /* сервер недоступен — идём в кэш */ }

        try
        {
            if (File.Exists(CachePath))
            {
                var cached = JsonSerializer.Deserialize<BrandingData>(File.ReadAllText(CachePath));
                if (cached != null)
                {
                    Current = cached;
                    Updated?.Invoke(cached);
                    return cached;
                }
            }
        }
        catch { }

        return Current;
    }

    /// Логотип из админки («Брендинг» → загрузка файла) как абсолютный URL:
    /// сервер отдаёт относительный путь вида /api/branding-logo.php?app=driver&v=...
    public static string? AbsoluteLogoUrl(BrandingData brand)
    {
        if (string.IsNullOrWhiteSpace(brand.LogoUrl)) return null;
        return brand.LogoUrl.StartsWith("http", StringComparison.OrdinalIgnoreCase)
            ? brand.LogoUrl
            : "https://taxi.event72.ru" + brand.LogoUrl;
    }

    public static Color ParseColor(string? hex, string fallback)
    {
        try
        {
            if (!string.IsNullOrWhiteSpace(hex))
                return Color.FromArgb(hex.Trim());
        }
        catch { }
        return Color.FromArgb(fallback);
    }
}
