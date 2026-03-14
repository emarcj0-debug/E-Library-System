<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail_helper.php';

$cn = db_connect();
if (!$cn) {
	echo "<script>alert('Database connection failed.'); window.location.href='login.html';</script>";
	exit;
}

$email = trim($_POST['email'] ?? '');
if ($email === '') {
	echo "<script>alert('Please enter your email.'); window.location.href='login.html';</script>";
	exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
	echo "<script>alert('Please enter a valid email address.'); window.location.href='login.html';</script>";
	exit;
}

$stmt = mysqli_prepare($cn, "SELECT id, acct_name, email, email_verified FROM tbl_login WHERE email = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 's', $email);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$user) {
	echo "<script>alert('Account not found.'); window.location.href='login.html';</script>";
	exit;
}

if ((int)$user['email_verified'] === 1) {
	echo "<script>alert('Your email is already verified. You can login now.'); window.location.href='login.html';</script>";
	exit;
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
	echo "<script>alert('Verification email re-sent. Please check your inbox.'); window.location.href='login.html';</script>";
	exit;
} catch (Throwable $e) {
	echo "<script>alert('Failed to send email: " . addslashes($e->getMessage()) . "'); window.location.href='login.html';</script>";
	exit;
}
