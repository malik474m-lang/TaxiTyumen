using TaxiDriver.Models;
using TaxiDriver.Services;

namespace TaxiDriver.Views;

public partial class ChatPage : ContentPage
{
    private readonly ApiService _api;
    private readonly SignalRService _signalR;
    private readonly Guid _orderId;
    private readonly Guid _userId;
    private readonly string _role;

    private readonly string[] _quickPhrases = new[]
    {
        "Подъехал",
        "Жду у входа",
        "Не могу дозвониться",
        "Выходите?",
        "Еду к вам",
        "Спасибо!"
    };

    public ChatPage(ApiService api, SignalRService signalR, Guid orderId, Guid userId, string role)
    {
        InitializeComponent();
        _api = api;
        _signalR = signalR;
        _orderId = orderId;
        _userId = userId;
        _role = role;

        _signalR.ChatMessageReceived += OnChatMessageReceived;

        _ = LoadMessagesAsync();
    }

    private async Task LoadMessagesAsync()
    {
        try
        {
            var messages = await _api.GetChatMessagesAsync(_orderId);

            MainThread.BeginInvokeOnMainThread(() =>
            {
                MessagesList.Children.Clear();

                foreach (var msg in messages)
                    AddMessageBubble(msg);

                ScrollToBottom();
            });
        }
        catch { }
    }

    private void AddMessageBubble(ChatMessageDto msg)
    {
        var isMe = msg.SenderId == _userId;

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
            }
        };

        var layout = new StackLayout { Spacing = 2 };

        if (!isMe)
        {
            layout.Children.Add(new Label
            {
                Text = msg.SenderName,
                TextColor = Color.FromArgb("#4FC3F7"),
                FontSize = 11,
                FontAttributes = FontAttributes.Bold
            });
        }

        layout.Children.Add(new Label
        {
            Text = msg.Text,
            TextColor = Colors.White,
            FontSize = 15
        });

        layout.Children.Add(new Label
        {
            Text = msg.CreatedAt.ToLocalTime().ToString("HH:mm"),
            TextColor = Colors.Gray,
            FontSize = 10,
            HorizontalOptions = LayoutOptions.End
        });

        bubble.Content = layout;
        MessagesList.Children.Add(bubble);
    }

    private async void OnSendMessage(object? sender, EventArgs e)
    {
        var text = MessageEntry.Text?.Trim();
        if (string.IsNullOrEmpty(text)) return;

        MessageEntry.Text = "";

        try
        {
            await _api.SendChatMessageAsync(_orderId, _userId, _role, text);
        }
        catch { }
    }

    private async void OnQuickPhrases(object? sender, EventArgs e)
    {
        var result = await DisplayActionSheet(
            "Быстрые фразы", "Отмена", null, _quickPhrases);

        if (!string.IsNullOrEmpty(result) && result != "Отмена")
        {
            try
            {
                await _api.SendChatMessageAsync(_orderId, _userId, _role, result);
            }
            catch { }
        }
    }

    private void OnChatMessageReceived(object data)
    {
        MainThread.BeginInvokeOnMainThread(async () =>
        {
            await LoadMessagesAsync();
        });
    }

    private async void ScrollToBottom()
    {
        await Task.Delay(100);
        try
        {
            await ChatScroll.ScrollToAsync(0, MessagesList.Height, true);
        }
        catch { }
    }

    protected override void OnDisappearing()
    {
        _signalR.ChatMessageReceived -= OnChatMessageReceived;
        base.OnDisappearing();
    }
}