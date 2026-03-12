<?php
// Central DB config for the E-Library project.
// Update these values to match your phpMyAdmin/MySQL setup.

$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'db_library';

/**
 * Returns a mysqli connection or null if connection fails.
 * Shows a user-friendly message instead of a fatal error.
 */
function db_connect(): ?mysqli {
	global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME;

	// Use exceptions so we can catch and display a friendly message.
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

	try {
		$cn = mysqli_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
		mysqli_set_charset($cn, 'utf8mb4');
		return $cn;
	} catch (mysqli_sql_exception $e) {
		// Typical case here is: Unknown database 'db_library'
		// Don't expose stack traces to end users.
		echo "<script>alert('Database connection failed. Create/import the database and make sure DB_NAME in db.php is correct.');</script>";
		return null;
	}
}
