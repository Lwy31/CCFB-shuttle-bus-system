variable "instance_type" {
  description = "EC2 instance size"
  type        = string
  default     = "t2.micro"
}

variable "app_bucket_name" {
  description = "Globally-unique S3 bucket name for deployed app content"
  type        = string
}
