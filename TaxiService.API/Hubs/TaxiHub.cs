using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.SignalR;
using Microsoft.EntityFrameworkCore;
using System.Security.Claims;
using TaxiService.Core.Hubs;
using TaxiService.Domain.Enums;
using TaxiService.Infrastructure.Data;

namespace TaxiService.Core.Services;

[Authorize]
public class TaxiHub : Hub, ITaxiHub
{
    private readonly ILogger<TaxiHub> _logger;
    private readonly TaxiDbContext _db;

    public TaxiHub(ILogger<TaxiHub> logger, TaxiDbContext db)
    {
        _logger = logger;
        _db = db;
    }

    public override async Task OnConnectedAsync()
    {
        var userId = Context.UserIdentifier;
        var role = Context.User?.FindFirst("role")?.Value
                ?? Context.User?.FindFirst(ClaimTypes.Role)?.Value;
        var name = Context.User?.Identity?.Name;
        var driverId = Context.User?.FindFirst("driverId")?.Value;

        _logger.LogInformation(
            "SignalR подключился: Name={Name}, Role={Role}, UserId={UserId}, DriverId={DriverId}",
            name, role, userId, driverId);

        if (role == "Operator" || role == "Admin")
            await Groups.AddToGroupAsync(Context.ConnectionId, "Operators");

        if (role == "Driver")
        {
            await Groups.AddToGroupAsync(Context.ConnectionId, "Drivers");
            _logger.LogInformation("Водитель добавлен в группу Drivers. UserId={UserId}", userId);
        }

        await base.OnConnectedAsync();
    }

    public override async Task OnDisconnectedAsync(Exception? exception)
    {
        var userId = Context.UserIdentifier;
        var role = Context.User?.FindFirst("role")?.Value
                ?? Context.User?.FindFirst(ClaimTypes.Role)?.Value;
        var driverId = Context.User?.FindFirst("driverId")?.Value;

        _logger.LogInformation("SignalR отключился: UserId={UserId}, Role={Role}", userId, role);

        // Автоматически ставим водителя Offline
        if (role == "Driver" && !string.IsNullOrEmpty(driverId))
        {
            try
            {
                if (Guid.TryParse(driverId, out var dId))
                {
                    var driver = await _db.Drivers.FindAsync(dId);
                    if (driver != null && driver.Status != DriverStatus.Offline)
                    {
                        driver.Status = DriverStatus.Offline;
                        driver.CurrentOrderId = null;
                        await _db.SaveChangesAsync();

                        _logger.LogInformation(
                            "Водитель {DriverId} автоматически переведён в Offline при отключении SignalR",
                            driverId);
                    }
                }
            }
            catch (Exception ex)
            {
                _logger.LogWarning(ex, "Ошибка автоматического Offline для водителя {DriverId}", driverId);
            }
        }

        await base.OnDisconnectedAsync(exception);
    }

    public async Task UpdateDriverLocation(double latitude, double longitude, double? speed)
    {
        var userId = Context.UserIdentifier!;

        await Clients.Group($"driver-{userId}")
            .SendAsync("DriverLocationUpdated", new
            {
                DriverId = userId,
                Latitude = latitude,
                Longitude = longitude,
                Speed = speed,
                Timestamp = DateTime.UtcNow
            });

        await Clients.Group("Operators")
            .SendAsync("DriverMoved", new
            {
                DriverId = userId,
                Latitude = latitude,
                Longitude = longitude
            });
    }

    public async Task SubscribeToOrder(string orderId)
        => await Groups.AddToGroupAsync(Context.ConnectionId, $"order-{orderId}");

    public async Task UnsubscribeFromOrder(string orderId)
        => await Groups.RemoveFromGroupAsync(Context.ConnectionId, $"order-{orderId}");

    public async Task SubscribeToDriver(string driverId)
        => await Groups.AddToGroupAsync(Context.ConnectionId, $"driver-{driverId}");
}