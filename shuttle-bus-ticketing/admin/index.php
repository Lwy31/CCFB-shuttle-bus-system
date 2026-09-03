<?php
require '../config.php';
require '../auth.php';
require_admin();

// Fetch summary metrics
$ticketStats = $conn->query('
    SELECT 
        COUNT(*) AS total_tickets, 
        COALESCE(SUM(total_price), 0) AS total_revenue, 
        COALESCE(SUM(seat_quantity), 0) AS total_seats_sold 
    FROM tickets
')->fetch_assoc();

$todayStats = $conn->query('
    SELECT 
        COUNT(*) AS today_tickets, 
        COALESCE(SUM(total_price), 0) AS today_revenue, 
        COALESCE(SUM(seat_quantity), 0) AS today_seats 
    FROM tickets 
    WHERE travel_date = CURDATE()
')->fetch_assoc();

$totalRoutes = (int)($conn->query('SELECT COUNT(*) AS total FROM routes')->fetch_assoc()['total'] ?? 0);
$totalUsers = (int)($conn->query('SELECT COUNT(*) AS total FROM users WHERE is_admin = 0')->fetch_assoc()['total'] ?? 0);
$totalMessages = (int)($conn->query('SELECT COUNT(*) AS total FROM contact_messages')->fetch_assoc()['total'] ?? 0);

$testimonialStats = $conn->query('
    SELECT 
        COUNT(*) AS total_reviews, 
        COALESCE(AVG(rating), 0) AS avg_rating 
    FROM testimonials
')->fetch_assoc();

// Popular routes by seats booked
$popularRoutes = $conn->query('
    SELECT 
        r.id, 
        r.route_name, 
        r.origin, 
        r.destination, 
        r.departure_time, 
        r.price, 
        r.total_seats, 
        COALESCE(SUM(t.seat_quantity), 0) AS seats_booked, 
        COUNT(t.id) AS ticket_count
    FROM routes r
    LEFT JOIN tickets t ON t.route_id = r.id
    GROUP BY r.id
    ORDER BY seats_booked DESC, r.departure_time ASC
    LIMIT 5
')->fetch_all(MYSQLI_ASSOC);

// Recent bookings
$recentTickets = $conn->query('
    SELECT 
        t.id, 
        r.route_name, 
        t.travel_date, 
        t.seat_quantity, 
        t.total_price, 
        u.name AS user_name, 
        u.email AS user_email,
        t.created_at
    FROM tickets t
    JOIN routes r ON r.id = t.route_id
    JOIN users u ON u.id = t.user_id
    ORDER BY t.id DESC
    LIMIT 6
')->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'Admin Dashboard';
require 'partials/header.php';
?>
<div class="page-header">
<h1>Dashboard Overview</h1>
<p>Real-time campus shuttle operations, revenue, and booking statistics.</p>
</div>

<!-- KPI Metrics Grid -->
<div class="card-grid" style="margin-bottom: 2rem;">
    <div class="card">
        <p class="stat-label">Total Revenue</p>
        <h2 style="margin: 0.25rem 0; font-size: 2rem; color: var(--primary);">RM <?= number_format($ticketStats['total_revenue'], 2) ?></h2>
        <p class="form-hint" style="margin: 0;"><?= number_format($todayStats['today_revenue'], 2) ?> collected today</p>
    </div>
    <div class="card">
        <p class="stat-label">Tickets Sold</p>
        <h2 style="margin: 0.25rem 0; font-size: 2rem; color: var(--primary);"><?= number_format($ticketStats['total_tickets']) ?></h2>
        <p class="form-hint" style="margin: 0;"><?= (int)$todayStats['today_tickets'] ?> bookings today (<?= (int)$todayStats['today_seats'] ?> seats)</p>
    </div>
    <div class="card">
        <p class="stat-label">Active Routes</p>
        <h2 style="margin: 0.25rem 0; font-size: 2rem; color: var(--primary);"><?= $totalRoutes ?></h2>
        <p class="form-hint" style="margin: 0;"><a href="route_create.php" style="text-decoration:none;">+ Add new route</a></p>
    </div>
    <div class="card">
        <p class="stat-label">Registered Students / Staff</p>
        <h2 style="margin: 0.25rem 0; font-size: 2rem; color: var(--primary);"><?= $totalUsers ?></h2>
        <p class="form-hint" style="margin: 0;"><a href="users.php" style="text-decoration:none;">Manage user accounts &rarr;</a></p>
    </div>
</div>

<!-- Secondary Stats Row -->
<div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 2.5rem;">
    <div style="flex: 1; min-width: 250px; background: var(--bg-surface, #fff); border: 1px solid var(--border, #e2e8f0); border-radius: 8px; padding: 1rem 1.25rem;">
        <span style="font-size: 0.9rem; color: var(--text-muted, #64748b);">Contact Inbox</span>
        <div style="display: flex; justify-content: space-between; align-items: baseline; margin-top: 0.25rem;">
            <strong style="font-size: 1.4rem;"><?= $totalMessages ?> message(s)</strong>
            <a href="messages.php" class="btn btn-secondary btn-small">View Inbox</a>
        </div>
    </div>
    <div style="flex: 1; min-width: 250px; background: var(--bg-surface, #fff); border: 1px solid var(--border, #e2e8f0); border-radius: 8px; padding: 1rem 1.25rem;">
        <span style="font-size: 0.9rem; color: var(--text-muted, #64748b);">Student Satisfaction</span>
        <div style="display: flex; justify-content: space-between; align-items: baseline; margin-top: 0.25rem;">
            <strong style="font-size: 1.4rem; color: #f59e0b;">&#9733; <?= number_format($testimonialStats['avg_rating'], 1) ?> / 5.0</strong>
            <span style="font-size: 0.85rem; color: var(--text-muted, #64748b);">(<?= (int)$testimonialStats['total_reviews'] ?> reviews)</span>
        </div>
    </div>
</div>

<!-- Popular Routes Section -->
<section style="margin-bottom: 2.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h2>Popular Routes by Ridership</h2>
        <a href="routes.php" class="btn btn-secondary btn-small">All Routes</a>
    </div>
    <?php if (empty($popularRoutes)): ?>
        <p class="alert">No routes configured yet.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Route Name</th>
                    <th>Departure</th>
                    <th>Bus Capacity</th>
                    <th>Total Seats Sold</th>
                    <th>Total Orders</th>
                    <th>Fare</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($popularRoutes as $pr): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($pr['route_name']) ?></strong><br><span style="font-size: 0.85rem; color: var(--text-muted, #64748b);"><?= htmlspecialchars($pr['origin']) ?> &rarr; <?= htmlspecialchars($pr['destination']) ?></span></td>
                    <td><?= htmlspecialchars($pr['departure_time']) ?></td>
                    <td><?= (int)$pr['total_seats'] ?> seats</td>
                    <td><span class="badge badge-accent"><?= (int)$pr['seats_booked'] ?> seats</span></td>
                    <td><?= (int)$pr['ticket_count'] ?></td>
                    <td>RM <?= number_format($pr['price'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<!-- Recent Bookings Section -->
<section>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h2>Recent Bookings</h2>
        <a href="tickets.php" class="btn btn-secondary btn-small">View All Tickets</a>
    </div>
    <?php if (empty($recentTickets)): ?>
        <p class="alert">No tickets have been booked yet.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Ticket ID</th>
                    <th>Student Name</th>
                    <th>Route</th>
                    <th>Travel Date</th>
                    <th>Seats</th>
                    <th>Total Paid</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentTickets as $rt): ?>
                <tr>
                    <td>#<?= (int)$rt['id'] ?></td>
                    <td><strong><?= htmlspecialchars($rt['user_name']) ?></strong><br><span style="font-size: 0.85rem; color: var(--text-muted, #64748b);"><?= htmlspecialchars($rt['user_email']) ?></span></td>
                    <td><?= htmlspecialchars($rt['route_name']) ?></td>
                    <td><?= htmlspecialchars($rt['travel_date']) ?></td>
                    <td><?= (int)$rt['seat_quantity'] ?></td>
                    <td>RM <?= number_format($rt['total_price'], 2) ?></td>
                    <td>
                        <form action="ticket_cancel.php" method="post" style="display:inline" onsubmit="return confirm('Cancel this ticket?');">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="id" value="<?= (int)$rt['id'] ?>">
                            <button type="submit" class="btn-small btn-danger">Cancel</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php require 'partials/footer.php'; ?>
