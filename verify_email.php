<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/toast_helper.php';

// Load config.php if present so we can redirect to the correct hosted login page.
$cfgFile = __DIR__ . '/config.php';
$cfg = null;
if (file_exists($cfgFile)) {
	$cfg = require $cfgFile;
}

$baseUrl = (is_array($cfg) && isset($cfg['app']['base_url']))
	? rtrim((string)$cfg['app']['base_url'], '/')
	: '';

$loginUrl = ($baseUrl !== '') ? ($baseUrl . '/login.html') : 'login.html';

$cn = db_connect();
if (!$cn) {
	toast_and_redirect('Database connection failed.', 'error', $loginUrl);
}

$token = trim($_GET['token'] ?? '');
if ($token === '') {
	toast_and_redirect('Missing token.', 'error', $loginUrl);
}

$tokenHash = hash('sha256', $token);

$stmt = mysqli_prepare($cn, "SELECT id, email_verified, email_verify_expires FROM tbl_login WHERE email_verify_token_hash = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 's', $tokenHash);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$row) {
	toast_and_redirect('Invalid or already-used verification link.', 'error', $loginUrl);
}

if ((int)$row['email_verified'] === 1) {
	toast_and_redirect('Your email is already verified. You can login now.', 'info', $loginUrl);
}

$expires = $row['email_verify_expires'];
if ($expires === null || strtotime($expires) < time()) {
	toast_and_redirect('This verification link has expired. Please request a new one from the login page.', 'warning', $loginUrl);
}

$upd = mysqli_prepare($cn, "UPDATE tbl_login SET email_verified = 1, email_verify_token_hash = NULL, email_verify_expires = NULL WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($upd, 'i', $row['id']);
mysqli_stmt_execute($upd);
mysqli_stmt_close($upd);

$msg = 'Email verified successfully! You can login now.';
toast_and_redirect($msg, 'success', $loginUrl);
