using Android.App;
using Android.Content.PM;
using Android.OS;

namespace TaxiSmsGateway;

[Activity(Theme = "@style/Maui.SplashTheme", MainLauncher = true,
    LaunchMode = LaunchMode.SingleTop,
    ConfigurationChanges = ConfigChanges.ScreenSize | ConfigChanges.Orientation
        | ConfigChanges.UiMode | ConfigChanges.ScreenLayout | ConfigChanges.SmallestScreenSize
        | ConfigChanges.Density)]
public class MainActivity : MauiAppCompatActivity
{
    protected override void OnCreate(Bundle? savedInstanceState)
    {
        base.OnCreate(savedInstanceState);
        // Экран не гаснет: Android не должен усыплять процесс шлюза
        Window?.AddFlags(global::Android.Views.WindowManagerFlags.KeepScreenOn);
    }
}
