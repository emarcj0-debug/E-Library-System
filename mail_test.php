<?php
/**
 * mail_test.php
 *
 * Quick SMTP test for PHPMailer.
 * Visit: http://localhost/library/mail_test.php?to=you@example.com
 */

require_once __DIR__ . '/mail_helper.php';

if ((getenv('APP_ENV') ?: 'production') === 'production') {
	header('HTTP/1.1 404 Not Found');
	header('Content-Type: text/plain');
	echo "Not found\n";
	exit;
}

$to = trim($_GET['to'] ?? '');
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
	header('Content-Type: text/plain');
	echo "Usage: /mail_test.php?to=you@example.com\n";
	exit;
}

try {
	send_verification_email($to, 'Test User', 'http://localhost/library/verify_email.php?token=test');
	header('Content-Type: text/plain');
	echo "OK: email sent (or accepted by SMTP) to $to\n";
} catch (Throwable $e) {
	header('Content-Type: text/plain');
	echo "FAILED:\n";
	echo $e->getMessage() . "\n";
}
