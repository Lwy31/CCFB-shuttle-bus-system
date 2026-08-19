resource "aws_security_group" "event_ticketing_sg" {
  name        = "event-ticketing-sg"
  description = "Allow SSH and HTTP"

  ingress {
    description = "SSH"
    from_port   = 22
    to_port     = 22
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  ingress {
    description = "HTTP"
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }
}

data "aws_ami" "amazon_linux" {
  most_recent = true
  owners      = ["amazon"]

  filter {
    name   = "name"
    values = ["al2023-ami-*-x86_64"]
  }
}

# The hand-off point between "Terraform provisions infrastructure" and
# "the CI/CD pipeline deploys the compiled application."
resource "aws_s3_bucket" "app_artifacts" {
  bucket        = var.app_bucket_name
  force_destroy = true
}

resource "aws_instance" "event" {
  ami                    = data.aws_ami.amazon_linux.id
  instance_type          = var.instance_type
  vpc_security_group_ids = [aws_security_group.event_ticketing_sg.id]
  iam_instance_profile        = "LabInstanceProfile"
  user_data_replace_on_change = true

  # This user_data ONLY prepares the server to RUN the app — installing
  # the .NET runtime, registering a systemd service, and setting up a
  # cron job that polls S3 for a new build. It never contains application
  # code itself; that's published, zipped, and shipped separately by the
  # CI/CD pipeline (see .github/workflows/build.yml).
  user_data = <<-EOF
    #!/bin/bash
    yum install -y awscli unzip cronie
    systemctl enable --now crond

    # ASP.NET Core runtime only — the app is compiled elsewhere (in CI),
    # this instance only needs to RUN it, not build it.
    curl -sSL https://dot.net/v1/dotnet-install.sh -o /tmp/dotnet-install.sh
    bash /tmp/dotnet-install.sh --channel 8.0 --runtime aspnetcore --install-dir /usr/share/dotnet
    ln -s /usr/share/dotnet/dotnet /usr/bin/dotnet

    mkdir -p /opt/event-ticketing/app /var/lib/event-ticketing

    # systemd manages the running app. It's enabled now, but not started —
    # there's nothing to run yet. The first successful deploy (below)
    # starts it for the first time.
    cat <<'UNIT' > /etc/systemd/system/event-ticketing.service
    [Unit]
    Description=event-ticketing ASP.NET Core app
    After=network.target

    [Service]
    WorkingDirectory=/opt/event-ticketing/app
    ExecStart=/usr/bin/dotnet /opt/event-ticketing/app/EventTicketingApp.dll
    Restart=always
    RestartSec=5
    User=root

    [Install]
    WantedBy=multi-user.target
    UNIT
    systemctl daemon-reload
    systemctl enable event-ticketing

    # Polls S3 every minute. Only unzips + restarts when the build
    # actually changed (compares a hash, not just "does the file exist") —
    # this is what lets "deploy the webapp" reach an ALREADY-RUNNING
    # instance without Terraform, SSH, or a replacement ever happening.
    cat <<'SCRIPT' > /usr/local/bin/deploy-app.sh
    #!/bin/bash
    set -e
    export PATH=$PATH:/usr/local/bin:/usr/bin:/bin
    aws s3 cp "s3://${var.app_bucket_name}/releases/app.zip" /tmp/app.zip --quiet || exit 0
    NEW_HASH=$(md5sum /tmp/app.zip | cut -d' ' -f1)
    OLD_HASH=$(cat /var/lib/event-ticketing/deployed.hash 2>/dev/null || echo "")
    if [ "$NEW_HASH" != "$OLD_HASH" ]; then
      rm -rf /opt/event-ticketing/app
      mkdir -p /opt/event-ticketing/app
      unzip -o -q /tmp/app.zip -d /opt/event-ticketing/app
      echo "$NEW_HASH" > /var/lib/event-ticketing/deployed.hash #这里全部file路径都要改，对我要睡觉，为什么现在怪怪的？因为copilot总之要改就对了
      systemctl restart event-ticketing
    fi
    SCRIPT
    chmod +x /usr/local/bin/deploy-app.sh

    # Use root's personal crontab directly. This is bulletproof and avoids
    # OS-specific quirks with /etc/cron.d/ file parsing.
    echo "* * * * * /usr/local/bin/deploy-app.sh" | crontab -
  EOF

  tags = {
    Name = "event-ticketing-instance"
  }
}

resource "aws_eip" "eve_eip" {
  instance = aws_instance.event.id
  domain   = "vpc"
}
