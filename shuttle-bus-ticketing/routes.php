<?php
require 'config.php';
require 'auth.php';
require 'helpers.php';

$routes = $conn->query('SELECT * FROM routes ORDER BY route_name')->fetch_all(MYSQLI_ASSOC);

$tripsByRoute = [];
$totalTrips = 0;
if (!empty($routes)) {
    $routeIds = array_column($routes, 'id');
    $placeholders = implode(',', array_fill(0, count($routeIds), '?'));
    $types = str_repeat('i', count($routeIds));
    $stmt = $conn->prepare("SELECT id, route_id, departure_time FROM trips WHERE route_id IN ($placeholders) ORDER BY departure_time");
    $stmt->bind_param($types, ...$routeIds);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $trip) {
        $tripsByRoute[(int)$trip['route_id']][] = $trip;
        $totalTrips++;
    }
    $stmt->close();
}

$totalRoutes = count($routes);
$totalSeats = array_sum(array_column($routes, 'total_seats'));

$pageTitle = 'All Routes';
require 'partials/header.php';
?>
<div class="page-header">
<h1>All Routes</h1>
<p>Every campus shuttle route, with its departure times and seat capacity.</p>
</div>

<section>
<div class="card-grid">
<div class="card"><h3><?= (int)$totalRoutes ?></h3><p>Active routes</p></div>
<div class="card"><h3><?= (int)$totalTrips ?></h3><p>Departures per day</p></div>
<div class="card"><h3><?= (int)$totalSeats ?></h3><p>Seats per run</p></div>
</div>
</section>

<?php foreach ($routes as $r): ?>
<section>
<div class="card" style="max-width:720px;">
<img class="card-thumb" src="<?= htmlspecialchars(entity_image_url($r)) ?>" alt="<?= htmlspecialchars($r['route_name']) ?>" loading="lazy">
<h3><?= htmlspecialchars($r['route_name']) ?></h3>
<p>&#128205; <?= htmlspecialchars($r['origin']) ?> &rarr; <?= htmlspecialchars($r['destination']) ?></p>
<p>RM<?= number_format($r['price'], 2) ?> &middot; <?= (int)$r['total_seats'] ?> seats/bus</p>
<?php $trips = $tripsByRoute[(int)$r['id']] ?? []; ?>
<?php if (empty($trips)): ?>
<p class="form-hint">No departures scheduled yet.</p>
<?php else: ?>
<div class="trip-list">
<?php foreach ($trips as $trip): ?>
<div class="trip-row">
<strong>Departs <?= htmlspecialchars($trip['departure_time']) ?></strong>
<?php if (current_user_id()): ?>
<a class="btn btn-small" href="create.php?trip_id=<?= (int)$trip['id'] ?>">Book Ticket</a>
<?php else: ?>
<a class="btn btn-small" href="login.php">Login to Book</a>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</section>
<?php endforeach; ?>
<?php require 'partials/footer.php'; ?>
