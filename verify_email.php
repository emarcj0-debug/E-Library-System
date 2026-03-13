<?php
require_once __DIR__ . '/db.php';

$cn = db_connect();
if (!$cn) {
	echo "Database connection failed.";
	exit;
}

$token = trim($_GET['token'] ?? '');
if ($token === '') {
	echo "Missing token.";
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
	echo "Invalid or already-used verification link.";
	exit;
}

if ((int)$row['email_verified'] === 1) {
	echo "Your email is already verified. You can login now. <a href=\"login.html\">Go to login</a>.";
	exit;
}

$expires = $row['email_verify_expires'];
if ($expires === null || strtotime($expires) < time()) {
	echo "This verification link has expired. Please request a new one from the login page.";
	exit;
}

$upd = mysqli_prepare($cn, "UPDATE tbl_login SET email_verified = 1, email_verify_token_hash = NULL, email_verify_expires = NULL WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($upd, 'i', $row['id']);
mysqli_stmt_execute($upd);
mysqli_stmt_close($upd);

echo "Email verified successfully. You can login now. <a href=\"login.html\">Go to login</a>.";
