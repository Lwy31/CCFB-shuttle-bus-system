<?php
require '../config.php';
require '../auth.php';
require '../helpers.php';
require_admin();

$flashError = $_SESSION['flash_error'] ?? null;
$flashSuccess = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

$routes = $conn->query('SELECT * FROM routes ORDER BY route_name')->fetch_all(MYSQLI_ASSOC);

// Every departure time for every route, grouped under its route id, so
// each route's card can list its own trips underneath it.
$tripsByRoute = [];
if (!empty($routes)) {
    $routeIds = array_column($routes, 'id');
    $placeholders = implode(',', array_fill(0, count($routeIds), '?'));
    $types = str_repeat('i', count($routeIds));
    $stmt = $conn->prepare("SELECT id, route_id, departure_time FROM trips WHERE route_id IN ($placeholders) ORDER BY departure_time");
    $stmt->bind_param($types, ...$routeIds);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $trip) {
        $tripsByRoute[(int)$trip['route_id']][] = $trip;
    }
    $stmt->close();
}

$pageTitle = 'Manage Routes';
require 'partials/header.php';
?>
<h1>Routes</h1>
<p><a class="btn btn-small" href="route_create.php">+ Add Route</a></p>
<?php if ($flashSuccess): ?><p class="alert alert-success"><?= htmlspecialchars($flashSuccess) ?></p><?php endif; ?>
<?php if ($flashError): ?><p class="alert alert-error"><?= htmlspecialchars($flashError) ?></p><?php endif; ?>

<?php if (empty($routes)): ?>
<div class="empty-state">
<div class="empty-state-icon">&#128652;</div>
<p>No routes yet.</p>
</div>
<?php endif; ?>

<?php foreach ($routes as $r): ?>
<div class="card route-card">
<div class="route-card-header">
<img class="table-thumb route-thumb" src="<?= htmlspecialchars(entity_image_url($r)) ?>" alt="<?= htmlspecialchars($r['route_name']) ?>">
<div class="route-info">
<h3><?= htmlspecialchars($r['route_name']) ?></h3>
<p><?= htmlspecialchars($r['origin']) ?> &rarr; <?= htmlspecialchars($r['destination']) ?></p>
<p>RM<?= number_format($r['price'], 2) ?> &middot; <?= (int)$r['total_seats'] ?> seats/bus</p>
</div>
<div class="route-actions">
<a class="btn btn-secondary btn-small" href="route_edit.php?id=<?= (int)$r['id'] ?>">Edit Route</a>
<form action="route_delete.php" method="post" class="contents" onsubmit="return confirm('Delete this route? All its departure times must be removed first.');">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
<input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
<button type="submit" class="btn-small btn-danger btn-nudge-down">Delete Route</button>
</form>
</div>
</div>

<table class="trips-table">
<tr><th>Departure Time</th><th>Actions</th></tr>
<?php foreach (($tripsByRoute[(int)$r['id']] ?? []) as $trip): ?>
<tr>
<td><?= htmlspecialchars($trip['departure_time']) ?></td>
<td>
<div class="trip-actions">
<a class="btn btn-secondary btn-small" href="trip_edit.php?id=<?= (int)$trip['id'] ?>">Edit</a>
<form action="trip_delete.php" method="post" class="contents" onsubmit="return confirm('Delete this departure time? It cannot be undone.');">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
<input type="hidden" name="id" value="<?= (int)$trip['id'] ?>">
<button type="submit" class="btn-small btn-danger btn-nudge-down">Delete</button>
</form>
</div>
</td>
</tr>
<?php endforeach; ?>
<?php if (empty($tripsByRoute[(int)$r['id']] ?? [])): ?>
<tr><td colspan="2" class="empty-cell">No departure times yet.</td></tr>
<?php endif; ?>
<tr><td colspan="2"><a class="btn btn-small" href="trip_create.php?route_id=<?= (int)$r['id'] ?>">+ Add departure time</a></td></tr>
</table>
</div>
<?php endforeach; ?>
<?php require 'partials/footer.php'; ?>