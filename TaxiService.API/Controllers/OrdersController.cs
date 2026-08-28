using Microsoft.EntityFrameworkCore;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using TaxiService.Core.DTOs.Orders;
using TaxiService.Core.Interfaces;
using TaxiService.Domain.Enums;

namespace TaxiService.API.Controllers;

[ApiController]
[Route("api/[controller]")]
[Authorize]
public class OrdersController : ControllerBase
{
    private readonly IOrderService _orderService;
    private readonly ILogger<OrdersController> _logger;

    public OrdersController(IOrderService orderService, ILogger<OrdersController> logger)
    {
        _orderService = orderService;
        _logger = logger;
    }

    /// <summary>Создать заказ из мобильного приложения клиента</summary>
    [HttpPost]
    public async Task<ActionResult<OrderResponse>> CreateOrder([FromBody] CreateOrderRequest request)
    {
        try
        {
            var order = await _orderService.CreateOrderAsync(request);
            _logger.LogInformation("Создан заказ {OrderNumber}", order.OrderNumber);
            return CreatedAtAction(nameof(GetOrder), new { id = order.Id }, order);
        }
        catch (Exception ex)
        {
            return BadRequest(new { message = ex.Message });
        }
    }

    /// <summary>Создать заказ оператором (по телефону)</summary>
    [HttpPost("operator")]
    [Authorize(Roles = "Operator,Admin")]
    public async Task<ActionResult<OrderResponse>> CreateOperatorOrder(
        [FromBody] CreateOperatorOrderRequest request)
    {
        try
        {
            var order = await _orderService.CreateOrderByOperatorAsync(request);
            _logger.LogInformation("Оператор создал заказ {OrderNumber}", order.OrderNumber);
            return CreatedAtAction(nameof(GetOrder), new { id = order.Id }, order);
        }
        catch (Exception ex)
        {
            return BadRequest(new { message = ex.Message });
        }
    }

    /// <summary>Получить заказ по ID</summary>
    [HttpGet("{id:guid}")]
    public async Task<ActionResult<OrderResponse>> GetOrder(Guid id)
    {
        var order = await _orderService.GetOrderAsync(id);
        return order == null ? NotFound() : Ok(order);
    }

    /// <summary>Все активные заказы (для оператора/админа)</summary>
    [HttpGet("active")]
    [Authorize(Roles = "Operator,Admin")]
    public async Task<ActionResult<List<OrderResponse>>> GetActiveOrders()
    {
        var orders = await _orderService.GetActiveOrdersAsync();
        return Ok(orders);
    }

    /// <summary>Доступные заказы для водителя поблизости</summary>
    [HttpGet("available")]
    [Authorize(Roles = "Driver")]
    public async Task<ActionResult<List<OrderResponse>>> GetAvailableOrders(
        [FromQuery] Guid driverId,
        [FromQuery] double lat,
        [FromQuery] double lng,
        [FromQuery] double radius = 10)
    {
        var orders = await _orderService.GetAvailableOrdersForDriverAsync(
            driverId, lat, lng, radius);
        return Ok(orders);
    }

    /// <summary>Водитель принимает заказ</summary>
    [HttpPost("{id:guid}/accept")]
    [Authorize(Roles = "Driver")]
    public async Task<ActionResult<OrderResponse>> AcceptOrder(
        Guid id, [FromQuery] Guid driverId)
    {
        try
        {
            var order = await _orderService.AcceptOrderAsync(id, driverId);
            return Ok(order);
        }
        catch (InvalidOperationException ex)
        {
            return BadRequest(new { message = ex.Message });
        }
    }

    /// <summary>Водитель отклоняет заказ</summary>
    [HttpPost("{id:guid}/reject")]
    [Authorize(Roles = "Driver")]
    public async Task<ActionResult<OrderResponse>> RejectOrder(
        Guid id,
        [FromQuery] Guid driverId,
        [FromBody] string? reason = null)
    {
        var order = await _orderService.RejectOrderAsync(id, driverId, reason);
        return Ok(order);
    }

    /// <summary>Обновить статус заказа</summary>
    [HttpPut("{id:guid}/status")]
    [Authorize(Roles = "Driver,Operator,Admin")]
    public async Task<ActionResult<OrderResponse>> UpdateStatus(
        Guid id, [FromBody] OrderStatus status)
    {
        try
        {
            var order = await _orderService.UpdateOrderStatusAsync(id, status);

            // Автодозвон при прибытии водителя
            if (status == TaxiService.Domain.Enums.OrderStatus.DriverArrived)
            {
                try
                {
                    var autoCall = HttpContext.RequestServices
                        .GetService<TaxiService.API.Services.IAutoCallService>();

                    if (autoCall != null)
                    {
                        var fullOrder = await _orderService.GetOrderAsync(id);
                        // Получаем полный Order из БД для звонка
                        var db = HttpContext.RequestServices
                            .GetRequiredService<TaxiService.Infrastructure.Data.TaxiDbContext>();
                        var dbOrder = await db.Orders
                            .Include(o => o.Client)
                            .Include(o => o.Driver).ThenInclude(d => d!.User)
                            .FirstOrDefaultAsync(o => o.Id == id);

                        if (dbOrder != null)
                            await autoCall.CallClientOnDriverArrivedAsync(dbOrder);
                    }
                }
                catch { }
            }

            return Ok(order);
        }
        catch (InvalidOperationException ex)
        {
            return BadRequest(new { message = ex.Message });
        }
    }

    /// <summary>Завершить поездку</summary>
    [HttpPost("{id:guid}/complete")]
    [Authorize(Roles = "Driver")]
    public async Task<ActionResult<OrderResponse>> CompleteOrder(Guid id)
    {
        try
        {
            var order = await _orderService.CompleteOrderAsync(id);
            return Ok(order);
        }
        catch (InvalidOperationException ex)
        {
            return BadRequest(new { message = ex.Message });
        }
    }

    /// <summary>Принудительно назначить водителя (оператор/админ)</summary>
    [HttpPost("{id:guid}/force-assign")]
    [Authorize(Roles = "Operator,Admin")]
    public async Task<ActionResult<OrderResponse>> ForceAssignDriver(
        Guid id, [FromQuery] Guid driverId)
    {
        try
        {
            var order = await _orderService.ForceAssignOrderAsync(id, driverId);
            _logger.LogInformation(
                "Оператор принудительно назначил водителя {DriverId} на заказ {OrderId}",
                driverId, id);
            return Ok(order);
        }
        catch (InvalidOperationException ex)
        {
            return BadRequest(new { message = ex.Message });
        }
    }

    /// <summary>Отменить заказ</summary>
    [HttpPost("{id:guid}/cancel")]
    public async Task<ActionResult<OrderResponse>> CancelOrder(
        Guid id, [FromBody] CancelOrderRequest request)
    {
        try
        {
            var order = await _orderService.CancelOrderAsync(id, request);
            return Ok(order);
        }
        catch (InvalidOperationException ex)
        {
            return BadRequest(new { message = ex.Message });
        }
    }

    /// <summary>История заказов пользователя</summary>
    [HttpGet("history/{userId:guid}")]
    public async Task<ActionResult<List<OrderListItem>>> GetHistory(
        Guid userId,
        [FromQuery] int page = 1,
        [FromQuery] int pageSize = 20)
    {
        var orders = await _orderService.GetOrderHistoryAsync(userId, page, pageSize);
        return Ok(orders);
    }

    /// <summary>Оценить поездку</summary>
    [HttpPost("{id:guid}/rate")]
    public async Task<ActionResult<OrderResponse>> RateOrder(
        Guid id, [FromBody] RateOrderRequest request)
    {
        try
        {
            var order = await _orderService.RateOrderAsync(id, request);
            return Ok(order);
        }
        catch (InvalidOperationException ex)
        {
            return BadRequest(new { message = ex.Message });
        }
    }
}
