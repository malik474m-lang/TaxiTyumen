using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TaxiService.Domain.Entities;

namespace TaxiService.Infrastructure.Data.Configurations;

public class ChatMessageConfiguration : IEntityTypeConfiguration<ChatMessage>
{
    public void Configure(EntityTypeBuilder<ChatMessage> builder)
    {
        builder.ToTable("chat_messages");
        builder.HasKey(m => m.Id);
        builder.Property(m => m.Text).IsRequired().HasMaxLength(1000);
        builder.Property(m => m.SenderRole).IsRequired().HasMaxLength(20);

        builder.HasOne(m => m.Order)
            .WithMany()
            .HasForeignKey(m => m.OrderId)
            .OnDelete(DeleteBehavior.Cascade);

        builder.HasOne(m => m.Sender)
            .WithMany()
            .HasForeignKey(m => m.SenderId)
            .OnDelete(DeleteBehavior.Cascade);

        builder.HasIndex(m => new { m.OrderId, m.CreatedAt });
    }
}