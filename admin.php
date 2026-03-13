<?php
session_start();
require_once __DIR__ . '/db.php';

$loginError = '';

if (isset($_POST['btnlogin'])) {
	$cn = db_connect();
	if ($cn) {
		$username = trim($_POST['txtuser'] ?? '');
		$password = trim($_POST['txtpass'] ?? '');

		if ($username === '' || $password === '') {
			$loginError = "<script>alert('Please enter username and password.');</script>";
		} else {
			$stmt = mysqli_prepare(
				$cn,
				"SELECT id, acct_name FROM tbl_adminreg WHERE username = ? AND password = ? LIMIT 1"
			);

			if (!$stmt) {
				$loginError = "<script>alert('Server error.');</script>";
			} else {
				mysqli_stmt_bind_param($stmt, 'ss', $username, $password);
				mysqli_stmt_execute($stmt);
				$result = mysqli_stmt_get_result($stmt);
				$row    = mysqli_fetch_assoc($result);
				mysqli_stmt_close($stmt);

				if ($row) {
					$_SESSION['role']      = 'admin';
					$_SESSION['user_id']   = $row['id'];
					$_SESSION['acct_name'] = $row['acct_name'];
					$_SESSION['username']  = $username;
					header('Location: admin_dashboard.php');
					exit;
				} else {
					$loginError = "<script>alert('Login Failed');</script>";
				}
			}
		}
	} else {
		$loginError = "<script>alert(" . json_encode(db_friendly_error_message()) . ");</script>";
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>E-Library | Admin Login</title>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
	<style>
		@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap');

		* { margin: 0; padding: 0; box-sizing: border-box; }

		body {
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			font-family: 'Poppins', sans-serif;
			background: linear-gradient(135deg, #0f0b26 0%, #2c1810 45%, #3e2723 100%);
			position: relative;
			overflow: hidden;
		}

		body::before {
			content: '\f3ed  \f084  \f3ed  \f023  \f3ed';
			font-family: 'Font Awesome 6 Free';
			font-weight: 900;
			position: absolute;
			inset: 0;
			font-size: 120px;
			color: rgba(255, 200, 100, 0.03);
			display: flex;
			align-items: center;
			justify-content: center;
			letter-spacing: 70px;
			pointer-events: none;
		}

		.login-container {
			width: 880px;
			max-width: 92vw;
			min-height: 500px;
			display: flex;
			border-radius: 20px;
			overflow: hidden;
			box-shadow: 0 25px 60px rgba(0, 0, 0, 0.55);
			animation: fadeInUp 0.8s ease;
		}

		@keyframes fadeInUp {
			from { opacity: 0; transform: translateY(30px); }
			to { opacity: 1; transform: translateY(0); }
		}

		.left {
			flex: 1;
			background: linear-gradient(180deg, #263238, #3e2723);
			padding: 44px;
			color: #fff;
			display: flex;
			flex-direction: column;
			align-items: center;
			justify-content: center;
			position: relative;
		}

		.left::before {
			content: '';
			position: absolute;
			inset: -50%;
			background: radial-gradient(circle, rgba(255,193,7,0.10) 0%, transparent 60%);
			pointer-events: none;
		}

		.left .badge {
			z-index: 1;
			display: inline-flex;
			align-items: center;
			gap: 10px;
			background: rgba(255, 213, 79, 0.12);
			border: 1px solid rgba(255, 213, 79, 0.25);
			color: #ffd54f;
			padding: 7px 14px;
			border-radius: 999px;
			font-size: 12px;
			font-weight: 700;
			letter-spacing: 1px;
			text-transform: uppercase;
			margin-bottom: 16px;
		}

		.left img.main-icon {
			width: 92px;
			height: 92px;
			object-fit: contain;
			margin-bottom: 14px;
			filter: drop-shadow(0 4px 15px rgba(255, 213, 79, 0.25));
			z-index: 1;
		}

		.left h1 {
			z-index: 1;
			font-family: 'Poppins', sans-serif;
			font-size: 28px;
			text-align: center;
			margin-bottom: 10px;
		}

		.left p {
			z-index: 1;
			color: rgba(255,255,255,0.75);
			font-size: 14px;
			text-align: center;
			line-height: 1.6;
		}

		.right {
			flex: 1;
			background: #faf3e8;
			padding: 52px 44px;
			display: flex;
			flex-direction: column;
			justify-content: center;
		}

		.right h2 {
			font-family: 'Poppins', sans-serif;
			color: #3e2723;
			font-size: 30px;
			margin-bottom: 8px;
		}

		.right .subtitle {
			color: #8d6e63;
			font-size: 13px;
			margin-bottom: 34px;
		}

		.form-group {
			width: 100%;
			margin-bottom: 20px;
			position: relative;
		}

		.form-group i {
			position: absolute;
			left: 15px;
			top: 50%;
			transform: translateY(-50%);
			color: #8d6e63;
			font-size: 16px;
		}

		.form-group input {
			width: 100%;
			padding: 14px 15px 14px 45px;
			border: 2px solid #d7ccc8;
			border-radius: 12px;
			font-size: 14px;
			font-family: 'Poppins', sans-serif;
			background: #fff;
			color: #3e2723;
			transition: all 0.3s ease;
			outline: none;
		}

		.form-group input:focus {
			border-color: #8d6e63;
			box-shadow: 0 0 0 3px rgba(141, 110, 99, 0.15);
		}

		.btn-login {
			width: 100%;
			padding: 14px;
			background: linear-gradient(135deg, #5d4037, #3e2723);
			color: #ffd54f;
			border: none;
			border-radius: 12px;
			font-size: 16px;
			font-weight: 600;
			font-family: 'Poppins', sans-serif;
			cursor: pointer;
			transition: all 0.3s ease;
			letter-spacing: 1px;
			margin-top: 5px;
		}

		.btn-login:hover {
			background: linear-gradient(135deg, #6d4c41, #4e342e);
			transform: translateY(-2px);
			box-shadow: 0 8px 25px rgba(62, 39, 35, 0.3);
		}

		.links {
			margin-top: 22px;
			display: flex;
			flex-direction: column;
			gap: 10px;
			font-size: 13px;
			color: #8d6e63;
		}

		.links a {
			color: #5d4037;
			font-weight: 600;
			text-decoration: none;
			border-bottom: 2px solid #ffd54f;
			padding-bottom: 1px;
			width: fit-content;
		}

		.links a:hover {
			color: #3e2723;
			border-bottom-color: #3e2723;
		}

		@media (max-width: 820px) {
			.login-container { flex-direction: column; }
			.left, .right { padding: 34px 26px; }
		}
	</style>
</head>
<body>

	<div class="login-container">
		<div class="left">
			<div class="badge"><i class="fas fa-user-shield"></i> Admin Area</div>
			<img class="main-icon" src="Images/Icon.png" alt="E-Library Icon">
			<h1>E-Library System</h1>
			<p>Sign in with your admin credentials to manage the system securely.</p>
		</div>

		<div class="right">
			<h2>Admin Login</h2>
			<p class="subtitle">Restricted access — authorized staff only</p>

			<form action="admin.php" method="post" style="width: 100%;">
				<div class="form-group">
					<i class="fas fa-user"></i>
					<input type="text" placeholder="Username" name="txtuser" required>
				</div>

				<div class="form-group">
					<i class="fas fa-lock"></i>
					<input type="password" placeholder="Password" name="txtpass" required>
				</div>

				<button type="submit" name="btnlogin" class="btn-login">
					<i class="fas fa-sign-in-alt"></i> &nbsp;Log In
				</button>
			</form>

			<div class="links">
				<div><a href="home.html"><i class="fas fa-arrow-left"></i> Back to Home</a></div>
			</div>
		</div>
	</div>

<?= $loginError ?>

</body>
</html>