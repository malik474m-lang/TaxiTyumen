using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TaxiService.Domain.Entities;

namespace TaxiService.Infrastructure.Data.Configurations;

public class OrderOptionConfiguration : IEntityTypeConfiguration<OrderOption>
{
    public void Configure(EntityTypeBuilder<OrderOption> builder)
    {
        builder.ToTable("order_options");
        builder.HasKey(o => o.Id);
        builder.Property(o => o.Name).IsRequired().HasMaxLength(200);
        builder.Property(o => o.ExtraPrice).HasPrecision(10, 2);
        builder.HasOne(o => o.Order)
            .WithMany(ord => ord.Options)
            .HasForeignKey(o => o.OrderId)
            .OnDelete(DeleteBehavior.Cascade);
    }
}
