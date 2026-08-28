using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TaxiService.Domain.Entities;

namespace TaxiService.Infrastructure.Data.Configurations;

public class OrderRejectionConfiguration : IEntityTypeConfiguration<OrderRejection>
{
    public void Configure(EntityTypeBuilder<OrderRejection> builder)
    {
        builder.ToTable("order_rejections");
        builder.HasKey(r => r.Id);
        builder.Property(r => r.Reason).HasMaxLength(500);
        builder.HasOne(r => r.Order)
            .WithMany(o => o.Rejections)
            .HasForeignKey(r => r.OrderId)
            .OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(r => r.Driver)
            .WithMany()
            .HasForeignKey(r => r.DriverId)
            .OnDelete(DeleteBehavior.Cascade);
        builder.HasIndex(r => new { r.OrderId, r.DriverId });
    }
}
