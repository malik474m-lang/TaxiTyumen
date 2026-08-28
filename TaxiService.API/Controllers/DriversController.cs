using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using TaxiService.Core.DTOs.Drivers;
using TaxiService.Core.Interfaces;
using TaxiService.Domain.Enums;

namespace TaxiService.API.Controllers;

[ApiController]
[Route("api/[controller]")]
[Authorize]
public class DriversController : ControllerBase
{
    private readonly IDriverService _driverService;
    private readonly ISignalRNotifier _notifier;

    public DriversController(IDriverService driverService, ISignalRNotifier notifier)
    {
        _driverService = driverService;
        _notifier = notifier;
    }

    /// <summary>Обновить геолокацию водителя</summary>
    [HttpPut("{id:guid}/location")]
    [Authorize(Roles = "Driver")]
    public async Task<ActionResult> UpdateLocation(
        Guid id, [FromBody] UpdateLocationRequest request)
    {
        try
        {
            await _driverService.UpdateLocationAsync(id, request);

            // Уведомляем подписчиков через SignalR
            if (request.OrderId.HasValue)
            {
                await _notifier.SendToGroupAsync(
                    $"order-{request.OrderId}",
                    "DriverLocationUpdated",
                    new
                    {
                        DriverId = id,
                        request.Latitude,
                        request.Longitude,
                        request.Speed,
                        Timestamp = DateTime.UtcNow
                    });
            }

            await _notifier.SendToGroupAsync("Operators", "DriverMoved", new
            {
                DriverId = id,
                request.Latitude,
                request.Longitude
            });

            return Ok();
        }
        catch (InvalidOperationException ex)
        {
            return BadRequest(new { message = ex.Message });
        }
    }

    /// <summary>Обновить статус водителя (онлайн/офлайн)</summary>
    [HttpPut("{id:guid}/status")]
    [Authorize(Roles = "Driver")]
    public async Task<ActionResult> UpdateStatus(Guid id, [FromBody] DriverStatus status)
    {
        try
        {
            await _driverService.UpdateStatusAsync(id, status);
            return Ok();
        }
        catch (InvalidOperationException ex)
        {
            return BadRequest(new { message = ex.Message });
        }
    }

    /// <summary>Все водители онлайн (для оператора/админа)</summary>
    [HttpGet("online")]
    [Authorize(Roles = "Operator,Admin")]
    public async Task<ActionResult<List<OnlineDriverResponse>>> GetOnlineDrivers()
    {
        var drivers = await _driverService.GetOnlineDriversAsync();
        return Ok(drivers);
    }

    /// <summary>Получить данные водителя</summary>
    [HttpGet("{id:guid}")]
    public async Task<ActionResult<OnlineDriverResponse>> GetDriver(Guid id)
    {
        var driver = await _driverService.GetDriverAsync(id);
        return driver == null ? NotFound() : Ok(driver);
    }
}
