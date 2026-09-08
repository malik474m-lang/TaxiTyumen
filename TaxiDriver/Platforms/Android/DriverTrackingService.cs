using Android.App;
using Android.Content;
using Android.Content.PM;
using Android.Graphics;
using Android.Graphics.Drawables;
using Android.OS;
using Android.Views;
using Android.Widget;
using TaxiDriver.Services;

// Явные алиасы: эти имена есть и в Android, и в Microsoft.Maui.Controls
// (implicit usings MAUI), без них сборка падает на неоднозначности.
using AButton = Android.Widget.Button;
using AColor = Android.Graphics.Color;
using AView = Android.Views.View;
using AOrientation = Android.Widget.Orientation;

namespace TaxiDriver.Platforms.Android;

// Фоновый GPS-трекинг «на линии» + плавающая панель заказа поверх Яндекс
// Навигатора. Foreground-сервис держит процесс живым, пока приложение свёрнуто:
// координаты продолжают уходить на сервер (машину видно в админке, у оператора
// и в приложении клиента), а кнопки заказа доступны прямо над картой Навигатора.
[Service(Exported = false, ForegroundServiceType = global::Android.Content.PM.ForegroundService.TypeLocation)]
public class DriverTrackingService : Service
{
    public const string ChannelId = "taxi_driver_tracking";
    public const int NotificationId = 42001;
    public const string ActionStop = "ru.taxityumen.driver.action.STOP_TRACKING";

    // ── Плавающая панель ────────────────────────────────────────────────────
    private IWindowManager? _windowManager;
    private LinearLayout? _overlayRoot;
    private WindowManagerLayoutParams? _overlayParams;
    private TextView? _titleView;
    private TextView? _subtitleView;
    private AButton? _actionButton;
    private AButton? _waitingButton;

    // ── Страховочный GPS-опрос, пока приложение свёрнуто ────────────────────
    private Handler? _pollHandler;
    private Java.Lang.IRunnable? _pollRunnable;
    private const int PollIntervalMs = 5000;
    private long _lastFixTimeMs;     // время последней принятой GPS-точки
    private long _lastPublishMs;     // когда мы в последний раз отдали точку приложению

    public override IBinder? OnBind(Intent? intent) => null;

    // ── Управление из общего кода (Services/NavigatorOverlay) ───────────────

    /// Живой экземпляр сервиса. Панель управляется только через него:
    /// поднимать сервис ради оверлея нельзя — foregroundServiceType=location
    /// без выданной геолокации роняет процесс (SecurityException, Android 14+).
    private static DriverTrackingService? _instance;

    public static bool IsRunning => _instance != null;

    public static void ShowOverlay(OverlayState state)
    {
        var service = _instance;
        if (service == null) return;   // водитель не «на линии» — панели нет
        try
        {
            service.RunOnMain(() => service.ShowOrUpdateOverlay(
                state.Title ?? "",
                state.Subtitle ?? "",
                state.ActionText ?? "",
                state.ActionColor ?? "#4CAF50",
                state.WaitingText ?? ""));
        }
        catch { }
    }

    public static void HideOverlay()
    {
        var service = _instance;
        if (service == null) return;
        try
        {
            service.RunOnMain(service.RemoveOverlay);
        }
        catch { }
    }

    private void RunOnMain(Action action)
    {
        try
        {
            new Handler(Looper.MainLooper!).Post(action);
        }
        catch { }
    }

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
            RemoveOverlay();
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
        RemoveOverlay();
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

    // ── Панель поверх других приложений ─────────────────────────────────────

    private int Dp(int value) => (int)(value * Resources!.DisplayMetrics!.Density);

    internal void ShowOrUpdateOverlay(
        string title, string subtitle, string actionText, string actionColor, string waitingText)
    {
        try
        {
            if (!global::Android.Provider.Settings.CanDrawOverlays(this))
                return;   // разрешение не выдано — молча работаем без панели

            if (_overlayRoot == null)
                BuildOverlay();

            if (_titleView != null) _titleView.Text = title;
            if (_subtitleView != null) _subtitleView.Text = subtitle;

            if (_actionButton != null)
            {
                _actionButton.Text = string.IsNullOrWhiteSpace(actionText) ? "Заказ" : actionText;
                _actionButton.Background = RoundedBackground(ParseColor(actionColor, "#4CAF50"), 10);
                _actionButton.Visibility = string.IsNullOrWhiteSpace(actionText)
                    ? ViewStates.Gone : ViewStates.Visible;
            }

            if (_waitingButton != null)
            {
                var hasWaiting = !string.IsNullOrWhiteSpace(waitingText);
                _waitingButton.Text = hasWaiting ? waitingText : "";
                _waitingButton.Visibility = hasWaiting ? ViewStates.Visible : ViewStates.Gone;
            }
        }
        catch { }
    }

    private void BuildOverlay()
    {
        _windowManager ??= GetSystemService(Context.WindowService) as IWindowManager;
        if (_windowManager == null) return;

        var root = new LinearLayout(this)
        {
            Orientation = AOrientation.Vertical,
        };
        root.SetPadding(Dp(14), Dp(12), Dp(14), Dp(12));
        root.Background = RoundedBackground(AColor.ParseColor("#F00E0E12"), 16);

        _titleView = new TextView(this) { TextSize = 15f };
        _titleView.SetTextColor(AColor.White);
        _titleView.SetMaxLines(1);
        _titleView.SetTypeface(Typeface.Default, TypefaceStyle.Bold);
        root.AddView(_titleView);

        _subtitleView = new TextView(this) { TextSize = 12.5f };
        _subtitleView.SetTextColor(AColor.ParseColor("#A1A1AA"));
        _subtitleView.SetMaxLines(2);
        var subtitleParams = new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MatchParent, ViewGroup.LayoutParams.WrapContent);
        subtitleParams.TopMargin = Dp(2);
        root.AddView(_subtitleView, subtitleParams);

        var row = new LinearLayout(this) { Orientation = AOrientation.Horizontal };
        var rowParams = new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MatchParent, ViewGroup.LayoutParams.WrapContent);
        rowParams.TopMargin = Dp(10);
        root.AddView(row, rowParams);

        _actionButton = CreateButton("Заказ", "#4CAF50", 2f);
        _actionButton.Click += (_, _) => NavigatorOverlay.RaiseAction("status");
        row.AddView(_actionButton);

        _waitingButton = CreateButton("Простой", "#F59E0B", 1.2f);
        _waitingButton.Click += (_, _) => NavigatorOverlay.RaiseAction("waiting");
        _waitingButton.Visibility = ViewStates.Gone;
        row.AddView(_waitingButton);

        var sosButton = CreateButton("SOS", "#DC2626", 1f);
        sosButton.Click += (_, _) => NavigatorOverlay.RaiseAction("sos");
        row.AddView(sosButton);

        var appButton = CreateButton("Меню", "#3F3F46", 1f);
        appButton.Click += (_, _) =>
        {
            YandexNavigatorLauncher.BringAppToFront();
            NavigatorOverlay.RaiseAction("app");
        };
        row.AddView(appButton);

        _overlayParams = new WindowManagerLayoutParams(
            ViewGroup.LayoutParams.MatchParent,
            ViewGroup.LayoutParams.WrapContent,
            WindowManagerTypes.ApplicationOverlay,     // minSdk 26 — тип доступен всегда
            WindowManagerFlags.NotFocusable            // ввод остаётся у Навигатора
                | WindowManagerFlags.NotTouchModal
                | WindowManagerFlags.LayoutNoLimits,
            Format.Translucent)
        {
            Gravity = GravityFlags.Bottom | GravityFlags.CenterHorizontal,
            Y = Dp(90),
        };

        AttachDragHandler(root);

        try
        {
            _windowManager.AddView(root, _overlayParams);
            _overlayRoot = root;
        }
        catch
        {
            _overlayRoot = null;   // например, разрешение отозвали между проверкой и добавлением
        }
    }

    /// Панель можно перетаскивать за свободное место — кнопки не перекрывают карту.
    private void AttachDragHandler(AView root)
    {
        var startY = 0;
        var startTouchY = 0f;
        root.Touch += (sender, args) =>
        {
            if (_overlayParams == null || _windowManager == null || args.Event == null) return;
            switch (args.Event.Action)
            {
                case MotionEventActions.Down:
                    startY = _overlayParams.Y;
                    startTouchY = args.Event.RawY;
                    args.Handled = true;
                    break;
                case MotionEventActions.Move:
                    // Gravity=Bottom: увеличение Y поднимает панель вверх
                    var delta = (int)(startTouchY - args.Event.RawY);
                    _overlayParams.Y = Math.Max(0, startY + delta);
                    try { _windowManager.UpdateViewLayout(root, _overlayParams); } catch { }
                    args.Handled = true;
                    break;
                default:
                    args.Handled = false;
                    break;
            }
        };
    }

    private AButton CreateButton(string text, string colorHex, float weight)
    {
        var button = new AButton(this)
        {
            Text = text,
            TextSize = 13f,
            LayoutParameters = new LinearLayout.LayoutParams(
                0, Dp(46), weight) { LeftMargin = Dp(3), RightMargin = Dp(3) },
        };
        button.SetTextColor(AColor.White);
        button.SetAllCaps(false);
        button.SetPadding(Dp(2), 0, Dp(2), 0);
        button.Background = RoundedBackground(ParseColor(colorHex, "#3F3F46"), 10);
        return button;
    }

    private Drawable RoundedBackground(AColor color, int radiusDp)
    {
        var drawable = new GradientDrawable();
        drawable.SetShape(ShapeType.Rectangle);
        drawable.SetColor(color);
        drawable.SetCornerRadius(Dp(radiusDp));
        return drawable;
    }

    private static AColor ParseColor(string value, string fallback)
    {
        try { return AColor.ParseColor(value); }
        catch { return AColor.ParseColor(fallback); }
    }

    internal void RemoveOverlay()
    {
        try
        {
            if (_overlayRoot != null && _windowManager != null)
                _windowManager.RemoveView(_overlayRoot);
        }
        catch { }
        finally
        {
            _overlayRoot = null;
            _titleView = null;
            _subtitleView = null;
            _actionButton = null;
            _waitingButton = null;
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
