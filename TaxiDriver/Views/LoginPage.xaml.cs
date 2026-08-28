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
