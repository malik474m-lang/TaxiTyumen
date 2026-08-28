using TaxiService.Domain.Enums;

namespace TaxiService.Domain.Entities;

public class Tariff
{
    public Guid Id { get; set; } = Guid.NewGuid();
    public TariffType Type { get; set; }
    public string Name { get; set; } = string.Empty;
    public string Description { get; set; } = string.Empty;
    public decimal BaseFare { get; set; }
    public decimal PricePerKm { get; set; }
    public decimal PricePerMinute { get; set; }
    public decimal MinimumFare { get; set; }
    public decimal FreeWaitingMinutes { get; set; } = 3;
    public decimal PaidWaitingPerMinute { get; set; }
    public decimal NightMultiplier { get; set; } = 1.0m;
    public decimal PeakMultiplier { get; set; } = 1.0m;
    public decimal CommissionPercent { get; set; } = 15;
    public bool IsActive { get; set; } = true;
    public DateTime CreatedAt { get; set; } = DateTime.UtcNow;
    public DateTime? UpdatedAt { get; set; }
}
