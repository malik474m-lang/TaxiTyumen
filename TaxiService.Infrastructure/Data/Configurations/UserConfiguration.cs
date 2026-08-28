using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TaxiService.Domain.Entities;

namespace TaxiService.Infrastructure.Data.Configurations;

public class UserConfiguration : IEntityTypeConfiguration<User>
{
    public void Configure(EntityTypeBuilder<User> builder)
    {
        builder.ToTable("users");
        builder.HasKey(u => u.Id);
        builder.Property(u => u.Phone).IsRequired().HasMaxLength(20);
        builder.Property(u => u.FirstName).IsRequired().HasMaxLength(100);
        builder.Property(u => u.LastName).IsRequired().HasMaxLength(100);
        builder.Property(u => u.Email).HasMaxLength(200);
        builder.Property(u => u.PasswordHash).IsRequired().HasMaxLength(500);
        builder.Property(u => u.SmsCode).HasMaxLength(10);
        builder.Property(u => u.BlockReason).HasMaxLength(500);
        builder.HasIndex(u => u.Phone).IsUnique();
        builder.HasIndex(u => u.Email).IsUnique()
            .HasFilter("\"Email\" IS NOT NULL");
        builder.HasIndex(u => u.Role);
        builder.HasIndex(u => u.IsActive);
    }
}
