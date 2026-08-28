using Microsoft.AspNetCore.Mvc;
using TaxiService.Core.DTOs.Auth;
using TaxiService.Core.Interfaces;

namespace TaxiService.API.Controllers;

[ApiController]
[Route("api/[controller]")]
public class AuthController : ControllerBase
{
    private readonly IAuthService _authService;
    private readonly ILogger<AuthController> _logger;

    public AuthController(IAuthService authService, ILogger<AuthController> logger)
    {
        _authService = authService;
        _logger = logger;
    }

    /// <summary>Регистрация нового пользователя</summary>
    [HttpPost("register")]
    public async Task<ActionResult<AuthResponse>> Register([FromBody] RegisterRequest request)
    {
        try
        {
            var response = await _authService.RegisterAsync(request);
            _logger.LogInformation("Новый пользователь: {Phone}, роль: {Role}",
                request.Phone, request.Role);
            return Ok(response);
        }
        catch (InvalidOperationException ex)
        {
            return BadRequest(new { message = ex.Message });
        }
    }

    /// <summary>Авторизация по номеру телефона и паролю</summary>
    [HttpPost("login")]
    public async Task<ActionResult<AuthResponse>> Login([FromBody] LoginRequest request)
    {
        try
        {
            var response = await _authService.LoginAsync(request);
            _logger.LogInformation("Вход: {Phone}", request.Phone);
            return Ok(response);
        }
        catch (InvalidOperationException ex)
        {
            return Unauthorized(new { message = ex.Message });
        }
    }

    /// <summary>Обновление токена</summary>
    [HttpPost("refresh")]
    public async Task<ActionResult<AuthResponse>> RefreshToken([FromBody] RefreshTokenRequest request)
    {
        try
        {
            var response = await _authService.RefreshTokenAsync(request);
            return Ok(response);
        }
        catch (InvalidOperationException ex)
        {
            return Unauthorized(new { message = ex.Message });
        }
    }

    /// <summary>Отправить SMS-код подтверждения</summary>
    [HttpPost("send-sms")]
    public async Task<ActionResult> SendSmsCode([FromBody] string phone)
    {
        try
        {
            await _authService.SendSmsCodeAsync(phone);
            return Ok(new { message = "Код отправлен" });
        }
        catch (InvalidOperationException ex)
        {
            return BadRequest(new { message = ex.Message });
        }
    }

    /// <summary>Подтвердить SMS-код</summary>
    [HttpPost("verify-sms")]
    public async Task<ActionResult> VerifySms([FromBody] VerifySmsRequest request)
    {
        try
        {
            var result = await _authService.VerifySmsCodeAsync(request);
            return result
                ? Ok(new { message = "Телефон подтверждён" })
                : BadRequest(new { message = "Неверный код" });
        }
        catch (InvalidOperationException ex)
        {
            return BadRequest(new { message = ex.Message });
        }
    }
}
