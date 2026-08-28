using TaxiService.Domain.Enums;

namespace TaxiService.Core.DTOs.Orders;

public class CreateOperatorOrderRequest
{
    public Guid OperatorId { get; set; }
    public string ClientPhone { get; set; } = string.Empty;
    public string ClientName { get; set; } = string.Empty;
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
}
