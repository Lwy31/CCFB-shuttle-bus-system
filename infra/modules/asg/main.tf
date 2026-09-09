data "aws_ami" "amazon_linux" {
  most_recent = true
  owners      = ["amazon"]

  filter {
    name   = "name"
    values = ["al2023-ami-*-x86_64"]
  }

  filter {
    name   = "virtualization-type"
    values = ["hvm"]
  }
}

# Referenced, never created - Academy Learner Lab accounts block new IAM
# roles/instance profiles, so app instances reuse the pre-provisioned LabRole.
data "aws_iam_instance_profile" "lab" {
  name = var.instance_profile_name
}

resource "aws_launch_template" "app" {
  name_prefix   = "${var.name_prefix}-lt-"
  image_id      = data.aws_ami.amazon_linux.id
  instance_type = var.instance_type

  vpc_security_group_ids = [var.ec2_sg_id]

  iam_instance_profile {
    name = data.aws_iam_instance_profile.lab.name
  }

  # Bootstraps Apache/PHP and the DB env vars from Secrets Manager. The actual
  # application code is deployed afterwards by the CD workflow via SSM.
  user_data = base64encode(templatefile("${path.module}/templates/user_data.sh.tftpl", {
    secret_arn      = var.secret_arn
    aws_region      = var.aws_region
    artifact_bucket = var.artifact_bucket
    artifact_key    = var.artifact_key
    sns_topic_arn   = var.sns_topic_arn
    cdn_domain      = var.cdn_domain
  }))

  tag_specifications {
    resource_type = "instance"
    tags = {
      Name = "${var.name_prefix}-ec2"
      App  = "${var.name_prefix}-shuttle-bus-ticketing"
    }
  }

  tags = {
    Name = "${var.name_prefix}-lt"
  }
}

resource "aws_autoscaling_group" "app" {
  name = "${var.name_prefix}-asg"

  vpc_zone_identifier = var.private_subnet_ids
  min_size            = var.min_size
  max_size            = var.max_size
  desired_capacity    = var.desired_capacity
  health_check_type   = "ELB"
  # Generous grace period: on a t3.micro, user-data runs dnf update + installs
  # httpd/php/mariadb and pulls the app artifact from S3 before Apache serves
  # healthz.php - a shorter window risks the ASG killing the instance mid-boot
  # and looping. 300s comfortably covers a cold boot.
  health_check_grace_period = 300
  target_group_arns         = [var.target_group_arn]

  # Needed for the GroupInServiceInstances alarm below - the ASG doesn't
  # publish its own CloudWatch metrics unless this is turned on.
  metrics_granularity = "1Minute"
  enabled_metrics = [
    "GroupInServiceInstances",
    "GroupDesiredCapacity",
  ]

  launch_template {
    id      = aws_launch_template.app.id
    version = "$Latest"
  }

  instance_refresh {
    strategy = "Rolling"
    preferences {
      min_healthy_percentage = 50
    }
  }

  tag {
    key                 = "Name"
    value               = "${var.name_prefix}-asg-instance"
    propagate_at_launch = true
  }

  # Used by the CD workflow to target instances via SSM Run Command.
  tag {
    key                 = "App"
    value               = "${var.name_prefix}-shuttle-bus-ticketing"
    propagate_at_launch = true
  }
}

# Ops alert: fewer in-service instances than desired for a sustained period
# usually means instances are crash-looping on boot (bad deploy, user-data
# failure) rather than just a brief mid-refresh dip.
resource "aws_cloudwatch_metric_alarm" "instances_below_desired" {
  alarm_name          = "${var.name_prefix}-asg-instances-below-desired"
  comparison_operator = "LessThanThreshold"
  evaluation_periods  = 5
  metric_name         = "GroupInServiceInstances"
  namespace           = "AWS/AutoScaling"
  period              = 60
  statistic           = "Average"
  threshold           = var.min_size
  alarm_description   = "Fewer in-service instances than min_size for 5 minutes straight."
  alarm_actions       = [var.sns_topic_arn]
  ok_actions          = [var.sns_topic_arn]
  treat_missing_data  = "breaching"

  dimensions = {
    AutoScalingGroupName = aws_autoscaling_group.app.name
  }
}

resource "aws_autoscaling_policy" "cpu_target_tracking" {
  name                   = "${var.name_prefix}-asg-cpu-scaling"
  autoscaling_group_name = aws_autoscaling_group.app.name
  policy_type            = "TargetTrackingScaling"

  target_tracking_configuration {
    predefined_metric_specification {
      predefined_metric_type = "ASGAverageCPUUtilization"
    }
    target_value = var.cpu_target_value
  }
}
