using TaxiService.Core.DTOs.Auth;

namespace TaxiService.Core.Interfaces;

public interface IAuthService
{
    Task<AuthResponse> RegisterAsync(RegisterRequest request);
    Task<AuthResponse> LoginAsync(LoginRequest request);
    Task<AuthResponse> RefreshTokenAsync(RefreshTokenRequest request);
    Task SendSmsCodeAsync(string phone);
    Task<bool> VerifySmsCodeAsync(VerifySmsRequest request);
}
