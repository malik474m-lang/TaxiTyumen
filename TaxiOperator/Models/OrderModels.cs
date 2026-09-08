namespace TaxiOperator.Models;

public class OrderResponse
{
    public DateTimeOffset? ScheduledAt { get; set; }
    public bool IsPreorder { get; set; }
    public decimal PreorderSurcharge { get; set; }
    public decimal StopsSurcharge { get; set; }
    /// Суммарная надбавка за выбранные опции (уже включена в Price).
    public decimal OptionsTotal { get; set; }
    public decimal TariffPrice { get; set; }
    public decimal TotalPrice { get; set; }
    public decimal WaitingCost { get; set; }
    public int WaitingSeconds { get; set; }
    public string? DestinationEntrance { get; set; }
    public Guid Id { get; set; }
    public string OrderNumber { get; set; } = string.Empty;
    public string Status { get; set; } = string.Empty;
    public string StatusText { get; set; } = string.Empty;

    public string? ClientName { get; set; }
    public string? ClientPhone { get; set; }

    public string PickupAddress { get; set; } = string.Empty;
    public string? PickupEntrance { get; set; }
    public string? DestinationAddress { get; set; }

    public string TariffName { get; set; } = string.Empty;
    public decimal EstimatedPrice { get; set; }
    public decimal? FinalPrice { get; set; }
    public double? EstimatedDistance { get; set; }
    public int? EstimatedDuration { get; set; }

    public DriverShortInfo? Driver { get; set; }
    public DateTimeOffset CreatedAt { get; set; }
    public string? Comment { get; set; }
    public string Source { get; set; } = string.Empty;
}

public class DriverShortInfo
{
    public Guid DriverId { get; set; }
    public string FullName { get; set; } = string.Empty;
    public string Phone { get; set; } = string.Empty;
    public string CarBrand { get; set; } = string.Empty;
    public string CarModel { get; set; } = string.Empty;
    public string CarColor { get; set; } = string.Empty;
    public string LicensePlate { get; set; } = string.Empty;
    public string CarDisplay { get; set; } = string.Empty;
    public double Rating { get; set; }
}

public class CreateOperatorOrderRequest
{
    public Guid OperatorId { get; set; }
    public string ClientPhone { get; set; } = string.Empty;
    public string ClientName { get; set; } = string.Empty;

    public string PickupAddress { get; set; } = string.Empty;
    public double PickupLatitude { get; set; }
    public double PickupLongitude { get; set; }
    public string? PickupEntrance { get; set; }

    public string? DestinationAddress { get; set; }
    public double? DestinationLatitude { get; set; }
    public double? DestinationLongitude { get; set; }
    public string? DestinationEntrance { get; set; }

    public string Tariff { get; set; } = "Economy";
    public string? Comment { get; set; }
    public int PassengerCount { get; set; } = 1;

    /// Промежуточные адреса маршрута в порядке следования.
    public List<IntermediatePointRequest> IntermediatePoints { get; set; } = new();

    /// Поездка «туда и обратно»: возврат клиента на адрес подачи.
    public bool RoundTrip { get; set; }

    /// Предварительный заказ: дата и время подачи (null — заказ сейчас).
    public DateTime? ScheduledAt { get; set; }

    /// Опции заказа: child_seat, pet, meeting_sign, extra_luggage, non_smoking.
    public List<string> Options { get; set; } = new();
}

public class IntermediatePointRequest
{
    public string Address { get; set; } = string.Empty;
    public double Latitude { get; set; }
    public double Longitude { get; set; }
}

public class ClientLookupResult
{
    public bool Found { get; set; }
    public string? Name { get; set; }
    public string? FirstName { get; set; }
    public bool IsBlocked { get; set; }
    public int CompletedTrips { get; set; }
    public string? LastPickupAddress { get; set; }
    public string? LastPickupEntrance { get; set; }
    public string? LastDestinationAddress { get; set; }
}

public class CancelOrderRequest
{
    public string Reason { get; set; } = string.Empty;
    public Guid CancelledByUserId { get; set; }
}

public class PriceEstimate
{
    public decimal PreorderSurcharge { get; set; }
    public decimal StopsSurcharge { get; set; }
    /// Суммарная надбавка за опции заказа (сервер уже включил её в Price).
    public decimal OptionsTotal { get; set; }
    public decimal Price { get; set; }
    public double DistanceKm { get; set; }
    public int DurationMinutes { get; set; }
    public string TariffName { get; set; } = string.Empty;
}

public class OnlineDriver
{
    public Guid Id { get; set; }
    public string FullName { get; set; } = string.Empty;
    public string Phone { get; set; } = string.Empty;
    public string CarBrand { get; set; } = string.Empty;
    public string CarModel { get; set; } = string.Empty;
    public string CarColor { get; set; } = string.Empty;
    public string LicensePlate { get; set; } = string.Empty;
    public string CarDisplay { get; set; } = string.Empty;
    public string Status { get; set; } = string.Empty;
    public double Latitude { get; set; }
    public double Longitude { get; set; }
    public double Rating { get; set; }
    /// Скорость в м/с из GPS водителя (диспетчеру показываем км/ч).
    public double? Speed { get; set; }
    /// Текущий заказ водителя — видно, кто занят.
    public Guid? CurrentOrderId { get; set; }
}

public class BalanceInfo
{
    public decimal Balance { get; set; }
    public bool HasSufficientBalance { get; set; }
}

public class BalanceTransactionDto
{
    public Guid Id { get; set; }
    public string Type { get; set; } = string.Empty;
    public decimal Amount { get; set; }
    public decimal BalanceAfter { get; set; }
    public string Description { get; set; } = string.Empty;
    public DateTimeOffset CreatedAt { get; set; }

    public string AmountText => Amount >= 0 ? $"+{Amount:F0} ₽" : $"{Amount:F0} ₽";
    public string BalanceAfterText => $"{BalanceAfter:F0} ₽";
    public string TimeText => CreatedAt.ToLocalTime().ToString("dd.MM HH:mm");
}