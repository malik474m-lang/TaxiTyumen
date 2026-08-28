using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;
using TaxiService.Core.Interfaces;
using TaxiService.Domain.Entities;
using TaxiService.Infrastructure.Data;

namespace TaxiService.API.Controllers;

[ApiController]
[Route("api/[controller]")]
[Authorize]
public class ChatController : ControllerBase
{
    private readonly TaxiDbContext _db;
    private readonly ISignalRNotifier _notifier;

    public ChatController(TaxiDbContext db, ISignalRNotifier notifier)
    {
        _db = db;
        _notifier = notifier;
    }

    /// <summary>Отправить сообщение</summary>
    [HttpPost("send")]
    public async Task<ActionResult> SendMessage([FromBody] SendMessageRequest request)
    {
        var msg = new ChatMessage
        {
            OrderId = request.OrderId,
            SenderId = request.SenderId,
            SenderRole = request.SenderRole,
            Text = request.Text.Trim()
        };

        _db.ChatMessages.Add(msg);
        await _db.SaveChangesAsync();

        // Уведомляем через SignalR
        await _notifier.SendToGroupAsync(
            $"order-{request.OrderId}",
            "NewChatMessage",
            new
            {
                MessageId = msg.Id,
                OrderId = msg.OrderId,
                SenderId = msg.SenderId,
                SenderRole = msg.SenderRole,
                Text = msg.Text,
                CreatedAt = msg.CreatedAt
            });

        return Ok(new { messageId = msg.Id });
    }

    /// <summary>Получить историю чата по заказу</summary>
    [HttpGet("{orderId:guid}")]
    public async Task<ActionResult> GetMessages(Guid orderId)
    {
        var messages = await _db.ChatMessages
            .Include(m => m.Sender)
            .Where(m => m.OrderId == orderId)
            .OrderBy(m => m.CreatedAt)
            .Select(m => new
            {
                m.Id,
                m.OrderId,
                m.SenderId,
                m.SenderRole,
                SenderName = m.Sender.FirstName + " " + m.Sender.LastName,
                m.Text,
                m.CreatedAt,
                m.IsRead
            })
            .ToListAsync();

        return Ok(messages);
    }

    /// <summary>Пометить сообщения как прочитанные</summary>
    [HttpPost("{orderId:guid}/read")]
    public async Task<ActionResult> MarkAsRead(Guid orderId, [FromQuery] Guid userId)
    {
        var unread = await _db.ChatMessages
            .Where(m => m.OrderId == orderId && m.SenderId != userId && !m.IsRead)
            .ToListAsync();

        foreach (var m in unread)
            m.IsRead = true;

        await _db.SaveChangesAsync();

        return Ok(new { markedAsRead = unread.Count });
    }
}

public class SendMessageRequest
{
    public Guid OrderId { get; set; }
    public Guid SenderId { get; set; }
    public string SenderRole { get; set; } = string.Empty;
    public string Text { get; set; } = string.Empty;
}