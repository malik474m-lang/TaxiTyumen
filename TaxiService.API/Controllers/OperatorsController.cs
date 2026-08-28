using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;
using TaxiService.Domain.Entities;
using TaxiService.Infrastructure.Data;

namespace TaxiService.API.Controllers;

[ApiController]
[Route("api/[controller]")]
[Authorize]
public class OperatorsController : ControllerBase
{
    private readonly TaxiDbContext _db;

    public OperatorsController(TaxiDbContext db)
    {
        _db = db;
    }

    [HttpPost("shift/start")]
    public async Task<ActionResult> StartShift([FromBody] ShiftRequest request)
    {
        // Закрываем предыдущую активную смену если есть
        var active = await _db.OperatorShifts
            .Where(s => s.OperatorId == request.OperatorId && s.EndedAt == null)
            .FirstOrDefaultAsync();

        if (active != null)
        {
            active.EndedAt = DateTime.UtcNow;
            active.HoursWorked = (DateTime.UtcNow - active.StartedAt).TotalHours;
        }

        var profile = await _db.OperatorProfiles
            .FirstOrDefaultAsync(p => p.UserId == request.OperatorId);

        _db.OperatorShifts.Add(new OperatorShift
        {
            OperatorId = request.OperatorId,
            ProfileId = profile?.Id,
            StartedAt = DateTime.UtcNow
        });

        await _db.SaveChangesAsync();
        return Ok(new { message = "Смена начата" });
    }

    [HttpPost("shift/end")]
    public async Task<ActionResult> EndShift([FromBody] ShiftRequest request)
    {
        var active = await _db.OperatorShifts
            .Where(s => s.OperatorId == request.OperatorId && s.EndedAt == null)
            .FirstOrDefaultAsync();

        if (active == null)
            return NotFound(new { message = "Нет активной смены" });

        active.EndedAt = DateTime.UtcNow;
        active.HoursWorked = (DateTime.UtcNow - active.StartedAt).TotalHours;

        // Считаем заработок за смену
        var profile = await _db.OperatorProfiles
            .FirstOrDefaultAsync(p => p.UserId == request.OperatorId);

        if (profile != null)
        {
            active.Earned = profile.Scheme switch
            {
                PaymentScheme.PerOrder => active.OrdersAccepted * profile.RatePerOrder,
                PaymentScheme.PerHour => (decimal)active.HoursWorked * profile.RatePerHour,
                PaymentScheme.PerDay => profile.RatePerDay,
                PaymentScheme.FixedMonthly => 0,
                _ => 0
            };
        }

        await _db.SaveChangesAsync();
        return Ok(new { message = "Смена завершена", hoursWorked = active.HoursWorked, earned = active.Earned });
    }
}

public class ShiftRequest
{
    public Guid OperatorId { get; set; }
}