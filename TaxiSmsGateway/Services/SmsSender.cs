namespace TaxiSmsGateway.Services;

/// <summary>
/// Отправка SMS с SIM-карты устройства (Android SmsManager).
/// Длинные сообщения автоматически разбиваются на части.
/// </summary>
public static class SmsSender
{
    public static async Task<(bool Ok, string? Error)> SendAsync(string phone, string message)
    {
#if ANDROID
        try
        {
            var granted = await EnsurePermissionAsync();
            if (!granted) return (false, "Нет разрешения на отправку SMS");

            return await Task.Run(() =>
            {
                try
                {
                    var manager = global::Android.Telephony.SmsManager.Default;
                    if (manager == null) return (false, (string?)"SmsManager недоступен");

                    // Длинный текст (кириллица — 70 символов на часть) режем сами
                    var parts = manager.DivideMessage(message);
                    if (parts != null && parts.Count > 1)
                        manager.SendMultipartTextMessage(phone, null, parts, null, null);
                    else
                        manager.SendTextMessage(phone, null, message, null, null);

                    return (true, (string?)null);
                }
                catch (Exception ex)
                {
                    return (false, (string?)ex.Message);
                }
            });
        }
        catch (Exception ex)
        {
            return (false, ex.Message);
        }
#else
        await Task.CompletedTask;
        return (false, "Отправка SMS доступна только на Android");
#endif
    }

    /// <summary>Разрешение SEND_SMS запрашивается в рантайме (Android 6+).</summary>
    public static async Task<bool> EnsurePermissionAsync()
    {
#if ANDROID
        var status = await Permissions.CheckStatusAsync<SendSmsPermission>();
        if (status != PermissionStatus.Granted)
            status = await Permissions.RequestAsync<SendSmsPermission>();
        return status == PermissionStatus.Granted;
#else
        await Task.CompletedTask;
        return false;
#endif
    }
}

#if ANDROID
/// Разрешение на отправку SMS (в MAUI нет готового класса для SEND_SMS).
public sealed class SendSmsPermission : Permissions.BasePlatformPermission
{
    public override (string androidPermission, bool isRuntime)[] RequiredPermissions =>
        new[] { (global::Android.Manifest.Permission.SendSms, true) };
}
#endif
