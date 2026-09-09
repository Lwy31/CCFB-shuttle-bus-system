<?php
require '../config.php';
require '../auth.php';
require_admin();

$route_id = (int)($_GET['route_id'] ?? $_POST['route_id'] ?? 0);
$error = '';

$stmt = $conn->prepare('SELECT * FROM routes WHERE id = ?');
$stmt->bind_param('i', $route_id);
$stmt->execute();
$route = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$route) {
    die('Route not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $departure_time = trim($_POST['departure_time']);

    if ($departure_time === '') {
        $error = 'Please choose a departure time.';
    } else {
        $stmt = $conn->prepare('INSERT INTO trips (route_id, departure_time) VALUES (?, ?)');
        $stmt->bind_param('is', $route_id, $departure_time);
        if ($stmt->execute()) {
            $stmt->close();
            header('Location: routes.php');
            exit;
        }
        // Unique key on (route_id, departure_time) - this route already has
        // a departure at that exact time.
        $error = 'This route already has a departure at that time.';
        $stmt->close();
    }
}

$pageTitle = 'Add Departure Time';
require 'partials/header.php';
?>
<div class="form-card">
<h1>Add Departure Time</h1>
<p class="form-hint"><?= htmlspecialchars($route['route_name']) ?> (<?= htmlspecialchars($route['origin']) ?> &rarr; <?= htmlspecialchars($route['destination']) ?>)</p>
<?php if ($error): ?><p class="alert alert-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
<input type="hidden" name="route_id" value="<?= (int)$route_id ?>">
<label>Departure Time <input type="time" name="departure_time" required></label>
<button type="submit">Add Departure Time</button>
</form>
<p><a class="btn btn-secondary btn-small" href="routes.php">Back to routes</a></p>
</div>
<?php require 'partials/footer.php'; ?>
