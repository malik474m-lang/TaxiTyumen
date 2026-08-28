using System.IdentityModel.Tokens.Jwt;
using System.Security.Claims;
using System.Security.Cryptography;
using System.Text;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Configuration;
using Microsoft.IdentityModel.Tokens;
using TaxiService.Core.DTOs.Auth;
using TaxiService.Core.Interfaces;
using TaxiService.Domain.Entities;
using TaxiService.Domain.Enums;
using TaxiService.Infrastructure.Data;

namespace TaxiService.Core.Services;

public class AuthService : IAuthService
{
    private readonly TaxiDbContext _db;
    private readonly IConfiguration _config;

    public AuthService(TaxiDbContext db, IConfiguration config)
    {
        _db = db;
        _config = config;
    }

    public async Task<AuthResponse> RegisterAsync(RegisterRequest request)
    {
        if (await _db.Users.AnyAsync(u => u.Phone == request.Phone))
            throw new InvalidOperationException("Пользователь с таким номером уже существует");

        if (!string.IsNullOrEmpty(request.Email) &&
            await _db.Users.AnyAsync(u => u.Email == request.Email))
            throw new InvalidOperationException("Пользователь с таким email уже существует");

        var user = new User
        {
            Phone = request.Phone,
            FirstName = request.FirstName,
            LastName = request.LastName,
            Email = request.Email,
            PasswordHash = BCrypt.Net.BCrypt.HashPassword(request.Password),
            Role = request.Role,
            IsPhoneVerified = false
        };

        _db.Users.Add(user);
        await _db.SaveChangesAsync();

        return BuildAuthResponse(user, null);
    }

    public async Task<AuthResponse> LoginAsync(LoginRequest request)
    {
        var user = await _db.Users
            .Include(u => u.DriverProfile)
            .FirstOrDefaultAsync(u => u.Phone == request.Phone)
            ?? throw new InvalidOperationException("Пользователь не найден");

        if (!BCrypt.Net.BCrypt.Verify(request.Password, user.PasswordHash))
            throw new InvalidOperationException("Неверный пароль");

        if (user.IsBlocked)
            throw new InvalidOperationException($"Аккаунт заблокирован: {user.BlockReason}");

        if (!user.IsActive)
            throw new InvalidOperationException("Аккаунт деактивирован");

        user.LastLoginAt = DateTime.UtcNow;
        await _db.SaveChangesAsync();

        return BuildAuthResponse(user, user.DriverProfile?.Id);
    }

    public async Task<AuthResponse> RefreshTokenAsync(RefreshTokenRequest request)
    {
        var principal = GetPrincipalFromExpiredToken(request.Token);
        var userId = principal.FindFirst(ClaimTypes.NameIdentifier)?.Value
            ?? throw new InvalidOperationException("Невалидный токен");

        var user = await _db.Users
            .Include(u => u.DriverProfile)
            .FirstOrDefaultAsync(u => u.Id == Guid.Parse(userId))
            ?? throw new InvalidOperationException("Пользователь не найден");

        return BuildAuthResponse(user, user.DriverProfile?.Id);
    }

    public async Task SendSmsCodeAsync(string phone)
    {
        var user = await _db.Users.FirstOrDefaultAsync(u => u.Phone == phone)
            ?? throw new InvalidOperationException("Пользователь не найден");

        var code = Random.Shared.Next(1000, 9999).ToString();
        user.SmsCode = code;
        user.SmsCodeExpiry = DateTime.UtcNow.AddMinutes(5);
        await _db.SaveChangesAsync();

        // TODO: Подключить SMS-провайдер
        Console.WriteLine($"[SMS] Код для {phone}: {code}");
    }

    public async Task<bool> VerifySmsCodeAsync(VerifySmsRequest request)
    {
        var user = await _db.Users.FirstOrDefaultAsync(u => u.Phone == request.Phone)
            ?? throw new InvalidOperationException("Пользователь не найден");

        if (user.SmsCode != request.Code) return false;

        if (user.SmsCodeExpiry < DateTime.UtcNow)
            throw new InvalidOperationException("Код истёк");

        user.IsPhoneVerified = true;
        user.SmsCode = null;
        user.SmsCodeExpiry = null;
        await _db.SaveChangesAsync();

        return true;
    }

    private AuthResponse BuildAuthResponse(User user, Guid? driverId)
    {
        return new AuthResponse
        {
            UserId = user.Id,
            Token = GenerateJwtToken(user),
            RefreshToken = GenerateRefreshToken(),
            TokenExpiry = DateTime.UtcNow.AddHours(24),
            FirstName = user.FirstName,
            LastName = user.LastName,
            Phone = user.Phone,
            Role = user.Role,
            DriverId = driverId
        };
    }

    private string GenerateJwtToken(User user)
    {
        var key = new SymmetricSecurityKey(Encoding.UTF8.GetBytes(_config["Jwt:Key"]!));
        var claims = new List<Claim>
        {
            new(ClaimTypes.NameIdentifier, user.Id.ToString()),
            new(ClaimTypes.MobilePhone, user.Phone),
            new(ClaimTypes.Name, $"{user.FirstName} {user.LastName}"),
            new(ClaimTypes.Role, user.Role.ToString()),
            new("role", user.Role.ToString())
        };

        if (user.DriverProfile != null)
            claims.Add(new Claim("driverId", user.DriverProfile.Id.ToString()));

        var descriptor = new SecurityTokenDescriptor
        {
            Subject = new ClaimsIdentity(claims),
            Expires = DateTime.UtcNow.AddHours(24),
            Issuer = _config["Jwt:Issuer"],
            Audience = _config["Jwt:Audience"],
            SigningCredentials = new SigningCredentials(key, SecurityAlgorithms.HmacSha256)
        };

        var handler = new JwtSecurityTokenHandler();
        return handler.WriteToken(handler.CreateToken(descriptor));
    }

    private static string GenerateRefreshToken()
    {
        var bytes = new byte[64];
        using var rng = RandomNumberGenerator.Create();
        rng.GetBytes(bytes);
        return Convert.ToBase64String(bytes);
    }

    private ClaimsPrincipal GetPrincipalFromExpiredToken(string token)
    {
        var key = Encoding.UTF8.GetBytes(_config["Jwt:Key"]!);
        var handler = new JwtSecurityTokenHandler();
        return handler.ValidateToken(token, new TokenValidationParameters
        {
            ValidateIssuer = true,
            ValidateAudience = true,
            ValidateLifetime = false,
            ValidateIssuerSigningKey = true,
            ValidIssuer = _config["Jwt:Issuer"],
            ValidAudience = _config["Jwt:Audience"],
            IssuerSigningKey = new SymmetricSecurityKey(key)
        }, out _);
    }
}
