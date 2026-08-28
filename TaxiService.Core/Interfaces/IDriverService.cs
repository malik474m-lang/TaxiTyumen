using TaxiService.Core.DTOs.Drivers;
using TaxiService.Domain.Enums;

namespace TaxiService.Core.Interfaces;

public interface IDriverService
{
    Task UpdateLocationAsync(Guid driverId, UpdateLocationRequest request);
    Task UpdateStatusAsync(Guid driverId, DriverStatus status);
    Task<List<OnlineDriverResponse>> GetOnlineDriversAsync();
    Task<OnlineDriverResponse?> GetDriverAsync(Guid driverId);
}
