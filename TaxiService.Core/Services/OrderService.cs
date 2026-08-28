using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;
using TaxiService.Core.DTOs.Orders;
using TaxiService.Core.Helpers;
using TaxiService.Core.Interfaces;
using TaxiService.Domain.Entities;
using TaxiService.Domain.Enums;
using TaxiService.Infrastructure.Data;

namespace TaxiService.Core.Services;

public class OrderService : IOrderService
{
    private readonly TaxiDbContext _db;
    private readonly IPricingService _pricingService;
    private readonly INotificationService _notificationService;
    private readonly IDriverSearchService _driverSearchService;
    private readonly ILogger<OrderService> _logger;
    private readonly IBalanceService _balanceService;

    public OrderService(
        TaxiDbContext db,
        IPricingService pricingService,
        INotificationService notificationService,
        IDriverSearchService driverSearchService,
        ILogger<OrderService> logger,
        IBalanceService balanceService)
    {
        _db = db;
        _pricingService = pricingService;
        _notificationService = notificationService;
        _driverSearchService = driverSearchService;
        _logger = logger;
        _balanceService = balanceService;
    }

    public async Task<OrderResponse> CreateOrderAsync(CreateOrderRequest request)
    {
        decimal estimatedPrice = 0;
        double estimatedDistance = 0;
        int estimatedDuration = 0;

        if (request.DestinationLatitude.HasValue && request.DestinationLongitude.HasValue)
        {
            var estimate = await _pricingService.CalculatePriceAsync(
                request.PickupLatitude, request.PickupLongitude,
                request.DestinationLatitude.Value, request.DestinationLongitude.Value,
                request.Tariff);

            estimatedPrice = estimate.Price;
            estimatedDistance = estimate.DistanceKm;
            estimatedDuration = estimate.DurationMinutes;
        }

        var order = new Order
        {
            ClientId = request.ClientId,
            Source = OrderSource.ClientApp,
            PickupAddress = request.PickupAddress,
            PickupLatitude = request.PickupLatitude,
            PickupLongitude = request.PickupLongitude,
            PickupEntrance = request.PickupEntrance,
            DestinationAddress = request.DestinationAddress,
            DestinationLatitude = request.DestinationLatitude,
            DestinationLongitude = request.DestinationLongitude,
            Tariff = request.Tariff,
            EstimatedPrice = estimatedPrice,
            EstimatedDistance = estimatedDistance,
            EstimatedDuration = estimatedDuration,
            Comment = request.Comment,
            PassengerCount = request.PassengerCount,
            PaymentMethod = request.PaymentMethod,
            Status = OrderStatus.Searching,
            OrderNumber = OrderNumberGenerator.Generate()
        };

        await SaveOrderWithRetryAsync(order);

        _ = Task.Run(() => _driverSearchService.FindDriverAsync(order.Id));

        _logger.LogInformation("Создан клиентский заказ {OrderNumber}", order.OrderNumber);

        return await MapToResponseAsync(order.Id);
    }

    public async Task<OrderResponse> CreateOrderByOperatorAsync(CreateOperatorOrderRequest request)
    {
        decimal estimatedPrice = 0;
        double estimatedDistance = 0;
        int estimatedDuration = 0;

        if (request.DestinationLatitude.HasValue && request.DestinationLongitude.HasValue)
        {
            var estimate = await _pricingService.CalculatePriceAsync(
                request.PickupLatitude, request.PickupLongitude,
                request.DestinationLatitude.Value, request.DestinationLongitude.Value,
                request.Tariff);

            estimatedPrice = estimate.Price;
            estimatedDistance = estimate.DistanceKm;
            estimatedDuration = estimate.DurationMinutes;
        }

        // Если координаты назначения не указаны  ставим минимальную цену
        if (estimatedPrice == 0)
        {
            var tariff = await _db.Tariffs
                .FirstOrDefaultAsync(t => t.Type == request.Tariff && t.IsActive);

            if (tariff != null)
                estimatedPrice = tariff.MinimumFare;
        }

        var order = new Order
        {
            OperatorId = request.OperatorId,
            Source = OrderSource.OperatorApp,
            ClientPhone = request.ClientPhone,
            ClientName = request.ClientName,
            PickupAddress = request.PickupAddress,
            PickupLatitude = request.PickupLatitude,
            PickupLongitude = request.PickupLongitude,
            PickupEntrance = request.PickupEntrance,
            DestinationAddress = request.DestinationAddress,
            DestinationLatitude = request.DestinationLatitude,
            DestinationLongitude = request.DestinationLongitude,
            Tariff = request.Tariff,
            EstimatedPrice = estimatedPrice,
            EstimatedDistance = estimatedDistance,
            EstimatedDuration = estimatedDuration,
            Comment = request.Comment,
            PassengerCount = request.PassengerCount,
            Status = OrderStatus.Searching,
            OrderNumber = OrderNumberGenerator.Generate()
        };

        await SaveOrderWithRetryAsync(order);

        _ = Task.Run(() => _driverSearchService.FindDriverAsync(order.Id));

        _logger.LogInformation("Создан операторский заказ {OrderNumber}", order.OrderNumber);

        return await MapToResponseAsync(order.Id);
    }

    private async Task SaveOrderWithRetryAsync(Order order, int maxAttempts = 5)
    {
        for (int attempt = 1; attempt <= maxAttempts; attempt++)
        {
            try
            {
                _db.Orders.Add(order);
                await _db.SaveChangesAsync();

                _logger.LogInformation(
                    "Заказ {OrderNumber} сохранён (попытка {Attempt})",
                    order.OrderNumber, attempt);

                return;
            }
            catch (DbUpdateException ex) when (IsUniqueConstraintViolation(ex))
            {
                _db.Entry(order).State = EntityState.Detached;

                if (attempt == maxAttempts)
                {
                    _logger.LogError(ex,
                        "Не удалось сохранить заказ после {Attempts} попыток", maxAttempts);

                    throw new InvalidOperationException(
                        "Не удалось создать заказ. Повторите попытку.", ex);
                }

                order.OrderNumber = OrderNumberGenerator.Generate();

                _logger.LogWarning(
                    "Конфликт номера заказа. Новая попытка {Attempt}/{MaxAttempts}, номер {OrderNumber}",
                    attempt, maxAttempts, order.OrderNumber);

                await Task.Delay(100 * attempt);
            }
        }
    }

    private static bool IsUniqueConstraintViolation(DbUpdateException ex)
    {
        var msg = ex.InnerException?.Message ?? ex.Message;
        return msg.Contains("duplicate key", StringComparison.OrdinalIgnoreCase)
            || msg.Contains("unique constraint", StringComparison.OrdinalIgnoreCase)
            || msg.Contains("23505", StringComparison.OrdinalIgnoreCase);
    }

    public async Task<OrderResponse?> GetOrderAsync(Guid orderId)
    {
        var exists = await _db.Orders.AnyAsync(o => o.Id == orderId);
        if (!exists) return null;

        return await MapToResponseAsync(orderId);
    }

    public async Task<List<OrderResponse>> GetActiveOrdersAsync()
    {
        var ids = await _db.Orders
            .Where(o => o.Status != OrderStatus.Completed
                     && o.Status != OrderStatus.Cancelled)
            .OrderByDescending(o => o.CreatedAt)
            .Select(o => o.Id)
            .ToListAsync();

        var result = new List<OrderResponse>();
        foreach (var id in ids)
            result.Add(await MapToResponseAsync(id));

        return result;
    }

    public async Task<List<OrderResponse>> GetAvailableOrdersForDriverAsync(
        Guid driverId, double lat, double lng, double radiusKm = 10)
    {
        // Обновляем LastLocationUpdate чтобы водитель не вылетал из онлайн
        var driver = await _db.Drivers.FindAsync(driverId);
        if (driver != null)
        {
            driver.LastLocationUpdate = DateTime.UtcNow;
            driver.Latitude = lat;
            driver.Longitude = lng;
            await _db.SaveChangesAsync();
        }

        var orders = await _db.Orders
            .Where(o =>
                (o.Status == OrderStatus.Searching || o.Status == OrderStatus.NoDriverFound)
                && o.DriverId == null)
            .OrderBy(o => o.CreatedAt) // самые старые сверху
            .Select(o => o.Id)
            .ToListAsync();

        var result = new List<OrderResponse>();
        foreach (var orderId in orders)
            result.Add(await MapToResponseAsync(orderId));

        return result;
    }

    public async Task<OrderResponse> ForceAssignOrderAsync(Guid orderId, Guid driverId)
    {
        var order = await _db.Orders
            .Include(o => o.Driver)
            .FirstOrDefaultAsync(o => o.Id == orderId)
            ?? throw new InvalidOperationException("Заказ не найден");

        if (order.Status == OrderStatus.Completed || order.Status == OrderStatus.Cancelled)
            throw new InvalidOperationException("Заказ уже завершён или отменён");

        var newDriver = await _db.Drivers.FindAsync(driverId)
            ?? throw new InvalidOperationException("Водитель не найден");

        // Если заказ уже назначен на другого водителя  освобождаем его
        if (order.DriverId.HasValue && order.DriverId != driverId)
        {
            var oldDriver = order.Driver;
            if (oldDriver != null)
            {
                oldDriver.Status = DriverStatus.Available;
                oldDriver.CurrentOrderId = null;

                _logger.LogInformation(
                    "Заказ {OrderNumber} снят с водителя {OldDriverId} для переназначения",
                    order.OrderNumber, oldDriver.Id);
            }
        }

        order.DriverId = driverId;
        order.Status = OrderStatus.DriverAssigned;
        order.AcceptedAt = DateTime.UtcNow;

        newDriver.Status = DriverStatus.OnRoute;
        newDriver.CurrentOrderId = orderId;

        await _db.SaveChangesAsync();

        var fullOrder = await _db.Orders
            .Include(o => o.Driver).ThenInclude(d => d!.User)
            .FirstAsync(o => o.Id == orderId);

        await _notificationService.NotifyClientOrderAcceptedAsync(fullOrder);
        await _notificationService.NotifyOperatorsOrderUpdateAsync(fullOrder);

        // Уведомляем водителя о назначенном заказе
        await _notificationService.NotifyDriverForceAssignedAsync(driverId, fullOrder);

        _logger.LogInformation(
            "Заказ {OrderNumber} принудительно назначен на водителя {DriverId}",
            order.OrderNumber, driverId);

        return await MapToResponseAsync(orderId);
    }
        public async Task<OrderResponse> AcceptOrderAsync(Guid orderId, Guid driverId)
    {
        var order = await _db.Orders.FindAsync(orderId)
            ?? throw new InvalidOperationException("Заказ не найден");

        if (order.Status != OrderStatus.Searching && order.Status != OrderStatus.NoDriverFound)
            throw new InvalidOperationException("Заказ уже принят или недоступен");

        var driver = await _db.Drivers.FindAsync(driverId)
            ?? throw new InvalidOperationException("Водитель не найден");

        if (driver.Balance < driver.MinBalanceForOrders)
        {
            throw new InvalidOperationException(
                $"Недостаточно средств на балансе. Баланс: {driver.Balance:F0} руб., минимум: {driver.MinBalanceForOrders:F0} руб.");
        }

        order.DriverId = driverId;
        order.Status = OrderStatus.DriverAssigned;
        order.AcceptedAt = DateTime.UtcNow;

        driver.Status = DriverStatus.OnRoute;
        driver.CurrentOrderId = orderId;

        await _db.SaveChangesAsync();

        var fullOrder = await _db.Orders
            .Include(o => o.Driver).ThenInclude(d => d!.User)
            .FirstAsync(o => o.Id == orderId);

        await _notificationService.NotifyClientOrderAcceptedAsync(fullOrder);
        await _notificationService.NotifyOperatorsOrderUpdateAsync(fullOrder);

        _logger.LogInformation(
            "Заказ {OrderNumber} принят водителем {DriverId}",
            order.OrderNumber, driverId);

        return await MapToResponseAsync(orderId);
    }

    public async Task<OrderResponse> RejectOrderAsync(Guid orderId, Guid driverId, string? reason)
    {
        var order = await _db.Orders
            .Include(o => o.Driver)
            .FirstOrDefaultAsync(o => o.Id == orderId)
            ?? throw new InvalidOperationException("Заказ не найден");

        _db.OrderRejections.Add(new OrderRejection
        {
            OrderId = orderId,
            DriverId = driverId,
            Reason = reason
        });

        // Если заказ был назначен на этого водителя  снимаем
        if (order.DriverId == driverId)
        {
            order.DriverId = null;
            order.Status = OrderStatus.Searching;
            order.AcceptedAt = null;

            // Освобождаем водителя
            if (order.Driver != null)
            {
                order.Driver.Status = DriverStatus.Available;
                order.Driver.CurrentOrderId = null;
            }

            _logger.LogInformation(
                "Водитель {DriverId} отказался от назначенного заказа {OrderNumber}. Заказ возвращён в поиск.",
                driverId, order.OrderNumber);
        }

        await _db.SaveChangesAsync();

        // Уведомляем операторов об отказе
        await _notificationService.NotifyOperatorsOrderUpdateAsync(order);

        // Отдельное уведомление оператору с именем водителя
        try
        {
            var rejectedDriver = await _db.Drivers
                .Include(d => d.User)
                .FirstOrDefaultAsync(d => d.Id == driverId);

            if (rejectedDriver != null)
            {
                var driverName = $"{rejectedDriver.User.FirstName} {rejectedDriver.User.LastName}";
                await _notificationService.NotifyOperatorsDriverRejectedAsync(
                    order.OrderNumber, driverName, reason);
            }
        }
        catch { }

        // Штраф за отказ от заказа
        try
        {
            var driver = await _db.Drivers.FindAsync(driverId);
            if (driver != null)
            {
                var penalty = driver.RejectionPenalty;
                if (penalty > 0)
                {
                    await _balanceService.ChargePenaltyAsync(
                        driverId, orderId, penalty, "Штраф за отказ от заказа");

                    _logger.LogInformation(
                        "Штраф {Penalty} руб. списан с водителя {DriverId} за отказ от заказа {OrderId}",
                        penalty, driverId, orderId);
                }
            }
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "Не удалось списать штраф за отказ");
        }

        _logger.LogInformation(
            "Заказ {OrderId} отклонён водителем {DriverId}. Причина: {Reason}",
            orderId, driverId, reason);

        return await MapToResponseAsync(orderId);
    }

    public async Task<OrderResponse> UpdateOrderStatusAsync(Guid orderId, OrderStatus status)
    {
        var order = await _db.Orders
            .Include(o => o.Driver)
            .FirstOrDefaultAsync(o => o.Id == orderId)
            ?? throw new InvalidOperationException("Заказ не найден");

        order.Status = status;

        switch (status)
        {
            case OrderStatus.DriverArrived:
                order.DriverArrivedAt = DateTime.UtcNow;
                await _notificationService.NotifyClientDriverArrivedAsync(order);
                break;

            case OrderStatus.InProgress:
                order.TripStartedAt = DateTime.UtcNow;
                if (order.Driver != null)
                    order.Driver.Status = DriverStatus.InTrip;
                break;
        }

        await _db.SaveChangesAsync();
        await _notificationService.NotifyOperatorsOrderUpdateAsync(order);

        return await MapToResponseAsync(orderId);
    }

    public async Task<OrderResponse> CompleteOrderAsync(Guid orderId)
    {
        var order = await _db.Orders
            .Include(o => o.Driver)
            .FirstOrDefaultAsync(o => o.Id == orderId)
            ?? throw new InvalidOperationException("Заказ не найден");

        order.Status = OrderStatus.Completed;
        order.CompletedAt = DateTime.UtcNow;
        order.FinalPrice = order.EstimatedPrice;

        if (order.Driver != null)
        {
            order.Driver.Status = DriverStatus.Available;
            order.Driver.CurrentOrderId = null;
            order.Driver.CompletedTrips++;
            order.Driver.TotalEarnings += order.FinalPrice ?? 0;
            order.Driver.TodayEarnings += order.FinalPrice ?? 0;
        }

        await _db.SaveChangesAsync();

        if (order.DriverId.HasValue)
        {
            try
            {
                var tariff = await _db.Tariffs
                    .FirstOrDefaultAsync(t => t.Type == order.Tariff && t.IsActive);

                var commissionPercent = tariff?.CommissionPercent ?? 15m;

                await _balanceService.ChargeCommissionAsync(
                    order.DriverId.Value,
                    order.Id,
                    order.FinalPrice ?? order.EstimatedPrice,
                    commissionPercent);
            }
            catch (Exception ex)
            {
                _logger.LogWarning(ex,
                    "Не удалось списать комиссию за заказ {OrderId}", order.Id);
            }
        }

        await _notificationService.NotifyClientTripCompletedAsync(order);
        await _notificationService.NotifyOperatorsOrderUpdateAsync(order);

        _logger.LogInformation(
            "Заказ {OrderNumber} завершён. Итоговая сумма: {Price}",
            order.OrderNumber, order.FinalPrice);

        return await MapToResponseAsync(orderId);
    }

    public async Task<OrderResponse> CancelOrderAsync(Guid orderId, CancelOrderRequest request)
    {
        var order = await _db.Orders
            .Include(o => o.Driver)
            .FirstOrDefaultAsync(o => o.Id == orderId)
            ?? throw new InvalidOperationException("Заказ не найден");

        order.Status = OrderStatus.Cancelled;
        order.CancellationReason = request.Reason;
        order.CancelledAt = DateTime.UtcNow;
        order.CancelledByUserId = request.CancelledByUserId;

        if (order.Driver != null)
        {
            order.Driver.Status = DriverStatus.Available;
            order.Driver.CurrentOrderId = null;
        }

        await _db.SaveChangesAsync();
        await _notificationService.NotifyOperatorsOrderUpdateAsync(order);

        _logger.LogInformation(
            "Заказ {OrderNumber} отменён. Причина: {Reason}",
            order.OrderNumber, request.Reason);

        return await MapToResponseAsync(orderId);
    }

    public async Task<List<OrderListItem>> GetOrderHistoryAsync(
        Guid userId, int page = 1, int pageSize = 20)
    {
        var driver = await _db.Drivers.FirstOrDefaultAsync(d => d.UserId == userId);
        var driverId = driver?.Id;

        var orders = await _db.Orders
            .Include(o => o.Driver).ThenInclude(d => d!.User)
            .Include(o => o.Client)
            .Where(o => o.ClientId == userId || (driverId != null && o.DriverId == driverId))
            .OrderByDescending(o => o.CreatedAt)
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .ToListAsync();

        return orders.Select(o => new OrderListItem
        {
            Id = o.Id,
            OrderNumber = o.OrderNumber,
            Status = o.Status,
            StatusText = o.Status switch
            {
                OrderStatus.Completed => "Завершён",
                OrderStatus.Cancelled => "Отменён",
                OrderStatus.InProgress => "Поездка",
                OrderStatus.Searching => "Поиск водителя",
                _ => o.Status.ToString()
            },
            ClientInfo = o.Client != null
                ? $"{o.Client.FirstName} {o.Client.LastName}"
                : o.ClientName ?? "Клиент",
            ClientPhone = o.Client?.Phone ?? o.ClientPhone ?? "",
            PickupAddress = o.PickupAddress,
            DestinationAddress = o.DestinationAddress,
            DriverInfo = o.Driver != null
                ? $"{o.Driver.User.FirstName} {o.Driver.User.LastName}"
                : null,
            CarInfo = o.Driver != null
                ? CarDisplayHelper.Format(
                    o.Driver.CarColor,
                    o.Driver.CarBrand,
                    o.Driver.CarModel,
                    o.Driver.LicensePlate)
                : null,
            EstimatedPrice = o.EstimatedPrice,
            TariffName = o.Tariff switch
            {
                TariffType.Economy => "Эконом",
                TariffType.Comfort => "Комфорт",
                TariffType.Business => "Бизнес",
                TariffType.Minivan => "Минивэн",
                _ => "Эконом"
            },
            CreatedAt = o.CreatedAt,
            TimeAgo = GetTimeAgo(o.CreatedAt)
        }).ToList();
    }

    public async Task<OrderResponse> RateOrderAsync(Guid orderId, RateOrderRequest request)
    {
        var order = await _db.Orders
            .Include(o => o.Driver)
            .FirstOrDefaultAsync(o => o.Id == orderId)
            ?? throw new InvalidOperationException("Заказ не найден");

        if (request.IsClient)
        {
            order.ClientRating = request.Rating;
            order.ClientReview = request.Review;

            if (order.Driver != null)
            {
                var ratings = await _db.Orders
                    .Where(o => o.DriverId == order.DriverId && o.ClientRating != null)
                    .Select(o => (double)o.ClientRating!)
                    .ToListAsync();

                order.Driver.Rating = ratings.Any() ? ratings.Average() : 5.0;
            }
        }
        else
        {
            order.DriverRating = request.Rating;
            order.DriverReview = request.Review;
        }

        await _db.SaveChangesAsync();

        return await MapToResponseAsync(orderId);
    }

    private async Task<OrderResponse> MapToResponseAsync(Guid orderId)
    {
        var o = await _db.Orders
            .Include(x => x.Client)
            .Include(x => x.Driver).ThenInclude(d => d!.User)
            .FirstAsync(x => x.Id == orderId);

        return new OrderResponse
        {
            Id = o.Id,
            OrderNumber = o.OrderNumber,
            Status = o.Status,
            ClientName = o.Client != null
                ? $"{o.Client.FirstName} {o.Client.LastName}"
                : o.ClientName,
            ClientPhone = o.Client?.Phone ?? o.ClientPhone,
            PickupAddress = o.PickupAddress,
            PickupLatitude = o.PickupLatitude,
            PickupLongitude = o.PickupLongitude,
            PickupEntrance = o.PickupEntrance,
            DestinationAddress = o.DestinationAddress,
            DestinationLatitude = o.DestinationLatitude,
            DestinationLongitude = o.DestinationLongitude,
            Tariff = o.Tariff,
            EstimatedPrice = o.EstimatedPrice,
            FinalPrice = o.FinalPrice,
            EstimatedDistance = o.EstimatedDistance,
            EstimatedDuration = o.EstimatedDuration,
            Comment = o.Comment,
            Source = o.Source,
            PaymentMethod = o.PaymentMethod,
            CreatedAt = o.CreatedAt,
            AcceptedAt = o.AcceptedAt,
            CompletedAt = o.CompletedAt,
            Payment = o.Driver != null ? new PaymentInfo
            {
                Method = o.PaymentMethod.ToString(),
                PaymentPhone = o.Driver.PaymentPhone,
                BankName = o.Driver.PaymentBankName,
                CardHolder = o.Driver.PaymentCardHolder,
                AcceptSbp = o.Driver.AcceptSbp,
                SbpLink = !string.IsNullOrEmpty(o.Driver.PaymentPhone)
                    ? $"https://qr.nspk.ru/AS1000{o.Driver.PaymentPhone.Replace("+","").Replace(" ","").Replace("-","")}"
                    : null,
                Amount = o.FinalPrice ?? o.EstimatedPrice
            } : null,
            Driver = o.Driver == null ? null : new DriverShortInfo
            {
                DriverId = o.Driver.Id,
                FullName = $"{o.Driver.User.FirstName} {o.Driver.User.LastName}",
                Phone = o.Driver.User.Phone,
                CarBrand = o.Driver.CarBrand,
                CarModel = o.Driver.CarModel,
                CarColor = CarColorHelper.Translate(o.Driver.CarColor),
                LicensePlate = LicensePlateHelper.Format(o.Driver.LicensePlate),
                CarDisplay = CarDisplayHelper.Format(
                    o.Driver.CarColor,
                    o.Driver.CarBrand,
                    o.Driver.CarModel,
                    o.Driver.LicensePlate),
                Rating = o.Driver.Rating,
                Latitude = o.Driver.Latitude,
                Longitude = o.Driver.Longitude
            }
        };
    }

    private static string GetTimeAgo(DateTime dateTime)
    {
        var diff = DateTime.UtcNow - dateTime;

        if (diff.TotalMinutes < 1)
            return "только что";

        if (diff.TotalMinutes < 60)
            return $"{(int)diff.TotalMinutes} мин назад";

        if (diff.TotalHours < 24)
            return $"{(int)diff.TotalHours} ч назад";

        return $"{(int)diff.TotalDays} д назад";
    }
}