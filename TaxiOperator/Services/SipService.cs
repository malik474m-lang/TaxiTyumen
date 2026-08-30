using System.Net;

namespace TaxiOperator.Services;

/// SIP-софтфон оператора: регистрация на АТС, приём и совершение звонков
/// через гарнитуру ПК (выбор микрофона и наушников в настройках).
///
/// Сборка без телефонии: dotnet publish -p:DisableSip=true — класс остаётся,
/// но становится заглушкой (панель софтфона показывает «SIP отключён»).
public sealed class SipService : IDisposable
{
    public event Action<string>? StatusChanged;      // текст состояния для UI
    public event Action<string>? IncomingCall;       // номер звонящего
    public event Action<string>? CallConnected;      // разговор начался
    public event Action? CallEnded;

    public bool IsAvailable { get; }
    public bool IsRegistered { get; private set; }
    public bool HasActiveCall { get; private set; }
    public bool IsMuted { get; private set; }
    public string? CurrentNumber { get; private set; }

    private readonly SipSettings _settings;

    public SipService(SipSettings settings)
    {
        _settings = settings;
#if SIP_ENABLED
        IsAvailable = true;
#else
        IsAvailable = false;
#endif
    }

    private void Status(string text) => StatusChanged?.Invoke(text);

#if SIP_ENABLED
    private SIPSorcery.SIP.SIPTransport? _transport;
    private SIPSorcery.SIP.App.SIPUserAgent? _userAgent;
    private SIPSorcery.SIP.App.SIPRegistrationUserAgent? _registration;
    private SIPSorceryMedia.Windows.WindowsAudioEndPoint? _audio;
    private SIPSorcery.SIP.App.SIPServerUserAgent? _pendingCall;

    public async Task StartAsync()
    {
        if (!_settings.Enabled || string.IsNullOrWhiteSpace(_settings.Server) ||
            string.IsNullOrWhiteSpace(_settings.Username))
        {
            Status("SIP не настроен");
            return;
        }

        try
        {
            Stop();

            _transport = new SIPSorcery.SIP.SIPTransport();
            _transport.AddSIPChannel(new SIPSorcery.SIP.SIPUDPChannel(new IPEndPoint(IPAddress.Any, 0)));

            _userAgent = new SIPSorcery.SIP.App.SIPUserAgent(_transport, null);
            _userAgent.OnIncomingCall += HandleIncomingCall;
            _userAgent.OnCallHungup += _ =>
            {
                HasActiveCall = false;
                CurrentNumber = null;
                Status(IsRegistered ? "Готов к приёму звонков" : "Не зарегистрирован");
                CallEnded?.Invoke();
            };
            _userAgent.ClientCallFailed += (_, error, _) =>
            {
                HasActiveCall = false;
                Status("Вызов не состоялся: " + error);
                CallEnded?.Invoke();
            };
            _userAgent.ClientCallAnswered += (_, _) =>
            {
                HasActiveCall = true;
                Status("Разговор" + (CurrentNumber != null ? " · " + CurrentNumber : ""));
                CallConnected?.Invoke(CurrentNumber ?? "");
            };

            _registration = new SIPSorcery.SIP.App.SIPRegistrationUserAgent(
                _transport, _settings.Username, _settings.Password, _settings.Server,
                _settings.ExpirySeconds);

            _registration.RegistrationSuccessful += (_, _) =>
            {
                IsRegistered = true;
                Status($"На линии · {_settings.Username}@{_settings.Server}");
            };
            _registration.RegistrationFailed += (_, _, err) =>
            {
                IsRegistered = false;
                Status("Ошибка регистрации: " + err);
            };
            _registration.RegistrationTemporaryFailure += (_, _, err) =>
            {
                IsRegistered = false;
                Status("Повтор регистрации: " + err);
            };
            _registration.RegistrationRemoved += (_, _) =>
            {
                IsRegistered = false;
                Status("Регистрация снята");
            };

            Status("Регистрация на АТС…");
            _registration.Start();
            await Task.CompletedTask;
        }
        catch (Exception ex)
        {
            Status("SIP недоступен: " + ex.Message);
        }
    }

    private SIPSorcery.Media.VoIPMediaSession CreateMediaSession()
    {
        _audio = new SIPSorceryMedia.Windows.WindowsAudioEndPoint(
            new SIPSorcery.Media.AudioEncoder(),
            _settings.OutputDeviceIndex,
            _settings.InputDeviceIndex);

        var endPoints = new SIPSorceryMedia.Abstractions.MediaEndPoints
        {
            AudioSink = _audio,
            AudioSource = _audio
        };
        var session = new SIPSorcery.Media.VoIPMediaSession(endPoints);
        session.AcceptRtpFromAny = true;
        return session;
    }

    private void HandleIncomingCall(SIPSorcery.SIP.App.SIPUserAgent ua, SIPSorcery.SIP.SIPRequest request)
    {
        try
        {
            var from = request.Header?.From?.FromURI?.User ?? "неизвестный";
            CurrentNumber = from;
            _pendingCall = _userAgent?.AcceptCall(request);
            Status("Входящий звонок · " + from);
            IncomingCall?.Invoke(from);

            if (_settings.AutoAnswer) _ = AnswerAsync();
        }
        catch (Exception ex)
        {
            Status("Ошибка входящего: " + ex.Message);
        }
    }

    public async Task AnswerAsync()
    {
        if (_userAgent == null || _pendingCall == null) return;
        try
        {
            var session = CreateMediaSession();
            var answered = await _userAgent.Answer(_pendingCall, session);
            _pendingCall = null;
            HasActiveCall = answered;
            if (answered)
            {
                Status("Разговор" + (CurrentNumber != null ? " · " + CurrentNumber : ""));
                CallConnected?.Invoke(CurrentNumber ?? "");
            }
        }
        catch (Exception ex)
        {
            Status("Не удалось ответить: " + ex.Message);
        }
    }

    public async Task<bool> CallAsync(string number)
    {
        if (_userAgent == null || string.IsNullOrWhiteSpace(number)) return false;
        try
        {
            CurrentNumber = number;
            Status("Набор · " + number);
            var session = CreateMediaSession();
            var destination = number.Contains('@') ? number : $"sip:{number}@{_settings.Server}";
            var ok = await _userAgent.Call(destination, _settings.Username, _settings.Password, session);
            if (!ok) Status("Вызов не удался");
            return ok;
        }
        catch (Exception ex)
        {
            Status("Ошибка вызова: " + ex.Message);
            return false;
        }
    }

    public void Hangup()
    {
        try
        {
            if (_pendingCall != null)
            {
                _pendingCall.Reject(SIPSorcery.SIP.SIPResponseStatusCodesEnum.BusyHere, null, null);
                _pendingCall = null;
            }
            _userAgent?.Hangup();
        }
        catch { }
        HasActiveCall = false;
        CurrentNumber = null;
        Status(IsRegistered ? "Готов к приёму звонков" : "Не зарегистрирован");
        CallEnded?.Invoke();
    }

    public void SetMute(bool mute)
    {
        IsMuted = mute;
        try
        {
            if (_audio == null) return;
            if (mute) _ = _audio.PauseAudio();
            else _ = _audio.ResumeAudio();
        }
        catch { }
        Status(mute ? "Микрофон выключен" : "Разговор" + (CurrentNumber != null ? " · " + CurrentNumber : ""));
    }

    public void SendDtmf(char tone)
    {
        try
        {
            if (byte.TryParse(tone.ToString(), out var digit)) _ = _userAgent?.SendDtmf(digit);
        }
        catch { }
    }

    /// Микрофоны, доступные Windows (для выбора гарнитуры)
    public static List<(int Index, string Name)> GetInputDevices()
    {
        var list = new List<(int, string)> { (-1, "По умолчанию (Windows)") };
        try
        {
            for (int i = 0; i < NAudio.Wave.WaveIn.DeviceCount; i++)
                list.Add((i, NAudio.Wave.WaveIn.GetCapabilities(i).ProductName));
        }
        catch { }
        return list;
    }

    /// Наушники/динамики, доступные Windows
    public static List<(int Index, string Name)> GetOutputDevices()
    {
        var list = new List<(int, string)> { (-1, "По умолчанию (Windows)") };
        try
        {
            for (int i = 0; i < NAudio.Wave.WaveOut.DeviceCount; i++)
                list.Add((i, NAudio.Wave.WaveOut.GetCapabilities(i).ProductName));
        }
        catch { }
        return list;
    }

    public void Stop()
    {
        try { _registration?.Stop(); } catch { }
        try { _userAgent?.Hangup(); } catch { }
        try { _transport?.Shutdown(); } catch { }
        _registration = null;
        _userAgent = null;
        _transport = null;
        _audio = null;
        IsRegistered = false;
        HasActiveCall = false;
    }
#else
    // Сборка без телефонии (-p:DisableSip=true)
    public Task StartAsync()
    {
        Status("SIP-телефония отключена в этой сборке");
        return Task.CompletedTask;
    }

    public Task AnswerAsync() => Task.CompletedTask;
    public Task<bool> CallAsync(string number) => Task.FromResult(false);
    public void Hangup() { }
    public void SetMute(bool mute) => IsMuted = mute;
    public void SendDtmf(char tone) { }
    public static List<(int Index, string Name)> GetInputDevices() => new() { (-1, "Недоступно") };
    public static List<(int Index, string Name)> GetOutputDevices() => new() { (-1, "Недоступно") };
    public void Stop() { }
#endif

    public void Dispose() => Stop();
}
