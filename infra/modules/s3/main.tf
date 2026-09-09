# assignment-s3-uploads: holds event photo uploads. Bucket ACLs stay blocked;
# only unauthenticated GetObject under uploads/* is allowed via bucket policy so
# images render in the browser, without allowing public listing or writes.
#
# The bucket itself is created via the AWS CLI (through a built-in
# terraform_data resource + local-exec) instead of a native aws_s3_bucket
# resource. The native resource unconditionally calls
# s3:GetBucketObjectLockConfiguration right after creation to populate
# state, even though this bucket never uses Object Lock. AWS Academy
# Learner Lab's org-wide Service Control Policy has an explicit Deny on
# that exact call - explicit SCP denies can't be overridden by any IAM
# permission - so `terraform apply`/`plan` fails outright with a 403 no
# matter what the Lab role is granted. The AWS CLI has no such built-in
# check, so shelling out to it for bucket creation/deletion sidesteps the
# problem entirely. Everything else below (public access block, bucket
# policy, CORS) stays as normal Terraform-managed resources, since those
# don't trigger the blocked call - only the bucket resource itself does.
#
# If you're running this outside a restricted sandbox, feel free to revert
# to a plain native aws_s3_bucket resource instead.
resource "terraform_data" "uploads" {
  input = var.bucket_name

  provisioner "local-exec" {
    command = <<-EOT
      aws s3api create-bucket --bucket "${var.bucket_name}" --region us-east-1
      aws s3api put-bucket-tagging --bucket "${var.bucket_name}" --tagging "TagSet=[{Key=Name,Value=${var.name_prefix}-s3-uploads}]"
      aws s3api put-public-access-block --bucket "${var.bucket_name}" --public-access-block-configuration "BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=false,RestrictPublicBuckets=false"
      sleep 5
    EOT
  }

  # Sandbox environment: by the time you `terraform destroy`, this bucket will
  # contain uploaded event images, deploy.yml release artifacts, and
  # db-init.yml's schema.sql/seed-db.sh. AWS refuses to delete a non-empty
  # bucket, so empty it first (mirrors the native resource's
  # force_destroy = true behaviour).
  provisioner "local-exec" {
    when    = destroy
    command = <<-EOT
      aws s3 rm "s3://${self.input}" --recursive
      aws s3api delete-bucket --bucket "${self.input}" --region us-east-1
    EOT
  }
}

resource "aws_s3_bucket_public_access_block" "uploads" {
  bucket = var.bucket_name

  depends_on = [terraform_data.uploads]

  block_public_acls       = true
  ignore_public_acls      = true
  block_public_policy     = false
  restrict_public_buckets = false
}

resource "aws_s3_bucket_policy" "public_read" {
  bucket = var.bucket_name
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "PublicReadEventImages"
        Effect    = "Allow"
        Principal = "*"
        Action    = "s3:GetObject"
        Resource  = "arn:aws:s3:::${var.bucket_name}/${var.public_read_prefix}"
      }
    ]
  })

  depends_on = [terraform_data.uploads, aws_s3_bucket_public_access_block.uploads]
}

resource "aws_s3_bucket_cors_configuration" "uploads" {
  bucket = var.bucket_name

  depends_on = [terraform_data.uploads]

  cors_rule {
    allowed_headers = ["*"]
    allowed_methods = ["GET"]
    allowed_origins = ["*"]
    max_age_seconds = 3000
  }
}
