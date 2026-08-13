namespace HelloWebApp;

public record Message(int Id, string Author, string Text, DateTimeOffset PostedAt);

/// <summary>
/// Payload for POST /api/messages. Both fields are optional on the wire so
/// the endpoint can validate them and return a clean 400 rather than letting
/// model binding throw.
/// </summary>
public record NewMessage(string? Author, string? Text);

/// <summary>
/// A tiny, thread-safe, in-memory message board. Deliberately not backed by a
/// database: hello-web is a single instance, so in-memory state is enough to
/// demo a real GET/POST API. The assignment's booking/ticketing app is where
/// this same shape gets a real, shared datastore (RDS) instead.
/// Kept separate from Program.cs so the logic can be unit tested without a
/// running web host — see HelloWebApp.Tests/MessageBoardTests.cs.
/// </summary>
public class MessageBoard
{
    public const int MaxLength = 280;

    private readonly List<Message> _messages = new();
    private readonly object _gate = new();
    private int _nextId = 1;

    public IReadOnlyList<Message> All()
    {
        lock (_gate)
        {
            // Newest first, so the page reads like a feed.
            return _messages.OrderByDescending(m => m.Id).ToList();
        }
    }

    public int Count
    {
        get { lock (_gate) { return _messages.Count; } }
    }

    public Message Add(string? author, string? text)
    {
        var cleanText = (text ?? string.Empty).Trim();
        if (cleanText.Length == 0)
        {
            throw new ArgumentException("Message text is required.");
        }
        if (cleanText.Length > MaxLength)
        {
            cleanText = cleanText[..MaxLength];
        }

        var cleanAuthor = string.IsNullOrWhiteSpace(author) ? "Anonymous" : author.Trim();

        lock (_gate)
        {
            var message = new Message(_nextId++, cleanAuthor, cleanText, DateTimeOffset.UtcNow);
            _messages.Add(message);
            return message;
        }
    }
}
