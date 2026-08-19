output "site_url" {
  description = "URL of the deployed webserver"
  value       = "http://${module.event-ticketing.public_ip}"
}

output "app_bucket_name" {
  description = "S3 bucket the deploy pipeline uploads app content to"
  value       = module.event-ticketing.app_bucket_name
}
