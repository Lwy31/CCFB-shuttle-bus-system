# CloudFront CDN distribution for S3 uploads.
# Provides low-latency edge delivery worldwide (including Malaysia / Singapore edge locations),
# HTTP/2 multiplexing, automatic compression, and browser caching.
resource "aws_cloudfront_distribution" "s3_distribution" {
  origin {
    domain_name = "${var.bucket_name}.s3.${var.aws_region}.amazonaws.com"
    origin_id   = "S3-${var.bucket_name}"
  }

  enabled             = true
  is_ipv6_enabled     = true
  comment             = "CDN distribution for ${var.name_prefix} photos and uploads"
  default_root_object = ""

  default_cache_behavior {
    allowed_methods  = ["GET", "HEAD", "OPTIONS"]
    cached_methods   = ["GET", "HEAD"]
    target_origin_id = "S3-${var.bucket_name}"

    forwarded_values {
      query_string = false
      headers      = ["Origin", "Access-Control-Request-Headers", "Access-Control-Request-Method"]
      cookies {
        forward = "none"
      }
    }

    viewer_protocol_policy = "redirect-to-https"
    min_ttl                = 0
    default_ttl            = 86400    # 1 day
    max_ttl                = 31536000 # 1 year
    compress               = true
  }

  # PriceClass_All provides edge locations globally, including Asia Pacific (Kuala Lumpur, Singapore)
  price_class = "PriceClass_All"

  restrictions {
    geo_restriction {
      restriction_type = "none"
    }
  }

  viewer_certificate {
    cloudfront_default_certificate = true
  }

  tags = {
    Name = "${var.name_prefix}-cloudfront"
  }
}
