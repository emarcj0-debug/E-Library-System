<?php
/**
 * auth.php – Tiny session helper.
 * Include at the top of any protected page.
 *
 * Usage:
 *   require_once __DIR__ . '/auth.php';
 *   require_role('student');   // or 'admin'
 */

if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

/**
 * Redirect to the appropriate login page if the user
 * doesn't have the required role stored in the session.
 */
function require_role(string $role): void {
	if (!isset($_SESSION['role']) || $_SESSION['role'] !== $role) {
		$target = ($role === 'admin') ? 'admin.php' : 'login.html';
		header("Location: $target");
		exit;
	}
}

/**
 * Destroy the session and redirect to home.
 */
function logout(): void {
	session_unset();
	session_destroy();
	header('Location: home.html');
	exit;
}
