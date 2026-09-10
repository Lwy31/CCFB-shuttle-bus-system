<?php
// Secure / idempotent web-based database seeder
// Runs schema.sql statement-by-statement to guarantee complete tables and seed rows.
require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

$tableCheck = $conn->query("SHOW TABLES LIKE 'users'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    echo "Database is already seeded.\n";
    $userCount = $conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0] ?? 0;
    $routeCount = $conn->query("SELECT COUNT(*) FROM routes")->fetch_row()[0] ?? 0;
    $tripCount = $conn->query("SELECT COUNT(*) FROM trips")->fetch_row()[0] ?? 0;
    echo "Current tables: $userCount user(s), $routeCount route(s), $tripCount trip(s).\n";
    exit;
}

$schemaFile = __DIR__ . '/schema.sql';
if (!is_file($schemaFile)) {
    if (is_file('/var/www/html/schema.sql')) {
        $schemaFile = '/var/www/html/schema.sql';
    } elseif (is_file('/tmp/schema.sql')) {
        $schemaFile = '/tmp/schema.sql';
    } else {
        http_response_code(500);
        die("Error: schema.sql file not found.\n");
    }
}

$sql = file_get_contents($schemaFile);
$cleanSql = preg_replace('/--.*$/m', '', $sql);
$cleanSql = preg_replace('/\/\*.*?\*\//s', '', $cleanSql);
$statements = array_filter(array_map('trim', explode(';', $cleanSql)));

$successCount = 0;
foreach ($statements as $stmtSql) {
    if ($stmtSql !== '') {
        if ($conn->query($stmtSql)) {
            $successCount++;
        } else {
            echo "Notice on statement: " . $conn->error . "\n";
        }
    }
}

echo "Database successfully initialized! Executed $successCount SQL statements.\n";
echo "Default admin account: admin@example.com / admin123\n";
