using Microsoft.EntityFrameworkCore;
using TaxiService.Core.DTOs.Drivers;
using TaxiService.Core.Helpers;
using TaxiService.Core.Interfaces;
using TaxiService.Domain.Entities;
using TaxiService.Domain.Enums;
using TaxiService.Infrastructure.Data;

namespace TaxiService.Core.Services;

public class DriverService : IDriverService
{
    private readonly TaxiDbContext _db;

    public DriverService(TaxiDbContext db)
    {
        _db = db;
    }

    public async Task UpdateLocationAsync(Guid driverId, UpdateLocationRequest request)
    {
        var driver = await _db.Drivers.FindAsync(driverId)
            ?? throw new InvalidOperationException("Водитель не найден");

        driver.Latitude = request.Latitude;
        driver.Longitude = request.Longitude;
        driver.Speed = request.Speed;
        driver.Bearing = request.Bearing;
        driver.LastLocationUpdate = DateTime.UtcNow;

        _db.DriverLocationHistory.Add(new DriverLocationHistory
        {
            DriverId = driverId,
            Latitude = request.Latitude,
            Longitude = request.Longitude,
            Speed = request.Speed,
            Bearing = request.Bearing,
            OrderId = request.OrderId
        });

        await _db.SaveChangesAsync();
    }

    public async Task UpdateStatusAsync(Guid driverId, DriverStatus status)
    {
        var driver = await _db.Drivers.FindAsync(driverId)
            ?? throw new InvalidOperationException("Водитель не найден");

        driver.Status = status;

        if (status == DriverStatus.Offline)
        {
            driver.CurrentOrderId = null;
        }
        else
        {
            // Важно: считаем водителя живым сразу при выходе на линию
            driver.LastLocationUpdate = DateTime.UtcNow;
        }

        await _db.SaveChangesAsync();
    }

    public async Task<List<OnlineDriverResponse>> GetOnlineDriversAsync()
    {
        return await _db.Drivers
            .Include(d => d.User)
            .Where(d => d.Status != DriverStatus.Offline)
            .Select(d => new OnlineDriverResponse
            {
                Id = d.Id,
                UserId = d.UserId,
                FullName = d.User.FirstName + " " + d.User.LastName,
                Phone = d.User.Phone,
                CarBrand = d.CarBrand,
                CarModel = d.CarModel,
                CarColor = CarColorHelper.Translate(d.CarColor),
                LicensePlate = LicensePlateHelper.Format(d.LicensePlate),
                CarDisplay = CarDisplayHelper.Format(d.CarColor, d.CarBrand, d.CarModel, d.LicensePlate),
                Status = d.Status,
                Latitude = d.Latitude,
                Longitude = d.Longitude,
                Rating = d.Rating,
                CurrentOrderId = d.CurrentOrderId,
                LastLocationUpdate = d.LastLocationUpdate
            })
            .ToListAsync();
    }

    public async Task<OnlineDriverResponse?> GetDriverAsync(Guid driverId)
    {
        var d = await _db.Drivers
            .Include(x => x.User)
            .FirstOrDefaultAsync(x => x.Id == driverId);

        if (d == null) return null;

        return new OnlineDriverResponse
        {
            Id = d.Id,
            UserId = d.UserId,
            FullName = d.User.FirstName + " " + d.User.LastName,
            Phone = d.User.Phone,
            CarBrand = d.CarBrand,
            CarModel = d.CarModel,
            CarColor = CarColorHelper.Translate(d.CarColor),
            LicensePlate = LicensePlateHelper.Format(d.LicensePlate),
            CarDisplay = CarDisplayHelper.Format(d.CarColor, d.CarBrand, d.CarModel, d.LicensePlate),
            Status = d.Status,
            Latitude = d.Latitude,
            Longitude = d.Longitude,
            Rating = d.Rating,
            CurrentOrderId = d.CurrentOrderId,
            LastLocationUpdate = d.LastLocationUpdate
        };
    }
}