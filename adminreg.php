<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/toast_helper.php';
require_role('admin');

if (isset($_POST['btnreg'])) {
	$cn = db_connect();
	if (!$cn) {
		toast_and_redirect('Database connection failed', 'error', 'admin_dashboard.php');
	}

	$acctName = trim($_POST['txtan'] ?? '');
	$gender   = trim($_POST['txtgender'] ?? '');
	$username = trim($_POST['txtun'] ?? '');
	$password = trim($_POST['txtpw'] ?? '');

	if ($acctName === '' || $gender === '' || $username === '' || $password === '') {
		toast_and_redirect('All fields are required', 'warning', 'admin_dashboard.php');
	}

	// Check if username already exists
	$check = mysqli_prepare($cn, "SELECT id FROM tbl_adminreg WHERE username = ?");
	mysqli_stmt_bind_param($check, 's', $username);
	mysqli_stmt_execute($check);
	$result = mysqli_stmt_get_result($check);

	if (mysqli_num_rows($result) > 0) {
		toast_and_redirect('Username already exists', 'error', 'admin_dashboard.php');
		mysqli_stmt_close($check);
		exit;
	}
	mysqli_stmt_close($check);

	$stmt = mysqli_prepare($cn, "INSERT INTO tbl_adminreg (acct_name, gender, username, password) VALUES (?, ?, ?, ?)");
	mysqli_stmt_bind_param($stmt, 'ssss', $acctName, $gender, $username, $password);

	if (mysqli_stmt_execute($stmt)) {
		toast_and_redirect('Admin registered successfully!', 'success', 'admin_dashboard.php');
	} else {
		toast_and_redirect('Registration failed', 'error', 'admin_dashboard.php');
	}
	mysqli_stmt_close($stmt);
} else {
	header('Location: admin_dashboard.php');
}
?>