output "cloudfront_domain_name" {
  description = "The domain name corresponding to the distribution (e.g. d111111abcdef8.cloudfront.net)."
  value       = aws_cloudfront_distribution.s3_distribution.domain_name
}

output "cloudfront_distribution_id" {
  description = "The identifier for the distribution."
  value       = aws_cloudfront_distribution.s3_distribution.id
}

output "cloudfront_distribution_arn" {
  description = "The ARN of the distribution."
  value       = aws_cloudfront_distribution.s3_distribution.arn
}
