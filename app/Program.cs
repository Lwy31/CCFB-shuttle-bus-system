using System.Net;
using HelloWebApp;

var builder = WebApplication.CreateBuilder(args);
builder.Services.AddSingleton<MessageBoard>();
var app = builder.Build();

var deploymentFile = Path.Combine(AppContext.BaseDirectory, "deployment.json");
var (websiteMessage, deploymentId) = DeploymentInfo.Load(deploymentFile);

// --- JSON API: the "real" part of the app, and what the unit tests cover ---
app.MapGet("/api/messages", (MessageBoard board) => Results.Ok(board.All()));

app.MapPost("/api/messages", (MessageBoard board, NewMessage input) =>
{
    try
    {
        var message = board.Add(input.Author, input.Text);
        return Results.Created($"/api/messages/{message.Id}", message);
    }
    catch (ArgumentException ex)
    {
        return Results.BadRequest(new { error = ex.Message });
    }
});

// --- The page: build info + a live message board backed by the API above ---
app.MapGet("/", (MessageBoard board) =>
{
    var rows = board.All().Count == 0
        ? "<p class=\"empty\">No messages yet — be the first.</p>"
        : string.Join("\n", board.All().Select(m =>
            $"<li><span class=\"who\">{WebUtility.HtmlEncode(m.Author)}</span>"
            + $"<span class=\"when\">{m.PostedAt.UtcDateTime:yyyy-MM-dd HH:mm} UTC</span>"
            + $"<span class=\"what\">{WebUtility.HtmlEncode(m.Text)}</span></li>"));

    return Results.Content($$"""
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>hello-web</title>
<style>
  body { font-family: -apple-system, "Segoe UI", Arial, sans-serif; background: #1A1A2E; color: #1E1E2E; margin: 0; padding: 2rem 1rem; }
  .card { background: #F7F6FB; max-width: 620px; margin: 0 auto; padding: 2.5rem 3rem; border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,.4); }
  h1 { margin: 0 0 .25rem; font-size: 2rem; }
  .tag { color: #7C3AED; font-weight: 600; margin: 0 0 1rem; font-size: .8rem; letter-spacing: .04em; }
  .meta { font-size: .8rem; color: #64748B; margin: 0 0 1.5rem; }
  code { background: #EDE6FB; padding: .15rem .5rem; border-radius: 6px; font-family: "Courier New", monospace; }
  form { display: flex; gap: .5rem; flex-wrap: wrap; margin: 0 0 1rem; }
  input { padding: .55rem .7rem; border: 1px solid #CBD5E1; border-radius: 8px; font-size: .9rem; }
  #author { width: 130px; } #text { flex: 1; min-width: 180px; }
  button { background: #7C3AED; color: #fff; border: 0; border-radius: 8px; padding: .55rem 1.1rem; font-weight: 600; cursor: pointer; }
  ul { list-style: none; padding: 0; margin: 0; }
  li { border-top: 1px solid #E2E8F0; padding: .6rem 0; display: grid; grid-template-columns: 1fr auto; gap: .1rem .8rem; }
  .who { font-weight: 600; } .when { color: #94A3B8; font-size: .75rem; } .what { grid-column: 1 / -1; color: #334155; }
  .empty { color: #94A3B8; }
</style>
</head>
<body>
  <div class="card">
    <h1>{{WebUtility.HtmlEncode(websiteMessage)}}</h1>
    <p class="tag">DEPLOYED WITH TERRAFORM + GITHUB ACTIONS</p>
    <p class="meta">Build <code>{{WebUtility.HtmlEncode(deploymentId)}}</code></p>

    <form id="f">
      <input id="author" name="author" placeholder="Your name" maxlength="60">
      <input id="text" name="text" placeholder="Leave a message…" maxlength="280" required>
      <button type="submit">Post</button>
    </form>

    <ul id="board">
      {{rows}}
    </ul>
  </div>

<script>
  const board = document.getElementById('board');
  const fmt = t => new Date(t).toISOString().slice(0, 16).replace('T', ' ') + ' UTC';

  function render(messages) {
    board.innerHTML = '';
    if (messages.length === 0) {
      const p = document.createElement('p');
      p.className = 'empty';
      p.textContent = 'No messages yet — be the first.';
      board.appendChild(p);
      return;
    }
    for (const m of messages) {
      const li = document.createElement('li');
      const who = document.createElement('span'); who.className = 'who'; who.textContent = m.author;
      const when = document.createElement('span'); when.className = 'when'; when.textContent = fmt(m.postedAt);
      const what = document.createElement('span'); what.className = 'what'; what.textContent = m.text;
      li.append(who, when, what);
      board.appendChild(li);
    }
  }

  document.getElementById('f').addEventListener('submit', async e => {
    e.preventDefault();
    const author = document.getElementById('author').value;
    const text = document.getElementById('text').value;
    const res = await fetch('/api/messages', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ author, text })
    });
    if (res.ok) {
      document.getElementById('text').value = '';
      render(await (await fetch('/api/messages')).json());
    }
  });
</script>
</body>
</html>
""", "text/html");
});

// Health endpoint — used by the Load Balancer target group in a later exercise.
app.MapGet("/health", (MessageBoard board) => Results.Ok(new { status = "healthy", messages = board.Count }));

// On the EC2 instance the systemd unit runs this with no ASPNETCORE_URLS set,
// so it binds port 80. Locally, set ASPNETCORE_URLS (e.g. http://localhost:5000)
// to run without needing port 80 / admin rights.
if (string.IsNullOrWhiteSpace(Environment.GetEnvironmentVariable("ASPNETCORE_URLS")))
{
    app.Run("http://0.0.0.0:80");
}
else
{
    app.Run();
}
