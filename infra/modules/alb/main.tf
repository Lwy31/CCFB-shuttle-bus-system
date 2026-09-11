# assignment-alb: the single public entry point. HTTP only, per the
# assignment's assumption that HTTPS/custom domain isn't required for this POC.
resource "aws_lb" "this" {
  name               = "${var.name_prefix}-alb"
  internal           = false
  load_balancer_type = "application"
  security_groups    = [var.alb_sg_id]
  subnets            = var.public_subnet_ids

  tags = {
    Name = "${var.name_prefix}-alb"
  }
}

resource "aws_lb_target_group" "app" {
  name                 = "${var.name_prefix}-tg"
  port                 = 80
  protocol             = "HTTP"
  vpc_id               = var.vpc_id
  target_type          = "instance"
  deregistration_delay = 30

  # Without this, the ALB round-robins every request across all healthy
  # instances independently. PHP sessions are meant to be shared across
  # instances via the RDS-backed DbSessionHandler, but if that's ever
  # broken/misconfigured on one instance, a user's GET (which writes the
  # CSRF token to their session) and their following POST (which checks
  # it) can land on two different instances that disagree on the session -
  # surfacing as "Invalid or expired request token" on effectively every
  # form submission, not just occasionally. Stickiness pins one browser's
  # requests to the same instance for the lifetime of the cookie, which
  # sidesteps this class of bug entirely regardless of whether the shared
  # session backend is actually working.
  stickiness {
    type            = "lb_cookie"
    cookie_duration = 3600
    enabled         = true
  }

  health_check {
    path                = var.health_check_path
    protocol            = "HTTP"
    matcher             = "200-399"
    healthy_threshold   = 2
    unhealthy_threshold = 3
    interval            = 15
    timeout             = 5
  }

  tags = {
    Name = "${var.name_prefix}-tg"
  }
}

resource "aws_lb_listener" "http" {
  load_balancer_arn = aws_lb.this.arn
  port              = 80
  protocol          = "HTTP"

  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.app.arn
  }
}

# Ops alert: fires when the ALB has been routing to zero healthy instances
# for 2 straight evaluation periods - i.e. the site is actually down, not
# just a single instance mid-boot/instance-refresh.
resource "aws_cloudwatch_metric_alarm" "unhealthy_hosts" {
  alarm_name          = "${var.name_prefix}-alb-zero-healthy-hosts"
  comparison_operator = "LessThanThreshold"
  evaluation_periods  = 2
  metric_name         = "HealthyHostCount"
  namespace           = "AWS/ApplicationELB"
  period              = 60
  statistic           = "Minimum"
  threshold           = 1
  alarm_description   = "No healthy EC2 instances behind the ALB - the site is unreachable."
  alarm_actions       = [var.sns_topic_arn]
  ok_actions          = [var.sns_topic_arn]
  treat_missing_data  = "breaching"

  dimensions = {
    LoadBalancer = aws_lb.this.arn_suffix
    TargetGroup  = aws_lb_target_group.app.arn_suffix
  }
}

# Ops alert: a burst of server errors (buggy deploy, DB connection
# exhaustion, etc.) even while instances still register as healthy.
resource "aws_cloudwatch_metric_alarm" "high_5xx_rate" {
  alarm_name          = "${var.name_prefix}-alb-high-5xx-rate"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 1
  metric_name         = "HTTPCode_Target_5XX_Count"
  namespace           = "AWS/ApplicationELB"
  period              = 300
  statistic           = "Sum"
  threshold           = 10
  alarm_description   = "More than 10 HTTP 5xx responses from app instances in 5 minutes."
  alarm_actions       = [var.sns_topic_arn]
  treat_missing_data  = "notBreaching"

  dimensions = {
    LoadBalancer = aws_lb.this.arn_suffix
  }
}
