variable "instance_type" {
  description = "EC2 instance size"
  type        = string
  default     = "t2.micro"
}

variable "app_bucket_name" {
  description = "Globally-unique S3 bucket name the deploy pipeline uploads app content to. The instance polls this bucket via cron and syncs new content locally."
  type        = string
}
