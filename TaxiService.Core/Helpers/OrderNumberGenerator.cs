namespace TaxiService.Core.Helpers;

public static class OrderNumberGenerator
{
    public static string Generate()
    {
        var now = DateTime.UtcNow;
        var random = Random.Shared.Next(10000, 99999);
        return $"TX-{now:yyyyMMdd-HHmmssfff}-{random}";
    }
}