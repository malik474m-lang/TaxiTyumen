using Android.Content;
using Android.OS;
using Android.Provider;
using Android.Widget;

namespace TaxiDriver.Platforms.Android;

/// <summary>
/// Запуск установленного Яндекс Навигатора и проверка системных разрешений,
/// нужных для плавающей панели поверх него.
/// Пакеты видимы благодаря секции &lt;queries&gt; в AndroidManifest (Android 11+).
/// </summary>
public static class YandexNavigatorLauncher
{
    public const string NavigatorPackage = "ru.yandex.yandexnavi";

    private static Context Context => global::Android.App.Application.Context;

    public static bool IsInstalled()
    {
        try
        {
            var pm = Context.PackageManager;
            if (pm == null) return false;
            // GetLaunchIntentForPackage возвращает null, если пакета нет
            return pm.GetLaunchIntentForPackage(NavigatorPackage) != null;
        }
        catch
        {
            return false;
        }
    }

    public static bool CanDrawOverlays()
    {
        try
        {
            if (Build.VERSION.SdkInt < BuildVersionCodes.M) return true;
            return Settings.CanDrawOverlays(Context);
        }
        catch
        {
            return false;
        }
    }

    public static void RequestOverlayPermission()
    {
        try
        {
            if (Build.VERSION.SdkInt < BuildVersionCodes.M) return;
            var intent = new Intent(
                Settings.ActionManageOverlayPermission,
                global::Android.Net.Uri.Parse("package:" + Context.PackageName));
            intent.AddFlags(ActivityFlags.NewTask);
            Context.StartActivity(intent);
        }
        catch { }
    }

    /// <summary>Маршрут до точки в Яндекс Навигаторе (deep link yandexnavi://).</summary>
    public static bool BuildRoute(double lat, double lng)
    {
        if (!IsInstalled()) return false;
        try
        {
            var ci = System.Globalization.CultureInfo.InvariantCulture;
            var uri = string.Format(ci,
                "yandexnavi://build_route_on_map?lat_to={0}&lon_to={1}",
                lat.ToString(ci), lng.ToString(ci));

            var intent = new Intent(Intent.ActionView, global::Android.Net.Uri.Parse(uri));
            intent.SetPackage(NavigatorPackage);
            intent.AddFlags(ActivityFlags.NewTask);
            Context.StartActivity(intent);
            return true;
        }
        catch
        {
            return false;
        }
    }

    /// <summary>
    /// Официальный составной маршрут Яндекс Навигатора:
    /// первая точка — старт, последняя — финиш, между ними lat_via_N/lon_via_N.
    /// </summary>
    public static bool BuildMultiPointRoute(IReadOnlyList<TaxiDriver.Models.NavigatorPoint> points)
    {
        if (!IsInstalled() || points.Count < 2) return false;
        try
        {
            var ci = System.Globalization.CultureInfo.InvariantCulture;
            var uri = new global::Android.Net.Uri.Builder()
                .Scheme("yandexnavi")
                .Authority("build_route_on_map")
                .AppendQueryParameter("lat_from", points[0].Latitude.ToString(ci))
                .AppendQueryParameter("lon_from", points[0].Longitude.ToString(ci))
                .AppendQueryParameter("lat_to", points[^1].Latitude.ToString(ci))
                .AppendQueryParameter("lon_to", points[^1].Longitude.ToString(ci));

            for (var i = 1; i < points.Count - 1; i++)
            {
                uri.AppendQueryParameter($"lat_via_{i - 1}", points[i].Latitude.ToString(ci));
                uri.AppendQueryParameter($"lon_via_{i - 1}", points[i].Longitude.ToString(ci));
            }

            var intent = new Intent(Intent.ActionView, uri.Build());
            intent.SetPackage(NavigatorPackage);
            intent.AddFlags(ActivityFlags.NewTask | ActivityFlags.SingleTop);
            Context.StartActivity(intent);
            return true;
        }
        catch
        {
            return false;
        }
    }

    /// <summary>Поиск адреса в Навигаторе, когда координаты неизвестны.</summary>
    public static bool SearchAddress(string address)
    {
        if (!IsInstalled() || string.IsNullOrWhiteSpace(address)) return false;
        try
        {
            // Адрес заявки передаём КАК ЕСТЬ: дописывание города ломало маршруты
            // за пределы города (пригород, соседний населённый пункт).
            var uri = "yandexnavi://map_search?text=" + global::Android.Net.Uri.Encode(address.Trim());
            var intent = new Intent(Intent.ActionView, global::Android.Net.Uri.Parse(uri));
            intent.SetPackage(NavigatorPackage);
            intent.AddFlags(ActivityFlags.NewTask);
            Context.StartActivity(intent);
            return true;
        }
        catch
        {
            return false;
        }
    }

    /// <summary>Открыть карточку Навигатора в Google Play.</summary>
    public static void OpenStore()
    {
        try
        {
            var intent = new Intent(Intent.ActionView,
                global::Android.Net.Uri.Parse("market://details?id=" + NavigatorPackage));
            intent.AddFlags(ActivityFlags.NewTask);
            Context.StartActivity(intent);
        }
        catch
        {
            try
            {
                var web = new Intent(Intent.ActionView, global::Android.Net.Uri.Parse(
                    "https://play.google.com/store/apps/details?id=" + NavigatorPackage));
                web.AddFlags(ActivityFlags.NewTask);
                Context.StartActivity(web);
            }
            catch { }
        }
    }

    /// <summary>Вернуть на экран приложение водителя (кнопка «Заказ» на панели).</summary>
    public static void BringAppToFront()
    {
        try
        {
            var pm = Context.PackageManager;
            var intent = pm?.GetLaunchIntentForPackage(Context.PackageName!);
            if (intent == null) return;
            intent.AddFlags(ActivityFlags.NewTask | ActivityFlags.SingleTop);
            Context.StartActivity(intent);
        }
        catch { }
    }

    public static void Toast(string text)
    {
        try
        {
            global::Android.App.Application.SynchronizationContext.Post(_ =>
            {
                global::Android.Widget.Toast
                    .MakeText(Context, text, ToastLength.Long)?.Show();
            }, null);
        }
        catch { }
    }
}
