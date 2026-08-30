namespace TaxiOperator.Models;

public class OrderResponse
{
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

    public string Tariff { get; set; } = "Economy";
    public string? Comment { get; set; }
    public int PassengerCount { get; set; } = 1;
}

public class CancelOrderRequest
{
    public string Reason { get; set; } = string.Empty;
    public Guid CancelledByUserId { get; set; }
}

public class PriceEstimate
{
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