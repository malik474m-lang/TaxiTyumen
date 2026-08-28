using TaxiService.Domain.Enums;

namespace TaxiService.Core.DTOs.Auth;

public class RegisterRequest
{
    public string Phone { get; set; } = string.Empty;
    public string FirstName { get; set; } = string.Empty;
    public string LastName { get; set; } = string.Empty;
    public string? Email { get; set; }
    public string Password { get; set; } = string.Empty;
    public UserRole Role { get; set; } = UserRole.Client;
}
