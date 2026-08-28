using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TaxiService.Domain.Entities;

namespace TaxiService.Infrastructure.Data.Configurations;

public class DriverConfiguration : IEntityTypeConfiguration<Driver>
{
    public void Configure(EntityTypeBuilder<Driver> builder)
    {
        builder.ToTable("drivers");
        builder.HasKey(d => d.Id);
        builder.Property(d => d.CarBrand).IsRequired().HasMaxLength(100);
        builder.Property(d => d.CarModel).IsRequired().HasMaxLength(100);
        builder.Property(d => d.CarColor).IsRequired().HasMaxLength(50);
        builder.Property(d => d.LicensePlate).IsRequired().HasMaxLength(20);
        builder.Property(d => d.DriverLicense).IsRequired().HasMaxLength(50);
        builder.Property(d => d.TotalEarnings).HasPrecision(12, 2);
        builder.Property(d => d.TodayEarnings).HasPrecision(10, 2);
        builder.Property(d => d.Balance).HasPrecision(10, 2);
        builder.Property(d => d.MinBalanceForOrders).HasPrecision(10, 2);
        builder.HasOne(d => d.User)
            .WithOne(u => u.DriverProfile)
            .HasForeignKey<Driver>(d => d.UserId)
            .OnDelete(DeleteBehavior.Cascade);
        builder.HasIndex(d => d.LicensePlate).IsUnique();
        builder.HasIndex(d => d.UserId).IsUnique();
        builder.HasIndex(d => d.Status);
    }
}
