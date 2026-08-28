namespace TaxiService.Domain.Entities;

public class DriverLocationHistory
{
    public Guid Id { get; set; } = Guid.NewGuid();
    public Guid DriverId { get; set; }
    public Driver Driver { get; set; } = null!;
    public Guid? OrderId { get; set; }
    public Order? Order { get; set; }
    public double Latitude { get; set; }
    public double Longitude { get; set; }
    public double? Speed { get; set; }
    public double? Bearing { get; set; }
    public DateTime Timestamp { get; set; } = DateTime.UtcNow;
}
