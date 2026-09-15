using System.Globalization;
using System.Linq;
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

        await LoadNpdAsync();
    }

    /// Статус самозанятого в ФНС и привязка кабинета «Мой налог».
    private async Task LoadNpdAsync()
    {
        try
        {
            var npd = await _api.GetNpdStatusAsync();

            NpdLinkForm.IsVisible = !npd.Linked;
            NpdUnlinkBtn.IsVisible = npd.Linked;
            if (!string.IsNullOrWhiteSpace(npd.Inn)) NpdInn.Text = npd.Inn;

            if (npd.Linked)
            {
                NpdStatusLabel.Text = "✓ Чеки формируются автоматически"
                    + (string.IsNullOrWhiteSpace(npd.DisplayName) ? "" : $" · {npd.DisplayName}");
                NpdStatusLabel.TextColor = Color.FromArgb("#4ADE80");
            }
            else if (npd.NpdChecked && npd.NpdStatus == false)
            {
                NpdStatusLabel.Text = "ФНС: вы не числитесь самозанятым — оформите статус в «Мой налог»";
                NpdStatusLabel.TextColor = Color.FromArgb("#FCA5A5");
            }
            else if (npd.NpdChecked && npd.NpdStatus == true)
            {
                NpdStatusLabel.Text = "ФНС: статус самозанятого подтверждён. Привяжите кабинет для авточеков.";
                NpdStatusLabel.TextColor = Color.FromArgb("#FACC15");
            }
            else
            {
                NpdStatusLabel.Text = "Укажите ИНН и привяжите кабинет «Мой налог»";
                NpdStatusLabel.TextColor = Colors.Gray;
            }

            if (!string.IsNullOrWhiteSpace(npd.LastError))
            {
                NpdStatusLabel.Text = npd.LastError;
                NpdStatusLabel.TextColor = Color.FromArgb("#FCA5A5");
            }

            NpdReceiptsList.Children.Clear();
            foreach (var r in npd.Receipts.Take(10))
            {
                var ok = r.Status is "created" or "manual";
                var label = new Label
                {
                    Text = $"{r.Amount:F0} ₽ · {ReceiptText(r)} · {r.CreatedAt}",
                    FontSize = 11,
                    TextColor = ok ? Color.FromArgb("#8FBF9F") : Color.FromArgb("#FCA5A5"),
                };
                if (ok && !string.IsNullOrWhiteSpace(r.PrintUrl))
                {
                    var url = r.PrintUrl!;
                    var tap = new TapGestureRecognizer();
                    tap.Tapped += async (_, _) =>
                    {
                        try { await Browser.Default.OpenAsync(url, BrowserLaunchMode.SystemPreferred); }
                        catch { }
                    };
                    label.GestureRecognizers.Add(tap);
                    label.TextDecorations = TextDecorations.Underline;
                }
                NpdReceiptsList.Children.Add(label);
            }
        }
        catch (Exception ex)
        {
            NpdStatusLabel.Text = ex.Message;
            NpdStatusLabel.TextColor = Color.FromArgb("#FCA5A5");
        }
    }

    private static string ReceiptText(NpdReceiptDto r) => r.Status switch
    {
        "created" => r.Source == "auto" ? "чек создан автоматически" : "чек создан",
        "manual" => "чек добавлен вручную",
        "cancelled" => "чек отменён",
        _ => "ошибка: " + (r.Error ?? ""),
    };

    private async void OnLinkNpd(object? sender, EventArgs e)
    {
        var inn = (NpdInn.Text ?? "").Trim();
        var password = NpdPassword.Text ?? "";
        if (inn.Length != 12)
        {
            await DisplayAlert("Мой налог", "ИНН должен содержать 12 цифр", "OK"); return;
        }
        if (string.IsNullOrWhiteSpace(password))
        {
            await DisplayAlert("Мой налог", "Введите пароль от кабинета lknpd.nalog.ru", "OK"); return;
        }

        var agree = await DisplayAlert("Согласие",
            "Разрешаете сервису формировать чеки НПД от вашего имени при выплатах? "
            + "Пароль не сохраняется, отключить можно в любой момент.",
            "Разрешаю", "Отмена");
        if (!agree) return;

        NpdLinkBtn.IsEnabled = false;
        try
        {
            await _api.LinkNpdAsync(inn, password);
            NpdPassword.Text = "";
            await DisplayAlert("Готово", "Кабинет привязан. Чеки будут создаваться автоматически.", "OK");
            await LoadNpdAsync();
        }
        catch (Exception ex) { await DisplayAlert("Мой налог", ex.Message, "OK"); }
        finally { NpdLinkBtn.IsEnabled = true; }
    }

    private async void OnUnlinkNpd(object? sender, EventArgs e)
    {
        if (!await DisplayAlert("Отключить",
            "Чеки перестанут формироваться автоматически — придётся создавать их вручную в «Мой налог». Продолжить?",
            "Отключить", "Отмена")) return;
        try
        {
            await _api.UnlinkNpdAsync();
            await LoadNpdAsync();
        }
        catch (Exception ex) { await DisplayAlert("Мой налог", ex.Message, "OK"); }
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
