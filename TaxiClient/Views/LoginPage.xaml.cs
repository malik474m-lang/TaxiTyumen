using TaxiClient.Services;

namespace TaxiClient.Views;

public partial class LoginPage : ContentPage
{
    private readonly ApiService _api;
    private readonly SignalRService _signalR;

    public LoginPage(ApiService api, SignalRService signalR)
    {
        InitializeComponent();
        _api = api;
        _signalR = signalR;
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
        Loading.IsVisible = true;
        Loading.IsRunning = true;
        ErrorLabel.IsVisible = false;

        try
        {
            var auth = await _api.LoginAsync(
                PhoneEntry.Text.Trim(), PasswordEntry.Text);

            try
            {
                await _signalR.ConnectAsync(auth.Token);
            }
            catch
            {
                // SignalR недоступен  продолжаем без реалтайма
            }

            Application.Current!.MainPage = new NavigationPage(
                new MainClientPage(_api, _signalR));
        }
        catch (Exception ex)
        {
            ErrorLabel.Text = ex.Message;
            ErrorLabel.IsVisible = true;
        }
        finally
        {
            LoginBtn.IsEnabled = true;
            Loading.IsRunning = false;
            Loading.IsVisible = false;
        }
    }

    private async void OnRegisterClicked(object sender, EventArgs e)
    {
        try
        {
            await Navigation.PushAsync(new RegisterPage(_api, _signalR));
        }
        catch (Exception ex)
        {
            await DisplayAlert("Ошибка", ex.Message, "OK");
        }
    }
}