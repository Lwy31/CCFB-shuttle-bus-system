# hello-web

A minimal, reusable Terraform module that provisions an EC2 instance, Security Group, Elastic IP, and an S3 bucket — the **infrastructure** for a tiny ASP.NET Core demo app.

This is the teaching vehicle for AMIT3253's Terraform + CI/CD module (Weeks 7–9) — deliberately tiny so it applies and destroys in minutes, leaving room to focus on the mechanics (state, modules, CI/CD) rather than waiting on slow infrastructure.

## Structure
```
hello-web/
├── main.tf, variables.tf, outputs.tf   # thin wiring, no provider block
├── modules/webserver/                  # infrastructure only
├── examples/basic/                     # the only place terraform apply runs
├── app/                                # the actual ASP.NET Core application
├── app.Tests/                          # unit tests for app/
└── .github/workflows/                  # GitHub Actions CI/CD pipelines
```

## Prerequisites: First-time Setup

Before you can deploy this project for the first time, you must create a Terraform state bucket manually using the AWS CLI. Terraform uses this bucket to keep track of the resources it creates.

1. **Create the state bucket:**
   ```bash
   aws s3 mb s3://hello-web-state-<your-name> --region us-east-1
   ```
2. **Update placeholders in `examples/basic/main.tf`:**
   Replace the placeholder values with your unique suffix:
   - `bucket = "hello-web-state-<your-name>"`
   - `app_bucket_name = "hello-web-app-<your-name>"`

## The Workflows (Manual Triggers)

This project uses **GitHub Actions** to automate deployments. All workflows are configured with `workflow_dispatch` so they only run when you manually trigger them from the **Actions** tab in GitHub.

1. **`ci.yml`**: Validates the .NET app (build & test) and Terraform config (fmt, validate, plan). Touches nothing in AWS.
2. **`build.yml` (Build and Deploy)**: 
   - **Stage 1 (Build)**: Compiles the app and creates a Terraform plan.
   - **Stage 2 (Deploy Resources)**: Runs `terraform apply` to provision the AWS infrastructure.
   - **Stage 3 (Deploy WebApp)**: Uploads the compiled `.NET` app (`app.zip`) to the newly created S3 bucket.
3. **`destroy.yml` (Destroy Infrastructure)**: Runs `terraform destroy` to completely wipe out all AWS resources. *Requires typing "destroy" to confirm.*

## How the Deployment Actually Works

This project deliberately separates two concerns that are easy to conflate:
- **Terraform provisions infrastructure**: It creates the EC2 instance, opens ports, and creates an S3 bucket.
- **The CI/CD pipeline deploys the application code**: It zips the app and uploads it to S3.

**So how does the code get onto the EC2 instance?**
1. When Terraform creates the EC2 instance, it uses a `user_data` script to set up a **cron job** that runs every minute.
2. Every 60 seconds, the EC2 instance automatically checks the S3 bucket for a new `app.zip`.
3. If the code has changed, it downloads the zip, extracts it, and restarts the `.NET` web server. 
4. *No SSH, no re-running Terraform, and no instance replacement is needed to deploy new code!*

## Troubleshooting

- **Server is up, but website is down?** It takes up to 60 seconds after a successful GitHub Action run for the EC2 instance to pull the code.
- **Changing the bucket name?** We added `user_data_replace_on_change = true` to the EC2 instance in Terraform. This ensures that if you change the S3 bucket name in your code, Terraform will destroy the old EC2 instance and create a new one with the updated bucket name baked in.
- **Can't delete the S3 bucket?** We added `force_destroy = true` to the Terraform S3 bucket configuration so that it can be destroyed even if it still contains `app.zip`.

## Running the app locally, without any of this
```bash
cd app && dotnet run
```
See `app/README.md` for details, including running the unit tests.
