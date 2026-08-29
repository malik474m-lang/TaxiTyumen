using TaxiDriver.Models;
using TaxiDriver.Services;

namespace TaxiDriver.Views;

// Общий чат водителей автопарка (api/fleet-chat.php на taxi.event72.ru):
// инкрементальный polling 4 с (?after=<ms>), свои сообщения справа.
public partial class FleetChatPage : ContentPage
{
    private readonly ApiService _api;
    private readonly Guid _userId;
    private long _lastMs;
    private bool _running;
    private bool _timerStarted;
    private bool _sending;

    public FleetChatPage(ApiService api, Guid userId)
    {
        InitializeComponent();
        _api = api;
        _userId = userId;
    }

    protected override void OnAppearing()
    {
        base.OnAppearing();
        _running = true;
        _ = PollAsync(full: true);

        if (!_timerStarted)
        {
            _timerStarted = true;
            Dispatcher.StartTimer(TimeSpan.FromSeconds(4), () =>
            {
                if (_running) _ = PollAsync(full: false);
                return _running;
            });
        }
    }

    protected override void OnDisappearing()
    {
        _running = false;
        base.OnDisappearing();
    }

    private async Task PollAsync(bool full)
    {
        try
        {
            var messages = await _api.GetFleetMessagesAsync(full ? 0 : _lastMs);
            if (messages.Count == 0) return;

            MainThread.BeginInvokeOnMainThread(() =>
            {
                if (full) MessagesList.Children.Clear();

                foreach (var msg in messages.OrderBy(m => m.CreatedAt))
                {
                    AddMessageBubble(msg);
                    _lastMs = Math.Max(_lastMs,
                        new DateTimeOffset(msg.CreatedAt.ToUniversalTime()).ToUnixTimeMilliseconds());
                }
                ScrollToBottom();
            });
        }
        catch { }
    }

    private void AddMessageBubble(FleetMessageDto msg)
    {
        var isMe = msg.SenderId == _userId;

        var content = new VerticalStackLayout { Spacing = 2 };
        if (!isMe)
        {
            content.Children.Add(new Label
            {
                Text = $"{msg.SenderName} · {msg.CarInfo}",
                FontSize = 11,
                FontAttributes = FontAttributes.Bold,
                TextColor = Color.FromArgb("#FFD700"),
                Opacity = 0.9
            });
        }
        content.Children.Add(new Label
        {
            Text = msg.Text,
            TextColor = Colors.White,
            FontSize = 15
        });
        content.Children.Add(new Label
        {
            Text = msg.CreatedAt.ToLocalTime().ToString("HH:mm"),
            FontSize = 10,
            TextColor = Color.FromArgb("#888"),
            HorizontalOptions = LayoutOptions.End
        });

        var bubble = new Border
        {
            BackgroundColor = isMe
                ? Color.FromArgb("#1A3A1A")
                : Color.FromArgb("#252536"),
            Stroke = isMe
                ? Color.FromArgb("#4CAF50")
                : Color.FromArgb("#444"),
            StrokeThickness = 1,
            Padding = new Thickness(12, 8),
            HorizontalOptions = isMe
                ? LayoutOptions.End
                : LayoutOptions.Start,
            MaximumWidthRequest = 280,
            StrokeShape = new Microsoft.Maui.Controls.Shapes.RoundRectangle
            {
                CornerRadius = isMe
                    ? new CornerRadius(12, 12, 4, 12)
                    : new CornerRadius(12, 12, 12, 4)
            },
            Content = content
        };

        MessagesList.Children.Add(bubble);
    }

    private async void OnSendMessage(object? sender, EventArgs e)
    {
        var text = MessageEntry.Text?.Trim();
        if (string.IsNullOrEmpty(text) || _sending) return;

        _sending = true;
        try
        {
            await _api.SendFleetMessageAsync(text);
            MainThread.BeginInvokeOnMainThread(() => MessageEntry.Text = "");
            await PollAsync(full: false);
        }
        catch { }
        finally
        {
            _sending = false;
        }
    }

    private void ScrollToBottom()
    {
        MainThread.BeginInvokeOnMainThread(async () =>
        {
            await Task.Delay(80);
            if (MessagesList.Children.Count > 0)
                await ChatScroll.ScrollToAsync(
                    (VisualElement)MessagesList.Children[^1], ScrollToPosition.End, false);
        });
    }
}
