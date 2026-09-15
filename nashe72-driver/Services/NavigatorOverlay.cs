namespace TaxiDriver.Services;

/// <summary>
/// Минимальный Android-мост: координаты фонового GPS и системные сообщения.
/// Код Яндекс Навигатора и системного оверлея удалён полностью — карта теперь
/// только внутри приложения, разрешение «Поверх других приложений» не нужно.
/// </summary>
public static class NavigatorOverlay
{
    public static event Action<double, double, double?, double?>? NativeLocation;

    public static void RaiseNativeLocation(double lat, double lng, double? speed, double? bearing)
    {
        try { NativeLocation?.Invoke(lat, lng, speed, bearing); }
        catch { }
    }

    public static void Toast(string text)
    {
#if ANDROID
        try
        {
            var context = global::Android.App.Application.Context;
            new global::Android.OS.Handler(global::Android.OS.Looper.MainLooper!).Post(() =>
                global::Android.Widget.Toast.MakeText(
                    context, text, global::Android.Widget.ToastLength.Long)?.Show());
        }
        catch { }
#else
        _ = text;
#endif
    }
}
