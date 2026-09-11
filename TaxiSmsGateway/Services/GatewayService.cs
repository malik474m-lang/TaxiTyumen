using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace TaxiSmsGateway.Services;

public sealed class SmsTask
{
    [JsonPropertyName("id")] public string Id { get; set; } = string.Empty;
    [JsonPropertyName("phone")] public string Phone { get; set; } = string.Empty;
    [JsonPropertyName("message")] public string Message { get; set; } = string.Empty;
}

public sealed class PollResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("messages")] public List<SmsTask> Messages { get; set; } = new();
    [JsonPropertyName("pollSeconds")] public int PollSeconds { get; set; } = 10;
}

/// <summary>
/// Связь с сервером такси: получение заданий на отправку SMS и подтверждение
/// результата. Устройство авторизуется собственным токеном из админки
/// (раздел «SMS-шлюз» → «Выдать новый токен»).
/// </summary>
public sealed class GatewayService
{
    private readonly HttpClient _http = new() { Timeout = TimeSpan.FromSeconds(30) };
    private static readonly JsonSerializerOptions Json = new() { PropertyNameCaseInsensitive = true };

    public string ServerUrl
    {
        get => Preferences.Get("server_url", "https://taxi.event72.ru");
        set => Preferences.Set("server_url", value.TrimEnd('/'));
    }

    public string Token
    {
        get => Preferences.Get("device_token", string.Empty);
        set => Preferences.Set("device_token", value.Trim());
    }

    public string DeviceName
    {
        get => Preferences.Get("device_name", DeviceInfo.Current.Model ?? "Android");
        set => Preferences.Set("device_name", value.Trim());
    }

    public string SimPhone
    {
        get => Preferences.Get("sim_phone", string.Empty);
        set => Preferences.Set("sim_phone", value.Trim());
    }

    public bool IsConfigured => !string.IsNullOrWhiteSpace(Token) && !string.IsNullOrWhiteSpace(ServerUrl);

    private string Endpoint(string query) => $"{ServerUrl}/api/sms-gateway.php{query}";

    private HttpRequestMessage Request(HttpMethod method, string query)
    {
        var request = new HttpRequestMessage(method, Endpoint(query));
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", Token);
        return request;
    }

    private static int BatteryPercent()
    {
        try { return (int)Math.Round(Battery.Default.ChargeLevel * 100); }
        catch { return -1; }
    }

    /// <summary>Забрать задания с сервера. Ошибка соединения — пустой список и текст ошибки.</summary>
    public async Task<(PollResponse? Data, string? Error)> PollAsync(CancellationToken ct = default)
    {
        try
        {
            var battery = BatteryPercent();
            var query = "?action=poll&limit=5"
                + "&device=" + Uri.EscapeDataString(DeviceName)
                + (battery >= 0 ? "&battery=" + battery : string.Empty)
                + (string.IsNullOrWhiteSpace(SimPhone) ? string.Empty : "&phone=" + Uri.EscapeDataString(SimPhone));

            using var request = Request(HttpMethod.Get, query);
            using var response = await _http.SendAsync(request, ct);
            var raw = await response.Content.ReadAsStringAsync(ct);

            if (!response.IsSuccessStatusCode)
                return (null, Explain(raw, (int)response.StatusCode));

            return (JsonSerializer.Deserialize<PollResponse>(raw, Json), null);
        }
        catch (OperationCanceledException) { throw; }
        catch (Exception ex) { return (null, ex.Message); }
    }

    /// <summary>Подтвердить результат отправки одного сообщения.</summary>
    public async Task<bool> AcknowledgeAsync(string id, bool success, string? error, CancellationToken ct = default)
    {
        try
        {
            using var request = Request(HttpMethod.Post, string.Empty);
            request.Content = JsonContent.Create(new
            {
                action = "ack",
                id,
                success,
                error = error ?? string.Empty,
                device = DeviceName,
            });
            using var response = await _http.SendAsync(request, ct);
            return response.IsSuccessStatusCode;
        }
        catch { return false; }
    }

    /// <summary>Проверка настроек: сервер отвечает и токен принят.</summary>
    public async Task<(bool Ok, string Message)> TestAsync(CancellationToken ct = default)
    {
        try
        {
            using var request = Request(HttpMethod.Get, "?action=status");
            using var response = await _http.SendAsync(request, ct);
            var raw = await response.Content.ReadAsStringAsync(ct);

            if (!response.IsSuccessStatusCode)
                return (false, Explain(raw, (int)response.StatusCode));

            using var doc = JsonDocument.Parse(raw);
            var enabled = doc.RootElement.TryGetProperty("enabled", out var e) && e.GetBoolean();
            return enabled
                ? (true, "Сервер отвечает, шлюз включён — можно принимать задания")
                : (false, "Токен принят, но SMS-шлюз выключен в админке");
        }
        catch (Exception ex) { return (false, ex.Message); }
    }

    private static string Explain(string raw, int code)
    {
        try
        {
            using var doc = JsonDocument.Parse(raw);
            if (doc.RootElement.TryGetProperty("error", out var err))
                return err.GetString() ?? $"Сервер ответил {code}";
        }
        catch { }
        return code switch
        {
            401 => "Неверный токен устройства",
            403 => "SMS-шлюз выключен в админке",
            _ => $"Сервер ответил {code}",
        };
    }
}
