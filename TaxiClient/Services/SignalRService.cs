using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;

namespace TaxiClient.Services;

// Realtime через PHP-хостинг (polling notifications) с прежним публичным API.
public class SignalRService
{
    private readonly HttpClient _http = new()
    {
        BaseAddress = new Uri("https://taxi.event72.ru/api/"),
        Timeout = TimeSpan.FromSeconds(12)
    };
    private CancellationTokenSource? _cts;
    private string? _orderId;

    public event Action<string, object?>? OrderStatusChanged;
    public event Action<double, double>? DriverLocationUpdated;
    public event Action<object>? ChatMessageReceived;
    public event Action<object>? DriverArrivedNotification;

    public bool IsConnected => _cts is { IsCancellationRequested: false };

    public Task ConnectAsync(string token)
    {
        _cts?.Cancel();
        _http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", token);
        _cts = new CancellationTokenSource();
        _ = Task.Run(() => PollAsync(_cts.Token));
        return Task.CompletedTask;
    }

    public Task SubscribeToOrderAsync(string orderId) { _orderId = orderId; return Task.CompletedTask; }
    public Task SubscribeToDriverAsync(string driverId) => Task.CompletedTask;

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
                if (!string.IsNullOrEmpty(_orderId))
                {
                    using var resp = await _http.GetAsync($"orders/{_orderId}", token);
                    if (resp.IsSuccessStatusCode)
                    {
                        using var doc = JsonDocument.Parse(await resp.Content.ReadAsStringAsync(token));
                        if (doc.RootElement.TryGetProperty("driver", out var driver)
                            && driver.ValueKind == JsonValueKind.Object
                            && driver.TryGetProperty("latitude", out var lat)
                            && driver.TryGetProperty("longitude", out var lng))
                            DriverLocationUpdated?.Invoke(lat.GetDouble(), lng.GetDouble());
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
        object payload = item.TryGetProperty("payload", out var p) && p.ValueKind != JsonValueKind.Null ? p.Clone() : item.Clone();
        switch (type)
        {
            case "OrderStatusChanged": OrderStatusChanged?.Invoke("StatusChanged", payload); break;
            case "DriverArrivedNotification":
                DriverArrivedNotification?.Invoke(payload);
                OrderStatusChanged?.Invoke("StatusChanged", payload);
                break;
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
