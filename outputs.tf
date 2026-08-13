output "site_url" {
  description = "URL of the deployed webserver"
  value       = "http://${module.webserver.public_ip}"
}

output "app_bucket_name" {
  description = "S3 bucket the deploy pipeline uploads app content to"
  value       = module.webserver.app_bucket_name
}
