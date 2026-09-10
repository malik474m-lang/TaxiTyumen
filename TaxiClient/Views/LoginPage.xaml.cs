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

        // Бренд сервиса из админки: применяем текущий и следим за обновлениями
        ApplyBrand(BrandingService.Current);
        BrandingService.Updated += b =>
            MainThread.BeginInvokeOnMainThread(() => ApplyBrand(b));
    }

    /// Применение бренда: название сервиса, подзаголовок приложения,
    /// фирменные цвета и логотип (если загружен в админке).
    private void ApplyBrand(BrandingData brand)
    {
        var accent = BrandingService.ParseColor(brand.PrimaryColor, "#FFD700");
        var ink = BrandingService.ParseColor(brand.PrimaryTextColor, "#1E1E2E");

        if (!string.IsNullOrWhiteSpace(brand.ServiceName))
        {
            BrandNameLabel.Text = brand.ServiceName;
            Title = brand.ServiceName;
        }
        if (!string.IsNullOrWhiteSpace(brand.AppName))
            BrandSubtitleLabel.Text = brand.AppName;

        BrandNameLabel.TextColor = accent;
        LoginBtn.BackgroundColor = accent;
        LoginBtn.TextColor = ink;
        Loading.Color = accent;

        var logo = BrandingService.AbsoluteLogoUrl(brand);
        if (logo != null)
        {
            LogoImage.Source = ImageSource.FromUri(new Uri(logo));
            LogoImage.IsVisible = true;
        }
    }

    protected override void OnAppearing()
    {
        base.OnAppearing();
        // Освежаем бренд при каждом показе экрана входа
        _ = BrandingService.LoadAsync();
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

    /// Восстановление пароля по SMS-коду (телефон подставляется в форму).
    private async void OnForgotClicked(object sender, EventArgs e)
    {
        await Navigation.PushAsync(
            new ResetPasswordPage(_api, PhoneEntry.Text?.Trim() ?? string.Empty));
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