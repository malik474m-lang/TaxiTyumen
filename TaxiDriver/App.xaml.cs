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
    }

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