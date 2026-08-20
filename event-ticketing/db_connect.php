<?php
// Fetches the dynamic configuration created by Terraform's user_data
require_once __DIR__ . '/db_config.php';

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    error_log("Connection failed: " . $conn->connect_error);
    die("Database connection error.");
}
?>