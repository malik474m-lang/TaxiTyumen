using TaxiService.Domain.Entities;

namespace TaxiService.Core.Interfaces;

public interface INotificationService
{
    Task NotifyDriverNewOrderAsync(Guid driverId, Order order);
    Task NotifyDriverForceAssignedAsync(Guid driverId, Order order);
    Task NotifyAllDriversNewOrderAsync(Order order, List<Guid> driverIds);
    Task NotifyClientOrderAcceptedAsync(Order order);
    Task NotifyClientDriverArrivedAsync(Order order);
    Task NotifyClientTripCompletedAsync(Order order);
    Task NotifyClientDriverLocationAsync(Guid orderId, double lat, double lng);
    Task NotifyOperatorsOrderUpdateAsync(Order order);
    Task NotifyOperatorsDriverRejectedAsync(string orderNumber, string driverName, string? reason);
}
