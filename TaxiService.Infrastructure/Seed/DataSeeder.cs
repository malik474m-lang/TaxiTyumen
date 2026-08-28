using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;
using TaxiService.Domain.Entities;
using TaxiService.Domain.Enums;
using TaxiService.Infrastructure.Data;

namespace TaxiService.Infrastructure.Seed;

public static class DataSeeder
{
    public static async Task SeedAsync(IServiceProvider serviceProvider)
    {
        using var scope = serviceProvider.CreateScope();
        var db = scope.ServiceProvider.GetRequiredService<TaxiDbContext>();

        await db.Database.MigrateAsync();

        // Тарифы для Тюмени
        if (!await db.Tariffs.AnyAsync())
        {
            db.Tariffs.AddRange(
                new Tariff
                {
                    Id = Guid.Parse("11111111-1111-1111-1111-111111111111"),
                    Type = TariffType.Economy,
                    Name = "Эконом",
                    Description = "Бюджетные поездки по городу",
                    BaseFare = 49,
                    PricePerKm = 10,
                    PricePerMinute = 3,
                    MinimumFare = 99,
                    FreeWaitingMinutes = 3,
                    PaidWaitingPerMinute = 4,
                    NightMultiplier = 1.3m,
                    PeakMultiplier = 1.5m
                },
                new Tariff
                {
                    Id = Guid.Parse("22222222-2222-2222-2222-222222222222"),
                    Type = TariffType.Comfort,
                    Name = "Комфорт",
                    Description = "Комфортные авто, кондиционер",
                    BaseFare = 99,
                    PricePerKm = 16,
                    PricePerMinute = 5,
                    MinimumFare = 179,
                    FreeWaitingMinutes = 5,
                    PaidWaitingPerMinute = 5,
                    NightMultiplier = 1.3m,
                    PeakMultiplier = 1.4m
                },
                new Tariff
                {
                    Id = Guid.Parse("33333333-3333-3333-3333-333333333333"),
                    Type = TariffType.Business,
                    Name = "Бизнес",
                    Description = "Авто бизнес-класса",
                    BaseFare = 199,
                    PricePerKm = 25,
                    PricePerMinute = 8,
                    MinimumFare = 349,
                    FreeWaitingMinutes = 5,
                    PaidWaitingPerMinute = 8,
                    NightMultiplier = 1.2m,
                    PeakMultiplier = 1.3m
                },
                new Tariff
                {
                    Id = Guid.Parse("44444444-4444-4444-4444-444444444444"),
                    Type = TariffType.Minivan,
                    Name = "Минивэн",
                    Description = "Для больших компаний, 6+ мест",
                    BaseFare = 149,
                    PricePerKm = 20,
                    PricePerMinute = 5,
                    MinimumFare = 249,
                    FreeWaitingMinutes = 5,
                    PaidWaitingPerMinute = 5,
                    NightMultiplier = 1.3m,
                    PeakMultiplier = 1.5m
                }
            );
        }

        // Администратор по умолчанию
        if (!await db.Users.AnyAsync(u => u.Role == UserRole.Admin))
        {
            db.Users.Add(new User
            {
                Id = Guid.Parse("aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa"),
                Phone = "+79001234567",
                FirstName = "Админ",
                LastName = "Системы",
                Email = "admin@taxityumen.ru",
                PasswordHash = BCrypt.Net.BCrypt.HashPassword("Admin123!"),
                Role = UserRole.Admin,
                IsPhoneVerified = true,
                IsActive = true
            });
        }

        // Тестовый оператор
        if (!await db.Users.AnyAsync(u => u.Role == UserRole.Operator))
        {
            db.Users.Add(new User
            {
                Id = Guid.Parse("bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb"),
                Phone = "+79001234568",
                FirstName = "Оператор",
                LastName = "Тестовый",
                Email = "operator@taxityumen.ru",
                PasswordHash = BCrypt.Net.BCrypt.HashPassword("Operator123!"),
                Role = UserRole.Operator,
                IsPhoneVerified = true,
                IsActive = true
            });
        }

        await db.SaveChangesAsync();
    }
}
