<?php
// Sessions are stored in the database (not local disk) so that any EC2
// instance behind an ALB/ASG can read a session written by a different
// instance - PHP's default file-based sessions only live on the instance
// that created them, so a request an ALB routes to a different instance
// would otherwise see the user as logged out. See schema.sql's
// `sessions` table.
class DbSessionHandler implements SessionHandlerInterface {
    private $conn;
    private array $lastReadData = [];
    private array $lastReadTime = [];

    public function __construct($conn) {
        $this->conn = $conn;
    }

    public function open($path, $name): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    public function read($id): string {
        $stmt = $this->conn->prepare('SELECT data, last_activity FROM sessions WHERE id = ?');
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $this->lastReadData[$id] = $row['data'];
            $this->lastReadTime[$id] = (int)$row['last_activity'];
            return $row['data'];
        }
        $this->lastReadData[$id] = null;
        $this->lastReadTime[$id] = 0;
        return '';
    }

    public function write($id, $data): bool {
        $now = time();

        // Performance optimization: If session data has not changed and the session was
        // refreshed recently (within 5 minutes / 300 seconds), skip the DB write.
        // This eliminates redundant DB writes on read-only page loads during high-traffic surges.
        if (
            isset($this->lastReadData[$id]) &&
            $this->lastReadData[$id] === $data &&
            ($now - ($this->lastReadTime[$id] ?? 0)) < 300
        ) {
            return true;
        }

        $stmt = $this->conn->prepare('INSERT INTO sessions (id, data, last_activity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data), last_activity = VALUES(last_activity)');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ssi', $id, $data, $now);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) {
            $this->lastReadData[$id] = $data;
            $this->lastReadTime[$id] = $now;
        }
        return $ok;
    }

    public function destroy($id): bool {
        unset($this->lastReadData[$id], $this->lastReadTime[$id]);
        $stmt = $this->conn->prepare('DELETE FROM sessions WHERE id = ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function gc($maxLifetime): int|false {
        $threshold = time() - $maxLifetime;
        $stmt = $this->conn->prepare('DELETE FROM sessions WHERE last_activity < ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $threshold);
        $stmt->execute();
        $count = $stmt->affected_rows;
        $stmt->close();
        return $count;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_set_save_handler(new DbSessionHandler($conn), true);
    register_shutdown_function('session_write_close');
    session_start();
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(403);
            die('Invalid or expired request token. Please refresh the page and try again.');
        }
    }
}

function current_user_id() {
    return $_SESSION['user_id'] ?? null;
}

function current_user_name() {
    return $_SESSION['user_name'] ?? null;
}

function current_user_is_admin() {
    return !empty($_SESSION['is_admin']);
}

function require_login() {
    if (!current_user_id()) {
        header('Location: login.php');
        exit;
    }
}

function require_admin() {
    require_login();
    if (!current_user_is_admin()) {
        http_response_code(403);
        die('Admins only.');
    }
}
