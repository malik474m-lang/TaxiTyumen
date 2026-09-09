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
    private static readonly SemaphoreSlim TileGate = new(16, 16);

    public static int Port { get; private set; }
    public static bool IsRunning => _listener != null;

    public static void SetState(string json) => _state = json ?? "{}";

    private static HttpClient CreateTileHttp()
    {
        var http = new HttpClient { Timeout = TimeSpan.FromSeconds(20) };
        http.DefaultRequestHeaders.UserAgent.Add(
            new ProductInfoHeaderValue("TaxiTyumen-Driver", "3.6"));
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
        NetworkStream stream, int code, string mime, byte[] body, bool noStore)
    {
        var header = $"HTTP/1.1 {code} {(code == 200 ? "OK" : "Error")}\r\n"
            + $"Content-Type: {mime}\r\nContent-Length: {body.Length}\r\n"
            + (noStore ? "Cache-Control: no-store\r\n" : "Cache-Control: public, max-age=604800\r\n")
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
