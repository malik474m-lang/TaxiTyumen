using System.Net;
using System.Net.Sockets;
using System.Text;

namespace TaxiDriver.Services;

/// <summary>
/// Крошечный HTTP-сервер на 127.0.0.1 — отдаёт файлы карты из кеша приложения.
///
/// Зачем: Android 10+ запрещает WebView загружать скрипты с file://
/// (AllowFileAccessFromFileURLs игнорируется), из-за этого MapLibre
/// не загружался и карта оставалась тёмной. По http://127.0.0.1 страница
/// получает нормальный источник: работают и скрипты, и IndexedDB,
/// где кешируются офлайн-тайлы.
/// </summary>
public static class LocalWebServer
{
    private static TcpListener? _listener;
    private static string _root = string.Empty;

    public static int Port { get; private set; }
    public static bool IsRunning => _listener != null;

    /// Текущее состояние карты (маршрут + позиция водителя).
    /// Страница карты забирает его через /state.json — это надёжнее, чем
    /// EvaluateJavaScriptAsync: тот молча не срабатывал на части устройств,
    /// из-за чего маршрут и маркер не появлялись.
    private static volatile string _state = "{}";

    public static void SetState(string json) => _state = json ?? "{}";

    /// URL страницы карты (сервер стартует при первом обращении).
    public static string Start(string rootDirectory)
    {
        if (_listener != null)
            return $"http://127.0.0.1:{Port}/map.html";

        _root = rootDirectory;
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
            catch { break; }   // слушатель остановлен
            _ = Task.Run(() => HandleAsync(client));
        }
    }

    private static async Task HandleAsync(TcpClient client)
    {
        try
        {
            using (client)
            {
                client.ReceiveTimeout = 5000;
                client.SendTimeout = 5000;
                using var stream = client.GetStream();

                // Читаем заголовок запроса
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

                // Состояние карты отдаём из памяти, без файлов
                if (path == "state.json")
                {
                    await WriteAsync(stream, 200, "application/json; charset=utf-8", _state);
                    return;
                }

                // Защита от выхода за пределы каталога
                var root = Path.GetFullPath(_root);
                var file = Path.GetFullPath(Path.Combine(_root, path));
                if (!file.StartsWith(root, StringComparison.Ordinal))
                {
                    await WriteAsync(stream, 403, "text/plain; charset=utf-8", "Forbidden");
                    return;
                }

                if (!File.Exists(file))
                {
                    await WriteAsync(stream, 404, "text/plain; charset=utf-8", "Not found: " + path);
                    return;
                }

                var bytes = await File.ReadAllBytesAsync(file);
                await WriteAsync(stream, 200, Mime(file), bytes);
            }
        }
        catch { /* разрыв соединения — игнорируем */ }
    }

    private static async Task WriteAsync(NetworkStream stream, int code, string mime, byte[] body)
    {
        var header = $"HTTP/1.1 {code} {(code == 200 ? "OK" : "Error")}\r\n"
            + $"Content-Type: {mime}\r\n"
            + $"Content-Length: {body.Length}\r\n"
            + "Cache-Control: no-store\r\n"
            + "Connection: close\r\n\r\n";
        var head = Encoding.ASCII.GetBytes(header);
        await stream.WriteAsync(head);
        await stream.WriteAsync(body);
        await stream.FlushAsync();
    }

    private static async Task WriteAsync(NetworkStream stream, int code, string mime, string text)
        => await WriteAsync(stream, code, mime, Encoding.UTF8.GetBytes(text));

    private static string Mime(string file) => Path.GetExtension(file).ToLowerInvariant() switch
    {
        ".html" => "text/html; charset=utf-8",
        ".js"   => "application/javascript; charset=utf-8",
        ".css"  => "text/css; charset=utf-8",
        ".json" => "application/json; charset=utf-8",
        ".png"  => "image/png",
        ".jpg" or ".jpeg" => "image/jpeg",
        ".pbf"  => "application/x-protobuf",
        _       => "application/octet-stream",
    };
}
