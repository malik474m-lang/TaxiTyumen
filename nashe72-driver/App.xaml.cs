using TaxiDriver.Services;
using TaxiDriver.Views;

namespace TaxiDriver;

public partial class App : Application
{
    private readonly ApiService _api;

    /// Журнал падений: доступен через «Меню → О приложении» и по пути
    /// Android/data/ru.taxityumen.driver/files/driver-crash.log
    public static string CrashLogPath =>
        Path.Combine(FileSystem.AppDataDirectory, "driver-crash.log");

    public static void LogCrash(string source, Exception? ex)
    {
        try
        {
            File.AppendAllText(CrashLogPath,
                $"[{DateTime.Now:yyyy-MM-dd HH:mm:ss}] {source}:\n{ex}\n\n");
        }
        catch { }
    }

    public App(LoginPage loginPage, ApiService api)
    {
        // Ловим необработанные исключения: приложение больше не «исчезает»
        // молча — причина остаётся в логе и показывается всплывающей подсказкой.
        AppDomain.CurrentDomain.UnhandledException += (_, args) =>
        {
            LogCrash("AppDomain", args.ExceptionObject as Exception);
            NavigatorOverlay.Toast("Сбой приложения: " +
                ((args.ExceptionObject as Exception)?.Message ?? "неизвестная ошибка"));
        };
        TaskScheduler.UnobservedTaskException += (_, args) =>
        {
            LogCrash("Task", args.Exception);
            args.SetObserved();
        };
#if ANDROID
        global::Android.Runtime.AndroidEnvironment.UnhandledExceptionRaiser += (_, args) =>
        {
            LogCrash("Android", args.Exception);
            NavigatorOverlay.Toast("Сбой: " + args.Exception.Message);
            // Не даём среде убить процесс без следа
            args.Handled = true;
        };
#endif

        InitializeComponent();
        _api = api;
        MainPage = new NavigationPage(loginPage);

        // Бренд сервиса и оформление из админки: подтягиваем при старте,
        // страницы обновляются через событие BrandingService.Updated
        _ = BrandingService.LoadAsync();

#if ANDROID
        RequestPostNotifications();
#endif
    }

#if ANDROID
    // Уведомления о заказах: на Android 13+ разрешение запрашивается в рантайме
    private static async void RequestPostNotifications()
    {
        try
        {
            if (!OperatingSystem.IsAndroidVersionAtLeast(33)) return;
            var status = await Permissions.CheckStatusAsync<Permissions.PostNotifications>();
            if (status != PermissionStatus.Granted)
                await Permissions.RequestAsync<Permissions.PostNotifications>();
        }
        catch { /* разрешение необязательно для работы */ }
    }
#endif

    protected override Window CreateWindow(IActivationState? activationState)
    {
        var window = base.CreateWindow(activationState);

        window.Destroying += async (s, e) =>
        {
            try
            {
                if (_api.CurrentUser?.DriverId != null)
                {
                    await _api.SetOnlineAsync(_api.CurrentUser.DriverId.Value, false);
                }
            }
            catch { }
        };

        return window;
    }
}