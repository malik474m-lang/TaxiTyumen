using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TaxiService.Domain.Entities;

namespace TaxiService.Infrastructure.Data.Configurations;

public class AutoCallSettingsConfiguration : IEntityTypeConfiguration<AutoCallSettings>
{
    public void Configure(EntityTypeBuilder<AutoCallSettings> builder)
    {
        builder.ToTable("auto_call_settings");
        builder.HasKey(s => s.Id);
        builder.Property(s => s.ZvonokApiKey).HasMaxLength(200);
        builder.Property(s => s.ZvonokCampaignId).HasMaxLength(100);
        builder.Property(s => s.MessageTemplate).HasMaxLength(1000);
        builder.Property(s => s.Provider).HasMaxLength(50);
        builder.Property(s => s.ZvonokBalance).HasPrecision(10, 2);
    }
}