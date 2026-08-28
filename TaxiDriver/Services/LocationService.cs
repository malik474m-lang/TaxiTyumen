namespace TaxiDriver.Services;

public class LocationService
{
    private IDispatcherTimer? _timer;
    private readonly ApiService _api;
    private readonly SignalRService _signalR;

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

    public void StartTracking()
    {
        _timer = Application.Current!.Dispatcher.CreateTimer();
        _timer.Interval = TimeSpan.FromSeconds(5);
        _timer.Tick += async (s, e) => await UpdateLocationAsync();
        _timer.Start();
    }

    public void StopTracking()
    {
        _timer?.Stop();
        _timer = null;
    }

    private async Task UpdateLocationAsync()
    {
        try
        {
            var location = await Geolocation.GetLastKnownLocationAsync();
            if (location == null)
            {
                location = await Geolocation.GetLocationAsync(
                    new GeolocationRequest(GeolocationAccuracy.Medium,
                        TimeSpan.FromSeconds(3)));
            }

            if (location != null)
            {
                CurrentLat = location.Latitude;
                CurrentLng = location.Longitude;

                LocationUpdated?.Invoke(CurrentLat, CurrentLng);

                // Отправляем на сервер
                if (DriverId.HasValue)
                {
                    await _api.UpdateLocationAsync(DriverId.Value, new()
                    {
                        Latitude = CurrentLat,
                        Longitude = CurrentLng,
                        Speed = location.Speed,
                        OrderId = ActiveOrderId
                    });
                }

                // Через SignalR
                await _signalR.SendLocationAsync(
                    CurrentLat, CurrentLng, location.Speed);
            }
        }
        catch { /* GPS недоступен */ }
    }
}
