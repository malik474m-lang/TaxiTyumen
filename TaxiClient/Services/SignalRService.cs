using Microsoft.AspNetCore.SignalR.Client;

namespace TaxiClient.Services;

public class SignalRService
{
    private HubConnection? _connection;

    public event Action<string, object?>? OrderStatusChanged;
    public event Action<double, double>? DriverLocationUpdated;
    public event Action<object>? ChatMessageReceived;
    public event Action<object>? DriverArrivedNotification;

    public bool IsConnected =>
        _connection?.State == HubConnectionState.Connected;

    public async Task ConnectAsync(string token)
    {
        _connection = new HubConnectionBuilder()
            .WithUrl("http://localhost:5172/hubs/taxi", options =>
            {
                options.AccessTokenProvider = () => Task.FromResult<string?>(token);
            })
            .WithAutomaticReconnect()
            .Build();

        _connection.On<object>("OrderStatusChanged", (data) =>
        {
            var json = data.ToString() ?? "";
            OrderStatusChanged?.Invoke("StatusChanged", data);
        });

        _connection.On<object>("DriverLocationUpdated", (data) =>
        {
            try
            {
                var jsonDoc = System.Text.Json.JsonDocument.Parse(data.ToString()!);
                var lat = jsonDoc.RootElement.GetProperty("latitude").GetDouble();
                var lng = jsonDoc.RootElement.GetProperty("longitude").GetDouble();
                DriverLocationUpdated?.Invoke(lat, lng);
            }
            catch { }
        });

                _connection.On<object>("DriverArrivedNotification", (data) =>
        {
            DriverArrivedNotification?.Invoke(data);
        });

        _connection.On<object>("NewChatMessage", (msg) =>
        {
            ChatMessageReceived?.Invoke(msg);
        });

        await _connection.StartAsync();
    }

    public async Task SubscribeToOrderAsync(string orderId)
    {
        if (IsConnected)
            await _connection!.InvokeAsync("SubscribeToOrder", orderId);
    }

    public async Task SubscribeToDriverAsync(string driverId)
    {
        if (IsConnected)
            await _connection!.InvokeAsync("SubscribeToDriver", driverId);
    }
}