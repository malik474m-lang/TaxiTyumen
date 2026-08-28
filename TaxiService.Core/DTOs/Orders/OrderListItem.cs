using TaxiService.Domain.Enums;

namespace TaxiService.Core.DTOs.Orders;

public class OrderListItem
{
    public Guid Id { get; set; }
    public string OrderNumber { get; set; } = string.Empty;
    public OrderStatus Status { get; set; }
    public string StatusText { get; set; } = string.Empty;
    public string ClientInfo { get; set; } = string.Empty;
    public string ClientPhone { get; set; } = string.Empty;
    public string PickupAddress { get; set; } = string.Empty;
    public string? DestinationAddress { get; set; }
    public string? DriverInfo { get; set; }
    public string? CarInfo { get; set; }
    public decimal EstimatedPrice { get; set; }
    public string TariffName { get; set; } = string.Empty;
    public DateTime CreatedAt { get; set; }
    public string TimeAgo { get; set; } = string.Empty;
}
