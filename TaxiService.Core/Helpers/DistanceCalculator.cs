using System.Net.Http.Json;
using System.Text.Json;
using System.Globalization;

namespace TaxiService.Core.Helpers;

public static class DistanceCalculator
{
    public static double GetDistanceKm(double lat1, double lng1, double lat2, double lng2)
    {
        const double R = 6371.0;
        var dLat = ToRadians(lat2 - lat1);
        var dLng = ToRadians(lng2 - lng1);
        var a = Math.Sin(dLat / 2) * Math.Sin(dLat / 2) +
                Math.Cos(ToRadians(lat1)) * Math.Cos(ToRadians(lat2)) *
                Math.Sin(dLng / 2) * Math.Sin(dLng / 2);
        var c = 2 * Math.Atan2(Math.Sqrt(a), Math.Sqrt(1 - a));
        return R * c;
    }

    public static double GetRoadDistanceKm(double lat1, double lng1, double lat2, double lng2, double roadFactor = 1.3)
    {
        return GetDistanceKm(lat1, lng1, lat2, lng2) * roadFactor;
    }

    public static int EstimateDurationMinutes(double distanceKm, double avgSpeedKmh = 25.0)
    {
        return (int)Math.Ceiling(distanceKm / avgSpeedKmh * 60);
    }

    
    /// <summary>
    /// Реальное расстояние по дорогам через OSRM (бесплатно).
    /// Если OSRM недоступен  fallback на Haversine  1.3.
    /// </summary>
    public static async Task<(double DistanceKm, int DurationMinutes)> GetRealRouteAsync(
        double lat1, double lng1, double lat2, double lng2)
    {
        try
        {
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(5) };

            var url = string.Format(CultureInfo.InvariantCulture,
                "https://router.project-osrm.org/route/v1/driving/{0},{1};{2},{3}?overview=false",
                lng1, lat1, lng2, lat2);

            var response = await http.GetAsync(url);
            if (!response.IsSuccessStatusCode)
                return FallbackRoute(lat1, lng1, lat2, lng2);

            var json = await response.Content.ReadFromJsonAsync<JsonElement>();

            if (json.TryGetProperty("routes", out var routes) &&
                routes.GetArrayLength() > 0)
            {
                var route = routes[0];
                var distanceMeters = route.GetProperty("distance").GetDouble();
                var durationSeconds = route.GetProperty("duration").GetDouble();

                var distanceKm = Math.Round(distanceMeters / 1000.0, 1);
                var durationMinutes = (int)Math.Ceiling(durationSeconds / 60.0);

                return (distanceKm, durationMinutes);
            }

            return FallbackRoute(lat1, lng1, lat2, lng2);
        }
        catch
        {
            return FallbackRoute(lat1, lng1, lat2, lng2);
        }
    }

    private static (double DistanceKm, int DurationMinutes) FallbackRoute(
        double lat1, double lng1, double lat2, double lng2)
    {
        var dist = GetRoadDistanceKm(lat1, lng1, lat2, lng2);
        var dur = EstimateDurationMinutes(dist);
        return (Math.Round(dist, 1), dur);
    }

    private static double ToRadians(double degrees) => degrees * Math.PI / 180.0;
}
