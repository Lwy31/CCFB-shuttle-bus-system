<?php
require 'config.php';
require 'auth.php';
require 'helpers.php';
// Security headers: Prevent MIME sniffing, clickjacking, and cache leaks
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, no-cache, must-revalidate');

$date      = $_GET['travel_date'] ?? '';
$excludeId = (int)($_GET['exclude_ticket_id'] ?? 0);
$uid       = current_user_id();

// Security: Validate date format strictly (YYYY-MM-DD) to block malformed inputs
if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['full_trip_ids' => [], 'remaining' => [], 'user_booked' => []]);
    exit;
}

// Security (Anti-IDOR): Only allow excluding a ticket ID if the user is logged in
// and the ticket actually belongs to them (e.g. during edit ticket flow).
if ($excludeId > 0) {
    if (!$uid) {
        $excludeId = 0;
    } else {
        $checkStmt = $conn->prepare('SELECT id FROM tickets WHERE id = ? AND user_id = ?');
        $checkStmt->bind_param('ii', $excludeId, $uid);
        $checkStmt->execute();
        if (!$checkStmt->get_result()->fetch_assoc()) {
            $excludeId = 0;
        }
        $checkStmt->close();
    }
}

$trips = $conn->query('SELECT t.id, r.total_seats FROM trips t JOIN routes r ON r.id = t.route_id')->fetch_all(MYSQLI_ASSOC);

$stmt = $conn->prepare('SELECT trip_id, COALESCE(SUM(seat_quantity), 0) AS booked FROM tickets WHERE travel_date = ? AND id != ? GROUP BY trip_id');
$stmt->bind_param('si', $date, $excludeId);
$stmt->execute();
$bookedByTrip = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $bookedByTrip[(int)$row['trip_id']] = (int)$row['booked'];
}
$stmt->close();

// How many seats the current user already holds on each trip for this
// date (excluding the ticket being edited, if any) - lets the front end
// enforce/display the per-user, per-trip, per-day cap.
$userBookedByTrip = [];
if ($uid) {
    $stmt = $conn->prepare('SELECT trip_id, COALESCE(SUM(seat_quantity), 0) AS booked FROM tickets WHERE travel_date = ? AND user_id = ? AND id != ? GROUP BY trip_id');
    $stmt->bind_param('sii', $date, $uid, $excludeId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $userBookedByTrip[(int)$row['trip_id']] = (int)$row['booked'];
    }
    $stmt->close();
}

$fullTripIds = [];
$remaining = [];
foreach ($trips as $t) {
    $tid = (int)$t['id'];
    $booked = $bookedByTrip[$tid] ?? 0;
    $left = max(0, (int)$t['total_seats'] - $booked);
    $remaining[$tid] = $left;
    if ($left <= 0) {
        $fullTripIds[] = $tid;
    }
}

echo json_encode([
    'full_trip_ids' => $fullTripIds,
    'remaining'     => $remaining,
    'user_booked'   => $userBookedByTrip,
]);
