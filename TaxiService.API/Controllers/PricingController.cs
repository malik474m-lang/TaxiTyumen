using Microsoft.AspNetCore.Mvc;
using TaxiService.Core.DTOs.Pricing;
using TaxiService.Core.Interfaces;
using TaxiService.Domain.Enums;

namespace TaxiService.API.Controllers;

[ApiController]
[Route("api/[controller]")]
public class PricingController : ControllerBase
{
    private readonly IPricingService _pricingService;

    public PricingController(IPricingService pricingService)
    {
        _pricingService = pricingService;
    }

    /// <summary>Рассчитать стоимость для одного тарифа</summary>
    [HttpGet("estimate")]
    public async Task<ActionResult<PriceEstimate>> GetEstimate(
        [FromQuery] double fromLat,
        [FromQuery] double fromLng,
        [FromQuery] double toLat,
        [FromQuery] double toLng,
        [FromQuery] TariffType tariff = TariffType.Economy)
    {
        try
        {
            var estimate = await _pricingService.CalculatePriceAsync(
                fromLat, fromLng, toLat, toLng, tariff);
            return Ok(estimate);
        }
        catch (InvalidOperationException ex)
        {
            return BadRequest(new { message = ex.Message });
        }
    }

    /// <summary>Рассчитать стоимость для всех тарифов сразу</summary>
    [HttpGet("estimate-all")]
    public async Task<ActionResult<List<PriceEstimate>>> GetAllEstimates(
        [FromQuery] double fromLat,
        [FromQuery] double fromLng,
        [FromQuery] double toLat,
        [FromQuery] double toLng)
    {
        var estimates = await _pricingService.CalculateAllTariffsAsync(
            fromLat, fromLng, toLat, toLng);
        return Ok(estimates);
    }
}
