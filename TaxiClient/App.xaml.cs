using TaxiClient.Views;

namespace TaxiClient;

public partial class App : Application
{
    public App(LoginPage loginPage)
    {
        InitializeComponent();

        // Перехват всех необработанных ошибок
        AppDomain.CurrentDomain.UnhandledException += (s, e) =>
        {
            LogCrash("AppDomain", e.ExceptionObject as Exception);
        };

        TaskScheduler.UnobservedTaskException += (s, e) =>
        {
            LogCrash("Task", e.Exception);
            e.SetObserved();
        };

        MainPage = new NavigationPage(loginPage);
    }

    public static void LogCrash(string source, Exception? ex)
    {
        try
        {
            var path = Path.Combine(AppContext.BaseDirectory, "crash.log");
            var msg = $"[{DateTime.Now}] [{source}] {ex?.ToString()}\n\n";
            File.AppendAllText(path, msg);
        }
        catch { }
    }
}