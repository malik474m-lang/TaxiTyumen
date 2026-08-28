namespace TaxiService.Core.Interfaces;

public interface IDriverSearchService
{
    Task FindDriverAsync(Guid orderId);
}
