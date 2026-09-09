<?php
require '../config.php';
require '../auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)$_POST['id'];

    $stmt = $conn->prepare('DELETE FROM trips WHERE id = ?');
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
        $_SESSION['flash_success'] = 'Departure time deleted.';
    } else {
        $_SESSION['flash_error'] = 'Cannot delete this departure time: it still has tickets booked against it.';
    }
    $stmt->close();
}

header('Location: routes.php');
exit;
