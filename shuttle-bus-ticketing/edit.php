<?php
require 'config.php';
require 'auth.php';
require 'helpers.php';
require_login();

$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$uid = current_user_id();
$error = '';

$stmt = $conn->prepare('SELECT t.*, r.price, tr.departure_time, r.route_name FROM tickets t JOIN trips tr ON tr.id = t.trip_id JOIN routes r ON r.id = tr.route_id WHERE t.id = ? AND t.user_id = ?');
$stmt->bind_param('ii', $id, $uid);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ticket) {
    die('Ticket not found or you do not have permission to edit it.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $travel_date   = $_POST['travel_date'] ?? '';
    $seat_quantity = (int)($_POST['seat_quantity'] ?? 0);

    if ($travel_date === '' || $seat_quantity < 1 || $seat_quantity > 5) {
        $error = 'Please choose a travel date and between 1 and 5 seats.';
    } elseif ($travel_date < date('Y-m-d')) {
        $error = 'Travel date cannot be in the past.';
    } elseif (is_departure_in_past($travel_date, $ticket['departure_time'])) {
        $error = 'This departure has already left today. Please choose a later date.';
    } else {
        $conn->begin_transaction();

        $trip_id = (int)$ticket['trip_id'];

        $stmt = $conn->prepare('SELECT r.total_seats FROM trips t JOIN routes r ON r.id = t.route_id WHERE t.id = ? FOR UPDATE');
        $stmt->bind_param('i', $trip_id);
        $stmt->execute();
        $trip = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $userBooked = user_seats_booked($conn, $uid, $trip_id, $travel_date, $id);

        $stmt = $conn->prepare('SELECT COALESCE(SUM(seat_quantity), 0) AS booked FROM tickets WHERE trip_id = ? AND travel_date = ? AND id != ?');
        $stmt->bind_param('isi', $trip_id, $travel_date, $id);
        $stmt->execute();
        $booked = (int)$stmt->get_result()->fetch_assoc()['booked'];
        $stmt->close();

        if ($userBooked + $seat_quantity > MAX_SEATS_PER_TRIP_PER_DATE) {
            $left = max(0, MAX_SEATS_PER_TRIP_PER_DATE - $userBooked);
            $error = $left > 0
                ? "You already have $userBooked other seat(s) booked on this departure for that date - you can set this ticket to at most $left seat(s) (max " . MAX_SEATS_PER_TRIP_PER_DATE . ' per departure per day).'
                : 'Your other tickets already use up your ' . MAX_SEATS_PER_TRIP_PER_DATE . '-seat limit on this departure for that date.';
            $conn->rollback();
        } elseif ($booked + $seat_quantity > $trip['total_seats']) {
            $available = $trip['total_seats'] - $booked;
            $error = $available > 0
                ? "Only $available seat(s) remaining on this departure for that date."
                : 'This departure is fully booked for that date.';
            $conn->rollback();
        } else {
            $total_price = $ticket['price'] * $seat_quantity;

            $stmt = $conn->prepare('UPDATE tickets SET travel_date=?, seat_quantity=?, total_price=? WHERE id=? AND user_id=?');
            $stmt->bind_param('sidii', $travel_date, $seat_quantity, $total_price, $id, $uid);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
            header('Location: index.php');
            exit;
        }
    }
}

$pageTitle = 'Edit Ticket';
require 'partials/header.php';
?>
<div class="form-card">
<h1>Edit Ticket</h1>
<?php if ($error): ?><p class="alert alert-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
<input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">
<label>Route <input type="text" value="<?= htmlspecialchars($ticket['route_name']) ?> (departs <?= htmlspecialchars($ticket['departure_time']) ?>)" disabled></label>
<label>Travel Date <input type="date" name="travel_date" id="travel-date" value="<?= htmlspecialchars($ticket['travel_date']) ?>" min="<?= date('Y-m-d') ?>" required></label>
<p class="form-hint" id="route-availability-hint"></p>
<label>Number of Seats (max 5 per departure per day) <input type="number" name="seat_quantity" id="seat-quantity" min="1" max="5" value="<?= (int)$ticket['seat_quantity'] ?>" required></label>
<button type="submit" id="submit-btn">Update Ticket</button>
</form>
<script>
(function () {
    var dateInput = document.getElementById('travel-date');
    var hint = document.getElementById('route-availability-hint');
    var submitBtn = document.getElementById('submit-btn');
    var seatInput = document.getElementById('seat-quantity');
    var tripId = <?= (int)$ticket['trip_id'] ?>;
    var excludeTicketId = <?= (int)$ticket['id'] ?>;
    var today = '<?= date('Y-m-d') ?>';
    var nowMinutes = <?= (int)date('H') * 60 + (int)date('i') ?>;
    var MAX_PER_TRIP = <?= MAX_SEATS_PER_TRIP_PER_DATE ?>;
    var departureMinutes = <?= (function () use ($ticket) {
        $parts = explode(':', $ticket['departure_time']);
        return (int)$parts[0] * 60 + (int)($parts[1] ?? 0);
    })() ?>;

    function refresh() {
        var date = dateInput.value;
        var departed = date === today && departureMinutes < nowMinutes;

        if (departed) {
            hint.textContent = 'This departure has already left today - choose a later date.';
            submitBtn.disabled = true;
            return;
        }

        submitBtn.disabled = false;
        hint.textContent = '';

        if (!date) { return; }

        fetch('route_availability.php?travel_date=' + encodeURIComponent(date) + '&exclude_ticket_id=' + excludeTicketId)
            .then(function (res) { return res.json(); })
            .then(function (data) {
                var fullTripIds = (data.full_trip_ids || []).map(String);
                var remaining = (data.remaining || {})[tripId];
                var otherSeats = (data.user_booked || {})[tripId] || 0;
                var full = fullTripIds.indexOf(String(tripId)) !== -1;
                var yourCap = Math.max(0, MAX_PER_TRIP - otherSeats);
                var cap = Math.min(5, remaining !== undefined ? remaining : 5, yourCap);

                if (full) {
                    hint.textContent = 'This departure is fully booked for that date - choose a different date.';
                    submitBtn.disabled = true;
                    return;
                }
                if (cap <= 0) {
                    hint.textContent = 'Your other tickets already use up your ' + MAX_PER_TRIP + '-seat limit on this departure for that date.';
                    submitBtn.disabled = true;
                    return;
                }

                seatInput.max = cap;
                if (parseInt(seatInput.value, 10) > cap) {
                    seatInput.value = cap;
                }
                if (otherSeats > 0) {
                    hint.textContent = 'You have ' + otherSeats + ' other seat(s) booked on this departure for this date - this ticket can hold up to ' + cap + ' (max ' + MAX_PER_TRIP + ' per departure per day).';
                } else if (remaining !== undefined) {
                    hint.textContent = remaining + ' seat(s) left on this departure for this date.';
                }
            })
            .catch(function () { /* availability check is a convenience, not required to save */ });
    }

    dateInput.addEventListener('change', refresh);
    refresh();
})();
</script>
<p><a class="btn btn-secondary btn-small" href="index.php">Back to home</a></p>
</div>
<?php require 'partials/footer.php'; ?>
