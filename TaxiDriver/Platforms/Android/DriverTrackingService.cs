using Android.App;
using Android.Content;
using Android.OS;

namespace TaxiDriver.Platforms.Android;

// Фоновый GPS-трекинг «на линии»: foreground-сервис держит процесс живым,
// пока приложение свёрнуто. Логика GPS и отправки координат остаётся в
// Services/LocationService.cs (тик каждые 5 с) — сервис лишь удерживает
// приоритет процесса и показывает обязательное уведомление (Android 8+).
[Service(Exported = false, ForegroundServiceType = global::Android.Content.PM.ForegroundService.TypeLocation)]
public class DriverTrackingService : Service
{
    public const string ChannelId = "taxi_driver_tracking";
    public const int NotificationId = 42001;
    public const string ActionStop = "ru.taxityumen.driver.action.STOP_TRACKING";

    public override IBinder? OnBind(Intent? intent) => null;

    public override StartCommandResult OnStartCommand(Intent? intent, StartCommandFlags flags, int startId)
    {
        if (intent?.Action == ActionStop)
        {
#pragma warning disable CA1422 // StopForeground(bool) — совместимость со старыми Android
            StopForeground(true);
#pragma warning restore CA1422
            StopSelf();
            return StartCommandResult.NotSticky;
        }

        EnsureChannel();
        var notification = BuildNotification();

        if (Build.VERSION.SdkInt >= BuildVersionCodes.Q)
        {
            // Android 10+: явно указываем тип location (на 14+ — обязательно)
            StartForeground(NotificationId, notification,
                global::Android.Content.PM.ForegroundService.TypeLocation);
        }
        else
        {
            StartForeground(NotificationId, notification);
        }
        return StartCommandResult.Sticky;
    }

    private void EnsureChannel()
    {
        if (Build.VERSION.SdkInt < BuildVersionCodes.O) return;
        var manager = (NotificationManager?)GetSystemService(Context.NotificationService);
        if (manager == null || manager.GetNotificationChannel(ChannelId) != null) return;
        manager.CreateNotificationChannel(new NotificationChannel(
            ChannelId, "Слежение на линии", NotificationImportance.Low)
        {
            Description = "Статус передачи координат серверу, пока вы на линии",
        });
    }

    private Notification BuildNotification()
    {
        var launchIntent = PackageManager?.GetLaunchIntentForPackage(PackageName!);
        var pending = launchIntent == null
            ? null
            : PendingIntent.GetActivity(this, 0, launchIntent,
                PendingIntentFlags.Immutable | PendingIntentFlags.UpdateCurrent);

        var builder = Build.VERSION.SdkInt >= BuildVersionCodes.O
            ? new Notification.Builder(this, ChannelId)
#pragma warning disable CS0618 // конструктор без канала — только для Android < 8
            : new Notification.Builder(this);
#pragma warning restore CS0618

        builder.SetContentTitle("Вы на линии · Такси Тюмень")
            .SetContentText("Координаты передаются на сервер каждые 5 секунд")
            .SetSmallIcon(global::Android.Resource.Drawable.IcMenuMylocation)
            .SetOngoing(true)
            .SetOnlyAlertOnce(true);
        if (pending != null) builder.SetContentIntent(pending);
        return builder.Build()!;
    }
}
