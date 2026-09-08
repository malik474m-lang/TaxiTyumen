namespace TaxiDriver.Services;

/// <summary>Состояние плавающей панели поверх Яндекс Навигатора.</summary>
public sealed class OverlayState
{
    /// Верхняя строка: адрес подачи или назначения
    public string Title { get; set; } = "";
    /// Вторая строка: этап заказа, цена, клиент
    public string Subtitle { get; set; } = "";
    /// Подпись главной кнопки: «Я на месте» / «Начать поездку» / «Завершить»
    public string ActionText { get; set; } = "";
    /// Цвет главной кнопки в формате #RRGGBB
    public string ActionColor { get; set; } = "#4CAF50";
    /// Подпись кнопки простоя; пусто — кнопка скрыта
    public string WaitingText { get; set; } = "";
}

/// <summary>
/// Навигация «как в Таксометре»: маршрут ведёт установленный Яндекс Навигатор,
/// а кнопки заказа показываются плавающей панелью поверх него (системный
/// оверлей Android). GPS-трекинг при этом не прерывается — координаты
/// продолжают уходить на сервер, поэтому машину видно в админке,
/// у оператора и в приложении клиента.
///
/// Класс кросс-платформенный: на не-Android платформах методы безопасно
/// ничего не делают, а IsSupported = false.
/// </summary>
public static class NavigatorOverlay
{
    /// Нажатие кнопки на плавающей панели: status | waiting | sos | app
    public static event Action<string>? ActionRequested;

    /// Координаты от нативного GPS-слушателя фонового сервиса
    /// (широта, долгота, скорость м/с, курс градусы)
    public static event Action<double, double, double?, double?>? NativeLocation;

    /// Панель сейчас показана поверх других приложений
    public static bool IsActive { get; private set; }

    /// Трекинг «на линии» запущен (для диагностики в интерфейсе)
    public static bool IsTrackingRunning =>
#if ANDROID
        Platforms.Android.DriverTrackingService.IsRunning;
#else
        false;
#endif

    /// Режим включён водителем (кнопка «Навигатор поверх»)
    public static bool ModeEnabled { get; set; }

#if ANDROID
    public static bool IsSupported => true;
#else
    public static bool IsSupported => false;
#endif

    public static void RaiseAction(string action)
    {
        try
        {
            MainThread.BeginInvokeOnMainThread(() => ActionRequested?.Invoke(action));
        }
        catch
        {
            ActionRequested?.Invoke(action);
        }
    }

    public static void RaiseNativeLocation(double lat, double lng, double? speed, double? bearing)
    {
        try
        {
            NativeLocation?.Invoke(lat, lng, speed, bearing);
        }
        catch { }
    }

    /// <summary>Установлен ли Яндекс Навигатор на устройстве.</summary>
    public static bool IsNavigatorInstalled()
    {
#if ANDROID
        return Platforms.Android.YandexNavigatorLauncher.IsInstalled();
#else
        return false;
#endif
    }

    /// <summary>Выдано ли разрешение «Поверх других приложений».</summary>
    public static bool HasOverlayPermission()
    {
#if ANDROID
        return Platforms.Android.YandexNavigatorLauncher.CanDrawOverlays();
#else
        return false;
#endif
    }

    /// <summary>Открыть системный экран выдачи разрешения на оверлей.</summary>
    public static void RequestOverlayPermission()
    {
#if ANDROID
        Platforms.Android.YandexNavigatorLauncher.RequestOverlayPermission();
#endif
    }

    /// <summary>Построить маршрут в Яндекс Навигаторе. false — приложение не установлено.</summary>
    public static bool OpenNavigator(double lat, double lng)
    {
#if ANDROID
        return Platforms.Android.YandexNavigatorLauncher.BuildRoute(lat, lng);
#else
        _ = lat; _ = lng;
        return false;
#endif
    }

    /// <summary>Маршрут по текстовому адресу (когда координат в заказе нет).</summary>
    public static bool SearchInNavigator(string address)
    {
#if ANDROID
        return Platforms.Android.YandexNavigatorLauncher.SearchAddress(address);
#else
        _ = address;
        return false;
#endif
    }

    /// <summary>Ссылка на Яндекс Навигатор в Google Play (если не установлен).</summary>
    public static void OpenNavigatorInStore()
    {
#if ANDROID
        Platforms.Android.YandexNavigatorLauncher.OpenStore();
#endif
    }

    /// <summary>Показать/обновить плавающую панель с кнопками заказа.</summary>
    public static void Show(OverlayState state)
    {
#if ANDROID
        // Отдельный сервис панели: запускается всегда, тип location ему не нужен
        Platforms.Android.OrderOverlayService.Show(state);
        IsActive = true;
#else
        _ = state;
#endif
    }

    /// <summary>Обновить содержимое панели, если она показана.</summary>
    public static void Update(OverlayState state)
    {
        if (!IsActive) return;
        Show(state);
    }

    /// <summary>Убрать панель с экрана.</summary>
    public static void Hide()
    {
        if (!IsActive) return;
#if ANDROID
        Platforms.Android.OrderOverlayService.Hide();
#endif
        IsActive = false;
    }

    /// <summary>Короткое системное уведомление (когда приложение свёрнуто).</summary>
    public static void Toast(string text)
    {
#if ANDROID
        Platforms.Android.YandexNavigatorLauncher.Toast(text);
#else
        _ = text;
#endif
    }
}
