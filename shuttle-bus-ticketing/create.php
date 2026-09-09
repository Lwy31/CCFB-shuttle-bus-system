<?php
require 'config.php';
require 'auth.php';
require 'helpers.php';
require_login();

$error = '';
$selectedTrip  = (int)($_GET['trip_id'] ?? 0);
$selectedDate  = $_GET['travel_date'] ?? date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $trip_id       = (int)($_POST['trip_id'] ?? 0);
    $travel_date   = $_POST['travel_date'] ?? '';
    $seat_quantity = (int)($_POST['seat_quantity'] ?? 0);
    $uid           = current_user_id();
    $selectedTrip  = $trip_id;
    $selectedDate  = $travel_date;

    if ($travel_date === '' || $seat_quantity < 1 || $seat_quantity > 5) {
        $error = 'Please choose a travel date and between 1 and 5 seats.';
    } elseif ($travel_date < date('Y-m-d')) {
        $error = 'Travel date cannot be in the past.';
    } else {
        $conn->begin_transaction();

        // A trip is a specific route + departure time - price/capacity live
        // on the parent route, so join them here.
        $stmt = $conn->prepare('SELECT r.price, r.total_seats, t.departure_time FROM trips t JOIN routes r ON r.id = t.route_id WHERE t.id = ? FOR UPDATE');
        $stmt->bind_param('i', $trip_id);
        $stmt->execute();
        $trip = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$trip) {
            $error = 'Departure not found.';
            $conn->rollback();
        } elseif (is_departure_in_past($travel_date, $trip['departure_time'])) {
            $error = 'This departure has already left today. Please choose a later departure or date.';
            $conn->rollback();
        } else {
            $userBooked = user_seats_booked($conn, $uid, $trip_id, $travel_date);

            $stmt = $conn->prepare('SELECT COALESCE(SUM(seat_quantity), 0) AS booked FROM tickets WHERE trip_id = ? AND travel_date = ?');
            $stmt->bind_param('is', $trip_id, $travel_date);
            $stmt->execute();
            $booked = (int)$stmt->get_result()->fetch_assoc()['booked'];
            $stmt->close();

            if ($userBooked + $seat_quantity > MAX_SEATS_PER_TRIP_PER_DATE) {
                $left = max(0, MAX_SEATS_PER_TRIP_PER_DATE - $userBooked);
                $error = $left > 0
                    ? "You already have $userBooked seat(s) booked on this departure for that date - you can book $left more (max " . MAX_SEATS_PER_TRIP_PER_DATE . ' per departure per day).'
                    : 'You have already booked the maximum of ' . MAX_SEATS_PER_TRIP_PER_DATE . ' seat(s) on this departure for that date.';
                $conn->rollback();
            } elseif ($booked + $seat_quantity > $trip['total_seats']) {
                $available = $trip['total_seats'] - $booked;
                $error = $available > 0
                    ? "Only $available seat(s) remaining on this departure for that date."
                    : 'This departure is fully booked for that date.';
                $conn->rollback();
            } else {
                $total_price = $trip['price'] * $seat_quantity;

                $stmt = $conn->prepare('INSERT INTO tickets (user_id, trip_id, travel_date, seat_quantity, total_price) VALUES (?, ?, ?, ?, ?)');
                $stmt->bind_param('iisid', $uid, $trip_id, $travel_date, $seat_quantity, $total_price);
                $stmt->execute();
                $stmt->close();
                $conn->commit();
                header('Location: index.php');
                exit;
            }
        }
    }
}

$routes = $conn->query('SELECT id, route_name, price FROM routes ORDER BY route_name')->fetch_all(MYSQLI_ASSOC);
$allTrips = $conn->query('SELECT id, route_id, departure_time FROM trips ORDER BY departure_time')->fetch_all(MYSQLI_ASSOC);

// Group every trip under its route id so the "Departure" dropdown can be
// repopulated in JS to show only the times that belong to whichever route
// is currently selected - not every route's times mixed together.
$tripsByRoute = [];
foreach ($allTrips as $t) {
    $tripsByRoute[(int)$t['route_id']][] = $t;
}

// If we arrived here via a specific trip (e.g. "Book" from the homepage),
// pre-select that trip's route so the two dropdowns start in sync.
$selectedRoute = 0;
foreach ($allTrips as $t) {
    if ((int)$t['id'] === $selectedTrip) {
        $selectedRoute = (int)$t['route_id'];
        break;
    }
}
if ($selectedRoute === 0 && !empty($routes)) {
    $selectedRoute = (int)$routes[0]['id'];
}

$pageTitle = 'Book Shuttle Ticket';
require 'partials/header.php';
?>
<div class="form-card">
<h1>Book a Shuttle Ticket</h1>
<?php if ($error): ?><p class="alert alert-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="post" id="ticket-form">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
<input type="hidden" name="trip_id" id="trip-id-field" value="<?= (int)$selectedTrip ?>">
<label>Route
<select id="route-picker">
<?php foreach ($routes as $r): ?>
<option value="<?= (int)$r['id'] ?>" <?= $r['id'] == $selectedRoute ? 'selected' : '' ?>><?= htmlspecialchars($r['route_name']) ?> - RM<?= number_format($r['price'], 2) ?></option>
<?php endforeach; ?>
</select>
</label>
<label>Departure Time
<select id="trip-picker" required></select>
</label>
<label>Travel Date <input type="date" name="travel_date" id="travel-date" value="<?= htmlspecialchars($selectedDate) ?>" min="<?= date('Y-m-d') ?>" required></label>
<p class="form-hint" id="route-availability-hint"></p>
<label>Number of Seats (max 5 per departure per day) <input type="number" name="seat_quantity" id="seat-quantity" min="1" max="5" value="1" required></label>
<button type="submit">Book Ticket</button>
</form>
<script>
(function () {
    var TRIPS_BY_ROUTE = <?= json_encode($tripsByRoute) ?>;
    var SELECTED_TRIP = <?= (int)$selectedTrip ?>;

    var routePicker = document.getElementById('route-picker');
    var tripPicker = document.getElementById('trip-picker');
    var tripIdField = document.getElementById('trip-id-field');
    var dateInput = document.getElementById('travel-date');
    var hint = document.getElementById('route-availability-hint');
    var seatInput = document.getElementById('seat-quantity');
    var today = '<?= date('Y-m-d') ?>';
    var nowMinutes = <?= (int)date('H') * 60 + (int)date('i') ?>;
    var MAX_PER_TRIP = <?= MAX_SEATS_PER_TRIP_PER_DATE ?>;

    function departureMinutes(value) {
        var parts = value.split(':');
        return (parseInt(parts[0], 10) * 60) + parseInt(parts[1], 10);
    }

    // Rebuilds the "Departure Time" dropdown to show only the trips that
    // belong to whichever route is currently picked.
    function populateTripPicker(preferredTripId) {
        var trips = TRIPS_BY_ROUTE[routePicker.value] || [];
        tripPicker.innerHTML = '';
        trips.forEach(function (t) {
            var opt = document.createElement('option');
            opt.value = t.id;
            opt.dataset.departure = t.departure_time;
            opt.dataset.originalText = 'Departs ' + t.departure_time;
            opt.textContent = opt.dataset.originalText;
            if (String(t.id) === String(preferredTripId)) {
                opt.selected = true;
            }
            tripPicker.appendChild(opt);
        });
        tripIdField.value = tripPicker.value || '';
    }

    function resetTripOptions() {
        Array.prototype.forEach.call(tripPicker.options, function (opt) {
            if (opt.dataset.originalText) {
                opt.textContent = opt.dataset.originalText;
            }
            opt.disabled = false;
        });
        hint.textContent = '';
    }

    function markDisabled(opt, label) {
        opt.textContent = opt.dataset.originalText + ' (' + label + ')';
        opt.disabled = true;
    }

    function annotate(opt, label) {
        opt.textContent = opt.dataset.originalText + ' (' + label + ')';
    }

    // How many more seats the current user could still add to whichever
    // departure is selected, given both its remaining capacity and their
    // own personal per-trip-per-day cap. Also updates the seat input's max
    // attribute + hint text to match.
    function updateSeatLimit(remaining, userBooked) {
        var tripId = tripPicker.value;
        if (!tripId) {
            seatInput.max = 5;
            return;
        }
        var tripLeft = remaining && remaining[tripId] !== undefined ? remaining[tripId] : Infinity;
        var yourLeft = MAX_PER_TRIP - (userBooked && userBooked[tripId] ? userBooked[tripId] : 0);
        var cap = Math.max(0, Math.min(5, tripLeft, yourLeft));
        seatInput.max = cap;
        if (seatInput.value === '' || parseInt(seatInput.value, 10) > cap) {
            seatInput.value = cap > 0 ? cap : 1;
        }

        var youHave = userBooked && userBooked[tripId] ? userBooked[tripId] : 0;
        if (youHave > 0) {
            hint.textContent = 'You already hold ' + youHave + ' seat(s) on this departure for this date - you can book ' + Math.max(0, yourLeft) + ' more (max ' + MAX_PER_TRIP + ' per departure per day).';
        }
    }

    function refreshAvailability() {
        var date = dateInput.value;
        var isToday = date === today;

        resetTripOptions();

        if (isToday) {
            Array.prototype.forEach.call(tripPicker.options, function (opt) {
                if (opt.dataset.departure && departureMinutes(opt.dataset.departure) < nowMinutes) {
                    markDisabled(opt, 'Departed');
                }
            });
        }

        if (!date) { seatInput.max = 5; return; }

        fetch('route_availability.php?travel_date=' + encodeURIComponent(date))
            .then(function (res) { return res.json(); })
            .then(function (data) {
                var fullTripIds = (data.full_trip_ids || []).map(String);
                var remaining = data.remaining || {};
                var userBooked = data.user_booked || {};

                Array.prototype.forEach.call(tripPicker.options, function (opt) {
                    if (opt.disabled) { return; }
                    if (fullTripIds.indexOf(opt.value) !== -1) {
                        markDisabled(opt, 'Fully Booked');
                    } else if (userBooked[opt.value] >= MAX_PER_TRIP) {
                        markDisabled(opt, 'Your limit reached');
                    } else if (remaining[opt.value] !== undefined) {
                        annotate(opt, remaining[opt.value] + ' left');
                    }
                });
                hint.textContent = 'Greyed-out departures have already left today, are fully booked, or you\'ve reached your ' + MAX_PER_TRIP + '-seat limit on them for this date.';
                updateSeatLimit(remaining, userBooked);
            })
            .catch(function () { /* availability check is a convenience, not required to book */ });
    }

    routePicker.addEventListener('change', function () {
        populateTripPicker(null);
        refreshAvailability();
    });
    tripPicker.addEventListener('change', function () {
        tripIdField.value = tripPicker.value;
        refreshAvailability();
    });
    dateInput.addEventListener('change', refreshAvailability);

    populateTripPicker(SELECTED_TRIP);
    refreshAvailability();
})();
</script>
<p><a class="btn btn-secondary btn-small" href="index.php">Back to home</a></p>
</div>
<?php require 'partials/footer.php'; ?>