using System.IO;
using System.Net.Http;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Windows;
using System.Windows.Media;

namespace TaxiOperator.Services;

/// Брендинг пульта оператора: название, цвета, телефон поддержки и логотип
/// приходят с сервера из раздела админки «Брендинг» (app=operator) и
/// «Бренд сервиса». Значения кэшируются локально — офлайн-старт не ломается.
public class BrandingData
{
    [JsonPropertyName("appName")] public string AppName { get; set; } = "Диспетчерская";
    [JsonPropertyName("appCode")] public string AppCode { get; set; } = "Такси";
    [JsonPropertyName("heroTitle")] public string HeroTitle { get; set; } = "Пульт оператора";
    [JsonPropertyName("heroSubtitle")] public string HeroSubtitle { get; set; } = "";
    [JsonPropertyName("logoUrl")] public string? LogoUrl { get; set; }
    [JsonPropertyName("primaryColor")] public string PrimaryColor { get; set; } = "#FFD700";
    [JsonPropertyName("primaryTextColor")] public string PrimaryTextColor { get; set; } = "#1E1E2E";
    [JsonPropertyName("supportPhone")] public string? SupportPhone { get; set; }

    /// Название сервиса целиком (из «Бренд сервиса»), например «НАШЕ Такси»
    public string ServiceName { get; set; } = "Такси";
    public string City { get; set; } = "";
}

public class ServiceBrandDto
{
    [JsonPropertyName("serviceName")] public string ServiceName { get; set; } = "Такси";
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

    /// Актуальный бренд приложения (обновляется при старте и по таймеру)
    public static BrandingData Current { get; private set; } = new();

    public static event Action<BrandingData>? Updated;

    private static string CachePath
    {
        get
        {
            var dir = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "TaxiTyumen");
            Directory.CreateDirectory(dir);
            return Path.Combine(dir, "branding-operator.json");
        }
    }

    /// Загрузка бренда с сервера; при недоступности — локальный кэш.
    public static async Task<BrandingData> LoadAsync()
    {
        try
        {
            var brand = await Http.GetFromJsonAsync<BrandingData>("branding.php?app=operator");
            if (brand != null)
            {
                try
                {
                    var service = await Http.GetFromJsonAsync<ServiceBrandDto>("service-settings.php");
                    if (service != null)
                    {
                        brand.ServiceName = service.ServiceName;
                        brand.City = service.City;
                        brand.SupportPhone ??= service.SupportPhone;
                    }
                }
                catch { }

                Current = brand;
                try { File.WriteAllText(CachePath, JsonSerializer.Serialize(brand)); } catch { }
                Apply(brand);
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
                    Apply(cached);
                    Updated?.Invoke(cached);
                    return cached;
                }
            }
        }
        catch { }

        Apply(Current);
        return Current;
    }

    /// Публикация цветов бренда в ресурсы приложения:
    /// кисти BrandBrush / BrandTextBrush доступны из любого окна через DynamicResource.
    public static void Apply(BrandingData brand)
    {
        try
        {
            var accent = ParseColor(brand.PrimaryColor, Color.FromRgb(0xFF, 0xD7, 0x00));
            var ink = ParseColor(brand.PrimaryTextColor, Color.FromRgb(0x1E, 0x1E, 0x2E));

            var res = Application.Current?.Resources;
            if (res == null) return;
            res["BrandBrush"] = new SolidColorBrush(accent);
            res["BrandTextBrush"] = new SolidColorBrush(ink);
            res["BrandColor"] = accent;
        }
        catch { }
    }

    public static Color ParseColor(string? hex, Color fallback)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(hex)) return fallback;
            var converted = ColorConverter.ConvertFromString(hex.Trim());
            return converted is Color c ? c : fallback;
        }
        catch
        {
            return fallback;
        }
    }

    /// Заголовок окна вида «НАШЕ Такси · Пульт оператора»
    public static string WindowTitle(string suffix)
        => $"{Current.ServiceName} · {suffix}";
}
