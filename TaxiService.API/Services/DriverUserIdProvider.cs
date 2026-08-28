using System.Security.Claims;
using Microsoft.AspNetCore.SignalR;

namespace TaxiService.API.Services;

public class DriverUserIdProvider : IUserIdProvider
{
    public string? GetUserId(HubConnectionContext connection)
    {
        var user = connection.User;
        if (user == null)
            return null;

        var role = user.FindFirst(ClaimTypes.Role)?.Value
                   ?? user.FindFirst("role")?.Value;

        // Для водителя используем driverId
        if (string.Equals(role, "Driver", StringComparison.OrdinalIgnoreCase))
        {
            return user.FindFirst("driverId")?.Value
                   ?? user.FindFirst(ClaimTypes.NameIdentifier)?.Value;
        }

        // Для остальных  обычный userId
        return user.FindFirst(ClaimTypes.NameIdentifier)?.Value;
    }
}