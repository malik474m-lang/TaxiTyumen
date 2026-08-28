using System.Windows;
using TaxiOperator.Services;
using TaxiOperator.Views;

namespace TaxiOperator;

public partial class App : Application
{
    protected override void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);
        var api = new ApiService();
        var login = new LoginWindow(api);
        login.Show();
    }
}
