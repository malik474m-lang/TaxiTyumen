using System.Globalization;
using TaxiDriver.Services;

namespace TaxiDriver.Views;

public partial class WalletPage : ContentPage
{
    private readonly ApiService _api;

    public WalletPage(ApiService api)
    {
        InitializeComponent();
        _api = api;
        _ = LoadAsync();
    }

    private async Task LoadAsync()
    {
        try
        {
            var w = await _api.GetSberWalletAsync();
            AvailableLabel.Text = $"{w.CashlessBalance:F0} ₽";
            PendingLabel.Text = $"{w.PendingWithdrawal:F0} ₽";
            TotalsLabel.Text = $"Всего по безналичным заявкам: {w.TotalCashlessEarned:F0} ₽ · Выплачено: {w.TotalPaidOut:F0} ₽";
            WithdrawalsList.Children.Clear();
            foreach (var r in w.Withdrawals)
            {
                WithdrawalsList.Children.Add(new Border
                {
                    BackgroundColor = Color.FromArgb("#252536"), Stroke = Color.FromArgb("#444"),
                    Padding = new Thickness(10), StrokeShape = new Microsoft.Maui.Controls.Shapes.RoundRectangle { CornerRadius = 8 },
                    Content = new Label
                    {
                        Text = $"{r.Amount:F0} ₽ · {StatusText(r.Status)}\n{r.Phone} · {r.BankName}\n{r.CreatedAt}",
                        TextColor = r.Status == "paid" ? Color.FromArgb("#4ADE80") : Colors.White,
                        FontSize = 12,
                    }
                });
            }
        }
        catch (Exception ex) { await DisplayAlert("Кошелёк", ex.Message, "OK"); }
        finally { Loading.IsRunning = false; Loading.IsVisible = false; }
    }

    private static string StatusText(string status) => status switch
    {
        "pending" => "на рассмотрении", "paid" => "выплачено",
        "rejected" => "отклонено", "approved" => "одобрено", _ => status,
    };

    private async void OnTopup(object? sender, EventArgs e)
    {
        if (!decimal.TryParse(TopupAmount.Text?.Replace(',', '.'), NumberStyles.Any,
                CultureInfo.InvariantCulture, out var amount) || amount < 100)
        {
            await DisplayAlert("Пополнение", "Укажите сумму от 100 ₽", "OK"); return;
        }
        try
        {
            var start = await _api.StartDriverTopupAsync(amount);
            if (string.IsNullOrWhiteSpace(start.FormUrl)) throw new Exception("Сбер не вернул ссылку оплаты");
            await Browser.Default.OpenAsync(start.FormUrl, BrowserLaunchMode.SystemPreferred);
            await DisplayAlert("Платёж открыт", "После оплаты вернитесь сюда и обновите страницу.", "OK");
            await LoadAsync();
        }
        catch (Exception ex) { await DisplayAlert("Пополнение", ex.Message, "OK"); }
    }

    private async void OnWithdraw(object? sender, EventArgs e)
    {
        if (!decimal.TryParse(WithdrawAmount.Text?.Replace(',', '.'), NumberStyles.Any,
                CultureInfo.InvariantCulture, out var amount) || amount < 100)
        {
            await DisplayAlert("Вывод", "Укажите сумму от 100 ₽", "OK"); return;
        }
        try
        {
            await _api.RequestSberWithdrawalAsync(amount, SbpPhone.Text ?? "",
                BankName.Text ?? "", InnEntry.Text ?? "");
            await DisplayAlert("Заявка создана",
                "Администратор выполнит перевод по СБП. После выплаты передайте чек самозанятого.", "OK");
            await LoadAsync();
        }
        catch (Exception ex) { await DisplayAlert("Вывод", ex.Message, "OK"); }
    }
}
