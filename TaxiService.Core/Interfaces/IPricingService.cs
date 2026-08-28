using TaxiService.Core.DTOs.Pricing;
using TaxiService.Domain.Enums;

namespace TaxiService.Core.Interfaces;

public interface IPricingService
{
    Task<PriceEstimate> CalculatePriceAsync(double fromLat, double fromLng, double toLat, double toLng, TariffType tariff);
    Task<List<PriceEstimate>> CalculateAllTariffsAsync(double fromLat, double fromLng, double toLat, double toLng);
}
