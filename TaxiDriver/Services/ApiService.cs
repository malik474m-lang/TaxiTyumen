using System.Net.Http.Json;
using System.Text.Json;
using TaxiDriver.Models;

namespace TaxiDriver.Services;

public class ApiService
{
    private readonly HttpClient _http;
    private static readonly JsonSerializerOptions _json = new()
    {
        PropertyNameCaseInsensitive = true,
        Converters =
        {
            // метки сервера с миллисекундами/Z и MySQL-формат — без падений парсинга
            new FlexibleDateTimeOffsetConverter(),
            new FlexibleNullableDateTimeOffsetConverter()
        }
    };

    public AuthResponse? CurrentUser { get; private set; }
    public string? Token { get; private set; }

    public ApiService()
    {
        _http = new HttpClient
        {
            BaseAddress = new Uri("https://taxi.event72.ru/api/"),
            Timeout = TimeSpan.FromSeconds(10)
        };
    }

    public void SetToken(string token)
    {
        Token = token;
        _http.DefaultRequestHeaders.Authorization =
            new System.Net.Http.Headers.AuthenticationHeaderValue("Bearer", token);
    }

    public async Task<AuthResponse> LoginAsync(string phone, string password)
    {
        var response = await _http.PostAsJsonAsync("auth/login", new LoginRequest
        {
            Phone = phone,
            Password = password
        });

        if (!response.IsSuccessStatusCode)
        {
            var err = await response.Content.ReadAsStringAsync();
            throw new Exception($"Ошибка входа: {err}");
        }

        var auth = await response.Content.ReadFromJsonAsync<AuthResponse>(_json)
            ?? throw new Exception("Пустой ответ от сервера");

        CurrentUser = auth;
        SetToken(auth.Token);
        return auth;
    }

    public async Task<List<OrderResponse>> GetAvailableOrdersAsync(Guid driverId, double lat, double lng)
    {
        var latStr = lat.ToString(System.Globalization.CultureInfo.InvariantCulture);
        var lngStr = lng.ToString(System.Globalization.CultureInfo.InvariantCulture);

        var url = $"orders/available?driverId={driverId}&lat={latStr}&lng={lngStr}&radius=999";
        var response = await _http.GetAsync(url);

        if (!response.IsSuccessStatusCode)
            return new();

        return await response.Content.ReadFromJsonAsync<List<OrderResponse>>(_json) ?? new();
    }

    /// Геометрия маршрута ПО ДОРОГАМ через сервер (OSRM) + манёвры
    /// для голосового навигатора (один OSRM-запрос на сервере: шаги «в подарок»).
    /// Без геометрии линия рисовалась напрямую — через озёра и дворы.
    public async Task<RoadRouteResult?> GetRoadRouteAsync(
        IEnumerable<(double Lat, double Lng)> points)
    {
        try
        {
            var ci = System.Globalization.CultureInfo.InvariantCulture;
            var query = string.Join(";", points
                .Where(p => p.Lat != 0 && p.Lng != 0)
                .Select(p => p.Lat.ToString("F6", ci) + "," + p.Lng.ToString("F6", ci)));
            if (string.IsNullOrEmpty(query)) return null;

            var resp = await _http.GetFromJsonAsync<RoadRouteResponse>(
                $"route?points={Uri.EscapeDataString(query)}&steps=1", _json);
            if (resp?.Geometry is not { Count: > 1 }) return null;

            // byRoads=false — сервер вернул отрезок-заглушку (маршрутизатор
            // недоступен). Такую «прямую через озеро» не показываем.
            LastRouteByRoads = resp.ByRoads;
            if (!resp.ByRoads) return null;
            return new RoadRouteResult
            {
                Geometry = resp.Geometry,
                Steps = resp.Steps ?? new(),
            };
        }
        catch { return null; }
    }

    /// Построил ли сервер последний маршрут по дорогам.
    public bool LastRouteByRoads { get; private set; } = true;

    /// Результат маршрутизации: линия дороги + лента манёвров для озвучки.
    public class RoadRouteResult
    {
        public List<List<double>>? Geometry { get; set; }
        public List<RouteStep> Steps { get; set; } = new();
    }

    private class RoadRouteResponse
    {
        public List<List<double>>? Geometry { get; set; }
        public List<RouteStep>? Steps { get; set; }
        public bool ByRoads { get; set; }
    }

    /// Полная карточка заказа: координаты, промежуточные точки, актуальный статус.
    public async Task<OrderResponse?> GetOrderAsync(Guid orderId)
    {
        try
        {
            return await _http.GetFromJsonAsync<OrderResponse>($"orders/{orderId}", _json);
        }
        catch { return null; }
    }

    public async Task<OrderResponse?> AcceptOrderAsync(Guid orderId, Guid driverId)
    {
        var response = await _http.PostAsync($"orders/{orderId}/accept?driverId={driverId}", null);

        if (!response.IsSuccessStatusCode)
        {
            var err = await response.Content.ReadAsStringAsync();
            throw new Exception(err);
        }

        return await response.Content.ReadFromJsonAsync<OrderResponse>(_json);
    }

    public async Task RejectOrderAsync(Guid orderId, Guid driverId, string reason)
    {
        await _http.PostAsJsonAsync($"orders/{orderId}/reject?driverId={driverId}", reason);
    }

    /// Меняет этап заказа и возвращает подтверждённое состояние с сервера.
    /// UI не должен переключаться локально, пока сервер не ответил 2xx.
    public async Task<(bool Ok, string? Error, OrderResponse? Order)> UpdateStatusAsync(
        Guid orderId, Guid driverId, string status)
    {
        // POST + явный driverId: одинаково работает через PHP compatibility router
        // и исключает потерю профиля водителя при строковом JSON-теле статуса.
        var response = await _http.PostAsJsonAsync(
            $"orders/{orderId}/status?driverId={driverId}", status);
        var raw = await response.Content.ReadAsStringAsync();

        if (!response.IsSuccessStatusCode)
        {
            try
            {
                using var doc = JsonDocument.Parse(raw);
                var error = doc.RootElement.TryGetProperty("error", out var value)
                    ? value.GetString()
                    : null;
                return (false, error ?? $"HTTP {(int)response.StatusCode}", null);
            }
            catch
            {
                return (false, $"HTTP {(int)response.StatusCode}", null);
            }
        }

        try
        {
            return (true, null, JsonSerializer.Deserialize<OrderResponse>(raw, _json));
        }
        catch
        {
            return (true, null, null);
        }
    }

    /// Завершение поездки. Возвращает итоговый заказ с разбивкой стоимости.
    public async Task<OrderResponse?> CompleteOrderAsync(Guid orderId)
    {
        var response = await _http.PostAsync($"orders/{orderId}/complete", null);
        if (!response.IsSuccessStatusCode) return null;
        try
        {
            var raw = await response.Content.ReadAsStringAsync();
            return JsonSerializer.Deserialize<OrderResponse>(raw, _json);
        }
        catch
        {
            return null;
        }
    }

    /// Текущий заказ водителя — источник правды для счётчика ожидания.
    public async Task<OrderResponse?> GetCurrentOrderAsync(Guid driverId)
    {
        try
        {
            var response = await _http.GetAsync($"orders/current/{driverId}");
            if (!response.IsSuccessStatusCode) return null;
            var raw = await response.Content.ReadAsStringAsync();
            if (string.IsNullOrWhiteSpace(raw) || raw == "null") return null;
            return JsonSerializer.Deserialize<OrderResponse>(raw, _json);
        }
        catch
        {
            return null;
        }
    }

    /// Простой: запуск/остановка.
    /// Возвращает успех, текст ошибки сервера (если есть) и актуальный заказ.
    public async Task<(bool Ok, string? Error, OrderResponse? Order)> SetOrderWaitingAsync(
        Guid orderId, Guid driverId, bool start)
    {
        var action = start ? "waiting-start" : "waiting-stop";
        var resp = await _http.PostAsync($"orders/{orderId}/{action}?driverId={driverId}", null);
        var raw = await resp.Content.ReadAsStringAsync();
        if (!resp.IsSuccessStatusCode)
        {
            try
            {
                using var doc = System.Text.Json.JsonDocument.Parse(raw);
                var err = doc.RootElement.TryGetProperty("error", out var e)
                    ? e.GetString() : null;
                return (false, err ?? $"HTTP {(int)resp.StatusCode}", null);
            }
            catch
            {
                return (false, $"HTTP {(int)resp.StatusCode}", null);
            }
        }
        try
        {
            var order = System.Text.Json.JsonSerializer.Deserialize<OrderResponse>(raw, _json);
            return (true, null, order);
        }
        catch
        {
            return (true, null, null);
        }
    }

    public async Task UpdateLocationAsync(Guid driverId, UpdateLocationRequest request)
    {
        await _http.PutAsJsonAsync($"drivers/{driverId}/location", request);
    }

    public async Task SetOnlineAsync(Guid driverId, bool online)
    {
        var status = online ? "Available" : "Offline";
        await _http.PutAsJsonAsync($"drivers/{driverId}/status", status);
    }

    /// Отправка сообщения в чат заказа.
    /// senderId ОБЯЗАН совпадать с uid токена, иначе сервер отвечает 403
    /// «Нельзя писать от чужого имени» — поэтому берём id из самого токена.
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
        return (false, ExplainError(raw, resp.StatusCode));
    }

    /// uid (идентификатор пользователя) из HMAC-токена сессии:
    /// payload — base64url(JSON) до точки. Работает и после авто-входа,
    /// когда точный userId в приложении не сохранился.
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

    private static string ExplainError(string raw, System.Net.HttpStatusCode code)
    {
        try
        {
            using var doc = JsonDocument.Parse(raw);
            if (doc.RootElement.TryGetProperty("error", out var err))
                return err.GetString() ?? raw;
        }
        catch { }
        return string.IsNullOrWhiteSpace(raw) ? $"Сервер ответил {(int)code}" : raw;
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

    // ── Тревожная кнопка (SOS) ──────────────────────────────────────────────
    public async Task<SosAlertDto?> RaiseSosAsync(double lat, double lng, Guid? orderId, string? comment)
    {
        var resp = await _http.PostAsJsonAsync("sos", new
        {
            Latitude = lat,
            Longitude = lng,
            OrderId = orderId,
            Comment = comment
        });
        if (!resp.IsSuccessStatusCode) return null;
        return await resp.Content.ReadFromJsonAsync<SosAlertDto>(_json);
    }

    public async Task<List<SosAlertDto>> GetSosAlertsAsync()
    {
        var resp = await _http.GetAsync("sos");
        if (!resp.IsSuccessStatusCode) return new();
        return await resp.Content.ReadFromJsonAsync<List<SosAlertDto>>(_json) ?? new();
    }

    public async Task ResolveSosAsync(Guid alertId)
    {
        await _http.PostAsync($"sos/{alertId}/resolve", null);
    }

    public async Task SendFleetMessageAsync(string text)
    {
        await _http.PostAsJsonAsync("fleetchat/send", new { Text = text });
    }

    public async Task<List<FleetMessageDto>> GetFleetMessagesAsync(long afterMs = 0)
    {
        var resp = await _http.GetAsync($"fleetchat?after={afterMs}");
        if (!resp.IsSuccessStatusCode) return new();
        return await resp.Content.ReadFromJsonAsync<List<FleetMessageDto>>(_json) ?? new();
    }

    public async Task<DriverInfoDto?> GetDriverInfoAsync(Guid driverId)
    {
        var resp = await _http.GetAsync($"drivers/{driverId}");
        if (!resp.IsSuccessStatusCode) return null;
        return await resp.Content.ReadFromJsonAsync<DriverInfoDto>(_json);
    }

    public async Task<BalanceInfo?> GetBalanceAsync(Guid driverId)
    {
        var resp = await _http.GetAsync($"balance/{driverId}");
        if (!resp.IsSuccessStatusCode)
            return null;

        return await resp.Content.ReadFromJsonAsync<BalanceInfo>(_json);
    }

    public async Task<List<BalanceTransactionDto>> GetBalanceHistoryAsync(Guid driverId)
    {
        var resp = await _http.GetAsync($"balance/{driverId}/history");
        if (!resp.IsSuccessStatusCode)
            return new();

        return await resp.Content.ReadFromJsonAsync<List<BalanceTransactionDto>>(_json) ?? new();
    }

    /// Безналичный заработок и заявки на вывод самозанятого.
    public async Task<SberWalletDto> GetSberWalletAsync()
    {
        var resp = await _http.GetAsync("sber.php?action=wallet");
        var raw = await resp.Content.ReadAsStringAsync();
        if (!resp.IsSuccessStatusCode) throw new Exception(ApiError(raw));
        return JsonSerializer.Deserialize<SberWalletDto>(raw, _json) ?? new();
    }

    /// Пополнение рабочего баланса через Сбер: СБП, если одобрен, иначе карта.
    public async Task<SberStartDto> StartDriverTopupAsync(decimal amount)
    {
        var configResp = await _http.GetAsync("sber.php?action=config");
        var method = "card";
        if (configResp.IsSuccessStatusCode)
        {
            try
            {
                using var doc = JsonDocument.Parse(await configResp.Content.ReadAsStringAsync());
                if (doc.RootElement.TryGetProperty("sbpEnabled", out var s) && s.GetBoolean()) method = "sbp";
            }
            catch { }
        }
        var resp = await _http.PostAsJsonAsync("sber.php",
            new { action = "driver-topup", amount, method });
        var raw = await resp.Content.ReadAsStringAsync();
        if (!resp.IsSuccessStatusCode) throw new Exception(ApiError(raw));
        return JsonSerializer.Deserialize<SberStartDto>(raw, _json)
            ?? throw new Exception("Сбер не вернул ссылку оплаты");
    }

    public async Task RequestSberWithdrawalAsync(decimal amount, string phone, string bankName, string inn)
    {
        var resp = await _http.PostAsJsonAsync("sber.php",
            new { action = "withdraw", amount, phone, bankName, inn });
        var raw = await resp.Content.ReadAsStringAsync();
        if (!resp.IsSuccessStatusCode) throw new Exception(ApiError(raw));
    }

    /// Статус самозанятого в ФНС, привязка «Мой налог» и выданные чеки.
    public async Task<NpdStatusDto> GetNpdStatusAsync(string inn = "")
    {
        var url = "sber.php?action=npd-status";
        if (!string.IsNullOrWhiteSpace(inn)) url += "&inn=" + Uri.EscapeDataString(inn);
        var resp = await _http.GetAsync(url);
        var raw = await resp.Content.ReadAsStringAsync();
        if (!resp.IsSuccessStatusCode) throw new Exception(ApiError(raw));
        return JsonSerializer.Deserialize<NpdStatusDto>(raw, _json) ?? new();
    }

    /// Разрешить сервису формировать чеки от имени водителя.
    public async Task LinkNpdAsync(string inn, string password)
    {
        var resp = await _http.PostAsJsonAsync("sber.php",
            new { action = "npd-link", inn, password });
        var raw = await resp.Content.ReadAsStringAsync();
        if (!resp.IsSuccessStatusCode) throw new Exception(ApiError(raw));
    }

    public async Task UnlinkNpdAsync()
    {
        var resp = await _http.PostAsJsonAsync("sber.php", new { action = "npd-unlink" });
        if (!resp.IsSuccessStatusCode)
            throw new Exception(ApiError(await resp.Content.ReadAsStringAsync()));
    }

    private static string ApiError(string raw)
    {
        try
        {
            using var doc = JsonDocument.Parse(raw);
            if (doc.RootElement.TryGetProperty("error", out var e)) return e.GetString() ?? raw;
        }
        catch { }
        return raw;
    }
}

public sealed class SberStartDto
{
    public string Id { get; set; } = string.Empty;
    public string Status { get; set; } = string.Empty;
    public string? FormUrl { get; set; }
}

public sealed class SberWalletDto
{
    public decimal CashlessBalance { get; set; }
    public decimal PendingWithdrawal { get; set; }
    public decimal TotalCashlessEarned { get; set; }
    public decimal TotalPaidOut { get; set; }
    public List<SberWithdrawalDto> Withdrawals { get; set; } = new();
}

public sealed class NpdStatusDto
{
    public string? Inn { get; set; }
    public string? DisplayName { get; set; }
    public bool Linked { get; set; }
    public string? LastError { get; set; }
    public bool? NpdStatus { get; set; }
    public bool NpdChecked { get; set; }
    public string NpdMessage { get; set; } = string.Empty;
    public List<NpdReceiptDto> Receipts { get; set; } = new();
}

public sealed class NpdReceiptDto
{
    public string Id { get; set; } = string.Empty;
    public decimal Amount { get; set; }
    public string Status { get; set; } = string.Empty;
    public string Source { get; set; } = string.Empty;
    public string? PrintUrl { get; set; }
    public string? Error { get; set; }
    public string CreatedAt { get; set; } = string.Empty;
}

public sealed class SberWithdrawalDto
{
    public string Id { get; set; } = string.Empty;
    public decimal Amount { get; set; }
    public string Phone { get; set; } = string.Empty;
    public string BankName { get; set; } = string.Empty;
    public string Status { get; set; } = string.Empty;
    public string? Comment { get; set; }
    public string CreatedAt { get; set; } = string.Empty;
}