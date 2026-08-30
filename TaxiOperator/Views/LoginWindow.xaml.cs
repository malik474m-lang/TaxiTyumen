using System.Windows;
using System.Windows.Input;
using TaxiOperator.Services;

namespace TaxiOperator.Views;

public partial class LoginWindow : Window
{
    private readonly ApiService _api;

    public LoginWindow(ApiService api)
    {
        InitializeComponent();
        _api = api;

        ApplyBranding();

        // Фокус на поле пароля если телефон уже заполнен
        Loaded += (s, e) => PasswordBox.Focus();
    }

    /// Название сервиса и подпись экрана входа — из админки
    private void ApplyBranding()
    {
        var b = BrandingService.Current;
        Title = BrandingService.WindowTitle("Вход");
        BrandTitleText.Text = b.ServiceName;
        BrandSubtitleText.Text = string.IsNullOrWhiteSpace(b.HeroTitle) ? b.AppName : b.HeroTitle;
    }

    // Enter в любом месте окна  войти
    private void OnKeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Enter)
            _ = DoLoginAsync();
    }

    private async void OnLoginClick(object sender, RoutedEventArgs e)
    {
        await DoLoginAsync();
    }

    private async Task DoLoginAsync()
    {
        if (LoginButton.IsEnabled == false) return;

        LoginButton.IsEnabled = false;
        LoginButton.Content = "Вход...";
        ErrorText.Visibility = Visibility.Collapsed;

        try
        {
            var phone = PhoneBox.Text.Trim();
            var password = PasswordBox.Password;

            if (string.IsNullOrEmpty(phone) || string.IsNullOrEmpty(password))
            {
                ErrorText.Text = "Введите телефон и пароль";
                ErrorText.Visibility = Visibility.Visible;
                return;
            }

            var auth = await _api.LoginAsync(phone, password);

            if (auth != null)
            {
                // Создаём смену оператора
                try
                {
                    await _api.StartShiftAsync();
                }
                catch { }

                var main = new MainWindow(_api);
                main.Show();
                Close();
            }
        }
        catch (Exception ex)
        {
            ErrorText.Text = $"Ошибка: {ex.Message}";
            ErrorText.Visibility = Visibility.Visible;
        }
        finally
        {
            LoginButton.IsEnabled = true;
            LoginButton.Content = "Войти";
        }
    }
}
