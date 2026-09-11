using System.Text;
using TaxiSmsGateway.Services;

namespace TaxiSmsGateway;

public partial class MainPage : ContentPage
{
    private readonly GatewayService _gateway = new();
    private CancellationTokenSource? _loop;
    private readonly StringBuilder _log = new();
    private int _sentCount, _failedCount;

    public MainPage()
    {
        InitializeComponent();

        ServerEntry.Text = _gateway.ServerUrl;
        TokenEntry.Text = _gateway.Token;
        SimEntry.Text = _gateway.SimPhone;

        // Шлюз должен подниматься сам после перезагрузки телефона
        if (Preferences.Get("auto_start", false) && _gateway.IsConfigured)
            Start();
    }

    // ── Журнал и статус ─────────────────────────────────────────────────────
    private void Log(string text)
    {
        var line = $"{DateTime.Now:HH:mm:ss}  {text}";
        MainThread.BeginInvokeOnMainThread(() =>
        {
            _log.Insert(0, line + Environment.NewLine);
            if (_log.Length > 4000) _log.Length = 4000;
            LogLabel.Text = _log.ToString();
            LastLabel.Text = line;
        });
    }

    private void UpdateStats() => MainThread.BeginInvokeOnMainThread(() =>
        StatsLabel.Text = $"Отправлено: {_sentCount} · Ошибок: {_failedCount}");

    private void SetRunning(bool running) => MainThread.BeginInvokeOnMainThread(() =>
    {
        StatusLabel.Text = running ? "Работает — ожидает задания" : "Остановлен";
        StatusLabel.TextColor = Color.FromArgb(running ? "#4ADE80" : "#FCA5A5");
        ToggleBtn.Text = running ? "Остановить шлюз" : "Запустить шлюз";
        ToggleBtn.BackgroundColor = Color.FromArgb(running ? "#3F3F46" : "#FACC15");
        ToggleBtn.TextColor = Color.FromArgb(running ? "#FFFFFF" : "#0A0A0C");
    });

    // ── Кнопки ──────────────────────────────────────────────────────────────
    private void OnSaveClicked(object? sender, EventArgs e)
    {
        _gateway.ServerUrl = (ServerEntry.Text ?? string.Empty).Trim();
        _gateway.Token = (TokenEntry.Text ?? string.Empty).Trim();
        _gateway.SimPhone = (SimEntry.Text ?? string.Empty).Trim();
        Log("Настройки сохранены");
    }

    private async void OnTestClicked(object? sender, EventArgs e)
    {
        OnSaveClicked(sender, e);
        if (!_gateway.IsConfigured)
        {
            await DisplayAlert("Настройки", "Укажите адрес сервера и токен устройства", "OK");
            return;
        }

        var (ok, message) = await _gateway.TestAsync();
        Log((ok ? "✓ " : "✗ ") + message);
        await DisplayAlert(ok ? "Связь есть" : "Не получилось", message, "OK");
    }

    private async void OnToggleClicked(object? sender, EventArgs e)
    {
        if (_loop != null) { Stop(); return; }

        OnSaveClicked(sender, e);
        if (!_gateway.IsConfigured)
        {
            await DisplayAlert("Настройки", "Сначала укажите адрес сервера и токен устройства", "OK");
            return;
        }

        // Разрешение на отправку SMS запрашиваем до старта цикла
        if (!await SmsSender.EnsurePermissionAsync())
        {
            await DisplayAlert("Нет разрешения",
                "Разрешите приложению отправлять SMS — без этого шлюз работать не сможет.", "OK");
            return;
        }

        Start();
    }

    private void Start()
    {
        _loop = new CancellationTokenSource();
        Preferences.Set("auto_start", true);
        SetRunning(true);
        Log("Шлюз запущен");
        _ = Task.Run(() => WorkAsync(_loop.Token));
    }

    private void Stop()
    {
        _loop?.Cancel();
        _loop = null;
        Preferences.Set("auto_start", false);
        SetRunning(false);
        Log("Шлюз остановлен");
    }

    // ── Основной цикл: опрос сервера → отправка → подтверждение ─────────────
    private async Task WorkAsync(CancellationToken ct)
    {
        var delay = 10;
        while (!ct.IsCancellationRequested)
        {
            try
            {
                var (data, error) = await _gateway.PollAsync(ct);

                if (error != null)
                {
                    Log("Сервер: " + error);
                    // Нет связи или выключен шлюз — не долбим сервер часто
                    delay = Math.Min(60, Math.Max(delay, 15));
                }
                else if (data != null)
                {
                    delay = Math.Max(3, data.PollSeconds);

                    foreach (var task in data.Messages)
                    {
                        if (ct.IsCancellationRequested) break;

                        var (ok, sendError) = await SmsSender.SendAsync(task.Phone, task.Message);
                        await _gateway.AcknowledgeAsync(task.Id, ok, sendError, ct);

                        if (ok) { _sentCount++; Log($"✓ SMS отправлено: {task.Phone}"); }
                        else { _failedCount++; Log($"✗ {task.Phone}: {sendError}"); }
                        UpdateStats();

                        // Пауза между сообщениями: оператор не любит «пулемёт»
                        await Task.Delay(1500, ct);
                    }
                }
            }
            catch (OperationCanceledException) { break; }
            catch (Exception ex) { Log("Ошибка: " + ex.Message); }

            try { await Task.Delay(TimeSpan.FromSeconds(delay), ct); }
            catch (OperationCanceledException) { break; }
        }
        SetRunning(false);
    }

    protected override void OnDisappearing()
    {
        // Цикл продолжает работать, пока приложение живо — специально не гасим
        base.OnDisappearing();
    }
}
