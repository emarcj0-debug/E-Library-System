<?php
require_once __DIR__ . '/db.php';

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
	$msg = 'Database connection failed.';
	echo "<script>alert(" . json_encode($msg) . "); window.location.href=" . json_encode($loginUrl) . ";</script>";
	exit;
}

$token = trim($_GET['token'] ?? '');
if ($token === '') {
	$msg = 'Missing token.';
	echo "<script>alert(" . json_encode($msg) . "); window.location.href=" . json_encode($loginUrl) . ";</script>";
	exit;
}

$tokenHash = hash('sha256', $token);

$stmt = mysqli_prepare($cn, "SELECT id, email_verified, email_verify_expires FROM tbl_login WHERE email_verify_token_hash = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 's', $tokenHash);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$row) {
	$msg = 'Invalid or already-used verification link.';
	echo "<script>alert(" . json_encode($msg) . "); window.location.href=" . json_encode($loginUrl) . ";</script>";
	exit;
}

if ((int)$row['email_verified'] === 1) {
	$msg = 'Your email is already verified. You can login now.';
	echo "<script>alert(" . json_encode($msg) . "); window.location.href=" . json_encode($loginUrl) . ";</script>";
	exit;
}

$expires = $row['email_verify_expires'];
if ($expires === null || strtotime($expires) < time()) {
	$msg = 'This verification link has expired. Please request a new one from the login page.';
	echo "<script>alert(" . json_encode($msg) . "); window.location.href=" . json_encode($loginUrl) . ";</script>";
	exit;
}

$upd = mysqli_prepare($cn, "UPDATE tbl_login SET email_verified = 1, email_verify_token_hash = NULL, email_verify_expires = NULL WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($upd, 'i', $row['id']);
mysqli_stmt_execute($upd);
mysqli_stmt_close($upd);

$msg = 'Email verified successfully! You can login now.';
echo "<script>alert(" . json_encode($msg) . "); window.location.href=" . json_encode($loginUrl) . ";</script>";
