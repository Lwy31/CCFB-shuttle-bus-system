<?php
require '../config.php';
require '../auth.php';
require_admin();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$error = '';

$stmt = $conn->prepare('SELECT tr.*, r.route_name, r.origin, r.destination FROM trips tr JOIN routes r ON r.id = tr.route_id WHERE tr.id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$trip = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$trip) {
    die('Departure time not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $departure_time = trim($_POST['departure_time']);

    if ($departure_time === '') {
        $error = 'Please choose a departure time.';
    } else {
        $stmt = $conn->prepare('UPDATE trips SET departure_time = ? WHERE id = ?');
        $stmt->bind_param('si', $departure_time, $id);
        if ($stmt->execute()) {
            $stmt->close();
            header('Location: routes.php');
            exit;
        }
        // Unique key on (route_id, departure_time) - this route already has
        // a different departure at that exact time.
        $error = 'This route already has a departure at that time.';
        $stmt->close();
    }
}

$pageTitle = 'Edit Departure Time';
require 'partials/header.php';
?>
<div class="form-card">
<h1>Edit Departure Time</h1>
<p class="form-hint"><?= htmlspecialchars($trip['route_name']) ?> (<?= htmlspecialchars($trip['origin']) ?> &rarr; <?= htmlspecialchars($trip['destination']) ?>)</p>
<?php if ($error): ?><p class="alert alert-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
<input type="hidden" name="id" value="<?= (int)$trip['id'] ?>">
<label>Departure Time <input type="time" name="departure_time" value="<?= htmlspecialchars($trip['departure_time']) ?>" required></label>
<button type="submit">Update Departure Time</button>
</form>
<p><a class="btn btn-secondary btn-small" href="routes.php">Back to routes</a></p>
</div>
<?php require 'partials/footer.php'; ?>
