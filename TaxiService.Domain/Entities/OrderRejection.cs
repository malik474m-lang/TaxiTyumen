namespace TaxiService.Domain.Entities;

public class OrderRejection
{
    public Guid Id { get; set; } = Guid.NewGuid();
    public Guid OrderId { get; set; }
    public Order Order { get; set; } = null!;
    public Guid DriverId { get; set; }
    public Driver Driver { get; set; } = null!;
    public string? Reason { get; set; }
    public DateTime RejectedAt { get; set; } = DateTime.UtcNow;
}
