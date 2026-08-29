using System.Net.Http.Json;
using System.Text.Json;
using TaxiDriver.Models;

namespace TaxiDriver.Services;

public class ApiService
{
    private readonly HttpClient _http;
    private static readonly JsonSerializerOptions _json = new()
    {
        PropertyNameCaseInsensitive = true
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

    public async Task UpdateStatusAsync(string orderId, string status)
    {
        await _http.PutAsJsonAsync("orders/" + orderId + "/status", status);
    }

    public async Task CompleteOrderAsync(Guid orderId)
    {
        await _http.PostAsync($"orders/{orderId}/complete", null);
    }

    public async Task CancelOrderAsync(Guid orderId, Guid driverId, string reason)
    {
        await _http.PostAsJsonAsync($"orders/{orderId}/cancel", new
        {
            Reason = reason,
            CancelledByUserId = driverId
        });
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

    public async Task SendChatMessageAsync(Guid orderId, Guid senderId, string senderRole, string text)
    {
        await _http.PostAsJsonAsync("chat/send", new
        {
            OrderId = orderId,
            SenderId = senderId,
            SenderRole = senderRole,
            Text = text
        });
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
}