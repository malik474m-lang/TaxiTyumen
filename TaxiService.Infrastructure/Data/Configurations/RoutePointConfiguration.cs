using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TaxiService.Domain.Entities;

namespace TaxiService.Infrastructure.Data.Configurations;

public class RoutePointConfiguration : IEntityTypeConfiguration<RoutePoint>
{
    public void Configure(EntityTypeBuilder<RoutePoint> builder)
    {
        builder.ToTable("route_points");
        builder.HasKey(r => r.Id);
        builder.Property(r => r.Address).IsRequired().HasMaxLength(500);
        builder.HasOne(r => r.Order)
            .WithMany(o => o.IntermediatePoints)
            .HasForeignKey(r => r.OrderId)
            .OnDelete(DeleteBehavior.Cascade);
    }
}
