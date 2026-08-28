using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;
using TaxiService.Domain.Entities;
using TaxiService.Infrastructure.Data;

namespace TaxiService.Core.Services;

public interface IBalanceService
{
    Task<decimal> GetBalanceAsync(Guid driverId);
    Task<decimal> TopUpAsync(Guid driverId, decimal amount, string createdBy);
    Task<decimal> ChargeCommissionAsync(Guid driverId, Guid orderId, decimal orderPrice, decimal commissionPercent);
    Task<decimal> ChargePenaltyAsync(Guid driverId, Guid orderId, decimal penalty, string description);
    Task<bool> HasSufficientBalanceAsync(Guid driverId);
    Task<List<BalanceTransaction>> GetHistoryAsync(Guid driverId, int page = 1, int pageSize = 20);
}

public class BalanceService : IBalanceService
{
    private readonly TaxiDbContext _db;
    private readonly ILogger<BalanceService> _logger;

    public BalanceService(TaxiDbContext db, ILogger<BalanceService> logger)
    {
        _db = db;
        _logger = logger;
    }

    public async Task<decimal> GetBalanceAsync(Guid driverId)
    {
        var driver = await _db.Drivers.FindAsync(driverId);
        return driver?.Balance ?? 0;
    }

    public async Task<decimal> TopUpAsync(Guid driverId, decimal amount, string createdBy)
    {
        var driver = await _db.Drivers.FindAsync(driverId)
            ?? throw new InvalidOperationException("Водитель не найден");

        driver.Balance += amount;

        _db.BalanceTransactions.Add(new BalanceTransaction
        {
            DriverId = driverId,
            Type = BalanceTransactionType.TopUp,
            Amount = amount,
            BalanceAfter = driver.Balance,
            Description = $"Пополнение +{amount:F0} руб.",
            CreatedBy = createdBy
        });

        await _db.SaveChangesAsync();

        _logger.LogInformation(
            "Баланс водителя {DriverId} пополнен на {Amount}. Баланс: {Balance}",
            driverId, amount, driver.Balance);

        return driver.Balance;
    }

    public async Task<decimal> ChargeCommissionAsync(
        Guid driverId, Guid orderId, decimal orderPrice, decimal commissionPercent)
    {
        var driver = await _db.Drivers.FindAsync(driverId)
            ?? throw new InvalidOperationException("Водитель не найден");

        var commission = Math.Round(orderPrice * commissionPercent / 100, 2);
        driver.Balance -= commission;

        _db.BalanceTransactions.Add(new BalanceTransaction
        {
            DriverId = driverId,
            OrderId = orderId,
            Type = BalanceTransactionType.Commission,
            Amount = -commission,
            BalanceAfter = driver.Balance,
            Description = $"Комиссия {commissionPercent}% ({orderPrice:F0} руб.)"
        });

        await _db.SaveChangesAsync();

        _logger.LogInformation(
            "Комиссия {Commission} списана с водителя {DriverId}. Баланс: {Balance}",
            commission, driverId, driver.Balance);

        return driver.Balance;
    }

    public async Task<decimal> ChargePenaltyAsync(
        Guid driverId, Guid orderId, decimal penalty, string description)
    {
        var driver = await _db.Drivers.FindAsync(driverId)
            ?? throw new InvalidOperationException("Водитель не найден");

        driver.Balance -= penalty;

        _db.BalanceTransactions.Add(new BalanceTransaction
        {
            DriverId = driverId,
            OrderId = orderId,
            Type = BalanceTransactionType.Commission,
            Amount = -penalty,
            BalanceAfter = driver.Balance,
            Description = description
        });

        await _db.SaveChangesAsync();

        _logger.LogInformation(
            "Штраф {Penalty} списан с водителя {DriverId}. Баланс: {Balance}",
            penalty, driverId, driver.Balance);

        return driver.Balance;
    }

    public async Task<bool> HasSufficientBalanceAsync(Guid driverId)
    {
        var driver = await _db.Drivers.FindAsync(driverId);
        if (driver == null) return false;
        return driver.Balance >= driver.MinBalanceForOrders;
    }

    public async Task<List<BalanceTransaction>> GetHistoryAsync(
        Guid driverId, int page = 1, int pageSize = 20)
    {
        return await _db.BalanceTransactions
            .Where(b => b.DriverId == driverId)
            .OrderByDescending(b => b.CreatedAt)
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .ToListAsync();
    }
}