using Microsoft.Extensions.Logging;
using Microsoft.Maui.Controls.Hosting;
using Microsoft.Maui.Hosting;
using TaxiDriver.Services;
using TaxiDriver.Views;

namespace TaxiDriver;

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
        builder.Services.AddSingleton<LocationService>();

        builder.Services.AddTransient<LoginPage>();

#if ANDROID
        // Кеш WebView: однажды загруженные тайлы и скрипты карт остаются
        // доступны без сети. Если интернет пропал — карта не белеет, а
        // показывает последнее закешированное состояние; при полном отсутствии
        // кеша включается собственный офлайн-рендерер маршрута (MapHtml).
        Microsoft.Maui.Handlers.WebViewHandler.Mapper.AppendToMapping(
            "OfflineMapCache", (handler, view) =>
            {
                var settings = handler.PlatformView.Settings;
                settings.JavaScriptEnabled = true;
                settings.DomStorageEnabled = true;
                settings.DatabaseEnabled = true;
                settings.CacheMode = global::Android.Webkit.CacheModes.Default;
                settings.SetGeolocationEnabled(true);

                // При обрыве связи переключаемся на кеш вместо ошибки сети
                var connectivity = Connectivity.Current;
                connectivity.ConnectivityChanged += (_, e) =>
                {
                    try
                    {
                        settings.CacheMode = e.NetworkAccess == NetworkAccess.Internet
                            ? global::Android.Webkit.CacheModes.Default
                            : global::Android.Webkit.CacheModes.CacheElseNetwork;
                    }
                    catch { }
                };
                if (connectivity.NetworkAccess != NetworkAccess.Internet)
                    settings.CacheMode = global::Android.Webkit.CacheModes.CacheElseNetwork;
            });
#endif

#if DEBUG
        builder.Logging.AddDebug();
#endif

        return builder.Build();
    }
}