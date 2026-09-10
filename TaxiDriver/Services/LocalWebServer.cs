using System.Net;
using System.Net.Http.Headers;
using System.Net.Sockets;
using System.Text;

namespace TaxiDriver.Services;

/// <summary>
/// Локальный HTTP-сервер карты на 127.0.0.1.
/// Отдаёт ассеты MapLibre, актуальное состояние маршрута и файловый кеш тайлов.
/// Кеш реализован на C#, а не в JavaScript/IndexedDB — поэтому кнопка скачивания
/// работает детерминированно и показывает нативный прогресс.
/// </summary>
public static class LocalWebServer
{
    private static TcpListener? _listener;
    private static string _root = string.Empty;
    private static string _tileRoot = string.Empty;
    private static volatile string _state = "{}";
    private static readonly HttpClient TileHttp = CreateTileHttp();
    private static readonly HttpClient TtHttp = CreateTtHttp();
    private static readonly SemaphoreSlim TileGate = new(16, 16);

    // ── Слои TomTom: тайлы ходят ЧЕРЕЗ ЭТОТ сервер ─────────────────────────
    // У ключа TomTom могут стоять referer-ограничения (MyTomTom → Keys): прямые
    // запросы из WebView пришли бы с origin http://127.0.0.1 и были бы отклонены
    // («Request contains an invalid Referer header»). Через локальный прокси
    // добавляем правильный Referer домена + файловый кеш с коротким TTL:
    // картинка пробок освежается, а лимит тайлов тратится экономно.
    private static readonly Dictionary<string, string> TtTemplates = new();

    // ── Офлайн-пакет векторных тайлов (OpenStreetMap → PMTiles) ────────────
    // Файл лежит внутри приложения: карта Тюмени и Тюменского района работает
    // полностью без интернета и без внешних сервисов (ODbL, без лимитов).
    private static PmTilesArchive? _vector;

    /// Подключить пакет карты. false — файла нет, остаёмся на растровых тайлах.
    public static bool SetVectorPack(string path)
    {
        try
        {
            var archive = PmTilesArchive.Open(path);
            if (archive == null) return false;
            var previous = _vector;
            _vector = archive;
            previous?.Dispose();
            return true;
        }
        catch { return false; }
    }

    public static bool VectorReady => _vector != null;

    /// Описание пакета для карты: покрытие, зумы, готовность.
    private static string MapInfoJson()
    {
        var v = _vector;
        if (v == null) return "{\"vector\":false}";
        var ci = System.Globalization.CultureInfo.InvariantCulture;
        return "{\"vector\":true,\"minzoom\":" + v.MinZoom
            + ",\"maxzoom\":" + v.MaxZoom
            + ",\"bounds\":[" + v.MinLon.ToString("F6", ci) + "," + v.MinLat.ToString("F6", ci)
            + "," + v.MaxLon.ToString("F6", ci) + "," + v.MaxLat.ToString("F6", ci) + "]"
            + ",\"sizeMb\":" + (v.FileSizeBytes / 1048576.0).ToString("F1", ci) + "}";
    }

    private static HttpClient CreateTtHttp()
    {
        var http = new HttpClient { Timeout = TimeSpan.FromSeconds(20) };
        http.DefaultRequestHeaders.UserAgent.ParseAdd(
            "TaxiTyumen-Driver/4.0 (+https://taxi.event72.ru)");
        http.DefaultRequestHeaders.Referrer = new Uri("https://taxi.event72.ru/");
        return http;
    }

    /// Шаблон тайлов слоя TomTom (с ключом) из map-config; null — слой выключен.
    public static void SetTomTomTile(string layer, string? templateUrl)
    {
        lock (TtTemplates)
        {
            if (string.IsNullOrEmpty(templateUrl)) TtTemplates.Remove(layer);
            else TtTemplates[layer] = templateUrl;
        }
    }

    private static string? TomTomTemplate(string layer)
    {
        lock (TtTemplates) return TtTemplates.TryGetValue(layer, out var t) ? t : null;
    }

    /// Локальный шаблон слоя для WebView: 127.0.0.1 — безопасно для referer-проверок.
    public static string? TomTomLocalUrl(string layer)
        => TomTomTemplate(layer) == null || !IsRunning ? null
            : $"http://127.0.0.1:{Port}/tt/{layer}/{{z}}/{{x}}/{{y}}.png";

    public static int Port { get; private set; }
    public static bool IsRunning => _listener != null;

    public static void SetState(string json) => _state = json ?? "{}";

    private static HttpClient CreateTileHttp()
    {
        var http = new HttpClient { Timeout = TimeSpan.FromSeconds(20) };
        http.DefaultRequestHeaders.UserAgent.Add(
            new ProductInfoHeaderValue("TaxiTyumen-Driver", "4.0"));
        http.DefaultRequestHeaders.UserAgent.Add(
            new ProductInfoHeaderValue("(+https://taxi.event72.ru)"));
        return http;
    }

    public static string Start(string rootDirectory)
    {
        if (_listener != null)
            return $"http://127.0.0.1:{Port}/map.html";

        _root = rootDirectory;
        _tileRoot = Path.Combine(FileSystem.AppDataDirectory, "offline-tiles");
        Directory.CreateDirectory(_tileRoot);

        _listener = new TcpListener(IPAddress.Loopback, 0);
        _listener.Start();
        Port = ((IPEndPoint)_listener.LocalEndpoint).Port;
        _ = Task.Run(AcceptLoopAsync);
        return $"http://127.0.0.1:{Port}/map.html";
    }

    private static async Task AcceptLoopAsync()
    {
        while (_listener != null)
        {
            TcpClient client;
            try { client = await _listener.AcceptTcpClientAsync(); }
            catch { break; }
            _ = Task.Run(() => HandleAsync(client));
        }
    }

    private static async Task HandleAsync(TcpClient client)
    {
        try
        {
            using (client)
            {
                client.ReceiveTimeout = 10000;
                client.SendTimeout = 10000;
                using var stream = client.GetStream();

                var buffer = new byte[4096];
                var header = new StringBuilder();
                var total = 0;
                while (total < 8192)
                {
                    var read = await stream.ReadAsync(buffer.AsMemory(0, buffer.Length));
                    if (read == 0) break;
                    total += read;
                    header.Append(Encoding.ASCII.GetString(buffer, 0, read));
                    if (header.ToString().Contains("\r\n\r\n")) break;
                }

                var firstLine = header.ToString().Split('\n')[0].Split(' ');
                var rawPath = firstLine.Length > 1 ? firstLine[1] : "/";
                var path = Uri.UnescapeDataString(rawPath.Split('?')[0]).TrimStart('/');
                if (string.IsNullOrEmpty(path)) path = "map.html";

                if (path == "state.json")
                {
                    await WriteAsync(stream, 200, "application/json; charset=utf-8", _state, noStore: true);
                    return;
                }

                // Состав офлайн-пакета: карта опрашивает его, пока пакет
                // копируется из APK, и затем включает векторные слои
                if (path == "mapinfo.json")
                {
                    await WriteAsync(stream, 200, "application/json; charset=utf-8",
                        MapInfoJson(), noStore: true);
                    return;
                }

                // /vector/z/x/y.pbf — векторный тайл из офлайн-пакета PMTiles
                if (TryParseVectorTile(path, out var vz, out var vx, out var vy))
                {
                    var archive = _vector;
                    var tile = archive?.GetTile(vz, vx, vy);
                    if (tile != null && tile.Length > 0)
                    {
                        await WriteAsync(stream, 200, "application/x-protobuf", tile,
                            noStore: false, maxAgeSeconds: 604800,
                            contentEncoding: archive!.TileContentEncoding);
                    }
                    else
                    {
                        // 204 — «тайла нет» (за границей пакета): MapLibre
                        // считает его пустым и не показывает ошибку
                        await WriteAsync(stream, 204, "application/x-protobuf",
                            Array.Empty<byte>(), noStore: false);
                    }
                    return;
                }

                // /fonts/{fontstack}/{range}.pbf — шрифты подписей карты
                if (path.StartsWith("fonts/", StringComparison.Ordinal))
                {
                    var fontFile = Path.GetFullPath(Path.Combine(_root, path));
                    if (fontFile.StartsWith(Path.GetFullPath(_root), StringComparison.Ordinal)
                        && File.Exists(fontFile))
                    {
                        var fontBytes = await File.ReadAllBytesAsync(fontFile);
                        // Шрифты OpenMapTiles распространяются gzip-сжатыми
                        var gz = fontBytes.Length > 1 && fontBytes[0] == 0x1f && fontBytes[1] == 0x8b;
                        await WriteAsync(stream, 200, "application/x-protobuf", fontBytes,
                            noStore: false, maxAgeSeconds: 2592000,
                            contentEncoding: gz ? "gzip" : string.Empty);
                    }
                    else
                    {
                        await WriteAsync(stream, 404, "text/plain", "no font", true);
                    }
                    return;
                }

                // /tile/z/x/y.png — файловый кеш; при наличии сети докачиваем
                if (TryParseTile(path, out var z, out var x, out var y))
                {
                    var bytes = await GetTileAsync(z, x, y);
                    if (bytes != null)
                    {
                        await WriteAsync(stream, 200, "image/png", bytes, noStore: false);
                    }
                    else
                    {
                        // Раньше здесь был 404: MapLibre считал тайл битым и
                        // навсегда оставлял чёрный прямоугольник. Отдаём светлую
                        // заглушку без кеширования — при следующем панорамировании
                        // тайл будет запрошен снова и появится настоящая карта.
                        await WriteAsync(stream, 200, "image/png", PlaceholderTile(), noStore: true);
                    }
                    return;
                }

                // /tt/{layer}/{z}/{x}/{y}.png — проксированные тайлы TomTom с кешем
                if (TryParseTtTile(path, out var layer, out var tz, out var tx, out var ty))
                {
                    var ttBytes = await GetTtTileAsync(layer, tz, tx, ty);
                    if (ttBytes != null)
                    {
                        // Пробки/происшествия живут минуты — браузер держит их недолго;
                        // базовая карта TomTom — как обычные тайлы.
                        var ttAge = layer is "flow" or "incidents" ? 240 : 604800;
                        await WriteAsync(stream, 200, "image/png", ttBytes,
                            noStore: false, maxAgeSeconds: ttAge);
                    }
                    else
                    {
                        await WriteAsync(stream, 200, "image/png", PlaceholderTile(), noStore: true);
                    }
                    return;
                }

                var root = Path.GetFullPath(_root);
                var file = Path.GetFullPath(Path.Combine(_root, path));
                if (!file.StartsWith(root, StringComparison.Ordinal))
                {
                    await WriteAsync(stream, 403, "text/plain", "Forbidden", true);
                    return;
                }
                if (!File.Exists(file))
                {
                    await WriteAsync(stream, 404, "text/plain", "Not found: " + path, true);
                    return;
                }

                await WriteAsync(stream, 200, Mime(file), await File.ReadAllBytesAsync(file), true);
            }
        }
        catch { }
    }

    private static bool TryParseTile(string path, out int z, out int x, out int y)
    {
        z = x = y = 0;
        var p = path.Split('/');
        if (p.Length != 4 || p[0] != "tile") return false;
        return int.TryParse(p[1], out z)
            && int.TryParse(p[2], out x)
            && int.TryParse(Path.GetFileNameWithoutExtension(p[3]), out y)
            && z is >= 0 and <= 19 && x >= 0 && y >= 0;
    }

    private static bool TryParseVectorTile(string path, out int z, out int x, out int y)
    {
        z = x = y = 0;
        var p = path.Split('/');
        if (p.Length != 4 || p[0] != "vector") return false;
        return int.TryParse(p[1], out z)
            && int.TryParse(p[2], out x)
            && int.TryParse(Path.GetFileNameWithoutExtension(p[3]), out y)
            && z is >= 0 and <= 20 && x >= 0 && y >= 0;
    }

    private static bool TryParseTtTile(
        string path, out string layer, out int z, out int x, out int y)
    {
        layer = string.Empty; z = x = y = 0;
        var p = path.Split('/');
        if (p.Length != 5 || p[0] != "tt") return false;
        if (p[1] != "flow" && p[1] != "incidents" && p[1] != "map") return false;
        if (!int.TryParse(p[2], out z) || !int.TryParse(p[3], out x)
            || !int.TryParse(Path.GetFileNameWithoutExtension(p[4]), out y)) return false;
        if (z is < 0 or > 19 || x < 0 || y < 0) return false;
        layer = p[1];
        return true;
    }

    private static string TtPath(string layer, int z, int x, int y)
        => Path.Combine(_tileRoot, "tt-" + layer, z.ToString(), x.ToString(), y + ".png");

    /// Пробки/происшествия освежаются часто, статическая карта — редко.
    private static TimeSpan TtTtl(string layer)
        => layer is "flow" or "incidents" ? TimeSpan.FromMinutes(5) : TimeSpan.FromDays(30);

    /// Тайл TomTom: кеш с TTL → сеть (Referer домена) → устаревшая копия из кеша.
    public static async Task<byte[]?> GetTtTileAsync(
        string layer, int z, int x, int y, CancellationToken ct = default)
    {
        var template = TomTomTemplate(layer);
        if (template == null) return null;
        var file = TtPath(layer, z, x, y);
        try
        {
            if (File.Exists(file)
                && DateTime.UtcNow - File.GetLastWriteTimeUtc(file) < TtTtl(layer))
                return await File.ReadAllBytesAsync(file, ct);

            await TileGate.WaitAsync(ct);
            try
            {
                if (File.Exists(file)
                    && DateTime.UtcNow - File.GetLastWriteTimeUtc(file) < TtTtl(layer))
                    return await File.ReadAllBytesAsync(file, ct);

                var ci = System.Globalization.CultureInfo.InvariantCulture;
                var url = template
                    .Replace("{z}", z.ToString(ci))
                    .Replace("{x}", x.ToString(ci))
                    .Replace("{y}", y.ToString(ci));
                byte[]? bytes = null;
                for (var attempt = 0; attempt < 2 && bytes == null; attempt++)
                {
                    try
                    {
                        using var response = await TtHttp.GetAsync(url, ct);
                        if (!response.IsSuccessStatusCode)
                        {
                            await Task.Delay(200, ct);
                            continue;
                        }
                        var data = await response.Content.ReadAsByteArrayAsync(ct);
                        if (data.Length > 100) bytes = data;
                    }
                    catch (OperationCanceledException) { throw; }
                    catch { await Task.Delay(200, ct); }
                }
                if (bytes != null)
                {
                    Directory.CreateDirectory(Path.GetDirectoryName(file)!);
                    await File.WriteAllBytesAsync(file, bytes, ct);
                    return bytes;
                }
            }
            finally { TileGate.Release(); }

            // Сети нет или TomTom отказал: лучше устаревшая картинка, чем пустота
            if (File.Exists(file)) return await File.ReadAllBytesAsync(file, ct);
            return null;
        }
        catch { return null; }
    }

    private static string TilePath(int z, int x, int y)
        => Path.Combine(_tileRoot, z.ToString(), x.ToString(), y + ".png");

    /// Получить тайл из файла или сети, сохранив для офлайн-работы.
    public static async Task<byte[]?> GetTileAsync(int z, int x, int y, CancellationToken ct = default)
    {
        var file = TilePath(z, x, y);
        try
        {
            if (File.Exists(file)) return await File.ReadAllBytesAsync(file, ct);

            await TileGate.WaitAsync(ct);
            try
            {
                // Второй запрос мог скачать тот же тайл, пока мы ждали semaphore
                if (File.Exists(file)) return await File.ReadAllBytesAsync(file, ct);

                var url = $"https://tile.openstreetmap.org/{z}/{x}/{y}.png";
                byte[]? bytes = null;
                for (var attempt = 0; attempt < 2 && bytes == null; attempt++)
                {
                    try
                    {
                        using var response = await TileHttp.GetAsync(url, ct);
                        if (!response.IsSuccessStatusCode)
                        {
                            await Task.Delay(200, ct);
                            continue;
                        }
                        var data = await response.Content.ReadAsByteArrayAsync(ct);
                        if (data.Length > 100) bytes = data;
                    }
                    catch (OperationCanceledException) { throw; }
                    catch { await Task.Delay(200, ct); }
                }
                if (bytes == null) return null;

                Directory.CreateDirectory(Path.GetDirectoryName(file)!);
                await File.WriteAllBytesAsync(file, bytes, ct);
                return bytes;
            }
            finally { TileGate.Release(); }
        }
        catch { return null; }
    }

    private static byte[]? _placeholder;

    /// Светлый PNG 256×256 — показывается вместо ещё не загруженного тайла.
    private static byte[] PlaceholderTile()
    {
        if (_placeholder != null) return _placeholder;
        // Минимальный valid PNG (1×1, цвет фона карты), масштабируется движком
        _placeholder = Convert.FromBase64String(
            "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mPk6" +
            "e/5DwAGgwJ/lK3Q6wAAAABJRU5ErkJggg==");
        return _placeholder;
    }

    public sealed record DownloadProgress(int Done, int Total, int Saved, int Existing, int Failed);
    public sealed record DownloadResult(int Total, int Saved, int Existing, int Failed);

    /// <summary>
    /// Скачать тайлы Тюмени в файловый кеш. Прогресс нативный — он не зависит
    /// от JavaScript/WebView, поэтому нажатие всегда видно пользователю.
    /// </summary>
    public static async Task<DownloadResult> DownloadTyumenAsync(
        IProgress<DownloadProgress>? progress, CancellationToken ct = default)
    {
        if (string.IsNullOrEmpty(_tileRoot))
            _tileRoot = Path.Combine(FileSystem.AppDataDirectory, "offline-tiles");
        Directory.CreateDirectory(_tileRoot);

        var tiles = new List<(int Z, int X, int Y)>();
        const double minLat = 57.05, maxLat = 57.26, minLng = 65.36, maxLng = 65.75;
        for (var z = 11; z <= 15; z++)
        {
            var x0 = LonToX(minLng, z); var x1 = LonToX(maxLng, z);
            var y0 = LatToY(maxLat, z); var y1 = LatToY(minLat, z);
            for (var x = x0; x <= x1; x++)
                for (var y = y0; y <= y1; y++) tiles.Add((z, x, y));
        }

        var done = 0; var saved = 0; var existing = 0; var failed = 0;
        foreach (var tile in tiles)
        {
            ct.ThrowIfCancellationRequested();
            var file = TilePath(tile.Z, tile.X, tile.Y);
            if (File.Exists(file)) existing++;
            else if (await GetTileAsync(tile.Z, tile.X, tile.Y, ct) != null) saved++;
            else failed++;

            done++;
            if (done % 3 == 0 || done == tiles.Count)
                progress?.Report(new DownloadProgress(done, tiles.Count, saved, existing, failed));

            // Не создаём агрессивную нагрузку на публичный источник
            if (!File.Exists(file)) await Task.Delay(80, ct);
        }
        return new DownloadResult(tiles.Count, saved, existing, failed);
    }

    private static int LonToX(double lon, int z)
        => (int)Math.Floor((lon + 180.0) / 360.0 * (1 << z));

    private static int LatToY(double lat, int z)
    {
        var rad = lat * Math.PI / 180.0;
        return (int)Math.Floor((1 - Math.Log(Math.Tan(rad) + 1 / Math.Cos(rad)) / Math.PI)
            / 2 * (1 << z));
    }

    private static async Task WriteAsync(
        NetworkStream stream, int code, string mime, byte[] body, bool noStore,
        int maxAgeSeconds = 604800, string contentEncoding = "")
    {
        var status = code switch { 200 => "OK", 204 => "No Content", 404 => "Not Found", _ => "Error" };
        var header = $"HTTP/1.1 {code} {status}\r\n"
            + $"Content-Type: {mime}\r\nContent-Length: {body.Length}\r\n"
            // Векторные тайлы и шрифты хранятся сжатыми — отдаём как есть,
            // распаковкой занимается движок карты в WebView
            + (string.IsNullOrEmpty(contentEncoding) ? "" : $"Content-Encoding: {contentEncoding}\r\n")
            + (noStore ? "Cache-Control: no-store\r\n" : $"Cache-Control: public, max-age={maxAgeSeconds}\r\n")
            + "Access-Control-Allow-Origin: *\r\nConnection: close\r\n\r\n";
        await stream.WriteAsync(Encoding.ASCII.GetBytes(header));
        await stream.WriteAsync(body);
        await stream.FlushAsync();
    }

    private static Task WriteAsync(
        NetworkStream stream, int code, string mime, string text, bool noStore)
        => WriteAsync(stream, code, mime, Encoding.UTF8.GetBytes(text), noStore);

    private static string Mime(string file) => Path.GetExtension(file).ToLowerInvariant() switch
    {
        ".html" => "text/html; charset=utf-8",
        ".js" => "application/javascript; charset=utf-8",
        ".css" => "text/css; charset=utf-8",
        ".json" => "application/json; charset=utf-8",
        ".png" => "image/png",
        _ => "application/octet-stream",
    };
}
