namespace TaxiService.Core.DTOs.Pricing;

public class PriceEstimate
{
    public decimal Price { get; set; }
    public double DistanceKm { get; set; }
    public int DurationMinutes { get; set; }
    public string TariffName { get; set; } = string.Empty;
    public bool IsNightRate { get; set; }
    public bool IsPeakRate { get; set; }
    public decimal Multiplier { get; set; } = 1.0m;
}
