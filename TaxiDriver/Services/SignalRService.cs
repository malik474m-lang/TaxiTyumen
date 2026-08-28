using Microsoft.AspNetCore.SignalR.Client;
using TaxiDriver.Models;

namespace TaxiDriver.Services;

public class SignalRService
{
    private HubConnection? _connection;

    public event Action<NewOrderNotification>? NewOrderReceived;
    public event Action<NewOrderNotification>? ForceAssignedReceived;
    public event Action<string>? OrderStatusChanged;
    public event Action<object>? ChatMessageReceived;

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

        // Обычный новый заказ  только в список
        _connection.On<NewOrderNotification>("NewOrderAvailable", (order) =>
        {
            NewOrderReceived?.Invoke(order);
        });

        // Принудительное назначение оператором  popup
        _connection.On<NewOrderNotification>("ForceAssignedOrder", (order) =>
        {
            ForceAssignedReceived?.Invoke(order);
        });

        // Изменение статуса заказа
        _connection.On<object>("OrderStatusChanged", (data) =>
        {
            OrderStatusChanged?.Invoke(data?.ToString() ?? "");
        });

                _connection.On<object>("NewChatMessage", (msg) =>
        {
            ChatMessageReceived?.Invoke(msg);
        });

        await _connection.StartAsync();
    }

    public async Task SendLocationAsync(double lat, double lng, double? speed)
    {
        if (!IsConnected) return;
        await _connection!.InvokeAsync("UpdateDriverLocation", lat, lng, speed);
    }

    public async Task SubscribeToOrderAsync(string orderId)
    {
        if (!IsConnected) return;
        await _connection!.InvokeAsync("SubscribeToOrder", orderId);
    }

    public async Task DisconnectAsync()
    {
        if (_connection != null)
            await _connection.StopAsync();
    }
}