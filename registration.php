<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>E-Library | Student Sign Up</title>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
	<style>
		@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Poppins:wght@300;400;500;600&display=swap');

		* {
			margin: 0;
			padding: 0;
			box-sizing: border-box;
		}

		body {
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			font-family: 'Poppins', sans-serif;
			background: linear-gradient(135deg, #1a0a00 0%, #2c1810 40%, #3e2723 100%);
			position: relative;
			overflow: hidden;
		}

		body::before {
			content: '\f02d  \f518  \f02d  \f5da  \f02d';
			font-family: 'Font Awesome 6 Free';
			font-weight: 900;
			position: absolute;
			inset: 0;
			font-size: 120px;
			color: rgba(255, 200, 100, 0.03);
			display: flex;
			align-items: center;
			justify-content: center;
			letter-spacing: 80px;
			pointer-events: none;
		}

		.card {
			width: 900px;
			max-width: 92vw;
			min-height: 520px;
			display: flex;
			border-radius: 20px;
			overflow: hidden;
			box-shadow: 0 25px 60px rgba(0, 0, 0, 0.5);
			animation: fadeInUp 0.8s ease;
		}

		@keyframes fadeInUp {
			from { opacity: 0; transform: translateY(30px); }
			to { opacity: 1; transform: translateY(0); }
		}

		.left {
			flex: 1;
			background: linear-gradient(180deg, #5d4037, #3e2723);
			color: #fff;
			padding: 42px;
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
			background: radial-gradient(circle, rgba(255,193,7,0.1) 0%, transparent 60%);
			pointer-events: none;
		}

		.left .main-icon {
			width: 92px;
			height: 92px;
			object-fit: contain;
			margin-bottom: 18px;
			filter: drop-shadow(0 4px 15px rgba(255, 213, 79, 0.3));
			z-index: 1;
		}

		.left h1 {
			font-family: 'Playfair Display', serif;
			font-size: 28px;
			text-align: center;
			margin-bottom: 10px;
			z-index: 1;
		}

		.left p {
			color: rgba(255, 255, 255, 0.7);
			font-size: 14px;
			text-align: center;
			line-height: 1.6;
			z-index: 1;
		}

		.right {
			flex: 1;
			background: #faf3e8;
			padding: 50px 42px;
			display: flex;
			flex-direction: column;
			justify-content: center;
		}

		.right h2 {
			font-family: 'Playfair Display', serif;
			color: #3e2723;
			font-size: 30px;
			margin-bottom: 6px;
		}

		.right .subtitle {
			color: #8d6e63;
			font-size: 13px;
			margin-bottom: 28px;
		}

		.form-group {
			width: 100%;
			margin-bottom: 18px;
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

		.form-group input::placeholder {
			color: #bcaaa4;
		}

		.gender {
			display: flex;
			gap: 12px;
			margin: 6px 0 2px;
		}

		.gender-row {
			margin: 0 0 18px 0;
		}

		.pill {
			display: inline-flex;
			align-items: center;
			gap: 10px;
			padding: 10px 12px;
			border: 2px solid #d7ccc8;
			border-radius: 12px;
			background: #fff;
			color: #5d4037;
			cursor: pointer;
			user-select: none;
			transition: all 0.2s ease;
			font-size: 13px;
			font-weight: 600;
		}

		.pill input {
			accent-color: #5d4037;
		}

		.pill i {
			color: #8d6e63;
			font-size: 14px;
		}

		.pill input[type="radio"] {
			margin: 0;
		}

		.pill:hover {
			border-color: #8d6e63;
			transform: translateY(-1px);
		}

		.btn-primary {
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
			margin-top: 8px;
		}

		.btn-primary:hover {
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
			.card { flex-direction: column; }
			.left { padding: 34px 26px; }
			.right { padding: 34px 26px; }
		}
	</style>
</head>
<body>

	<div class="card">
		<div class="left">
			<img class="main-icon" src="Images/Icon.png" alt="E-Library Icon">
			<h1>E-Library System</h1>
			<p>Create your student account to access thousands of books and resources.</p>
		</div>

		<div class="right">
			<h2>Create Account</h2>
			<p class="subtitle">Sign up to start reading</p>

			<form action="registration.php" method="post">
				<div class="form-group">
					<i class="fas fa-id-card"></i>
					<input type="text" placeholder="Account Name" name="txtan" required>
				</div>

				<input type="hidden" name="txtgender" id="txtgender" required>

				<div class="gender gender-row">
					<label class="pill">
						<input type="radio" name="gender_ui" value="Male"> <i class="fas fa-mars"></i> Male
					</label>
					<label class="pill">
						<input type="radio" name="gender_ui" value="Female"> <i class="fas fa-venus"></i> Female
					</label>
				</div>

				<div class="form-group">
					<i class="fas fa-user"></i>
					<input type="text" placeholder="Username" name="txtun" required>
				</div>

				<div class="form-group">
					<i class="fas fa-lock"></i>
					<input type="password" placeholder="Password" name="txtpw" required>
				</div>

				<button type="submit" name="btnreg" class="btn-primary">
					<i class="fas fa-user-plus"></i> &nbsp;Sign Up
				</button>
			</form>

			<div class="links">
				<div>Already have an account? <a href="login.html">Log In</a></div>
				<div><a href="home.html"><i class="fas fa-arrow-left"></i> Back to Home</a></div>
			</div>
		</div>
	</div>

	<script>
		(function () {
			var hiddenGender = document.getElementById('txtgender');
			var radios = document.querySelectorAll('input[name="gender_ui"]');

			radios.forEach(function (r) {
				r.addEventListener('change', function () {
					hiddenGender.value = this.value;
				});
			});
		})();
	</script>

</body>
</html>

<?php

require_once __DIR__ . '/db.php';

$cn = db_connect();

if(isset($_POST['btnreg']) && $cn)
{
	$a = trim($_POST['txtan'] ?? '');
	$b = trim($_POST['txtgender'] ?? '');
	$c = trim($_POST['txtun'] ?? '');
	$d = trim($_POST['txtpw'] ?? '');

	if ($a === '' || $b === '' || $c === '' || $d === '') {
		echo "<script>alert('Please complete all fields.');</script>";
	} else {
		$stmt = mysqli_prepare($cn, "INSERT INTO tbl_login (acct_name, gender, username, password) VALUES (?, ?, ?, ?)");
		if ($stmt) {
			mysqli_stmt_bind_param($stmt, 'ssss', $a, $b, $c, $d);
			$ok = mysqli_stmt_execute($stmt);
			mysqli_stmt_close($stmt);

			if ($ok) {
				echo"<script>
				alert('Registration Successful')
				window.location.href = 'login.html';
				</script>";
			} else {
				echo "<script>alert('Registration failed. Please try again.');</script>";
			}
		} else {
			echo "<script>alert('Server error: unable to prepare query.');</script>";
		}
	}
}

?>