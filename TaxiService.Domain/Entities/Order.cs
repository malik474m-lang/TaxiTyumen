using TaxiService.Domain.Enums;

namespace TaxiService.Domain.Entities;

public class Order
{
    public Guid Id { get; set; } = Guid.NewGuid();
    public string OrderNumber { get; set; } = string.Empty;

    public Guid? ClientId { get; set; }
    public User? Client { get; set; }
    public Guid? OperatorId { get; set; }
    public User? Operator { get; set; }
    public OrderSource Source { get; set; }

    public string? ClientPhone { get; set; }
    public string? ClientName { get; set; }

    public Guid? DriverId { get; set; }
    public Driver? Driver { get; set; }

    public string PickupAddress { get; set; } = string.Empty;
    public double PickupLatitude { get; set; }
    public double PickupLongitude { get; set; }
    public string? PickupEntrance { get; set; }
    public string? DestinationAddress { get; set; }
    public double? DestinationLatitude { get; set; }
    public double? DestinationLongitude { get; set; }

    public TariffType Tariff { get; set; } = TariffType.Economy;
    public decimal EstimatedPrice { get; set; }
    public decimal? FinalPrice { get; set; }
    public double? EstimatedDistance { get; set; }
    public int? EstimatedDuration { get; set; }
    public double? ActualDistance { get; set; }
    public PaymentMethod PaymentMethod { get; set; } = PaymentMethod.Cash;

    public OrderStatus Status { get; set; } = OrderStatus.Created;
    public DateTime CreatedAt { get; set; } = DateTime.UtcNow;
    public DateTime? AcceptedAt { get; set; }
    public DateTime? DriverArrivedAt { get; set; }
    public DateTime? TripStartedAt { get; set; }
    public DateTime? CompletedAt { get; set; }
    public DateTime? CancelledAt { get; set; }
    public Guid? CancelledByUserId { get; set; }

    public string? Comment { get; set; }
    public string? CancellationReason { get; set; }
    public int PassengerCount { get; set; } = 1;

    public int? ClientRating { get; set; }
    public int? DriverRating { get; set; }
    public string? ClientReview { get; set; }
    public string? DriverReview { get; set; }

    public List<RoutePoint> IntermediatePoints { get; set; } = new();
    public List<OrderOption> Options { get; set; } = new();
    public List<OrderRejection> Rejections { get; set; } = new();
    public Transaction? Transaction { get; set; }
}
