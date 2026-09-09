<?php
require 'config.php';
require 'auth.php';
require 'helpers.php';
$error = '';
$today = new DateTimeImmutable('today');
$minimum_date_of_birth = $today->modify('-150 years')->format('Y-m-d');
$maximum_date_of_birth = $today->format('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name          = trim($_POST['name'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $password      = $_POST['password'] ?? '';
    $confirm       = $_POST['confirm_password'] ?? '';
    $id_number     = trim($_POST['id_number'] ?? '');
    $faculty       = trim($_POST['faculty'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $date_of_birth_object = DateTimeImmutable::createFromFormat('!Y-m-d', $date_of_birth);

    if ($name === '' || $email === '' || $password === '' || $date_of_birth === '' || $id_number === '' || $faculty === '') {
        $error = 'All fields are required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_of_birth) ||
        !$date_of_birth_object || $date_of_birth_object->format('Y-m-d') !== $date_of_birth ||
        $date_of_birth < $minimum_date_of_birth || $date_of_birth > $maximum_date_of_birth) {
        $error = 'Date of birth must be between 150 years ago and today.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^[0-9]{2}[A-Z]{3}[0-9]{5}$/', $id_number)) {
        $error = 'Student ID / Staff ID must use the format 000XXX00000.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}$/', $password)) {
        $error = 'Password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.';
    } else {
        $stmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($exists) {
            $error = 'An account with this email already exists.';
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('INSERT INTO users (name, email, password_hash, id_number, faculty, date_of_birth) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('ssssss', $name, $email, $password_hash, $id_number, $faculty, $date_of_birth);
            $stmt->execute();
            $user_id = $stmt->insert_id;
            $stmt->close();

            session_regenerate_id(true);
            $_SESSION['user_id'] = $user_id;
            $_SESSION['user_name'] = $name;
            $_SESSION['is_admin'] = false;
            header('Location: index.php');
            exit;
        }
    }
}

$pageTitle = 'Register';
require 'partials/header.php';
?>
<div class="auth-card">
<h1>Create an Account</h1>
<?php if ($error): ?><p class="alert alert-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
<label>Full Name <span class="required-mark">*</span> <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required></label>
<label>Email <span class="required-mark">*</span> <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" pattern="[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+" title="Enter an email address such as name@example.com" required></label>
<label>Student ID / Staff ID <span class="required-mark">*</span> <input type="text" name="id_number" value="<?= htmlspecialchars($_POST['id_number'] ?? '') ?>" pattern="[0-9]{2}[A-Z]{3}[0-9]{5}" placeholder="123ABC45678" title="Use 2 numbers, 3 uppercase letters, then 5 numbers" maxlength="10" required></label>
<label>Faculty <span class="required-mark">*</span>
<select name="faculty" required>
<option value="">-- Select Faculty / Centre --</option>
<?php foreach (tarumt_faculties() as $f): ?>
<option value="<?= htmlspecialchars($f) ?>" <?= ($_POST['faculty'] ?? '') === $f ? 'selected' : '' ?>><?= htmlspecialchars($f) ?></option>
<?php endforeach; ?>
</select>
</label>
<label>Date of Birth <span class="required-mark">*</span> <input type="date" name="date_of_birth" value="<?= htmlspecialchars($_POST['date_of_birth'] ?? '') ?>" min="<?= $minimum_date_of_birth ?>" max="<?= $maximum_date_of_birth ?>" title="Choose a date from 150 years ago up to today" required></label>
<label>Password <span class="required-mark">*</span>
<div class="password-field">
<input type="password" name="password" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}" placeholder="Example: Password123!" title="At least 8 characters with uppercase, lowercase, number, and special character" required>
<button type="button" class="password-toggle" tabindex="-1" aria-label="Show password"></button>
</div>
</label>
<label>Confirm Password <span class="required-mark">*</span>
<div class="password-field">
<input type="password" name="confirm_password" placeholder="Re-enter your password" required>
<button type="button" class="password-toggle" tabindex="-1" aria-label="Show password"></button>
</div>
</label>
<button type="submit">Register</button>
</form>
<p>Already have an account? <a href="login.php">Login here</a></p>
</div>
<?php require 'partials/footer.php'; ?>
