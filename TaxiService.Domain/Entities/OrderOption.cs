namespace TaxiService.Domain.Entities;

public class OrderOption
{
    public Guid Id { get; set; } = Guid.NewGuid();
    public Guid OrderId { get; set; }
    public Order Order { get; set; } = null!;
    public string Name { get; set; } = string.Empty;
    public decimal ExtraPrice { get; set; }
}
