namespace TaxiService.Domain.Entities;

public class OperatorShift
{
    public Guid Id { get; set; } = Guid.NewGuid();
    public Guid OperatorId { get; set; }
    public User Operator { get; set; } = null!;
    public Guid? ProfileId { get; set; }
    public OperatorProfile? Profile { get; set; }

    public DateTime StartedAt { get; set; } = DateTime.UtcNow;
    public DateTime? EndedAt { get; set; }
    public double HoursWorked { get; set; } = 0;
    public int OrdersAccepted { get; set; } = 0;
    public decimal Earned { get; set; } = 0;

    public bool IsActive => EndedAt == null;
}