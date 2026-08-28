using System.Net.Http.Json;
using System.Text.Json;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;
using TaxiService.Core.Helpers;
using TaxiService.Core.Interfaces;
using TaxiService.Domain.Entities;
using TaxiService.Infrastructure.Data;

namespace TaxiService.API.Services;

public interface IAutoCallService
{
    Task CallClientOnDriverArrivedAsync(Order order);
    Task<decimal> CheckZvonokBalanceAsync();
}

public class AutoCallService : IAutoCallService
{
    private readonly TaxiDbContext _db;
    private readonly ISignalRNotifier _notifier;
    private readonly ILogger<AutoCallService> _logger;
    private readonly HttpClient _http;

    public AutoCallService(
        TaxiDbContext db,
        ISignalRNotifier notifier,
        ILogger<AutoCallService> logger)
    {
        _db = db;
        _notifier = notifier;
        _logger = logger;
        _http = new HttpClient();
    }

    public async Task CallClientOnDriverArrivedAsync(Order order)
    {
        try
        {
            var settings = await _db.AutoCallSettings.FirstOrDefaultAsync();
            if (settings == null)
            {
                _logger.LogInformation("Автодозвон: настройки не найдены, используем SignalR");
                await SendSignalRNotificationAsync(order, 5);
                return;
            }

            // Формируем текст сообщения
            var messageText = FormatMessage(settings.MessageTemplate, order, settings.FreeWaitingMinutes);

            // SignalR уведомление клиенту (всегда)
            await SendSignalRNotificationAsync(order, settings.FreeWaitingMinutes, messageText);

            // Если автодозвон включён и провайдер настроен
            if (settings.IsEnabled && settings.Provider == "zvonok" &&
                !string.IsNullOrEmpty(settings.ZvonokApiKey))
            {
                await CallViaZvonokAsync(order, settings, messageText);
            }
            else
            {
                _logger.LogInformation(
                    "Автодозвон выключен. SignalR уведомление отправлено клиенту заказа {OrderNumber}",
                    order.OrderNumber);
            }
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "Ошибка автодозвона для заказа {OrderNumber}", order.OrderNumber);
        }
    }

    private async Task SendSignalRNotificationAsync(Order order, int freeMinutes, string? text = null)
    {
        if (order.ClientId == null) return;

        var carInfo = order.Driver != null
            ? $"{CarColorHelper.Translate(order.Driver.CarColor)} {order.Driver.CarBrand} {order.Driver.CarModel}, номер {LicensePlateHelper.Format(order.Driver.LicensePlate)}"
            : "Ваше такси";

        var defaultText = $"Ваше такси прибыло! {carInfo}. " +
                          $"Бесплатное ожидание: {freeMinutes} минут.";

        await _notifier.SendToUserAsync(
            order.ClientId.ToString()!,
            "DriverArrivedNotification",
            new
            {
                OrderId = order.Id,
                OrderNumber = order.OrderNumber,
                Message = text ?? defaultText,
                CarInfo = carInfo,
                FreeWaitingMinutes = freeMinutes,
                Timestamp = DateTime.UtcNow
            });
    }

    private async Task CallViaZvonokAsync(Order order, AutoCallSettings settings, string messageText)
    {
        try
        {
            var clientPhone = order.Client?.Phone ?? order.ClientPhone;
            if (string.IsNullOrEmpty(clientPhone))
            {
                _logger.LogWarning("Автодозвон: нет номера телефона клиента");
                return;
            }

            // Формат номера для Zvonok: 79991234567
            var phone = clientPhone
                .Replace("+", "")
                .Replace(" ", "")
                .Replace("-", "")
                .Replace("(", "")
                .Replace(")", "");

            if (phone.StartsWith("8"))
                phone = "7" + phone[1..];

            // Zvonok.com API: https://zvonok.com/api-doc/
            var url = "https://zvonok.com/manager/cabapi_external/api/v1/phones/call/";

            var formData = new FormUrlEncodedContent(new[]
            {
                new KeyValuePair<string, string>("public_key", settings.ZvonokApiKey),
                new KeyValuePair<string, string>("phone", phone),
                new KeyValuePair<string, string>("campaign_id", settings.ZvonokCampaignId),
                new KeyValuePair<string, string>("text", messageText),
            });

            var response = await _http.PostAsync(url, formData);
            var responseText = await response.Content.ReadAsStringAsync();

            if (response.IsSuccessStatusCode)
            {
                _logger.LogInformation(
                    "Автодозвон через Zvonok: звонок на {Phone} по заказу {OrderNumber}. Ответ: {Response}",
                    phone, order.OrderNumber, responseText);
            }
            else
            {
                _logger.LogWarning(
                    "Автодозвон через Zvonok: ошибка. Phone={Phone}, Response={Response}",
                    phone, responseText);
            }
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "Ошибка вызова Zvonok API");
        }
    }

    public async Task<decimal> CheckZvonokBalanceAsync()
    {
        try
        {
            var settings = await _db.AutoCallSettings.FirstOrDefaultAsync();
            if (settings == null || string.IsNullOrEmpty(settings.ZvonokApiKey))
                return 0;

            var url = $"https://zvonok.com/manager/cabapi_external/api/v1/balance/?public_key={settings.ZvonokApiKey}";

            var response = await _http.GetAsync(url);
            if (!response.IsSuccessStatusCode)
                return 0;

            var json = await response.Content.ReadFromJsonAsync<JsonElement>();
            if (json.TryGetProperty("balance", out var balanceProp))
            {
                var balance = balanceProp.GetDecimal();
                settings.ZvonokBalance = balance;
                settings.BalanceCheckedAt = DateTime.UtcNow;
                await _db.SaveChangesAsync();
                return balance;
            }

            return 0;
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "Ошибка проверки баланса Zvonok");
            return 0;
        }
    }

    /// <summary>
    /// Форматирование текста сообщения из шаблона.
    /// Доступные переменные:
    /// {CarColor}, {CarBrand}, {CarModel}, {LicensePlate},
    /// {FreeWaitingMinutes}, {OrderNumber}, {ClientName}
    /// </summary>
    public static string FormatMessage(string template, Order order, int freeMinutes)
    {
        var text = template;

        if (order.Driver != null)
        {
            text = text
                .Replace("{CarColor}", CarColorHelper.Translate(order.Driver.CarColor))
                .Replace("{CarBrand}", order.Driver.CarBrand)
                .Replace("{CarModel}", order.Driver.CarModel)
                .Replace("{LicensePlate}", LicensePlateHelper.Format(order.Driver.LicensePlate));
        }

        text = text
            .Replace("{FreeWaitingMinutes}", freeMinutes.ToString())
            .Replace("{OrderNumber}", order.OrderNumber)
            .Replace("{ClientName}", order.Client?.FirstName ?? order.ClientName ?? "");

        return text;
    }
}