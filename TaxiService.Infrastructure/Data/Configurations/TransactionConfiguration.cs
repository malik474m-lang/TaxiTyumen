using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TaxiService.Domain.Entities;

namespace TaxiService.Infrastructure.Data.Configurations;

public class TransactionConfiguration : IEntityTypeConfiguration<Transaction>
{
    public void Configure(EntityTypeBuilder<Transaction> builder)
    {
        builder.ToTable("transactions");
        builder.HasKey(t => t.Id);
        builder.Property(t => t.Amount).HasPrecision(10, 2);
        builder.Property(t => t.ExternalTransactionId).HasMaxLength(200);
        builder.Property(t => t.FailureReason).HasMaxLength(500);
        builder.HasIndex(t => t.OrderId);
        builder.HasIndex(t => t.Status);
    }
}
