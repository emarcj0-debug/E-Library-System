<?php
/**
 * mail_config.example.php
 *
 * Copy this file to mail_config.php and fill in your SMTP details,
 * OR set the environment variables listed below.
 *
 * IMPORTANT: Never commit mail_config.php to GitHub. It's in .gitignore.
 */

$cfgFile = __DIR__ . '/config.php';
$cfg = null;
if (file_exists($cfgFile)) {
	$cfg = require $cfgFile;
}

$baseUrl = (is_array($cfg) && isset($cfg['app']['base_url']))
	? (string)$cfg['app']['base_url']
	: (getenv('APP_BASE_URL') ?: 'http://localhost/library');

return [
	'app' => [
		'base_url' => $baseUrl,
	],
	'smtp' => [
		'host' => (string)((is_array($cfg) ? ($cfg['smtp']['host'] ?? null) : null) ?? (getenv('SMTP_HOST') ?: 'smtp.gmail.com')),
		'port' => (int)((is_array($cfg) ? ($cfg['smtp']['port'] ?? null) : null) ?? (getenv('SMTP_PORT') ?: 587)),
		'username' => (string)((is_array($cfg) ? ($cfg['smtp']['username'] ?? null) : null) ?? (getenv('SMTP_USERNAME') ?: '')),
		'password' => (string)((is_array($cfg) ? ($cfg['smtp']['password'] ?? null) : null) ?? (getenv('SMTP_PASSWORD') ?: '')),
		'encryption' => (string)((is_array($cfg) ? ($cfg['smtp']['encryption'] ?? null) : null) ?? (getenv('SMTP_ENCRYPTION') ?: 'tls')),
		'from_email' => (string)((is_array($cfg) ? ($cfg['smtp']['from_email'] ?? null) : null) ?? (getenv('SMTP_FROM_EMAIL') ?: (getenv('SMTP_USERNAME') ?: ''))),
		'from_name' => (string)((is_array($cfg) ? ($cfg['smtp']['from_name'] ?? null) : null) ?? (getenv('SMTP_FROM_NAME') ?: 'E-Library')),
	],
];
