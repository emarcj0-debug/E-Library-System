<?php
/**
 * Vercel front controller.
 *
 * Vercel serverless functions need a single entrypoint.
 * This file routes requests to your existing PHP/HTML pages.
 */

$requested = $_GET['page'] ?? 'home.html';
$requested = ltrim((string)$requested, '/');

// Default documents
if ($requested === '' || $requested === '/') {
	$requested = 'home.html';
}

// Block direct access to sensitive/internal files
$blocked = [
	'.env',
	'.env.example',
	'composer.json',
	'composer.lock',
	'vercel.json',
	'setup.sql',
	'mail_config.php',
	'mail_config.example.php',
	'README.md',
];
foreach ($blocked as $b) {
	if (strcasecmp($requested, $b) === 0) {
		http_response_code(404);
		echo "Not found";
		exit;
	}
}

// Prevent path traversal
if (str_contains($requested, '..')) {
	http_response_code(400);
	echo "Bad request";
	exit;
}

$root = dirname(__DIR__);
$full = $root . DIRECTORY_SEPARATOR . $requested;

if (!file_exists($full)) {
	http_response_code(404);
	echo "Not found";
	exit;
}

$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));

// Force dynamic pages through PHP; static HTML is echoed.
if ($ext === 'php') {
	require $full;
	return;
}

if ($ext === 'html') {
	header('Content-Type: text/html; charset=UTF-8');
	readfile($full);
	return;
}

// Anything else isn't served by the function.
http_response_code(404);
echo "Not found";
