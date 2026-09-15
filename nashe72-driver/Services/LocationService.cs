namespace TaxiDriver.Services;

public class LocationService
{
    private IDispatcherTimer? _timer;
    private readonly ApiService _api;
    private readonly SignalRService _signalR;
    private bool _tracking;

    // До первой реальной GPS-точки координаты = 0. Раньше здесь был центр
    // Тюмени, и карта считала его местоположением машины за городом.
    public double CurrentLat { get; private set; }
    public double CurrentLng { get; private set; }
    public bool HasFix { get; private set; }

    /// Курс движения в градусах (0 = север) — иконка машины разворачивается по нему.
    public double? CurrentBearing { get; private set; }
    public double? CurrentSpeed { get; private set; }
    public Guid? ActiveOrderId { get; set; }
    public Guid? DriverId { get; set; }

    public event Action<double, double>? LocationUpdated;

    public LocationService(ApiService api, SignalRService signalR)
    {
        _api = api;
        _signalR = signalR;

        // Координаты от фонового сервиса: пока водитель в Яндекс Навигаторе,
        // MAUI-таймер может тормозиться системой, а нативный опрос — нет.
        // Благодаря этому машина не «замирает» на картах админки, оператора и клиента.
        NavigatorOverlay.NativeLocation += OnNativeLocation;
    }

    private void OnNativeLocation(double lat, double lng, double? speed, double? bearing)
    {
        if (!_tracking) return;
        _ = Task.Run(async () =>
        {
            try
            {
                await ApplyLocationAsync(new Location(lat, lng)
                {
                    Speed = speed,
                    Course = bearing,
                });
            }
            catch { }
        });
    }

    /// <summary>
    /// Проверить/запросить разрешение геолокации.
    /// На Android — системный диалог; на Windows разрешения не требуются.
    /// </summary>
    public async Task<bool> EnsureLocationPermissionAsync()
    {
#if ANDROID
        try
        {
            var status = await Permissions.CheckStatusAsync<Permissions.LocationWhenInUse>();
            if (status != PermissionStatus.Granted)
                status = await Permissions.RequestAsync<Permissions.LocationWhenInUse>();
            return status == PermissionStatus.Granted;
        }
        catch
        {
            return false;
        }
#else
        await Task.CompletedTask;
        return true;
#endif
    }

    /// <summary>
    /// Запуск слежения. Возвращает false только если нет разрешения —
    /// GPS прогревается асинхронно и продолжает пытаться каждый тик.
    /// </summary>
    public async Task<bool> StartTrackingAsync()
    {
        if (!await EnsureLocationPermissionAsync())
            return false;

        if (_tracking)
            return true;
        _tracking = true;

        // Экран водителя «на линии» не должен гаснуть
        try { DeviceDisplay.Current.KeepScreenOn = true; } catch { }

#if ANDROID
        // Фон: foreground-сервис держит процесс при свёрнутом приложении
        StartAndroidTrackingService();
#endif

        _timer = Application.Current!.Dispatcher.CreateTimer();
        _timer.Interval = TimeSpan.FromSeconds(3);
        _timer.Tick += async (s, e) => await UpdateLocationAsync();
        _timer.Start();

        // Прогрев: первая точка сразу, не дожидаясь первого тика
        _ = UpdateLocationAsync();
        return true;
    }

    public void StopTracking()
    {
        _tracking = false;
        _timer?.Stop();
        _timer = null;
        try { DeviceDisplay.Current.KeepScreenOn = false; } catch { }
#if ANDROID
        StopAndroidTrackingService();
#endif
    }

#if ANDROID
    private static void StartAndroidTrackingService()
    {
        try
        {
            var context = global::Android.App.Application.Context;
            var intent = new global::Android.Content.Intent(
                context, typeof(global::TaxiDriver.Platforms.Android.DriverTrackingService));
            // Геолокация уже подтверждена EnsureLocationPermissionAsync —
            // требование Android 14 для foregroundServiceType=location выполнено
            if (global::Android.OS.Build.VERSION.SdkInt >= global::Android.OS.BuildVersionCodes.O)
                context.StartForegroundService(intent);
            else
                context.StartService(intent);
        }
        catch { /* сервис недоступен — работаем на видимом экране */ }
    }

    private static void StopAndroidTrackingService()
    {
        try
        {
            var context = global::Android.App.Application.Context;
            var intent = new global::Android.Content.Intent(
                context, typeof(global::TaxiDriver.Platforms.Android.DriverTrackingService));
            intent.SetAction(global::TaxiDriver.Platforms.Android.DriverTrackingService.ActionStop);
            context.StartService(intent);
        }
        catch { }
    }
#endif

    private bool _updating;

    private async Task UpdateLocationAsync()
    {
        // Тик раз в 5 секунд может наложиться на медленный GPS-ответ
        if (_updating) return;
        _updating = true;
        try
        {
            // ВАЖНО: GetLastKnownLocationAsync отдаёт КЕШ и возвращает одну и ту
            // же точку — из-за неё машина «стояла на месте» на карте.
            // Запрашиваем свежую позицию, кеш используем только как резерв.
            Location? location = null;
            try
            {
                location = await Geolocation.GetLocationAsync(
                    new GeolocationRequest(GeolocationAccuracy.Best, TimeSpan.FromSeconds(8)));
            }
            catch { }

            location ??= await Geolocation.GetLastKnownLocationAsync();

            if (location != null)
                await ApplyLocationAsync(location);
        }
        catch { /* GPS временно недоступен */ }
        finally { _updating = false; }
    }

    private async Task ApplyLocationAsync(Location location)
    {
        // Курс сохраняем последний известный: при остановке GPS его обнуляет,
        // и иконка машины дёргалась бы на север.
        if (location.Course is > 0) CurrentBearing = location.Course;
        CurrentSpeed = location.Speed;

        CurrentLat = location.Latitude;
        CurrentLng = location.Longitude;
        HasFix = true;

        LocationUpdated?.Invoke(CurrentLat, CurrentLng);

        // Отправляем на сервер (координаты + скорость + курс)
        if (DriverId.HasValue)
        {
            await _api.UpdateLocationAsync(DriverId.Value, new()
            {
                Latitude = CurrentLat,
                Longitude = CurrentLng,
                Speed = location.Speed,
                Bearing = location.Course,
                OrderId = ActiveOrderId
            });
        }

        // Через SignalR (polling-транспорт PHP)
        await _signalR.SendLocationAsync(
            CurrentLat, CurrentLng, location.Speed);
    }
}
