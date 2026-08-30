using TaxiDriver.Services;
using TaxiDriver.Views;

namespace TaxiDriver;

public partial class App : Application
{
    private readonly ApiService _api;

    public App(LoginPage loginPage, ApiService api)
    {
        InitializeComponent();
        _api = api;
        MainPage = new NavigationPage(loginPage);

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