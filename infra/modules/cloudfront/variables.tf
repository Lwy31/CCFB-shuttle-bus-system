variable "name_prefix" {
  description = "Prefix applied to all resource names in this module."
  type        = string
  default     = "assignment"
}

variable "bucket_name" {
  description = "S3 bucket name to serve static uploads from."
  type        = string
}

variable "aws_region" {
  description = "AWS region of the S3 bucket."
  type        = string
  default     = "us-east-1"
}
