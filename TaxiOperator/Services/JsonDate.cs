using System.Globalization;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace TaxiOperator.Services;

/// Метки API сервера: ISO 8601 («2026-08-30T06:04:03.300Z» либо без смещения)
/// и MySQL-формат («2026-08-30 06:04:03») — парсим независимо от культуры.
public class FlexibleDateTimeOffsetConverter : JsonConverter<DateTimeOffset>
{
    public override DateTimeOffset Read(ref Utf8JsonReader reader, Type typeToConvert, JsonSerializerOptions options)
    {
        var v = reader.GetString();
        if (string.IsNullOrWhiteSpace(v)) return default;

        if (DateTimeOffset.TryParse(v, CultureInfo.InvariantCulture,
                DateTimeStyles.AssumeUniversal | DateTimeStyles.AdjustToUniversal, out var dto))
            return dto;
        if (DateTimeOffset.TryParseExact(v, "yyyy-MM-dd HH:mm:ss.fff", CultureInfo.InvariantCulture,
                DateTimeStyles.AssumeUniversal | DateTimeStyles.AdjustToUniversal, out dto))
            return dto;
        if (DateTimeOffset.TryParseExact(v, "yyyy-MM-dd HH:mm:ss", CultureInfo.InvariantCulture,
                DateTimeStyles.AssumeUniversal | DateTimeStyles.AdjustToUniversal, out dto))
            return dto;
        return default; // без падения приложения, если формат изменится
    }

    public override void Write(Utf8JsonWriter writer, DateTimeOffset value, JsonSerializerOptions options)
        => writer.WriteStringValue(value.ToUniversalTime().ToString("yyyy-MM-dd'T'HH:mm:ss.fff'Z'"));
}

public class FlexibleNullableDateTimeOffsetConverter : JsonConverter<DateTimeOffset?>
{
    private static readonly FlexibleDateTimeOffsetConverter Inner = new();

    public override DateTimeOffset? Read(ref Utf8JsonReader reader, Type typeToConvert, JsonSerializerOptions options)
        => reader.TokenType == JsonTokenType.Null ? null : Inner.Read(ref reader, typeToConvert, options);

    public override void Write(Utf8JsonWriter writer, DateTimeOffset? value, JsonSerializerOptions options)
    {
        if (value.HasValue) writer.WriteStringValue(
            value.Value.ToUniversalTime().ToString("yyyy-MM-dd'T'HH:mm:ss.fff'Z'"));
        else writer.WriteNullValue();
    }
}
