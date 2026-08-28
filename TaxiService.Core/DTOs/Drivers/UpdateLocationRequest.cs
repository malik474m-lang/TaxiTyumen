namespace TaxiService.Core.DTOs.Drivers;

public class UpdateLocationRequest
{
    public double Latitude { get; set; }
    public double Longitude { get; set; }
    public double? Speed { get; set; }
    public double? Bearing { get; set; }
    public Guid? OrderId { get; set; }
}
