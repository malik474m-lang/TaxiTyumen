using Android.App;
using Android.Content;
using Android.Graphics;
using Android.Graphics.Drawables;
using Android.OS;
using Android.Views;
using Android.Widget;
using TaxiDriver.Services;

using AButton = Android.Widget.Button;
using AColor = Android.Graphics.Color;
using AOrientation = Android.Widget.Orientation;

namespace TaxiDriver.Platforms.Android;

/// <summary>
/// Прозрачное компактное Activity-окно ПОВЕРХ Яндекс Навигатора.
/// Это не SYSTEM_ALERT_WINDOW: разрешение производителя/MIUI не нужно.
/// Окно занимает только нижнюю полосу, остальная карта Навигатора остаётся
/// видимой и получает касания (FLAG_NOT_TOUCH_MODAL).
/// </summary>
[Activity(
    Theme = "@style/NavigatorControlsTheme",
    Exported = false,
    ExcludeFromRecents = true,
    LaunchMode = global::Android.Content.PM.LaunchMode.SingleTop,
    NoHistory = false,
    TaskAffinity = "ru.taxityumen.driver.navigatorcontrols")]
public class NavigatorControlsActivity : Activity
{
    private static NavigatorControlsActivity? _instance;
    private static OverlayState? _pendingState;

    private TextView? _title;
    private TextView? _subtitle;
    private AButton? _action;
    private AButton? _waiting;

    private int Dp(int value) => (int)(value * Resources!.DisplayMetrics!.Density);

    /// Открыть панель через небольшую задержку ПОСЛЕ запуска Навигатора:
    /// так это окно гарантированно оказывается верхним, а не под его Activity.
    public static void Show(OverlayState state, int delayMs = 650)
    {
        _pendingState = state;
        var current = _instance;
        if (current != null && !current.IsFinishing)
            current.RunOnUiThread(() => current.ApplyState(state));

        // Intent отправляется ВСЕГДА: если Навигатор только что перестроил маршрут,
        // он снова стал верхним Activity и закрыл даже уже созданную панель.
        // ReorderToFront возвращает панель наверх после каждого такого запуска.
        BringToFront(delayMs);
    }

    public static void BringToFront(int delayMs = 650)
    {
        try
        {
            var context = global::Android.App.Application.Context;
            new Handler(Looper.MainLooper!).PostDelayed(() =>
            {
                try
                {
                    var intent = new Intent(context, typeof(NavigatorControlsActivity));
                    intent.AddFlags(ActivityFlags.NewTask
                        | ActivityFlags.ReorderToFront
                        | ActivityFlags.NoAnimation);
                    context.StartActivity(intent);
                }
                catch (Exception ex)
                {
                    YandexNavigatorLauncher.Toast("Не удалось показать кнопки: " + ex.Message);
                }
            }, delayMs);
        }
        catch (Exception ex)
        {
            YandexNavigatorLauncher.Toast("Не удалось запустить панель: " + ex.Message);
        }
    }

    public static void Update(OverlayState state)
    {
        _pendingState = state;
        var current = _instance;
        if (current != null && !current.IsFinishing)
            current.RunOnUiThread(() => current.ApplyState(state));
    }

    public static void Hide()
    {
        _pendingState = null;
        var current = _instance;
        if (current != null && !current.IsFinishing)
            current.RunOnUiThread(current.FinishAndRemoveTask);
    }

    protected override void OnCreate(Bundle? savedInstanceState)
    {
        base.OnCreate(savedInstanceState);
        _instance = this;

        // Только нижняя полоса экрана; вне неё касания идут Яндекс Навигатору.
        Window?.SetFlags(WindowManagerFlags.NotTouchModal, WindowManagerFlags.NotTouchModal);
        Window?.AddFlags(WindowManagerFlags.NotFocusable
            | WindowManagerFlags.LayoutNoLimits
            | WindowManagerFlags.LayoutInScreen);
        Window?.SetBackgroundDrawable(new ColorDrawable(AColor.Transparent));
        Window?.SetDimAmount(0f);
        Window?.SetLayout(ViewGroup.LayoutParams.MatchParent, ViewGroup.LayoutParams.WrapContent);
        Window?.SetGravity(GravityFlags.Bottom | GravityFlags.CenterHorizontal);
        if (Window?.Attributes is { } attrs)
        {
            attrs.Y = Dp(28);
            Window.Attributes = attrs;
        }

        SetContentView(BuildPanel());
        ApplyState(_pendingState ?? new OverlayState { ActionText = "Заказ" });
    }

    protected override void OnNewIntent(Intent? intent)
    {
        base.OnNewIntent(intent);
        if (_pendingState != null) ApplyState(_pendingState);
    }

    protected override void OnDestroy()
    {
        if (ReferenceEquals(_instance, this)) _instance = null;
        base.OnDestroy();
    }

    // Не закрываем панель системной кнопкой «Назад» случайно — она нужна всю поездку.
    public override void OnBackPressed()
    {
        YandexNavigatorLauncher.BringAppToFront();
    }

    private LinearLayout BuildPanel()
    {
        var root = new LinearLayout(this) { Orientation = AOrientation.Vertical };
        root.SetPadding(Dp(12), Dp(9), Dp(12), Dp(11));
        root.Background = Rounded(AColor.ParseColor("#FA111116"), 16);

        _title = new TextView(this) { TextSize = 15f };
        _title.SetTextColor(AColor.White);
        _title.SetTypeface(Android.Graphics.Typeface.Default, Android.Graphics.TypefaceStyle.Bold);
        _title.SetMaxLines(1);
        root.AddView(_title);

        _subtitle = new TextView(this) { TextSize = 12f };
        _subtitle.SetTextColor(AColor.ParseColor("#D4D4D8"));
        _subtitle.SetMaxLines(2);
        root.AddView(_subtitle, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MatchParent, ViewGroup.LayoutParams.WrapContent)
        { TopMargin = Dp(2) });

        var row = new LinearLayout(this) { Orientation = AOrientation.Horizontal };
        root.AddView(row, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MatchParent, ViewGroup.LayoutParams.WrapContent)
        { TopMargin = Dp(8) });

        _action = Button("Этап", "#4CAF50", 2.2f);
        _action.Click += (_, _) => NavigatorOverlay.RaiseAction("status");
        row.AddView(_action);

        _waiting = Button("Простой", "#F59E0B", 1.25f);
        _waiting.Click += (_, _) => NavigatorOverlay.RaiseAction("waiting");
        row.AddView(_waiting);

        // Второй ряд: чат, отмена, SOS и полная карточка заказа
        var tools = new LinearLayout(this) { Orientation = AOrientation.Horizontal };
        root.AddView(tools, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MatchParent, ViewGroup.LayoutParams.WrapContent)
        { TopMargin = Dp(6) });

        var chat = Button("Чат", "#2563EB", 1f);
        chat.Click += (_, _) =>
        {
            YandexNavigatorLauncher.BringAppToFront();
            NavigatorOverlay.RaiseAction("chat");
        };
        tools.AddView(chat);

        var cancel = Button("Отмена", "#52525B", 1f);
        cancel.Click += (_, _) =>
        {
            YandexNavigatorLauncher.BringAppToFront();
            NavigatorOverlay.RaiseAction("cancel");
        };
        tools.AddView(cancel);

        var sos = Button("SOS", "#DC2626", 1f);
        sos.Click += (_, _) =>
        {
            YandexNavigatorLauncher.BringAppToFront();
            NavigatorOverlay.RaiseAction("sos");
        };
        tools.AddView(sos);

        var menu = Button("Заказ", "#3F3F46", 1f);
        menu.Click += (_, _) =>
        {
            YandexNavigatorLauncher.BringAppToFront();
            NavigatorOverlay.RaiseAction("app");
        };
        tools.AddView(menu);

        return root;
    }

    private void ApplyState(OverlayState state)
    {
        if (_title != null) _title.Text = state.Title;
        if (_subtitle != null) _subtitle.Text = state.Subtitle;
        if (_action != null)
        {
            _action.Text = string.IsNullOrWhiteSpace(state.ActionText) ? "Этап" : state.ActionText;
            _action.Background = Rounded(ParseColor(state.ActionColor, "#4CAF50"), 10);
        }
        if (_waiting != null)
        {
            var show = !string.IsNullOrWhiteSpace(state.WaitingText);
            _waiting.Text = show ? state.WaitingText : "Простой";
            _waiting.Visibility = show ? ViewStates.Visible : ViewStates.Gone;
        }
    }

    private AButton Button(string text, string color, float weight)
    {
        var button = new AButton(this)
        {
            Text = text,
            TextSize = 13f,
            LayoutParameters = new LinearLayout.LayoutParams(0, Dp(48), weight)
            { LeftMargin = Dp(3), RightMargin = Dp(3) },
        };
        button.SetAllCaps(false);
        button.SetTextColor(AColor.White);
        button.SetPadding(Dp(2), 0, Dp(2), 0);
        button.Background = Rounded(ParseColor(color, "#3F3F46"), 10);
        return button;
    }

    private GradientDrawable Rounded(AColor color, int radius)
    {
        var d = new GradientDrawable();
        d.SetShape(ShapeType.Rectangle);
        d.SetColor(color);
        d.SetCornerRadius(Dp(radius));
        return d;
    }

    private static AColor ParseColor(string value, string fallback)
    {
        try { return AColor.ParseColor(value); }
        catch { return AColor.ParseColor(fallback); }
    }
}
