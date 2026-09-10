using System.Buffers.Binary;
using System.Collections.Concurrent;
using System.IO.Compression;
using System.Text;
using Microsoft.Win32.SafeHandles;

namespace TaxiDriver.Services;

/// <summary>
/// Чтение офлайн-пакета векторных тайлов PMTiles v3 (спецификация protomaps).
///
/// Пакет — ОДИН файл внутри APK: карта Тюмени и Тюменского района, собранная
/// из данных OpenStreetMap (лицензия ODbL, коммерческое использование и офлайн
/// разрешены). Никаких сторонних сервисов и лимитов: тайлы читаются прямо
/// с диска устройства, поэтому карта работает полностью без интернета.
///
/// Формат: 127-байтовый заголовок → корневой каталог → метаданные →
/// листовые каталоги → данные тайлов. Каталоги — varint-таблицы, адресация
/// тайлов по кривой Гильберта. Реализация без внешних пакетов.
/// </summary>
public sealed class PmTilesArchive : IDisposable
{
    private const int HeaderSize = 127;

    private readonly SafeFileHandle _handle;
    private readonly long _rootOffset, _rootLength, _leafOffset, _tileDataOffset;
    private readonly byte _internalCompression, _tileCompression;

    // Каталоги маленькие и переиспользуются: держим в памяти (ограниченно)
    private readonly ConcurrentDictionary<long, Entry[]> _dirCache = new();

    public int MinZoom { get; }
    public int MaxZoom { get; }
    public double MinLon { get; }
    public double MinLat { get; }
    public double MaxLon { get; }
    public double MaxLat { get; }
    public long FileSizeBytes { get; }

    /// Значение заголовка Content-Encoding для тайлов (planetiler пишет gzip).
    public string TileContentEncoding => _tileCompression switch
    {
        2 => "gzip",
        3 => "br",
        _ => string.Empty,
    };

    private readonly struct Entry
    {
        public readonly long TileId, Offset, Length, RunLength;
        public Entry(long tileId, long offset, long length, long runLength)
        {
            TileId = tileId; Offset = offset; Length = length; RunLength = runLength;
        }
    }

    private PmTilesArchive(SafeFileHandle handle, byte[] header, long fileSize)
    {
        _handle = handle;
        FileSizeBytes = fileSize;

        _rootOffset = ReadI64(header, 8);
        _rootLength = ReadI64(header, 16);
        _leafOffset = ReadI64(header, 40);
        _tileDataOffset = ReadI64(header, 56);

        _internalCompression = header[0x61];
        _tileCompression = header[0x62];
        MinZoom = header[0x64];
        MaxZoom = header[0x65];

        MinLon = ReadI32(header, 0x66) / 1e7;
        MinLat = ReadI32(header, 0x6A) / 1e7;
        MaxLon = ReadI32(header, 0x6E) / 1e7;
        MaxLat = ReadI32(header, 0x72) / 1e7;
    }

    /// Открыть пакет. null — файла нет или он повреждён (работаем на растре).
    public static PmTilesArchive? Open(string path)
    {
        try
        {
            if (!File.Exists(path)) return null;
            var size = new FileInfo(path).Length;
            if (size < HeaderSize + 16) return null;

            var handle = File.OpenHandle(path, FileMode.Open, FileAccess.Read, FileShare.Read);
            var header = new byte[HeaderSize];
            if (!ReadExact(handle, header, 0))
            {
                handle.Dispose();
                return null;
            }
            // Магия "PMTiles" + версия 3
            if (Encoding.ASCII.GetString(header, 0, 7) != "PMTiles" || header[7] != 3)
            {
                handle.Dispose();
                return null;
            }
            return new PmTilesArchive(handle, header, size);
        }
        catch { return null; }
    }

    /// Тайл по координатам. null — тайла в пакете нет (за границами покрытия).
    public byte[]? GetTile(int z, int x, int y)
    {
        try
        {
            if (z < 0 || z > 24) return null;
            var max = 1L << z;
            if (x < 0 || y < 0 || x >= max || y >= max) return null;

            var tileId = ZxyToTileId(z, x, y);
            var dirOffset = _rootOffset;
            var dirLength = _rootLength;

            // Корневой каталог + до трёх уровней листовых каталогов
            for (var depth = 0; depth < 4; depth++)
            {
                var entries = ReadDirectory(dirOffset, dirLength);
                if (entries.Length == 0) return null;

                var found = Find(entries, tileId);
                if (found == null) return null;

                var entry = found.Value;
                if (entry.RunLength > 0)
                {
                    if (entry.Length <= 0 || entry.Length > 32 * 1024 * 1024) return null;
                    var data = new byte[entry.Length];
                    return ReadExact(_handle, data, _tileDataOffset + entry.Offset) ? data : null;
                }
                dirOffset = _leafOffset + entry.Offset;
                dirLength = entry.Length;
            }
            return null;
        }
        catch { return null; }
    }

    // ── Каталоги ────────────────────────────────────────────────────────────

    private Entry[] ReadDirectory(long offset, long length)
    {
        if (_dirCache.TryGetValue(offset, out var cached)) return cached;
        if (length <= 0 || length > 64 * 1024 * 1024) return Array.Empty<Entry>();

        var raw = new byte[length];
        if (!ReadExact(_handle, raw, offset)) return Array.Empty<Entry>();

        var buf = Decompress(raw, _internalCompression);
        var entries = ParseDirectory(buf);

        // Кеш каталогов ограничиваем: пакет города укладывается в десятки записей
        if (_dirCache.Count < 256) _dirCache[offset] = entries;
        return entries;
    }

    private static Entry[] ParseDirectory(byte[] buf)
    {
        var p = 0;
        var count = (int)ReadVarint(buf, ref p);
        if (count <= 0 || count > 5_000_000) return Array.Empty<Entry>();

        var ids = new long[count];
        var runs = new long[count];
        var lens = new long[count];
        var offs = new long[count];

        long lastId = 0;
        for (var i = 0; i < count; i++)
        {
            lastId += ReadVarint(buf, ref p);
            ids[i] = lastId;
        }
        for (var i = 0; i < count; i++) runs[i] = ReadVarint(buf, ref p);
        for (var i = 0; i < count; i++) lens[i] = ReadVarint(buf, ref p);
        for (var i = 0; i < count; i++)
        {
            var v = ReadVarint(buf, ref p);
            // 0 — тайл лежит сразу за предыдущим (сжатие смещений)
            offs[i] = v == 0 && i > 0 ? offs[i - 1] + lens[i - 1] : v - 1;
        }

        var entries = new Entry[count];
        for (var i = 0; i < count; i++) entries[i] = new Entry(ids[i], offs[i], lens[i], runs[i]);
        return entries;
    }

    /// Двоичный поиск записи, покрывающей tileId (учитывая RunLength).
    private static Entry? Find(Entry[] entries, long tileId)
    {
        int m = 0, n = entries.Length - 1;
        while (m <= n)
        {
            var k = (n + m) >> 1;
            var cmp = tileId - entries[k].TileId;
            if (cmp > 0) m = k + 1;
            else if (cmp < 0) n = k - 1;
            else return entries[k];
        }
        if (n >= 0)
        {
            if (entries[n].RunLength == 0) return entries[n];                       // листовой каталог
            if (tileId - entries[n].TileId < entries[n].RunLength) return entries[n]; // серия одинаковых тайлов
        }
        return null;
    }

    // ── Адресация тайлов: кривая Гильберта (как в спецификации v3) ──────────

    public static long ZxyToTileId(int z, int x, int y)
    {
        long acc = 0;
        for (var t = 0; t < z; t++) acc += (1L << t) * (1L << t);

        long tx = x, ty = y, d = 0;
        for (var s = (1L << z) / 2; s > 0; s /= 2)
        {
            var rx = (tx & s) > 0 ? 1L : 0L;
            var ry = (ty & s) > 0 ? 1L : 0L;
            d += s * s * ((3 * rx) ^ ry);

            // Поворот квадранта
            if (ry == 0)
            {
                if (rx == 1)
                {
                    tx = s - 1 - tx;
                    ty = s - 1 - ty;
                }
                (tx, ty) = (ty, tx);
            }
        }
        return acc + d;
    }

    // ── Утилиты ─────────────────────────────────────────────────────────────

    private static byte[] Decompress(byte[] data, byte compression) => compression switch
    {
        1 => data,                                   // без сжатия
        2 => GunzipOrRaw(data),                      // gzip (planetiler по умолчанию)
        3 => BrotliOrRaw(data),                      // brotli
        _ => GunzipOrRaw(data),                      // 0 — «неизвестно»: пробуем gzip
    };

    private static byte[] GunzipOrRaw(byte[] data)
    {
        try
        {
            if (data.Length < 2 || data[0] != 0x1f || data[1] != 0x8b) return data;
            using var input = new MemoryStream(data);
            using var gzip = new GZipStream(input, CompressionMode.Decompress);
            using var output = new MemoryStream();
            gzip.CopyTo(output);
            return output.ToArray();
        }
        catch { return data; }
    }

    private static byte[] BrotliOrRaw(byte[] data)
    {
        try
        {
            using var input = new MemoryStream(data);
            using var br = new BrotliStream(input, CompressionMode.Decompress);
            using var output = new MemoryStream();
            br.CopyTo(output);
            return output.ToArray();
        }
        catch { return data; }
    }

    private static bool ReadExact(SafeFileHandle handle, byte[] buffer, long offset)
    {
        var read = 0;
        while (read < buffer.Length)
        {
            var n = RandomAccess.Read(handle, buffer.AsSpan(read), offset + read);
            if (n <= 0) return false;
            read += n;
        }
        return true;
    }

    private static long ReadVarint(byte[] buf, ref int pos)
    {
        long result = 0;
        var shift = 0;
        while (pos < buf.Length && shift < 64)
        {
            var b = buf[pos++];
            result |= (long)(b & 0x7f) << shift;
            if ((b & 0x80) == 0) return result;
            shift += 7;
        }
        return result;
    }

    private static long ReadI64(byte[] b, int offset)
        => BinaryPrimitives.ReadInt64LittleEndian(b.AsSpan(offset, 8));

    private static int ReadI32(byte[] b, int offset)
        => BinaryPrimitives.ReadInt32LittleEndian(b.AsSpan(offset, 4));

    public void Dispose()
    {
        try { _handle.Dispose(); } catch { }
    }
}
