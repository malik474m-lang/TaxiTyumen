namespace TaxiService.Domain.Entities;

public enum BalanceTransactionType
{
    TopUp = 0,
    Commission = 1,
    Refund = 2,
    Bonus = 3
}

public class BalanceTransaction
{
    public Guid Id { get; set; } = Guid.NewGuid();
    public Guid DriverId { get; set; }
    public Driver Driver { get; set; } = null!;
    public Guid? OrderId { get; set; }
    public Order? Order { get; set; }
    public BalanceTransactionType Type { get; set; }
    public decimal Amount { get; set; }
    public decimal BalanceAfter { get; set; }
    public string Description { get; set; } = string.Empty;
    public string? CreatedBy { get; set; }
    public DateTime CreatedAt { get; set; } = DateTime.UtcNow;
}