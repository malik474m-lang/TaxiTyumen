using System.Globalization;
using System.Text;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Data;
using System.Windows.Documents;
using System.Windows.Media;
using System.Windows.Threading;
using Microsoft.Web.WebView2.Wpf;
using TaxiOperator.Models;
using TaxiOperator.Services;

namespace TaxiOperator.Views;

/// Карта автопарка — полностью в C# без XAML (XamlC оказался несостоятельным здесь с пространством xmlns:wv2)
public class FleetMapWindow : Window
{
    private readonly ApiService _api;
    private readonly WebView2 _mapView = new();
    private readonly DispatcherTimer _timer;
    private readonly ListBox _driversList = new();
    private readonly TextBlock _statusText = new();
    private readonly CheckBox _followCheck = new();

    private bool _mapReady;
    private Guid? _followDriverId;

    public FleetMapWindow(ApiService api)
    {
        _api = api;
        BuildUi();

        _timer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(5) };
        _timer.Tick += async (_, _) => await RefreshAsync();

        Loaded += async (_, _) => await InitAsync();
        Closed += (_, _) => _timer.Stop();
    }

    private void BuildUi()
    {
        Title = "Карта автопарка";
        Width = 1180; Height = 760;
        WindowStartupLocation = WindowStartupLocation.CenterOwner;
        Background = new SolidColorBrush(Color.FromRgb(0x1E, 0x1E, 0x2E));

        var root = new Grid();
        root.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });
        root.RowDefinitions.Add(new RowDefinition());
        root.ColumnDefinitions.Add(new ColumnDefinition());
        root.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(320) });
        root.Margin = new Thickness(12);

        // --- Заголовок: только текст и кнопки без дополнительного XAML (стабильность SDK)
        var header = new DockPanel();
        Grid.SetRow(header, 0); Grid.SetColumnSpan(header, 2);
        var titleBlock = new TextBlock
        {
            Text = "Карта автопарка",
            FontSize = 20, FontWeight = FontWeights.Bold,
            Foreground = new SolidColorBrush(Color.FromRgb(0xFF, 0xD7, 0x00)),
            VerticalAlignment = VerticalAlignment.Center
        };
        _statusText.Foreground = new SolidColorBrush(Colors.LightGray);
        _statusText.Margin = new Thickness(15, 0, 0, 0);
        _statusText.VerticalAlignment = VerticalAlignment.Center;
        _statusText.Text = "Загрузка…";
        _followCheck.Foreground = new SolidColorBrush(Colors.LightGray);
        _followCheck.Content = "Следить за выбранной машиной";
        _followCheck.Margin = new Thickness(20, 0, 0, 0);
        _followCheck.VerticalAlignment = VerticalAlignment.Center;

        var refreshBtn = new Button
        {
            Content = "Обновить",
            Background = new SolidColorBrush(Color.FromRgb(0x33, 0x33, 0x33)),
            Foreground = new SolidColorBrush(Colors.White), BorderThickness = new Thickness(0),
            Padding = new Thickness(12, 6, 12, 6), Margin = new Thickness(20, 0, 0, 0)
        };
        refreshBtn.Click += async (_, _) => await RefreshAsync();

        DockPanel.SetDock(header, Dock.Top);
        titleBlock.SetValue(DockPanel.DockProperty, Dock.Left);
        _statusText.SetValue(DockPanel.DockProperty, Dock.Left);
        _followCheck.SetValue(DockPanel.DockProperty, Dock.Left);
        refreshBtn.SetValue(DockPanel.DockProperty, Dock.Right);
        header.Children.Add(refreshBtn);
        header.Children.Add(_followCheck);
        header.Children.Add(_statusText);
        header.Children.Add(titleBlock);
        root.Children.Add(header);

        // --- Карта слева, список водителей справа
        var mapHost = new Border
        {
            Background = new SolidColorBrush(Color.FromRgb(0x0F, 0x0F, 0x13)),
            CornerRadius = new CornerRadius(8),
            Child = _mapView
        };
        Grid.SetRow(mapHost, 1);
        root.Children.Add(mapHost);

        var driversPanel = new Border
        {
            Background = new SolidColorBrush(Color.FromRgb(0x25, 0x25, 0x36)),
            CornerRadius = new CornerRadius(8), Margin = new Thickness(10, 0, 0, 0)
        };
        Grid.SetRow(driversPanel, 1); Grid.SetColumn(driversPanel, 1);

        var driversLayout = new DockPanel();
        var listHeader = new TextBlock
        {
            Text = "Водители в сети", FontWeight = FontWeights.Bold,
            Foreground = new SolidColorBrush(Colors.White), Margin = new Thickness(12, 12, 12, 8),
            FontSize = 14
        };
        DockPanel.SetDock(listHeader, Dock.Top);
        driversLayout.Children.Add(listHeader);
        driversLayout.Children.Add(_driversList);
        driversPanel.Child = driversLayout;
        root.Children.Add(driversPanel);

        // Список водителей
        _driversList.Background = new SolidColorBrush(Colors.Transparent);
        _driversList.BorderThickness = new Thickness(0);
        _driversList.Foreground = new SolidColorBrush(Colors.White);
        _driversList.Margin = new Thickness(6, 0, 6, 10);
        _driversList.SelectionChanged += OnDriverSelected;
        _driversList.ItemTemplate = new DataTemplate
        {
            VisualTree = new FrameworkElementFactory(typeof(StackPanel))
        };
        _driversList.ItemTemplate.VisualTree.AppendChild(new FrameworkElementFactory(typeof(TextBlock))
            .ApplyTemplate(t => t.SetBinding(TextBlock.TextProperty, new Binding("FullName"))
                .SetValue(TextBlock.FontWeightProperty, FontWeights.Bold)
                .SetValue(TextBlock.ForegroundProperty, new SolidColorBrush(Colors.White)));
        var carBlock = new FrameworkElementFactory(typeof(TextBlock));
        carBlock.SetBinding(TextBlock.TextProperty, new Binding("CarLine"));
        carBlock.SetValue(TextBlock.ForegroundProperty, new SolidColorBrush(Colors.LightGray));
        carBlock.SetValue(TextBlock.FontSizeProperty, 12.0);
        _driversList.ItemTemplate.VisualTree.AppendChild(carBlock);
        var statusBlock = new FrameworkElementFactory(typeof(TextBlock));
        statusBlock.SetBinding(TextBlock.TextProperty, new Binding("StatusLine"));
        statusBlock.SetValue(TextBlock.ForegroundProperty, new SolidColorBrush(Color.FromRgb(0x7d, 0xd3, 0xfc)));
        statusBlock.SetValue(TextBlock.FontSizeProperty, 12.0);
        _driversList.ItemTemplate.VisualTree.AppendChild(statusBlock);

        Content = root;
    }

    private async Task InitAsync()
    {
        try
        {
            await _mapView.EnsureCoreWebView2Async();
            _mapView.NavigationCompleted += async (_, _) =>
            {
                _mapReady = true;
                await RefreshAsync();
            };
            _mapView.NavigateToString(BuildMapHtml(await MapConfig.GetApiKeyAsync()));
            _timer.Start();
        }
        catch (Exception ex)
        {
            _statusText.Text = "WebView2 недоступен: " + ex.Message + ". Список ниже продолжает работать.";
            _timer.Start();
            await RefreshAsync();
        }
    }

    private async Task RefreshAsync()
    {
        try
        {
            var drivers = await _api.GetOnlineDriversAsync();
            var items = drivers
                .OrderBy(d => d.Status?.ToLower() == "available" ? 0 : 1)
                .ThenBy(d => d.FullName)
                .Select(d => new DriverRow(d))
                .ToList();

            var selectedId = (_driversList.SelectedItem as DriverRow)?.Id;
            _driversList.ItemsSource = items;
            if (selectedId != null)
                _driversList.SelectedItem = items.FirstOrDefault(i => i.Id == selectedId);

            _statusText.Text = $"В сети: {drivers.Count} · {DateTime.Now:HH:mm:ss}";

            if (_mapReady && _mapView.CoreWebView2 != null)
            {
                var payload = JsonSerializer.Serialize(drivers.Select(d => new
                {
                    id = d.Id.ToString(), name = d.FullName,
                    car = string.IsNullOrWhiteSpace(d.CarDisplay)
                        ? $"{d.CarColor} {d.CarBrand} {d.CarModel}".Trim()
                        : d.CarDisplay,
                    plate = d.LicensePlate, status = d.Status,
                    lat = d.Latitude, lng = d.Longitude
                }));
                var follow = _followCheck.IsChecked == true && _followDriverId != null
                    ? $"'{_followDriverId}'" : "null";
                await _mapView.ExecuteScriptAsync(
                    $"window.syncDrivers && window.syncDrivers({payload}, {follow});");
            }
        }
        catch (Exception ex)
        {
            _statusText.Text = "Ошибка: " + ex.Message;
        }
    }

    private async void OnDriverSelected(object? sender, SelectionChangedEventArgs e)
    {
        if (_driversList.SelectedItem is not DriverRow row) return;
        _followDriverId = row.Id;
        if (!_mapReady || _mapView.CoreWebView2 == null) return;

        var lat = row.Latitude.ToString("F6", CultureInfo.InvariantCulture);
        var lng = row.Longitude.ToString("F6", CultureInfo.InvariantCulture);
        await _mapView.ExecuteScriptAsync(
            $"window.focusDriver && window.focusDriver('{row.Id}',{lat},{lng});");
    }

    private static string BuildMapHtml(string apiKey)
    {
        var key = string.IsNullOrWhiteSpace(apiKey) ? "" : "&apikey=" + Uri.EscapeDataString(apiKey);
        return @"<!DOCTYPE html><html><head><meta charset='utf-8'>
<style>html,body,#map{margin:0;padding:0;height:100%;width:100%;background:#0F0F13}
.err{color:#aaa;font:14px sans-serif;padding:24px;text-align:center}</style>
<script src='https://api-maps.yandex.ru/2.1/?lang=ru_RU" + key + @"'></script></head>
<body><div id='map'></div>
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
</script></body></html>";
    }

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
