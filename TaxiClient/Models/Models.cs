namespace TaxiClient.Models;

public class LoginRequest
{
    public string Phone { get; set; } = string.Empty;
    public string Password { get; set; } = string.Empty;
}

public class RegisterRequest
{
    public string Phone { get; set; } = string.Empty;
    public string FirstName { get; set; } = string.Empty;
    public string LastName { get; set; } = string.Empty;
    public string Password { get; set; } = string.Empty;
    public string Role { get; set; } = "Client";
}

public class AuthResponse
{
    public Guid UserId { get; set; }
    public string Token { get; set; } = string.Empty;
    public string FirstName { get; set; } = string.Empty;
    public string LastName { get; set; } = string.Empty;
    public string Phone { get; set; } = string.Empty;
    public string Role { get; set; } = string.Empty;
}

public class OrderResponse
{
    public Guid Id { get; set; }
    public string OrderNumber { get; set; } = string.Empty;
    public string Status { get; set; } = string.Empty;
    public string StatusText { get; set; } = string.Empty;

    public string PickupAddress { get; set; } = string.Empty;
    public double PickupLatitude { get; set; }
    public double PickupLongitude { get; set; }
    public string? PickupEntrance { get; set; }

    public string? DestinationAddress { get; set; }
    public double? DestinationLatitude { get; set; }
    public double? DestinationLongitude { get; set; }

    public decimal EstimatedPrice { get; set; }
    public double? EstimatedDistance { get; set; }
    public int? EstimatedDuration { get; set; }
    public string TariffName { get; set; } = string.Empty;
    public string? Comment { get; set; }

    public PaymentInfoDto? Payment { get; set; }
    public DriverInfo? Driver { get; set; }
    public DateTime CreatedAt { get; set; }
}

public class DriverInfo
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
    public double? Latitude { get; set; }
    public double? Longitude { get; set; }
}

public class CreateOrderRequest
{
    public Guid ClientId { get; set; }
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
    public string PaymentMethod { get; set; } = "Cash";
}

public class PriceEstimate
{
    public decimal Price { get; set; }
    public double DistanceKm { get; set; }
    public int DurationMinutes { get; set; }
    public string TariffName { get; set; } = string.Empty;
}

public class CancelRequest
{
    public string Reason { get; set; } = string.Empty;
    public Guid CancelledByUserId { get; set; }
}

public class RateRequest
{
    public int Rating { get; set; }
    public string? Review { get; set; }
    public bool IsClient { get; set; } = true;
}

public class HistoryItem
{
    public string OrderNumber { get; set; } = "";
    public string StatusText { get; set; } = "";
    public string PickupAddress { get; set; } = "";
    public string? DestinationAddress { get; set; }
    public decimal EstimatedPrice { get; set; }
    public string TariffName { get; set; } = "";
    public string? DriverInfo { get; set; }
    public string TimeAgo { get; set; } = "";
}

public class ChatMessageDto
{
    public Guid Id { get; set; }
    public Guid OrderId { get; set; }
    public Guid SenderId { get; set; }
    public string SenderRole { get; set; } = "";
    public string SenderName { get; set; } = "";
    public string Text { get; set; } = "";
    public DateTime CreatedAt { get; set; }
    public bool IsRead { get; set; }
}
public class PaymentInfoDto
{
    public string Method { get; set; } = "Cash";
    public string? PaymentPhone { get; set; }
    public string? BankName { get; set; }
    public string? CardHolder { get; set; }
    public bool AcceptSbp { get; set; }
    public string? SbpLink { get; set; }
    public decimal Amount { get; set; }
}