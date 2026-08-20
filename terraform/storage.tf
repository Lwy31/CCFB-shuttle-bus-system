resource "random_id" "bucket_suffix" { byte_length = 4 }

resource "aws_s3_bucket" "artwork_bucket" {
  bucket = "artvault-media-${random_id.bucket_suffix.hex}"

  force_destroy = true
}

data "archive_file" "website_zip" {
  type        = "zip"
  source_dir  = "${path.module}/../website"
  output_path = "${path.module}/website.zip"
}

resource "aws_s3_object" "code_upload" {
  bucket = aws_s3_bucket.artwork_bucket.id
  key    = "deployments/website.zip"
  source = data.archive_file.website_zip.output_path
  etag   = filemd5(data.archive_file.website_zip.output_path)
}