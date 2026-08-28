namespace TaxiService.Core.DTOs.Orders;

public class CancelOrderRequest
{
    public string Reason { get; set; } = string.Empty;
    public Guid CancelledByUserId { get; set; }
}
