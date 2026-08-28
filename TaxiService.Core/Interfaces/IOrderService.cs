using TaxiService.Core.DTOs.Orders;
using TaxiService.Domain.Enums;

namespace TaxiService.Core.Interfaces;

public interface IOrderService
{
    Task<OrderResponse> CreateOrderAsync(CreateOrderRequest request);
    Task<OrderResponse> CreateOrderByOperatorAsync(CreateOperatorOrderRequest request);
    Task<OrderResponse?> GetOrderAsync(Guid orderId);
    Task<List<OrderResponse>> GetActiveOrdersAsync();
    Task<List<OrderResponse>> GetAvailableOrdersForDriverAsync(Guid driverId, double lat, double lng, double radiusKm = 10);
    Task<OrderResponse> ForceAssignOrderAsync(Guid orderId, Guid driverId);
    Task<OrderResponse> AcceptOrderAsync(Guid orderId, Guid driverId);
    Task<OrderResponse> RejectOrderAsync(Guid orderId, Guid driverId, string? reason);
    Task<OrderResponse> UpdateOrderStatusAsync(Guid orderId, OrderStatus status);
    Task<OrderResponse> CompleteOrderAsync(Guid orderId);
    Task<OrderResponse> CancelOrderAsync(Guid orderId, CancelOrderRequest request);
    Task<List<OrderListItem>> GetOrderHistoryAsync(Guid userId, int page = 1, int pageSize = 20);
    Task<OrderResponse> RateOrderAsync(Guid orderId, RateOrderRequest request);
}
