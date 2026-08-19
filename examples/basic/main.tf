terraform {
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
  }

  # Remote state — so this survives a GitHub Actions runner (a fresh VM
  # every run). The bucket must already exist (create it once by hand):
  #   aws s3 mb s3://hello-web-state-<your-unique-suffix> --region us-east-1
  backend "s3" {
    bucket = "event-ticketing-terraform-state-dft2026g7"
    key    = "event-ticketing/terraform.tfstate"
    region = "us-east-1"
  }
}

provider "aws" {
  region = "us-east-1"
}

module "event-ticketing" {
  source        = "../../"
  instance_type = "t2.micro"
  # Also globally unique — separate from the state bucket above, and
  # separate in PURPOSE: this one holds deployed website content, not
  # Terraform's own bookkeeping.
  app_bucket_name = "event-ticketing-app-dft2026g7"
}

output "site_url" {
  value = module.event-ticketing.site_url
}

output "app_bucket_name" {
  value = module.event-ticketing.app_bucket_name
}
