namespace TaxiDriver.Services;

public class LocationService
{
    private IDispatcherTimer? _timer;
    private readonly ApiService _api;
    private readonly SignalRService _signalR;
    private bool _tracking;

    public double CurrentLat { get; private set; } = 57.1522; // Тюмень центр
    public double CurrentLng { get; private set; } = 65.5272;
    public Guid? ActiveOrderId { get; set; }
    public Guid? DriverId { get; set; }

    public event Action<double, double>? LocationUpdated;

    public LocationService(ApiService api, SignalRService signalR)
    {
        _api = api;
        _signalR = signalR;
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

        _timer = Application.Current!.Dispatcher.CreateTimer();
        _timer.Interval = TimeSpan.FromSeconds(5);
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
    }

    private async Task UpdateLocationAsync()
    {
        try
        {
            var location = await Geolocation.GetLastKnownLocationAsync();
            if (location == null)
            {
                location = await Geolocation.GetLocationAsync(
                    new GeolocationRequest(GeolocationAccuracy.High,
                        TimeSpan.FromSeconds(3)));
            }

            if (location != null)
                await ApplyLocationAsync(location);
        }
        catch { /* GPS временно недоступен */ }
    }

    private async Task ApplyLocationAsync(Location location)
    {
        CurrentLat = location.Latitude;
        CurrentLng = location.Longitude;

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
