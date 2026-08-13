# webserver module

Deploys the **infrastructure only**: an EC2 instance, Security Group,
Elastic IP, and an S3 bucket that acts as the hand-off point for the
compiled application. This module never contains application code — see
the top-level `app/` directory for the actual ASP.NET Core source, and
`.github/workflows/build.yml` for how a build reaches this instance.

No `provider` block — this module can be called from any AWS account/region;
the caller decides that (see `examples/basic/`).

## How the app reaches the instance
1. Terraform creates the instance and the S3 bucket (this module). The
   instance's `user_data` installs the ASP.NET Core **runtime** (not the
   SDK — nothing is compiled here), registers a `systemd` service for the
   app, and sets up a cron job that runs every minute.
2. That cron job runs `/usr/local/bin/deploy-app.sh`, which checks
   `s3://<app_bucket_name>/releases/app.zip` for a new build (comparing an
   md5 hash, so it only acts when something actually changed) and, if so,
   unzips it into place and restarts the `hello-web` systemd service.
3. The CI/CD pipeline's `build.yml` is what actually puts a new
   `app.zip` into that bucket — see the root `README.md`.

## Inputs
| Name | Type | Default | Description |
|---|---|---|---|
| `instance_type` | string | `"t2.micro"` | EC2 instance size |
| `app_bucket_name` | string | *(required)* | Globally-unique S3 bucket name for the compiled app |

## Outputs
| Name | Description |
|---|---|
| `public_ip` | Elastic IP address of the webserver |
| `instance_id` | EC2 instance ID |
| `app_bucket_name` | The S3 bucket the deploy pipeline should upload `app.zip` to |

## A real Learner Lab dependency to verify
This relies on `LabInstanceProfile` having S3 read permissions from
*inside* the running instance (for the cron sync). Test `aws s3 ls` from
a running instance before teaching this — if it fails, the cron job does
nothing every minute, silently, with no error to point at.

## Why systemd, and why a hash check instead of just re-syncing every time?
A compiled app is a running *process*, not static files — a plain
`aws s3 sync` (as an earlier, HTML-only version of this course used)
isn't enough, because a running `dotnet` process won't notice new DLLs
land on disk. `systemctl restart` is what actually picks up a new build;
the hash check is what stops the service from restarting (and briefly
dropping requests) every single minute even when nothing changed.
