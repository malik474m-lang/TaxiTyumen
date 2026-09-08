using System.Linq;
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
        { "Еду к клиенту", "На месте", "Начало движения", "Завершить" };
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

        // Кнопки плавающей панели поверх Яндекс Навигатора
        NavigatorOverlay.ActionRequested += OnOverlayAction;

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

            // Проверяем разрешение «Поверх других приложений» при выходе на линию
            if (NavigatorOverlay.IsSupported && !NavigatorOverlay.HasOverlayPermission())
            {
                var ask = await DisplayAlert(
                    "Кнопки поверх Навигатора",
                    "Чтобы кнопки управления заказом отображались поверх Яндекс Навигатора, разрешите приложению «Поверх других приложений».\n\nОткрыть настройки сейчас?",
                    "Открыть настройки", "Позже");
                if (ask) NavigatorOverlay.RequestOverlayPermission();
            }

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
                // Используем полный подтверждённый ответ сервера, а не старую карточку
                // из списка: именно здесь раньше терялись конечные/промежуточные точки.
                var fullOrder = await _api.GetOrderAsync(accepted.Id) ?? accepted;
                _activeOrder = fullOrder;
                _location.ActiveOrderId = fullOrder.Id;
                _orderStatusStep = 0;

                await _signalR.SubscribeToOrderAsync(fullOrder.Id.ToString());

                MainThread.BeginInvokeOnMainThread(() =>
                {
                    ShowActiveOrder(fullOrder);
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

        // Предзаказ помечаем временем подачи прямо в заголовке карточки.
        ActiveOrderNumber.Text = order.IsPreorder && order.ScheduledAt.HasValue
            ? $"{order.OrderNumber} · подача {order.ScheduledAt.Value.ToLocalTime():dd.MM HH:mm}"
            : order.OrderNumber;
        ActivePickupLabel.Text = order.PickupAddress
            + (string.IsNullOrWhiteSpace(order.PickupEntrance) ? "" : ", подъезд " + order.PickupEntrance);
        // Промежуточные адреса — в строгом порядке следования
        if (order.IntermediatePoints is { Count: > 0 })
        {
            ActiveStopsPanel.IsVisible = true;
            ActiveStopsLabel.Text = string.Join("\n", order.IntermediatePoints
                .OrderBy(p => p.SortOrder)
                .Select((p, i) => $"{i + 1}. {p.Address}"));
        }
        else
        {
            ActiveStopsPanel.IsVisible = false;
            ActiveStopsLabel.Text = "";
        }

        ActiveDestLabel.Text = order.DestinationAddress ?? "не указано";
        if (!string.IsNullOrWhiteSpace(order.DestinationEntrance))
            ActiveDestLabel.Text += ", подъезд " + order.DestinationEntrance;
        ActivePriceLabel.Text = order.EstimatedPrice.ToString("F0") + " ₽";
        ActiveTariffLabel.Text = order.TariffName;

        UpdateWaitingUi(order);
        var waitStatus = NormStatus(order.Status);
        if (waitStatus is "driverarrived" or "inprogress") EnsureWaitingTimer();

        UpdateStatusButton();
        // Заказ принят — сразу открываем карту Яндекс Навигатора и кнопки поверх неё
        StartNavigatorGuidance();
    }

    private bool _waitingTimerStarted;

    /// Статус заказа приходит в двух форматах: 'DriverArrived' (мобильный контракт)
    /// и 'driver_arrived' (веб). Приводим к единому нижнему регистру без подчёркиваний.
    private static string NormStatus(string? status)
        => (status ?? string.Empty).Replace("_", string.Empty).ToLowerInvariant();

    /// Простой по вашему сценарию:
    ///  • «На месте» — идёт бесплатное ожидание, кнопка «Простой» неактивна;
    ///  • бесплатное закончилось — счётчик продолжает считать платное время,
    ///    кнопка «Простой» остаётся неактивной (ожидание уже идёт);
    ///  • «Начало» — счётчик останавливается, кнопка «Простой» становится активной;
    ///  • промежуточная остановка — водитель жмёт «Простой», счётчик идёт снова;
    ///  • следующее «Начало» снова останавливает счётчик.
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

        // Накопленное платное время простоя, включая текущий незакрытый интервал.
        var total = order.WaitingSeconds;
        if (order.WaitingActive && order.WaitingStartedAt.HasValue)
        {
            total += Math.Max(0,
                (int)(DateTimeOffset.UtcNow - order.WaitingStartedAt.Value).TotalSeconds);
        }
        var paidTimer = $"{total / 60:00}:{total % 60:00}";
        var freeLeft = order.FreeWaitingLeftSeconds;

        // На точке подачи ожидание идёт автоматически (бесплатное → платное),
        // поэтому кнопка нужна только в поездке — для остановок в пути.
        var canStartWaiting = st == "inprogress" && !order.WaitingActive;
        WaitingBtn.Text = "Простой";
        WaitingBtn.IsEnabled = canStartWaiting;
        WaitingBtn.BackgroundColor = canStartWaiting
            ? Color.FromArgb("#0EA5E9")
            : Color.FromArgb("#2A3A44");

        // Счётчик виден всегда: сначала бесплатное ожидание, затем платное.
        string waitingText;
        Color waitingColor;
        if (order.WaitingActive)
        {
            waitingText = $"Платное ожидание {paidTimer}";
            waitingColor = Color.FromArgb("#F87171");
        }
        else if (freeLeft > 0 && st == "driverarrived")
        {
            waitingText = $"Бесплатное ожидание {freeLeft / 60:00}:{freeLeft % 60:00}";
            waitingColor = Color.FromArgb("#4ADE80");
        }
        else if (total > 0)
        {
            waitingText = $"Ожидание остановлено · {paidTimer}";
            waitingColor = Color.FromArgb("#9CA3AF");
        }
        else
        {
            waitingText = "";
            waitingColor = Color.FromArgb("#9CA3AF");
        }

        WaitingLabel.Text = waitingText;
        WaitingLabel.TextColor = waitingColor;


        // Кнопка этапа зависит от состояния ожидания («Продолжить»/«Завершить»).
        UpdateStatusButton();
    }

    private int _waitingSyncCounter;

    /// Секундный таймер ожидания. Работает всё время, пока водитель на месте
    /// или в поездке: сначала показывает бесплатное ожидание, затем платное.
    private void EnsureWaitingTimer()
    {
        if (_waitingTimerStarted) return;
        _waitingTimerStarted = true;
        Dispatcher.StartTimer(TimeSpan.FromSeconds(1), () =>
        {
            var order = _activeOrder;
            if (order == null)
            {
                _waitingTimerStarted = false;
                return false;
            }

            var status = NormStatus(order.Status);
            if (status is not ("driverarrived" or "inprogress"))
            {
                _waitingTimerStarted = false;
                return false;
            }

            // Локально уменьшаем остаток бесплатного времени для плавного отсчёта.
            if (!order.WaitingActive && order.FreeWaitingLeftSeconds > 0)
            {
                order.FreeWaitingLeftSeconds--;
            }

            // Раз в 5 секунд сверяемся с сервером: именно он включает платный
            // счётчик после бесплатных минут, поэтому экран не должен «застревать».
            if (++_waitingSyncCounter >= 5)
            {
                _waitingSyncCounter = 0;
                _ = SyncWaitingStateAsync();
            }

            UpdateWaitingUi(order);
            return true;
        });
    }

    /// Подтягивает актуальное состояние ожидания с сервера.
    private async Task SyncWaitingStateAsync()
    {
        try
        {
            if (_activeOrder == null || _auth.DriverId == null) return;
            var fresh = await _api.GetCurrentOrderAsync(_auth.DriverId.Value);
            if (fresh == null || fresh.Id != _activeOrder.Id) return;

            _activeOrder.WaitingActive = fresh.WaitingActive;
            _activeOrder.WaitingStartedAt = fresh.WaitingStartedAt;
            _activeOrder.WaitingSeconds = fresh.WaitingSeconds;
            _activeOrder.WaitingAutoStarted = fresh.WaitingAutoStarted;
            _activeOrder.FreeWaitingLeftSeconds = fresh.FreeWaitingLeftSeconds;
            UpdateWaitingUi(_activeOrder);
        }
        catch
        {
            // Сеть недоступна — продолжаем локальный отсчёт до следующей синхронизации.
        }
    }

    private async void OnToggleWaiting(object? sender, EventArgs e)
    {
        if (_activeOrder == null || _auth.DriverId == null) return;
        try
        {
            // Кнопка только запускает ожидание. Останавливает его кнопка «Начало».
            if (_activeOrder.WaitingActive) return;
            var (ok, serverError, fresh) = await _api.SetOrderWaitingAsync(
                _activeOrder.Id, _auth.DriverId.Value, true);
            if (!ok)
            {
                // Показываем точную причину отказа от сервера — быстрее найти проблему
                await SafeAlertAsync("Простой",
                    serverError ?? "Не удалось изменить простой. Он доступен после нажатия «Я на месте» и во время поездки.");
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
                _activeOrder.WaitingActive = true;
                _activeOrder.WaitingStartedAt = DateTimeOffset.UtcNow;
            }
            UpdateWaitingUi(_activeOrder);
            if (_activeOrder.WaitingActive) EnsureWaitingTimer();
        }
        catch (Exception ex)
        {
            await DisplayAlert("Простой", "Ошибка связи: " + ex.Message, "OK");
        }
    }

    /// Во время поездки идёт платное ожидание: кнопка этапа должна его
    /// останавливать («Продолжить»), а не завершать заказ.
    private bool IsWaitingStopStep()
        => _activeOrder is { WaitingActive: true }
           && NormStatus(_activeOrder.Status) == "inprogress";

    private void UpdateStatusButton()
    {
        if (_orderStatusStep >= _statusLabels.Length) return;

        var label = _statusLabels[_orderStatusStep];
        var color = _statusColors[_orderStatusStep];

        // Остановок в пути может быть сколько угодно: пока счётчик идёт,
        // кнопка «Завершить» временно превращается в «Продолжить».
        if (IsWaitingStopStep())
        {
            label = "Продолжить";
            color = "#4CAF50";
        }

        StatusBtn.Text = label;
        StatusBtn.BackgroundColor = Color.FromArgb(color);
        // Дублируем текущий этап на кнопке поверх карты
        // Панель поверх Навигатора и, при смене цели, сам маршрут
        UpdateOverlayState();
        RouteInNavigator();
        // Смена этапа меняет и подпись цели на плашке
        if (_activeOrder != null) NavPanel.IsVisible = true;
    }

    /// Останавливает платное ожидание, не меняя этап заказа.
    private async Task StopWaitingAsync()
    {
        if (_activeOrder == null || _auth.DriverId == null) return;
        try
        {
            var (ok, serverError, fresh) = await _api.SetOrderWaitingAsync(
                _activeOrder.Id, _auth.DriverId.Value, false);
            if (!ok)
            {
                await DisplayAlert("Ожидание",
                    serverError ?? "Не удалось остановить ожидание.", "OK");
                return;
            }

            if (fresh != null)
            {
                _activeOrder.WaitingActive = fresh.WaitingActive;
                _activeOrder.WaitingStartedAt = fresh.WaitingStartedAt;
                _activeOrder.WaitingSeconds = fresh.WaitingSeconds;
                _activeOrder.WaitingAutoStarted = fresh.WaitingAutoStarted;
                _activeOrder.FreeWaitingLeftSeconds = fresh.FreeWaitingLeftSeconds;
            }
            else
            {
                _activeOrder.WaitingActive = false;
                _activeOrder.WaitingStartedAt = null;
            }

            UpdateWaitingUi(_activeOrder);
            UpdateStatusButton();
        }
        catch (Exception ex)
        {
            await DisplayAlert("Ожидание", "Ошибка связи: " + ex.Message, "OK");
        }
    }

    private async void OnStatusButtonClick(object? sender, EventArgs e)
    {
        if (_activeOrder == null || _orderStatusStep >= _statusSteps.Length) return;

        // «Продолжить»: снимаем ожидание и остаёмся в поездке.
        // Этап не меняется, поэтому остановок может быть неограниченно много.
        if (IsWaitingStopStep())
        {
            await StopWaitingAsync();
            return;
        }

        var status = _statusSteps[_orderStatusStep];

        try
        {
            if (status == "Completed")
            {
                var finished = await _api.CompleteOrderAsync(_activeOrder.Id);
                if (finished != null)
                {
                    // Итог показываем разбивкой: поездка, простой и полная сумма.
                    var waitingLine = finished.WaitingCost > 0
                        ? $"\nПростой: {finished.WaitingCost:F0} ₽ ({finished.WaitingSeconds / 60} мин)"
                        : "\nПростой: 0 ₽";
                    await SafeAlertAsync("Поездка завершена",
                        $"По тарифу: {finished.TariffPrice:F0} ₽{waitingLine}\nИтого к оплате: {finished.TotalPrice:F0} ₽");
                }
                await OnOrderCompleted();
            }
            else
            {
                if (_auth.DriverId == null)
                {
                    await SafeAlertAsync("Ошибка", "Профиль водителя не найден. Войдите заново.");
                    return;
                }

                var (ok, serverError, fresh) = await _api.UpdateStatusAsync(
                    _activeOrder.Id, _auth.DriverId.Value, status);
                if (!ok)
                {
                    // Не меняем кнопку локально: показываем точный отказ сервера.
                    await SafeAlertAsync("Не удалось изменить этап",
                        serverError ?? "Сервер не подтвердил изменение статуса заказа.");
                    return;
                }

                // Сервер подтвердил действие — только теперь переключаем интерфейс.
                if (fresh != null)
                {
                    _activeOrder = fresh;
                    ShowActiveOrder(fresh);
                }
                _orderStatusStep++;
                UpdateStatusButton();

                if (_orderStatusStep > 0 && _orderStatusStep <= _statusLabels.Length)
                    StatusLabel.Text = _statusLabels[_orderStatusStep - 1];

                // После «Я на месте» водитель видит явное подтверждение:
                // именно этот серверный переход запускает in-app/SMS/Zvonok.
                if (string.Equals(status, "DriverArrived", StringComparison.OrdinalIgnoreCase))
                {
                    var notificationStatus = fresh?.ClientNotificationStatus ?? "unknown";
                    var shortText = notificationStatus switch
                    {
                        "sent" => "Звонок пассажиру поставлен в очередь.",
                        "already_sent" => "Оповещение пассажиру уже было запущено.",
                        "skipped" => "Оповещение пассажиру пропущено.",
                        "failed" => "Не удалось позвонить пассажиру автоматически.",
                        _ => "Сервер подтвердил прибытие."
                    };
                    NavigatorOverlay.Toast(shortText);
                    // После успешной смены этапа поднимаем приложение, чтобы панель/Навигатор
                    // не оставляли водителя без интерфейса.
                    NavigatorOverlay.BringAppToFront();
                }
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

    private async Task OnOrderCompleted()
    {
        _activeOrder = null;
        NavPanel.IsVisible = false;
        _location.ActiveOrderId = null;
        _orderStatusStep = 0;
        // Панель поверх Навигатора больше не нужна (режим остаётся включённым
        // и сработает на следующем заказе автоматически)
        NavigatorOverlay.Hide();
        _lastNavTargetKey = "";
        NavPanel.IsVisible = false;

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

    // ── Режим «Яндекс Навигатор + панель поверх» ───────────────────────────

    /// Страница видна на экране. Когда сверху Навигатор — диалоги MAUI показать
    /// нельзя, поэтому сообщения уходят системным Toast поверх карты.
    private bool _uiVisible = true;

    protected override void OnAppearing()
    {
        base.OnAppearing();
        _uiVisible = true;
        // Панель навигатора — вспомогательная функция: её сбой не должен
        // мешать открытию главного экрана после входа.
        try
        {
            // Ведение Навигатором включено всегда, пока есть активный заказ
            if (_activeOrder != null) StartNavigatorGuidance();
            UpdateOverlayState();
        }
        catch (Exception ex)
        {
            App.LogCrash("OnAppearing/overlay", ex);
        }
    }

    protected override void OnDisappearing()
    {
        _uiVisible = false;
        base.OnDisappearing();
    }

    private async Task SafeAlertAsync(string title, string message, string cancel = "OK")
    {
        if (_uiVisible)
        {
            await DisplayAlert(title, message, cancel);
            return;
        }
        NavigatorOverlay.Toast(title + ": " + message);
    }

    /// Навигация работает через УСТАНОВЛЕННЫЙ Яндекс Навигатор и его офлайн-карты.
    /// Перед запуском никаких HTTP-запросов нет: используем координаты из полного
    /// объекта заказа. Для старых заказов без координат адрес передаётся самому
    /// Навигатору через map_search — его поиск использует скачанные карты.
    private static NavigatorPoint? PointFromOrder(
        string? address, double? latitude, double? longitude)
    {
        var lat = latitude ?? 0;
        var lng = longitude ?? 0;
        if (lat == 0 || lng == 0 || IsCityCenterPlaceholder(lat, lng)) return null;
        return new NavigatorPoint
        {
            Address = address ?? string.Empty,
            Latitude = lat,
            Longitude = lng,
        };
    }

    private static bool IsCityCenterPlaceholder(double lat, double lng)
        => Math.Abs(lat - 57.1522) < 0.0005 && Math.Abs(lng - 65.5272) < 0.0005;

    /// Куда сейчас ведёт Навигатор — чтобы не перестраивать маршрут на каждый тик.
    private string _lastNavTargetKey = string.Empty;

    /// До посадки: текущее положение → подача.
    /// После начала поездки: текущее положение → промежуточные точки → назначение.
    /// Яндекс Навигатор получает официальный составной URL (lat_from/lon_from,
    /// lat_via_N/lon_via_N, lat_to/lon_to). Если какой-то точки нет координат,
    /// Навигатор ищет её по точному тексту адреса из своей офлайн-карты.
    private void RouteInNavigator(bool force = false)
    {
        if (!NavigatorOverlay.ModeEnabled || _activeOrder == null) return;

        var inProgress = NormStatus(_activeOrder.Status) == "inprogress";
        var key = inProgress
            ? $"trip:{_activeOrder.Id}:{_activeOrder.DestinationAddress}:{_activeOrder.IntermediatePoints.Count}"
            : $"pickup:{_activeOrder.Id}:{_activeOrder.PickupAddress}";
        if (!force && key == _lastNavTargetKey) return;

        bool ok;
        if (!inProgress)
        {
            var pickup = PointFromOrder(
                _activeOrder.PickupAddress,
                _activeOrder.PickupLatitude,
                _activeOrder.PickupLongitude);
            ok = pickup != null
                ? NavigatorOverlay.OpenNavigator(pickup.Latitude, pickup.Longitude)
                : NavigatorOverlay.SearchInNavigator(_activeOrder.PickupAddress);
        }
        else
        {
            var navPoints = new List<NavigatorPoint>();

            if (_location.CurrentLat != 0 && _location.CurrentLng != 0)
            {
                navPoints.Add(new NavigatorPoint
                {
                    Address = "Текущее местоположение",
                    Latitude = _location.CurrentLat,
                    Longitude = _location.CurrentLng,
                });
            }

            foreach (var stop in _activeOrder.IntermediatePoints.OrderBy(p => p.SortOrder))
            {
                var point = PointFromOrder(stop.Address, stop.Latitude, stop.Longitude);
                if (point != null) navPoints.Add(point);
            }

            var destination = PointFromOrder(
                _activeOrder.DestinationAddress,
                _activeOrder.DestinationLatitude,
                _activeOrder.DestinationLongitude);
            if (destination != null) navPoints.Add(destination);

            ok = navPoints.Count >= 2
                ? NavigatorOverlay.OpenMultiPointRoute(navPoints)
                : NavigatorOverlay.SearchInNavigator(_activeOrder.DestinationAddress ?? string.Empty);
        }

        if (ok)
        {
            _lastNavTargetKey = key;
            // Яндекс Навигатор стал верхним Activity. Через небольшую задержку
            // возвращаем прозрачную сервисную панель поверх его карты.
            NavigatorOverlay.BringControlsToFront();
        }
        else
        {
            NavigatorOverlay.Toast("Не удалось открыть маршрут Яндекс Навигатора");
        }
    }

    private void OnOpenNavigatorAgain(object? sender, EventArgs e)
    {
        if (NavigatorOverlay.IsSupported && !NavigatorOverlay.IsNavigatorInstalled())
        {
            NavigatorOverlay.OpenNavigatorInStore();
            return;
        }
        if (NavigatorOverlay.IsSupported && !NavigatorOverlay.HasOverlayPermission())
        {
            NavigatorOverlay.RequestOverlayPermission();
            return;
        }
        StartNavigatorGuidance(force: true);
    }

    /// Заказ в работе → карта Яндекс Навигатора на весь экран + сервисные
    /// кнопки поверх неё. Вызывается автоматически при принятии заказа,
    /// смене этапа и возврате в приложение.
    private async void StartNavigatorGuidance(bool force = false)
    {
        if (_activeOrder == null)
        {
            NavPanel.IsVisible = false;
            NavigatorOverlay.Hide();
            return;
        }

        NavPanel.IsVisible = true;
        NavigatorOverlay.ModeEnabled = true;

        if (!NavigatorOverlay.IsSupported)
        {
            NavHintLabel.Text = "Ведение Навигатором доступно только на Android.";
            return;
        }

        if (!NavigatorOverlay.IsNavigatorInstalled())
        {
            NavHintLabel.Text = "Яндекс Навигатор не установлен — нажмите кнопку ниже для установки.";
            NavOpenBtn.Text = "Установить Яндекс Навигатор";
            var install = await DisplayAlert("Яндекс Навигатор",
                "Для ведения по маршруту требуется установленный Яндекс Навигатор.\n\nОткрыть Google Play для установки?",
                "Установить", "Отмена");
            if (install) NavigatorOverlay.OpenNavigatorInStore();
            return;
        }
        NavOpenBtn.Text = "Открыть карту Навигатора";

        // Проверяем разрешение «Поверх других приложений»
        if (!NavigatorOverlay.HasOverlayPermission())
        {
            NavHintLabel.Text = "Включите «Поверх других приложений», чтобы кнопки были на карте.";
            var go = await DisplayAlert("Кнопки поверх карты",
                "Чтобы кнопки заказа отображались поверх Яндекс Навигатора, необходимо включить системное разрешение «Поверх других приложений».\n\nСейчас откроются настройки телефона — включите переключатель и вернитесь в приложение.",
                "Открыть настройки", "Позже");
            if (go)
            {
                NavigatorOverlay.RequestOverlayPermission();
                return; // Не запускаем Навигатор поверх экрана настроек
            }
        }
        else
        {
            NavHintLabel.Text = "Кнопки заказа показаны поверх карты Навигатора.";
        }

        UpdateOverlayState();
        RouteInNavigator(force);
    }

    /// Синхронизация плавающей панели с текущим этапом заказа.
    private void UpdateOverlayState()
    {
        if (!NavigatorOverlay.IsSupported) return;

        if (_activeOrder == null)
        {
            NavigatorOverlay.Hide();
            return;
        }

        var index = Math.Clamp(_orderStatusStep, 0, _statusLabels.Length - 1);
        var label = _statusLabels[index];
        var color = _statusColors[index];
        if (IsWaitingStopStep())
        {
            label = "Продолжить";
            color = "#4CAF50";
        }

        var inProgress = NormStatus(_activeOrder.Status) == "inprogress";
        var target = inProgress
            ? (_activeOrder.DestinationAddress ?? "Назначение не указано")
            : _activeOrder.PickupAddress;
        try
        {
            NavTargetLabel.Text = (NormStatus(_activeOrder.Status) == "inprogress"
                ? "Назначение: " : "Подача: ") + target;
        }
        catch { }

        var price = _activeOrder.EstimatedPrice.ToString("F0");
        var subtitle = $"№{_activeOrder.OrderNumber} · {price} ₽";
        if (_activeOrder.WaitingActive)
            subtitle += " · идёт простой";

        NavigatorOverlay.Show(new OverlayState
        {
            Title = string.IsNullOrWhiteSpace(target) ? "Заказ в работе" : target,
            Subtitle = subtitle,
            ActionText = label,
            ActionColor = color,
            // Кнопку простоя показываем там же, где она доступна в приложении
            WaitingText = (_orderStatusStep >= 1 && !_activeOrder.WaitingActive) ? "Простой" : "",
        });
    }

    /// Нажатия на плавающей панели выполняют те же действия, что и в приложении.
    private void OnOverlayAction(string action)
    {
        try
        {
            switch (action)
            {
                case "status":
                    OnStatusButtonClick(this, EventArgs.Empty);
                    break;
                case "waiting":
                    OnToggleWaiting(this, EventArgs.Empty);
                    break;
                case "chat":
                    OnOpenChat(this, EventArgs.Empty);
                    break;
                case "cancel":
                    OnCancelActiveOrder(this, EventArgs.Empty);
                    break;
                case "sos":
                    // Тревожная кнопка требует подтверждения в приложении
                    OnSosClicked(this, EventArgs.Empty);
                    break;
            }
        }
        catch { }
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
    /// Выход из аккаунта: чистим сохранённую сессию и возвращаемся на вход
    private async void OnLogoutClicked(object? sender, EventArgs e)
    {
        try
        {
            if (!await DisplayAlert("Выход", "Выйти из аккаунта водителя?", "Выйти", "Отмена"))
                return;
            SecureStorage.Remove("token");
            SecureStorage.Remove("driver_id");
            SecureStorage.Remove("user_name");
            // Телефон оставляем в хранилище — следующий вход только с паролем
            // Используем текущие сервисы (у них сбрасываем состояние)
            try { _location.StopTracking(); } catch { }
            Application.Current!.MainPage = new NavigationPage(
                new LoginPage(_api, _signalR, _location));
        }
        catch { }
    }

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

                // Загружаем ПОЛНУЮ карточку: уведомление не содержит координат и
                // промежуточных точек, из-за чего раньше Навигатор получал мусор.
                var order = await _api.GetOrderAsync(notification.OrderId);
                if (order == null)
                {
                    await DisplayAlert("Заказ", "Не удалось загрузить полный маршрут заказа.", "OK");
                    return;
                }

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