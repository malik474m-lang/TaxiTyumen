using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TaxiService.Domain.Entities;

namespace TaxiService.Infrastructure.Data.Configurations;

public class OperatorShiftConfiguration : IEntityTypeConfiguration<OperatorShift>
{
    public void Configure(EntityTypeBuilder<OperatorShift> builder)
    {
        builder.ToTable("operator_shifts");
        builder.HasKey(s => s.Id);
        builder.Property(s => s.Earned).HasPrecision(10, 2);

        builder.HasOne(s => s.Operator)
            .WithMany()
            .HasForeignKey(s => s.OperatorId)
            .OnDelete(DeleteBehavior.Cascade);

        builder.HasOne(s => s.Profile)
            .WithMany()
            .HasForeignKey(s => s.ProfileId)
            .OnDelete(DeleteBehavior.SetNull);

        builder.HasIndex(s => s.OperatorId);
        builder.HasIndex(s => s.StartedAt);
    }
}