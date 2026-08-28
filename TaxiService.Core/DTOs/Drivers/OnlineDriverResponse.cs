using TaxiService.Domain.Enums;

namespace TaxiService.Core.DTOs.Drivers;

public class OnlineDriverResponse
{
    public Guid Id { get; set; }
    public Guid UserId { get; set; }
    public string FullName { get; set; } = string.Empty;
    public string Phone { get; set; } = string.Empty;
    public string CarBrand { get; set; } = string.Empty;
    public string CarModel { get; set; } = string.Empty;
    public string CarColor { get; set; } = string.Empty;
    public string LicensePlate { get; set; } = string.Empty;
    public string CarDisplay { get; set; } = string.Empty;
    public DriverStatus Status { get; set; }
    public double Latitude { get; set; }
    public double Longitude { get; set; }
    public double Rating { get; set; }
    public Guid? CurrentOrderId { get; set; }
    public DateTime? LastLocationUpdate { get; set; }
}
