<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail_helper.php';

$regMsg = '';

if (isset($_POST['btnreg'])) {
	$cn = db_connect();
	if ($cn) {
		$a = trim($_POST['txtan'] ?? '');
		$b = trim($_POST['txtgender'] ?? '');
		$email = trim($_POST['txtemail'] ?? '');
		$c = ''; // username removed; we'll auto-generate from email
		$d = trim($_POST['txtpw'] ?? '');
		$confirm = trim($_POST['txtcpw'] ?? '');

		if ($a === '' || $b === '' || $email === '' || $d === '' || $confirm === '') {
			$regMsg = "<script>alert('Please complete all fields.');</script>";
		} else {
			if ($d !== $confirm) {
				$regMsg = "<script>alert('Passwords do not match.');</script>";
			} else {
			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$regMsg = "<script>alert('Please enter a valid email address.');</script>";
			} else {
				$token = bin2hex(random_bytes(32));
				$tokenHash = hash('sha256', $token);
				$expires = date('Y-m-d H:i:s', time() + 60 * 60); // 1 hour

				// Auto-generate a unique username (keeps existing DB schema/admin pages compatible)
				$baseUser = strtolower(preg_replace('/[^a-z0-9]+/i', '', explode('@', $email)[0] ?? 'user'));
				if ($baseUser === '') {
					$baseUser = 'user';
				}
				$c = $baseUser;
				$check = mysqli_prepare($cn, "SELECT id FROM tbl_login WHERE username = ? LIMIT 1");
				if ($check) {
					$suffix = 0;
					while (true) {
						$tmp = $suffix === 0 ? $baseUser : ($baseUser . $suffix);
						mysqli_stmt_bind_param($check, 's', $tmp);
						mysqli_stmt_execute($check);
						$res = mysqli_stmt_get_result($check);
						$exists = mysqli_fetch_assoc($res);
						if (!$exists) {
							$c = $tmp;
							break;
						}
						$suffix++;
						if ($suffix > 9999) {
							break;
						}
					}
					mysqli_stmt_close($check);
				}

				$stmt = mysqli_prepare($cn, "INSERT INTO tbl_login (acct_name, gender, email, username, password, role, borrow_limit, email_verified, email_verify_token_hash, email_verify_expires) VALUES (?, ?, ?, ?, ?, 'student', 3, 0, ?, ?)");
				if ($stmt) {
					mysqli_stmt_bind_param($stmt, 'sssssss', $a, $b, $email, $c, $d, $tokenHash, $expires);
					$ok = false;
					$insertError = null;
					try {
						$ok = mysqli_stmt_execute($stmt);
					} catch (mysqli_sql_exception $e) {
						$insertError = $e;
					}
					mysqli_stmt_close($stmt);

					if ($ok) {
						$cfgFile = __DIR__ . '/config.php';
						$cfg = null;
						if (file_exists($cfgFile)) {
							$cfg = require $cfgFile;
						}
						// Backward compatible: if config.php isn't used, fall back to mail_config.php
						if (!is_array($cfg)) {
							$cfg = require __DIR__ . '/mail_config.php';
						}
						$baseUrl = rtrim(($cfg['app']['base_url'] ?? 'http://localhost/library'), '/');
						$verifyLink = $baseUrl . '/verify_email.php?token=' . urlencode($token);

						try {
							send_verification_email($email, $a, $verifyLink);
							echo "<script>alert('Registration Successful! Please check your email to verify your account before logging in.'); window.location.href='login.html';</script>";
							exit;
						} catch (Throwable $e) {
							echo "<script>alert('Account created, but verification email failed: " . addslashes($e->getMessage()) . "'); window.location.href='login.html';</script>";
							exit;
						}
					} else {
						// 1062 = duplicate key
						if ($insertError instanceof mysqli_sql_exception && (int)$insertError->getCode() === 1062) {
							$regMsg = "<script>alert('That email is already registered. Please login instead.');</script>";
						} else {
							$regMsg = "<script>alert('Registration failed. Please try again.');</script>";
						}
					}
				} else {
					$regMsg = "<script>alert('Server error: unable to prepare query.');</script>";
				}
			}
			}
		}
	} else {
		$regMsg = "<script>alert('Database connection failed.');</script>";
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>E-Library | Student Sign Up</title>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
	<style>
		@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap');

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
			font-family: 'Poppins', sans-serif;
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
			font-family: 'Poppins', sans-serif;
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
			padding: 14px 15px 14px 78px;
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

		.password-wrap {
			position: relative;
		}

		.toggle-pass {
			position: absolute;
			right: 38px;
			top: 50%;
			transform: translateY(-50%);
			background: transparent;
			border: none;
			color: #8d6e63;
			cursor: pointer;
			padding: 6px 8px;
			border-radius: 10px;
		}

		.toggle-pass:hover {
			background: rgba(141, 110, 99, 0.12);
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

			<form action="registration.php" method="post" id="regForm">
				<div class="form-group">
					<i class="fas fa-id-card"></i>
					<input type="text" placeholder="Account Name" name="txtan" required>
				</div>

				<div class="form-group">
					<i class="fas fa-envelope"></i>
					<input type="email" placeholder="Email" name="txtemail" required>
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

				<div class="form-group password-wrap">
					<i class="fas fa-lock"></i>
					<input id="txtpw" type="password" placeholder="Password" name="txtpw" required>
					<button class="toggle-pass" type="button" id="togglePw" aria-label="Show password">
						<i class="fa-regular fa-eye"></i>
					</button>
				</div>

				<div class="form-group password-wrap">
					<i class="fas fa-lock"></i>
					<input id="txtcpw" type="password" placeholder="Confirm Password" name="txtcpw" required>
					<button class="toggle-pass" type="button" id="toggleCpw" aria-label="Show confirm password">
						<i class="fa-regular fa-eye"></i>
					</button>
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

		(function () {
			function bindToggle(inputId, btnId) {
				var input = document.getElementById(inputId);
				var btn = document.getElementById(btnId);
				if (!input || !btn) return;
				btn.addEventListener('click', function () {
					var isPw = input.getAttribute('type') === 'password';
					input.setAttribute('type', isPw ? 'text' : 'password');
					btn.innerHTML = isPw ? '<i class="fa-regular fa-eye-slash"></i>' : '<i class="fa-regular fa-eye"></i>';
				});
			}

			bindToggle('txtpw', 'togglePw');
			bindToggle('txtcpw', 'toggleCpw');

			var form = document.getElementById('regForm');
			var pw = document.getElementById('txtpw');
			var cpw = document.getElementById('txtcpw');
			if (form && pw && cpw) {
				form.addEventListener('submit', function (e) {
					if (pw.value !== cpw.value) {
						e.preventDefault();
						alert('Passwords do not match.');
						cpw.focus();
					}
				});
			}
		})();
	</script>

<?= $regMsg ?>
</body>
</html>