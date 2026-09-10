using TaxiDriver.Models;

namespace TaxiDriver.Services;

/// <summary>
/// Голосовые подсказки по маршруту. Манёвры приходят от сервера
/// (api/route.php?steps=1, источник — OSRM), произносятся штатным
/// синтезатором речи устройства (Android: Google TTS — русский голос
/// обычно предустановлен и работает без интернета).
///
/// Логика навигатора: на подходе к манёвру (дистанция зависит от скорости)
/// звучит «Через N метров …», у самой точки — «Сейчас …» / «Вы прибыли».
/// Очередь сбрасывается при смене маршрута и по окончании поездки.
/// </summary>
public static class VoiceNavigator
{
    private static readonly List<RouteStep> _steps = new();
    private static int _next;
    private static bool _preAnnounced;
    private static string? _routeKey;
    private static CancellationTokenSource? _speechCts;
    private static Locale? _russian;
    private static bool _localeResolved;

    /// Озвучка включена (настройка сохраняется между запусками).
    public static bool Enabled
    {
        get => Preferences.Get("voice_nav", true);
        set
        {
            Preferences.Set("voice_nav", value);
            if (!value) StopSpeaking();
        }
    }

    /// Есть ли ещё непроехавшие манёвры.
    public static bool HasRoute => _next < _steps.Count;

    /// Новая очередь манёвров. Повтор с тем же ключом игнорируется —
    /// фоновые обновления позиции не сбрасывают уже сказанное.
    public static void SetRoute(IEnumerable<RouteStep>? steps, string? routeKey = null)
    {
        if (routeKey != null && routeKey == _routeKey) return;
        _routeKey = routeKey;

        _steps.Clear();
        _next = 0;
        _preAnnounced = false;
        if (steps != null)
        {
            foreach (var s in steps)
            {
                if (s.Lat != 0 && s.Lng != 0 && s.Type != "")
                    _steps.Add(s);
            }
        }
    }

    /// Полный сброс (заказ завершён/отменён, карта скрыта).
    public static void Clear()
    {
        _routeKey = null;
        SetRoute(null);
        StopSpeaking();
    }

    /// Произвольная фраза (подтверждение включения озвучки и т.п.).
    public static void SpeakText(string text)
    {
        if (string.IsNullOrWhiteSpace(text)) return;
        _ = SpeakInternalAsync(text);
    }

    /// Очередная точка GPS: решаем, что озвучить.
    public static void OnPosition(double lat, double lng, double? speedMps)
    {
        if (!Enabled || !HasRoute) return;
        try
        {
            var step = _steps[_next];
            var dist = HaversineM(lat, lng, step.Lat, step.Lng);

            // Предупреждаем за ~8 секунд хода, но не ближе 120 м и не дальше 400 м
            var approach = Math.Clamp((speedMps ?? 5.5) * 8.0, 120, 400);

            if (dist <= 35)
            {
                _next++;
                _preAnnounced = false;
                Speak(step.Type == "arrive"
                    ? "Вы прибыли в пункт назначения"
                    : ImmediatePrompt(step));
            }
            else if (!_preAnnounced && dist <= approach)
            {
                _preAnnounced = true;
                Speak($"Через {FormatDistance(dist)} {AdvancePrompt(step)}");
            }
        }
        catch { }
    }

    // ── Формулировки ─────────────────────────────────────────────────────

    private static string ImmediatePrompt(RouteStep s) => s.Type switch
    {
        "depart" => WithStreet("начните движение", s),
        "roundabout" or "rotary" => $"Сейчас на круговом движении {ExitPrompt(s)}",
        _ => TurnAction(s) is { } a ? $"Сейчас {a}" : "Следуйте по маршруту",
    };

    private static string AdvancePrompt(RouteStep s) => s.Type switch
    {
        "depart" => WithStreet("начните движение", s),
        "arrive" => s.Modifier == "left" ? "пункт назначения слева"
            : s.Modifier == "right" ? "пункт назначения справа"
            : "пункт назначения",
        "roundabout" or "rotary" => $"круговое движение, {ExitPrompt(s)}",
        _ => TurnAction(s) ?? "следуйте по маршруту",
    };

    private static string? TurnAction(RouteStep s)
    {
        if (s.Modifier == "uturn") return "развернитесь";
        var mod = Direction(s.Modifier);
        return s.Type switch
        {
            "turn" => mod switch
            {
                "прямо" => "двигайтесь прямо",
                "" => WithStreet("продолжайте движение", s),
                _ => WithStreet($"поверните {mod}", s),
            },
            "new name" => WithStreet("продолжайте движение", s),
            "continue" => "продолжайте движение",
            "end of road" => WithStreet($"в конце дороги поверните {mod}", s),
            "fork" => mod.Contains("лев") ? "держитесь левее"
                : mod.Contains("прав") ? "держитесь правее" : "держитесь направления",
            "merge" => $"вливайтесь в поток {mod}".TrimEnd(),
            "on ramp" => $"двигайтесь на съезд {mod}".TrimEnd(),
            "off ramp" => $"съезжайте {mod}".TrimEnd(),
            "roundabout turn" or "exit roundabout" => $"поверните {mod}".TrimEnd(),
            "notification" or "arrive" => null,
            _ => mod == "" ? "следуйте по маршруту" : $"двигайтесь {mod}",
        };
    }

    /// Название улицы без склонения — нейтральное «, далее — улица …»,
    /// TTS произносит тире как паузу (OSM хранит имена в именительном падеже).
    private static string WithStreet(string action, RouteStep s)
    {
        var name = (s.Name ?? "").Trim();
        return name == "" ? action : $"{action}, далее — {name}";
    }

    private static string Direction(string modifier) => modifier switch
    {
        "left" => "налево",
        "right" => "направо",
        "slight left" => "плавно налево",
        "slight right" => "плавно направо",
        "sharp left" => "резко налево",
        "sharp right" => "резко направо",
        "straight" => "прямо",
        _ => "",
    };

    private static readonly string[] Ordinals =
    {
        "", "первый", "второй", "третий", "четвёртый", "пятый",
        "шестой", "седьмой", "восьмой", "девятый",
    };

    private static string ExitPrompt(RouteStep s)
    {
        var exit = s.Exit ?? 0;
        return exit is >= 1 and <= 9 ? $"{Ordinals[exit]} выход" : "нужный выход";
    }

    // ── Форматирование расстояний ────────────────────────────────────────

    private static string FormatDistance(double meters)
    {
        if (meters < 950)
        {
            var rounded = Math.Max(50, (int)(Math.Round(meters / 50.0) * 50));
            return $"{rounded} {Plural(rounded, "метр", "метра", "метров")}";
        }
        var km = meters / 1000.0;
        return string.Format(System.Globalization.CultureInfo.GetCultureInfo("ru-RU"),
            "{0:F1} километра", km);
    }

    private static string Plural(int n, string one, string few, string many)
    {
        var mod100 = n % 100;
        if (mod100 is >= 11 and <= 14) return many;
        return (n % 10) switch
        {
            1 => one,
            2 or 3 or 4 => few,
            _ => many,
        };
    }

    // ── Синтез речи ──────────────────────────────────────────────────────

    private static void Speak(string text)
    {
        if (Enabled) _ = SpeakInternalAsync(text);
    }

    private static async Task SpeakInternalAsync(string text)
    {
        try
        {
            _speechCts?.Cancel();
            var cts = _speechCts = new CancellationTokenSource();
            var options = await TtsOptionsAsync();
            await TextToSpeech.Default.SpeakAsync(text, options, cts.Token);
        }
        catch (OperationCanceledException) { }
        catch { /* TTS недоступен — молча продолжаем без голоса */ }
    }

    private static void StopSpeaking()
    {
        try { _speechCts?.Cancel(); } catch { }
    }

    private static async Task<SpeechOptions> TtsOptionsAsync()
    {
        if (!_localeResolved)
        {
            _localeResolved = true;
            try
            {
                var locales = await TextToSpeech.Default.GetLocalesAsync();
                _russian = locales?.FirstOrDefault(l =>
                    l.Language?.StartsWith("ru", StringComparison.OrdinalIgnoreCase) == true);
            }
            catch { }
        }
        return new SpeechOptions { Locale = _russian, Volume = 1.0f, Pitch = 1.0f };
    }

    // ── Геометрия ────────────────────────────────────────────────────────

    private static double HaversineM(double lat1, double lng1, double lat2, double lng2)
    {
        const double r = 6371000.0;
        var dLat = Deg(lat2 - lat1);
        var dLng = Deg(lng2 - lng1);
        var a = Math.Sin(dLat / 2) * Math.Sin(dLat / 2)
            + Math.Cos(Deg(lat1)) * Math.Cos(Deg(lat2))
            * Math.Sin(dLng / 2) * Math.Sin(dLng / 2);
        return 2 * r * Math.Atan2(Math.Sqrt(a), Math.Sqrt(1 - a));

        static double Deg(double v) => v * Math.PI / 180.0;
    }
}
