<?php
/**
 * index.php – Handles the student login POST from login.html.
 * On success → student_dashboard.php
 * On failure → back to login.html with alert.
 */

session_start();
require_once __DIR__ . '/db.php';

$cn = db_connect();

if (!$cn) {
	echo "<script>alert(" . json_encode(db_friendly_error_message()) . "); window.location.href='login.html';</script>";
	exit;
}

if (isset($_POST['btnlogin'])) {
	$email = trim($_POST['txtemail'] ?? '');
	$password = trim($_POST['txtpass'] ?? '');

	if ($email === '' || $password === '') {
		echo "<script>alert('Please enter email and password.'); window.location.href='login.html';</script>";
		exit;
	}

	if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
		echo "<script>alert('Please enter a valid email address.'); window.location.href='login.html';</script>";
		exit;
	}

	$stmt = mysqli_prepare($cn, "SELECT id, acct_name, email_verified FROM tbl_login WHERE email = ? AND password = ? LIMIT 1");

	if (!$stmt) {
		echo "<script>alert('Server error.'); window.location.href='login.html';</script>";
		exit;
	}

	mysqli_stmt_bind_param($stmt, 'ss', $email, $password);
	mysqli_stmt_execute($stmt);
	$result = mysqli_stmt_get_result($stmt);
	$row    = mysqli_fetch_assoc($result);
	mysqli_stmt_close($stmt);

	if ($row) {
		// Require verified email before allowing login
		if ((int)($row['email_verified'] ?? 0) !== 1) {
			echo "<script>alert('Please verify your email before logging in. Click OK to resend verification.'); window.location.href='login.html?verify=1&e=" . urlencode($email) . "';</script>";
			exit;
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