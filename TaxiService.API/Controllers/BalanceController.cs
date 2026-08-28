using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using TaxiService.Core.Services;
using TaxiService.Domain.Entities;

namespace TaxiService.API.Controllers;

[ApiController]
[Route("api/[controller]")]
[Authorize]
public class BalanceController : ControllerBase
{
    private readonly IBalanceService _balanceService;

    public BalanceController(IBalanceService balanceService)
    {
        _balanceService = balanceService;
    }

    /// <summary>Получить баланс водителя</summary>
    [HttpGet("{driverId:guid}")]
    public async Task<ActionResult<object>> GetBalance(Guid driverId)
    {
        try
        {
            var balance = await _balanceService.GetBalanceAsync(driverId);
            var hasSufficient = await _balanceService.HasSufficientBalanceAsync(driverId);
            return Ok(new
            {
                Balance = balance,
                HasSufficientBalance = hasSufficient
            });
        }
        catch (Exception ex) { return BadRequest(new { message = ex.Message }); }
    }

    /// <summary>Пополнить баланс</summary>
    [HttpPost("{driverId:guid}/topup")]
    [Authorize(Roles = "Operator,Admin")]
    public async Task<ActionResult<object>> TopUp(
        Guid driverId, [FromBody] TopUpRequest request)
    {
        try
        {
            var newBalance = await _balanceService.TopUpAsync(
                driverId, request.Amount, request.CreatedBy);
            return Ok(new { Balance = newBalance });
        }
        catch (Exception ex) { return BadRequest(new { message = ex.Message }); }
    }

    /// <summary>История баланса водителя</summary>
    [HttpGet("{driverId:guid}/history")]
    public async Task<ActionResult<List<BalanceTransaction>>> GetHistory(
        Guid driverId, [FromQuery] int page = 1, [FromQuery] int pageSize = 20)
    {
        var history = await _balanceService.GetHistoryAsync(driverId, page, pageSize);
        return Ok(history);
    }
}

public class TopUpRequest
{
    public decimal Amount { get; set; }
    public string CreatedBy { get; set; } = "Оператор";
}