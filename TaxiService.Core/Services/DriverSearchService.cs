using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;
using TaxiService.Core.Helpers;
using TaxiService.Core.Interfaces;
using TaxiService.Domain.Enums;
using TaxiService.Infrastructure.Data;

namespace TaxiService.Core.Services;

public class DriverSearchService : IDriverSearchService
{
    private readonly IServiceScopeFactory _scopeFactory;

    public DriverSearchService(IServiceScopeFactory scopeFactory)
    {
        _scopeFactory = scopeFactory;
    }

    public async Task FindDriverAsync(Guid orderId)
    {
        using var scope = _scopeFactory.CreateScope();
        var db = scope.ServiceProvider.GetRequiredService<TaxiDbContext>();
        var notifications = scope.ServiceProvider.GetRequiredService<INotificationService>();

        var order = await db.Orders.FindAsync(orderId);
        if (order == null || order.Status == OrderStatus.Cancelled) return;

        double searchRadiusKm = 5;
        int maxRounds = 4;

        for (int round = 0; round < maxRounds; round++)
        {
            var availableDrivers = await db.Drivers
                .Where(d => d.Status == DriverStatus.Available && d.IsVerified)
                .ToListAsync();

            var rejectedDriverIds = await db.OrderRejections
                .Where(r => r.OrderId == orderId)
                .Select(r => r.DriverId)
                .ToListAsync();

            var nearbyDrivers = availableDrivers
                .Where(d => !rejectedDriverIds.Contains(d.Id))
                .Select(d => new
                {
                    Driver = d,
                    Distance = DistanceCalculator.GetDistanceKm(
                        order.PickupLatitude, order.PickupLongitude,
                        d.Latitude, d.Longitude)
                })
                .Where(x => x.Distance <= searchRadiusKm)
                .OrderBy(x => x.Distance)
                .Take(3)
                .ToList();

            if (nearbyDrivers.Any())
            {
                var driverIds = nearbyDrivers.Select(x => x.Driver.Id).ToList();
                await notifications.NotifyAllDriversNewOrderAsync(order, driverIds);

                await Task.Delay(TimeSpan.FromSeconds(30));

                await db.Entry(order).ReloadAsync();
                if (order.DriverId != null)
                    return;
            }

            searchRadiusKm += 5;
        }

        order.Status = OrderStatus.NoDriverFound;
        await db.SaveChangesAsync();
    }
}