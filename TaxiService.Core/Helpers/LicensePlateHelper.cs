namespace TaxiService.Core.Helpers;

public static class LicensePlateHelper
{
    private static readonly Dictionary<char, char> LatinToCyrillic = new()
    {
        {'A', 'А'}, {'a', 'А'},
        {'B', 'В'}, {'b', 'В'},
        {'C', 'С'}, {'c', 'С'},
        {'E', 'Е'}, {'e', 'Е'},
        {'H', 'Н'}, {'h', 'Н'},
        {'K', 'К'}, {'k', 'К'},
        {'M', 'М'}, {'m', 'М'},
        {'O', 'О'}, {'o', 'О'},
        {'P', 'Р'}, {'p', 'Р'},
        {'T', 'Т'}, {'t', 'Т'},
        {'X', 'Х'}, {'x', 'Х'},
        {'Y', 'У'}, {'y', 'У'}
    };

    public static string Format(string? plate)
    {
        if (string.IsNullOrWhiteSpace(plate))
            return "";

        var clean = new string(plate
            .Where(char.IsLetterOrDigit)
            .ToArray())
            .ToUpperInvariant();

        var chars = clean
            .Select(c => LatinToCyrillic.TryGetValue(c, out var mapped) ? mapped : c)
            .ToArray();

        var normalized = new string(chars);

        var letters = new string(normalized.Where(char.IsLetter).ToArray());
        var digits = new string(normalized.Where(char.IsDigit).ToArray());

        // Стандарт РФ: 1 буква + 3 цифры + 2 буквы + 2/3 цифры региона
        if (letters.Length == 3 && digits.Length >= 5)
        {
            var mainDigits = digits[..3];
            var region = digits[3..];
            return $"{letters[0]} {mainDigits} {letters[1]}{letters[2]} | {region}";
        }

        return normalized;
    }

    public static string FormatShort(string? plate)
    {
        if (string.IsNullOrWhiteSpace(plate))
            return "";

        var clean = new string(plate
            .Where(char.IsLetterOrDigit)
            .ToArray())
            .ToUpperInvariant();

        var chars = clean
            .Select(c => LatinToCyrillic.TryGetValue(c, out var mapped) ? mapped : c)
            .ToArray();

        var normalized = new string(chars);

        var letters = new string(normalized.Where(char.IsLetter).ToArray());
        var digits = new string(normalized.Where(char.IsDigit).ToArray());

        if (letters.Length == 3 && digits.Length >= 5)
        {
            var mainDigits = digits[..3];
            var region = digits[3..];
            return $"{letters[0]}{mainDigits}{letters[1]}{letters[2]} {region}";
        }

        return normalized;
    }
}