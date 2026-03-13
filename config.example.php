<?php
/**
 * config.example.php
 *
 * Copy to config.php (do NOT commit config.php).
 * Put your InfinityFree database + SMTP settings here.
 */

return [
	'app' => [
		'base_url' => 'https://yourdomain.com',
	],
	'db' => [
		'host' => 'sqlXXX.infinityfree.com',
		'port' => 3306,
		'name' => 'epiz_XXXXXXX_db_library',
		'user' => 'epiz_XXXXXXX',
		'pass' => 'YOUR_DB_PASSWORD',
	],
	'smtp' => [
		'host' => 'smtp.gmail.com',
		'port' => 587,
		'username' => 'your_email@gmail.com',
		'password' => 'YOUR_APP_PASSWORD',
		'encryption' => 'tls',
		'from_email' => 'your_email@gmail.com',
		'from_name' => 'E-Library',
	],
];
