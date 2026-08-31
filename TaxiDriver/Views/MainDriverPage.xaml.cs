using TaxiDriver.Models;
using TaxiDriver.Services;

namespace TaxiDriver.Views;

public partial class MainDriverPage : ContentPage
{
    private readonly ApiService _api;
    private readonly SignalRService _signalR;
    private readonly LocationService _location;
    private readonly AuthResponse _auth;

    private bool _isOnline = false;
    private OrderResponse? _activeOrder;
    private int _orderStatusStep = 0;
    private bool _hasBalance = true;

    private readonly string[] _statusSteps =
        { "DriverEnRoute", "DriverArrived", "InProgress", "Completed" };
    private readonly string[] _statusLabels =
        { "Еду к клиенту", "На месте", "Начать поездку", "Завершить" };
    private readonly string[] _statusColors =
        { "#2196F3", "#FF9800", "#4CAF50", "#9C27B0" };

    private string _sortMode = "nearby";
    private List<OrderResponse> _currentOrders = new();
    private double _searchRadiusKm = 2.0;
    private bool _radiusEnabled = false;
    private bool _balanceHidden = false;
    private bool _earningsHidden = true;
    private int _previousOrderCount = 0;

    private IDispatcherTimer? _ordersRefreshTimer;

    public MainDriverPage(
        ApiService api,
        SignalRService signalR,
        LocationService location,
        AuthResponse auth)
    {
        InitializeComponent();

        _api = api;
        _signalR = signalR;
        _location = location;
        _auth = auth;

        DriverNameLabel.Text = $"{auth.FirstName} {auth.LastName}";
        StatusLabel.Text = "Не в сети";

        _signalR.NewOrderReceived += OnNewOrderFromSignalR;
        _signalR.ForceAssignedReceived += OnForceAssigned;
        _signalR.ChatMessageReceived += OnChatMessageOnMainPage;
        _location.LocationUpdated += OnLocationUpdated;

        _ = LoadBalanceAsync();
        _ = LoadBalanceHistoryAsync();
        _ = LoadDriverStatsAsync();

        InitOrdersRefreshTimer();
        _ = LoadSosAlertsAsync();
        UpdateSortButtons();
    }

    // ==========================
    // ТАЙМЕР ОБНОВЛЕНИЯ ЗАЯВОК
    // ==========================
    private void InitOrdersRefreshTimer()
    {
        try
        {
            _ordersRefreshTimer = Application.Current!.Dispatcher.CreateTimer();
            _ordersRefreshTimer.Interval = TimeSpan.FromSeconds(5);
            _ordersRefreshTimer.Tick += async (s, e) =>
            {
                try
                {
                    if (_isOnline && _activeOrder == null)
                        await LoadAvailableOrdersAsync();
                    await LoadSosAlertsAsync();   // чужие тревоги — тем же тиком (5 с)
                }
                catch { }
            };
        }
        catch { }
    }

    private void StartOrdersRefreshTimer()
    {
        try
        {
            _ordersRefreshTimer?.Start();
        }
        catch { }
    }

    private void StopOrdersRefreshTimer()
    {
        try
        {
            _ordersRefreshTimer?.Stop();
        }
        catch { }
    }

    // ==========================
    // БАЛАНС
    // ==========================

    private async Task LoadDriverStatsAsync()
    {
        try
        {
            if (_auth.DriverId == null) return;

            var driver = await _api.GetDriverInfoAsync(_auth.DriverId.Value);
            if (driver == null) return;

            MainThread.BeginInvokeOnMainThread(() =>
            {
                TodayTripsLabel.Text = driver.CompletedTrips.ToString();
                RatingLabel.Text = driver.Rating.ToString("F1") + " ";

                if (_earningsHidden)
                    TodayEarningsLabel.Text = "***";
                else
                    TodayEarningsLabel.Text = driver.TotalEarnings.ToString("F0") + " ₽";
            });
        }
        catch { }
    }
        private async Task LoadBalanceAsync()
    {
        try
        {
            if (_auth.DriverId == null) return;

            var info = await _api.GetBalanceAsync(_auth.DriverId.Value);
            if (info == null) return;

            _hasBalance = info.HasSufficientBalance;

            MainThread.BeginInvokeOnMainThread(() =>
            {
                BalanceLabel.Text = info.Balance.ToString("F0") + " ₽";

                if (info.HasSufficientBalance)
                {
                    BalanceLabel.TextColor = Color.FromArgb("#FFD700");
                    BalanceToggleBtn.Text = " Баланс";
                    
                    LowBalanceWarning.IsVisible = false;

                    if (!_isOnline)
                    {
                        OnlineBorder.BackgroundColor = Color.FromArgb("#333");
                        OnlineBorder.Opacity = 1.0;
                    }
                }
                else
                {
                    BalanceLabel.TextColor = Colors.Red;
                    BalanceToggleBtn.Text = " Мало!";
                    
                    LowBalanceText.Text =
                        $" Баланс: {info.Balance:F0} ₽  недостаточно. Обратитесь к оператору.";
                    LowBalanceWarning.IsVisible = true;

                    if (!_isOnline)
                    {
                        OnlineBorder.BackgroundColor = Color.FromArgb("#555");
                        OnlineBorder.Opacity = 0.5;
                    }
                }
            });
        }
        catch { }
    }

    private async Task LoadBalanceHistoryAsync()
    {
        try
        {
            if (_auth.DriverId == null) return;

            var history = await _api.GetBalanceHistoryAsync(_auth.DriverId.Value);

            MainThread.BeginInvokeOnMainThread(() =>
            {
                BalanceHistoryList.Children.Clear();

                if (history == null || history.Count == 0)
                {
                    BalanceHistoryEmpty.Text = "История пуста";
                    BalanceHistoryEmpty.IsVisible = true;
                    return;
                }

                BalanceHistoryEmpty.IsVisible = false;

                foreach (var item in history.Take(10))
                {
                    var isPositive = item.Amount >= 0;
                    var typeText = item.Type switch
                    {
                        "TopUp" => "Пополнение",
                        "Commission" => "Комиссия",
                        "Refund" => "Возврат",
                        "Bonus" => "Бонус",
                        _ => ""
                    };

                    var row = new Grid
                    {
                        ColumnDefinitions =
                        {
                            new ColumnDefinition { Width = GridLength.Auto },
                            new ColumnDefinition { Width = GridLength.Star },
                            new ColumnDefinition { Width = GridLength.Auto }
                        },
                        Padding = new Thickness(0, 4)
                    };

                    var amountLabel = new Label
                    {
                        Text = (isPositive
                            ? "+" + item.Amount.ToString("F0") + " ₽"
                            : item.Amount.ToString("F0") + " ₽")
                            + (string.IsNullOrEmpty(typeText) ? "" : "  " + typeText),
                        TextColor = isPositive
                            ? Color.FromArgb("#4CAF50")
                            : Color.FromArgb("#FF6B6B"),
                        FontSize = 14,
                        FontAttributes = FontAttributes.Bold,
                        VerticalOptions = LayoutOptions.Center
                    };

                    var descLabel = new Label
                    {
                        Text = item.Description,
                        TextColor = Colors.LightGray,
                        FontSize = 11,
                        LineBreakMode = LineBreakMode.TailTruncation,
                        Margin = new Thickness(8, 0, 8, 0),
                        VerticalOptions = LayoutOptions.Center
                    };

                    var timeLabel = new Label
                    {
                        Text = item.CreatedAt.ToLocalTime().ToString("dd.MM HH:mm"),
                        TextColor = Colors.Gray,
                        FontSize = 11,
                        VerticalOptions = LayoutOptions.Center
                    };

                    row.Children.Add(amountLabel);
                    Grid.SetColumn(descLabel, 1);
                    row.Children.Add(descLabel);
                    Grid.SetColumn(timeLabel, 2);
                    row.Children.Add(timeLabel);

                    BalanceHistoryList.Children.Add(row);
                    BalanceHistoryList.Children.Add(new BoxView
                    {
                        HeightRequest = 1,
                        Color = Color.FromArgb("#333")
                    });
                }
            });
        }
        catch
        {
            MainThread.BeginInvokeOnMainThread(() =>
            {
                BalanceHistoryList.Children.Clear();
                BalanceHistoryEmpty.Text = "Ошибка загрузки";
                BalanceHistoryEmpty.IsVisible = true;
            });
        }
    }

    private async void OnRefreshBalance(object? sender, EventArgs e)
    {
        await LoadBalanceAsync();
        await LoadBalanceHistoryAsync();
        await LoadAvailableOrdersAsync();
    }

    // ==========================
    // ОНЛАЙН / ОФЛАЙН
    // ==========================
    private async void OnToggleOnline(object? sender, TappedEventArgs e)
    {
        if (!_isOnline && !_hasBalance)
        {
            await DisplayAlert(
                "Недостаточно средств",
                "Пополните баланс у оператора для выхода на линию.",
                "OK");
            return;
        }

        _isOnline = !_isOnline;

        if (_isOnline)
        {
            await LoadBalanceAsync();

            if (!_hasBalance)
            {
                _isOnline = false;
                return;
            }

            OnlineBorder.BackgroundColor = Color.FromArgb("#1A4A1A");
            OnlineBorder.Opacity = 1.0;
            OnlineLabel.Text = " В сети";
            StatusLabel.Text = "Ожидаю заказы";
            LowBalanceWarning.IsVisible = false;

            if (!await _location.StartTrackingAsync())
                StatusLabel.Text = "Ожидаю заказы · без доступа к геолокации заказы недоступны";

            await _api.SetOnlineAsync(_auth.DriverId!.Value, true);

            // Сразу грузим заявки без нажатия кнопки
            await LoadAvailableOrdersAsync();

            // Через секунду ещё раз обновим  на случай задержки на сервере
            _ = Task.Run(async () =>
            {
                await Task.Delay(1000);
                await MainThread.InvokeOnMainThreadAsync(async () =>
                {
                    if (_isOnline && _activeOrder == null)
                        await LoadAvailableOrdersAsync();
                });
            });

            StartOrdersRefreshTimer();
        }
        else
        {
            OnlineBorder.BackgroundColor = Color.FromArgb("#333");
            OnlineBorder.Opacity = 1.0;
            OnlineLabel.Text = " Не в сети";
            StatusLabel.Text = "Не в сети";

            _location.StopTracking();
            await _api.SetOnlineAsync(_auth.DriverId!.Value, false);

            StopOrdersRefreshTimer();
            ClearOrdersList();
        }
    }

    // ==========================
    // ЗАКАЗЫ
    // ==========================
    private async Task LoadAvailableOrdersAsync()
    {
        if (!_isOnline || _auth.DriverId == null) return;

        try
        {
            var orders = await _api.GetAvailableOrdersAsync(
                _auth.DriverId.Value,
                _location.CurrentLat,
                _location.CurrentLng);

            MainThread.BeginInvokeOnMainThread(() =>
            {
                OrdersHeaderLabel.Text = "Доступные заказы";
                RenderOrders(orders);
            });
        }
        catch
        {
            MainThread.BeginInvokeOnMainThread(() =>
            {
                OrdersHeaderLabel.Text = "Доступные заказы (ошибка)";
            });
        }
    }

    private void RenderOrders(List<OrderResponse> orders)
    {
        OrdersList.Children.Clear();
        _currentOrders = orders ?? new List<OrderResponse>();

        if (_currentOrders.Count == 0)
        {
            NoOrdersPanel.IsVisible = true;
            OrdersCountLabel.Text = "(0)";
            return;
        }

        List<OrderResponse> filtered;

        if (_radiusEnabled)
        {
            filtered = _currentOrders
                .Where(o => GetDistanceKm(
                    _location.CurrentLat, _location.CurrentLng,
                    o.PickupLatitude, o.PickupLongitude) <= _searchRadiusKm)
                .ToList();

            OrdersCountLabel.Text = $"({filtered.Count} из {_currentOrders.Count})";
        }
        else
        {
            filtered = _currentOrders;
            OrdersCountLabel.Text = $"({_currentOrders.Count})";
        }

        if (filtered.Count == 0)
        {
            NoOrdersPanel.IsVisible = true;
            return;
        }

        NoOrdersPanel.IsVisible = false;

        // Звук при появлении новых заказов
        if (filtered.Count > _previousOrderCount && _previousOrderCount >= 0)
        {
            PlayNewOrderSound();
        }
        _previousOrderCount = filtered.Count;

        IEnumerable<OrderResponse> sorted = filtered;

        if (_sortMode == "nearby")
        {
            sorted = _currentOrders.OrderBy(o =>
                GetDistanceKm(_location.CurrentLat, _location.CurrentLng,
                    o.PickupLatitude, o.PickupLongitude));
        }
        else if (_sortMode == "old")
        {
            sorted = _currentOrders.OrderBy(o => o.CreatedAt);
        }
        else if (_sortMode == "expensive")
        {
            sorted = _currentOrders.OrderByDescending(o => o.EstimatedPrice);
        }

        foreach (var order in sorted)
            OrdersList.Children.Add(BuildOrderCard(order));
    }

    private Border BuildOrderCard(OrderResponse order)
    {
        var card = new Border
        {
            BackgroundColor = Color.FromArgb("#252536"),
            Stroke = Color.FromArgb("#444"),
            StrokeThickness = 1,
            Padding = new Thickness(15),
            Margin = new Thickness(0, 0, 0, 4),
            StrokeShape = new Microsoft.Maui.Controls.Shapes.RoundRectangle
            {
                CornerRadius = 12
            }
        };

        var layout = new StackLayout { Spacing = 8 };

        var header = new Grid
        {
            ColumnDefinitions =
            {
                new ColumnDefinition { Width = GridLength.Star },
                new ColumnDefinition { Width = GridLength.Auto }
            }
        };

        header.Children.Add(new Label
        {
            Text = order.OrderNumber,
            TextColor = Color.FromArgb("#FFD700"),
            FontSize = 14,
            FontAttributes = FontAttributes.Bold
        });

        var tariffLbl = new Label
        {
            Text = order.TariffName,
            TextColor = Color.FromArgb("#2196F3"),
            FontSize = 12,
            VerticalOptions = LayoutOptions.Center
        };
        Grid.SetColumn(tariffLbl, 1);
        header.Children.Add(tariffLbl);

        layout.Children.Add(header);

        var waitTime = DateTime.UtcNow - order.CreatedAt.UtcDateTime;
        var waitText = waitTime.TotalMinutes < 1
            ? "только что"
            : waitTime.TotalMinutes < 60
                ? $"{(int)waitTime.TotalMinutes} мин назад"
                : $"{(int)waitTime.TotalHours} ч {(int)(waitTime.TotalMinutes % 60)} мин назад";

        var waitColor = waitTime.TotalMinutes < 3
            ? "#4CAF50"
            : waitTime.TotalMinutes < 10
                ? "#FF9800"
                : "#F44336";

        var distanceToDriver = GetDistanceKm(
            _location.CurrentLat, _location.CurrentLng,
            order.PickupLatitude, order.PickupLongitude);

        var timeRow = new Grid
        {
            ColumnDefinitions =
            {
                new ColumnDefinition { Width = GridLength.Star },
                new ColumnDefinition { Width = GridLength.Auto }
            }
        };
        timeRow.Children.Add(new Label
        {
            Text = " Заявка",
            TextColor = Color.FromArgb("#888"),
            FontSize = 11
        });

        var waitLabel = new Label
        {
            Text = " " + waitText,
            TextColor = Color.FromArgb(waitColor),
            FontSize = 12,
            FontAttributes = FontAttributes.Bold
        };
        Grid.SetColumn(waitLabel, 1);
        timeRow.Children.Add(waitLabel);

        layout.Children.Add(timeRow);

        layout.Children.Add(new BoxView
        {
            HeightRequest = 1,
            Color = Color.FromArgb("#333")
        });

        var pickupText = " " + order.PickupAddress;
        if (!string.IsNullOrWhiteSpace(order.PickupEntrance))
            pickupText += ", подъезд " + order.PickupEntrance;

        layout.Children.Add(new Label
        {
            Text = pickupText,
            TextColor = Colors.White,
            FontSize = 14
        });

        if (!string.IsNullOrEmpty(order.DestinationAddress))
        {
            layout.Children.Add(new Label
            {
                Text = " " + order.DestinationAddress,
                TextColor = Color.FromArgb("#AAAAAA"),
                FontSize = 13
            });
        }

        var priceGrid = new Grid
        {
            ColumnDefinitions =
            {
                new ColumnDefinition { Width = GridLength.Star },
                new ColumnDefinition { Width = GridLength.Auto }
            }
        };
        priceGrid.Children.Add(new Label
        {
            Text = order.EstimatedPrice.ToString("F0") + " ₽",
            TextColor = Color.FromArgb("#4CAF50"),
            FontSize = 20,
            FontAttributes = FontAttributes.Bold
        });

        var distLbl = new Label
        {
            Text = distanceToDriver.ToString("F1") + " км до клиента  " + (order.EstimatedDuration?.ToString() ?? "") + " мин",
            TextColor = Color.FromArgb("#888"),
            FontSize = 12,
            VerticalOptions = LayoutOptions.Center
        };
        Grid.SetColumn(distLbl, 1);
        priceGrid.Children.Add(distLbl);

        layout.Children.Add(priceGrid);

        if (!string.IsNullOrEmpty(order.Comment))
        {
            layout.Children.Add(new Label
            {
                Text = " " + order.Comment,
                TextColor = Color.FromArgb("#888"),
                FontSize = 12
            });
        }

        var btnGrid = new Grid
        {
            ColumnDefinitions =
            {
                new ColumnDefinition { Width = GridLength.Star },
                new ColumnDefinition { Width = GridLength.Star }
            }
        };

        var capturedOrder = order;

        var acceptBtn = new Button
        {
            Text = " Принять",
            BackgroundColor = Color.FromArgb("#4CAF50"),
            TextColor = Colors.White,
            CornerRadius = 8,
            Margin = new Thickness(0, 0, 4, 0),
            HeightRequest = 42
        };
        acceptBtn.Clicked += async (s, e) => await OnAcceptOrder(capturedOrder);

        var rejectBtn = new Button
        {
            Text = " Отказать",
            BackgroundColor = Color.FromArgb("#555"),
            TextColor = Colors.White,
            CornerRadius = 8,
            Margin = new Thickness(4, 0, 0, 0),
            HeightRequest = 42
        };
        rejectBtn.Clicked += async (s, e) => await OnRejectOrder(capturedOrder);

        btnGrid.Children.Add(acceptBtn);
        Grid.SetColumn(rejectBtn, 1);
        btnGrid.Children.Add(rejectBtn);

        layout.Children.Add(btnGrid);

        card.Content = layout;
        return card;
    }

    private async Task OnAcceptOrder(OrderResponse order)
    {
        try
        {
            var accepted = await _api.AcceptOrderAsync(order.Id, _auth.DriverId!.Value);
            if (accepted != null)
            {
                _activeOrder = order;
                _location.ActiveOrderId = order.Id;
                _orderStatusStep = 0;

                await _signalR.SubscribeToOrderAsync(order.Id.ToString());

                MainThread.BeginInvokeOnMainThread(() =>
                {
                    ShowActiveOrder(order);
                    StatusLabel.Text = "Еду к клиенту";
                });

                StopOrdersRefreshTimer();
            }
        }
        catch (Exception ex)
        {
            await DisplayAlert("Ошибка", ex.Message, "OK");

            if (ex.Message.Contains("баланс", StringComparison.OrdinalIgnoreCase) ||
                ex.Message.Contains("средств", StringComparison.OrdinalIgnoreCase))
            {
                _hasBalance = false;
                await LoadBalanceAsync();
            }
        }
    }

    private async Task OnRejectOrder(OrderResponse order)
    {
        await _api.RejectOrderAsync(order.Id, _auth.DriverId!.Value, "Не подходит");
        await LoadAvailableOrdersAsync();
    }

    private void ShowActiveOrder(OrderResponse order)
    {
        ActiveOrderPanel.IsVisible = true;
        OrdersList.IsVisible = false;
        NoOrdersPanel.IsVisible = false;
        OrdersHeaderLabel.IsVisible = false;
        OrdersCountLabel.IsVisible = false;
        SortNearbyBtn.IsVisible = false;
        SortOldBtn.IsVisible = false;
        SortExpensiveBtn.IsVisible = false;

        ActiveOrderNumber.Text = order.OrderNumber;
        ActivePickupLabel.Text = order.PickupAddress
            + (string.IsNullOrWhiteSpace(order.PickupEntrance) ? "" : ", подъезд " + order.PickupEntrance);
        ActiveDestLabel.Text = order.DestinationAddress ?? "не указано";
        ActivePriceLabel.Text = order.EstimatedPrice.ToString("F0") + " ₽";
        ActiveTariffLabel.Text = order.TariffName;

        UpdateWaitingUi(order);
        if (order.WaitingActive) EnsureWaitingTimer();

        UpdateStatusButton();
        _ = ShowRouteMapAsync(order);
    }

    /// Карта маршрута в приложении: водитель → подача → (финиш)
    private async Task ShowRouteMapAsync(OrderResponse order)
    {
        try
        {
            MapContainer.IsVisible = true;

            var apiKey = await MapHtml.GetApiKeyAsync();
            // До посадки ведём к точке подачи, в поездке — к точке назначения
            var toPickup = NormStatus(order.Status) != "inprogress";
            double? toLat = toPickup ? order.PickupLatitude : order.DestinationLatitude;
            double? toLng = toPickup ? order.PickupLongitude : order.DestinationLongitude;

            var html = MapHtml.Build(
                apiKey,
                _location.CurrentLat, _location.CurrentLng,
                toLat, toLng,
                toPickup ? "Подача" : "Назначение",
                toPickup ? order.DestinationLatitude : null,
                toPickup ? order.DestinationLongitude : null);

            _lastMapHtml = html;
            RouteMap.Source = new HtmlWebViewSource { Html = html };
            if (_mapFullscreen)
                FullscreenMap.Source = new HtmlWebViewSource { Html = html };
        }
        catch
        {
            MapContainer.IsVisible = false;
        }
    }

    private bool _waitingTimerStarted;

    /// Статус заказа приходит в двух форматах: 'DriverArrived' (мобильный контракт)
    /// и 'driver_arrived' (веб). Приводим к единому нижнему регистру без подчёркиваний.
    private static string NormStatus(string? status)
        => (status ?? string.Empty).Replace("_", string.Empty).ToLowerInvariant();

    // Простой: кнопка видна после прибытия и в поездке; таймер живой (1 с)
    private void UpdateWaitingUi(OrderResponse order)
    {
        var st = NormStatus(order.Status);
        var canWait = st is "driverarrived" or "inprogress";
        WaitingBtn.IsVisible = canWait;
        if (!canWait)
        {
            WaitingLabel.Text = "";
            return;
        }
        WaitingBtn.Text = order.WaitingActive ? "Стоп простой" : "Простой";
        WaitingBtn.BackgroundColor = Color.FromArgb(order.WaitingActive ? "#0EA5E9" : "#333");

        var total = order.WaitingSeconds;
        if (order.WaitingActive && order.WaitingStartedAt.HasValue)
        {
            total += Math.Max(0,
                (int)(DateTimeOffset.UtcNow - order.WaitingStartedAt.Value).TotalSeconds);
        }
        var timer = total > 0 || order.WaitingActive
            ? $"{total / 60:00}:{total % 60:00}"
            : "";
        WaitingLabel.Text = timer;

        // Кнопка и таймер простоя поверх карты
        MapWaitingBtn.Text = order.WaitingActive ? "Стоп" : "Простой";
        MapWaitingBtn.BackgroundColor = Color.FromArgb(order.WaitingActive ? "#0EA5E9" : "#475569");
        MapWaitingLabel.Text = order.WaitingActive ? "Простой " + timer : timer;
        MapWaitingLabel.IsVisible = order.WaitingActive || total > 0;
        if (_mapFullscreen) SyncFullscreenButtons();
    }

    private void EnsureWaitingTimer()
    {
        if (_waitingTimerStarted) return;
        _waitingTimerStarted = true;
        Dispatcher.StartTimer(TimeSpan.FromSeconds(1), () =>
        {
            if (_activeOrder != null && _activeOrder.WaitingActive)
            {
                UpdateWaitingUi(_activeOrder);
                return true;
            }
            _waitingTimerStarted = false;
            return false;
        });
    }

    private async void OnToggleWaiting(object? sender, EventArgs e)
    {
        if (_activeOrder == null || _auth.DriverId == null) return;
        try
        {
            var start = !_activeOrder.WaitingActive;
            var (ok, fresh) = await _api.SetOrderWaitingAsync(_activeOrder.Id, _auth.DriverId.Value, start);
            if (!ok)
            {
                await DisplayAlert("Простой",
                    "Не удалось изменить простой. Он доступен после нажатия «Я на месте» и во время поездки.",
                    "OK");
                return;
            }
            // Сервер — единственный источник истины: применяем его подсчёт времени
            if (fresh != null)
            {
                _activeOrder.WaitingActive = fresh.WaitingActive;
                _activeOrder.WaitingStartedAt = fresh.WaitingStartedAt;
                _activeOrder.WaitingSeconds = fresh.WaitingSeconds;
            }
            else
            {
                _activeOrder.WaitingActive = start;
                _activeOrder.WaitingStartedAt = start ? DateTimeOffset.UtcNow : null;
            }
            UpdateWaitingUi(_activeOrder);
            if (_activeOrder.WaitingActive) EnsureWaitingTimer();
        }
        catch (Exception ex)
        {
            await DisplayAlert("Простой", "Ошибка связи: " + ex.Message, "OK");
        }
    }

    private void UpdateStatusButton()
    {
        if (_orderStatusStep >= _statusLabels.Length) return;
        StatusBtn.Text = _statusLabels[_orderStatusStep];
        StatusBtn.BackgroundColor = Color.FromArgb(_statusColors[_orderStatusStep]);
        // Дублируем текущий этап на кнопке поверх карты
        MapStatusBtn.Text = _statusLabels[_orderStatusStep];
        MapStatusBtn.BackgroundColor = Color.FromArgb(_statusColors[_orderStatusStep]);
        if (_mapFullscreen) SyncFullscreenButtons();
    }

    private async void OnStatusButtonClick(object? sender, EventArgs e)
    {
        if (_activeOrder == null || _orderStatusStep >= _statusSteps.Length) return;

        var status = _statusSteps[_orderStatusStep];

        try
        {
            if (status == "Completed")
            {
                await _api.CompleteOrderAsync(_activeOrder.Id);
                await OnOrderCompleted();
            }
            else
            {
                await _api.UpdateStatusAsync(_activeOrder.Id.ToString(), status);
                _orderStatusStep++;
                UpdateStatusButton();

                if (_orderStatusStep > 0 && _orderStatusStep <= _statusLabels.Length)
                    StatusLabel.Text = _statusLabels[_orderStatusStep - 1];
            }
        }
        catch (Exception ex)
        {
            await DisplayAlert("Ошибка", ex.Message, "OK");
        }
    }

    private async void OnCancelActiveOrder(object? sender, EventArgs e)
    {
        if (_activeOrder == null) return;

        var confirm = await DisplayAlert("Отмена", "Отменить текущий заказ?", "Да", "Нет");
        if (!confirm) return;

        await _api.CancelOrderAsync(_activeOrder.Id, _auth.DriverId!.Value, "Отменён водителем");
        await OnOrderCompleted();
    }

    private bool _mapFullscreen;
    private string? _lastMapHtml;

    /// Карта на весь экран: отдельный оверлей поверх страницы (не «окно» в списке).
    private void OnToggleMapFullscreen(object? sender, EventArgs e)
    {
        try
        {
            _mapFullscreen = !_mapFullscreen;
            FullscreenMapOverlay.IsVisible = _mapFullscreen;

            if (_mapFullscreen)
            {
                // Переносим текущую карту в полноэкранный WebView
                if (!string.IsNullOrEmpty(_lastMapHtml))
                    FullscreenMap.Source = new HtmlWebViewSource { Html = _lastMapHtml };
                SyncFullscreenButtons();
            }
        }
        catch { }
    }

    /// Кнопки полноэкранной карты повторяют состояние основных
    private void SyncFullscreenButtons()
    {
        try
        {
            FsStatusBtn.Text = MapStatusBtn.Text;
            FsStatusBtn.BackgroundColor = MapStatusBtn.BackgroundColor;
            FsWaitingBtn.Text = MapWaitingBtn.Text;
            FsWaitingBtn.BackgroundColor = MapWaitingBtn.BackgroundColor;
            FullscreenWaitingLabel.Text = MapWaitingLabel.Text;
            FullscreenWaitingLabel.IsVisible = MapWaitingLabel.IsVisible;
        }
        catch { }
    }

    /// Аппаратная кнопка «Назад» закрывает полноэкранную карту
    protected override bool OnBackButtonPressed()
    {
        if (_mapFullscreen)
        {
            OnToggleMapFullscreen(null, EventArgs.Empty);
            return true;
        }
        return base.OnBackButtonPressed();
    }

    private void HideRouteMap()
    {
        try
        {
            MapContainer.IsVisible = false;
            MapWaitingLabel.IsVisible = false;
            FullscreenMapOverlay.IsVisible = false;
            _mapFullscreen = false;
        }
        catch { }
    }

    private async Task OnOrderCompleted()
    {
        _activeOrder = null;
        HideRouteMap();
        _location.ActiveOrderId = null;
        _orderStatusStep = 0;

        await LoadBalanceAsync();
        await LoadBalanceHistoryAsync();
        await LoadDriverStatsAsync();

        MainThread.BeginInvokeOnMainThread(() =>
        {
            ActiveOrderPanel.IsVisible = false;
            OrdersList.IsVisible = true;
            OrdersHeaderLabel.IsVisible = true;
            OrdersCountLabel.IsVisible = true;
            SortNearbyBtn.IsVisible = true;
            SortOldBtn.IsVisible = true;
            SortExpensiveBtn.IsVisible = true;
            StatusLabel.Text = _hasBalance ? "Ожидаю заказы" : "Пополните баланс";
        });

                    await LoadAvailableOrdersAsync();
            await LoadDriverStatsAsync();

            if (_isOnline) StartOrdersRefreshTimer();
    }

    private void ClearOrdersList()
    {
        OrdersList.Children.Clear();
        NoOrdersPanel.IsVisible = true;
        OrdersCountLabel.Text = "(0)";
    }

    // ==========================
    // SIGNALR
    // ==========================
    private void OnNewOrderFromSignalR(NewOrderNotification notification)
    {
        if (!_isOnline || !_hasBalance) return;

        // Если водитель назначен принудительно  показываем popup
        if (_activeOrder != null) return;

        MainThread.BeginInvokeOnMainThread(async () =>
        {
            try
            {
                // Звуковое уведомление
                PlayNewOrderSound();

                // Обновляем список заявок
                await LoadAvailableOrdersAsync();
            }
            catch { }
        });
    }

    private void OnLocationUpdated(double lat, double lng)
    {
        MainThread.BeginInvokeOnMainThread(() =>
        {
            if (_isOnline)
                StatusLabel.Text = "В сети  " + lat.ToString("F4") + ", " + lng.ToString("F4");
        });
    }

    // ==========================
    // СОРТИРОВКА
    // ==========================
    private void OnSortNearby(object? sender, EventArgs e)
    {
        _sortMode = "nearby";
        UpdateSortButtons();
        RenderOrders(_currentOrders);
    }

    private void OnSortOld(object? sender, EventArgs e)
    {
        _sortMode = "old";
        UpdateSortButtons();
        RenderOrders(_currentOrders);
    }

    private void OnSortExpensive(object? sender, EventArgs e)
    {
        _sortMode = "expensive";
        UpdateSortButtons();
        RenderOrders(_currentOrders);
    }

    private void UpdateSortButtons()
    {
        SortNearbyBtn.BackgroundColor = _sortMode == "nearby" ? Color.FromArgb("#FFD700") : Color.FromArgb("#333");
        SortNearbyBtn.TextColor = _sortMode == "nearby" ? Color.FromArgb("#1E1E2E") : Colors.White;

        SortOldBtn.BackgroundColor = _sortMode == "old" ? Color.FromArgb("#FFD700") : Color.FromArgb("#333");
        SortOldBtn.TextColor = _sortMode == "old" ? Color.FromArgb("#1E1E2E") : Colors.White;

        SortExpensiveBtn.BackgroundColor = _sortMode == "expensive" ? Color.FromArgb("#FFD700") : Color.FromArgb("#333");
        SortExpensiveBtn.TextColor = _sortMode == "expensive" ? Color.FromArgb("#1E1E2E") : Colors.White;
    }

    private double GetDistanceKm(double lat1, double lng1, double lat2, double lng2)
    {
        const double R = 6371.0;
        var dLat = (lat2 - lat1) * Math.PI / 180.0;
        var dLng = (lng2 - lng1) * Math.PI / 180.0;

        var a = Math.Sin(dLat / 2) * Math.Sin(dLat / 2) +
                Math.Cos(lat1 * Math.PI / 180.0) *
                Math.Cos(lat2 * Math.PI / 180.0) *
                Math.Sin(dLng / 2) * Math.Sin(dLng / 2);

        var c = 2 * Math.Atan2(Math.Sqrt(a), Math.Sqrt(1 - a));
        return R * c;
    }

    // ==========================
    // НАВИГАТОР
    // ==========================


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

                if (senderId == _auth.UserId.ToString())
                    return;

                var senderName = senderRole == "Client" ? "Клиент" : "Водитель";

                ChatBannerText.Text = " " + text;
                ChatBannerSender.Text = senderName;
                ChatBanner.IsVisible = true;

                PlayNewOrderSound();

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

            if (_activeOrder == null || _auth.DriverId == null) return;

            await Navigation.PushAsync(new ChatPage(
                _api, _signalR,
                _activeOrder.Id,
                _auth.UserId,
                "Driver"));
        }
        catch { }
    }
        private async void OnOpenChat(object? sender, EventArgs e)
    {
        try
        {
            if (_activeOrder == null || _auth.DriverId == null) return;

            var driver = await _api.GetBalanceAsync(_auth.DriverId.Value);

            await Navigation.PushAsync(new ChatPage(
                _api, _signalR,
                _activeOrder.Id,
                _auth.UserId,
                "Driver"));
        }
        catch { }
    }
        // ── Тревожная кнопка: подтверждение → отправка координат → оповещение всех ──
    private async void OnSosClicked(object? sender, EventArgs e)
    {
        var confirmed = await DisplayAlert(
            "Тревожная кнопка",
            "Отправить сигнал SOS? Ваши координаты получат все водители автопарка и диспетчерская.",
            "Отправить SOS", "Отмена");
        if (!confirmed) return;

        try
        {
            // Свежие координаты; при недоступности GPS сервер возьмёт последнюю известную точку
            double lat = _location.CurrentLat, lng = _location.CurrentLng;
            try
            {
                var loc = await Geolocation.GetLocationAsync(
                    new GeolocationRequest(GeolocationAccuracy.Best, TimeSpan.FromSeconds(5)));
                if (loc != null) { lat = loc.Latitude; lng = loc.Longitude; }
            }
            catch { }

            var alert = await _api.RaiseSosAsync(lat, lng, _activeOrder?.Id, null);
            await DisplayAlert(
                alert != null ? "SOS отправлен" : "Ошибка",
                alert != null
                    ? "Сигнал получен диспетчерской и водителями автопарка. Оставайтесь на связи."
                    : "Не удалось отправить сигнал. Позвоните диспетчеру.",
                "OK");
            if (alert != null) await LoadSosAlertsAsync();
        }
        catch
        {
            await DisplayAlert("Ошибка", "Нет связи с сервером. Позвоните диспетчеру.", "OK");
        }
    }

    private readonly HashSet<Guid> _shownSos = new();

    // Чужие тревоги: показываем баннером-алертом один раз на каждую
    private async Task LoadSosAlertsAsync()
    {
        try
        {
            var alerts = await _api.GetSosAlertsAsync();
            foreach (var a in alerts)
            {
                if (a.DriverId == _auth.DriverId) continue;   // своя тревога
                if (!_shownSos.Add(a.Id)) continue;           // уже показывали

                var open = await DisplayAlert(
                    "🆘 SOS · " + a.DriverName,
                    $"{a.CarInfo}\n{(string.IsNullOrWhiteSpace(a.Comment) ? "" : a.Comment + "\n")}Координаты: {a.Latitude:F5}, {a.Longitude:F5}",
                    "Открыть карту", "Закрыть");
                if (open)
                {
                    try { await Launcher.OpenAsync(new Uri(a.MapUrl)); } catch { }
                }
            }
        }
        catch { }
    }

    private async void OnOpenFleetChat(object? sender, EventArgs e)
    {
        try
        {
            await Navigation.PushAsync(new FleetChatPage(_api, _auth.UserId));
        }
        catch { }
    }
        private async void OnYandexNavClicked(object? sender, EventArgs e)
    {
        await OpenNavigatorAsync("yandexnavi");
    }

    private async void OnDgisNavClicked(object? sender, EventArgs e)
    {
        await OpenNavigatorAsync("dgis");
    }

    private async void OnGoogleNavClicked(object? sender, EventArgs e)
    {
        await OpenNavigatorAsync("google");
    }

    private async Task OpenNavigatorAsync(string navigator)
    {
        if (_activeOrder == null) return;

        var lat = _activeOrder.PickupLatitude.ToString(System.Globalization.CultureInfo.InvariantCulture);
        var lng = _activeOrder.PickupLongitude.ToString(System.Globalization.CultureInfo.InvariantCulture);

        if (_orderStatusStep >= 2 &&
            _activeOrder.DestinationLatitude.HasValue &&
            _activeOrder.DestinationLongitude.HasValue)
        {
            lat = _activeOrder.DestinationLatitude.Value.ToString(System.Globalization.CultureInfo.InvariantCulture);
            lng = _activeOrder.DestinationLongitude.Value.ToString(System.Globalization.CultureInfo.InvariantCulture);
        }

        string url = navigator switch
        {
            "yandexnavi" => $"yandexnavi://build_route_on_map?lat_to={lat}&lon_to={lng}",
            "dgis" => $"dgis://2gis.ru/routeSearch/rsType/car/to/{lng},{lat}",
            "google" => $"https://www.google.com/maps/dir/?api=1&destination={lat},{lng}&travelmode=driving",
            _ => $"https://www.google.com/maps/dir/?api=1&destination={lat},{lng}"
        };

        try
        {
            await Launcher.OpenAsync(new Uri(url));
        }
        catch
        {
            var fallback = $"https://www.google.com/maps/dir/?api=1&destination={lat},{lng}&travelmode=driving";
            try { await Launcher.OpenAsync(new Uri(fallback)); } catch { }
        }
    }

    // ==========================
    // РУЧНОЕ ОБНОВЛЕНИЕ
    // ==========================


    // ==========================
    // ПРИНУДИТЕЛЬНОЕ НАЗНАЧЕНИЕ
    // ==========================
    private void OnForceAssigned(NewOrderNotification notification)
    {
        if (!_isOnline) return;

        MainThread.BeginInvokeOnMainThread(async () =>
        {
            try
            {
                // Двойной сигнал для принудительного назначения
                PlayNewOrderSound();
                await Task.Delay(150);
                PlayNewOrderSound();

                var accept = await DisplayAlert(
                    " Вам назначен заказ!",
                    "Оператор назначил вам заказ.\n" +
                    "Откуда: " + notification.PickupAddress + "\n" +
                    "Куда: " + (notification.DestinationAddress ?? "не указано") + "\n" +
                    "Сумма: " + notification.EstimatedPrice.ToString("F0") + " ₽",
                    "ОК", "Отклонить");

                if (!accept)
                {
                    await _api.RejectOrderAsync(
                        notification.OrderId,
                        _auth.DriverId!.Value,
                        "Отказ от принудительно назначенного заказа");

                    await LoadAvailableOrdersAsync();
                    return;
                }

                // Подгружаем список и сразу показываем этот заказ как активный
                await LoadAvailableOrdersAsync();

                var order = new OrderResponse
                {
                    Id = notification.OrderId,
                    OrderNumber = notification.OrderNumber,
                    PickupAddress = notification.PickupAddress,
                    DestinationAddress = notification.DestinationAddress,
                    EstimatedPrice = notification.EstimatedPrice,
                    TariffName = notification.Tariff
                };

                _activeOrder = order;
                _location.ActiveOrderId = order.Id;
                _orderStatusStep = 0;

                await _signalR.SubscribeToOrderAsync(order.Id.ToString());

                ShowActiveOrder(order);
                StatusLabel.Text = "Еду к клиенту";
                StopOrdersRefreshTimer();
            }
            catch { }
        });
    }
    // ==========================
    // ЗВУК ПРИ НОВОМ ЗАКАЗЕ
    // ==========================
    private void PlayNewOrderSound()
    {
        try
        {
            Console.Beep(900, 180);
        }
        catch { }
    }

    // ==========================
    // РАДИУС
    // ==========================

    private void OnRadiusToggled(object? sender, CheckedChangedEventArgs e)
    {
        _radiusEnabled = e.Value;

        if (_radiusEnabled)
        {
            RadiusSlider.IsEnabled = true;
            RadiusSlider.MinimumTrackColor = Color.FromArgb("#FFD700");
            RadiusSlider.ThumbColor = Color.FromArgb("#FFD700");

            if (_searchRadiusKm < 0.5)
                RadiusValueLabel.Text = (_searchRadiusKm * 1000).ToString("F0") + " м";
            else
                RadiusValueLabel.Text = _searchRadiusKm.ToString("F1") + " км";

            RadiusValueLabel.TextColor = Color.FromArgb("#FFD700");
        }
        else
        {
            RadiusSlider.IsEnabled = false;
            RadiusSlider.MinimumTrackColor = Color.FromArgb("#555");
            RadiusSlider.ThumbColor = Color.FromArgb("#888");
            RadiusValueLabel.Text = "Выкл";
            RadiusValueLabel.TextColor = Colors.Gray;
        }

        if (_currentOrders.Count > 0)
            RenderOrders(_currentOrders);
    }


    private bool _balanceHistoryExpanded = false;

    private void OnToggleBalanceHistory(object? sender, EventArgs e)
    {
        try
        {
            _balanceHistoryExpanded = !_balanceHistoryExpanded;
            BalanceHistoryContainer.IsVisible = _balanceHistoryExpanded;
            BalanceHistoryToggle.Text = _balanceHistoryExpanded ? "" : "";
        }
        catch { }
    }
    
    private async void OnToggleEarningsVisibility(object? sender, EventArgs e)
    {
        try
        {
            if (!_earningsHidden) return;

            _earningsHidden = false;
            await LoadDriverStatsAsync();
            EarningsToggleBtn.Text = " Скрыто через 10с";

            _ = Task.Run(async () =>
            {
                await Task.Delay(10000);
                _earningsHidden = true;

                MainThread.BeginInvokeOnMainThread(() =>
                {
                    TodayEarningsLabel.Text = "***";
                    EarningsToggleBtn.Text = " Заработок";
                });
            });
        }
        catch { }
    }
        private void OnToggleBalanceVisibility(object? sender, EventArgs e)
    {
        try
        {
            _balanceHidden = !_balanceHidden;

            if (_balanceHidden)
            {
                BalanceLabel.Text = "***";
                BalanceToggleBtn.Text = " Скрыто";
            }
            else
            {
                BalanceToggleBtn.Text = " Баланс";
                _ = LoadBalanceAsync();
            }
        }
        catch { }
    }
        private void OnRadiusChanged(object? sender, ValueChangedEventArgs e)
    {
        _searchRadiusKm = Math.Round(e.NewValue, 1);

        if (_searchRadiusKm < 0.5)
            RadiusValueLabel.Text = (_searchRadiusKm * 1000).ToString("F0") + " м";
        else
            RadiusValueLabel.Text = _searchRadiusKm.ToString("F1") + " км";

        if (_currentOrders.Count > 0)
            RenderOrders(_currentOrders);
    }
        private async void OnRefreshOrders(object? sender, EventArgs e)
    {
        try
        {
            StatusLabel.Text = "Обновление...";
            await LoadBalanceAsync();
            await LoadBalanceHistoryAsync();
                        await LoadAvailableOrdersAsync();
            await LoadDriverStatsAsync();

            if (_isOnline)
                StatusLabel.Text = "Ожидаю заказы";
            else
                StatusLabel.Text = "Не в сети";
        }
        catch
        {
            StatusLabel.Text = "Ошибка обновления";
        }
    }
}