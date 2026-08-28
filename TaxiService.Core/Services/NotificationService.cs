using TaxiService.Core.Helpers;
using TaxiService.Core.Interfaces;
using TaxiService.Domain.Entities;

namespace TaxiService.Core.Services;

public class NotificationService : INotificationService
{
    private readonly ISignalRNotifier _notifier;

    public NotificationService(ISignalRNotifier notifier)
    {
        _notifier = notifier;
    }

    public async Task NotifyDriverNewOrderAsync(Guid driverId, Order order)
    {
        await _notifier.SendToUserAsync(driverId.ToString(),
            "NewOrderAvailable", BuildOrderPayload(order));
    }

    public async Task NotifyAllDriversNewOrderAsync(Order order, List<Guid> driverIds)
    {
        var ids = driverIds.Select(id => id.ToString()).ToList();
        await _notifier.SendToUsersAsync(ids,
            "NewOrderAvailable", BuildOrderPayload(order));
    }

    public async Task NotifyClientOrderAcceptedAsync(Order order)
    {
        if (order.ClientId == null) return;

        object? driverData = null;
        if (order.Driver != null)
        {
            driverData = new
            {
                Name = order.Driver.User.FirstName + " " + order.Driver.User.LastName,
                Phone = order.Driver.User.Phone,
                CarBrand = order.Driver.CarBrand,
                CarModel = order.Driver.CarModel,
                CarColor = CarColorHelper.Translate(order.Driver.CarColor),
                LicensePlate = LicensePlateHelper.Format(order.Driver.LicensePlate),
                CarDisplay = CarDisplayHelper.Format(
                    order.Driver.CarColor,
                    order.Driver.CarBrand,
                    order.Driver.CarModel,
                    order.Driver.LicensePlate),
                Rating = order.Driver.Rating,
                Latitude = order.Driver.Latitude,
                Longitude = order.Driver.Longitude
            };
        }

        await _notifier.SendToUserAsync(order.ClientId.ToString()!,
            "OrderStatusChanged", new
            {
                OrderId = order.Id,
                Status = "DriverAssigned",
                Driver = driverData
            });
    }

    public async Task NotifyClientDriverArrivedAsync(Order order)
    {
        if (order.ClientId == null) return;

        await _notifier.SendToUserAsync(order.ClientId.ToString()!,
            "OrderStatusChanged", new
            {
                OrderId = order.Id,
                Status = "DriverArrived"
            });
    }

    public async Task NotifyClientTripCompletedAsync(Order order)
    {
        if (order.ClientId == null) return;

        await _notifier.SendToUserAsync(order.ClientId.ToString()!,
            "OrderStatusChanged", new
            {
                OrderId = order.Id,
                Status = "Completed",
                FinalPrice = order.FinalPrice ?? order.EstimatedPrice
            });
    }

    public async Task NotifyClientDriverLocationAsync(Guid orderId, double lat, double lng)
    {
        await _notifier.SendToGroupAsync("order-" + orderId,
            "DriverLocationUpdated", new
            {
                OrderId = orderId,
                Latitude = lat,
                Longitude = lng,
                Timestamp = DateTime.UtcNow
            });
    }

    public async Task NotifyOperatorsOrderUpdateAsync(Order order)
    {
        await _notifier.SendToGroupAsync("Operators",
            "OrderUpdated", new
            {
                OrderId = order.Id,
                OrderNumber = order.OrderNumber,
                Status = order.Status.ToString(),
                PickupAddress = order.PickupAddress,
                DestinationAddress = order.DestinationAddress
            });
    }

    public async Task NotifyOperatorsDriverRejectedAsync(
        string orderNumber, string driverName, string? reason)
    {
        await _notifier.SendToGroupAsync("Operators",
            "DriverRejectedOrder", new
            {
                OrderNumber = orderNumber,
                DriverName = driverName,
                Reason = reason ?? "Без причины",
                Timestamp = DateTime.UtcNow
            });
    }
        public async Task NotifyDriverForceAssignedAsync(Guid driverId, Order order)
    {
        await _notifier.SendToUserAsync(
            driverId.ToString(),
            "ForceAssignedOrder",
            new
            {
                OrderId = order.Id,
                OrderNumber = order.OrderNumber,
                PickupAddress = order.PickupAddress,
                DestinationAddress = order.DestinationAddress,
                EstimatedPrice = order.EstimatedPrice,
                Tariff = order.Tariff.ToString(),
                Comment = order.Comment,
                PassengerCount = order.PassengerCount,
                CreatedAt = order.CreatedAt
            });
    }
        private static object BuildOrderPayload(Order order)
    {
        return new
        {
            OrderId = order.Id,
            OrderNumber = order.OrderNumber,
            PickupAddress = order.PickupAddress,
            DestinationAddress = order.DestinationAddress,
            EstimatedPrice = order.EstimatedPrice,
            Tariff = order.Tariff.ToString(),
            Comment = order.Comment,
            PassengerCount = order.PassengerCount,
            CreatedAt = order.CreatedAt
        };
    }
}