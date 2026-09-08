using Android.App;
using Android.Content;
using Android.Content.PM;
using Android.OS;
using TaxiDriver.Services;


namespace TaxiDriver.Platforms.Android;

// Фоновый GPS-трекинг «на линии»: foreground-сервис держит процесс живым, пока
// приложение свёрнуто, и дополнительно опрашивает системный GPS. Координаты
// продолжают уходить на сервер, поэтому машину видно в админке, у оператора и в
// приложении клиента, даже когда сверху открыт Яндекс Навигатор.
// Плавающая панель заказа живёт в отдельном OrderOverlayService.
[Service(Exported = false, ForegroundServiceType = global::Android.Content.PM.ForegroundService.TypeLocation)]
public class DriverTrackingService : Service
{
    public const string ChannelId = "taxi_driver_tracking";
    public const int NotificationId = 42001;
    public const string ActionStop = "ru.taxityumen.driver.action.STOP_TRACKING";

    // ── Страховочный GPS-опрос, пока приложение свёрнуто ────────────────────
    private Handler? _pollHandler;
    private Java.Lang.IRunnable? _pollRunnable;
    private const int PollIntervalMs = 5000;
    private long _lastFixTimeMs;     // время последней принятой GPS-точки
    private long _lastPublishMs;     // когда мы в последний раз отдали точку приложению

    public override IBinder? OnBind(Intent? intent) => null;

    /// Живой экземпляр: используется, чтобы понять, идёт ли трекинг «на линии».
    private static DriverTrackingService? _instance;

    public static bool IsRunning => _instance != null;

    // ── Жизненный цикл сервиса ──────────────────────────────────────────────

    public override void OnCreate()
    {
        base.OnCreate();
        _instance = this;
    }

    public override StartCommandResult OnStartCommand(Intent? intent, StartCommandFlags flags, int startId)
    {
        _instance = this;
        var action = intent?.Action;

        if (action == ActionStop)
        {
            StopLocationPolling();
#pragma warning disable CA1422 // StopForeground(bool) — совместимость со старыми Android
            StopForeground(true);
#pragma warning restore CA1422
            StopSelf();
            return StartCommandResult.NotSticky;
        }

        if (!StartForegroundSafe())
        {
            // Нет разрешения геолокации — foreground с типом location запрещён.
            // Не роняем процесс: просто не запускаем фоновый режим.
            StopSelf();
            return StartCommandResult.NotSticky;
        }

        StartLocationPolling();
        return StartCommandResult.Sticky;
    }

    public override void OnDestroy()
    {
        StopLocationPolling();
        if (ReferenceEquals(_instance, this)) _instance = null;
        base.OnDestroy();
    }

    /// Перевод в foreground с защитой: на Android 14+ тип location требует
    /// выданного ACCESS_FINE_LOCATION, иначе система бросает SecurityException.
    private bool StartForegroundSafe()
    {
        try
        {
            EnsureChannel();
            var notification = BuildNotification();
            var hasLocation = CheckSelfPermission(
                global::Android.Manifest.Permission.AccessFineLocation) == Permission.Granted
                || CheckSelfPermission(
                    global::Android.Manifest.Permission.AccessCoarseLocation) == Permission.Granted;

            if (Build.VERSION.SdkInt >= BuildVersionCodes.Q && hasLocation)
            {
                StartForeground(NotificationId, notification,
                    global::Android.Content.PM.ForegroundService.TypeLocation);
                return true;
            }
            if (Build.VERSION.SdkInt >= BuildVersionCodes.UpsideDownCake && !hasLocation)
            {
                // Android 14+: без разрешения тип location недопустим
                return false;
            }
            StartForeground(NotificationId, notification);
            return true;
        }
        catch
        {
            return false;
        }
    }

    // ── Страховочный GPS-опрос ──────────────────────────────────────────────
    // Пока водитель в Яндекс Навигаторе, тот активно обновляет системные
    // координаты. Читаем последнюю известную точку и отдаём её приложению,
    // чтобы трек на картах админки/оператора/клиента не прерывался.

    private void StartLocationPolling()
    {
        if (_pollHandler != null) return;
        _pollHandler = new Handler(Looper.MainLooper!);
        _pollRunnable = new Java.Lang.Runnable(() =>
        {
            PublishLastKnownLocation();
            _pollHandler?.PostDelayed(_pollRunnable!, PollIntervalMs);
        });
        _pollHandler.PostDelayed(_pollRunnable, PollIntervalMs);
    }

    private void StopLocationPolling()
    {
        try
        {
            if (_pollHandler != null && _pollRunnable != null)
                _pollHandler.RemoveCallbacks(_pollRunnable);
        }
        catch { }
        _pollHandler = null;
        _pollRunnable = null;
    }

    private void PublishLastKnownLocation()
    {
        try
        {
            var manager = GetSystemService(Context.LocationService) as global::Android.Locations.LocationManager;
            if (manager == null) return;

            global::Android.Locations.Location? best = null;
            foreach (var provider in manager.GetProviders(true) ?? new List<string>())
            {
                global::Android.Locations.Location? candidate;
                try { candidate = manager.GetLastKnownLocation(provider); }
                catch { continue; }   // нет разрешения на конкретный провайдер
                if (candidate == null) continue;
                if (best == null || candidate.Time > best.Time) best = candidate;
            }

            if (best == null) return;
            // Не дублируем одну и ту же точку и не чаще раза в 5 секунд
            var now = Java.Lang.JavaSystem.CurrentTimeMillis();
            if (best.Time <= _lastFixTimeMs) return;
            if (_lastPublishMs > 0 && now - _lastPublishMs < PollIntervalMs - 500) return;
            _lastFixTimeMs = best.Time;
            _lastPublishMs = now;

            NavigatorOverlay.RaiseNativeLocation(
                best.Latitude,
                best.Longitude,
                best.HasSpeed ? (double?)best.Speed : null,
                best.HasBearing ? (double?)best.Bearing : null);
        }
        catch { }
    }

    // ── Уведомление foreground-сервиса ──────────────────────────────────────

    private void EnsureChannel()
    {
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

        var builder = new Notification.Builder(this, ChannelId);
        builder.SetContentTitle("Вы на линии · Такси Тюмень")
            .SetContentText("Координаты передаются на сервер каждые 5 секунд")
            .SetSmallIcon(ApplicationInfo!.Icon)
            .SetOngoing(true)
            .SetOnlyAlertOnce(true);
        if (pending != null) builder.SetContentIntent(pending);
        return builder.Build()!;
    }
}
