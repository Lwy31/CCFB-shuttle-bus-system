output "topic_arn" {
  description = "ARN of the shared alerts/notifications topic - pass this into the alb, rds, and asg modules' sns_topic_arn variable."
  value       = aws_sns_topic.alerts.arn
}
