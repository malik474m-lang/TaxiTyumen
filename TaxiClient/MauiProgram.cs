using Microsoft.Extensions.Logging;
using TaxiClient.Services;
using TaxiClient.Views;

namespace TaxiClient;

public static class MauiProgram
{
    public static MauiApp CreateMauiApp()
    {
        var builder = MauiApp.CreateBuilder();

        builder
            .UseMauiApp<App>()
            .ConfigureFonts(fonts =>
            {
                fonts.AddFont("OpenSans-Regular.ttf", "OpenSansRegular");
                fonts.AddFont("OpenSans-Semibold.ttf", "OpenSansSemibold");
            });

        builder.Services.AddSingleton<ApiService>();
        builder.Services.AddSingleton<SignalRService>();
        builder.Services.AddSingleton<GeocodingService>();
        builder.Services.AddTransient<LoginPage>();

#if ANDROID
        // Разрешаем WebView мультитач, щипок для зума и хранилище
        Microsoft.Maui.Handlers.WebViewHandler.Mapper.AppendToMapping(
            "ClientMapTouchAndZoom", (handler, view) =>
            {
                var settings = handler.PlatformView.Settings;
                settings.JavaScriptEnabled = true;
                settings.DomStorageEnabled = true;
                settings.DatabaseEnabled = true;
                settings.SetSupportZoom(true);
                settings.BuiltInZoomControls = true;
                settings.DisplayZoomControls = false;
                settings.CacheMode = global::Android.Webkit.CacheModes.Default;
                handler.PlatformView.OverScrollMode = global::Android.Views.OverScrollMode.Never;
            });
#endif

#if DEBUG
        builder.Logging.AddDebug();
#endif

        return builder.Build();
    }
}