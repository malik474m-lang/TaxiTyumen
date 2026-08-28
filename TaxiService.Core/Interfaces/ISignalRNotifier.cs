namespace TaxiService.Core.Interfaces;

/// <summary>
/// Абстракция над SignalR. Реализация живёт в API и внедряется через DI.
/// Так Core не зависит от конкретного Hub.
/// </summary>
public interface ISignalRNotifier
{
    Task SendToUserAsync(string userId, string method, object payload);
    Task SendToUsersAsync(List<string> userIds, string method, object payload);
    Task SendToGroupAsync(string groupName, string method, object payload);
}
