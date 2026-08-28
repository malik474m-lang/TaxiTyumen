using Microsoft.AspNetCore.SignalR;
using TaxiService.Core.Interfaces;
using TaxiService.Core.Services;

namespace TaxiService.API.Services;

public class SignalRNotifier : ISignalRNotifier
{
    private readonly IHubContext<TaxiHub> _hubContext;

    public SignalRNotifier(IHubContext<TaxiHub> hubContext)
    {
        _hubContext = hubContext;
    }

    public async Task SendToUserAsync(string userId, string method, object payload)
    {
        await _hubContext.Clients.User(userId).SendAsync(method, payload);
    }

    public async Task SendToUsersAsync(List<string> userIds, string method, object payload)
    {
        await _hubContext.Clients.Users(userIds).SendAsync(method, payload);
    }

    public async Task SendToGroupAsync(string groupName, string method, object payload)
    {
        await _hubContext.Clients.Group(groupName).SendAsync(method, payload);
    }
}
