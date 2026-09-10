# assignment-alerts: a single SNS topic that both CloudWatch alarms
# (ALB/ASG/RDS operational issues, wired up in their own modules) and the
# PHP application itself (new bookings, new testimonials - see helpers.php's
# sns_publish()) publish to. One topic means the admin gets everything in
# one inbox instead of juggling several subscriptions.
resource "aws_sns_topic" "alerts" {
  name = "${var.name_prefix}-alerts"

  tags = {
    Name = "${var.name_prefix}-alerts"
  }
}

# Email subscriptions start in "PendingConfirmation" - AWS sends a
# confirmation link to admin_email right after the first `terraform apply`,
# and nothing is actually delivered until that link is clicked. Terraform
# has no way to detect or wait for that confirmation click, so if alerts
# never arrive, check the inbox (and spam folder) for that first email.
resource "aws_sns_topic_subscription" "admin_email" {
  count     = var.admin_email != "" ? 1 : 0
  topic_arn = aws_sns_topic.alerts.arn
  protocol  = "email"
  endpoint  = var.admin_email
}
