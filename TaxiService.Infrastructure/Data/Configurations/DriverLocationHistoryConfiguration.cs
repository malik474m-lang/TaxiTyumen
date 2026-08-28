using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TaxiService.Domain.Entities;

namespace TaxiService.Infrastructure.Data.Configurations;

public class DriverLocationHistoryConfiguration : IEntityTypeConfiguration<DriverLocationHistory>
{
    public void Configure(EntityTypeBuilder<DriverLocationHistory> builder)
    {
        builder.ToTable("driver_location_history");
        builder.HasKey(l => l.Id);
        builder.HasOne(l => l.Driver)
            .WithMany(d => d.LocationHistory)
            .HasForeignKey(l => l.DriverId)
            .OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(l => l.Order)
            .WithMany()
            .HasForeignKey(l => l.OrderId)
            .OnDelete(DeleteBehavior.SetNull);
        builder.HasIndex(l => new { l.DriverId, l.Timestamp });
        builder.HasIndex(l => l.Timestamp);
    }
}
