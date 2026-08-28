namespace TaxiService.Core.Helpers;

public static class CarColorHelper
{
    private static readonly Dictionary<string, string> BaseColors = new(StringComparer.OrdinalIgnoreCase)
    {
        { "White", "Белый" },
        { "Black", "Чёрный" },
        { "Silver", "Серебристый" },
        { "Gray", "Серый" },
        { "Grey", "Серый" },
        { "Red", "Красный" },
        { "Blue", "Синий" },
        { "Green", "Зелёный" },
        { "Yellow", "Жёлтый" },
        { "Orange", "Оранжевый" },
        { "Brown", "Коричневый" },
        { "Beige", "Бежевый" },
        { "Gold", "Золотистый" },
        { "Purple", "Фиолетовый" },
        { "Pink", "Розовый" },
        { "Dark Blue", "Тёмно-синий" },
        { "Dark Green", "Тёмно-зелёный" },
        { "Light Blue", "Голубой" },
        { "Dark Red", "Бордовый" },
        { "Burgundy", "Бордовый" }
    };

    private static readonly Dictionary<string, string> FeminineColors = new(StringComparer.OrdinalIgnoreCase)
    {
        { "White", "Белая" },
        { "Black", "Чёрная" },
        { "Silver", "Серебристая" },
        { "Gray", "Серая" },
        { "Grey", "Серая" },
        { "Red", "Красная" },
        { "Blue", "Синяя" },
        { "Green", "Зелёная" },
        { "Yellow", "Жёлтая" },
        { "Orange", "Оранжевая" },
        { "Brown", "Коричневая" },
        { "Beige", "Бежевая" },
        { "Gold", "Золотистая" },
        { "Purple", "Фиолетовая" },
        { "Pink", "Розовая" },
        { "Dark Blue", "Тёмно-синяя" },
        { "Dark Green", "Тёмно-зелёная" },
        { "Light Blue", "Голубая" },
        { "Dark Red", "Бордовая" },
        { "Burgundy", "Бордовая" }
    };

    public static string Translate(string? color)
    {
        if (string.IsNullOrWhiteSpace(color))
            return "";

        var trimmed = color.Trim();

        if (BaseColors.TryGetValue(trimmed, out var result))
            return result;

        return Normalize(trimmed);
    }

    public static string TranslateFeminine(string? color)
    {
        if (string.IsNullOrWhiteSpace(color))
            return "";

        var trimmed = color.Trim();

        if (FeminineColors.TryGetValue(trimmed, out var result))
            return result;

        return Normalize(trimmed);
    }

    private static string Normalize(string value)
    {
        if (string.IsNullOrWhiteSpace(value))
            return "";

        if (value.Length == 1)
            return value.ToUpper();

        return char.ToUpper(value[0]) + value[1..].ToLower();
    }
}