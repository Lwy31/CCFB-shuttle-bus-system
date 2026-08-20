resource "aws_iam_role" "ec2_role" {
  name = "artvault_ec2_role"
  assume_role_policy = jsonencode({
    Version = "2012-10-17",
    Statement = [{ Action = "sts:AssumeRole", Effect = "Allow", Principal = { Service = "ec2.amazonaws.com" } }]
  })
}
resource "aws_iam_role_policy_attachment" "s3_access" { 
    role = aws_iam_role.ec2_role.name 
    policy_arn = "arn:aws:iam::aws:policy/AmazonS3FullAccess" 
    }
resource "aws_iam_role_policy_attachment" "secrets_access" { 
    role = aws_iam_role.ec2_role.name 
    policy_arn = "arn:aws:iam::aws:policy/SecretsManagerReadWrite" 
    }
resource "aws_iam_instance_profile" "ec2_profile" { 
    name = "artvault_ec2_profile" 
    role = aws_iam_role.ec2_role.name 
    }

resource "aws_launch_template" "app" {
  name_prefix   = "artvault-app-"
  image_id      = "ami-0c55b159cbfafe1f0" # Amazon Linux 2023
  instance_type = "t3.micro"
  
  iam_instance_profile { name = aws_iam_instance_profile.ec2_profile.name }

  network_interfaces {
    associate_public_ip_address = false
    security_groups             = [aws_security_group.ec2_sg.id]
  }

  user_data = base64encode(<<-EOF
              #!/bin/bash
              yum update -y
              yum install -y httpd php php-mysqli php-json jq unzip

              SECRET=$(aws secretsmanager get-secret-value --secret-id ${aws_secretsmanager_secret.db_secret.name} --region us-east-1 --query SecretString --output text)
              DB_USER=$(echo $SECRET | jq -r .username)
              DB_PASS=$(echo $SECRET | jq -r .password)
              DB_HOST=$(echo $SECRET | jq -r .host)

              cat <<EOT > /var/www/html/db_config.php
              <?php
              \$host = "\$DB_HOST";
              \$user = "\$DB_USER";
              \$pass = "\$DB_PASS";
              \$db   = "artvault_db";
              ?>
              EOT

              aws s3 cp s3://${aws_s3_bucket.artwork_bucket.id}/deployments/website.zip /tmp/website.zip
              unzip -o /tmp/website.zip -d /var/www/html/
              
              chown -R apache:apache /var/www/html/
              systemctl enable httpd
              systemctl start httpd
              EOF
  )
}

resource "aws_autoscaling_group" "asg" {
  vpc_zone_identifier = [aws_subnet.private_a.id, aws_subnet.private_b.id]
  desired_capacity    = 2
  min_size            = 2
  max_size            = 4
  target_group_arns   = [aws_lb_target_group.tg.arn]
  health_check_type   = "ELB"

  launch_template {
    id      = aws_launch_template.app.id
    version = "$Latest"
  }
}

resource "aws_autoscaling_policy" "cpu_tracking" {
  name                   = "cpu-tracking"
  autoscaling_group_name = aws_autoscaling_group.asg.name
  policy_type            = "TargetTrackingScaling"

  target_tracking_configuration {
    predefined_metric_specification { predefined_metric_type = "ASGAverageCPUUtilization" }
    target_value = 60.0
  }
}