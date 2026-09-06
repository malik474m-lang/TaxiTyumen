using System.Globalization;
using System.Text;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Threading;
using TaxiOperator.Models;
using TaxiOperator.Services;

namespace TaxiOperator.Views;

/// Карта автопарка для диспетчера: показывает только водителей в сети,
/// метки двигаются в реальном времени без перезагрузки страницы.
public partial class FleetMapWindow : Window
{
    private readonly ApiService _api;
    private readonly DispatcherTimer _timer;
    private bool _mapReady;
    private Guid? _followDriverId;

    public FleetMapWindow(ApiService api)
    {
        InitializeComponent();
        _api = api;

        _timer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(5) };
        _timer.Tick += async (_, _) => await RefreshAsync();

        Loaded += async (_, _) => await InitAsync();
        Closed += (_, _) => _timer.Stop();
    }

    private async Task InitAsync()
    {
        try
        {
            await MapView.EnsureCoreWebView2Async();
            MapView.NavigationCompleted += async (_, _) =>
            {
                _mapReady = true;
                await RefreshAsync();
            };
            MapView.NavigateToString(BuildMapHtml(await MapConfig.GetApiKeyAsync()));
            _timer.Start();
        }
        catch (Exception ex)
        {
            // Нет WebView2 Runtime — оставляем рабочим список машин справа.
            MapView.Visibility = Visibility.Collapsed;
            MapFallbackText.Visibility = Visibility.Visible;
            MapFallbackText.Text = "Карта недоступна: " + ex.Message
                + "\nУстановите Microsoft Edge WebView2 Runtime. Список водителей ниже продолжает работать.";
            StatusText.Text = "Карта отключена, список активен";
            _timer.Start();
            await RefreshAsync();
        }
    }

    private async Task RefreshAsync()
    {
        try
        {
            var drivers = await _api.GetOnlineDriversAsync();

            // Список слева направо: свободные первыми, затем занятые.
            var items = drivers
                .OrderBy(d => d.Status == "Available" || d.Status == "available" ? 0 : 1)
                .ThenBy(d => d.FullName)
                .Select(d => new DriverRow(d))
                .ToList();

            var selectedId = (DriversList.SelectedItem as DriverRow)?.Id;
            DriversList.ItemsSource = items;
            if (selectedId != null)
                DriversList.SelectedItem = items.FirstOrDefault(i => i.Id == selectedId);

            StatusText.Text = $"В сети: {drivers.Count} · обновлено {DateTime.Now:HH:mm:ss}";

            if (_mapReady && MapView.Visibility == Visibility.Visible && MapView.CoreWebView2 != null)
            {
                var payload = JsonSerializer.Serialize(drivers.Select(d => new
                {
                    id = d.Id.ToString(),
                    name = d.FullName,
                    car = string.IsNullOrWhiteSpace(d.CarDisplay)
                        ? $"{d.CarColor} {d.CarBrand} {d.CarModel}".Trim()
                        : d.CarDisplay,
                    plate = d.LicensePlate,
                    status = d.Status,
                    lat = d.Latitude,
                    lng = d.Longitude
                }));

                var follow = FollowCheck.IsChecked == true && _followDriverId != null
                    ? $"'{_followDriverId}'"
                    : "null";
                await MapView.ExecuteScriptAsync($"window.syncDrivers && window.syncDrivers({payload}, {follow});");
            }
        }
        catch (Exception ex)
        {
            StatusText.Text = "Ошибка обновления: " + ex.Message;
        }
    }

    private async void OnRefreshClick(object sender, RoutedEventArgs e) => await RefreshAsync();

    private async void OnDriverSelected(object sender, SelectionChangedEventArgs e)
    {
        if (DriversList.SelectedItem is not DriverRow row) return;
        _followDriverId = row.Id;
        if (!_mapReady || MapView.CoreWebView2 == null) return;

        var lat = row.Latitude.ToString("F6", CultureInfo.InvariantCulture);
        var lng = row.Longitude.ToString("F6", CultureInfo.InvariantCulture);
        await MapView.ExecuteScriptAsync($"window.focusDriver && window.focusDriver('{row.Id}',{lat},{lng});");
    }

    /// Страница карты: метки создаются один раз и далее только перемещаются.
    private static string BuildMapHtml(string apiKey)
    {
        var key = string.IsNullOrWhiteSpace(apiKey) ? "" : "&apikey=" + Uri.EscapeDataString(apiKey);
        var sb = new StringBuilder();
        sb.Append(@"<!DOCTYPE html><html><head><meta charset='utf-8'>
<style>html,body,#map{margin:0;padding:0;height:100%;width:100%;background:#0F0F13}
.err{color:#aaa;font:14px sans-serif;padding:24px;text-align:center}</style>
<script src='https://api-maps.yandex.ru/2.1/?lang=ru_RU");
        sb.Append(key);
        sb.Append(@"'></script></head><body><div id='map'></div>
<script>
var map, marks = {};
function presetFor(s){
  s = (s||'').toLowerCase();
  if(s==='available') return 'islands#greenAutoCircleIcon';
  if(s==='intrip'||s==='in_trip') return 'islands#blueAutoCircleIcon';
  if(s==='onroute'||s==='on_route') return 'islands#orangeAutoCircleIcon';
  return 'islands#grayAutoCircleIcon';
}
function esc(t){var d=document.createElement('div');d.textContent=t==null?'':String(t);return d.innerHTML}
window.syncDrivers = function(list, followId){
  if(!map) return;
  var seen = {};
  (list||[]).forEach(function(d){
    seen[d.id] = true;
    var pos = [d.lat, d.lng];
    var balloon = '<b>'+esc(d.name)+'</b><br>'+esc(d.car)+' · <b>'+esc(d.plate)+'</b>';
    if(marks[d.id]){
      marks[d.id].geometry.setCoordinates(pos);
      marks[d.id].properties.set({iconCaption: d.plate, balloonContent: balloon});
      marks[d.id].options.set('preset', presetFor(d.status));
    } else {
      var pm = new ymaps.Placemark(pos, {iconCaption: d.plate, balloonContent: balloon},
        {preset: presetFor(d.status)});
      marks[d.id] = pm;
      map.geoObjects.add(pm);
    }
    if(followId && d.id === followId) map.panTo(pos, {flying:false, duration:250});
  });
  Object.keys(marks).forEach(function(id){
    if(!seen[id]){ map.geoObjects.remove(marks[id]); delete marks[id]; }
  });
};
window.focusDriver = function(id, lat, lng){
  if(!map) return;
  map.setCenter([lat,lng], 16, {duration:300});
  if(marks[id]) marks[id].balloon.open();
};
if(window.ymaps){
  ymaps.ready(function(){
    map = new ymaps.Map('map', {center:[57.1522,65.5272], zoom:12,
      controls:['zoomControl','typeSelector','fullscreenControl']},
      {suppressMapOpenBlock:true});
  });
} else {
  document.getElementById('map').innerHTML = '<div class=""err"">Не удалось загрузить Яндекс Карты</div>';
}
</script></body></html>");
        return sb.ToString();
    }

    /// Строка списка водителей.
    private sealed class DriverRow
    {
        public DriverRow(OnlineDriver d)
        {
            Id = d.Id;
            FullName = d.FullName;
            Latitude = d.Latitude;
            Longitude = d.Longitude;
            CarLine = string.IsNullOrWhiteSpace(d.CarDisplay)
                ? $"{d.CarColor} {d.CarBrand} {d.CarModel} · {d.LicensePlate}".Trim()
                : $"{d.CarDisplay} · {d.LicensePlate}";
            StatusLine = StatusText(d.Status);
        }

        public Guid Id { get; }
        public string FullName { get; }
        public string CarLine { get; }
        public string StatusLine { get; }
        public double Latitude { get; }
        public double Longitude { get; }

        private static string StatusText(string status) => status?.ToLowerInvariant() switch
        {
            "available" => "Свободен",
            "onroute" or "on_route" => "Едет к клиенту",
            "intrip" or "in_trip" => "В поездке",
            "busy" => "Занят",
            _ => status ?? ""
        };
    }
}
