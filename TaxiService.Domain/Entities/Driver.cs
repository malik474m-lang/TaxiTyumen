using TaxiService.Domain.Enums;

namespace TaxiService.Domain.Entities;

public class Driver
{
    public Guid Id { get; set; } = Guid.NewGuid();
    public Guid UserId { get; set; }
    public User User { get; set; } = null!;

    public string CarBrand { get; set; } = string.Empty;
    public string CarModel { get; set; } = string.Empty;
    public string CarColor { get; set; } = string.Empty;
    public string LicensePlate { get; set; } = string.Empty;
    public int CarYear { get; set; }

    public string DriverLicense { get; set; } = string.Empty;
    public DateTime LicenseExpiry { get; set; }
    public bool IsVerified { get; set; } = false;
    public DateTime? VerifiedAt { get; set; }

    public DriverStatus Status { get; set; } = DriverStatus.Offline;
    public double Latitude { get; set; }
    public double Longitude { get; set; }
    public double? Speed { get; set; }
    public double? Bearing { get; set; }
    public DateTime? LastLocationUpdate { get; set; }

    public double Rating { get; set; } = 5.0;
    public int CompletedTrips { get; set; } = 0;
    public int CancelledTrips { get; set; } = 0;
    public decimal TotalEarnings { get; set; } = 0;
    public decimal TodayEarnings { get; set; } = 0;
    public decimal Balance { get; set; } = 0;
    public decimal MinBalanceForOrders { get; set; } = 100;
    public decimal RejectionPenalty { get; set; } = 0;

    // Настройки оплаты
    public string? PaymentPhone { get; set; }
    public string? PaymentBankName { get; set; }
    public string? PaymentCardHolder { get; set; }
    public bool AcceptCardTransfer { get; set; } = true;
    public bool AcceptSbp { get; set; } = true;
    public Guid? CurrentOrderId { get; set; }

    public List<Order> Orders { get; set; } = new();
    public List<DriverLocationHistory> LocationHistory { get; set; } = new();
}
