<?php
require 'config.php';
require 'auth.php';
require 'helpers.php';
require_login();

$uid = current_user_id();
$profileError = '';
$profileSuccess = '';
$passwordError = '';
$passwordSuccess = '';
$today = new DateTimeImmutable('today');
$minimum_date_of_birth = $today->modify('-150 years')->format('Y-m-d');
$maximum_date_of_birth = $today->format('Y-m-d');

$stmt = $conn->prepare('SELECT name, email, id_number, faculty, date_of_birth FROM users WHERE id = ?');
$stmt->bind_param('i', $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'profile') {
    verify_csrf();
    $name          = trim($_POST['name'] ?? '');
    $id_number     = trim($_POST['id_number'] ?? '');
    $faculty       = trim($_POST['faculty'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $date_of_birth_object = DateTimeImmutable::createFromFormat('!Y-m-d', $date_of_birth);

    if ($name === '' || $date_of_birth === '' || $id_number === '' || $faculty === '') {
        $profileError = 'All fields are required.';
    } elseif (!preg_match('/^[0-9]{2}[A-Z]{3}[0-9]{5}$/', $id_number)) {
        $profileError = 'Student ID / Staff ID must use the format 00XXX00000.';
    } elseif (!in_array($faculty, tarumt_faculties(), true)) {
        $profileError = 'Please select a valid faculty or centre.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_of_birth) ||
        !$date_of_birth_object || $date_of_birth_object->format('Y-m-d') !== $date_of_birth ||
        $date_of_birth < $minimum_date_of_birth || $date_of_birth > $maximum_date_of_birth) {
        $profileError = 'Date of birth must be between 150 years ago and today.';
    } else {
        $stmt = $conn->prepare('UPDATE users SET name = ?, id_number = ?, faculty = ?, date_of_birth = ? WHERE id = ?');
        $stmt->bind_param('ssssi', $name, $id_number, $faculty, $date_of_birth, $uid);
        $stmt->execute();
        $stmt->close();
        $_SESSION['user_name'] = $name;
        $user['name'] = $name;
        $user['id_number'] = $id_number;
        $user['faculty'] = $faculty;
        $user['date_of_birth'] = $date_of_birth;
        $profileSuccess = 'Profile updated.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'password') {
    verify_csrf();
    $current = $_POST['current_password'];
    $new = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];

    $stmt = $conn->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!password_verify($current, $row['password_hash'])) {
        $passwordError = 'Current password is incorrect.';
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}$/', $new)) {
        $passwordError = 'New password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.';
    } elseif ($new !== $confirm) {
        $passwordError = 'New passwords do not match.';
    } else {
        $newHash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->bind_param('si', $newHash, $uid);
        $stmt->execute();
        $stmt->close();
        $passwordSuccess = 'Password changed.';
    }
}

$pageTitle = 'My Account';
require 'partials/header.php';
?>
<div class="page-header">
<h1>My Account</h1>
<p>Manage your profile and password.</p>
</div>

<div class="form-card" style="margin-bottom:24px;">
<h2>Profile</h2>
<?php if ($profileError): ?><p class="alert alert-error"><?= htmlspecialchars($profileError) ?></p><?php endif; ?>
<?php if ($profileSuccess): ?><p class="alert alert-success"><?= htmlspecialchars($profileSuccess) ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
<input type="hidden" name="form" value="profile">
<label>Full Name <span class="required-mark">*</span> <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" required></label>
<label>Email <input type="email" value="<?= htmlspecialchars($user['email']) ?>" disabled></label>
<label>Student ID / Staff ID <span class="required-mark">*</span> <input type="text" name="id_number" value="<?= htmlspecialchars($user['id_number'] ?? '') ?>" pattern="[0-9]{2}[A-Z]{3}[0-9]{5}" placeholder="12ABC34567" title="Use 2 numbers, 3 uppercase letters, then 5 numbers" maxlength="10" required></label>
<label>Faculty <span class="required-mark">*</span>
<select name="faculty" required>
<option value="">-- Select Faculty / Centre --</option>
<?php foreach (tarumt_faculties() as $f): ?>
<option value="<?= htmlspecialchars($f) ?>" <?= ($user['faculty'] ?? '') === $f ? 'selected' : '' ?>><?= htmlspecialchars($f) ?></option>
<?php endforeach; ?>
</select>
</label>
<label>Date of Birth <span class="required-mark">*</span> <input type="date" name="date_of_birth" value="<?= htmlspecialchars($user['date_of_birth'] ?? '') ?>" min="<?= $minimum_date_of_birth ?>" max="<?= $maximum_date_of_birth ?>" title="Choose a date from 150 years ago up to today" required></label>
<button type="submit">Save Changes</button>
</form>
</div>

<div class="form-card">
<h2>Change Password</h2>
<?php if ($passwordError): ?><p class="alert alert-error"><?= htmlspecialchars($passwordError) ?></p><?php endif; ?>
<?php if ($passwordSuccess): ?><p class="alert alert-success"><?= htmlspecialchars($passwordSuccess) ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
<input type="hidden" name="form" value="password">
<label>Current Password <span class="required-mark">*</span>
<div class="password-field">
<input type="password" name="current_password" required>
<button type="button" class="password-toggle" tabindex="-1" aria-label="Show password"></button>
</div>
</label>
<label>New Password <span class="required-mark">*</span>
<div class="password-field">
<input type="password" name="new_password" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}" placeholder="Example: Password123!" title="At least 8 characters with uppercase, lowercase, number, and special character" required>
<button type="button" class="password-toggle" tabindex="-1" aria-label="Show password"></button>
</div>
</label>
<label>Confirm New Password <span class="required-mark">*</span>
<div class="password-field">
<input type="password" name="confirm_password" placeholder="Re-enter your password" required>
<button type="button" class="password-toggle" tabindex="-1" aria-label="Show password"></button>
</div>
</label>
<button type="submit">Change Password</button>
</form>
</div>
<?php require 'partials/footer.php'; ?>
