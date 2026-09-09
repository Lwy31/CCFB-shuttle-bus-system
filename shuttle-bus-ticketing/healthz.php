<?php
// Lightweight target for an ALB health check.
// config.php enforces a 3s connection timeout and fails fast if the DB is down.
require_once 'config.php';

header('Content-Type: text/plain');

if (!isset($conn) || $conn->connect_error) {
    http_response_code(503);
    echo 'FAIL: Database connection unavailable';
    exit;
}

if (!$conn->ping()) {
    http_response_code(503);
    echo 'FAIL: Database ping failed';
    exit;
}

http_response_code(200);
echo 'OK';
