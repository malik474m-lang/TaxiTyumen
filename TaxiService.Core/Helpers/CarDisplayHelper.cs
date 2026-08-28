namespace TaxiService.Core.Helpers;

public static class CarDisplayHelper
{
    public static string Format(string? color, string? brand, string? model, string? plate)
    {
        var colorText = CarColorHelper.TranslateFeminine(color);
        var brandText = string.IsNullOrWhiteSpace(brand) ? "" : brand.Trim();
        var modelText = string.IsNullOrWhiteSpace(model) ? "" : model.Trim();
        var plateText = LicensePlateHelper.Format(plate);

        var carName = string.Join(" ",
            new[] { colorText, brandText, modelText }
            .Where(x => !string.IsNullOrWhiteSpace(x)));

        if (string.IsNullOrWhiteSpace(carName))
            return plateText;

        if (plateText == "")
            return carName;

        return $"{carName} ({plateText})";
    }
}