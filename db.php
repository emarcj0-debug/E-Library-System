<?php
/**
 * db.php
 * Central DB config for the E-Library project.
 *
 * Hosting-friendly: reads credentials from environment variables.
 * Falls back to XAMPP defaults for local development.
 */

/**
 * Read an env var with a fallback.
 */
function env(string $key, ?string $default = null): ?string
{
	$val = getenv($key);
	if ($val === false || $val === '') {
		return $default;
	}
	return $val;
}

/**
 * Returns a mysqli connection or null if connection fails.
 *
 * Note: we intentionally don't echo JS alerts here because many pages redirect
 * with headers; callers can show a friendly message if $cn is null.
 */
function db_connect(): ?mysqli
{
	// Use exceptions so we can catch and display a friendly message.
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

	$DB_HOST = env('DB_HOST', 'localhost');
	$DB_USER = env('DB_USER', 'root');
	$DB_PASS = env('DB_PASS', '');
	$DB_NAME = env('DB_NAME', 'db_library');
	$DB_PORT = (int)(env('DB_PORT', '3306') ?? 3306);

	try {
		$cn = mysqli_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
		mysqli_set_charset($cn, 'utf8mb4');
		return $cn;
	} catch (mysqli_sql_exception $e) {
		return null;
	}
}
