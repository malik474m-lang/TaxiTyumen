using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TaxiService.Domain.Entities;

namespace TaxiService.Infrastructure.Data.Configurations;

public class OperatorProfileConfiguration : IEntityTypeConfiguration<OperatorProfile>
{
    public void Configure(EntityTypeBuilder<OperatorProfile> builder)
    {
        builder.ToTable("operator_profiles");
        builder.HasKey(o => o.Id);
        builder.Property(o => o.RatePerOrder).HasPrecision(10, 2);
        builder.Property(o => o.RatePerHour).HasPrecision(10, 2);
        builder.Property(o => o.RatePerDay).HasPrecision(10, 2);
        builder.Property(o => o.FixedMonthly).HasPrecision(12, 2);
        builder.Property(o => o.TotalEarnings).HasPrecision(12, 2);

        builder.HasOne(o => o.User)
            .WithMany()
            .HasForeignKey(o => o.UserId)
            .OnDelete(DeleteBehavior.Cascade);

        builder.HasIndex(o => o.UserId).IsUnique();
    }
}