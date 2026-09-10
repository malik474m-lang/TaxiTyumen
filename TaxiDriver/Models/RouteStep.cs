using System.Text.Json.Serialization;

namespace TaxiDriver.Models;

/// Шаг маршрута (манёвр) от OSRM через api/route.php?steps=1.
/// Используется голосовым навигатором для подсказок водителю.
public class RouteStep
{
    /// Точка начала манёвра: [lat, lng] — в том же порядке, что и геометрия
    [JsonPropertyName("loc")] public List<double> Loc { get; set; } = new();

    /// Тип манёвра OSRM: turn, new name, roundabout, arrive, depart, ...
    [JsonPropertyName("type")] public string Type { get; set; } = "";

    /// Уточнение: left | right | slight left | slight right | straight | uturn
    [JsonPropertyName("modifier")] public string Modifier { get; set; } = "";

    /// Номер выхода на круговом движении
    [JsonPropertyName("exit")] public int? Exit { get; set; }

    /// Улица, на которую ведёт шаг
    [JsonPropertyName("name")] public string Name { get; set; } = "";

    /// Длина участка после манёвра, метров
    [JsonPropertyName("distance")] public double Distance { get; set; }

    public double Lat => Loc.Count > 0 ? Loc[0] : 0;
    public double Lng => Loc.Count > 1 ? Loc[1] : 0;
}
