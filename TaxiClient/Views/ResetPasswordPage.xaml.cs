using TaxiClient.Services;

namespace TaxiClient.Views;

/// Восстановление пароля по SMS-коду:
/// телефон → код → новый пароль. Код — тот же, что и для входа по SMS
/// (одноразовый, 5 минут), сервер: POST /api/auth/reset.php.
public partial class ResetPasswordPage : ContentPage
{
    private readonly ApiService _api;

    public ResetPasswordPage(ApiService api, string phone)
    {
        InitializeComponent();
        _api = api;
        PhoneEntry.Text = phone ?? string.Empty;

        // Брендинг — как на экране входа
        ApplyBrand(BrandingService.Current);
        BrandingService.Updated += b =>
            MainThread.BeginInvokeOnMainThread(() => ApplyBrand(b));
    }

    private void ApplyBrand(BrandingData brand)
    {
        var accent = BrandingService.ParseColor(brand.PrimaryColor, "#FFD700");
        var ink = BrandingService.ParseColor(brand.PrimaryTextColor, "#1E1E2E");
        if (!string.IsNullOrWhiteSpace(brand.ServiceName)) Title = brand.ServiceName;
        SendCodeBtn.BackgroundColor = accent;
        SendCodeBtn.TextColor = ink;
        ConfirmBtn.BackgroundColor = accent;
        ConfirmBtn.TextColor = ink;
        Loading.Color = accent;
    }

    private void SetBusy(bool busy)
    {
        SendCodeBtn.IsEnabled = !busy;
        ConfirmBtn.IsEnabled = !busy;
        Loading.IsVisible = busy;
        Loading.IsRunning = busy;
    }

    private void ShowError(string message)
    {
        ErrorLabel.Text = message;
        ErrorLabel.IsVisible = true;
        InfoLabel.IsVisible = false;
    }

    private static string Explain(Exception ex) => ex.Message
        .Replace("{\"error\":\"", string.Empty)
        .Replace("\"}", string.Empty);

    private async void OnSendCodeClicked(object sender, EventArgs e)
    {
        if (string.IsNullOrWhiteSpace(PhoneEntry.Text))
        {
            ShowError("Введите телефон");
            return;
        }

        SetBusy(true);
        ErrorLabel.IsVisible = false;
        InfoLabel.IsVisible = false;
        try
        {
            var devCode = await _api.RequestPasswordResetAsync(PhoneEntry.Text.Trim());

            CodePanel.IsVisible = true;
            if (!string.IsNullOrWhiteSpace(devCode))
            {
                // Демо-режим сервера: без провайдера sms.ru код возвращается ответом
                DevCodeLabel.Text = "Код (демо-режим, SMS не отправлено): " + devCode;
                DevCodeLabel.IsVisible = true;
            }
            else
            {
                HintLabel.Text = "Код отправлен по SMS. Введите его и задайте новый пароль";
            }
        }
        catch (Exception ex)
        {
            ShowError(Explain(ex));
        }
        finally
        {
            SetBusy(false);
        }
    }

    private async void OnConfirmClicked(object sender, EventArgs e)
    {
        if (string.IsNullOrWhiteSpace(CodeEntry.Text))
        {
            ShowError("Введите код из SMS");
            return;
        }
        if (string.IsNullOrWhiteSpace(NewPasswordEntry.Text) || NewPasswordEntry.Text.Length < 6)
        {
            ShowError("Пароль должен быть не короче 6 символов");
            return;
        }
        if (NewPasswordEntry.Text != RepeatPasswordEntry.Text)
        {
            ShowError("Пароли не совпадают");
            return;
        }

        SetBusy(true);
        ErrorLabel.IsVisible = false;
        InfoLabel.IsVisible = false;
        try
        {
            await _api.ConfirmPasswordResetAsync(
                PhoneEntry.Text.Trim(), CodeEntry.Text.Trim(), NewPasswordEntry.Text);

            InfoLabel.Text = "Пароль обновлён. Вернитесь и войдите с новым паролем";
            InfoLabel.IsVisible = true;
            await Task.Delay(900);
            await Navigation.PopAsync();
        }
        catch (Exception ex)
        {
            ShowError(Explain(ex));
        }
        finally
        {
            SetBusy(false);
        }
    }
}
