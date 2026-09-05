using TaxiDriver.Services;

namespace TaxiDriver.Views;

public partial class LoginPage : ContentPage
{
    private readonly ApiService _api;
    private readonly SignalRService _signalR;
    private readonly LocationService _location;

    public LoginPage(ApiService api, SignalRService signalR, LocationService location)
    {
        InitializeComponent();
        _api = api;
        _signalR = signalR;
        _location = location;
        Loaded += OnPageLoaded;
    }

    /// Авто-вход по сохранённой сессии: токен и ID водителя восстанавливаются
    /// из защищённого хранилища — ввод логина и пароля нужен только один раз.
    private async void OnPageLoaded(object? sender, EventArgs e)
    {
        Loaded -= OnPageLoaded;

        // Телефон из последнего входа подставляем сразу
        try
        {
            var lastPhone = await SecureStorage.GetAsync("last_phone");
            if (!string.IsNullOrWhiteSpace(lastPhone))
                PhoneEntry.Text = lastPhone;
        }
        catch { }

        try
        {
            var token = await SecureStorage.GetAsync("token");
            var driverIdRaw = await SecureStorage.GetAsync("driver_id");
            var userName = await SecureStorage.GetAsync("user_name");

            if (string.IsNullOrWhiteSpace(token) ||
                !Guid.TryParse(driverIdRaw, out var driverId))
                return;

            // Показываем прогресс вместо формы, пока осуществляется авто-вход
            LoadingIndicator.IsVisible = true;
            LoadingIndicator.IsRunning = true;
            LoginBtn.IsEnabled = false;

            _api.SetToken(token);
            var names = (userName ?? "Водитель").Split(' ', 2);

            var auth = new Models.AuthResponse
            {
                UserId = driverId,           // точный userId не нужен для работы; авторитетный ID — у профиля водителя
                Token = token,
                FirstName = names[0],
                LastName = names.Length > 1 ? names[1] : "",
                Role = "Driver",
                DriverId = driverId
            };

            await _signalR.ConnectAsync(token);
            _location.DriverId = auth.DriverId;

            Application.Current!.MainPage = new NavigationPage(
                new MainDriverPage(_api, _signalR, _location, auth));
        }
        catch
        {
            // Сессия повреждена или токен протух — показываем обычный вход
            LoadingIndicator.IsRunning = false;
            LoadingIndicator.IsVisible = false;
            LoginBtn.IsEnabled = true;
        }
    }

    private async void OnLoginClicked(object sender, EventArgs e)
    {
        if (string.IsNullOrWhiteSpace(PhoneEntry.Text) ||
            string.IsNullOrWhiteSpace(PasswordEntry.Text))
        {
            ErrorLabel.Text = "Введите телефон и пароль";
            ErrorLabel.IsVisible = true;
            return;
        }

        LoginBtn.IsEnabled = false;
        LoadingIndicator.IsVisible = true;
        LoadingIndicator.IsRunning = true;
        ErrorLabel.IsVisible = false;

        try
        {
            var auth = await _api.LoginAsync(
                PhoneEntry.Text.Trim(),
                PasswordEntry.Text);

            if (auth.Role != "Driver")
            {
                ErrorLabel.Text = "Этот аккаунт не является водителем";
                ErrorLabel.IsVisible = true;
                return;
            }

            if (!auth.DriverId.HasValue)
            {
                ErrorLabel.Text = "Профиль водителя не найден. Обратитесь к администратору.";
                ErrorLabel.IsVisible = true;
                return;
            }

            // Сохраняем данные
            await SecureStorage.SetAsync("token", auth.Token);
            await SecureStorage.SetAsync("last_phone", PhoneEntry.Text.Trim());
            await SecureStorage.SetAsync("driver_id", auth.DriverId.ToString()!);
            await SecureStorage.SetAsync("user_name",
                $"{auth.FirstName} {auth.LastName}");

            // Подключаем SignalR
            await _signalR.ConnectAsync(auth.Token);

            // Устанавливаем driverId в LocationService
            _location.DriverId = auth.DriverId;

            // Переходим на главный экран
            Application.Current!.MainPage = new NavigationPage(
                new MainDriverPage(_api, _signalR, _location, auth));
        }
        catch (Exception ex)
        {
            ErrorLabel.Text = ex.Message;
            ErrorLabel.IsVisible = true;
        }
        finally
        {
            LoginBtn.IsEnabled = true;
            LoadingIndicator.IsRunning = false;
            LoadingIndicator.IsVisible = false;
        }
    }
}
