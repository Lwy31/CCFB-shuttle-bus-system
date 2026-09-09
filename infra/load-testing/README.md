# Load & Spike Testing Guide (Grafana k6)

This directory contains the simulation suite for load and surge testing the **Shuttle Bus Ticketing System**.

---

## 1. Prerequisites & Installation

### Windows
```powershell
# Using Winget (recommended)
winget install k6 --source winget

# Or using Chocolatey
choco install k6
```

### macOS
```bash
brew install k6
```

### Linux (Debian / Ubuntu)
```bash
sudo gpg -k
sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt-get update
sudo apt-get install k6
```

---

## 2. Test Profile & Behavior

The [`spike_test.js`](spike_test.js) script models realistic user behavior during an open ticket release window:

- **Stage 1 (0:00 - 0:30)**: Warm-up ramping from 0 to 20 Virtual Users (VUs).
- **Stage 2 (0:30 - 1:00)**: **Spike Surge** aggressively scaling to 200 concurrent VUs in 30 seconds.
- **Stage 3 (1:00 - 3:00)**: Sustained Peak holding at 200 VUs for 2 minutes to test:
  - ALB request queuing and target group health.
  - ASG CPU utilization alarm and scale-out reaction.
  - RDS MySQL connection pool behavior with persistent connections.
- **Stage 4 (3:00 - 3:30)**: Wind-down recovery dropping to 50 VUs.
- **Stage 5 (3:30 - 4:00)**: Cooldown to 0 VUs.

### Traffic Mix
- **65% Browsing**: Loads `index.php` and `schedule.php`.
- **25% Route Availability Check**: Polls `route_availability.php?travel_date=YYYY-MM-DD`.
- **10% Booking Flow**: Logs into `login.php`, extracts CSRF tokens, and submits a booking to `create.php`.

---

## 3. Running the Simulation

### Against AWS ALB (Recommended)
Replace with your ALB's DNS name (found in `terraform output -raw alb_dns_name` or the AWS Console):

```powershell
k6 run -e TARGET_URL="http://assignment-alb-xxxxxxxxxx.us-east-1.elb.amazonaws.com" spike_test.js
```

### With Custom Credentials
```powershell
k6 run -e TARGET_URL="http://assignment-alb-xxxxxxxxxx.us-east-1.elb.amazonaws.com" -e SIM_EMAIL="admin@example.com" -e SIM_PASSWORD="admin123" spike_test.js
```

### Local Testing (Docker / XAMPP)
```powershell
k6 run -e TARGET_URL="http://localhost:8080" spike_test.js
```

---

## 4. Key Metrics to Watch

### In the k6 CLI Summary
- **`http_req_duration (p95)`**: 95th percentile response time. Goal is `< 1500ms`.
- **`http_req_failed`**: HTTP error rate. Goal is `< 5%` during the peak spike.
- **`successful_bookings`**: Total count of completed ticket reservations.
- **`custom_error_rate`**: Logic errors (e.g. failed CSRF, database error messages).

### In AWS CloudWatch
During the test, open CloudWatch and inspect:

| Metric | Namespace / Dimension | Target / Healthy | Warning / Bottleneck |
|---|---|---|---|
| **`TargetResponseTime`** | `AWS/ApplicationELB` | `< 300 ms` | `> 2000 ms` (DB locks or worker saturation) |
| **`HTTPCode_Target_5XX_Count`** | `AWS/ApplicationELB` | `0` | `> 0` (500 = DB failure, 502/504 = HTTPD timeout) |
| **`GroupInServiceInstances`** | `AWS/AutoScaling` | Scales from 2 to 4 | Stays at 2 (alarm threshold not reached or boot failure) |
| **`CPUUtilization`** | `AWS/RDS` | `< 70%` | `> 85%` (missing index or lock contention) |
| **`DatabaseConnections`** | `AWS/RDS` | `< 50` | Approaching 80 (t3.micro limit) |
