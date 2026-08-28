using Microsoft.EntityFrameworkCore;
using TaxiService.Core.DTOs.Pricing;
using TaxiService.Core.Helpers;
using TaxiService.Core.Interfaces;
using TaxiService.Domain.Enums;
using TaxiService.Infrastructure.Data;

namespace TaxiService.Core.Services;

public class PricingService : IPricingService
{
    private readonly TaxiDbContext _db;

    public PricingService(TaxiDbContext db)
    {
        _db = db;
    }

    public async Task<PriceEstimate> CalculatePriceAsync(
        double fromLat, double fromLng,
        double toLat, double toLng,
        TariffType tariffType)
    {
        var tariff = await _db.Tariffs
            .FirstOrDefaultAsync(t => t.Type == tariffType && t.IsActive)
            ?? throw new InvalidOperationException($"Тариф {tariffType} не найден");

        double roadDistance;
        int duration;

        try
        {
            var route = await DistanceCalculator.GetRealRouteAsync(fromLat, fromLng, toLat, toLng);
            roadDistance = route.DistanceKm;
            duration = route.DurationMinutes;
        }
        catch
        {
            roadDistance = DistanceCalculator.GetRoadDistanceKm(fromLat, fromLng, toLat, toLng);
            duration = DistanceCalculator.EstimateDurationMinutes(roadDistance);
        }
        var price = tariff.BaseFare + (decimal)roadDistance * tariff.PricePerKm;

        // Тюмень UTC+5
        var tyumenTime = DateTime.UtcNow.AddHours(5);
        var hour = tyumenTime.Hour;
        bool isNight = hour >= 23 || hour < 6;
        bool isPeak = (hour >= 7 && hour < 9) || (hour >= 17 && hour < 19);

        decimal multiplier = 1.0m;
        if (isNight)      { multiplier = tariff.NightMultiplier; price *= multiplier; }
        else if (isPeak)  { multiplier = tariff.PeakMultiplier;  price *= multiplier; }

        price = Math.Max(price, tariff.MinimumFare);

        return new PriceEstimate
        {
            Price = Math.Round(price, 0),
            DistanceKm = Math.Round(roadDistance, 1),
            DurationMinutes = duration,
            TariffName = tariff.Name,
            IsNightRate = isNight,
            IsPeakRate = isPeak,
            Multiplier = multiplier
        };
    }

    public async Task<List<PriceEstimate>> CalculateAllTariffsAsync(
        double fromLat, double fromLng, double toLat, double toLng)
    {
        var tariffs = await _db.Tariffs.Where(t => t.IsActive).ToListAsync();
        var results = new List<PriceEstimate>();
        foreach (var tariff in tariffs)
        {
            var estimate = await CalculatePriceAsync(fromLat, fromLng, toLat, toLng, tariff.Type);
            results.Add(estimate);
        }
        return results;
    }
}
