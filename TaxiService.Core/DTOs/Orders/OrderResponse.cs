using TaxiService.Domain.Enums;

namespace TaxiService.Core.DTOs.Orders;

public class OrderResponse
{
    public Guid Id { get; set; }
    public string OrderNumber { get; set; } = string.Empty;
    public OrderStatus Status { get; set; }
    public string StatusText => Status switch
    {
        OrderStatus.Created       => "Создан",
        OrderStatus.Searching     => "Поиск водителя",
        OrderStatus.DriverAssigned => "Водитель назначен",
        OrderStatus.DriverEnRoute  => "Водитель в пути",
        OrderStatus.DriverArrived  => "Водитель на месте",
        OrderStatus.InProgress    => "Поездка",
        OrderStatus.Completed     => "Завершён",
        OrderStatus.Cancelled     => "Отменён",
        OrderStatus.NoDriverFound => "Водитель не найден",
        _ => "Неизвестно"
    };
    public string? ClientName { get; set; }
    public string? ClientPhone { get; set; }
    public string PickupAddress { get; set; } = string.Empty;
    public double PickupLatitude { get; set; }
    public double PickupLongitude { get; set; }
    public string? PickupEntrance { get; set; }
    public string? DestinationAddress { get; set; }
    public double? DestinationLatitude { get; set; }
    public double? DestinationLongitude { get; set; }
    public TariffType Tariff { get; set; }
    public string TariffName => Tariff switch
    {
        TariffType.Economy  => "Эконом",
        TariffType.Comfort  => "Комфорт",
        TariffType.Business => "Бизнес",
        TariffType.Minivan  => "Минивэн",
        _ => "Эконом"
    };
    public decimal EstimatedPrice { get; set; }
    public decimal? FinalPrice { get; set; }
    public double? EstimatedDistance { get; set; }
    public int? EstimatedDuration { get; set; }
    public DriverShortInfo? Driver { get; set; }
    public DateTime CreatedAt { get; set; }
    public DateTime? AcceptedAt { get; set; }
    public DateTime? CompletedAt { get; set; }
    public string? Comment { get; set; }
    public OrderSource Source { get; set; }
    public PaymentMethod PaymentMethod { get; set; }
    public PaymentInfo? Payment { get; set; }
}

public class DriverShortInfo
{
    public Guid DriverId { get; set; }
    public string FullName { get; set; } = string.Empty;
    public string Phone { get; set; } = string.Empty;
    public string CarBrand { get; set; } = string.Empty;
    public string CarModel { get; set; } = string.Empty;
    public string CarColor { get; set; } = string.Empty;
    public string LicensePlate { get; set; } = string.Empty;
    public string CarDisplay { get; set; } = string.Empty;
    public double Rating { get; set; }
    public double? Latitude { get; set; }
    public double? Longitude { get; set; }
}

public class PaymentInfo
{
    public string Method { get; set; } = "Cash";
    public string? PaymentPhone { get; set; }
    public string? BankName { get; set; }
    public string? CardHolder { get; set; }
    public bool AcceptSbp { get; set; }
    public string? SbpLink { get; set; }
    public decimal Amount { get; set; }
}