using System.Globalization;
using TaxiClient.Models;
using TaxiClient.Services;

namespace TaxiClient.Views;

public partial class MainClientPage : ContentPage
{
    private readonly ApiService _api;
    private readonly SignalRService _signalR;
    private readonly GeocodingService? _geo;

    private string _selectedTariff = "Economy";
    private OrderResponse? _activeOrder;
    private List<PriceEstimate> _prices = new();

    private readonly List<Entry> _stopEntries = new();
    private int _stopCount = 0;

    private double _pickupLat = 57.1522;
    private double _pickupLng = 65.5272;
    private double _destLat = 0;
    private double _destLng = 0;

    private bool _suppressPickup;
    private bool _suppressDest;
    private string _paymentMethod = "Cash";

    private CancellationTokenSource? _pickupCts;
    private CancellationTokenSource? _destCts;

    public MainClientPage(ApiService api, SignalRService signalR)
    {
        InitializeComponent();

        _api = api;
        _signalR = signalR;

        try
        {
            _geo = new GeocodingService();
        }
        catch
        {
            _geo = null;
        }

        try
        {
            _signalR.OrderStatusChanged += OnOrderStatusChanged;
            _signalR.DriverLocationUpdated += OnDriverLocationUpdated;
            _signalR.ChatMessageReceived += OnChatMessageOnMainPage;
            _signalR.DriverArrivedNotification += OnDriverArrivedNotification;
        }
        catch { }

        SafeLoadMap();
        BuildTariffButtons();

        PickupEntry.Text = "г. Тюмень, ул. Республики, 52";
    }

    // =========================
    // КАРТА + OSRM
    // =========================
    private void SafeLoadMap()
    {
        try
        {
            var html = @"<!DOCTYPE html>
<html>
<head>
<meta name='viewport' content='width=device-width,initial-scale=1.0'>
<link rel='stylesheet' href='https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'/>
<script src='https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'></script>
<style>
html,body,#map{margin:0;padding:0;width:100%;height:100%}
</style>
</head>
<body>
<div id='map'></div>
<script>
var map = L.map('map').setView([57.1522, 65.5272], 13);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19
}).addTo(map);

var pickupM = L.marker([57.1522, 65.5272], { draggable: true })
    .addTo(map)
    .bindPopup('Подача');

var destM = null;
var driverM = null;
var routeLine = null;

pickupM.on('dragend', function(e) {
    var p = e.target.getLatLng();
    window.location = 'callback://pickup/' + p.lat + '/' + p.lng;
});

function setPickup(lat, lng) {
    pickupM.setLatLng([lat, lng]);
    map.setView([lat, lng], 15);
}

function setDest(lat, lng) {
    if (!destM) {
        destM = L.marker([lat, lng], { draggable: true })
            .addTo(map)
            .bindPopup('Назначение');

        destM.on('dragend', function(e) {
            var p = e.target.getLatLng();
            window.location = 'callback://dest/' + p.lat + '/' + p.lng;
        });
    } else {
        destM.setLatLng([lat, lng]);
    }

    try {
        map.fitBounds([pickupM.getLatLng(), destM.getLatLng()], { padding: [40, 40] });
    } catch(e) {}
}

function drawRoute(lat1, lng1, lat2, lng2) {
    if (routeLine) {
        map.removeLayer(routeLine);
        routeLine = null;
    }

    var url = 'https://router.project-osrm.org/route/v1/driving/'
        + lng1 + ',' + lat1 + ';' + lng2 + ',' + lat2
        + '?overview=full&geometries=geojson';

    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.routes && data.routes.length > 0) {
                var coords = data.routes[0].geometry.coordinates.map(function(c) {
                    return [c[1], c[0]];
                });

                routeLine = L.polyline(coords, {
                    color: '#FFD700',
                    weight: 5,
                    opacity: 0.9
                }).addTo(map);

                map.fitBounds(routeLine.getBounds(), { padding: [40, 40] });
            } else {
                routeLine = L.polyline(
                    [[lat1, lng1], [lat2, lng2]],
                    { color: '#FFD700', weight: 4, dashArray: '8,8' }
                ).addTo(map);
            }
        })
        .catch(function() {
            routeLine = L.polyline(
                [[lat1, lng1], [lat2, lng2]],
                { color: '#FFD700', weight: 4, dashArray: '8,8' }
            ).addTo(map);
        });
}

function setDriver(lat, lng) {
    if (!driverM) {
        driverM = L.marker([lat, lng], {
            icon: L.divIcon({
                className: '',
                html: '<div style=""font-size:30px""></div>',
                iconSize: [30, 30]
            })
        }).addTo(map);
    } else {
        driverM.setLatLng([lat, lng]);
    }
}

function clearDriver() {
    if (driverM) {
        map.removeLayer(driverM);
        driverM = null;
    }
}

function clearRoute() {
    if (routeLine) {
        map.removeLayer(routeLine);
        routeLine = null;
    }
}
</script>
</body>
</html>";

            MapWebView.Source = new HtmlWebViewSource { Html = html };

            MapWebView.Navigating += (s, e) =>
            {
                try
                {
                    if (e.Url.StartsWith("callback://"))
                    {
                        e.Cancel = true;
                        HandleMapCallback(e.Url);
                    }
                }
                catch { }
            };
        }
        catch
        {
            MapWebView.Source = new HtmlWebViewSource
            {
                Html = "<html><body style='background:#1E1E2E;color:white;text-align:center;padding-top:100px'>" +
                       "<h2>Ошибка загрузки карты</h2><p>Проверьте подключение к интернету</p></body></html>"
            };
        }
    }

    private async void HandleMapCallback(string url)
    {
        try
        {
            var parts = url.Replace("callback://", "").Split('/');
            if (parts.Length < 3) return;

            var type = parts[0];

            if (!double.TryParse(parts[1], CultureInfo.InvariantCulture, out var lat))
                return;

            if (!double.TryParse(parts[2], CultureInfo.InvariantCulture, out var lng))
                return;

            AddressSuggestion? address = null;
            try
            {
                if (_geo != null)
                    address = await _geo.ReverseGeocodeAsync(lat, lng);
            }
            catch { }

            MainThread.BeginInvokeOnMainThread(() =>
            {
                var display = address?.DisplayName ?? $"{lat:F4}, {lng:F4}";

                if (type == "pickup")
                {
                    _pickupLat = lat;
                    _pickupLng = lng;
                    _suppressPickup = true;
                    PickupEntry.Text = display;
                    _suppressPickup = false;
                }
                else if (type == "dest")
                {
                    _destLat = lat;
                    _destLng = lng;
                    _suppressDest = true;
                    DestEntry.Text = display;
                    _suppressDest = false;
                    SafeDrawRoute();
                }
            });

            await SafeLoadPricesAsync();
        }
        catch { }
    }

    private void SafeDrawRoute()
    {
        try
        {
            if (_destLat == 0 || _destLng == 0)
                return;

            var p1 = _pickupLat.ToString(CultureInfo.InvariantCulture);
            var p2 = _pickupLng.ToString(CultureInfo.InvariantCulture);
            var p3 = _destLat.ToString(CultureInfo.InvariantCulture);
            var p4 = _destLng.ToString(CultureInfo.InvariantCulture);

            MapWebView.EvaluateJavaScriptAsync($"drawRoute({p1},{p2},{p3},{p4})");
        }
        catch { }
    }

    // =========================
    // ПОДСКАЗКИ АДРЕСОВ
    // =========================
    private async void OnPickupTextChanged(object? sender, TextChangedEventArgs e)
    {
        if (_suppressPickup || _geo == null)
            return;

        try
        {
            _pickupCts?.Cancel();
            _pickupCts = new CancellationTokenSource();
            var token = _pickupCts.Token;

            await Task.Delay(400, token);
            if (token.IsCancellationRequested) return;

            var suggestions = await _geo.SearchAsync(e.NewTextValue);
            MainThread.BeginInvokeOnMainThread(() =>
                ShowSuggestions(PickupSuggestions, suggestions, true));
        }
        catch
        {
            PickupSuggestions.IsVisible = false;
        }
    }

    private async void OnDestTextChanged(object? sender, TextChangedEventArgs e)
    {
        if (_suppressDest || _geo == null)
            return;

        try
        {
            _destCts?.Cancel();
            _destCts = new CancellationTokenSource();
            var token = _destCts.Token;

            await Task.Delay(400, token);
            if (token.IsCancellationRequested) return;

            var suggestions = await _geo.SearchAsync(e.NewTextValue);
            MainThread.BeginInvokeOnMainThread(() =>
                ShowSuggestions(DestSuggestions, suggestions, false));
        }
        catch
        {
            DestSuggestions.IsVisible = false;
        }
    }

    private void ShowSuggestions(StackLayout panel, List<AddressSuggestion> items, bool isPickup)
    {
        try
        {
            panel.Children.Clear();

            if (items == null || items.Count == 0)
            {
                panel.IsVisible = false;
                return;
            }

            foreach (var item in items)
            {
                var label = new Label
                {
                    Text = item.DisplayName,
                    TextColor = Colors.White,
                    FontSize = 13,
                    Padding = new Thickness(10, 8),
                    BackgroundColor = Color.FromArgb("#363650")
                };

                var captured = item;
                var tap = new TapGestureRecognizer();
                tap.Tapped += async (s, e) =>
                {
                    try
                    {
                        var latStr = captured.Latitude.ToString(CultureInfo.InvariantCulture);
                        var lngStr = captured.Longitude.ToString(CultureInfo.InvariantCulture);

                        if (isPickup)
                        {
                            _pickupLat = captured.Latitude;
                            _pickupLng = captured.Longitude;
                            _suppressPickup = true;
                            PickupEntry.Text = captured.DisplayName;
                            _suppressPickup = false;
                            PickupSuggestions.IsVisible = false;

                            await MapWebView.EvaluateJavaScriptAsync($"setPickup({latStr},{lngStr})");
                        }
                        else
                        {
                            _destLat = captured.Latitude;
                            _destLng = captured.Longitude;
                            _suppressDest = true;
                            DestEntry.Text = captured.DisplayName;
                            _suppressDest = false;
                            DestSuggestions.IsVisible = false;

                            await MapWebView.EvaluateJavaScriptAsync($"setDest({latStr},{lngStr})");
                            SafeDrawRoute();
                        }

                        await SafeLoadPricesAsync();
                    }
                    catch { }
                };

                label.GestureRecognizers.Add(tap);
                panel.Children.Add(label);
            }

            panel.IsVisible = true;
        }
        catch
        {
            panel.IsVisible = false;
        }
    }

    // =========================
    // ТАРИФЫ
    // =========================
    private void BuildTariffButtons()
    {
        try
        {
            var tariffs = new[]
            {
                ("Economy", " Эконом"),
                ("Comfort", " Комфорт"),
                ("Business", " Бизнес"),
                ("Minivan", " Минивэн")
            };

            foreach (var (id, name) in tariffs)
            {
                var button = new Button
                {
                    Text = name,
                    BackgroundColor = id == _selectedTariff
                        ? Color.FromArgb("#FFD700")
                        : Color.FromArgb("#333"),
                    TextColor = id == _selectedTariff
                        ? Color.FromArgb("#1E1E2E")
                        : Colors.White,
                    CornerRadius = 20,
                    Padding = new Thickness(14, 6),
                    FontSize = 13,
                    HeightRequest = 36
                };

                var capturedId = id;
                button.Clicked += (s, e) =>
                {
                    _selectedTariff = capturedId;
                    RefreshTariffButtons();
                    UpdatePriceDisplay();
                };

                TariffPanel.Children.Add(button);
            }
        }
        catch { }
    }

    private void RefreshTariffButtons()
    {
        try
        {
            var ids = new[] { "Economy", "Comfort", "Business", "Minivan" };
            for (int i = 0; i < TariffPanel.Children.Count; i++)
            {
                if (TariffPanel.Children[i] is Button button)
                {
                    var selected = ids[i] == _selectedTariff;
                    button.BackgroundColor = selected
                        ? Color.FromArgb("#FFD700")
                        : Color.FromArgb("#333");
                    button.TextColor = selected
                        ? Color.FromArgb("#1E1E2E")
                        : Colors.White;
                }
            }
        }
        catch { }
    }

    // =========================
    // ОСТАНОВКИ
    // =========================
    private void OnAddStopClicked(object? sender, EventArgs e)
    {
        try
        {
            if (_stopCount >= 3) return;

            _stopCount++;

            var grid = new Grid
            {
                ColumnDefinitions =
                {
                    new ColumnDefinition { Width = GridLength.Star },
                    new ColumnDefinition { Width = GridLength.Auto }
                }
            };

            var border = new Border
            {
                BackgroundColor = Color.FromArgb("#2D2D3F"),
                Stroke = Color.FromArgb("#555"),
                StrokeThickness = 1,
                Padding = new Thickness(6, 2),
                StrokeShape = new Microsoft.Maui.Controls.Shapes.RoundRectangle
                {
                    CornerRadius = 10
                }
            };

            var entry = new Entry
            {
                Placeholder = "Остановка " + _stopCount,
                PlaceholderColor = Color.FromArgb("#888"),
                TextColor = Colors.White,
                FontSize = 14
            };

            _stopEntries.Add(entry);
            border.Content = entry;
            grid.Children.Add(border);

            var removeButton = new Button
            {
                Text = "",
                BackgroundColor = Color.FromArgb("#F44336"),
                TextColor = Colors.White,
                CornerRadius = 6,
                WidthRequest = 36,
                HeightRequest = 36,
                FontSize = 14,
                Padding = 0,
                Margin = new Thickness(6, 0, 0, 0)
            };

            Grid.SetColumn(removeButton, 1);

            var capturedGrid = grid;
            var capturedEntry = entry;

            removeButton.Clicked += (s, e2) =>
            {
                try
                {
                    StopsPanel.Children.Remove(capturedGrid);
                    _stopEntries.Remove(capturedEntry);
                    _stopCount--;
                    if (_stopCount < 3)
                        AddStopBtn.IsVisible = true;
                }
                catch { }
            };

            grid.Children.Add(removeButton);
            StopsPanel.Children.Add(grid);

            if (_stopCount >= 3)
                AddStopBtn.IsVisible = false;
        }
        catch { }
    }

    // =========================
    // ГЕОКОДИРОВАНИЕ И ЦЕНА
    // =========================

    private void OnPayCash(object? sender, EventArgs e)
    {
        _paymentMethod = "Cash";
        PayCashBtn.BackgroundColor = Color.FromArgb("#FFD700");
        PayCashBtn.TextColor = Color.FromArgb("#1E1E2E");
        PayCardBtn.BackgroundColor = Color.FromArgb("#333");
        PayCardBtn.TextColor = Colors.White;
    }

    private void OnPayCard(object? sender, EventArgs e)
    {
        _paymentMethod = "Card";
        PayCardBtn.BackgroundColor = Color.FromArgb("#FFD700");
        PayCardBtn.TextColor = Color.FromArgb("#1E1E2E");
        PayCashBtn.BackgroundColor = Color.FromArgb("#333");
        PayCashBtn.TextColor = Colors.White;
    }
        private async void OnCalcPriceClicked(object? sender, EventArgs e)
    {
        try
        {
            PriceLabel.Text = "...";
            DistLabel.Text = "Проверяем адрес...";
            await SafeLoadPricesAsync();
        }
        catch
        {
            PriceLabel.Text = "Ошибка";
            DistLabel.Text = "Ошибка расчёта";
        }
    }

    private async Task GeocodeIfNeededAsync()
    {
        if (_geo == null)
            return;

        try
        {
            if (!string.IsNullOrWhiteSpace(PickupEntry.Text))
            {
                var pickupResults = await _geo.SearchAsync(PickupEntry.Text);
                if (pickupResults.Count > 0)
                {
                    _pickupLat = pickupResults[0].Latitude;
                    _pickupLng = pickupResults[0].Longitude;

                    var latStr = _pickupLat.ToString(CultureInfo.InvariantCulture);
                    var lngStr = _pickupLng.ToString(CultureInfo.InvariantCulture);

                    try
                    {
                        await MapWebView.EvaluateJavaScriptAsync($"setPickup({latStr},{lngStr})");
                    }
                    catch { }
                }
            }

            if (!string.IsNullOrWhiteSpace(DestEntry.Text))
            {
                var destResults = await _geo.SearchAsync(DestEntry.Text);
                if (destResults.Count > 0)
                {
                    _destLat = destResults[0].Latitude;
                    _destLng = destResults[0].Longitude;

                    var latStr = _destLat.ToString(CultureInfo.InvariantCulture);
                    var lngStr = _destLng.ToString(CultureInfo.InvariantCulture);

                    try
                    {
                        await MapWebView.EvaluateJavaScriptAsync($"setDest({latStr},{lngStr})");
                    }
                    catch { }

                    SafeDrawRoute();
                }
            }
        }
        catch { }
    }

    private async Task SafeLoadPricesAsync()
    {
        try
        {
            await GeocodeIfNeededAsync();

            if (_destLat == 0 || _destLng == 0)
            {
                MainThread.BeginInvokeOnMainThread(() =>
                {
                    PriceLabel.Text = "Ошибка";
                    DistLabel.Text = "Ошибка геокодирования";
                });
                return;
            }

            _prices = await _api.GetAllPricesAsync(_pickupLat, _pickupLng, _destLat, _destLng);

            MainThread.BeginInvokeOnMainThread(() =>
            {
                if (_prices == null || _prices.Count == 0)
                {
                    PriceLabel.Text = "Ошибка";
                    DistLabel.Text = "Нет данных по цене";
                }
                else
                {
                    UpdatePriceDisplay();
                }
            });
        }
        catch
        {
            MainThread.BeginInvokeOnMainThread(() =>
            {
                PriceLabel.Text = "Ошибка";
                DistLabel.Text = "Ошибка расчёта";
            });
        }
    }

    private void UpdatePriceDisplay()
    {
        try
        {
            var tariffName = _selectedTariff switch
            {
                "Economy" => "Эконом",
                "Comfort" => "Комфорт",
                "Business" => "Бизнес",
                "Minivan" => "Минивэн",
                _ => "Эконом"
            };

            var price = _prices.FirstOrDefault(x => x.TariffName == tariffName);
            if (price != null)
            {
                PriceLabel.Text = price.Price.ToString("F0") + " ";
                DistLabel.Text = price.DistanceKm.ToString("F1") + " км  " + price.DurationMinutes + " мин";
            }
            else
            {
                PriceLabel.Text = "";
                DistLabel.Text = "";
            }
        }
        catch
        {
            PriceLabel.Text = "Ошибка";
            DistLabel.Text = "";
        }
    }

    // =========================
    // СОЗДАНИЕ ЗАКАЗА
    // =========================
    private async void OnOrderClicked(object? sender, EventArgs e)
    {
        if (string.IsNullOrWhiteSpace(PickupEntry.Text))
        {
            await DisplayAlert("Ошибка", "Укажите адрес подачи", "OK");
            return;
        }

        OrderBtn.IsEnabled = false;
        OrderBtn.Text = " Ищем водителя...";

        try
        {
            if (_prices.Count == 0)
                await SafeLoadPricesAsync();

            if (_destLat == 0 || _destLng == 0)
            {
                await DisplayAlert(
                    "Ошибка геокодирования",
                    "Не удалось определить адрес назначения. Выберите адрес из подсказок или уточните его.",
                    "OK");

                OrderBtn.IsEnabled = true;
                OrderBtn.Text = "  Заказать такси";
                return;
            }

            var comment = CommentEntry.Text ?? "";

            if (ChildSeatCheck.IsChecked)
                comment += " [Детское кресло]";

            if (AcCheck.IsChecked)
                comment += " [Кондиционер]";

            foreach (var stop in _stopEntries)
            {
                if (!string.IsNullOrWhiteSpace(stop.Text))
                    comment += " [Остановка: " + stop.Text.Trim() + "]";
            }

            var passengers = 1;
            if (int.TryParse(PassengerEntry.Text, out int pp) && pp >= 1 && pp <= 8)
                passengers = pp;

            var order = await _api.CreateOrderAsync(new CreateOrderRequest
            {
                ClientId = _api.CurrentUser!.UserId,
                PickupAddress = PickupEntry.Text.Trim(),
                PickupLatitude = _pickupLat,
                PickupLongitude = _pickupLng,
                PickupEntrance = string.IsNullOrWhiteSpace(EntranceEntry.Text) ? null : EntranceEntry.Text.Trim(),
                DestinationAddress = DestEntry.Text?.Trim(),
                DestinationLatitude = _destLat == 0 ? null : _destLat,
                DestinationLongitude = _destLng == 0 ? null : _destLng,
                Tariff = _selectedTariff,
                Comment = comment.Trim(),
                PassengerCount = passengers,
                PaymentMethod = _paymentMethod
            });

            if (order != null)
            {
                _activeOrder = order;

                try
                {
                    await _signalR.SubscribeToOrderAsync(order.Id.ToString());
                }
                catch { }

                ShowActiveOrder(order);
            }
        }
        catch (Exception ex)
        {
            await DisplayAlert("Ошибка", ex.Message, "OK");
            OrderBtn.IsEnabled = true;
            OrderBtn.Text = "  Заказать такси";
        }
    }

    // =========================
    // АКТИВНЫЙ ЗАКАЗ
    // =========================
    private void ShowActiveOrder(OrderResponse order)
    {
        try
        {
            OrderPanel.IsVisible = false;
            ActivePanel.IsVisible = true;

            ActiveStatusLabel.Text = " Ищем водителя...";
            ActiveOrderNum.Text = "Заказ " + order.OrderNumber;
            ActivePriceLabel.Text = order.EstimatedPrice.ToString("F0") + " ";
            ActivePickupLabel.Text = " " + order.PickupAddress
                + (string.IsNullOrWhiteSpace(order.PickupEntrance) ? "" : ", подъезд " + order.PickupEntrance);
            ActiveDestLabel.Text = " " + (order.DestinationAddress ?? "не указано");

            if (order.Driver != null)
                ShowDriverInfo(order.Driver);
            else
            {
                ActiveDriverLabel.Text = "Ищем водителя...";
                ActiveCarLabel.Text = "";
                ActivePhoneLabel.Text = "";
            }
        }
        catch { }
    }

    private void ShowDriverInfo(DriverInfo driver)
    {
        try
        {
            ActiveDriverLabel.Text = driver.FullName + "   " + driver.Rating.ToString("F1");
            ActiveCarLabel.Text = !string.IsNullOrWhiteSpace(driver.CarDisplay)
                ? driver.CarDisplay
                : driver.CarColor + " " + driver.CarBrand + " " + driver.CarModel + " (" + driver.LicensePlate + ")";
            ActivePhoneLabel.Text = " " + driver.Phone;
        }
        catch { }
    }

    // =========================
    // SIGNALR
    // =========================
    private void OnOrderStatusChanged(string method, object? data)
    {
        MainThread.BeginInvokeOnMainThread(async () =>
        {
            try
            {
                if (_activeOrder == null)
                    return;

                var updated = await _api.GetOrderAsync(_activeOrder.Id);
                if (updated == null)
                    return;

                _activeOrder = updated;

                ActiveStatusLabel.Text = updated.Status switch
                {
                    "Searching" => " Ищем водителя...",
                    "DriverAssigned" => " Водитель найден!",
                    "DriverEnRoute" => " Водитель едет к вам",
                    "DriverArrived" => " Водитель на месте!",
                    "InProgress" => " Поездка началась",
                    "Completed" => " Поездка завершена!",
                    "Cancelled" => " Отменён",
                    "NoDriverFound" => " Водитель не найден",
                    _ => updated.StatusText
                };

                if (updated.Driver != null)
                {
                    ShowDriverInfo(updated.Driver);

                    try
                    {
                        await _signalR.SubscribeToDriverAsync(updated.Driver.DriverId.ToString());
                    }
                    catch { }
                }

                if (updated.Status is "Completed" or "Cancelled" or "NoDriverFound")
                {
                    CancelBtn.IsVisible = false;

                    if (updated.Status == "Completed")
                    {
                        // Показываем баннер оплаты если перевод
                        if (updated.Payment != null &&
                            updated.Payment.Method == "Card" &&
                            !string.IsNullOrEmpty(updated.Payment.PaymentPhone))
                        {
                            await ShowPaymentBannerAsync(updated);
                        }

                        await Task.Delay(1000);
                        await SafeShowRatingAsync(updated);
                    }

                    await Task.Delay(2000);
                    ResetToOrderScreen();
                }
            }
            catch { }
        });
    }

    private void OnDriverLocationUpdated(double lat, double lng)
    {
        MainThread.BeginInvokeOnMainThread(() =>
        {
            try
            {
                var la = lat.ToString(CultureInfo.InvariantCulture);
                var lo = lng.ToString(CultureInfo.InvariantCulture);
                MapWebView.EvaluateJavaScriptAsync($"setDriver({la},{lo})");
            }
            catch { }
        });
    }

    // =========================
    // ОТМЕНА / ОЦЕНКА
    // =========================

    private async void OnOpenChat(object? sender, EventArgs e)
    {
        try
        {
            if (_activeOrder == null) return;

            await Navigation.PushAsync(new ChatPage(
                _api, _signalR,
                _activeOrder.Id,
                _api.CurrentUser!.UserId,
                "Client"));
        }
        catch (Exception ex)
        {
            await DisplayAlert("Ошибка", ex.Message, "OK");
        }
    }
        private async void OnCancelClicked(object? sender, EventArgs e)
    {
        try
        {
            if (_activeOrder == null)
                return;

            if (!await DisplayAlert("Отмена", "Отменить заказ?", "Да", "Нет"))
                return;

            try
            {
                await _api.CancelOrderAsync(_activeOrder.Id, _api.CurrentUser!.UserId, "Отменён клиентом");
            }
            catch { }

            ResetToOrderScreen();
        }
        catch { }
    }

    private async Task SafeShowRatingAsync(OrderResponse order)
    {
        try
        {
            var ratingText = await DisplayPromptAsync(
                " Оцените поездку",
                "Оценка от 1 до 5",
                "Отправить",
                "Пропустить",
                maxLength: 1,
                keyboard: Keyboard.Numeric);

            if (int.TryParse(ratingText, out int rating) && rating >= 1 && rating <= 5)
            {
                try
                {
                    await _api.RateOrderAsync(order.Id, rating, null);
                }
                catch { }
            }
        }
        catch { }
    }

    private void ResetToOrderScreen()
    {
        try
        {
            _activeOrder = null;
            OrderPanel.IsVisible = true;
            ActivePanel.IsVisible = false;
            OrderBtn.IsEnabled = true;
            OrderBtn.Text = "  Заказать такси";
            CancelBtn.IsVisible = true;

            try { MapWebView.EvaluateJavaScriptAsync("clearDriver()"); } catch { }
            try { MapWebView.EvaluateJavaScriptAsync("clearRoute()"); } catch { }
        }
        catch { }
    }

    // =========================
    // ИСТОРИЯ
    // =========================

    private void OnChatMessageOnMainPage(object data)
    {
        MainThread.BeginInvokeOnMainThread(() =>
        {
            try
            {
                if (_activeOrder == null) return;

                var json = System.Text.Json.JsonDocument.Parse(data.ToString()!);
                var senderId = json.RootElement.GetProperty("senderId").GetString();
                var senderRole = json.RootElement.GetProperty("senderRole").GetString();
                var text = json.RootElement.GetProperty("text").GetString();

                // Не показываем свои же сообщения
                if (senderId == _api.CurrentUser?.UserId.ToString())
                    return;

                var senderName = senderRole == "Driver" ? "Водитель" : "Клиент";

                ChatBannerText.Text = " " + text;
                ChatBannerSender.Text = senderName;
                ChatBanner.IsVisible = true;

                // Скрываем через 10 секунд
                _ = Task.Run(async () =>
                {
                    await Task.Delay(10000);
                    MainThread.BeginInvokeOnMainThread(() =>
                    {
                        ChatBanner.IsVisible = false;
                    });
                });
            }
            catch { }
        });
    }

    private async void OnChatBannerTapped(object? sender, EventArgs e)
    {
        try
        {
            ChatBanner.IsVisible = false;

            if (_activeOrder == null) return;

            await Navigation.PushAsync(new ChatPage(
                _api, _signalR,
                _activeOrder.Id,
                _api.CurrentUser!.UserId,
                "Client"));
        }
        catch { }
    }
    
    private void OnDriverArrivedNotification(object data)
    {
        MainThread.BeginInvokeOnMainThread(async () =>
        {
            try
            {
                var json = System.Text.Json.JsonDocument.Parse(data.ToString()!);
                var message = json.RootElement.GetProperty("message").GetString();
                var carInfo = json.RootElement.GetProperty("carInfo").GetString();
                var freeMin = json.RootElement.GetProperty("freeWaitingMinutes").GetInt32();

                await DisplayAlert(
                    " Такси прибыло!",
                    $"{message}\n\nБесплатное ожидание: {freeMin} минут.",
                    "OK");
            }
            catch { }
        });
    }
        
    private async Task ShowPaymentBannerAsync(OrderResponse order)
    {
        try
        {
            var p = order.Payment;
            if (p == null)
                return;

            var bankInfo = !string.IsNullOrWhiteSpace(p.BankName)
                ? p.BankName
                : "Банк";

            var holder = !string.IsNullOrWhiteSpace(p.CardHolder)
                ? p.CardHolder
                : "Получатель не указан";

            var phone = !string.IsNullOrWhiteSpace(p.PaymentPhone)
                ? p.PaymentPhone
                : "Телефон не указан";

            var message =
                $"Сумма к оплате: {p.Amount:F0} ₽\n\n" +
                $"Банк: {bankInfo}\n" +
                $"Получатель: {holder}\n" +
                $"Телефон: {phone}\n\n";

            if (p.AcceptSbp && !string.IsNullOrWhiteSpace(p.SbpLink))
            {
                var useSbp = await DisplayAlert(
                    " Оплата переводом",
                    message + "Открыть СБП для перевода?",
                    "Открыть СБП",
                    "Переведу сам");

                if (useSbp)
                {
                    try
                    {
                        await Launcher.OpenAsync(new Uri(p.SbpLink));
                    }
                    catch
                    {
                        await DisplayAlert("Ошибка", "Не удалось открыть ссылку СБП.", "OK");
                    }
                }
            }
            else
            {
                await DisplayAlert(
                    " Оплата переводом",
                    message + "Переведите указанную сумму на номер телефона водителя.",
                    "OK");
            }
        }
        catch (Exception ex)
        {
            await DisplayAlert("Ошибка оплаты", ex.Message, "OK");
        }
    }
private async void OnHistoryClicked(object? sender, EventArgs e)
    {
        try
        {
            await Navigation.PushAsync(new HistoryPage(_api));
        }
        catch (Exception ex)
        {
            await DisplayAlert("Ошибка истории поездок", ex.Message, "OK");
        }
    }
}