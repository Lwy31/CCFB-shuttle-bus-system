<?php
require '../config.php';
require '../auth.php';
require '../helpers.php';
require_admin();

$error = '';
$uploadDir = __DIR__ . '/../uploads';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $route_name     = trim($_POST['route_name']);
    $origin         = trim($_POST['origin']);
    $destination    = trim($_POST['destination']);
    $departure_time = trim($_POST['departure_time']);
    $price          = (float)$_POST['price'];
    $total_seats    = (int)$_POST['total_seats'];

    [$image_url, $uploadError] = handle_image_upload($_FILES['image'] ?? null, $uploadDir, 'route');

    if ($route_name === '' || $origin === '' || $destination === '' || $departure_time === '' || $total_seats < 1) {
        $error = 'All fields are required and total seats must be at least 1.';
    } elseif ($uploadError) {
        $error = $uploadError;
    } else {
        $conn->begin_transaction();

        $stmt = $conn->prepare('INSERT INTO routes (route_name, origin, destination, price, total_seats, image_url) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sssdis', $route_name, $origin, $destination, $price, $total_seats, $image_url);
        $stmt->execute();
        $route_id = $conn->insert_id;
        $stmt->close();

        // A route needs at least one departure time to be bookable - create
        // it together with the route so there's no in-between state where
        // the route exists but has nothing to book.
        $stmt = $conn->prepare('INSERT INTO trips (route_id, departure_time) VALUES (?, ?)');
        $stmt->bind_param('is', $route_id, $departure_time);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        header('Location: routes.php');
        exit;
    }
}

$pageTitle = 'Add Route';
require 'partials/header.php';
?>
<div class="form-card">
<h1>Add Route</h1>
<?php if ($error): ?><p class="alert alert-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
<label>Route Name <input type="text" name="route_name" required></label>
<label>Origin <input type="text" name="origin" required></label>
<label>Destination <input type="text" name="destination" required></label>
<label>First Departure Time <input type="time" name="departure_time" required></label>
<p class="form-hint">You can add more departure times for this route afterwards.</p>
<label>Price (RM) <input type="number" name="price" step="0.01" min="0" required></label>
<label>Total Seats <input type="number" name="total_seats" min="1" required></label>
<label>Photo <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp"></label>
<button type="submit">Add Route</button>
</form>
<p><a class="btn btn-secondary btn-small" href="routes.php">Back to routes</a></p>
</div>
<?php require 'partials/footer.php'; ?>
