using TaxiService.Domain.Enums;

namespace TaxiService.Core.DTOs.Orders;

public class CreateOrderRequest
{
    public Guid ClientId { get; set; }
    public string PickupAddress { get; set; } = string.Empty;
    public double PickupLatitude { get; set; }
    public double PickupLongitude { get; set; }
    public string? PickupEntrance { get; set; }
    public string? DestinationAddress { get; set; }
    public double? DestinationLatitude { get; set; }
    public double? DestinationLongitude { get; set; }
    public TariffType Tariff { get; set; } = TariffType.Economy;
    public string? Comment { get; set; }
    public int PassengerCount { get; set; } = 1;
    public PaymentMethod PaymentMethod { get; set; } = PaymentMethod.Cash;
    public List<string>? Options { get; set; }
}
