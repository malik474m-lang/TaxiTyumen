using TaxiClient.Models;
using TaxiClient.Services;

namespace TaxiClient.Views;

public partial class HistoryPage : ContentPage
{
    private readonly ApiService _api;

    public HistoryPage(ApiService api)
    {
        InitializeComponent();
        _api = api;
        _ = SafeLoadHistoryAsync();
    }

    private async Task SafeLoadHistoryAsync()
    {
        try
        {
            var orders = await _api.GetHistoryAsync();

            Loading.IsVisible = false;
            Loading.IsRunning = false;

            if (orders == null || orders.Count == 0)
            {
                HistoryList.Children.Add(new Label { Text = "Поездок пока нет", TextColor = Colors.Gray, FontSize = 16, HorizontalOptions = LayoutOptions.Center });
                return;
            }

            foreach (var o in orders)
            {
                try
                {
                    var statusColor = o.StatusText switch { "Завершён" => "#4CAF50", "Отменён" => "#F44336", _ => "#FF9800" };
                    var topGrid = new Grid
                    {
                        ColumnDefinitions = { new ColumnDefinition{Width=GridLength.Auto}, new ColumnDefinition{Width=GridLength.Auto}, new ColumnDefinition{Width=GridLength.Star} }
                    };
                    topGrid.Children.Add(new Label { Text = o.OrderNumber, TextColor = Color.FromArgb("#FFD700"), FontSize = 13, FontAttributes = FontAttributes.Bold });
                    var sl = new Label { Text = o.StatusText, TextColor = Color.FromArgb(statusColor), FontSize = 12, VerticalOptions = LayoutOptions.Center, Margin = new Thickness(10,0,0,0) };
                    Grid.SetColumn(sl, 1); topGrid.Children.Add(sl);
                    var tl = new Label { Text = o.TimeAgo, TextColor = Colors.Gray, FontSize = 11, HorizontalOptions = LayoutOptions.End, VerticalOptions = LayoutOptions.Center };
                    Grid.SetColumn(tl, 2); topGrid.Children.Add(tl);

                    var card = new Border
                    {
                        BackgroundColor = Color.FromArgb("#252536"), Stroke = Color.FromArgb("#444"), StrokeThickness = 1, Padding = new Thickness(14,10),
                        StrokeShape = new Microsoft.Maui.Controls.Shapes.RoundRectangle{CornerRadius=10},
                        Content = new StackLayout
                        {
                            Spacing = 4,
                            Children =
                            {
                                topGrid,
                                new Label { Text = " " + o.PickupAddress, TextColor = Colors.White, FontSize = 13 },
                                new Label { Text = " " + (o.DestinationAddress ?? ""), TextColor = Colors.LightGray, FontSize = 12 },
                                new HorizontalStackLayout
                                {
                                    Spacing = 15,
                                    Children =
                                    {
                                        new Label { Text = o.EstimatedPrice.ToString("F0") + " ", TextColor = Color.FromArgb("#FFD700"), FontSize = 16, FontAttributes = FontAttributes.Bold },
                                        new Label { Text = o.TariffName, TextColor = Colors.Gray, FontSize = 12, VerticalOptions = LayoutOptions.Center },
                                        new Label { Text = o.DriverInfo ?? "", TextColor = Colors.LightGray, FontSize = 12, VerticalOptions = LayoutOptions.Center }
                                    }
                                }
                            }
                        }
                    };
                    HistoryList.Children.Add(card);
                }
                catch { }
            }
        }
        catch (Exception ex)
        {
            Loading.IsVisible = false;
            Loading.IsRunning = false;
            HistoryList.Children.Add(new Label { Text = "Ошибка истории поездок: " + ex.Message, TextColor = Colors.Red });
        }
    }
}