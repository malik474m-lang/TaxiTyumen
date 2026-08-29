using System.Globalization;
using System.Net.Http.Json;
using System.Text.Json;
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
            Timeout = TimeSpan.FromSeconds(10)
        };
    }

    public void SetToken(string token)
    {
        _http.DefaultRequestHeaders.Authorization =
            new System.Net.Http.Headers.AuthenticationHeaderValue("Bearer", token);
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
        return auth;
    }

    public async Task<List<PriceEstimate>> GetAllPricesAsync(
        double fromLat, double fromLng, double toLat, double toLng)
    {
        var url = string.Format(CultureInfo.InvariantCulture,
            "pricing/estimate-all?fromLat={0}&fromLng={1}&toLat={2}&toLng={3}",
            fromLat, fromLng, toLat, toLng);

        var resp = await _http.GetAsync(url);
        if (!resp.IsSuccessStatusCode) return new();
        return await resp.Content.ReadFromJsonAsync<List<PriceEstimate>>(_json) ?? new();
    }

    public async Task<OrderResponse?> CreateOrderAsync(CreateOrderRequest request)
    {
        var resp = await _http.PostAsJsonAsync("orders", request);
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

    public async Task CancelOrderAsync(Guid orderId, Guid userId, string reason)
    {
        await _http.PostAsJsonAsync($"orders/{orderId}/cancel", new CancelRequest
        {
            Reason = reason,
            CancelledByUserId = userId
        });
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