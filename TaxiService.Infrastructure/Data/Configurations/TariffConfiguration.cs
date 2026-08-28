using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TaxiService.Domain.Entities;

namespace TaxiService.Infrastructure.Data.Configurations;

public class TariffConfiguration : IEntityTypeConfiguration<Tariff>
{
    public void Configure(EntityTypeBuilder<Tariff> builder)
    {
        builder.ToTable("tariffs");
        builder.HasKey(t => t.Id);
        builder.Property(t => t.Name).IsRequired().HasMaxLength(100);
        builder.Property(t => t.Description).HasMaxLength(500);
        builder.Property(t => t.BaseFare).HasPrecision(10, 2);
        builder.Property(t => t.PricePerKm).HasPrecision(10, 2);
        builder.Property(t => t.PricePerMinute).HasPrecision(10, 2);
        builder.Property(t => t.MinimumFare).HasPrecision(10, 2);
        builder.Property(t => t.FreeWaitingMinutes).HasPrecision(5, 1);
        builder.Property(t => t.PaidWaitingPerMinute).HasPrecision(10, 2);
        builder.Property(t => t.NightMultiplier).HasPrecision(5, 2);
        builder.Property(t => t.PeakMultiplier).HasPrecision(5, 2);
        builder.Property(t => t.CommissionPercent).HasPrecision(5, 2);
        builder.HasIndex(t => t.Type);
    }
}
