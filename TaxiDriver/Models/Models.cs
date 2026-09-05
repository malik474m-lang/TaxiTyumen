namespace TaxiDriver.Models;

public class LoginRequest
{
    public string Phone { get; set; } = string.Empty;
    public string Password { get; set; } = string.Empty;
}

public class AuthResponse
{
    public Guid UserId { get; set; }
    public string Token { get; set; } = string.Empty;
    public string FirstName { get; set; } = string.Empty;
    public string LastName { get; set; } = string.Empty;
    public string Phone { get; set; } = string.Empty;
    public string Role { get; set; } = string.Empty;
    public Guid? DriverId { get; set; }
}

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
    public double PickupLatitude { get; set; }
    public double PickupLongitude { get; set; }
    public string? DestinationAddress { get; set; }
    public double? DestinationLatitude { get; set; }
    public double? DestinationLongitude { get; set; }
    public string TariffName { get; set; } = string.Empty;
    public decimal EstimatedPrice { get; set; }
    public double? EstimatedDistance { get; set; }
    public int? EstimatedDuration { get; set; }
    public string? Comment { get; set; }
    public int PassengerCount { get; set; }
    public DateTimeOffset CreatedAt { get; set; }

    // Простой по просьбе пассажира (поминутная тарификация)
    public DateTimeOffset? WaitingStartedAt { get; set; }
    public int WaitingSeconds { get; set; }
    public decimal WaitingCost { get; set; }
    public bool WaitingActive { get; set; }

    // Результат оповещения пассажира после этапа «Я на месте».
    public string? ClientNotificationStatus { get; set; }
    public string? ClientNotificationMessage { get; set; }
    public string? ClientNotificationCallId { get; set; }
    public int? ClientNotificationHttpCode { get; set; }
}

public class UpdateLocationRequest
{
    public double Latitude { get; set; }
    public double Longitude { get; set; }
    public double? Speed { get; set; }
    public double? Bearing { get; set; }
    public Guid? OrderId { get; set; }
}

public class NewOrderNotification
{
    public Guid OrderId { get; set; }
    public string OrderNumber { get; set; } = string.Empty;
    public string PickupAddress { get; set; } = string.Empty;
    public string? PickupEntrance { get; set; }
    public string? DestinationAddress { get; set; }
    public decimal EstimatedPrice { get; set; }
    public string Tariff { get; set; } = string.Empty;
    public string? Comment { get; set; }
    public int PassengerCount { get; set; }
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
}
public class ChatMessageDto
{
    public Guid Id { get; set; }
    public Guid OrderId { get; set; }
    public Guid SenderId { get; set; }
    public string SenderRole { get; set; } = "";
    public string SenderName { get; set; } = "";
    public string Text { get; set; } = "";
    public DateTimeOffset CreatedAt { get; set; }
    public bool IsRead { get; set; }
}
public class SosAlertDto
{
    public Guid Id { get; set; }
    public Guid DriverId { get; set; }
    public string DriverName { get; set; } = "";
    public string? DriverPhone { get; set; }
    public string CarInfo { get; set; } = "";
    public double Latitude { get; set; }
    public double Longitude { get; set; }
    public string? Comment { get; set; }
    public string Status { get; set; } = "active";
    public string MapUrl { get; set; } = "";
    public DateTimeOffset CreatedAt { get; set; }
}

public class FleetMessageDto
{
    public Guid Id { get; set; }
    public Guid SenderId { get; set; }
    public string SenderName { get; set; } = "";
    public string CarInfo { get; set; } = "";
    public string Text { get; set; } = "";
    // DateTimeOffset: метки сервера UTC 'Z' — миллисекундная точность для ?after=
    public DateTimeOffset CreatedAt { get; set; }
}

public class DriverInfoDto
{
    public Guid Id { get; set; }
    public int CompletedTrips { get; set; }
    public decimal TotalEarnings { get; set; }
    public decimal TodayEarnings { get; set; }
    public double Rating { get; set; }
    public string Status { get; set; } = "";
    public string FullName { get; set; } = "";
}