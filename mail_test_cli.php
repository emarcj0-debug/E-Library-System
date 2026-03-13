<?php
/**
 * mail_test_cli.php
 *
 * CLI-friendly SMTP test:
 *   php mail_test_cli.php you@example.com
 */

require_once __DIR__ . '/mail_helper.php';

if ((getenv('APP_ENV') ?: 'production') === 'production') {
	fwrite(STDERR, "This script is disabled in production (APP_ENV=production).\n");
	exit(3);
}

$to = $argv[1] ?? '';
$to = trim($to);

if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
	fwrite(STDERR, "Usage: php mail_test_cli.php you@example.com\n");
	exit(2);
}

try {
	send_verification_email($to, 'Test User', 'http://localhost/library/verify_email.php?token=test');
	echo "OK: email sent (or accepted by SMTP) to {$to}\n";
	exit(0);
} catch (Throwable $e) {
	fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
	exit(1);
}
