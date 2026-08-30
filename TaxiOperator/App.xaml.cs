using System.Windows;
using TaxiOperator.Services;
using TaxiOperator.Views;

namespace TaxiOperator;

public partial class App : Application
{
    protected override async void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);

        // Брендинг из админки (название, цвета, телефон поддержки) — до входа
        await BrandingService.LoadAsync();

        var api = new ApiService();
        var login = new LoginWindow(api);
        login.Show();
    }
}
