namespace TaxiService.Domain.Entities;

public class AutoCallSettings
{
    public Guid Id { get; set; } = Guid.NewGuid();

    // Включён ли автодозвон
    public bool IsEnabled { get; set; } = false;

    // Провайдер: "zvonok" или "none"
    public string Provider { get; set; } = "none";

    // Zvonok.com
    public string ZvonokApiKey { get; set; } = string.Empty;
    public string ZvonokCampaignId { get; set; } = string.Empty;

    // Шаблон текста для синтеза речи
    public string MessageTemplate { get; set; } =
        "Здравствуйте! Ваше такси прибыло. {CarColor} {CarBrand} {CarModel}, номер {LicensePlate}. " +
        "У вас {FreeWaitingMinutes} минут бесплатного ожидания. Хорошей поездки!";

    // Бесплатное ожидание (минуты)
    public int FreeWaitingMinutes { get; set; } = 5;

    // Баланс Zvonok (обновляется при проверке)
    public decimal ZvonokBalance { get; set; } = 0;
    public DateTime? BalanceCheckedAt { get; set; }

    public DateTime UpdatedAt { get; set; } = DateTime.UtcNow;
}