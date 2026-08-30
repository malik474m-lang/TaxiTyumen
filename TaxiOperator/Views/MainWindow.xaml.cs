using System.Collections.ObjectModel;
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
        DetailStatus.Text = o.StatusText;
        DetailClient.Text = o.ClientName ?? "";
        DetailPhone.Text = o.ClientPhone ?? "";
        DetailPickup.Text = o.PickupAddress
                + (string.IsNullOrWhiteSpace(o.PickupEntrance) ? "" : ", подъезд " + o.PickupEntrance);
        DetailDest.Text = o.DestinationAddress ?? "не указано";
        DetailDriver.Text = o.Driver != null ? o.Driver.FullName : "не назначен";
        DetailCar.Text = o.Driver != null
            ? (!string.IsNullOrWhiteSpace(o.Driver.CarDisplay)
                ? o.Driver.CarDisplay
                : $"{o.Driver.CarColor} {o.Driver.CarBrand} {o.Driver.CarModel} ({o.Driver.LicensePlate})")
            : "";
        DetailTariff.Text = o.TariffName;
        DetailPrice.Text = $"{o.EstimatedPrice:F0} ";
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
    private async void OnCreateOrderClick(object sender, RoutedEventArgs e)
    {
        if (string.IsNullOrWhiteSpace(ClientPhoneBox.Text) ||
            string.IsNullOrWhiteSpace(PickupAddressBox.Text))
        {
            MessageBox.Show("Заполните телефон и адрес подачи!",
                "Ошибка", MessageBoxButton.OK, MessageBoxImage.Warning);
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
                Tariff = tariff,
                Comment = string.IsNullOrWhiteSpace(CommentBox.Text)
                    ? null
                    : CommentBox.Text.Trim(),
                PassengerCount = PassengersCombo.SelectedIndex + 1
            };

            var order = await _api.CreateOrderAsync(request);

            if (order != null)
            {
                System.Media.SystemSounds.Asterisk.Play();

                MessageBox.Show(
                    $"Заказ {order.OrderNumber} создан!\n" +
                    $"Стоимость: {order.EstimatedPrice:F0} \n" +
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
    }
}

// ViewModel для таблицы
public class OrderViewModel
{
    public OrderResponse Order { get; }

    public OrderViewModel(OrderResponse order) => Order = order;

    public string OrderNumber => Order.OrderNumber;
    public string StatusText => Order.StatusText;

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
            var diff = DateTime.UtcNow - Order.CreatedAt;
            if (diff.TotalMinutes < 60) return $"{(int)diff.TotalMinutes} мин";
            return $"{(int)diff.TotalHours} ч";
        }
    }
}
