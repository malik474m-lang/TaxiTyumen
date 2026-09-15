using System.IO;
using System.Text.Json;

namespace TaxiOperator.Services;

/// Настройки SIP-софтфона оператора. Хранятся локально в профиле пользователя
/// (%APPDATA%\TaxiTyumen\sip.json) — у каждого оператора свой внутренний номер.
public class SipSettings
{
    public bool Enabled { get; set; }
    public string Server { get; set; } = "";        // sip.example.ru или IP АТС
    public string Username { get; set; } = "";      // внутренний номер (100, 101…)
    public string Password { get; set; } = "";
    public string DisplayName { get; set; } = "Оператор";
    public int ExpirySeconds { get; set; } = 120;

    /// Индексы устройств гарнитуры (-1 = устройство Windows по умолчанию)
    public int InputDeviceIndex { get; set; } = -1;
    public int OutputDeviceIndex { get; set; } = -1;

    /// Автоответ на входящий звонок (режим «оператор в наушниках всегда на линии»)
    public bool AutoAnswer { get; set; }
    /// Подставлять номер звонящего в форму нового заказа
    public bool AutoFillPhone { get; set; } = true;

    private static string FilePath
    {
        get
        {
            var dir = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData),
                "TaxiTyumen");
            Directory.CreateDirectory(dir);
            return Path.Combine(dir, "sip.json");
        }
    }

    public static SipSettings Load()
    {
        try
        {
            if (File.Exists(FilePath))
            {
                var json = File.ReadAllText(FilePath);
                return JsonSerializer.Deserialize<SipSettings>(json) ?? new SipSettings();
            }
        }
        catch { }
        return new SipSettings();
    }

    public void Save()
    {
        try
        {
            File.WriteAllText(FilePath,
                JsonSerializer.Serialize(this, new JsonSerializerOptions { WriteIndented = true }));
        }
        catch { }
    }
}
