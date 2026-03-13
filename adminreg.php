<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_role('admin');

if (isset($_POST['btnreg'])) {
	$cn = db_connect();
	if (!$cn) {
		echo "<script>alert('Database connection failed');window.location.href='admin_dashboard.php';</script>";
		exit;
	}

	$acctName = trim($_POST['txtan'] ?? '');
	$gender   = trim($_POST['txtgender'] ?? '');
	$username = trim($_POST['txtun'] ?? '');
	$password = trim($_POST['txtpw'] ?? '');

	if ($acctName === '' || $gender === '' || $username === '' || $password === '') {
		echo "<script>alert('All fields are required');window.location.href='admin_dashboard.php';</script>";
		exit;
	}

	// Check if username already exists
	$check = mysqli_prepare($cn, "SELECT id FROM tbl_adminreg WHERE username = ?");
	mysqli_stmt_bind_param($check, 's', $username);
	mysqli_stmt_execute($check);
	$result = mysqli_stmt_get_result($check);

	if (mysqli_num_rows($result) > 0) {
		echo "<script>alert('Username already exists');window.location.href='admin_dashboard.php';</script>";
		mysqli_stmt_close($check);
		exit;
	}
	mysqli_stmt_close($check);

	$stmt = mysqli_prepare($cn, "INSERT INTO tbl_adminreg (acct_name, gender, username, password) VALUES (?, ?, ?, ?)");
	mysqli_stmt_bind_param($stmt, 'ssss', $acctName, $gender, $username, $password);

	if (mysqli_stmt_execute($stmt)) {
		echo "<script>alert('Admin registered successfully!');window.location.href='admin_dashboard.php';</script>";
	} else {
		echo "<script>alert('Registration failed');window.location.href='admin_dashboard.php';</script>";
	}
	mysqli_stmt_close($stmt);
} else {
	header('Location: admin_dashboard.php');
}
?>