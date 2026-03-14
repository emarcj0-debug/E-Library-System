<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail_helper.php';
require_once __DIR__ . '/toast_helper.php';

$cn = db_connect();
if (!$cn) {
	toast_and_redirect('Database connection failed.', 'error', 'login.html');
}

$email = trim($_POST['email'] ?? '');
if ($email === '') {
	toast_and_redirect('Please enter your email.', 'warning', 'login.html');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
	toast_and_redirect('Please enter a valid email address.', 'warning', 'login.html');
}

$stmt = mysqli_prepare($cn, "SELECT id, acct_name, email, email_verified FROM tbl_login WHERE email = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 's', $email);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$user) {
	toast_and_redirect('Account not found.', 'error', 'login.html');
}

if ((int)$user['email_verified'] === 1) {
	toast_and_redirect('Your email is already verified. You can login now.', 'info', 'login.html');
}

// Generate a fresh token
$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$expires = date('Y-m-d H:i:s', time() + 60 * 60); // 1 hour

$upd = mysqli_prepare($cn, "UPDATE tbl_login SET email_verify_token_hash = ?, email_verify_expires = ? WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($upd, 'ssi', $tokenHash, $expires, $user['id']);
mysqli_stmt_execute($upd);
mysqli_stmt_close($upd);

$cfgFile = __DIR__ . '/config.php';
$cfg = null;
if (file_exists($cfgFile)) {
	$cfg = require $cfgFile;
}
if (!is_array($cfg)) {
	$cfg = require __DIR__ . '/mail_config.php';
}
$baseUrl = rtrim(($cfg['app']['base_url'] ?? 'http://localhost/library'), '/');
$verifyLink = $baseUrl . '/verify_email.php?token=' . urlencode($token);

try {
	send_verification_email($user['email'], $user['acct_name'], $verifyLink);
	toast_and_redirect('Verification email re-sent. Please check your inbox.', 'success', 'login.html');
} catch (Throwable $e) {
	toast_and_redirect('Failed to send email: ' . $e->getMessage(), 'error', 'login.html', 4500);
}
