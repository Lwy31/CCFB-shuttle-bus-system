<?php
require 'config.php';
require 'auth.php';

$route_id = isset($_GET['route_id']) && is_scalar($_GET['route_id']) ? trim((string)$_GET['route_id']) : '';
$departure_time = isset($_GET['departure_time']) && is_scalar($_GET['departure_time']) ? trim((string)$_GET['departure_time']) : '';

if (!ctype_digit($route_id)) {
    $route_id = '';
}
if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $departure_time)) {
    $departure_time = '';
}
$departure_prefix = $departure_time !== '' ? substr($departure_time, 0, 2) . ':%' : '';

$routes = $conn->query('SELECT id, route_name FROM routes ORDER BY route_name')->fetch_all(MYSQLI_ASSOC);

$query = '
    SELECT tr.id AS trip_id, tr.departure_time, r.route_name, r.origin, r.destination, r.price, r.total_seats
    FROM trips tr
    JOIN routes r ON r.id = tr.route_id
    WHERE 1 = 1';

if ($route_id !== '') {
    $query .= ' AND r.id = ?';
}
if ($departure_time !== '') {
    $query .= ' AND tr.departure_time LIKE ?';
}
$query .= ' ORDER BY tr.departure_time, r.route_name';

$stmt = $conn->prepare($query);
if ($route_id !== '' && $departure_time !== '') {
    $stmt->bind_param('is', $route_id, $departure_prefix);
} elseif ($route_id !== '') {
    $stmt->bind_param('i', $route_id);
} elseif ($departure_time !== '') {
    $stmt->bind_param('s', $departure_prefix);
}
$stmt->execute();
$trips = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pageTitle = 'Shuttle Timetable';
require 'partials/header.php';
?>
<div class="page-header">
<h1>Shuttle Timetable</h1>
<p>Daily departure schedule for every campus shuttle route.</p>
</div>

<form class="filter-bar" method="get">
<label>Route
<select name="route_id">
<option value="">All routes</option>
<?php foreach ($routes as $route): ?>
<option value="<?= (int)$route['id'] ?>" <?= $route_id === (string)$route['id'] ? 'selected' : '' ?>><?= htmlspecialchars($route['route_name']) ?></option>
<?php endforeach; ?>
</select>
</label>
<label>Departure hour
<input type="time" name="departure_time" value="<?= htmlspecialchars($departure_time) ?>" step="3600" title="Shows all departures within the selected hour">
</label>
<button type="submit" class="btn btn-small">Search</button>
<?php if ($route_id !== '' || $departure_time !== ''): ?><a class="btn btn-small btn-secondary" href="schedule.php">Reset</a><?php endif; ?>
</form>

<?php if (empty($trips)): ?>
<div class="empty-state">
<div class="empty-state-icon">&#128652;</div>
<p>No departures match your filters.</p>
</div>
<?php else: ?>
<table>
<tr><th>Departs</th><th>Route</th><th>Origin</th><th>Destination</th><th>Price (RM)</th><th>Seats</th><th>Actions</th></tr>
<?php foreach ($trips as $t): ?>
<tr>
<td><?= htmlspecialchars($t['departure_time']) ?></td>
<td><?= htmlspecialchars($t['route_name']) ?></td>
<td><?= htmlspecialchars($t['origin']) ?></td>
<td><?= htmlspecialchars($t['destination']) ?></td>
<td><?= number_format($t['price'], 2) ?></td>
<td><?= (int)$t['total_seats'] ?></td>
<td>
<?php if (current_user_id()): ?>
<a class="btn btn-small" href="create.php?trip_id=<?= (int)$t['trip_id'] ?>">Book Ticket</a>
<?php else: ?>
<a class="btn btn-small btn-secondary" href="login.php">Login to Book</a>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
<?php require 'partials/footer.php'; ?>
