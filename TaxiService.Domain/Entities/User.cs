using TaxiService.Domain.Enums;

namespace TaxiService.Domain.Entities;

public class User
{
    public Guid Id { get; set; } = Guid.NewGuid();
    public string Phone { get; set; } = string.Empty;
    public string FirstName { get; set; } = string.Empty;
    public string LastName { get; set; } = string.Empty;
    public string? Email { get; set; }
    public string PasswordHash { get; set; } = string.Empty;
    public UserRole Role { get; set; }
    public DateTime CreatedAt { get; set; } = DateTime.UtcNow;
    public DateTime? LastLoginAt { get; set; }
    public bool IsActive { get; set; } = true;
    public bool IsBlocked { get; set; } = false;
    public string? BlockReason { get; set; }
    public double Rating { get; set; } = 5.0;
    public int TotalTrips { get; set; } = 0;
    public string? SmsCode { get; set; }
    public DateTime? SmsCodeExpiry { get; set; }
    public bool IsPhoneVerified { get; set; } = false;

    public Driver? DriverProfile { get; set; }
}
