using Microsoft.EntityFrameworkCore;
using TaxiService.Domain.Entities;

namespace TaxiService.Infrastructure.Data;

public class TaxiDbContext : DbContext
{
    public TaxiDbContext(DbContextOptions<TaxiDbContext> options) : base(options) { }

    public DbSet<User> Users => Set<User>();
    public DbSet<Driver> Drivers => Set<Driver>();
    public DbSet<Order> Orders => Set<Order>();
    public DbSet<RoutePoint> RoutePoints => Set<RoutePoint>();
    public DbSet<OrderOption> OrderOptions => Set<OrderOption>();
    public DbSet<Tariff> Tariffs => Set<Tariff>();
    public DbSet<DriverLocationHistory> DriverLocationHistory => Set<DriverLocationHistory>();
    public DbSet<Transaction> Transactions => Set<Transaction>();
    public DbSet<OrderRejection> OrderRejections => Set<OrderRejection>();
    public DbSet<BalanceTransaction> BalanceTransactions => Set<BalanceTransaction>();
    public DbSet<OperatorProfile> OperatorProfiles => Set<OperatorProfile>();
    public DbSet<OperatorShift> OperatorShifts => Set<OperatorShift>();
    public DbSet<ChatMessage> ChatMessages => Set<ChatMessage>();
    public DbSet<AutoCallSettings> AutoCallSettings => Set<AutoCallSettings>();

    protected override void OnModelCreating(ModelBuilder modelBuilder)
    {
        base.OnModelCreating(modelBuilder);
        modelBuilder.ApplyConfigurationsFromAssembly(typeof(TaxiDbContext).Assembly);
    }
}
