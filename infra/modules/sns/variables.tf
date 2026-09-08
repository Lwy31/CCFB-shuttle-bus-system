variable "name_prefix" {
  description = "Prefix applied to all resource names in this module."
  type        = string
  default     = "assignment"
}

variable "admin_email" {
  description = "Email address that receives operational alerts (ALB/ASG/RDS issues) and application notifications (new bookings, new testimonials). AWS emails a confirmation link here after the first apply - alerts won't deliver until it's clicked."
  type        = string
}
