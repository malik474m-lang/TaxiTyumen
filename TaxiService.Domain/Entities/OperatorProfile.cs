namespace TaxiService.Domain.Entities;

public enum PaymentScheme
{
    PerOrder = 0,
    PerHour = 1,
    PerDay = 2,
    FixedMonthly = 3
}

public class OperatorProfile
{
    public Guid Id { get; set; } = Guid.NewGuid();
    public Guid UserId { get; set; }
    public User User { get; set; } = null!;

    // Схема оплаты
    public PaymentScheme Scheme { get; set; } = PaymentScheme.PerOrder;
    public decimal RatePerOrder { get; set; } = 30;
    public decimal RatePerHour { get; set; } = 150;
    public decimal RatePerDay { get; set; } = 1500;
    public decimal FixedMonthly { get; set; } = 30000;

    // Статистика
    public int TotalOrdersAccepted { get; set; } = 0;
    public decimal TotalEarnings { get; set; } = 0;

    public DateTime CreatedAt { get; set; } = DateTime.UtcNow;
}