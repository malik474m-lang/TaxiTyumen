using System.IO;
using System.Windows;
using TaxiOperator.Services;
using TaxiOperator.Views;

namespace TaxiOperator;

public partial class App : Application
{
    protected override async void OnStartup(StartupEventArgs e)
    {
        // Приложение больше не умирает молча: любое необработанное исключение
        // пишется в operator-crash.log, а по UI-потоку — показывается окно ошибки.
        AppDomain.CurrentDomain.UnhandledException += (_, args) =>
            CrashLog("AppDomain", args.ExceptionObject as Exception);

        DispatcherUnhandledException += (_, args) =>
        {
            CrashLog("Dispatcher", args.Exception);
            args.Handled = true;
            MessageBox.Show(
                "Пульт столкнулся с ошибкой:\n\n" + args.Exception.Message +
                "\n\nПодробности записаны в файл:\n" + CrashLogPath,
                "Ошибка пульта", MessageBoxButton.OK, MessageBoxImage.Error);
        };

        TaskScheduler.UnobservedTaskException += (_, args) =>
            CrashLog("UnobservedTask", args.Exception);

        base.OnStartup(e);

        try
        {
            // Брендинг из админки (название, цвета, телефон поддержки) — до входа
            await BrandingService.LoadAsync();
        }
        catch (Exception ex)
        {
            CrashLog("Branding", ex);
        }

        var api = new ApiService();
        var login = new LoginWindow(api);
        login.Show();
    }

    /// Журнал падений: %LOCALAPPDATA%\TaxiTyumen\operator-crash.log
    private static string CrashLogPath => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "TaxiTyumen", "operator-crash.log");

    private static void CrashLog(string source, Exception? ex)
    {
        try
        {
            Directory.CreateDirectory(Path.GetDirectoryName(CrashLogPath)!);
            File.AppendAllText(CrashLogPath,
                $"[{DateTime.Now:yyyy-MM-dd HH:mm:ss}] {source}:\n{ex}\n\n");
        }
        catch { }
    }
}
