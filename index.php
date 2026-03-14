<?php
/**
 * index.php – Handles the student login POST from login.html.
 * On success → student_dashboard.php
 * On failure → back to login.html with alert.
 */

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/toast_helper.php';

$cn = db_connect();

if (!$cn) {
	toast_and_redirect(db_friendly_error_message(), 'error', 'login.html');
}

if (isset($_POST['btnlogin'])) {
	$email = trim($_POST['txtemail'] ?? '');
	$password = trim($_POST['txtpass'] ?? '');

	if ($email === '' || $password === '') {
		toast_and_redirect('Please enter email and password.', 'warning', 'login.html');
	}

	if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
		toast_and_redirect('Please enter a valid email address.', 'warning', 'login.html');
	}

	$stmt = mysqli_prepare($cn, "SELECT id, acct_name, email_verified FROM tbl_login WHERE email = ? AND password = ? LIMIT 1");

	if (!$stmt) {
		toast_and_redirect('Server error.', 'error', 'login.html');
	}

	mysqli_stmt_bind_param($stmt, 'ss', $email, $password);
	mysqli_stmt_execute($stmt);
	$result = mysqli_stmt_get_result($stmt);
	$row    = mysqli_fetch_assoc($result);
	mysqli_stmt_close($stmt);

	if ($row) {
		// Require verified email before allowing login
		if ((int)($row['email_verified'] ?? 0) !== 1) {
			toast_and_redirect('Please verify your email before logging in. Use the form below to resend the verification email.', 'warning', 'login.html?verify=1&e=' . urlencode($email));
		}

		$_SESSION['role']      = 'student';
		$_SESSION['user_id']   = $row['id'];
		$_SESSION['acct_name'] = $row['acct_name'];
		$_SESSION['email']     = $email;
		header('Location: student_dashboard.php');
		exit;
	} else {
		header('Location: login.html?err=1');
		exit;
	}
} else {
	header('Location: login.html');
	exit;
}