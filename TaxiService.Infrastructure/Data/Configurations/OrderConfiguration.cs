using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TaxiService.Domain.Entities;

namespace TaxiService.Infrastructure.Data.Configurations;

public class OrderConfiguration : IEntityTypeConfiguration<Order>
{
    public void Configure(EntityTypeBuilder<Order> builder)
    {
        builder.ToTable("orders");
        builder.HasKey(o => o.Id);
        builder.Property(o => o.OrderNumber).IsRequired().HasMaxLength(30);
        builder.Property(o => o.PickupAddress).IsRequired().HasMaxLength(500);
        builder.Property(o => o.DestinationAddress).HasMaxLength(500);
        builder.Property(o => o.ClientPhone).HasMaxLength(20);
        builder.Property(o => o.ClientName).HasMaxLength(200);
        builder.Property(o => o.Comment).HasMaxLength(1000);
        builder.Property(o => o.CancellationReason).HasMaxLength(500);
        builder.Property(o => o.ClientReview).HasMaxLength(2000);
        builder.Property(o => o.DriverReview).HasMaxLength(2000);
        builder.Property(o => o.EstimatedPrice).HasPrecision(10, 2);
        builder.Property(o => o.FinalPrice).HasPrecision(10, 2);
        builder.HasOne(o => o.Client)
            .WithMany()
            .HasForeignKey(o => o.ClientId)
            .OnDelete(DeleteBehavior.SetNull);
        builder.HasOne(o => o.Operator)
            .WithMany()
            .HasForeignKey(o => o.OperatorId)
            .OnDelete(DeleteBehavior.SetNull);
        builder.HasOne(o => o.Driver)
            .WithMany(d => d.Orders)
            .HasForeignKey(o => o.DriverId)
            .OnDelete(DeleteBehavior.SetNull);
        builder.HasOne(o => o.Transaction)
            .WithOne(t => t.Order)
            .HasForeignKey<Transaction>(t => t.OrderId);
        builder.HasIndex(o => o.OrderNumber).IsUnique();
        builder.HasIndex(o => o.Status);
        builder.HasIndex(o => o.CreatedAt);
        builder.HasIndex(o => o.ClientId);
        builder.HasIndex(o => o.DriverId);
    }
}
