# app — HelloWebApp (ASP.NET Core)

A small ASP.NET Core app (.NET 8, minimal API style) — the actual compiled
application this course deploys, replacing the earlier static HTML version
once the CI/CD story needs a real build step.

It serves a landing page plus a tiny **in-memory message board** with a
real JSON API, so "build" and "deploy" mean something concrete: there's
logic to unit test and live endpoints to hit. The board is intentionally
in-memory (state resets on restart, and isn't shared across instances) —
that's exactly the limitation the assignment removes by introducing a
shared database (RDS).

## Endpoints
| Method | Path | Purpose |
|---|---|---|
| GET | `/` | Landing page: build info + the live message board (form posts via `fetch`) |
| GET | `/api/messages` | JSON list of messages, newest first |
| POST | `/api/messages` | Add a message (`{"author": "...", "text": "..."}`); 400 if text is blank |
| GET | `/health` | `{ status, messages }` — used by the ALB target group in a later exercise |

## Structure
```
app/
├── HelloWebApp.csproj
├── Program.cs           # the web app: page + JSON API endpoints
├── MessageBoard.cs       # in-memory, thread-safe store (testable in isolation)
├── DeploymentInfo.cs     # reads build-time metadata (testable in isolation)
└── deployment.json       # local-dev placeholder; CI overwrites this before publish
app.Tests/
├── HelloWebApp.Tests.csproj
├── DeploymentInfoTests.cs
└── MessageBoardTests.cs
```

## Running it locally
```
cd app
dotnet run
```
With no `ASPNETCORE_URLS` set the app binds port 80 (what the EC2 systemd
unit expects). For local testing without admin rights, pick a high port:
```
# PowerShell
$env:ASPNETCORE_URLS="http://localhost:5000"; dotnet run
# bash
ASPNETCORE_URLS=http://localhost:5000 dotnet run
```
Then open `http://localhost:5000`, post a message, and watch the board
update.

## Running the tests
```
cd app.Tests
dotnet test
```

## How deployment.json works
`deployment.json` ships **inside** the published, zipped artifact — not
injected at deploy time via environment variables. The CI pipeline's
`build` job (see `.github/workflows/build.yml`) overwrites this file with
the real commit SHA before running `dotnet publish`, so the artifact is
self-contained: the exact zip that gets tested is the exact zip that gets
deployed, with no last-minute substitution that could differ between
environments.

## Building a deployable package manually (what CI automates)
```
dotnet publish app/HelloWebApp.csproj -c Release -o publish
cd publish && zip -r ../app.zip . && cd ..
```
`app.zip` is what gets uploaded to the app S3 bucket — see the root
`README.md` for how it reaches the running EC2 instance from there.
