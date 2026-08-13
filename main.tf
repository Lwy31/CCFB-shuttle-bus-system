module "webserver" {
  source          = "./modules/webserver"
  instance_type   = var.instance_type
  app_bucket_name = var.app_bucket_name
}
