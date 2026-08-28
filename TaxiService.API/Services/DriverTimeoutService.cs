using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using TaxiService.Domain.Enums;
using TaxiService.Infrastructure.Data;

namespace TaxiService.API.Services;

public class DriverTimeoutService : BackgroundService
{
    private readonly IServiceScopeFactory _scopeFactory;
    private readonly ILogger<DriverTimeoutService> _logger;

    public DriverTimeoutService(
        IServiceScopeFactory scopeFactory,
        ILogger<DriverTimeoutService> logger)
    {
        _scopeFactory = scopeFactory;
        _logger = logger;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                await CheckStaleDriversAsync();
            }
            catch (Exception ex)
            {
                _logger.LogWarning(ex, "Ошибка проверки зависших водителей");
            }

            await Task.Delay(TimeSpan.FromSeconds(30), stoppingToken);
        }
    }

    private async Task CheckStaleDriversAsync()
    {
        using var scope = _scopeFactory.CreateScope();
        var db = scope.ServiceProvider.GetRequiredService<TaxiDbContext>();

        var threshold = DateTime.UtcNow.AddMinutes(-5);

        var staleDrivers = await db.Drivers
            .Where(d => d.Status != DriverStatus.Offline)
            .Where(d => d.LastLocationUpdate == null || d.LastLocationUpdate < threshold)
            .ToListAsync();

        foreach (var driver in staleDrivers)
        {
            driver.Status = DriverStatus.Offline;
            driver.CurrentOrderId = null;

            _logger.LogInformation(
                "Водитель {DriverId} автоматически переведён в Offline (таймаут 2 мин)",
                driver.Id);
        }

        if (staleDrivers.Count > 0)
            await db.SaveChangesAsync();
    }
}