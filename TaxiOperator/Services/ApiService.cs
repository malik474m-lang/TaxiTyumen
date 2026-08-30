using System.Net.Http;
using System.Net.Http.Json;
using System.Text.Json;
using TaxiOperator.Models;

namespace TaxiOperator.Services;

public class ApiService
{
    private readonly HttpClient _http;
    private static readonly JsonSerializerOptions _jsonOptions = new()
    {
        PropertyNameCaseInsensitive = true,
        Converters =
        {
            // метки сервера: ISO с 'Z'/мс и MySQL-формат — без падений десериализации
            new FlexibleDateTimeOffsetConverter(),
            new FlexibleNullableDateTimeOffsetConverter()
        }
    };

    public string? Token { get; private set; }
    public AuthResponse? CurrentUser { get; private set; }

    public ApiService()
    {
        _http = new HttpClient
        {
            BaseAddress = new Uri("https://taxi.event72.ru/api/")
        };
    }

    public void SetToken(string token)
    {
        Token = token;
        _http.DefaultRequestHeaders.Authorization =
            new System.Net.Http.Headers.AuthenticationHeaderValue("Bearer", token);
    }

    // ===== AUTH =====
    public async Task<AuthResponse?> LoginAsync(string phone, string password)
    {
        var response = await _http.PostAsJsonAsync("auth/login", new LoginRequest
        {
            Phone = phone,
            Password = password
        });

        if (!response.IsSuccessStatusCode)
        {
            var error = await response.Content.ReadAsStringAsync();
            throw new Exception($"Ошибка входа: {error}");
        }

        var auth = await response.Content.ReadFromJsonAsync<AuthResponse>(_jsonOptions);
        if (auth != null)
        {
            CurrentUser = auth;
            SetToken(auth.Token);
        }
        return auth;
    }

    // ===== ЗАКАЗЫ =====
    public async Task<List<OrderResponse>> GetActiveOrdersAsync()
    {
        var response = await _http.GetAsync("orders/active");
        if (!response.IsSuccessStatusCode) return new List<OrderResponse>();
        return await response.Content.ReadFromJsonAsync<List<OrderResponse>>(_jsonOptions)
               ?? new List<OrderResponse>();
    }

    public async Task<OrderResponse?> CreateOrderAsync(CreateOperatorOrderRequest request)
    {
        var response = await _http.PostAsJsonAsync("orders/operator", request);
        if (!response.IsSuccessStatusCode)
        {
            var error = await response.Content.ReadAsStringAsync();
            throw new Exception(error);
        }
        return await response.Content.ReadFromJsonAsync<OrderResponse>(_jsonOptions);
    }

    public async Task<bool> CancelOrderAsync(Guid orderId, string reason)
    {
        if (CurrentUser == null) return false;

        var response = await _http.PostAsJsonAsync($"orders/{orderId}/cancel", new CancelOrderRequest
        {
            Reason = reason,
            CancelledByUserId = CurrentUser.UserId
        });

        return response.IsSuccessStatusCode;
    }

    public async Task<List<PriceEstimate>> GetPriceEstimateAsync(
        double fromLat, double fromLng, double toLat, double toLng)
    {
        var url = $"pricing/estimate-all?fromLat={fromLat}&fromLng={fromLng}&toLat={toLat}&toLng={toLng}";
        var response = await _http.GetAsync(url);
        if (!response.IsSuccessStatusCode) return new List<PriceEstimate>();
        return await response.Content.ReadFromJsonAsync<List<PriceEstimate>>(_jsonOptions)
               ?? new List<PriceEstimate>();
    }

    // ===== ВОДИТЕЛИ =====
    public async Task<List<OnlineDriver>> GetOnlineDriversAsync()
    {
        var response = await _http.GetAsync("drivers/online");
        if (!response.IsSuccessStatusCode) return new List<OnlineDriver>();
        return await response.Content.ReadFromJsonAsync<List<OnlineDriver>>(_jsonOptions)
               ?? new List<OnlineDriver>();
    }

    // ===== ВОДИТЕЛИ (принудительное назначение) =====
    public async Task<OrderResponse?> ForceAssignDriverAsync(Guid orderId, Guid driverId)
    {
        var response = await _http.PostAsync(
            $"orders/{orderId}/force-assign?driverId={driverId}", null);

        if (!response.IsSuccessStatusCode)
        {
            var err = await response.Content.ReadAsStringAsync();
            throw new Exception(err);
        }

        return await response.Content.ReadFromJsonAsync<OrderResponse>(_jsonOptions);
    }

    // ===== СМЕНЫ =====
    public async Task StartShiftAsync()
    {
        if (CurrentUser == null) return;
        await _http.PostAsJsonAsync("operators/shift/start", new { OperatorId = CurrentUser.UserId });
    }

    public async Task EndShiftAsync()
    {
        if (CurrentUser == null) return;
        await _http.PostAsJsonAsync("operators/shift/end", new { OperatorId = CurrentUser.UserId });
    }

    // ===== БАЛАНС =====
    public async Task<BalanceInfo?> GetBalanceAsync(Guid driverId)
    {
        var response = await _http.GetAsync($"balance/{driverId}");
        if (!response.IsSuccessStatusCode) return null;

        return await response.Content.ReadFromJsonAsync<BalanceInfo>(_jsonOptions);
    }

    public async Task<List<BalanceTransactionDto>> GetBalanceHistoryAsync(Guid driverId)
    {
        var response = await _http.GetAsync($"balance/{driverId}/history");
        if (!response.IsSuccessStatusCode) return new List<BalanceTransactionDto>();

        return await response.Content.ReadFromJsonAsync<List<BalanceTransactionDto>>(_jsonOptions)
               ?? new List<BalanceTransactionDto>();
    }

    public async Task<decimal?> TopUpBalanceAsync(Guid driverId, decimal amount)
    {
        if (CurrentUser == null) return null;

        var response = await _http.PostAsJsonAsync($"balance/{driverId}/topup", new
        {
            Amount = amount,
            CreatedBy = $"{CurrentUser.FirstName} {CurrentUser.LastName}"
        });

        if (!response.IsSuccessStatusCode)
        {
            var err = await response.Content.ReadAsStringAsync();
            throw new Exception(err);
        }

        var json = await response.Content.ReadFromJsonAsync<JsonElement>();
        if (json.TryGetProperty("balance", out var balanceProp))
            return balanceProp.GetDecimal();

        return null;
    }

    // ===== УВЕДОМЛЕНИЯ PHP-СЕРВЕРА =====
    public async Task<List<NotificationDto>> GetNotificationsAsync()
    {
        var response = await _http.GetAsync("notifications?unread=1&limit=50");
        if (!response.IsSuccessStatusCode) return new();
        var result = await response.Content.ReadFromJsonAsync<NotificationListResponse>(_jsonOptions);
        return result?.Items ?? new();
    }

    public async Task MarkNotificationReadAsync(Guid id)
    {
        await _http.PostAsJsonAsync("notifications", new { action = "read", id });
    }
}