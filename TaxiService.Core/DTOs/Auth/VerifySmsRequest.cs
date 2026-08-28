namespace TaxiService.Core.DTOs.Auth;

public class VerifySmsRequest
{
    public string Phone { get; set; } = string.Empty;
    public string Code { get; set; } = string.Empty;
}
