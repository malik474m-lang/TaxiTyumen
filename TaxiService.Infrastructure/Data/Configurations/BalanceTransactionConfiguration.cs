using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TaxiService.Domain.Entities;

namespace TaxiService.Infrastructure.Data.Configurations;

public class BalanceTransactionConfiguration : IEntityTypeConfiguration<BalanceTransaction>
{
    public void Configure(EntityTypeBuilder<BalanceTransaction> builder)
    {
        builder.ToTable("balance_transactions");
        builder.HasKey(b => b.Id);
        builder.Property(b => b.Amount).HasPrecision(10, 2);
        builder.Property(b => b.BalanceAfter).HasPrecision(10, 2);
        builder.Property(b => b.Description).HasMaxLength(500);
        builder.Property(b => b.CreatedBy).HasMaxLength(200);

        builder.HasOne(b => b.Driver)
            .WithMany()
            .HasForeignKey(b => b.DriverId)
            .OnDelete(DeleteBehavior.Cascade);

        builder.HasOne(b => b.Order)
            .WithMany()
            .HasForeignKey(b => b.OrderId)
            .OnDelete(DeleteBehavior.SetNull);

        builder.HasIndex(b => b.DriverId);
        builder.HasIndex(b => b.CreatedAt);
    }
}