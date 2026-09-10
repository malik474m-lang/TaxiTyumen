using TaxiClient.Services;

namespace TaxiClient.Views;

public partial class RegisterPage : ContentPage
{
    private readonly ApiService _api;
    private readonly SignalRService _signalR;

    public RegisterPage(ApiService api, SignalRService signalR)
    {
        InitializeComponent();
        _api = api;
        _signalR = signalR;

        // Брендинг: фирменный акцент и название сервиса, как на экране входа
        ApplyBrand(BrandingService.Current);
        BrandingService.Updated += b =>
            MainThread.BeginInvokeOnMainThread(() => ApplyBrand(b));
    }

    private void ApplyBrand(BrandingData brand)
    {
        var accent = BrandingService.ParseColor(brand.PrimaryColor, "#FFD700");
        var ink = BrandingService.ParseColor(brand.PrimaryTextColor, "#1E1E2E");
        if (!string.IsNullOrWhiteSpace(brand.ServiceName)) Title = brand.ServiceName;
        RegBtn.BackgroundColor = accent;
        RegBtn.TextColor = ink;
    }

    private async void OnRegisterClicked(object sender, EventArgs e)
    {
        if (string.IsNullOrWhiteSpace(FirstNameEntry.Text) ||
            string.IsNullOrWhiteSpace(PhoneEntry.Text) ||
            string.IsNullOrWhiteSpace(PasswordEntry.Text))
        {
            ErrorLabel.Text = "Заполните все поля";
            ErrorLabel.IsVisible = true;
            return;
        }

        RegBtn.IsEnabled = false;
        ErrorLabel.IsVisible = false;

        try
        {
            var auth = await _api.RegisterAsync(
                PhoneEntry.Text.Trim(),
                FirstNameEntry.Text.Trim(),
                LastNameEntry.Text?.Trim() ?? "",
                PasswordEntry.Text);

            await _signalR.ConnectAsync(auth.Token);
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
            RegBtn.IsEnabled = true;
        }
    }
}