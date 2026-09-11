using System.Globalization;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;
using TaxiClient.Models;

namespace TaxiClient.Services;

public class ApiService
{
    private readonly HttpClient _http;
    private static readonly JsonSerializerOptions _json = new()
    {
        PropertyNameCaseInsensitive = true
    };

    public AuthResponse? CurrentUser { get; private set; }

    public ApiService()
    {
        _http = new HttpClient
        {
            BaseAddress = new Uri("https://taxi.event72.ru/api/"),
            // Отмена и создание заказа тянут за собой SMS/уведомления —
            // 10 секунд не хватало, запрос рвался по таймауту
            Timeout = TimeSpan.FromSeconds(25)
        };
    }

    public void SetToken(string token)
    {
        _http.DefaultRequestHeaders.Authorization =
            new System.Net.Http.Headers.AuthenticationHeaderValue("Bearer", token);
    }

    /// Восстановление сессии из защищённого хранилища (авто-вход).
    public void RestoreSession(AuthResponse auth)
    {
        CurrentUser = auth;
        SetToken(auth.Token);
    }

    public async Task<AuthResponse> LoginAsync(string phone, string password)
    {
        var resp = await _http.PostAsJsonAsync("auth/login",
            new LoginRequest { Phone = phone, Password = password });

        if (!resp.IsSuccessStatusCode)
            throw new Exception(await resp.Content.ReadAsStringAsync());

        var auth = await resp.Content.ReadFromJsonAsync<AuthResponse>(_json)
            ?? throw new Exception("Пустой ответ");

        CurrentUser = auth;
        SetToken(auth.Token);

        // Сохраняем сессию в защищённом хранилище для авто-входа при следующем запуске
        try
        {
            await SecureStorage.SetAsync("token", auth.Token);
            await SecureStorage.SetAsync("last_phone", phone);
            await SecureStorage.SetAsync("user_id", auth.UserId.ToString());
            await SecureStorage.SetAsync("user_name", $"{auth.FirstName} {auth.LastName}".Trim());
            await SecureStorage.SetAsync("role", auth.Role ?? "Client");
        }
        catch { }

        return auth;
    }

    public async Task<AuthResponse> RegisterAsync(string phone, string firstName,
        string lastName, string password)
    {
        var resp = await _http.PostAsJsonAsync("auth/register", new RegisterRequest
        {
            Phone = phone,
            FirstName = firstName,
            LastName = lastName,
            Password = password,
            Role = "Client"
        });

        if (!resp.IsSuccessStatusCode)
            throw new Exception(await resp.Content.ReadAsStringAsync());

        var auth = await resp.Content.ReadFromJsonAsync<AuthResponse>(_json)
            ?? throw new Exception("Пустой ответ");

        CurrentUser = auth;
        SetToken(auth.Token);

        // Сохраняем сессию в защищённом хранилище для авто-входа при следующем запуске
        try
        {
            await SecureStorage.SetAsync("token", auth.Token);
            await SecureStorage.SetAsync("last_phone", phone);
            await SecureStorage.SetAsync("user_id", auth.UserId.ToString());
            await SecureStorage.SetAsync("user_name", $"{auth.FirstName} {auth.LastName}".Trim());
            await SecureStorage.SetAsync("role", auth.Role ?? "Client");
        }
        catch { }

        return auth;
    }

    /// Восстановление пароля: отправка SMS-кода.
    /// В демо-режиме (без sms.ru на сервере) код возвращается ответом.
    public async Task<string?> RequestPasswordResetAsync(string phone)
    {
        var resp = await _http.PostAsJsonAsync("auth/reset", new { action = "send", phone });
        var raw = await resp.Content.ReadAsStringAsync();
        if (!resp.IsSuccessStatusCode)
            throw new Exception(raw);
        using var doc = JsonDocument.Parse(raw);
        return doc.RootElement.TryGetProperty("devCode", out var d) && d.ValueKind == JsonValueKind.String
            ? d.GetString()
            : null;
    }

    /// Восстановление пароля: установка нового пароля по SMS-коду.
    public async Task ConfirmPasswordResetAsync(string phone, string code, string newPassword)
    {
        var resp = await _http.PostAsJsonAsync("auth/reset",
            new { action = "confirm", phone, code, newPassword });
        if (!resp.IsSuccessStatusCode)
            throw new Exception(await resp.Content.ReadAsStringAsync());
    }

    /// Время в пути по дорогам между двумя точками (минуты).
    /// Используется для «водитель приедет через N мин»: сервер считает
    /// маршрут через TomTom (с пробками) или OSRM.
    public async Task<int?> GetEtaMinutesAsync(
        double fromLat, double fromLng, double toLat, double toLng)
    {
        try
        {
            var points = string.Format(CultureInfo.InvariantCulture,
                "{0},{1};{2},{3}", fromLat, fromLng, toLat, toLng);
            var resp = await _http.GetAsync("route.php?points=" + Uri.EscapeDataString(points));
            if (!resp.IsSuccessStatusCode) return null;

            using var doc = JsonDocument.Parse(await resp.Content.ReadAsStringAsync());
            if (doc.RootElement.TryGetProperty("durationMinutes", out var d)
                && d.ValueKind == JsonValueKind.Number)
                return d.GetInt32();
        }
        catch { }
        return null;
    }

    /// Конфиг карты с сервера (админка → «API-ключи»): провайдер и ключ.
    public async Task<MapConfigDto?> GetMapConfigAsync()
    {
        try
        {
            return await _http.GetFromJsonAsync<MapConfigDto>("map-config.php", _json);
        }
        catch { return null; }
    }

    public async Task<List<PriceEstimate>> GetAllPricesAsync(
        double fromLat, double fromLng, double toLat, double toLng,
        IEnumerable<string>? options = null)
    {
        var url = string.Format(CultureInfo.InvariantCulture,
            "pricing/estimate-all?fromLat={0}&fromLng={1}&toLat={2}&toLng={3}",
            fromLat, fromLng, toLat, toLng);
        var optionCodes = (options ?? Enumerable.Empty<string>()).ToList();
        if (optionCodes.Count > 0)
            url += "&options=" + Uri.EscapeDataString(string.Join(",", optionCodes));

        var resp = await _http.GetAsync(url);
        if (!resp.IsSuccessStatusCode) return new();
        return await resp.Content.ReadFromJsonAsync<List<PriceEstimate>>(_json) ?? new();
    }

    /// Справочник опций заказа с актуальными ценами (GET /api/options).
    public async Task<List<OrderOptionInfo>> GetOrderOptionsAsync()
    {
        try
        {
            return await _http.GetFromJsonAsync<List<OrderOptionInfo>>("options", _json) ?? new();
        }
        catch { return new(); }
    }

    public async Task<OrderResponse?> CreateOrderAsync(CreateOrderRequest request)
    {
        // URL со слэшем: /api/orders — физический каталог на хостинге, и
        // mod_dir отвечал 301 с потерей POST-тела и уходом на http://,
        // который Android блокирует политикой cleartext (connection failure)
        var resp = await _http.PostAsJsonAsync("orders/", request);
        if (!resp.IsSuccessStatusCode)
            throw new Exception(await resp.Content.ReadAsStringAsync());
        return await resp.Content.ReadFromJsonAsync<OrderResponse>(_json);
    }

    public async Task<OrderResponse?> GetOrderAsync(Guid orderId)
    {
        var resp = await _http.GetAsync($"orders/{orderId}");
        if (!resp.IsSuccessStatusCode) return null;
        return await resp.Content.ReadFromJsonAsync<OrderResponse>(_json);
    }

    public async Task<List<HistoryItem>> GetHistoryAsync()
    {
        if (CurrentUser == null)
            return new List<HistoryItem>();

        var resp = await _http.GetAsync($"orders/history/{CurrentUser.UserId}");
        if (!resp.IsSuccessStatusCode)
            return new List<HistoryItem>();

        return await resp.Content.ReadFromJsonAsync<List<HistoryItem>>(_json)
               ?? new List<HistoryItem>();
    }

    /// Отмена заказа клиентом.
    /// Раньше результат игнорировался: экран очищался всегда, а на сервере
    /// заказ мог остаться активным (и висел у водителя). Теперь отмена
    /// подтверждается сервером, а ошибка возвращается наверх.
    public async Task<(bool Ok, string? Error)> CancelOrderAsync(
        Guid orderId, Guid userId, string reason)
    {
        var resp = await _http.PostAsJsonAsync($"orders/{orderId}/cancel", new CancelRequest
        {
            Reason = reason,
            CancelledByUserId = userId
        });

        if (resp.IsSuccessStatusCode)
        {
            // Сервер вернул карточку заказа — сверяем, что статус действительно
            // сменился на «отменён» (страховка от «тихого» несрабатывания)
            try
            {
                var raw = await resp.Content.ReadAsStringAsync();
                using var doc = JsonDocument.Parse(raw);
                if (doc.RootElement.TryGetProperty("status", out var st))
                {
                    var status = (st.GetString() ?? string.Empty)
                        .Replace("_", string.Empty).ToLowerInvariant();
                    if (status.Length > 0 && status != "cancelled")
                        return (false, "Сервер не подтвердил отмену (статус: " + st.GetString() + ")");
                }
            }
            catch { /* тело не разобрали — считаем успехом по HTTP-коду */ }
            return (true, null);
        }

        var body = await resp.Content.ReadAsStringAsync();
        try
        {
            using var doc = JsonDocument.Parse(body);
            if (doc.RootElement.TryGetProperty("error", out var err))
                return (false, err.GetString() ?? body);
        }
        catch { }
        return (false, string.IsNullOrWhiteSpace(body)
            ? $"Сервер ответил {(int)resp.StatusCode}"
            : body);
    }

    /// Отправка сообщения в чат заказа. senderId обязан совпадать с uid
    /// токена (иначе сервер отвечает 403), поэтому берём его из токена.
    public async Task<(bool Ok, string? Error)> SendChatMessageAsync(
        Guid orderId, Guid senderId, string senderRole, string text)
    {
        var uid = TokenUserId() ?? senderId;
        var resp = await _http.PostAsJsonAsync("chat/send", new
        {
            OrderId = orderId,
            SenderId = uid,
            SenderRole = senderRole,
            Text = text
        });
        if (resp.IsSuccessStatusCode) return (true, null);

        var raw = await resp.Content.ReadAsStringAsync();
        try
        {
            using var doc = JsonDocument.Parse(raw);
            if (doc.RootElement.TryGetProperty("error", out var err))
                return (false, err.GetString() ?? raw);
        }
        catch { }
        return (false, string.IsNullOrWhiteSpace(raw) ? $"Сервер ответил {(int)resp.StatusCode}" : raw);
    }

    /// uid пользователя из HMAC-токена сессии (payload до точки).
    public Guid? TokenUserId()
    {
        try
        {
            var token = _http.DefaultRequestHeaders.Authorization?.Parameter;
            if (string.IsNullOrWhiteSpace(token)) return null;
            var body = token.Split('.')[0].Replace('-', '+').Replace('_', '/');
            body = body.PadRight(body.Length + (4 - body.Length % 4) % 4, '=');
            using var doc = JsonDocument.Parse(Convert.FromBase64String(body));
            return doc.RootElement.TryGetProperty("uid", out var uid)
                && Guid.TryParse(uid.GetString(), out var parsed) ? parsed : null;
        }
        catch { return null; }
    }

    public async Task<List<ChatMessageDto>> GetChatMessagesAsync(Guid orderId)
    {
        var resp = await _http.GetAsync($"chat/{orderId}");
        if (!resp.IsSuccessStatusCode) return new();
        var messages = await resp.Content.ReadFromJsonAsync<List<ChatMessageDto>>(_json) ?? new();
        if (CurrentUser != null)
            await _http.PostAsync($"chat/{orderId}/read?userId={CurrentUser.UserId}", null);
        return messages;
    }

    public async Task RateOrderAsync(Guid orderId, int rating, string? review)
    {
        await _http.PostAsJsonAsync($"orders/{orderId}/rate", new RateRequest
        {
            Rating = rating,
            Review = review,
            IsClient = true
        });
    }
}

/// Конфиг карты, отдаётся /api/map-config.php (админка → «API и сервисы»).
public sealed class MapConfigDto
{
    [JsonPropertyName("provider")] public string Provider { get; set; } = string.Empty;
    [JsonPropertyName("apiKey")] public string? ApiKey { get; set; }
    [JsonPropertyName("configured")] public bool Configured { get; set; }
    [JsonPropertyName("center")] public double[]? Center { get; set; }

    public double CenterLat => Center is { Length: 2 } ? Center[0] : 57.1522;
    public double CenterLng => Center is { Length: 2 } ? Center[1] : 65.5272;
}