<?php
/**
 * mail_config.example.php
 *
 * Copy this file to mail_config.php and fill in your SMTP details,
 * OR set the environment variables listed below.
 *
 * IMPORTANT: Never commit mail_config.php to GitHub. It's in .gitignore.
 */

$baseUrl = getenv('APP_BASE_URL') ?: 'http://localhost/library';

return [
	'app' => [
		'base_url' => $baseUrl,
	],
	'smtp' => [
		'host' => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
		'port' => (int)(getenv('SMTP_PORT') ?: 587),
		'username' => getenv('SMTP_USERNAME') ?: '',
		'password' => getenv('SMTP_PASSWORD') ?: '',
		'encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls', // tls|ssl
		'from_email' => getenv('SMTP_FROM_EMAIL') ?: (getenv('SMTP_USERNAME') ?: ''),
		'from_name' => getenv('SMTP_FROM_NAME') ?: 'E-Library',
	],
];
