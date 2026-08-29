using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using TaxiDriver.Models;

namespace TaxiDriver.Services;

// Polling-транспорт PHP-сервера с прежним публичным API SignalRService.
public class SignalRService
{
    private readonly HttpClient _http = new()
    {
        BaseAddress = new Uri("https://taxi.event72.ru/api/"),
        Timeout = TimeSpan.FromSeconds(12)
    };
    private readonly JsonSerializerOptions _json = new() { PropertyNameCaseInsensitive = true };
    private CancellationTokenSource? _cts;

    public event Action<NewOrderNotification>? NewOrderReceived;
    public event Action<NewOrderNotification>? ForceAssignedReceived;
    public event Action<string>? OrderStatusChanged;
    public event Action<object>? ChatMessageReceived;

    public bool IsConnected => _cts is { IsCancellationRequested: false };

    public Task ConnectAsync(string token)
    {
        _cts?.Cancel();
        _http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", token);
        _cts = new CancellationTokenSource();
        _ = Task.Run(() => PollAsync(_cts.Token));
        return Task.CompletedTask;
    }

    public Task SendLocationAsync(double lat, double lng, double? speed) => Task.CompletedTask;
    public Task SubscribeToOrderAsync(string orderId) => Task.CompletedTask;
    public Task DisconnectAsync() { _cts?.Cancel(); _cts = null; return Task.CompletedTask; }

    private async Task PollAsync(CancellationToken token)
    {
        while (!token.IsCancellationRequested)
        {
            try
            {
                using var response = await _http.GetAsync("notifications?unread=1&limit=50", token);
                if (response.IsSuccessStatusCode)
                {
                    using var doc = JsonDocument.Parse(await response.Content.ReadAsStringAsync(token));
                    if (doc.RootElement.TryGetProperty("items", out var items))
                    {
                        foreach (var item in items.EnumerateArray().Reverse())
                        {
                            Dispatch(item.Clone());
                            if (item.TryGetProperty("id", out var id))
                                await _http.PostAsJsonAsync("notifications", new { action = "read", id = id.GetString() }, token);
                        }
                    }
                }
            }
            catch (OperationCanceledException) { break; }
            catch { }
            try { await Task.Delay(TimeSpan.FromSeconds(3), token); }
            catch (OperationCanceledException) { break; }
        }
    }

    private void Dispatch(JsonElement item)
    {
        var type = item.TryGetProperty("type", out var t) ? t.GetString() : "";
        var title = item.TryGetProperty("title", out var ti) ? ti.GetString() ?? "Такси Тюмень" : "Такси Тюмень";
        var message = item.TryGetProperty("message", out var me) ? me.GetString() ?? "" : "";
        var payload = item.TryGetProperty("payload", out var p) && p.ValueKind == JsonValueKind.Object ? p.Clone() : item.Clone();
        switch (type)
        {
            case "NewOrderAvailable":
                if (payload.Deserialize<NewOrderNotification>(_json) is { } o) NewOrderReceived?.Invoke(o); break;
            case "ForceAssignedOrder":
                if (payload.Deserialize<NewOrderNotification>(_json) is { } a) ForceAssignedReceived?.Invoke(a); break;
            case "OrderStatusChanged": OrderStatusChanged?.Invoke(payload.ToString()); break;
            case "NewChatMessage": ChatMessageReceived?.Invoke(payload); break;
            case "AdminMessage":
                MainThread.BeginInvokeOnMainThread(async () =>
                {
                    var page = Application.Current?.MainPage;
                    if (page != null) await page.DisplayAlert(title, message, "OK");
                });
                break;
        }
    }
}
