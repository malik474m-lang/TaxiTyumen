namespace TaxiService.Core.DTOs.Orders;

public class RateOrderRequest
{
    public int Rating { get; set; }
    public string? Review { get; set; }
    public bool IsClient { get; set; } = true;
}
