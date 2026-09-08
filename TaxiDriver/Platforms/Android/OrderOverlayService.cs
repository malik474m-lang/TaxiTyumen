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
using AButton = Android.Widget.Button;
using AColor = Android.Graphics.Color;
using AView = Android.Views.View;
using AOrientation = Android.Widget.Orientation;

namespace TaxiDriver.Platforms.Android;

/// <summary>
/// Плавающая панель заказа поверх Яндекс Навигатора.
///
/// Отдельный сервис (не трекинговый): ему НЕ нужен тип location, поэтому он
/// запускается всегда — даже до выхода «на линию» и без разрешения GPS.
/// Именно из-за привязки к трекингу панель раньше не появлялась.
/// </summary>
[Service(Exported = false, ForegroundServiceType = ForegroundService.TypeDataSync)]
public class OrderOverlayService : Service
{
    public const string ChannelId = "taxi_driver_overlay";
    public const int NotificationId = 42002;
    public const string ActionShow = "ru.taxityumen.driver.action.OVERLAY_SHOW";
    public const string ActionHide = "ru.taxityumen.driver.action.OVERLAY_HIDE";

    private static OrderOverlayService? _instance;
    public static bool IsRunning => _instance != null;

    private IWindowManager? _windowManager;
    private LinearLayout? _overlayRoot;
    private WindowManagerLayoutParams? _overlayParams;
    private TextView? _titleView;
    private TextView? _subtitleView;
    private AButton? _actionButton;
    private AButton? _waitingButton;

    public override IBinder? OnBind(Intent? intent) => null;

    // ── Управление из общего кода ───────────────────────────────────────────

    public static void Show(OverlayState state)
    {
        try
        {
            var context = global::Android.App.Application.Context;
            var intent = new Intent(context, typeof(OrderOverlayService));
            intent.SetAction(ActionShow);
            intent.PutExtra("title", state.Title ?? "");
            intent.PutExtra("subtitle", state.Subtitle ?? "");
            intent.PutExtra("actionText", state.ActionText ?? "");
            intent.PutExtra("actionColor", state.ActionColor ?? "#4CAF50");
            intent.PutExtra("waitingText", state.WaitingText ?? "");
            if (Build.VERSION.SdkInt >= BuildVersionCodes.O)
                context.StartForegroundService(intent);
            else
                context.StartService(intent);
        }
        catch (Exception ex)
        {
            YandexNavigatorLauncher.Toast("Панель заказа: " + ex.Message);
        }
    }

    public static void Hide()
    {
        try
        {
            var context = global::Android.App.Application.Context;
            var intent = new Intent(context, typeof(OrderOverlayService));
            intent.SetAction(ActionHide);
            context.StartService(intent);
        }
        catch { }
    }

    // ── Жизненный цикл ──────────────────────────────────────────────────────

    public override void OnCreate()
    {
        base.OnCreate();
        _instance = this;
    }

    public override StartCommandResult OnStartCommand(Intent? intent, StartCommandFlags flags, int startId)
    {
        _instance = this;
        EnsureForeground();

        if (intent?.Action == ActionHide)
        {
            RemoveOverlay();
#pragma warning disable CA1422
            StopForeground(true);
#pragma warning restore CA1422
            StopSelf();
            return StartCommandResult.NotSticky;
        }

        if (intent != null)
        {
            ShowOrUpdateOverlay(
                intent.GetStringExtra("title") ?? "",
                intent.GetStringExtra("subtitle") ?? "",
                intent.GetStringExtra("actionText") ?? "",
                intent.GetStringExtra("actionColor") ?? "#4CAF50",
                intent.GetStringExtra("waitingText") ?? "");
        }
        return StartCommandResult.Sticky;
    }

    public override void OnDestroy()
    {
        RemoveOverlay();
        if (ReferenceEquals(_instance, this)) _instance = null;
        base.OnDestroy();
    }

    private void EnsureForeground()
    {
        try
        {
            var manager = (NotificationManager?)GetSystemService(Context.NotificationService);
            if (manager != null && manager.GetNotificationChannel(ChannelId) == null)
            {
                manager.CreateNotificationChannel(new NotificationChannel(
                    ChannelId, "Панель заказа", NotificationImportance.Low)
                {
                    Description = "Кнопки заказа поверх Яндекс Навигатора",
                });
            }

            var launch = PackageManager?.GetLaunchIntentForPackage(PackageName!);
            var pending = launch == null ? null : PendingIntent.GetActivity(
                this, 0, launch, PendingIntentFlags.Immutable | PendingIntentFlags.UpdateCurrent);

            var builder = new Notification.Builder(this, ChannelId)
                .SetContentTitle("Заказ в работе")
                .SetContentText("Кнопки заказа показаны поверх Навигатора")
                .SetSmallIcon(ApplicationInfo!.Icon)
                .SetOngoing(true)
                .SetOnlyAlertOnce(true);
            if (pending != null) builder.SetContentIntent(pending);

            if (Build.VERSION.SdkInt >= BuildVersionCodes.Q)
                StartForeground(NotificationId, builder.Build()!, ForegroundService.TypeDataSync);
            else
                StartForeground(NotificationId, builder.Build()!);
        }
        catch { /* панель попробует работать и без foreground */ }
    }

    // ── Отрисовка панели ────────────────────────────────────────────────────

    private int Dp(int value) => (int)(value * Resources!.DisplayMetrics!.Density);

    internal void ShowOrUpdateOverlay(
        string title, string subtitle, string actionText, string actionColor, string waitingText)
    {
        try
        {
            if (Build.VERSION.SdkInt >= BuildVersionCodes.M
                && !global::Android.Provider.Settings.CanDrawOverlays(this))
            {
                YandexNavigatorLauncher.Toast(
                    "Разрешите «Поверх других приложений», чтобы видеть кнопки заказа");
                return;
            }

            if (_overlayRoot == null) BuildOverlay();
            if (_overlayRoot == null) return;

            if (_titleView != null) _titleView.Text = title;
            if (_subtitleView != null) _subtitleView.Text = subtitle;

            if (_actionButton != null)
            {
                _actionButton.Text = string.IsNullOrWhiteSpace(actionText) ? "Заказ" : actionText;
                _actionButton.Background = RoundedBackground(ParseColor(actionColor, "#4CAF50"), 10);
            }
            if (_waitingButton != null)
            {
                var hasWaiting = !string.IsNullOrWhiteSpace(waitingText);
                _waitingButton.Text = hasWaiting ? waitingText : "";
                _waitingButton.Visibility = hasWaiting ? ViewStates.Visible : ViewStates.Gone;
            }
        }
        catch (Exception ex)
        {
            YandexNavigatorLauncher.Toast("Панель заказа: " + ex.Message);
        }
    }

    private void BuildOverlay()
    {
        _windowManager ??= GetSystemService(Context.WindowService) as IWindowManager;
        if (_windowManager == null) return;

        var root = new LinearLayout(this) { Orientation = AOrientation.Vertical };
        root.SetPadding(Dp(14), Dp(10), Dp(14), Dp(12));
        root.Background = RoundedBackground(AColor.ParseColor("#F50E0E12"), 16);

        // Полоска-«ручка»: за неё панель перетаскивается
        var handle = new AView(this);
        var handleParams = new LinearLayout.LayoutParams(Dp(44), Dp(4)) { BottomMargin = Dp(8) };
        handleParams.Gravity = GravityFlags.CenterHorizontal;
        handle.Background = RoundedBackground(AColor.ParseColor("#52525B"), 2);
        root.AddView(handle, handleParams);

        _titleView = new TextView(this) { TextSize = 15f };
        _titleView.SetTextColor(AColor.White);
        _titleView.SetMaxLines(1);
        _titleView.SetTypeface(Typeface.Default, TypefaceStyle.Bold);
        root.AddView(_titleView);

        _subtitleView = new TextView(this) { TextSize = 12.5f };
        _subtitleView.SetTextColor(AColor.ParseColor("#A1A1AA"));
        _subtitleView.SetMaxLines(2);
        var subParams = new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MatchParent, ViewGroup.LayoutParams.WrapContent) { TopMargin = Dp(2) };
        root.AddView(_subtitleView, subParams);

        var row = new LinearLayout(this) { Orientation = AOrientation.Horizontal };
        var rowParams = new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MatchParent, ViewGroup.LayoutParams.WrapContent) { TopMargin = Dp(10) };
        root.AddView(row, rowParams);

        _actionButton = CreateButton("Заказ", "#4CAF50", 2.2f);
        _actionButton.Click += (_, _) => NavigatorOverlay.RaiseAction("status");
        row.AddView(_actionButton);

        _waitingButton = CreateButton("Простой", "#F59E0B", 1.3f);
        _waitingButton.Click += (_, _) => NavigatorOverlay.RaiseAction("waiting");
        _waitingButton.Visibility = ViewStates.Gone;
        row.AddView(_waitingButton);

        var sos = CreateButton("SOS", "#DC2626", 1f);
        sos.Click += (_, _) => NavigatorOverlay.RaiseAction("sos");
        row.AddView(sos);

        var app = CreateButton("Меню", "#3F3F46", 1f);
        app.Click += (_, _) =>
        {
            YandexNavigatorLauncher.BringAppToFront();
            NavigatorOverlay.RaiseAction("app");
        };
        row.AddView(app);

        var flags = WindowManagerFlags.NotFocusable
                  | WindowManagerFlags.LayoutInScreen;

        _overlayParams = new WindowManagerLayoutParams(
            ViewGroup.LayoutParams.MatchParent,
            ViewGroup.LayoutParams.WrapContent,
            WindowManagerTypes.ApplicationOverlay,
            flags,
            Format.Translucent)
        {
            Gravity = GravityFlags.Bottom | GravityFlags.CenterHorizontal,
            X = 0,
            Y = Dp(40),
        };

        AttachDragHandler(handle, root);

        try
        {
            _windowManager.AddView(root, _overlayParams);
            _overlayRoot = root;
        }
        catch (Exception ex)
        {
            _overlayRoot = null;
            YandexNavigatorLauncher.Toast("Не удалось показать панель: " + ex.Message);
        }
    }

    /// Перетаскивание за «ручку» — кнопки при этом остаются нажимаемыми.
    private void AttachDragHandler(AView handle, AView root)
    {
        var startY = 0;
        var startTouchY = 0f;
        handle.Touch += (sender, args) =>
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
            LayoutParameters = new LinearLayout.LayoutParams(0, Dp(48), weight)
            {
                LeftMargin = Dp(3),
                RightMargin = Dp(3),
            },
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
}
