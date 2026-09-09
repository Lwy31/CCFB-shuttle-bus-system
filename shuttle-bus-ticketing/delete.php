<?php
require 'config.php';
require 'auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id  = (int)$_POST['id'];
    $uid = current_user_id();

    $stmt = $conn->prepare('DELETE FROM tickets WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $id, $uid);
    $stmt->execute();
    // affected_rows tells us whether a row actually matched and got deleted -
    // without checking this, a failed cancel (wrong/stale id, already
    // cancelled, doesn't belong to this user) looks identical to a
    // successful one: the page just redirects back to index.php either way.
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();

    $_SESSION[$deleted ? 'flash_success' : 'flash_error'] = $deleted
        ? 'Ticket cancelled.'
        : 'Could not cancel that ticket - it may have already been cancelled or refreshed. Please reload the page and try again.';
}

header('Location: index.php');
exit;
