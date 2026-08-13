output "public_ip" {
  description = "Elastic IP address of the webserver"
  value       = aws_eip.web_eip.public_ip
}

output "instance_id" {
  description = "EC2 instance ID"
  value       = aws_instance.web.id
}

output "app_bucket_name" {
  description = "S3 bucket the deploy pipeline uploads app content to"
  value       = aws_s3_bucket.app_artifacts.bucket
}
