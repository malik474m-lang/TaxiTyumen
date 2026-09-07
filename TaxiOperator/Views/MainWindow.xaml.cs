using System.Collections.ObjectModel;
using System.Linq;
using System.Windows;
using System.Windows.Threading;
using TaxiOperator.Models;
using TaxiOperator.Services;

namespace TaxiOperator.Views;

public partial class MainWindow : Window
{
    private readonly ApiService _api;
    private readonly DispatcherTimer _refreshTimer;
    private ObservableCollection<OrderViewModel> _orders = new();
    private OrderResponse? _selectedOrder;
    private readonly DadataService _dadata = new();
    private double _pickupLat = 57.1522;
    private double _pickupLng = 65.5272;
    private double _destLat = 0;
    private double _destLng = 0;
    private bool _suppressPickupChange = false;
    private bool _suppressDestChange = false;
    private readonly List<IntermediatePointRequest> _stops = new();
    private List<AddressSuggestion> _pickupSuggestions = new();
    private List<AddressSuggestion> _destSuggestions = new();

    // ── SIP-софтфон ────────────────────────────────────────────────────────
    private readonly SipSettings _sipSettings = SipSettings.Load();
    private SipService? _sip;
    private DispatcherTimer? _callTimer;
    private DateTime _callStartedAt;
    private DispatcherTimer? _brandTimer;

    public MainWindow(ApiService api)
    {
        InitializeComponent();
        _api = api;

        OrdersGrid.ItemsSource = _orders;

        // PHP-хостинг: realtime через notifications polling
        // Обновление заказов и уведомлений каждые 3 секунды
        _refreshTimer = new DispatcherTimer
        {
            Interval = TimeSpan.FromSeconds(3)
        };
        _refreshTimer.Tick += async (s, e) =>
        {
            await RefreshAsync();
            await PollNotificationsAsync();
        };
        _refreshTimer.Start();
        InitPreorderControls();

        if (_api.CurrentUser != null)
            OperatorNameText.Text = $"{_api.CurrentUser.FirstName} {_api.CurrentUser.LastName}";

        ApplyBranding(BrandingService.Current);
        BrandingService.Updated += b => Dispatcher.Invoke(() => ApplyBranding(b));

        // Бренд может измениться в админке во время смены — тянем раз в 5 минут
        _brandTimer = new DispatcherTimer { Interval = TimeSpan.FromMinutes(5) };
        _brandTimer.Tick += async (_, _) => await BrandingService.LoadAsync();
        _brandTimer.Start();

        InitSip();

        // Корректно отпускаем SIP-регистрацию и звук при закрытии пульта
        Closed += (_, _) =>
        {
            _brandTimer?.Stop();
            _sip?.Dispose();
        };
    }

    /// Брендинг из админки: заголовок окна, название сервиса, телефон поддержки, цвета
    private void ApplyBranding(BrandingData b)
    {
        try
        {
            Title = BrandingService.WindowTitle(
                string.IsNullOrWhiteSpace(b.HeroTitle) ? "Пульт оператора" : b.HeroTitle);
            BrandNameText.Text = b.ServiceName;
            BrandAppText.Text = string.IsNullOrWhiteSpace(b.AppName) ? "Пульт оператора" : b.AppName;
            BrandSupportText.Text = string.IsNullOrWhiteSpace(b.SupportPhone)
                ? ""
                : "Поддержка: " + b.SupportPhone;
            BrandingService.Apply(b);   // обновляем кисти BrandBrush в ресурсах
        }
        catch { }
    }

    // ── Телефония: инициализация, события, управление вызовом ──────────────
    private void InitSip()
    {
        _sip = new SipService(_sipSettings);

        _sip.StatusChanged += text => Dispatcher.Invoke(() => SipStatusText.Text = text);

        _sip.IncomingCall += number => Dispatcher.Invoke(() =>
        {
            SipCallerText.Text = "☎ " + number;
            SipAnswerBtn.IsEnabled = true;
            SipHangupBtn.IsEnabled = true;
            Activate();                       // поднимаем окно оператора
            if (_sipSettings.AutoFillPhone) FillClientPhone(number);
        });

        _sip.CallConnected += number => Dispatcher.Invoke(() =>
        {
            SipAnswerBtn.IsEnabled = false;
            SipHangupBtn.IsEnabled = true;
            SipMuteBtn.IsEnabled = true;
            StartCallTimer();
            if (_sipSettings.AutoFillPhone && !string.IsNullOrWhiteSpace(number)) FillClientPhone(number);
        });

        _sip.CallEnded += () => Dispatcher.Invoke(() =>
        {
            SipCallerText.Text = "";
            SipTimerText.Text = "";
            SipAnswerBtn.IsEnabled = false;
            SipHangupBtn.IsEnabled = false;
            SipMuteBtn.IsEnabled = false;
            SipMuteBtn.IsChecked = false;
            _callTimer?.Stop();
        });

        if (!_sip.IsAvailable)
        {
            SipStatusText.Text = "SIP-телефония отключена в этой сборке";
            return;
        }
        _ = _sip.StartAsync();
    }

    /// Номер звонящего → в форму нового заказа (нормализация к +7…)
    private void FillClientPhone(string number)
    {
        var digits = new string(number.Where(char.IsDigit).ToArray());
        if (digits.Length >= 10)
        {
            digits = digits[^10..];
            ClientPhoneBox.Text = "+7" + digits;
        }
        else if (!string.IsNullOrWhiteSpace(number))
        {
            ClientPhoneBox.Text = number;
        }
        PickupAddressBox.Focus();
    }

    private void StartCallTimer()
    {
        _callStartedAt = DateTime.Now;
        _callTimer ??= new DispatcherTimer { Interval = TimeSpan.FromSeconds(1) };
        _callTimer.Tick -= OnCallTimerTick;
        _callTimer.Tick += OnCallTimerTick;
        _callTimer.Start();
    }

    private void OnCallTimerTick(object? sender, EventArgs e)
    {
        var d = DateTime.Now - _callStartedAt;
        SipTimerText.Text = $"Разговор {d:mm\\:ss}";
    }

    private void OnSipSettingsClick(object sender, RoutedEventArgs e)
    {
        var dlg = new SipSettingsWindow(_sipSettings) { Owner = this };
        if (dlg.ShowDialog() == true)
        {
            _sip?.Stop();
            _ = _sip?.StartAsync();
        }
    }

    private async void OnSipAnswerClick(object sender, RoutedEventArgs e)
    {
        if (_sip != null) await _sip.AnswerAsync();
    }

    private void OnSipHangupClick(object sender, RoutedEventArgs e) => _sip?.Hangup();

    private void OnSipMuteClick(object sender, RoutedEventArgs e)
        => _sip?.SetMute(SipMuteBtn.IsChecked == true);

    private async void OnSipCallClick(object sender, RoutedEventArgs e)
    {
        var number = SipDialBox.Text.Trim();
        if (string.IsNullOrWhiteSpace(number) || _sip == null) return;
        SipHangupBtn.IsEnabled = true;
        SipCallerText.Text = "☎ " + number;
        await _sip.CallAsync(number);
    }

    private async void OnWindowLoaded(object sender, RoutedEventArgs e)
    {
        await RefreshAsync();
    }

    private async Task PollNotificationsAsync()
    {
        try
        {
            var notifications = await _api.GetNotificationsAsync();
            foreach (var n in notifications)
            {
                if (n.Type == "DriverRejectedOrder")
                {
                    System.Media.SystemSounds.Exclamation.Play();
                    MessageBox.Show(n.Message, n.Title, MessageBoxButton.OK, MessageBoxImage.Warning);
                }
                else if (n.Type == "AdminMessage")
                {
                    MessageBox.Show(n.Message, n.Title, MessageBoxButton.OK, MessageBoxImage.Information);
                }
                await _api.MarkNotificationReadAsync(n.Id);
            }
        }
        catch { }
    }

    // ===== Обновление данных =====
    private async Task RefreshAsync()
    {
        try
        {
            var orders = await _api.GetActiveOrdersAsync();
            var drivers = await _api.GetOnlineDriversAsync();

            Dispatcher.Invoke(() =>
            {
                _orders.Clear();
                foreach (var o in orders)
                    _orders.Add(new OrderViewModel(o));

                ActiveCountText.Text = orders.Count(o =>
                    o.Status is "DriverAssigned" or "DriverEnRoute"
                        or "DriverArrived" or "InProgress").ToString();

                WaitingCountText.Text = orders.Count(o =>
                    o.Status is "Created" or "Searching").ToString();

                DriversCountText.Text = drivers.Count.ToString();
                LastUpdateText.Text = DateTime.Now.ToString("HH:mm:ss");
            });
        }
        catch (Exception ex)
        {
            Dispatcher.Invoke(() =>
                LastUpdateText.Text = $"Ошибка: {ex.Message}");
        }
    }

    private FleetMapWindow? _fleetMapWindow;

    /// Карта автопарка: одно окно на сессию, повторный клик — переключение фокуса.
    private void OnFleetMapClick(object sender, RoutedEventArgs e)
    {
        if (_fleetMapWindow is { IsLoaded: true })
        {
            _fleetMapWindow.Activate();
            return;
        }
        _fleetMapWindow = new FleetMapWindow(_api) { Owner = this };
        _fleetMapWindow.Closed += (_, _) => _fleetMapWindow = null;
        _fleetMapWindow.Show();
    }

    private async void OnRefreshClick(object sender, RoutedEventArgs e)
    {
        await RefreshAsync();
    }

    // ===== Выбор заказа =====
    private void OnOrderSelected(object sender,
        System.Windows.Controls.SelectionChangedEventArgs e)
    {
        if (OrdersGrid.SelectedItem is not OrderViewModel vm) return;

        _selectedOrder = vm.Order;
        var o = vm.Order;

        DetailNumber.Text = o.OrderNumber;
        DetailStatus.Text = o.IsPreorder && o.ScheduledAt.HasValue
            ? $"{o.StatusText}\nПредзаказ на {o.ScheduledAt.Value.ToLocalTime():dd.MM.yyyy HH:mm}"
              + (o.PreorderSurcharge > 0 ? $" (наценка {o.PreorderSurcharge:F0} ₽)" : "")
            : o.StatusText;
        DetailClient.Text = o.ClientName ?? "";
        DetailPhone.Text = o.ClientPhone ?? "";
        DetailPickup.Text = o.PickupAddress
                + (string.IsNullOrWhiteSpace(o.PickupEntrance) ? "" : ", подъезд " + o.PickupEntrance);
        DetailDest.Text = o.DestinationAddress ?? "не указано";
        if (!string.IsNullOrWhiteSpace(o.DestinationEntrance))
            DetailDest.Text += ", подъезд " + o.DestinationEntrance;
        DetailDriver.Text = o.Driver != null ? o.Driver.FullName : "не назначен";
        DetailCar.Text = o.Driver != null
            ? (!string.IsNullOrWhiteSpace(o.Driver.CarDisplay)
                ? o.Driver.CarDisplay
                : $"{o.Driver.CarColor} {o.Driver.CarBrand} {o.Driver.CarModel} ({o.Driver.LicensePlate})")
            : "";
        DetailTariff.Text = o.TariffName;
        // Разбивка: тариф + простой = итог. Пока заказ не завершён, показываем оценку.
        if (o.WaitingCost > 0)
        {
            DetailPrice.Text = $"{o.TotalPrice:F0} ₽  (тариф {o.TariffPrice:F0} ₽ + простой {o.WaitingCost:F0} ₽)";
        }
        else
        {
            var basePrice = o.FinalPrice ?? o.EstimatedPrice;
            DetailPrice.Text = $"{basePrice:F0} ₽";
        }
        DetailDistTime.Text = o.EstimatedDistance.HasValue
            ? $"{o.EstimatedDistance:F1} км  {o.EstimatedDuration} мин"
            : "";
        DetailComment.Text = o.Comment ?? "";
            _ = LoadDriverBalanceAsync(o);
            _ = LoadDriverBalanceHistoryAsync(o);

        CancelOrderBtn.IsEnabled =
                o.Status != "Completed" &&
                o.Status != "Cancelled";
    }

    // ===== Создание заказа =====
    // ── Автоподстановка клиента по номеру телефона ─────────────────────────
    private async void OnClientPhoneLostFocus(object sender, RoutedEventArgs e)
        => await LookupClientAsync();

    private async void OnClientPhoneKeyDown(object sender, System.Windows.Input.KeyEventArgs e)
    {
        if (e.Key == System.Windows.Input.Key.Enter) await LookupClientAsync();
    }

    private async Task LookupClientAsync()
    {
        var phone = ClientPhoneBox.Text.Trim();
        if (phone.Length < 11)
        {
            ClientHintText.Text = "";
            return;
        }

        var client = await _api.LookupClientAsync(phone);
        if (client is not { Found: true })
        {
            ClientHintText.Text = "Новый клиент — будет сохранён автоматически";
            return;
        }

        // Имя подставляем только в пустое поле, чтобы не затирать ручной ввод.
        if (string.IsNullOrWhiteSpace(ClientNameBox.Text) && !string.IsNullOrWhiteSpace(client.FirstName))
            ClientNameBox.Text = client.FirstName;

        var hint = $"Клиент найден: {client.Name}, поездок: {client.CompletedTrips}";
        if (client.IsBlocked) hint += " · ЗАБЛОКИРОВАН";
        if (!string.IsNullOrWhiteSpace(client.LastPickupAddress))
            hint += $"\nПоследняя подача: {client.LastPickupAddress}";
        ClientHintText.Text = hint;

        // Пустой адрес подачи заполняем прошлым — частый повторный заказ.
        if (string.IsNullOrWhiteSpace(PickupAddressBox.Text) && !string.IsNullOrWhiteSpace(client.LastPickupAddress))
        {
            PickupAddressBox.Text = client.LastPickupAddress;
            if (string.IsNullOrWhiteSpace(EntranceBox.Text) && !string.IsNullOrWhiteSpace(client.LastPickupEntrance))
                EntranceBox.Text = client.LastPickupEntrance;
        }
    }

    // ── Промежуточные адреса маршрута ──────────────────────────────────────
    private async void OnAddStopClick(object sender, RoutedEventArgs e)
    {
        var address = StopAddressBox.Text.Trim();
        if (address.Length < 3)
        {
            MessageBox.Show("Введите промежуточный адрес", "Промежуточная точка",
                MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }

        double lat = 0, lng = 0;
        var found = await _dadata.SearchAsync(address);
        if (found.Count > 0)
        {
            address = found[0].DisplayName;
            lat = found[0].Latitude;
            lng = found[0].Longitude;
        }

        _stops.Add(new IntermediatePointRequest { Address = address, Latitude = lat, Longitude = lng });
        RefreshStopsList();
        StopAddressBox.Clear();
        await UpdatePriceAsync();
    }

    private void OnTariffChanged(object sender,
        System.Windows.Controls.SelectionChangedEventArgs e)
    {
        if (!IsLoaded) return;
        _ = UpdatePriceAsync();
    }

    // ── Предварительный заказ ──────────────────────────────────────────────
    private void InitPreorderControls()
    {
        for (var h = 0; h < 24; h++) PreorderHourCombo.Items.Add(h.ToString("00"));
        for (var m = 0; m < 60; m += 5) PreorderMinuteCombo.Items.Add(m.ToString("00"));

        // По умолчанию — ближайшее время через час, округлённое до 5 минут.
        var suggested = DateTime.Now.AddHours(1);
        PreorderDatePicker.SelectedDate = suggested.Date;
        PreorderHourCombo.SelectedIndex = suggested.Hour;
        PreorderMinuteCombo.SelectedIndex = Math.Min(11, suggested.Minute / 5);
    }

    /// Выбранные дата и время подачи или null, если предзаказ выключен.
    private DateTime? GetScheduledAt()
    {
        if (PreorderCheck.IsChecked != true) return null;
        var date = PreorderDatePicker.SelectedDate ?? DateTime.Today;
        var hour = Math.Max(0, PreorderHourCombo.SelectedIndex);
        var minute = Math.Max(0, PreorderMinuteCombo.SelectedIndex) * 5;
        return date.Date.AddHours(hour).AddMinutes(minute);
    }

    private void OnPreorderChanged(object sender, RoutedEventArgs e)
    {
        if (!IsLoaded) return;
        PreorderPanel.Visibility = PreorderCheck.IsChecked == true
            ? Visibility.Visible
            : Visibility.Collapsed;
        UpdatePreorderHint();
        _ = UpdatePriceAsync();
    }

    private void OnPreorderDateTimeChanged(object sender, SelectionChangedEventArgs e)
    {
        if (!IsLoaded) return;
        UpdatePreorderHint();
        _ = UpdatePriceAsync();
    }

    private void OnPreorderTimeChanged(object sender,
        System.Windows.Controls.SelectionChangedEventArgs e)
    {
        if (!IsLoaded) return;
        UpdatePreorderHint();
        _ = UpdatePriceAsync();
    }

    private void UpdatePreorderHint()
    {
        var scheduled = GetScheduledAt();
        if (scheduled == null)
        {
            PreorderHintText.Text = "";
            return;
        }

        var diff = scheduled.Value - DateTime.Now;
        if (diff.TotalMinutes < 5)
        {
            PreorderHintText.Text = "Время должно быть минимум через 5 минут";
            PreorderHintText.Foreground = System.Windows.Media.Brushes.IndianRed;
            return;
        }

        PreorderHintText.Foreground = System.Windows.Media.Brushes.LightSkyBlue;
        PreorderHintText.Text = diff.TotalHours >= 1
            ? $"Подача через {(int)diff.TotalHours} ч {diff.Minutes} мин · {scheduled:dd.MM HH:mm}"
            : $"Подача через {(int)diff.TotalMinutes} мин · {scheduled:dd.MM HH:mm}";
    }

    private void OnRoundTripChanged(object sender, RoutedEventArgs e)
    {
        if (!IsLoaded) return;
        _ = UpdatePriceAsync();
    }

    private void OnClearStopsClick(object sender, RoutedEventArgs e)
    {
        _stops.Clear();
        RefreshStopsList();
    }

    private void RefreshStopsList()
    {
        StopsList.ItemsSource = _stops
            .Select((p, i) => $"{i + 1}. {p.Address}")
            .ToList();
        var hasStops = _stops.Count > 0;
        StopsList.Visibility = hasStops ? Visibility.Visible : Visibility.Collapsed;
        ClearStopsBtn.Visibility = hasStops ? Visibility.Visible : Visibility.Collapsed;
    }

    /// Предварительная стоимость: считается по выбранному тарифу,
    /// как только известны координаты подачи и назначения.
    private async Task UpdatePriceAsync()
    {
        try
        {
            if (_pickupLat == 0 || _destLat == 0)
            {
                PriceText.Text = "—";
                DistanceText.Text = "Укажите адреса подачи и назначения";
                return;
            }

            var scheduledAt = GetScheduledAt();
            var estimates = await _api.GetPriceEstimateAsync(
                _pickupLat, _pickupLng, _destLat, _destLng,
                _stops.ToList(), RoundTripCheck.IsChecked == true,
                scheduledAt != null);
            if (estimates.Count == 0)
            {
                PriceText.Text = "—";
                DistanceText.Text = "Не удалось рассчитать стоимость";
                return;
            }

            var index = Math.Clamp(TariffCombo.SelectedIndex, 0, estimates.Count - 1);
            var estimate = estimates[index];

            // Маршрут уже посчитан через все точки, поэтому просто показываем их количество.
            var stopsNote = _stops.Count > 0 ? $" · через {_stops.Count} точк(и)" : "";
            if (RoundTripCheck.IsChecked == true) stopsNote += " · туда и обратно";
            if (estimate.PreorderSurcharge > 0)
                stopsNote += $" · предзаказ +{estimate.PreorderSurcharge:F0} ₽";

            PriceText.Text = $"{estimate.Price:F0} ₽";
            DistanceText.Text = $"{estimate.DistanceKm:F1} км · ~{estimate.DurationMinutes} мин{stopsNote}";
        }
        catch
        {
            PriceText.Text = "—";
            DistanceText.Text = "Ошибка расчёта стоимости";
        }
    }

    private async void OnCreateOrderClick(object sender, RoutedEventArgs e)
    {
        if (string.IsNullOrWhiteSpace(ClientPhoneBox.Text) ||
            string.IsNullOrWhiteSpace(PickupAddressBox.Text))
        {
            MessageBox.Show("Заполните телефон и адрес подачи!",
                "Ошибка", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        // Предзаказ: время должно быть в будущем.
        var scheduledCheck = GetScheduledAt();
        if (scheduledCheck != null && scheduledCheck.Value <= DateTime.Now.AddMinutes(5))
        {
            MessageBox.Show("Время предварительного заказа должно быть минимум через 5 минут.",
                "Ошибка", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        // Конечный адрес обязателен: без него нельзя рассчитать стоимость поездки.
        if (string.IsNullOrWhiteSpace(DestinationBox.Text))
        {
            MessageBox.Show("Укажите адрес назначения — он обязателен.",
                "Ошибка", MessageBoxButton.OK, MessageBoxImage.Warning);
            DestinationBox.Focus();
            return;
        }

        CreateOrderBtn.IsEnabled = false;
        CreateOrderBtn.Content = "  Создание...";

        try
        {
            var tariff = TariffCombo.SelectedIndex switch
            {
                0 => "Economy",
                1 => "Comfort",
                2 => "Business",
                3 => "Minivan",
                _ => "Economy"
            };

            // Если координаты подачи не выбраны из подсказки  геокодируем сами
            if (!string.IsNullOrWhiteSpace(PickupAddressBox.Text))
            {
                var pickupResults = await _dadata.SearchAsync(PickupAddressBox.Text.Trim());
                if (pickupResults.Count > 0)
                {
                    _pickupLat = pickupResults[0].Latitude;
                    _pickupLng = pickupResults[0].Longitude;
                }
            }

            if (_pickupLat == 0 || _pickupLng == 0)
            {
                MessageBox.Show("Не удалось определить координаты адреса подачи. Выберите адрес из подсказок.",
                    "Ошибка геокодирования", MessageBoxButton.OK, MessageBoxImage.Warning);
                return;
            }

            // Если введён конечный адрес  координаты должны быть обязательно
            string? destinationAddress = null;
            double? destinationLat = null;
            double? destinationLng = null;

            if (!string.IsNullOrWhiteSpace(DestinationBox.Text))
            {
                destinationAddress = DestinationBox.Text.Trim();

                var destResults = await _dadata.SearchAsync(destinationAddress);
                if (destResults.Count > 0)
                {
                    _destLat = destResults[0].Latitude;
                    _destLng = destResults[0].Longitude;
                }

                if (_destLat == 0 || _destLng == 0)
                {
                    MessageBox.Show(
                        "Не удалось определить координаты адреса назначения.\n" +
                        "Выберите адрес из подсказок или уточните его.",
                        "Ошибка геокодирования",
                        MessageBoxButton.OK,
                        MessageBoxImage.Warning);
                    return;
                }

                destinationLat = _destLat;
                destinationLng = _destLng;
            }

            var request = new CreateOperatorOrderRequest
            {
                OperatorId = _api.CurrentUser!.UserId,
                ClientPhone = ClientPhoneBox.Text.Trim(),
                ClientName = ClientNameBox.Text.Trim(),
                PickupAddress = PickupAddressBox.Text.Trim(),
                PickupLatitude = _pickupLat,
                PickupLongitude = _pickupLng,
                PickupEntrance = string.IsNullOrWhiteSpace(EntranceBox.Text) ? null : EntranceBox.Text.Trim(),
                DestinationAddress = destinationAddress,
                DestinationLatitude = destinationLat,
                DestinationLongitude = destinationLng,
                DestinationEntrance = string.IsNullOrWhiteSpace(DestEntranceBox.Text)
                    ? null
                    : DestEntranceBox.Text.Trim(),
                Tariff = tariff,
                Comment = string.IsNullOrWhiteSpace(CommentBox.Text)
                    ? null
                    : CommentBox.Text.Trim(),
                PassengerCount = PassengersCombo.SelectedIndex + 1,
                IntermediatePoints = _stops.ToList(),
                RoundTrip = RoundTripCheck.IsChecked == true,
                ScheduledAt = GetScheduledAt()
            };

            var order = await _api.CreateOrderAsync(request);

            if (order != null)
            {
                System.Media.SystemSounds.Asterisk.Play();

                var stopsInfo = _stops.Count > 0 ? $"\nПромежуточных точек: {_stops.Count}" : "";
                MessageBox.Show(
                    $"Заказ {order.OrderNumber} создан!\n" +
                    $"Стоимость по тарифу: {order.EstimatedPrice:F0} ₽{stopsInfo}\n" +
                    $"Статус: {order.StatusText}",
                    "Успех",
                    MessageBoxButton.OK,
                    MessageBoxImage.Information);

                ClearForm();
                await RefreshAsync();
            }
        }
        catch (Exception ex)
        {
            MessageBox.Show($"Ошибка: {ex.Message}",
                "Ошибка", MessageBoxButton.OK, MessageBoxImage.Error);
        }
        finally
        {
            CreateOrderBtn.IsEnabled = true;
            CreateOrderBtn.Content = "  Создать заказ";
        }
    }

    // ===== Отмена заказа =====
    private async void OnCancelOrderClick(object sender, RoutedEventArgs e)
    {
        if (_selectedOrder == null) return;

        var result = MessageBox.Show(
            $"Отменить заказ {_selectedOrder.OrderNumber}?",
            "Подтверждение", MessageBoxButton.YesNo, MessageBoxImage.Question);

        if (result != MessageBoxResult.Yes) return;

        var reason = "Отменён оператором";
        var ok = await _api.CancelOrderAsync(_selectedOrder.Id, reason);

        if (ok)
        {
            MessageBox.Show("Заказ отменён", "Готово",
                MessageBoxButton.OK, MessageBoxImage.Information);
            await RefreshAsync();
        }
    }

    private async void OnPickupChanged(object sender,
        System.Windows.Controls.TextChangedEventArgs e)
    {
        if (_suppressPickupChange) return;

        var text = PickupAddressBox.Text;
        if (string.IsNullOrWhiteSpace(text) || text.Length < 3)
        {
            PickupSuggestionsList.Visibility = Visibility.Collapsed;
            return;
        }

        try
        {
            _pickupSuggestions = await _dadata.SearchAsync(text);
            PickupSuggestionsList.ItemsSource =
                _pickupSuggestions.Select(s => s.DisplayName).ToList();
            PickupSuggestionsList.Visibility =
                _pickupSuggestions.Count > 0 ? Visibility.Visible : Visibility.Collapsed;
        }
        catch
        {
            PickupSuggestionsList.Visibility = Visibility.Collapsed;
        }
    }

    private void OnPickupSuggestionSelected(object sender,
        System.Windows.Controls.SelectionChangedEventArgs e)
    {
        if (PickupSuggestionsList.SelectedIndex < 0 ||
            PickupSuggestionsList.SelectedIndex >= _pickupSuggestions.Count)
            return;

        var selected = _pickupSuggestions[PickupSuggestionsList.SelectedIndex];
        _suppressPickupChange = true;
        PickupAddressBox.Text = selected.DisplayName;
        _pickupLat = selected.Latitude;
        _pickupLng = selected.Longitude;
        _suppressPickupChange = false;
        PickupSuggestionsList.Visibility = Visibility.Collapsed;
        _ = UpdatePriceAsync();
    }

    private async void OnDestChanged(object sender,
        System.Windows.Controls.TextChangedEventArgs e)
    {
        if (_suppressDestChange) return;

        var text = DestinationBox.Text;
        if (string.IsNullOrWhiteSpace(text) || text.Length < 3)
        {
            DestSuggestionsList.Visibility = Visibility.Collapsed;
            return;
        }

        try
        {
            _destSuggestions = await _dadata.SearchAsync(text);
            DestSuggestionsList.ItemsSource =
                _destSuggestions.Select(s => s.DisplayName).ToList();
            DestSuggestionsList.Visibility =
                _destSuggestions.Count > 0 ? Visibility.Visible : Visibility.Collapsed;
        }
        catch
        {
            DestSuggestionsList.Visibility = Visibility.Collapsed;
        }
    }

    private void OnDestSuggestionSelected(object sender,
        System.Windows.Controls.SelectionChangedEventArgs e)
    {
        if (DestSuggestionsList.SelectedIndex < 0 ||
            DestSuggestionsList.SelectedIndex >= _destSuggestions.Count)
            return;

        var selected = _destSuggestions[DestSuggestionsList.SelectedIndex];
        _suppressDestChange = true;
        DestinationBox.Text = selected.DisplayName;
        _destLat = selected.Latitude;
        _destLng = selected.Longitude;
        _suppressDestChange = false;
        DestSuggestionsList.Visibility = Visibility.Collapsed;
        _ = UpdatePriceAsync();
    }

    private void OnClearFormClick(object sender, RoutedEventArgs e) => ClearForm();


    private async Task LoadDriverBalanceAsync(OrderResponse order)
    {
        try
        {
            if (order.Driver == null)
            {
                DetailDriverBalance.Text = "";
                TopUpBalanceBtn.IsEnabled = false;
                return;
            }

            var balance = await _api.GetBalanceAsync(order.Driver.DriverId);
            if (balance == null)
            {
                DetailDriverBalance.Text = "";
                TopUpBalanceBtn.IsEnabled = false;
                return;
            }

            DetailDriverBalance.Text = $"{balance.Balance:F0} ";

            if (balance.HasSufficientBalance)
                DetailDriverBalance.Foreground = System.Windows.Media.Brushes.LightGreen;
            else
                DetailDriverBalance.Foreground = System.Windows.Media.Brushes.OrangeRed;

            TopUpBalanceBtn.IsEnabled = true;
        }
        catch
        {
            DetailDriverBalance.Text = "Ошибка";
            TopUpBalanceBtn.IsEnabled = false;
        }
    }

    private async void OnTopUpBalanceClick(object sender, RoutedEventArgs e)
    {
        try
        {
            if (_selectedOrder == null || _selectedOrder.Driver == null)
            {
                MessageBox.Show("Выберите заказ с назначенным водителем.",
                    "Ошибка", MessageBoxButton.OK, MessageBoxImage.Warning);
                return;
            }

            if (!decimal.TryParse(TopUpAmountBox.Text, out var amount) || amount <= 0)
            {
                MessageBox.Show("Введите корректную сумму пополнения.",
                    "Ошибка", MessageBoxButton.OK, MessageBoxImage.Warning);
                return;
            }

            TopUpBalanceBtn.IsEnabled = false;
            TopUpBalanceBtn.Content = "";

            var newBalance = await _api.TopUpBalanceAsync(_selectedOrder.Driver.DriverId, amount);

            MessageBox.Show(
                $"Баланс успешно пополнен.\nНовый баланс: {newBalance:F0} ",
                "Успех", MessageBoxButton.OK, MessageBoxImage.Information);

            await LoadDriverBalanceAsync(_selectedOrder);
            await LoadDriverBalanceHistoryAsync(_selectedOrder);
        }
        catch (Exception ex)
        {
            MessageBox.Show($"Ошибка пополнения: {ex.Message}",
                "Ошибка", MessageBoxButton.OK, MessageBoxImage.Error);
        }
        finally
        {
            TopUpBalanceBtn.IsEnabled = true;
            TopUpBalanceBtn.Content = " Пополнить";
        }
    }

    private async Task LoadDriverBalanceHistoryAsync(OrderResponse order)
    {
        try
        {
            if (order.Driver == null)
            {
                BalanceHistoryList.ItemsSource = null;
                return;
            }

            var history = await _api.GetBalanceHistoryAsync(order.Driver.DriverId);

            var items = history.Select(h =>
            {
                var amountText = h.Amount >= 0
                    ? $"+{h.Amount:F0} "
                    : $"{h.Amount:F0} ";

                var typeText = h.Type switch
                {
                    "TopUp" => "Пополнение",
                    "Commission" => "Комиссия",
                    "Refund" => "Возврат",
                    "Bonus" => "Бонус",
                    _ => ""
                };

                return $"{h.TimeText} | {amountText} | {typeText} | {h.Description} | Баланс: {h.BalanceAfterText}";
            }).ToList();

            BalanceHistoryList.ItemsSource = items;
        }
        catch
        {
            BalanceHistoryList.ItemsSource = null;
        }
    }

    private async void OnForceAssignClick(object sender, RoutedEventArgs e)
    {
        if (_selectedOrder == null)
        {
            MessageBox.Show("Выберите заказ в таблице", "Ошибка",
                MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        try
        {
            var drivers = await _api.GetOnlineDriversAsync();
            if (drivers == null || drivers.Count == 0)
            {
                MessageBox.Show("Нет водителей онлайн", "Ошибка",
                    MessageBoxButton.OK, MessageBoxImage.Warning);
                return;
            }

            var driverList = drivers.Select(d =>
                $"{d.FullName} | {d.CarBrand} {d.CarModel} ({d.LicensePlate}) | {d.Status}").ToList();

            var dialog = new DriverSelectWindow(driverList);
            if (dialog.ShowDialog() != true) return;

            var selectedDriver = drivers[dialog.SelectedIndex];
            var result = await _api.ForceAssignDriverAsync(
                _selectedOrder.Id, selectedDriver.Id);

            if (result != null)
            {
                System.Media.SystemSounds.Asterisk.Play();
                MessageBox.Show(
                    $"Водитель {selectedDriver.FullName} назначен на заказ {_selectedOrder.OrderNumber}",
                    "Успех", MessageBoxButton.OK, MessageBoxImage.Information);
                await RefreshAsync();
            }
        }
        catch (Exception ex)
        {
            MessageBox.Show($"Ошибка назначения: {ex.Message}",
                "Ошибка", MessageBoxButton.OK, MessageBoxImage.Error);
        }
    }

    private async void OnChargePenaltyClick(object sender, RoutedEventArgs e)
    {
        if (_selectedOrder == null || _selectedOrder.Driver == null)
        {
            MessageBox.Show("Выберите заказ с назначенным водителем",
                "Ошибка", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        if (!decimal.TryParse(PenaltyAmountBox.Text, out var penalty) || penalty <= 0)
        {
            MessageBox.Show("Введите корректную сумму штрафа",
                "Ошибка", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        var confirm = MessageBox.Show(
            $"Списать штраф {penalty:F0}  с водителя {_selectedOrder.Driver.FullName}?",
            "Подтверждение", MessageBoxButton.YesNo, MessageBoxImage.Question);

        if (confirm != MessageBoxResult.Yes) return;

        try
        {
            var newBalance = await _api.TopUpBalanceAsync(
                _selectedOrder.Driver.DriverId, -penalty);

            MessageBox.Show(
                $"Штраф {penalty:F0}  списан.\nБаланс водителя: {newBalance:F0} ",
                "Готово", MessageBoxButton.OK, MessageBoxImage.Information);

            await LoadDriverBalanceAsync(_selectedOrder);
            await LoadDriverBalanceHistoryAsync(_selectedOrder);
        }
        catch (Exception ex)
        {
            MessageBox.Show($"Ошибка: {ex.Message}",
                "Ошибка", MessageBoxButton.OK, MessageBoxImage.Error);
        }
    }

    private void OnPenaltyPresetChanged(object sender, System.Windows.Controls.SelectionChangedEventArgs e)
    {
        try
        {
            if (PenaltyPresetCombo.SelectedItem is System.Windows.Controls.ComboBoxItem item)
            {
                var text = item.Content?.ToString() ?? "";
                if (text.StartsWith("50")) PenaltyAmountBox.Text = "50";
                else if (text.StartsWith("100")) PenaltyAmountBox.Text = "100";
                else if (text.StartsWith("200")) PenaltyAmountBox.Text = "200";
                else if (text.Contains("Своя")) PenaltyAmountBox.Text = "";
            }
        }
        catch { }
    }

    private async void OnForceAssignAvailableClick(object sender, RoutedEventArgs e)
    {
        if (_selectedOrder == null)
        {
            MessageBox.Show("Выберите заказ в таблице.",
                "Ошибка", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        try
        {
            var drivers = await _api.GetOnlineDriversAsync();

            var availableDrivers = drivers
                .Where(d => string.Equals(d.Status, "Available", StringComparison.OrdinalIgnoreCase))
                .ToList();

            if (availableDrivers.Count == 0)
            {
                MessageBox.Show("Нет свободных водителей для назначения.",
                    "Нет водителей", MessageBoxButton.OK, MessageBoxImage.Information);
                return;
            }

            var driverList = availableDrivers
                .Select(d => $"{d.FullName} | {d.CarBrand} {d.CarModel} ({d.LicensePlate})")
                .ToList();

            var dialog = new DriverSelectWindow(driverList);
            dialog.Owner = this;

            if (dialog.ShowDialog() != true)
                return;

            if (dialog.SelectedIndex < 0 || dialog.SelectedIndex >= availableDrivers.Count)
                return;

            var selectedDriver = availableDrivers[dialog.SelectedIndex];

            var result = await _api.ForceAssignDriverAsync(_selectedOrder.Id, selectedDriver.Id);

            if (result != null)
            {
                System.Media.SystemSounds.Asterisk.Play();
                MessageBox.Show(
                    $"Водитель {selectedDriver.FullName} назначен на заказ {_selectedOrder.OrderNumber}",
                    "Успех", MessageBoxButton.OK, MessageBoxImage.Information);

                await RefreshAsync();
            }
        }
        catch (Exception ex)
        {
            MessageBox.Show($"Ошибка назначения: {ex.Message}",
                "Ошибка", MessageBoxButton.OK, MessageBoxImage.Error);
        }
    }

    private async void OnChargePenaltyPresetClick(object sender, RoutedEventArgs e)
    {
        if (_selectedOrder == null || _selectedOrder.Driver == null)
        {
            MessageBox.Show("Выберите заказ с назначенным водителем.",
                "Ошибка", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        if (!decimal.TryParse(PenaltyAmountBox.Text, out var penalty) || penalty <= 0)
        {
            MessageBox.Show("Введите корректную сумму штрафа.",
                "Ошибка", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        var confirm = MessageBox.Show(
            $"Списать штраф {penalty:F0} ₽ с водителя {_selectedOrder.Driver.FullName}?",
            "Подтверждение", MessageBoxButton.YesNo, MessageBoxImage.Question);

        if (confirm != MessageBoxResult.Yes)
            return;

        try
        {
            // Отрицательное пополнение = списание
            var newBalance = await _api.TopUpBalanceAsync(_selectedOrder.Driver.DriverId, -penalty);

            MessageBox.Show(
                $"Штраф {penalty:F0} ₽ списан.\nНовый баланс: {newBalance:F0} ₽",
                "Готово", MessageBoxButton.OK, MessageBoxImage.Information);

            await LoadDriverBalanceAsync(_selectedOrder);
            await LoadDriverBalanceHistoryAsync(_selectedOrder);
        }
        catch (Exception ex)
        {
            MessageBox.Show($"Ошибка списания штрафа: {ex.Message}",
                "Ошибка", MessageBoxButton.OK, MessageBoxImage.Error);
        }
    }
    private void ClearForm()
    {
        ClientPhoneBox.Text = "+7";
        ClientNameBox.Text = "";
        PickupAddressBox.Text = "";
        DestinationBox.Text = "";
        CommentBox.Text = "";
        TariffCombo.SelectedIndex = 0;
        PassengersCombo.SelectedIndex = 0;
        PriceText.Text = "";
        DistanceText.Text = "";

        _pickupLat = 57.1522;
        _pickupLng = 65.5272;
        _destLat = 0;
        _destLng = 0;
        EntranceBox.Text = "";
        DestEntranceBox.Text = "";
        ClientHintText.Text = "";
        StopAddressBox.Text = "";
        RoundTripCheck.IsChecked = false;
        PreorderCheck.IsChecked = false;
        PreorderPanel.Visibility = Visibility.Collapsed;
        PreorderHintText.Text = "";
        _stops.Clear();
        RefreshStopsList();
    }
}

// ViewModel для таблицы
public class OrderViewModel
{
    public OrderResponse Order { get; }

    public OrderViewModel(OrderResponse order) => Order = order;

    public string OrderNumber => Order.OrderNumber;
    public string StatusText => Order.IsPreorder && Order.ScheduledAt.HasValue
        ? $"{Order.StatusText} · на {Order.ScheduledAt.Value.ToLocalTime():dd.MM HH:mm}"
        : Order.StatusText;

    public string ClientDisplay => Order.Source == "OperatorApp"
        ? $"{Order.ClientName} ({Order.ClientPhone})"
        : Order.ClientName ?? Order.ClientPhone ?? "";

    public string PickupAddress => Order.PickupAddress.Length > 30
        ? Order.PickupAddress[..30] + "..."
        : Order.PickupAddress;

    public string? DestinationAddress => Order.DestinationAddress != null &&
        Order.DestinationAddress.Length > 30
        ? Order.DestinationAddress[..30] + "..."
        : Order.DestinationAddress;

    public string DriverDisplay => Order.Driver != null
        ? $"{Order.Driver.FullName}"
        : "";

    public string PriceDisplay => $"{Order.EstimatedPrice:F0} ";

    public string TimeDisplay
    {
        get
        {
            var diff = DateTime.UtcNow - Order.CreatedAt.UtcDateTime;
            if (diff.TotalMinutes < 60) return $"{(int)diff.TotalMinutes} мин";
            return $"{(int)diff.TotalHours} ч";
        }
    }
}
