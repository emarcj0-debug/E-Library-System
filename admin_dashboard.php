<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_role('admin');

$cn = db_connect();
$acctName = $_SESSION['acct_name'];
$msg = '';
$msgType = '';

// ── AUTO-MIGRATION: ensure new columns exist ────────────────
if ($cn) {
	/**
	 * Returns true if a column exists in the current database.
	 */
	$column_exists = function(mysqli $cn, string $table, string $column): bool {
		$sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
		$stmt = mysqli_prepare($cn, $sql);
		if (!$stmt) return false;
		mysqli_stmt_bind_param($stmt, 'ss', $table, $column);
		mysqli_stmt_execute($stmt);
		$res = mysqli_stmt_get_result($stmt);
		$ok = (bool)mysqli_fetch_assoc($res);
		mysqli_stmt_close($stmt);
		return $ok;
	};

	// tbl_login
	if (!$column_exists($cn, 'tbl_login', 'role')) {
		@mysqli_query($cn, "ALTER TABLE tbl_login ADD COLUMN role ENUM('student','teacher') NOT NULL DEFAULT 'student' AFTER password");
	}
	if (!$column_exists($cn, 'tbl_login', 'borrow_limit')) {
		@mysqli_query($cn, "ALTER TABLE tbl_login ADD COLUMN borrow_limit INT NOT NULL DEFAULT 3 AFTER role");
	}

	// tbl_books
	if (!$column_exists($cn, 'tbl_books', 'cover_image')) {
		@mysqli_query($cn, "ALTER TABLE tbl_books ADD COLUMN cover_image VARCHAR(500) DEFAULT NULL AFTER description");
	}
	if (!$column_exists($cn, 'tbl_books', 'publisher')) {
		@mysqli_query($cn, "ALTER TABLE tbl_books ADD COLUMN publisher VARCHAR(200) DEFAULT NULL AFTER cover_image");
	}
	if (!$column_exists($cn, 'tbl_books', 'pub_year')) {
		@mysqli_query($cn, "ALTER TABLE tbl_books ADD COLUMN pub_year VARCHAR(10) DEFAULT NULL AFTER publisher");
	}

	// tbl_borrow (OTP handshake)
	if (!$column_exists($cn, 'tbl_borrow', 'issue_otp_hash')) {
		@mysqli_query($cn, "ALTER TABLE tbl_borrow ADD COLUMN issue_otp_hash CHAR(64) DEFAULT NULL AFTER status");
	}
	// Ensure status enum supports OTP workflow
	// If the enum doesn't include 'Pending Pickup', upgrade it.
	$statusInfo = @mysqli_fetch_assoc(@mysqli_query($cn, "SHOW COLUMNS FROM tbl_borrow LIKE 'status'"));
	if ($statusInfo && isset($statusInfo['Type']) && stripos($statusInfo['Type'], 'Pending Pickup') === false) {
		@mysqli_query($cn, "ALTER TABLE tbl_borrow MODIFY status ENUM('Pending Pickup','Borrowed','Returned') NOT NULL DEFAULT 'Pending Pickup'");
	}
	if (!$column_exists($cn, 'tbl_borrow', 'issue_otp_expires')) {
		@mysqli_query($cn, "ALTER TABLE tbl_borrow ADD COLUMN issue_otp_expires DATETIME DEFAULT NULL AFTER issue_otp_hash");
	}
	if (!$column_exists($cn, 'tbl_borrow', 'issue_confirmed')) {
		@mysqli_query($cn, "ALTER TABLE tbl_borrow ADD COLUMN issue_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER issue_otp_expires");
	}
	if (!$column_exists($cn, 'tbl_borrow', 'return_requested')) {
		@mysqli_query($cn, "ALTER TABLE tbl_borrow ADD COLUMN return_requested TINYINT(1) NOT NULL DEFAULT 0 AFTER issue_confirmed");
	}
	if (!$column_exists($cn, 'tbl_borrow', 'return_otp_hash')) {
		@mysqli_query($cn, "ALTER TABLE tbl_borrow ADD COLUMN return_otp_hash CHAR(64) DEFAULT NULL AFTER return_requested");
	}
	if (!$column_exists($cn, 'tbl_borrow', 'return_otp_expires')) {
		@mysqli_query($cn, "ALTER TABLE tbl_borrow ADD COLUMN return_otp_expires DATETIME DEFAULT NULL AFTER return_otp_hash");
	}

	// tbl_reservations (ready/pickup confirm via desk QR)
	@mysqli_query($cn, "CREATE TABLE IF NOT EXISTS tbl_reservations (
		id INT AUTO_INCREMENT PRIMARY KEY,
		student_id INT NOT NULL,
		book_id INT NOT NULL,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		status ENUM('Requested','Ready','Borrowed','Cancelled','Expired') NOT NULL DEFAULT 'Requested',
		ready_at DATETIME DEFAULT NULL,
		ready_expires_at DATETIME DEFAULT NULL,
		pickup_confirmed_at DATETIME DEFAULT NULL,
		pickup_confirm_token_hash CHAR(64) DEFAULT NULL,
		pickup_confirm_token_expires DATETIME DEFAULT NULL,
		borrow_id INT DEFAULT NULL,
		INDEX idx_res_student_book (student_id, book_id),
		INDEX idx_res_status (status),
		INDEX idx_res_ready_expires (ready_expires_at),
		INDEX idx_res_borrow_id (borrow_id),
		FOREIGN KEY (student_id) REFERENCES tbl_login(id) ON DELETE CASCADE,
		FOREIGN KEY (book_id) REFERENCES tbl_books(id) ON DELETE CASCADE
	) ENGINE=InnoDB");

	// Ensure enum supports current reservation lifecycle
	$resStatusInfo = @mysqli_fetch_assoc(@mysqli_query($cn, "SHOW COLUMNS FROM tbl_reservations LIKE 'status'"));
	if ($resStatusInfo && isset($resStatusInfo['Type']) && stripos($resStatusInfo['Type'], 'Borrowed') === false) {
		@mysqli_query($cn, "ALTER TABLE tbl_reservations MODIFY status ENUM('Requested','Ready','Borrowed','Cancelled','Expired') NOT NULL DEFAULT 'Requested'");
	}
	// Ensure borrow_id exists
	if (!$column_exists($cn, 'tbl_reservations', 'borrow_id')) {
		@mysqli_query($cn, "ALTER TABLE tbl_reservations ADD COLUMN borrow_id INT DEFAULT NULL AFTER pickup_confirm_token_expires");
	}
}

// ── Reservations data (admin) ───────────────────────────────
$reservationRequested = [];
$reservationReady = [];
$reservationBorrowed = [];
$totalReservations = 0;
$borrowedFromReservationCount = 0;

if ($cn) {
	// Expire any Ready reservations that passed the 24h window
	@mysqli_query($cn, "UPDATE tbl_reservations SET status='Expired' WHERE status='Ready' AND ready_expires_at IS NOT NULL AND ready_expires_at < NOW()");

	$totalReservations = (int)mysqli_fetch_assoc(mysqli_query($cn,"SELECT COUNT(*) AS c FROM tbl_reservations"))['c'];
	$borrowedFromReservationCount = (int)mysqli_fetch_assoc(mysqli_query($cn,"SELECT COUNT(*) AS c FROM tbl_reservations WHERE status='Borrowed'"))['c'];

	$q1 = "SELECT r.*, l.acct_name, l.email, bk.title, bk.author, bk.cover_image
		FROM tbl_reservations r
		JOIN tbl_login l ON r.student_id=l.id
		JOIN tbl_books bk ON r.book_id=bk.id
		WHERE r.status='Requested'
		ORDER BY r.created_at ASC";
	$rr = @mysqli_query($cn, $q1);
	if ($rr) while ($row = mysqli_fetch_assoc($rr)) $reservationRequested[] = $row;

	$q2 = "SELECT r.*, l.acct_name, l.email, bk.title, bk.author, bk.cover_image
		FROM tbl_reservations r
		JOIN tbl_login l ON r.student_id=l.id
		JOIN tbl_books bk ON r.book_id=bk.id
		WHERE r.status='Ready'
		ORDER BY r.ready_at DESC";
	$rr = @mysqli_query($cn, $q2);
	if ($rr) while ($row = mysqli_fetch_assoc($rr)) $reservationReady[] = $row;

	$q3 = "SELECT r.*, l.acct_name, l.email, bk.title, bk.author, bk.cover_image
		FROM tbl_reservations r
		JOIN tbl_login l ON r.student_id=l.id
		JOIN tbl_books bk ON r.book_id=bk.id
		WHERE r.status='Borrowed'
		ORDER BY r.created_at DESC";
	$rr = @mysqli_query($cn, $q3);
	if ($rr) while ($row = mysqli_fetch_assoc($rr)) $reservationBorrowed[] = $row;
}

// ══════════════════════════════════════════════════════════════
//  POST HANDLERS
// ══════════════════════════════════════════════════════════════

// ── ADD BOOK ────────────────────────────────────────────────
if (isset($_POST['add_book']) && $cn) {
	$title    = trim($_POST['title'] ?? '');
	$author   = trim($_POST['author'] ?? '');
	$category = trim($_POST['category'] ?? '');
	$isbn     = trim($_POST['isbn'] ?? '');
	$qty      = (int)($_POST['quantity'] ?? 1);
	$desc     = trim($_POST['description'] ?? '');
	$cover    = trim($_POST['cover_image'] ?? '');
	$publisher= trim($_POST['publisher'] ?? '');
	$pubYear  = trim($_POST['pub_year'] ?? '');

	if ($title === '' || $author === '' || $category === '') {
		$msg = 'Title, Author, and Category are required.'; $msgType = 'error';
	} else {
		$stmt = mysqli_prepare($cn, "INSERT INTO tbl_books (title, author, category, isbn, quantity, description, cover_image, publisher, pub_year) VALUES (?,?,?,?,?,?,?,?,?)");
		mysqli_stmt_bind_param($stmt, 'ssssissss', $title, $author, $category, $isbn, $qty, $desc, $cover, $publisher, $pubYear);
		mysqli_stmt_execute($stmt);
		mysqli_stmt_close($stmt);
		$msg = "Book \"" . htmlspecialchars($title) . "\" added successfully!"; $msgType = 'success';
	}
}

// ── EDIT BOOK ───────────────────────────────────────────────
if (isset($_POST['edit_book']) && $cn) {
	$id       = (int)$_POST['book_id'];
	$title    = trim($_POST['title'] ?? '');
	$author   = trim($_POST['author'] ?? '');
	$category = trim($_POST['category'] ?? '');
	$isbn     = trim($_POST['isbn'] ?? '');
	$qty      = (int)($_POST['quantity'] ?? 1);
	$desc     = trim($_POST['description'] ?? '');
	$cover    = trim($_POST['cover_image'] ?? '');
	$publisher= trim($_POST['publisher'] ?? '');
	$pubYear  = trim($_POST['pub_year'] ?? '');

	$stmt = mysqli_prepare($cn, "UPDATE tbl_books SET title=?, author=?, category=?, isbn=?, quantity=?, description=?, cover_image=?, publisher=?, pub_year=? WHERE id=?");
	mysqli_stmt_bind_param($stmt, 'ssssissssi', $title, $author, $category, $isbn, $qty, $desc, $cover, $publisher, $pubYear, $id);
	mysqli_stmt_execute($stmt);
	mysqli_stmt_close($stmt);
	$msg = "Book updated successfully!"; $msgType = 'success';
}

// ── DELETE BOOK ─────────────────────────────────────────────
if (isset($_POST['delete_book']) && $cn) {
	$id = (int)$_POST['book_id'];
	$stmt = mysqli_prepare($cn, "DELETE FROM tbl_books WHERE id = ?");
	mysqli_stmt_bind_param($stmt, 'i', $id);
	mysqli_stmt_execute($stmt);
	mysqli_stmt_close($stmt);
	$msg = "Book deleted."; $msgType = 'success';
}

// ── DELETE USER ─────────────────────────────────────────────
if (isset($_POST['delete_student']) && $cn) {
	$id = (int)$_POST['student_id'];
	$stmt = mysqli_prepare($cn, "DELETE FROM tbl_login WHERE id = ?");
	mysqli_stmt_bind_param($stmt, 'i', $id);
	mysqli_stmt_execute($stmt);
	mysqli_stmt_close($stmt);
	$msg = "User removed."; $msgType = 'success';
}

// ── ADD USER (admin-side) ───────────────────────────────────
if (isset($_POST['add_user']) && $cn) {
	$name   = trim($_POST['acct_name'] ?? '');
	$gender = trim($_POST['gender'] ?? '');
	$uname  = trim($_POST['username'] ?? '');
	$pass   = trim($_POST['password'] ?? '');
	$role   = in_array($_POST['role'] ?? '', ['student','teacher']) ? $_POST['role'] : 'student';
	$limit  = ($role === 'teacher') ? 5 : 3;

	if ($name === '' || $uname === '' || $pass === '' || $gender === '') {
		$msg = 'All fields are required.'; $msgType = 'error';
	} else {
		$stmt = mysqli_prepare($cn, "INSERT INTO tbl_login (acct_name, gender, username, password, role, borrow_limit) VALUES (?,?,?,?,?,?)");
		mysqli_stmt_bind_param($stmt, 'sssssi', $name, $gender, $uname, $pass, $role, $limit);
		if (mysqli_stmt_execute($stmt)) {
			$msg = ucfirst($role) . " \"" . htmlspecialchars($name) . "\" added!"; $msgType = 'success';
		} else {
			$msg = "Failed — username may already exist."; $msgType = 'error';
		}
		mysqli_stmt_close($stmt);
	}
}

// ── EDIT USER (role & borrow limit) ─────────────────────────
if (isset($_POST['edit_user']) && $cn) {
	$id    = (int)$_POST['user_id'];
	$role  = in_array($_POST['role'] ?? '', ['student','teacher']) ? $_POST['role'] : 'student';
	$limit = (int)($_POST['borrow_limit'] ?? 3);

	$stmt = mysqli_prepare($cn, "UPDATE tbl_login SET role=?, borrow_limit=? WHERE id=?");
	mysqli_stmt_bind_param($stmt, 'sii', $role, $limit, $id);
	mysqli_stmt_execute($stmt);
	mysqli_stmt_close($stmt);
	$msg = "User updated."; $msgType = 'success';
}

// ── RESET PASSWORD ──────────────────────────────────────────
if (isset($_POST['reset_password']) && $cn) {
	$id      = (int)$_POST['user_id'];
	$newPass = 'password123';
	$stmt = mysqli_prepare($cn, "UPDATE tbl_login SET password=? WHERE id=?");
	mysqli_stmt_bind_param($stmt, 'si', $newPass, $id);
	mysqli_stmt_execute($stmt);
	mysqli_stmt_close($stmt);
	$msg = "Password reset to <strong>password123</strong>."; $msgType = 'success';
}

// ── OTP helpers (circulation) ──────────────────────────────
function otp_generate_4digit(): string {
	return str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}

function otp_hash(string $otp): string {
	return hash('sha256', $otp);
}

function otp_not_expired(?string $expires): bool {
	if (!$expires) return false;
	$ts = strtotime($expires);
	return ($ts !== false) && ($ts >= time());
}

// ── Reservation helpers (desk QR token) ────────────────────
function res_token_generate(): string {
	// URL-safe token for desk QR codes
	return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}

function res_token_hash(string $token): string {
	return hash('sha256', $token);
}

function res_not_expired(?string $expires): bool {
	if (!$expires) return false;
	$ts = strtotime($expires);
	return ($ts !== false) && ($ts >= time());
}

// ── RESERVATIONS: Mark reservation ready for pickup ─────────
if (isset($_POST['mark_res_ready']) && $cn) {
	$resId = (int)($_POST['reservation_id'] ?? 0);
	if ($resId <= 0) {
		$msg = 'Invalid reservation.'; $msgType = 'error';
	} else {
		$token = res_token_generate();
		$hash  = res_token_hash($token);
		$readyExp = date('Y-m-d H:i:s', time() + 24 * 60 * 60);
		$tokenExp = date('Y-m-d H:i:s', time() + 24 * 60 * 60);

		$u = mysqli_prepare($cn, "UPDATE tbl_reservations
			SET status='Ready', ready_at=NOW(), ready_expires_at=?, pickup_confirm_token_hash=?, pickup_confirm_token_expires=?
			WHERE id=? AND status IN ('Requested')");
		mysqli_stmt_bind_param($u, 'sssi', $readyExp, $hash, $tokenExp, $resId);
		mysqli_stmt_execute($u);
		$affected = mysqli_stmt_affected_rows($u);
		mysqli_stmt_close($u);

		if ($affected < 1) {
			$msg = 'Reservation not updated (maybe already Ready/Cancelled/Expired).'; $msgType = 'warning';
		} else {
			// Show token once so librarian can generate a desk QR from it.
			$_SESSION['last_res_desk_token'] = ['reservation_id' => $resId, 'token' => $token, 'expires' => $tokenExp];
			$msg = "Reservation #" . str_pad((string)$resId, 4, '0', STR_PAD_LEFT) . " is Ready for pickup (24h).";
			$msgType = 'success';
		}
	}
}

// ── CIRCULATION: Generate issue OTP (admin) ─────────────────
if (isset($_POST['gen_issue_otp']) && $cn) {
	$borrowId = (int)($_POST['borrow_id'] ?? 0);

	$stmt = mysqli_prepare($cn, "SELECT b.id, b.status, b.issue_confirmed FROM tbl_borrow b WHERE b.id=? LIMIT 1");
	mysqli_stmt_bind_param($stmt, 'i', $borrowId);
	mysqli_stmt_execute($stmt);
	$res = mysqli_stmt_get_result($stmt);
	$row = mysqli_fetch_assoc($res);
	mysqli_stmt_close($stmt);

	if (!$row) {
		$msg = 'Transaction not found.'; $msgType = 'error';
	} elseif (($row['status'] ?? '') !== 'Pending Pickup') {
		$msg = 'Issue OTP can only be generated for Pending Pickup transactions.'; $msgType = 'warning';
	} else {
		$otp = otp_generate_4digit();
		$hash = otp_hash($otp);
		$exp  = date('Y-m-d H:i:s', time() + 10 * 60);
		$u = mysqli_prepare($cn, "UPDATE tbl_borrow SET issue_otp_hash=?, issue_otp_expires=?, issue_confirmed=0 WHERE id=?");
		mysqli_stmt_bind_param($u, 'ssi', $hash, $exp, $borrowId);
		mysqli_stmt_execute($u);
		mysqli_stmt_close($u);

		// Store last OTP in session so admin can see it once after post
		$_SESSION['last_issue_otp'] = ['borrow_id' => $borrowId, 'otp' => $otp, 'expires' => $exp];
		$msg = "Issue OTP generated for Transaction #" . str_pad((string)$borrowId, 4, '0', STR_PAD_LEFT) . ": <strong>$otp</strong>";
		$msgType = 'success';
	}
}

// ── CIRCULATION: Confirm issue with OTP (admin) ─────────────
if (isset($_POST['confirm_issue']) && $cn) {
	$borrowId = (int)($_POST['borrow_id'] ?? 0);
	$otp = trim($_POST['otp'] ?? '');

	if (!preg_match('/^\d{4}$/', $otp)) {
		$msg = 'Enter a valid 4-digit OTP.'; $msgType = 'error';
	} else {
		$stmt = mysqli_prepare($cn, "SELECT b.id, b.status, b.issue_confirmed, b.issue_otp_hash, b.issue_otp_expires, b.book_id, bk.quantity
			FROM tbl_borrow b JOIN tbl_books bk ON b.book_id=bk.id
			WHERE b.id=? LIMIT 1");
		mysqli_stmt_bind_param($stmt, 'i', $borrowId);
		mysqli_stmt_execute($stmt);
		$res = mysqli_stmt_get_result($stmt);
		$row = mysqli_fetch_assoc($res);
		mysqli_stmt_close($stmt);

		if (!$row) {
			$msg = 'Transaction not found.'; $msgType = 'error';
		} elseif (($row['status'] ?? '') !== 'Pending Pickup') {
			$msg = 'This transaction is not pending pickup.'; $msgType = 'warning';
		} elseif ((int)($row['issue_confirmed'] ?? 0) === 1) {
			$msg = 'Already confirmed.'; $msgType = 'warning';
		} elseif (!otp_not_expired($row['issue_otp_expires'] ?? null)) {
			$msg = 'OTP expired. Please generate a new OTP.'; $msgType = 'error';
		} elseif (!hash_equals($row['issue_otp_hash'] ?? '', otp_hash($otp))) {
			$msg = 'Incorrect OTP.'; $msgType = 'error';
		} elseif ((int)($row['quantity'] ?? 0) < 1) {
			$msg = 'Book is out of stock.'; $msgType = 'error';
		} else {
			mysqli_begin_transaction($cn);
			try {
				$dec = mysqli_prepare($cn, "UPDATE tbl_books SET quantity = quantity - 1 WHERE id=? AND quantity > 0");
				mysqli_stmt_bind_param($dec, 'i', $row['book_id']);
				mysqli_stmt_execute($dec);
				$affected = mysqli_stmt_affected_rows($dec);
				mysqli_stmt_close($dec);
				if ($affected < 1) throw new Exception('Out of stock.');

				$up = mysqli_prepare($cn, "UPDATE tbl_borrow SET status='Borrowed', issue_confirmed=1, issue_otp_hash=NULL, issue_otp_expires=NULL, borrow_date=NOW() WHERE id=?");
				mysqli_stmt_bind_param($up, 'i', $borrowId);
				mysqli_stmt_execute($up);
				mysqli_stmt_close($up);

				mysqli_commit($cn);
				$msg = 'Issue confirmed. Book marked as Borrowed.'; $msgType = 'success';
			} catch (Throwable $e) {
				mysqli_rollback($cn);
				$msg = 'Failed to confirm issue: ' . htmlspecialchars($e->getMessage());
				$msgType = 'error';
			}
		}
	}
}

// ── CIRCULATION: Confirm return with OTP (admin) ────────────
if (isset($_POST['confirm_return']) && $cn) {
	$borrowId = (int)($_POST['borrow_id'] ?? 0);
	$otp = trim($_POST['otp'] ?? '');

	if (!preg_match('/^\d{4}$/', $otp)) {
		$msg = 'Enter a valid 4-digit OTP.'; $msgType = 'error';
	} else {
		$stmt = mysqli_prepare($cn, "SELECT b.id, b.status, b.return_requested, b.return_otp_hash, b.return_otp_expires, b.book_id
			FROM tbl_borrow b WHERE b.id=? LIMIT 1");
		mysqli_stmt_bind_param($stmt, 'i', $borrowId);
		mysqli_stmt_execute($stmt);
		$res = mysqli_stmt_get_result($stmt);
		$row = mysqli_fetch_assoc($res);
		mysqli_stmt_close($stmt);

		if (!$row) {
			$msg = 'Transaction not found.'; $msgType = 'error';
		} elseif (($row['status'] ?? '') !== 'Borrowed') {
			$msg = 'Only Borrowed transactions can be returned.'; $msgType = 'warning';
		} elseif ((int)($row['return_requested'] ?? 0) !== 1) {
			$msg = 'No return request found for this transaction.'; $msgType = 'warning';
		} elseif (!otp_not_expired($row['return_otp_expires'] ?? null)) {
			$msg = 'Return OTP expired. Ask the student to request again.'; $msgType = 'error';
		} elseif (!hash_equals($row['return_otp_hash'] ?? '', otp_hash($otp))) {
			$msg = 'Incorrect return OTP.'; $msgType = 'error';
		} else {
			mysqli_begin_transaction($cn);
			try {
				$inc = mysqli_prepare($cn, "UPDATE tbl_books SET quantity = quantity + 1 WHERE id=?");
				mysqli_stmt_bind_param($inc, 'i', $row['book_id']);
				mysqli_stmt_execute($inc);
				mysqli_stmt_close($inc);

				$up = mysqli_prepare($cn, "UPDATE tbl_borrow SET status='Returned', return_date=NOW(), return_requested=0, return_otp_hash=NULL, return_otp_expires=NULL WHERE id=?");
				mysqli_stmt_bind_param($up, 'i', $borrowId);
				mysqli_stmt_execute($up);
				mysqli_stmt_close($up);

				mysqli_commit($cn);
				$msg = 'Return confirmed. Book marked as Returned.'; $msgType = 'success';
			} catch (Throwable $e) {
				mysqli_rollback($cn);
				$msg = 'Failed to confirm return: ' . htmlspecialchars($e->getMessage());
				$msgType = 'error';
			}
		}
	}
}

// ══════════════════════════════════════════════════════════════
//  DATA FETCHING
// ══════════════════════════════════════════════════════════════

$books = [];
if ($cn) {
	$r = mysqli_query($cn, "SELECT * FROM tbl_books ORDER BY title ASC");
	while ($row = mysqli_fetch_assoc($r)) $books[] = $row;
}

$categories = [];
if ($cn) {
	$cr = mysqli_query($cn, "SELECT DISTINCT category FROM tbl_books ORDER BY category");
	while ($row = mysqli_fetch_assoc($cr)) $categories[] = $row['category'];
}

$students = [];
if ($cn) {
	$r = mysqli_query($cn, "SELECT * FROM tbl_login ORDER BY acct_name ASC");
	while ($row = mysqli_fetch_assoc($r)) $students[] = $row;
}

$circulation = [];
if ($cn) {
	$r = mysqli_query($cn, "SELECT b.id AS borrow_id, b.borrow_date, b.return_date, b.status,
		b.issue_confirmed, b.issue_otp_expires, b.return_requested, b.return_otp_expires,
		s.id AS student_id, s.acct_name AS student_name, s.gender AS student_gender, s.username AS student_user, s.role AS student_role,
		bk.id AS book_id, bk.title, bk.author, bk.category, bk.isbn
		FROM tbl_borrow b
		JOIN tbl_books bk ON b.book_id = bk.id
		JOIN tbl_login s  ON b.student_id = s.id
		ORDER BY b.borrow_date DESC");
	while ($row = mysqli_fetch_assoc($r)) $circulation[] = $row;
}

// ── Quick stats ─────────────────────────────────────────────
$totalBooks       = $cn ? (int)mysqli_fetch_assoc(mysqli_query($cn,"SELECT COUNT(*) AS c FROM tbl_books"))['c'] : 0;
$totalStudents    = $cn ? (int)mysqli_fetch_assoc(mysqli_query($cn,"SELECT COUNT(*) AS c FROM tbl_login"))['c'] : 0;
$totalBorrowed    = $cn ? (int)mysqli_fetch_assoc(mysqli_query($cn,"SELECT COUNT(*) AS c FROM tbl_borrow WHERE status='Borrowed'"))['c'] : 0;
$totalReturned    = $cn ? (int)mysqli_fetch_assoc(mysqli_query($cn,"SELECT COUNT(*) AS c FROM tbl_borrow WHERE status='Returned'"))['c'] : 0;
$totalCirculation = $cn ? (int)mysqli_fetch_assoc(mysqli_query($cn,"SELECT COUNT(*) AS c FROM tbl_borrow"))['c'] : 0;
$overdueCount     = 0;
if ($cn) {
	$oRes = mysqli_query($cn, "SELECT COUNT(*) AS c FROM tbl_borrow WHERE status='Borrowed' AND borrow_date < DATE_SUB(NOW(), INTERVAL 14 DAY)");
	$overdueCount = (int)mysqli_fetch_assoc($oRes)['c'];
}

// ── Reporting data ──────────────────────────────────────────
$popularBooks = [];
$catDistrib   = [];
$monthlyTrend = [];
$peakDays     = [];
$overdueList  = [];

if ($cn) {
	// Top 10 most borrowed books
	$r = mysqli_query($cn, "SELECT bk.title, COUNT(*) AS cnt FROM tbl_borrow b JOIN tbl_books bk ON b.book_id=bk.id GROUP BY b.book_id ORDER BY cnt DESC LIMIT 10");
	while ($row = mysqli_fetch_assoc($r)) $popularBooks[] = $row;

	// Category distribution
	$r = mysqli_query($cn, "SELECT bk.category, COUNT(*) AS cnt FROM tbl_borrow b JOIN tbl_books bk ON b.book_id=bk.id GROUP BY bk.category ORDER BY cnt DESC");
	while ($row = mysqli_fetch_assoc($r)) $catDistrib[] = $row;

	// Monthly trend (last 12 months)
	$r = mysqli_query($cn, "SELECT DATE_FORMAT(borrow_date,'%Y-%m') AS mo, COUNT(*) AS cnt FROM tbl_borrow WHERE borrow_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH) GROUP BY mo ORDER BY mo");
	while ($row = mysqli_fetch_assoc($r)) $monthlyTrend[] = $row;

	// Peak days of the week
	$r = mysqli_query($cn, "SELECT DAYNAME(borrow_date) AS d, COUNT(*) AS cnt FROM tbl_borrow GROUP BY d ORDER BY FIELD(d,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')");
	while ($row = mysqli_fetch_assoc($r)) $peakDays[] = $row;

	// Current overdue list
	$r = mysqli_query($cn, "SELECT b.id AS borrow_id, b.borrow_date, s.acct_name, s.username, s.role, bk.title, bk.author, DATEDIFF(NOW(), b.borrow_date) AS days_overdue
		FROM tbl_borrow b JOIN tbl_books bk ON b.book_id=bk.id JOIN tbl_login s ON b.student_id=s.id
		WHERE b.status='Borrowed' AND b.borrow_date < DATE_SUB(NOW(), INTERVAL 14 DAY)
		ORDER BY days_overdue DESC");
	while ($row = mysqli_fetch_assoc($r)) $overdueList[] = $row;
}

// ── Active page ─────────────────────────────────────────────
$page = $_GET['page'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>E-Library | Admin Dashboard</title>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
	<link rel="stylesheet" href="assets/toast.css">
	<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
	<style>
		@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
		*{margin:0;padding:0;box-sizing:border-box}
		:root{--sidebar:260px;--brown:#3e2723;--brown2:#5d4037;--brown3:#6d4c41;--gold:#ffd54f;--gold2:#ffb300;--bg:#f4f0ea;--card:#fff;--text:#3e2723;--muted:#8d6e63;--border:#ece3d5;--radius:14px;--shadow:0 2px 12px rgba(0,0,0,.05)}
		html{scroll-behavior:smooth}
		body{font-family:'Poppins',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex}

		/* ═══════════════ SIDEBAR ═══════════════ */
		.sidebar{width:var(--sidebar);height:100vh;position:fixed;top:0;left:0;z-index:100;background:linear-gradient(180deg,#2c1810 0%,var(--brown) 40%,var(--brown2) 100%);display:flex;flex-direction:column;transition:transform .3s cubic-bezier(.4,0,.2,1);overflow:hidden}
		.sidebar-brand{padding:28px 24px 20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid rgba(255,255,255,.08)}
		.sidebar-brand img{width:34px;height:34px;object-fit:contain;filter:drop-shadow(0 2px 8px rgba(255,213,79,.25))}
		.sidebar-brand h1{font-family:'Poppins',sans-serif;font-size:18px;color:#fff;font-weight:700;letter-spacing:.3px}
		.sidebar-brand small{display:block;font-family:'Poppins',sans-serif;font-size:10px;color:var(--gold);font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin-top:2px}

		.sidebar-nav{flex:1;padding:16px 12px;overflow-y:auto}
		.nav-label{font-size:10px;font-weight:700;color:rgba(255,255,255,.3);letter-spacing:1.5px;text-transform:uppercase;padding:10px 14px 6px;margin-top:6px}
		.nav-item{display:flex;align-items:center;gap:12px;padding:11px 16px;border-radius:10px;color:rgba(255,255,255,.65);font-size:13px;font-weight:500;text-decoration:none;transition:all .2s;cursor:pointer;margin-bottom:2px;position:relative}
		.nav-item:hover{background:rgba(255,255,255,.07);color:rgba(255,255,255,.9)}
		.nav-item.active{background:rgba(255,213,79,.12);color:var(--gold)}
		.nav-item.active::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:3px;height:22px;background:var(--gold);border-radius:0 3px 3px 0}
		.nav-item i{width:20px;text-align:center;font-size:15px}
		.nav-item .badge-count{margin-left:auto;background:rgba(255,255,255,.12);color:rgba(255,255,255,.7);font-size:10px;font-weight:700;padding:2px 8px;border-radius:50px}
		.nav-item.active .badge-count{background:rgba(255,213,79,.2);color:var(--gold)}
		.nav-item .badge-alert{margin-left:auto;background:#ef5350;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:50px}

		.sidebar-footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,.08)}
		.sidebar-user{display:flex;align-items:center;gap:10px}
		.sidebar-user .avatar{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,var(--gold),var(--gold2));display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--brown);font-size:14px}
		.sidebar-user .user-info{flex:1}
		.sidebar-user .user-info .name{color:#fff;font-size:13px;font-weight:600}
		.sidebar-user .user-info .role{color:rgba(255,255,255,.4);font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:1px}
		.sidebar-user .logout-btn{color:rgba(255,255,255,.4);font-size:16px;padding:6px;border-radius:8px;transition:.2s;text-decoration:none}
		.sidebar-user .logout-btn:hover{color:#ef5350;background:rgba(239,83,80,.1)}

		/* ═══════════════ MAIN CONTENT ═══════════════ */
		.main{margin-left:var(--sidebar);flex:1;min-height:100vh}

		.topbar{height:64px;background:var(--card);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 32px;position:sticky;top:0;z-index:50}
		.topbar-left{display:flex;align-items:center;gap:12px}
		.topbar-left h2{font-family:'Poppins',sans-serif;font-size:20px;color:var(--brown);font-weight:700}
		.hamburger{display:none;background:none;border:none;font-size:20px;color:var(--brown);cursor:pointer;padding:6px}
		.topbar-right{display:flex;align-items:center;gap:8px}
		.topbar-date{font-size:12px;color:var(--muted);background:var(--bg);padding:6px 14px;border-radius:8px}

		.content{padding:28px 32px}

		/* ═══════════════ STAT CARDS ═══════════════ */
		.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:18px;margin-bottom:28px}
		.stat-card{background:var(--card);border-radius:var(--radius);padding:22px 24px;display:flex;align-items:center;gap:16px;box-shadow:var(--shadow);border:1px solid var(--border);transition:transform .2s,box-shadow .2s}
		.stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.08)}
		.stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px}
		.stat-icon.blue{background:#e3f2fd;color:#1565c0}
		.stat-icon.green{background:#e8f5e9;color:#2e7d32}
		.stat-icon.orange{background:#fff3e0;color:#e65100}
		.stat-icon.purple{background:#f3e5f5;color:#6a1b9a}
		.stat-icon.red{background:#ffebee;color:#c62828}
		.stat-icon.teal{background:#e0f2f1;color:#00695c}
		.stat-info .num{font-family:'Poppins',sans-serif;font-size:28px;font-weight:800;line-height:1}
		.stat-info .lbl{font-size:11px;color:var(--muted);margin-top:4px;font-weight:500}

		/* ═══════════════ SECTION CARD ═══════════════ */
		.section-card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);border:1px solid var(--border);margin-bottom:24px;overflow:hidden}
		.section-header{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
		.section-header h3{font-family:'Poppins',sans-serif;font-size:18px;color:var(--brown);display:flex;align-items:center;gap:10px;font-weight:800}
		.section-header h3 i{color:var(--muted);font-size:16px}
		.section-body{padding:24px}

		/* ═══════════════ FORMS ═══════════════ */
		.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:16px}
		.form-group{display:flex;flex-direction:column;gap:5px}
		.form-group label{font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
		.form-group input,.form-group select,.form-group textarea{padding:10px 14px;border:2px solid var(--border);border-radius:10px;font-size:13px;font-family:'Poppins',sans-serif;outline:none;transition:all .25s;background:#faf8f5;color:var(--text)}
		.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--brown3);background:#fff;box-shadow:0 0 0 3px rgba(109,76,65,.08)}
		.form-group textarea{min-height:60px;resize:vertical}
		.form-full{grid-column:1/-1}

		.btn{padding:10px 22px;border:none;border-radius:10px;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;font-size:13px;transition:all .25s;display:inline-flex;align-items:center;gap:7px}
		.btn-primary{background:linear-gradient(135deg,var(--gold),var(--gold2));color:var(--brown);box-shadow:0 2px 8px rgba(255,179,0,.2)}
		.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(255,179,0,.3)}
		.btn-ghost{background:transparent;color:var(--muted);border:2px solid var(--border)}
		.btn-ghost:hover{border-color:var(--brown3);color:var(--brown)}
		.btn-danger{background:#ffebee;color:#c62828;border:2px solid #ffcdd2}
		.btn-danger:hover{background:#ffcdd2}
		.btn-info{background:#e3f2fd;color:#1565c0;border:2px solid #bbdefb}
		.btn-info:hover{background:#bbdefb}

		/* ═══════════════ TABLES ═══════════════ */
		.tbl-wrap{overflow-x:auto}
		.tbl{width:100%;border-collapse:collapse;font-size:13px}
		.tbl th{background:var(--bg);color:var(--brown2);padding:12px 16px;text-align:left;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap}
		.tbl td{padding:12px 16px;border-bottom:1px solid var(--border);vertical-align:middle}
		.tbl tbody tr{transition:background .15s}
		.tbl tbody tr:hover{background:rgba(255,213,79,.04)}
		.tbl tbody tr:last-child td{border-bottom:none}

		.tbl-actions{display:flex;gap:6px;flex-wrap:wrap}
		.btn-sm{padding:6px 12px;border-radius:8px;font-size:11px;font-weight:600;cursor:pointer;border:none;font-family:'Poppins',sans-serif;transition:.2s;display:inline-flex;align-items:center;gap:4px}
		.btn-sm.edit{background:#e3f2fd;color:#1565c0}
		.btn-sm.edit:hover{background:#bbdefb}
		.btn-sm.del{background:#ffebee;color:#c62828}
		.btn-sm.del:hover{background:#ffcdd2}
		.btn-sm.reset{background:#fff3e0;color:#e65100}
		.btn-sm.reset:hover{background:#ffe0b2}

		.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:50px;font-size:11px;font-weight:600}
		.badge-borrowed{background:#fff3e0;color:#e65100}
		.badge-returned{background:#e8f5e9;color:#2e7d32}
		.badge-overdue{background:#ffebee;color:#c62828}
		.badge-cat{background:#f3e5f5;color:#6a1b9a}
		.badge-id{background:var(--bg);color:var(--brown2);font-family:monospace;font-size:12px}
		.badge-role{padding:4px 10px;border-radius:50px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px}
		.badge-role.student{background:#e3f2fd;color:#1565c0}
		.badge-role.teacher{background:#fce4ec;color:#880e4f}

		/* ═══════════════ FILTERS ═══════════════ */
		.filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
		.filter-bar input,.filter-bar select{padding:8px 14px;border:2px solid var(--border);border-radius:8px;font-size:12px;font-family:'Poppins',sans-serif;outline:none;background:#faf8f5;transition:.2s}
		.filter-bar input:focus,.filter-bar select:focus{border-color:var(--brown3);background:#fff}
		.filter-bar input{min-width:0}

		/* ═══════════════ ALERT ═══════════════ */
		.alert{padding:14px 20px;border-radius:10px;font-size:13px;font-weight:500;margin-bottom:20px;display:flex;align-items:center;gap:10px;animation:slideDown .3s ease}
		@keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
		.alert.success{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7}
		.alert.success i{color:#2e7d32}
		.alert.error{background:#ffebee;color:#c62828;border:1px solid #ef9a9a}
		.alert.error i{color:#c62828}

		.empty-state{text-align:center;padding:50px 20px;color:var(--muted)}
		.empty-state i{font-size:48px;margin-bottom:14px;display:block;opacity:.3}
		.empty-state p{font-size:14px}

		/* ═══════════════ GENDER PILLS ═══════════════ */
		.gender-pills{display:flex;gap:10px}
		.gender-pill{display:flex;align-items:center;gap:6px;padding:8px 16px;border:2px solid var(--border);border-radius:10px;cursor:pointer;font-size:13px;font-weight:600;color:var(--brown2);background:#faf8f5;transition:.2s}
		.gender-pill:hover{border-color:var(--brown3)}
		.gender-pill input{accent-color:var(--brown2)}

		/* ═══════════════ MODAL ═══════════════ */
		.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);z-index:300;align-items:center;justify-content:center}
		.modal-overlay.show{display:flex}
		.modal{background:var(--card);border-radius:16px;padding:32px;width:560px;max-width:94vw;max-height:90vh;overflow-y:auto;box-shadow:0 25px 60px rgba(0,0,0,.25);animation:modalIn .25s ease}
		@keyframes modalIn{from{opacity:0;transform:scale(.95) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}
		.modal h3{font-family:'Poppins',sans-serif;font-size:22px;margin-bottom:20px;color:var(--brown);display:flex;align-items:center;gap:10px;font-weight:800}
		.modal-btns{display:flex;gap:10px;margin-top:18px;justify-content:flex-end}

		/* ═══════════════ PANEL VISIBILITY ═══════════════ */
		.panel{display:none}
		.panel.active{display:block}

		/* ═══════════════ CIRCULATION / MISC ═══════════════ */
		.cell-main{font-weight:600;color:var(--text)}
		.cell-sub{font-size:11px;color:var(--muted);margin-top:1px}
		.cell-date{font-weight:500}
		.cell-time{font-size:11px;color:var(--muted)}

		/* ═══════════════ ISBN LOOKUP ═══════════════ */
		.isbn-row{display:flex;gap:8px;align-items:flex-end}
		.isbn-row .form-group{flex:1}
		.isbn-row .btn{height:42px;white-space:nowrap}
		.isbn-preview{display:flex;gap:18px;align-items:flex-start;padding:14px;background:var(--bg);border-radius:10px;margin-bottom:16px;animation:slideDown .3s ease}
		.isbn-preview img{width:80px;height:auto;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.12)}
		.isbn-preview .meta{flex:1}
		.isbn-preview .meta h4{font-family:'Poppins',sans-serif;font-size:16px;margin-bottom:4px;font-weight:800}
		.isbn-preview .meta p{font-size:12px;color:var(--muted);margin-bottom:2px}
		.isbn-status{font-size:12px;padding:6px 12px;border-radius:8px;display:inline-flex;align-items:center;gap:6px;margin-bottom:12px}
		.isbn-status.loading{background:#fff3e0;color:#e65100}
		.isbn-status.error{background:#ffebee;color:#c62828}

		/* ═══════════════ CHARTS ═══════════════ */
		.chart-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(400px,1fr));gap:24px;margin-bottom:24px}
		.chart-card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);border:1px solid var(--border);padding:24px}
		.chart-card h4{font-family:'Poppins',sans-serif;font-size:16px;color:var(--brown);margin-bottom:16px;display:flex;align-items:center;gap:8px;font-weight:800}
		.chart-card h4 i{color:var(--muted);font-size:14px}
		.chart-card canvas{max-height:300px}

		/* ═══════════════ BOOK COVER THUMBNAIL ═══════════════ */
		.book-thumb{width:40px;height:56px;object-fit:cover;border-radius:4px;background:var(--bg);border:1px solid var(--border)}

		/* ═══════════════ RESPONSIVE ═══════════════ */
		@media(max-width:900px){
			.sidebar{transform:translateX(-100%)}
			.sidebar.open{transform:translateX(0)}
			.main{margin-left:0}
			.hamburger{display:block}
			.mobile-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:90}
			.mobile-overlay.show{display:block}
			.stats-grid{grid-template-columns:repeat(2,1fr)}
			.content{padding:20px 16px}
			.topbar{padding:0 16px}
			.chart-grid{grid-template-columns:1fr}
		}
		@media(max-width:500px){
			.stats-grid{grid-template-columns:1fr}
		}
	</style>
</head>
<body>

<div class="mobile-overlay" id="mobileOverlay" onclick="toggleSidebar()"></div>

<!-- ═══════════════ SIDEBAR ═══════════════ -->
<aside class="sidebar" id="sidebar">
	<div class="sidebar-brand">
		<img src="Images/Icon.png" alt="Icon">
		<div>
			<h1>E-Library</h1>
			<small>Admin Panel</small>
		</div>
	</div>

	<nav class="sidebar-nav">
		<div class="nav-label">Main</div>
		<a class="nav-item <?= $page==='dashboard'?'active':'' ?>" href="?page=dashboard">
			<i class="fas fa-chart-pie"></i> Dashboard
		</a>
		<a class="nav-item <?= $page==='books'?'active':'' ?>" href="?page=books">
			<i class="fas fa-book"></i> Catalog (OPAC)
			<span class="badge-count"><?= $totalBooks ?></span>
		</a>
		<a class="nav-item <?= $page==='circulation'?'active':'' ?>" href="?page=circulation">
			<i class="fas fa-exchange-alt"></i> Circulation
			<?php if ($overdueCount > 0): ?>
				<span class="badge-alert"><?= $overdueCount ?> overdue</span>
			<?php else: ?>
				<span class="badge-count"><?= $totalCirculation ?></span>
			<?php endif; ?>
		</a>
		<a class="nav-item <?= $page==='reservations'?'active':'' ?>" href="?page=reservations">
			<i class="fas fa-bookmark"></i> Reservations
			<?php if ($borrowedFromReservationCount > 0): ?>
				<span class="badge-alert"><?= $borrowedFromReservationCount ?> borrowed</span>
			<?php else: ?>
				<span class="badge-count"><?= $totalReservations ?></span>
			<?php endif; ?>
		</a>

		<div class="nav-label">Management</div>
		<a class="nav-item <?= $page==='users'?'active':'' ?>" href="?page=users">
			<i class="fas fa-users-cog"></i> User Management
			<span class="badge-count"><?= $totalStudents ?></span>
		</a>
		<a class="nav-item <?= $page==='addadmin'?'active':'' ?>" href="?page=addadmin">
			<i class="fas fa-user-shield"></i> Add Admin
		</a>

		<div class="nav-label">Insights</div>
		<a class="nav-item <?= $page==='reports'?'active':'' ?>" href="?page=reports">
			<i class="fas fa-chart-bar"></i> Reports & Analytics
		</a>
	</nav>

	<div class="sidebar-footer">
		<div class="sidebar-user">
			<div class="avatar"><?= strtoupper(substr($acctName,0,1)) ?></div>
			<div class="user-info">
				<div class="name"><?= htmlspecialchars($acctName) ?></div>
				<div class="role">Administrator</div>
			</div>
			<a href="logout.php" class="logout-btn" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
		</div>
	</div>
</aside>

<!-- ═══════════════ MAIN ═══════════════ -->
<div class="main">
	<div class="topbar">
		<div class="topbar-left">
			<button class="hamburger" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
			<h2>
			<?php
				$titles = [
					'dashboard'   => 'Dashboard',
					'books'       => 'Catalog Management (OPAC)',
					'circulation' => 'Book Circulation',
					'users'       => 'User Management',
					'addadmin'    => 'Add Admin',
					'reports'     => 'Reports & Analytics',
				];
				echo $titles[$page] ?? 'Dashboard';
			?>
			</h2>
		</div>
		<div class="topbar-right">
			<span class="topbar-date"><i class="far fa-calendar-alt"></i>&nbsp; <?= date('F d, Y') ?></span>
		</div>
	</div>

	<div class="content">

		<?php if ($msg): ?>
			<div class="alert <?= $msgType ?>">
				<i class="fas <?= $msgType==='success'?'fa-check-circle':'fa-exclamation-circle' ?>"></i>
				<?= $msg ?>
			</div>
		<?php endif; ?>

		<!-- ═══════════════ DASHBOARD PANEL ═══════════════ -->
		<div class="panel <?= $page==='dashboard'?'active':'' ?>" id="panel-dashboard">
			<div class="stats-grid">
				<div class="stat-card">
					<div class="stat-icon blue"><i class="fas fa-book"></i></div>
					<div class="stat-info"><div class="num"><?= $totalBooks ?></div><div class="lbl">Total Books</div></div>
				</div>
				<div class="stat-card">
					<div class="stat-icon green"><i class="fas fa-users"></i></div>
					<div class="stat-info"><div class="num"><?= $totalStudents ?></div><div class="lbl">Users</div></div>
				</div>
				<div class="stat-card">
					<div class="stat-icon orange"><i class="fas fa-hand-holding"></i></div>
					<div class="stat-info"><div class="num"><?= $totalBorrowed ?></div><div class="lbl">Currently Borrowed</div></div>
				</div>
				<div class="stat-card">
					<div class="stat-icon purple"><i class="fas fa-undo-alt"></i></div>
					<div class="stat-info"><div class="num"><?= $totalReturned ?></div><div class="lbl">Returned</div></div>
				</div>
				<div class="stat-card">
					<div class="stat-icon teal"><i class="fas fa-exchange-alt"></i></div>
					<div class="stat-info"><div class="num"><?= $totalCirculation ?></div><div class="lbl">Total Transactions</div></div>
				</div>
				<?php if ($overdueCount > 0): ?>
				<div class="stat-card">
					<div class="stat-icon red"><i class="fas fa-exclamation-triangle"></i></div>
					<div class="stat-info"><div class="num"><?= $overdueCount ?></div><div class="lbl">Overdue (>14 days)</div></div>
				</div>
				<?php endif; ?>
			</div>

			<div class="section-card">
				<div class="section-header">
					<h3><i class="fas fa-clock"></i> Recent Circulation</h3>
					<a href="?page=circulation" class="btn btn-ghost" style="font-size:12px"><i class="fas fa-arrow-right"></i> View All</a>
				</div>
				<div class="section-body">
					<?php $recent = array_slice($circulation, 0, 8); ?>
					<?php if (empty($recent)): ?>
						<div class="empty-state"><i class="fas fa-inbox"></i><p>No transactions yet</p></div>
					<?php else: ?>
						<div class="tbl-wrap">
						<table class="tbl">
							<thead><tr><th>Student</th><th>Book</th><th>Date & Time</th><th>Status</th></tr></thead>
							<tbody>
							<?php foreach ($recent as $r): ?>
								<?php $isOverdue = ($r['status'] === 'Borrowed' && strtotime($r['borrow_date']) < strtotime('-14 days')); ?>
								<tr>
									<td>
										<div class="cell-main"><?= htmlspecialchars($r['student_name']) ?></div>
										<div class="cell-sub"><?= ucfirst($r['student_role'] ?? 'student') ?> · ID: <?= $r['student_id'] ?></div>
									</td>
									<td>
										<div class="cell-main"><?= htmlspecialchars($r['title']) ?></div>
										<div class="cell-sub"><?= htmlspecialchars($r['author']) ?></div>
									</td>
									<td>
										<div class="cell-date"><?= date('M d, Y', strtotime($r['borrow_date'])) ?></div>
										<div class="cell-time"><?= date('h:i A', strtotime($r['borrow_date'])) ?></div>
									</td>
									<td>
										<?php if ($isOverdue): ?>
											<span class="badge badge-overdue"><i class="fas fa-exclamation-circle"></i> Overdue</span>
										<?php else: ?>
											<span class="badge badge-<?= strtolower($r['status']) ?>"><?= $r['status'] ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<!-- ═══════════════ BOOKS / OPAC PANEL ═══════════════ -->
		<div class="panel <?= $page==='books'?'active':'' ?>" id="panel-books">

			<!-- ISBN Lookup Section -->
			<div class="section-card">
				<div class="section-header">
					<h3><i class="fas fa-barcode"></i> ISBN Lookup — Auto-Populate from Global Database</h3>
				</div>
				<div class="section-body">
					<p style="font-size:13px;color:var(--muted);margin-bottom:14px">
						Enter an ISBN below and click <strong>Lookup</strong> to auto-fill book details from the Open Library global database. You can scan a barcode into the ISBN field.
					</p>
					<div class="isbn-row">
						<div class="form-group" style="max-width:340px">
							<label>ISBN (10 or 13 digits)</label>
							<input type="text" id="isbnLookupInput" placeholder="e.g. 978-0-13-110362-7" autocomplete="off">
						</div>
						<button type="button" class="btn btn-primary" onclick="lookupISBN()" id="isbnLookupBtn" style="margin-bottom:0">
							<i class="fas fa-search"></i> Lookup
						</button>
					</div>
					<div id="isbnStatus" style="margin-top:10px"></div>
					<div id="isbnPreview" style="margin-top:10px"></div>
				</div>
			</div>

			<!-- Add Book Form -->
			<div class="section-card">
				<div class="section-header">
					<h3><i class="fas fa-plus-circle"></i> Add New Book</h3>
				</div>
				<div class="section-body">
					<form method="post" action="?page=books" id="addBookForm">
						<div class="form-grid">
							<div class="form-group">
								<label>Title *</label>
								<input name="title" id="addTitle" placeholder="Enter book title" required>
							</div>
							<div class="form-group">
								<label>Author *</label>
								<input name="author" id="addAuthor" placeholder="Enter author name" required>
							</div>
							<div class="form-group">
								<label>Category *</label>
								<input name="category" id="addCategory" placeholder="e.g. Technology, Science" required>
							</div>
							<div class="form-group">
								<label>ISBN</label>
								<input name="isbn" id="addISBN" placeholder="e.g. 978-0-13-110362-7">
							</div>
							<div class="form-group">
								<label>Publisher</label>
								<input name="publisher" id="addPublisher" placeholder="Publisher name">
							</div>
							<div class="form-group">
								<label>Year Published</label>
								<input name="pub_year" id="addPubYear" placeholder="e.g. 2023">
							</div>
							<div class="form-group">
								<label>Quantity</label>
								<input name="quantity" type="number" min="1" value="1" required>
							</div>
							<div class="form-group">
								<label>Cover Image URL</label>
								<input name="cover_image" id="addCover" placeholder="Auto-filled from ISBN lookup">
							</div>
							<div class="form-group form-full">
								<label>Description</label>
								<textarea name="description" id="addDesc" placeholder="Short description of the book"></textarea>
							</div>
						</div>
						<button type="submit" name="add_book" class="btn btn-primary"><i class="fas fa-plus"></i> Add Book</button>
					</form>
				</div>
			</div>

			<!-- Book Catalog Table -->
			<div class="section-card">
				<div class="section-header">
					<h3><i class="fas fa-list"></i> Book Catalog</h3>
					<div class="filter-bar">
						<input type="text" id="bookSearch" placeholder="Search books..." oninput="filterBooks()">
						<select id="bookCatFilter" onchange="filterBooks()">
							<option value="">All Categories</option>
							<?php foreach ($categories as $cat): ?>
								<option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<div class="section-body" style="padding:0">
					<?php if (empty($books)): ?>
						<div class="empty-state" style="padding:50px"><i class="fas fa-book-open"></i><p>No books in the catalog yet.</p></div>
					<?php else: ?>
						<div class="tbl-wrap">
						<table class="tbl" id="booksTable">
							<thead>
								<tr><th></th><th>Title</th><th>Author</th><th>Category</th><th>ISBN</th><th>Publisher</th><th>Qty</th><th>Actions</th></tr>
							</thead>
							<tbody>
							<?php foreach ($books as $bk): ?>
								<tr data-cat="<?= htmlspecialchars($bk['category']) ?>">
									<td style="width:50px">
										<?php if (!empty($bk['cover_image'])): ?>
											<img src="<?= htmlspecialchars($bk['cover_image']) ?>" class="book-thumb" alt="Cover" loading="lazy">
										<?php else: ?>
											<div class="book-thumb" style="display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:16px"><i class="fas fa-book"></i></div>
										<?php endif; ?>
									</td>
									<td>
										<span class="cell-main"><?= htmlspecialchars($bk['title']) ?></span>
										<?php if (!empty($bk['pub_year'])): ?>
											<div class="cell-sub"><?= htmlspecialchars($bk['pub_year']) ?></div>
										<?php endif; ?>
									</td>
									<td><?= htmlspecialchars($bk['author']) ?></td>
									<td><span class="badge badge-cat"><?= htmlspecialchars($bk['category']) ?></span></td>
									<td style="font-family:monospace;font-size:12px;color:var(--muted)"><?= htmlspecialchars($bk['isbn'] ?? '—') ?></td>
									<td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($bk['publisher'] ?? '—') ?></td>
									<td><strong><?= $bk['quantity'] ?></strong></td>
									<td>
										<div class="tbl-actions">
											<button class="btn-sm edit" onclick='openEdit(<?= json_encode($bk) ?>)'><i class="fas fa-pen"></i> Edit</button>
											<form method="post" action="?page=books" style="display:inline" data-confirm="Delete this book?" data-confirm-title="Delete book" data-confirm-ok="Delete" data-confirm-cancel="Cancel" data-confirm-danger="1">
												<input type="hidden" name="book_id" value="<?= $bk['id'] ?>">
												<button type="submit" name="delete_book" class="btn-sm del"><i class="fas fa-trash"></i></button>
											</form>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<!-- ═══════════════ CIRCULATION PANEL ═══════════════ -->
		<div class="panel <?= $page==='circulation'?'active':'' ?>" id="panel-circulation">
			<div class="stats-grid" style="margin-bottom:22px">
				<div class="stat-card">
					<div class="stat-icon orange"><i class="fas fa-hand-holding"></i></div>
					<div class="stat-info"><div class="num"><?= $totalBorrowed ?></div><div class="lbl">Currently Borrowed</div></div>
				</div>
				<div class="stat-card">
					<div class="stat-icon green"><i class="fas fa-undo-alt"></i></div>
					<div class="stat-info"><div class="num"><?= $totalReturned ?></div><div class="lbl">Returned</div></div>
				</div>
				<div class="stat-card">
					<div class="stat-icon teal"><i class="fas fa-exchange-alt"></i></div>
					<div class="stat-info"><div class="num"><?= $totalCirculation ?></div><div class="lbl">Total Records</div></div>
				</div>
				<?php if ($overdueCount > 0): ?>
				<div class="stat-card">
					<div class="stat-icon red"><i class="fas fa-exclamation-triangle"></i></div>
					<div class="stat-info"><div class="num"><?= $overdueCount ?></div><div class="lbl">Overdue</div></div>
				</div>
				<?php endif; ?>
			</div>

			<div class="section-card">
				<div class="section-header">
					<h3><i class="fas fa-exchange-alt"></i> All Circulation Records</h3>
					<div class="filter-bar">
						<input type="text" id="circSearch" placeholder="Search student or book..." oninput="filterCirculation()">
						<select id="circStatus" onchange="filterCirculation()">
							<option value="">All Status</option>
							<option value="Pending Pickup">Pending Pickup</option>
							<option value="Borrowed">Borrowed</option>
							<option value="Returned">Returned</option>
							<option value="Overdue">Overdue</option>
						</select>
					</div>
				</div>
				<div class="section-body" style="padding:0">
					<?php if (empty($circulation)): ?>
						<div class="empty-state" style="padding:50px"><i class="fas fa-exchange-alt"></i><p>No circulation records yet.</p></div>
					<?php else: ?>
						<div class="tbl-wrap">
						<table class="tbl" id="circTable">
							<thead>
								<tr>
									<th>Transaction</th>
									<th>User Info</th>
									<th>Book Details</th>
									<th>Borrowed</th>
									<th>Returned</th>
									<th>Status</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($circulation as $c):
								$isOverdue = ($c['status'] === 'Borrowed' && strtotime($c['borrow_date']) < strtotime('-14 days'));
								$daysBorrowed = floor((time() - strtotime($c['borrow_date'])) / 86400);
								$isPending = ($c['status'] === 'Pending Pickup');
								$isReturnReq = ((int)($c['return_requested'] ?? 0) === 1);
							?>
								<tr data-status="<?= $isOverdue ? 'Overdue' : $c['status'] ?>">
									<td><span class="badge badge-id">#<?= str_pad($c['borrow_id'], 4, '0', STR_PAD_LEFT) ?></span></td>
									<td>
										<div class="cell-main"><?= htmlspecialchars($c['student_name']) ?></div>
										<div class="cell-sub">
											<span class="badge-role <?= $c['student_role'] ?? 'student' ?>" style="padding:2px 6px;font-size:9px"><?= ucfirst($c['student_role'] ?? 'student') ?></span>
											&nbsp;·&nbsp;@<?= htmlspecialchars($c['student_user']) ?>
										</div>
									</td>
									<td>
										<div class="cell-main"><?= htmlspecialchars($c['title']) ?></div>
										<div class="cell-sub">
											<?= htmlspecialchars($c['author']) ?>
											<?php if ($c['isbn']): ?> · ISBN: <?= htmlspecialchars($c['isbn']) ?><?php endif; ?>
										</div>
										<div class="cell-sub"><span class="badge badge-cat" style="padding:2px 8px;font-size:10px"><?= htmlspecialchars($c['category']) ?></span></div>
									</td>
									<td>
										<div class="cell-date"><?= date('M d, Y', strtotime($c['borrow_date'])) ?></div>
										<div class="cell-time"><i class="far fa-clock"></i> <?= date('h:i A', strtotime($c['borrow_date'])) ?></div>
										<?php if ($c['status'] === 'Borrowed'): ?>
											<div class="cell-sub" style="margin-top:3px"><?= $daysBorrowed ?> day<?= $daysBorrowed!==1?'s':'' ?> ago</div>
										<?php endif; ?>
									</td>
									<td>
										<?php if ($c['return_date']): ?>
											<div class="cell-date"><?= date('M d, Y', strtotime($c['return_date'])) ?></div>
											<div class="cell-time"><i class="far fa-clock"></i> <?= date('h:i A', strtotime($c['return_date'])) ?></div>
										<?php else: ?>
											<span style="color:var(--muted)">—</span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ($isPending): ?>
											<span class="badge badge-borrowed" style="background:#e3f2fd;color:#1565c0;border:1px solid #bbdefb"><i class="fas fa-hourglass-half"></i> Pending Pickup</span>
											<?php if (!empty($c['issue_otp_expires'])): ?>
												<div class="cell-sub" style="margin-top:6px">OTP expires: <?= date('M d, Y h:i A', strtotime($c['issue_otp_expires'])) ?></div>
											<?php endif; ?>
										<?php elseif ($isOverdue): ?>
											<span class="badge badge-overdue"><i class="fas fa-exclamation-circle"></i> Overdue</span>
										<?php elseif ($c['status'] === 'Borrowed'): ?>
											<span class="badge badge-borrowed"><i class="fas fa-hand-holding"></i> Borrowed</span>
											<?php if ($isReturnReq): ?>
												<div class="cell-sub" style="margin-top:6px"><span class="badge badge-overdue" style="background:#fff3e0;color:#e65100;border:1px solid #ffcc80"><i class="fas fa-key"></i> Return requested</span></div>
												<?php if (!empty($c['return_otp_expires'])): ?>
													<div class="cell-sub" style="margin-top:6px">OTP expires: <?= date('M d, Y h:i A', strtotime($c['return_otp_expires'])) ?></div>
												<?php endif; ?>
											<?php endif; ?>
										<?php else: ?>
											<span class="badge badge-returned"><i class="fas fa-check-circle"></i> Returned</span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ($isPending): ?>
											<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
												<form method="post" action="?page=circulation" style="display:inline">
													<input type="hidden" name="borrow_id" value="<?= (int)$c['borrow_id'] ?>">
													<button type="submit" name="gen_issue_otp" class="btn btn-info" style="padding:7px 12px;font-size:12px"><i class="fas fa-key"></i> Generate OTP</button>
												</form>
												<form method="post" action="?page=circulation" style="display:inline">
													<input type="hidden" name="borrow_id" value="<?= (int)$c['borrow_id'] ?>">
													<input name="otp" maxlength="4" inputmode="numeric" pattern="\d{4}" placeholder="OTP" style="width:78px;padding:7px 10px;border:2px solid var(--border);border-radius:10px;font-size:12px">
													<button type="submit" name="confirm_issue" class="btn btn-primary" style="padding:7px 12px;font-size:12px"><i class="fas fa-check"></i> Confirm Issue</button>
												</form>
											</div>
											<?php if (!empty($_SESSION['last_issue_otp']) && (int)($_SESSION['last_issue_otp']['borrow_id'] ?? 0) === (int)$c['borrow_id']): ?>
												<div class="cell-sub" style="margin-top:8px">Latest OTP: <strong><?= htmlspecialchars($_SESSION['last_issue_otp']['otp']) ?></strong></div>
											<?php endif; ?>
										<?php elseif (($c['status'] ?? '') === 'Borrowed' && $isReturnReq): ?>
											<form method="post" action="?page=circulation" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
												<input type="hidden" name="borrow_id" value="<?= (int)$c['borrow_id'] ?>">
												<input name="otp" maxlength="4" inputmode="numeric" pattern="\d{4}" placeholder="Return OTP" style="width:110px;padding:7px 10px;border:2px solid var(--border);border-radius:10px;font-size:12px">
												<button type="submit" name="confirm_return" class="btn btn-primary" style="padding:7px 12px;font-size:12px"><i class="fas fa-undo"></i> Confirm Return</button>
											</form>
										<?php else: ?>
											<span style="color:var(--muted);font-size:12px">—</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<!-- ═══════════════ RESERVATIONS PANEL ═══════════════ -->
		<div class="panel <?= $page==='reservations'?'active':'' ?>" id="panel-reservations">
			<div class="stats-grid" style="margin-bottom:22px">
				<div class="stat-card">
					<div class="stat-icon orange"><i class="fas fa-bookmark"></i></div>
					<div class="stat-info"><div class="num"><?= (int)$totalReservations ?></div><div class="lbl">Total Reservations</div></div>
				</div>
				<div class="stat-card">
					<div class="stat-icon teal"><i class="fas fa-bell"></i></div>
					<div class="stat-info"><div class="num"><?= (int)$borrowedFromReservationCount ?></div><div class="lbl">Borrowed (from reservations)</div></div>
				</div>
				<div class="stat-card">
					<div class="stat-icon green"><i class="fas fa-clock"></i></div>
					<div class="stat-info"><div class="num"><?= count($reservationReady) ?></div><div class="lbl">Ready (24h window)</div></div>
				</div>
				<div class="stat-card">
					<div class="stat-icon purple"><i class="fas fa-inbox"></i></div>
					<div class="stat-info"><div class="num"><?= count($reservationRequested) ?></div><div class="lbl">Requested</div></div>
				</div>
			</div>

			<?php if (!empty($_SESSION['last_res_desk_token'])):
				$tok = $_SESSION['last_res_desk_token'];
				unset($_SESSION['last_res_desk_token']);
			?>
				<div class="alert success" style="align-items:flex-start">
					<i class="fas fa-qrcode" style="margin-top:2px"></i>
					<div>
						<div style="font-weight:800;margin-bottom:4px">Desk QR token (show once)</div>
						<div style="font-size:12px;color:var(--muted);margin-bottom:6px">
							Reservation #<?= str_pad((string)((int)($tok['reservation_id'] ?? 0)), 4, '0', STR_PAD_LEFT) ?> · Expires <?= htmlspecialchars($tok['expires'] ?? '') ?>
						</div>
						<div style="font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;background:var(--bg);border:1px solid var(--border);padding:10px 12px;border-radius:10px;display:inline-block">
							<?= htmlspecialchars($tok['token'] ?? '') ?>
						</div>
						<div class="cell-sub" style="margin-top:8px">
							Create a QR code that encodes this token, and place it on the desk. Students will scan it and paste the token to confirm pickup.
						</div>
					</div>
				</div>
			<?php endif; ?>

			<div class="section-card">
				<div class="section-header">
					<h3><i class="fas fa-bell"></i> Borrowed from Reservations</h3>
					<div class="cell-sub">These holds were picked up and converted into real borrow transactions.</div>
				</div>
				<div class="section-body" style="padding:0">
					<?php if (empty($reservationBorrowed)): ?>
						<div class="empty-state" style="padding:50px"><i class="fas fa-book"></i><p>No reservation pickups converted to borrows yet.</p></div>
					<?php else: ?>
						<div class="tbl-wrap">
						<table class="tbl">
							<thead>
								<tr>
									<th>Reservation</th>
									<th>Borrow ID</th>
									<th>Student</th>
									<th>Book</th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($reservationBorrowed as $r): ?>
								<tr>
									<td><span class="badge badge-id">#<?= str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT) ?></span></td>
									<td>
										<?php if (!empty($r['borrow_id'])): ?>
											<span class="badge badge-id">#<?= str_pad((string)$r['borrow_id'], 4, '0', STR_PAD_LEFT) ?></span>
										<?php else: ?>
											<span style="color:var(--muted)">—</span>
										<?php endif; ?>
									</td>
									<td>
										<div class="cell-main"><?= htmlspecialchars($r['acct_name'] ?? '') ?></div>
										<div class="cell-sub"><?= htmlspecialchars($r['email'] ?? '') ?></div>
									</td>
									<td>
										<div class="cell-main"><?= htmlspecialchars($r['title'] ?? '') ?></div>
										<div class="cell-sub"><?= htmlspecialchars($r['author'] ?? '') ?></div>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<div class="section-card">
				<div class="section-header">
					<h3><i class="fas fa-inbox"></i> Requested Reservations</h3>
					<div class="cell-sub">Mark as ready once the book is prepared; students get a 24h pickup window + must confirm on-site via desk QR.</div>
				</div>
				<div class="section-body" style="padding:0">
					<?php if (empty($reservationRequested)): ?>
						<div class="empty-state" style="padding:50px"><i class="fas fa-inbox"></i><p>No reservation requests.</p></div>
					<?php else: ?>
						<div class="tbl-wrap">
						<table class="tbl">
							<thead>
								<tr>
									<th>Reservation</th>
									<th>Student</th>
									<th>Book</th>
									<th>Requested</th>
									<th>Action</th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($reservationRequested as $r): ?>
								<tr>
									<td><span class="badge badge-id">#<?= str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT) ?></span></td>
									<td>
										<div class="cell-main"><?= htmlspecialchars($r['acct_name'] ?? '') ?></div>
										<div class="cell-sub"><?= htmlspecialchars($r['email'] ?? '') ?></div>
									</td>
									<td>
										<div class="cell-main"><?= htmlspecialchars($r['title'] ?? '') ?></div>
										<div class="cell-sub"><?= htmlspecialchars($r['author'] ?? '') ?></div>
									</td>
									<td>
										<div class="cell-date"><?= date('M d, Y', strtotime($r['created_at'])) ?></div>
										<div class="cell-time"><i class="far fa-clock"></i> <?= date('h:i A', strtotime($r['created_at'])) ?></div>
									</td>
									<td>
										<form method="post" action="?page=reservations" style="display:inline">
											<input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
											<button type="submit" name="mark_res_ready" class="btn btn-primary" style="padding:7px 12px;font-size:12px">
												<i class="fas fa-bell"></i> Mark Ready (24h)
											</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<div class="section-card">
				<div class="section-header">
					<h3><i class="fas fa-clock"></i> Ready for Pickup</h3>
					<div class="cell-sub">Students see: “Your book is ready! Pick it up within 24 hours.”</div>
				</div>
				<div class="section-body" style="padding:0">
					<?php if (empty($reservationReady)): ?>
						<div class="empty-state" style="padding:50px"><i class="fas fa-clock"></i><p>No reservations currently ready.</p></div>
					<?php else: ?>
						<div class="tbl-wrap">
						<table class="tbl">
							<thead>
								<tr>
									<th>Reservation</th>
									<th>Student</th>
									<th>Book</th>
									<th>Ready Until</th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($reservationReady as $r): ?>
								<tr>
									<td><span class="badge badge-id">#<?= str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT) ?></span></td>
									<td>
										<div class="cell-main"><?= htmlspecialchars($r['acct_name'] ?? '') ?></div>
										<div class="cell-sub"><?= htmlspecialchars($r['email'] ?? '') ?></div>
									</td>
									<td>
										<div class="cell-main"><?= htmlspecialchars($r['title'] ?? '') ?></div>
										<div class="cell-sub"><?= htmlspecialchars($r['author'] ?? '') ?></div>
									</td>
									<td>
										<?php if (!empty($r['ready_expires_at'])): ?>
											<div class="cell-date"><?= date('M d, Y', strtotime($r['ready_expires_at'])) ?></div>
											<div class="cell-time"><i class="far fa-clock"></i> <?= date('h:i A', strtotime($r['ready_expires_at'])) ?></div>
										<?php else: ?>
											<span style="color:var(--muted)">—</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<!-- ═══════════════ USER MANAGEMENT PANEL ═══════════════ -->
		<div class="panel <?= $page==='users'?'active':'' ?>" id="panel-users">

			<!-- Add User Form -->
			<div class="section-card">
				<div class="section-header">
					<h3><i class="fas fa-user-plus"></i> Add New User</h3>
				</div>
				<div class="section-body">
					<form method="post" action="?page=users">
						<div class="form-grid">
							<div class="form-group">
								<label>Full Name *</label>
								<input name="acct_name" placeholder="Enter full name" required>
							</div>
							<div class="form-group">
								<label>Username *</label>
								<input name="username" placeholder="Choose a username" required>
							</div>
							<div class="form-group">
								<label>Password *</label>
								<input name="password" type="password" placeholder="Set a password" required>
							</div>
							<div class="form-group">
								<label>Role</label>
								<select name="role" id="newUserRole" onchange="autoSetLimit()">
									<option value="student">Student</option>
									<option value="teacher">Teacher</option>
								</select>
							</div>
							<div class="form-group">
								<label>Gender *</label>
								<div class="gender-pills">
									<label class="gender-pill">
										<input type="radio" name="gender" value="Male" required>
										<i class="fas fa-mars" style="color:#1565c0"></i> Male
									</label>
									<label class="gender-pill">
										<input type="radio" name="gender" value="Female">
										<i class="fas fa-venus" style="color:#ad1457"></i> Female
									</label>
								</div>
							</div>
						</div>
						<button type="submit" name="add_user" class="btn btn-primary"><i class="fas fa-user-plus"></i> Add User</button>
					</form>
				</div>
			</div>

			<!-- User Table -->
			<div class="section-card">
				<div class="section-header">
					<h3><i class="fas fa-users"></i> All Users</h3>
					<div class="filter-bar">
						<input type="text" id="userSearch" placeholder="Search users..." oninput="filterUsers()">
						<select id="userRoleFilter" onchange="filterUsers()">
							<option value="">All Roles</option>
							<option value="student">Students</option>
							<option value="teacher">Teachers</option>
						</select>
					</div>
				</div>
				<div class="section-body" style="padding:0">
					<?php if (empty($students)): ?>
						<div class="empty-state" style="padding:50px"><i class="fas fa-users"></i><p>No users registered yet.</p></div>
					<?php else: ?>
						<div class="tbl-wrap">
						<table class="tbl" id="usersTable">
							<thead>
								<tr><th>ID</th><th>Name</th><th>Gender</th><th>Username</th><th>Role</th><th>Borrow Limit</th><th>Joined</th><th>Actions</th></tr>
							</thead>
							<tbody>
							<?php foreach ($students as $s): ?>
								<tr data-role="<?= $s['role'] ?? 'student' ?>">
									<td><span class="badge badge-id"><?= $s['id'] ?></span></td>
									<td><span class="cell-main"><?= htmlspecialchars($s['acct_name']) ?></span></td>
									<td>
										<i class="fas fa-<?= strtolower($s['gender'])==='male'?'mars':'venus' ?>" style="color:<?= strtolower($s['gender'])==='male'?'#1565c0':'#ad1457' ?>"></i>
										<?= htmlspecialchars($s['gender']) ?>
									</td>
									<td style="color:var(--muted)">@<?= htmlspecialchars($s['username']) ?></td>
									<td><span class="badge-role <?= $s['role'] ?? 'student' ?>"><?= ucfirst($s['role'] ?? 'student') ?></span></td>
									<td style="text-align:center"><strong><?= $s['borrow_limit'] ?? 3 ?></strong></td>
									<td><?= isset($s['created_at']) ? date('M d, Y', strtotime($s['created_at'])) : '—' ?></td>
									<td>
										<div class="tbl-actions">
											<button class="btn-sm edit" onclick='openUserEdit(<?= json_encode($s) ?>)'><i class="fas fa-pen"></i> Edit</button>
											<form method="post" action="?page=users" style="display:inline" data-confirm="Reset password to password123?" data-confirm-title="Reset password" data-confirm-ok="Reset" data-confirm-cancel="Cancel" data-confirm-danger="1">
												<input type="hidden" name="user_id" value="<?= $s['id'] ?>">
												<button type="submit" name="reset_password" class="btn-sm reset"><i class="fas fa-key"></i> Reset</button>
											</form>
											<form method="post" action="?page=users" style="display:inline" data-confirm="Remove this user and all their borrow records?" data-confirm-title="Remove user" data-confirm-ok="Remove" data-confirm-cancel="Cancel" data-confirm-danger="1">
												<input type="hidden" name="student_id" value="<?= $s['id'] ?>">
												<button type="submit" name="delete_student" class="btn-sm del"><i class="fas fa-trash"></i></button>
											</form>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<!-- ═══════════════ ADD ADMIN PANEL ═══════════════ -->
		<div class="panel <?= $page==='addadmin'?'active':'' ?>" id="panel-addadmin">
			<div class="section-card" style="max-width:560px">
				<div class="section-header">
					<h3><i class="fas fa-user-shield"></i> Register New Admin</h3>
				</div>
				<div class="section-body">
					<form method="post" action="adminreg.php">
						<div class="form-grid" style="grid-template-columns:1fr">
							<div class="form-group">
								<label>Account Name</label>
								<input name="txtan" placeholder="Enter full name" required>
							</div>
							<div class="form-group">
								<label>Gender</label>
								<div class="gender-pills">
									<label class="gender-pill">
										<input type="radio" name="txtgender" value="Male" required>
										<i class="fas fa-mars" style="color:#1565c0"></i> Male
									</label>
									<label class="gender-pill">
										<input type="radio" name="txtgender" value="Female">
										<i class="fas fa-venus" style="color:#ad1457"></i> Female
									</label>
								</div>
							</div>
							<div class="form-group">
								<label>Username</label>
								<input name="txtun" placeholder="Choose a username" required>
							</div>
							<div class="form-group">
								<label>Password</label>
								<input name="txtpw" type="password" placeholder="Set a password" required>
							</div>
						</div>
						<button type="submit" name="btnreg" class="btn btn-primary"><i class="fas fa-user-plus"></i> Register Admin</button>
					</form>
				</div>
			</div>
		</div>

		<!-- ═══════════════ REPORTS & ANALYTICS PANEL ═══════════════ -->
		<div class="panel <?= $page==='reports'?'active':'' ?>" id="panel-reports">

			<!-- Quick Stats Row -->
			<div class="stats-grid">
				<div class="stat-card">
					<div class="stat-icon blue"><i class="fas fa-book"></i></div>
					<div class="stat-info"><div class="num"><?= $totalBooks ?></div><div class="lbl">Cataloged Books</div></div>
				</div>
				<div class="stat-card">
					<div class="stat-icon green"><i class="fas fa-users"></i></div>
					<div class="stat-info"><div class="num"><?= $totalStudents ?></div><div class="lbl">Total Users</div></div>
				</div>
				<div class="stat-card">
					<div class="stat-icon teal"><i class="fas fa-exchange-alt"></i></div>
					<div class="stat-info"><div class="num"><?= $totalCirculation ?></div><div class="lbl">Total Borrows</div></div>
				</div>
				<div class="stat-card">
					<div class="stat-icon red"><i class="fas fa-exclamation-triangle"></i></div>
					<div class="stat-info"><div class="num"><?= $overdueCount ?></div><div class="lbl">Currently Overdue</div></div>
				</div>
			</div>

			<!-- Charts Row 1 -->
			<div class="chart-grid">
				<div class="chart-card">
					<h4><i class="fas fa-trophy"></i> Most Popular Books (Top 10)</h4>
					<?php if (empty($popularBooks)): ?>
						<div class="empty-state"><i class="fas fa-chart-bar"></i><p>No borrowing data yet.</p></div>
					<?php else: ?>
						<canvas id="chartPopular"></canvas>
					<?php endif; ?>
				</div>
				<div class="chart-card">
					<h4><i class="fas fa-tags"></i> Borrows by Category</h4>
					<?php if (empty($catDistrib)): ?>
						<div class="empty-state"><i class="fas fa-chart-pie"></i><p>No data yet.</p></div>
					<?php else: ?>
						<canvas id="chartCategory"></canvas>
					<?php endif; ?>
				</div>
			</div>

			<!-- Charts Row 2 -->
			<div class="chart-grid">
				<div class="chart-card">
					<h4><i class="fas fa-chart-line"></i> Monthly Borrowing Trend (Last 12 Months)</h4>
					<?php if (empty($monthlyTrend)): ?>
						<div class="empty-state"><i class="fas fa-chart-line"></i><p>No data yet.</p></div>
					<?php else: ?>
						<canvas id="chartMonthly"></canvas>
					<?php endif; ?>
				</div>
				<div class="chart-card">
					<h4><i class="fas fa-calendar-week"></i> Peak Usage by Day of the Week</h4>
					<?php if (empty($peakDays)): ?>
						<div class="empty-state"><i class="fas fa-calendar-alt"></i><p>No data yet.</p></div>
					<?php else: ?>
						<canvas id="chartPeakDays"></canvas>
					<?php endif; ?>
				</div>
			</div>

			<!-- Overdue Items Detail -->
			<div class="section-card">
				<div class="section-header">
					<h3><i class="fas fa-exclamation-triangle"></i> Overdue Items</h3>
					<span style="font-size:12px;color:var(--muted)">Books borrowed for more than 14 days</span>
				</div>
				<div class="section-body" style="padding:0">
					<?php if (empty($overdueList)): ?>
						<div class="empty-state" style="padding:40px"><i class="fas fa-check-circle" style="color:#2e7d32"></i><p>No overdue items — everything is on time!</p></div>
					<?php else: ?>
						<div class="tbl-wrap">
						<table class="tbl">
							<thead><tr><th>#</th><th>User</th><th>Book</th><th>Borrowed On</th><th>Days Overdue</th></tr></thead>
							<tbody>
							<?php foreach ($overdueList as $i => $od): ?>
								<tr>
									<td><span class="badge badge-id">#<?= str_pad($od['borrow_id'], 4, '0', STR_PAD_LEFT) ?></span></td>
									<td>
										<div class="cell-main"><?= htmlspecialchars($od['acct_name']) ?></div>
										<div class="cell-sub">
											<span class="badge-role <?= $od['role'] ?? 'student' ?>" style="padding:2px 6px;font-size:9px"><?= ucfirst($od['role'] ?? 'student') ?></span>
											&nbsp;@<?= htmlspecialchars($od['username']) ?>
										</div>
									</td>
									<td>
										<div class="cell-main"><?= htmlspecialchars($od['title']) ?></div>
										<div class="cell-sub"><?= htmlspecialchars($od['author']) ?></div>
									</td>
									<td><?= date('M d, Y', strtotime($od['borrow_date'])) ?></td>
									<td><span class="badge badge-overdue"><i class="fas fa-exclamation-circle"></i> <?= $od['days_overdue'] - 14 ?> days overdue</span></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

	</div><!-- /.content -->
</div><!-- /.main -->

<!-- ═══════════════ EDIT BOOK MODAL ═══════════════ -->
<div class="modal-overlay" id="editModal">
	<div class="modal">
		<h3><i class="fas fa-pen"></i> Edit Book</h3>
		<form method="post" action="?page=books">
			<input type="hidden" name="book_id" id="edit_id">
			<div class="form-grid" style="grid-template-columns:1fr">
				<div class="form-group"><label>Title</label><input name="title" id="edit_title" required></div>
				<div class="form-group"><label>Author</label><input name="author" id="edit_author" required></div>
				<div class="form-group"><label>Category</label><input name="category" id="edit_category" required></div>
				<div class="form-group"><label>ISBN</label><input name="isbn" id="edit_isbn"></div>
				<div class="form-group"><label>Publisher</label><input name="publisher" id="edit_publisher"></div>
				<div class="form-group"><label>Year Published</label><input name="pub_year" id="edit_pub_year"></div>
				<div class="form-group"><label>Quantity</label><input name="quantity" type="number" min="0" id="edit_qty" required></div>
				<div class="form-group"><label>Cover Image URL</label><input name="cover_image" id="edit_cover"></div>
				<div class="form-group"><label>Description</label><textarea name="description" id="edit_desc"></textarea></div>
			</div>
			<div class="modal-btns">
				<button type="button" class="btn btn-ghost" onclick="closeEdit()">Cancel</button>
				<button type="submit" name="edit_book" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
			</div>
		</form>
	</div>
</div>

<!-- ═══════════════ EDIT USER MODAL ═══════════════ -->
<div class="modal-overlay" id="editUserModal">
	<div class="modal">
		<h3><i class="fas fa-user-edit"></i> Edit User</h3>
		<form method="post" action="?page=users">
			<input type="hidden" name="user_id" id="eu_id">
			<div class="form-grid" style="grid-template-columns:1fr">
				<div class="form-group">
					<label>Name (read only)</label>
					<input id="eu_name" disabled style="opacity:.6">
				</div>
				<div class="form-group">
					<label>Role</label>
					<select name="role" id="eu_role" onchange="autoLimitFromRole()">
						<option value="student">Student</option>
						<option value="teacher">Teacher</option>
					</select>
				</div>
				<div class="form-group">
					<label>Borrow Limit</label>
					<input name="borrow_limit" id="eu_limit" type="number" min="1" max="50" required>
				</div>
			</div>
			<div class="modal-btns">
				<button type="button" class="btn btn-ghost" onclick="closeUserEdit()">Cancel</button>
				<button type="submit" name="edit_user" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
			</div>
		</form>
	</div>
</div>

<script src="assets/toast.js"></script>
<script>
// ═══════════════ SIDEBAR TOGGLE ═══════════════
function toggleSidebar(){
	document.getElementById('sidebar').classList.toggle('open');
	document.getElementById('mobileOverlay').classList.toggle('show');
}

// ═══════════════ EDIT BOOK MODAL ═══════════════
function openEdit(bk){
	document.getElementById('edit_id').value       = bk.id;
	document.getElementById('edit_title').value    = bk.title;
	document.getElementById('edit_author').value   = bk.author;
	document.getElementById('edit_category').value = bk.category;
	document.getElementById('edit_isbn').value     = bk.isbn || '';
	document.getElementById('edit_qty').value      = bk.quantity;
	document.getElementById('edit_desc').value     = bk.description || '';
	document.getElementById('edit_cover').value    = bk.cover_image || '';
	document.getElementById('edit_publisher').value= bk.publisher || '';
	document.getElementById('edit_pub_year').value = bk.pub_year || '';
	document.getElementById('editModal').classList.add('show');
}
function closeEdit(){
	document.getElementById('editModal').classList.remove('show');
}
document.getElementById('editModal').addEventListener('click', function(e){
	if(e.target === this) closeEdit();
});

// ═══════════════ EDIT USER MODAL ═══════════════
function openUserEdit(u){
	document.getElementById('eu_id').value    = u.id;
	document.getElementById('eu_name').value  = u.acct_name;
	document.getElementById('eu_role').value  = u.role || 'student';
	document.getElementById('eu_limit').value = u.borrow_limit || 3;
	document.getElementById('editUserModal').classList.add('show');
}
function closeUserEdit(){
	document.getElementById('editUserModal').classList.remove('show');
}
document.getElementById('editUserModal').addEventListener('click', function(e){
	if(e.target === this) closeUserEdit();
});
function autoLimitFromRole(){
	const role = document.getElementById('eu_role').value;
	document.getElementById('eu_limit').value = role === 'teacher' ? 5 : 3;
}

// ═══════════════ ISBN LOOKUP ═══════════════
async function lookupISBN(){
	const isbn = document.getElementById('isbnLookupInput').value.trim();
	const statusEl = document.getElementById('isbnStatus');
	const previewEl = document.getElementById('isbnPreview');
	const btn = document.getElementById('isbnLookupBtn');

	if(!isbn){ statusEl.innerHTML = '<div class="isbn-status error"><i class="fas fa-exclamation-circle"></i> Please enter an ISBN.</div>'; return; }

	statusEl.innerHTML = '<div class="isbn-status loading"><i class="fas fa-spinner fa-spin"></i> Looking up ISBN…</div>';
	previewEl.innerHTML = '';
	btn.disabled = true;

	try {
		const resp = await fetch('isbn_lookup.php?isbn=' + encodeURIComponent(isbn));
		const json = await resp.json();
		if(!json.success){
			statusEl.innerHTML = '<div class="isbn-status error"><i class="fas fa-times-circle"></i> ' + json.error + '</div>';
			btn.disabled = false;
			return;
		}
		const d = json.data;

		// Auto-fill the add-book form
		document.getElementById('addTitle').value     = d.title || '';
		document.getElementById('addAuthor').value    = d.author || '';
		document.getElementById('addCategory').value  = d.category || '';
		document.getElementById('addISBN').value      = isbn;
		document.getElementById('addCover').value     = d.cover_image || '';
		document.getElementById('addDesc').value      = d.description || '';
		document.getElementById('addPublisher').value = d.publisher || '';
		document.getElementById('addPubYear').value   = d.pub_year || '';

		statusEl.innerHTML = '<div class="isbn-status" style="background:#e8f5e9;color:#2e7d32"><i class="fas fa-check-circle"></i> Book found! Fields auto-populated below.</div>';

		let previewHtml = '<div class="isbn-preview">';
		if(d.cover_image){
			previewHtml += '<img src="' + d.cover_image + '" alt="Cover">';
		}
		previewHtml += '<div class="meta"><h4>' + (d.title||'—') + '</h4>';
		previewHtml += '<p><strong>Author:</strong> ' + (d.author||'—') + '</p>';
		if(d.publisher) previewHtml += '<p><strong>Publisher:</strong> ' + d.publisher + '</p>';
		if(d.pub_year) previewHtml += '<p><strong>Year:</strong> ' + d.pub_year + '</p>';
		if(d.category && d.category !== 'General') previewHtml += '<p><strong>Subject:</strong> ' + d.category + '</p>';
		previewHtml += '</div></div>';
		previewEl.innerHTML = previewHtml;

	} catch(err){
		statusEl.innerHTML = '<div class="isbn-status error"><i class="fas fa-times-circle"></i> Network error — could not reach server.</div>';
	}
	btn.disabled = false;
}

// Allow Enter in ISBN input to trigger lookup
document.getElementById('isbnLookupInput').addEventListener('keydown', function(e){
	if(e.key === 'Enter'){ e.preventDefault(); lookupISBN(); }
});

// ═══════════════ TABLE FILTERS ═══════════════
function filterBooks(){
	const q = document.getElementById('bookSearch').value.toLowerCase();
	const cat = document.getElementById('bookCatFilter').value;
	document.querySelectorAll('#booksTable tbody tr').forEach(tr=>{
		const text = Array.from(tr.querySelectorAll('td')).map(td=>td.textContent.toLowerCase()).join(' ');
		const rowCat = tr.dataset.cat || '';
		const matchText = text.includes(q);
		const matchCat = !cat || rowCat === cat;
		tr.style.display = (matchText && matchCat) ? '' : 'none';
	});
}

function filterUsers(){
	const q = document.getElementById('userSearch').value.toLowerCase();
	const role = document.getElementById('userRoleFilter').value;
	document.querySelectorAll('#usersTable tbody tr').forEach(tr=>{
		const text = Array.from(tr.querySelectorAll('td')).map(td=>td.textContent.toLowerCase()).join(' ');
		const rowRole = tr.dataset.role || '';
		const matchText = text.includes(q);
		const matchRole = !role || rowRole === role;
		tr.style.display = (matchText && matchRole) ? '' : 'none';
	});
}

function filterCirculation(){
	const q = document.getElementById('circSearch').value.toLowerCase();
	const status = document.getElementById('circStatus').value;
	document.querySelectorAll('#circTable tbody tr').forEach(tr=>{
		const text = Array.from(tr.querySelectorAll('td')).map(td=>td.textContent.toLowerCase()).join(' ');
		const rowStatus = tr.dataset.status;
		const matchText = text.includes(q);
		const matchStatus = !status || rowStatus === status;
		tr.style.display = (matchText && matchStatus) ? '' : 'none';
	});
}

// ═══════════════ CHART.JS RENDERING ═══════════════
const brownPalette = ['#8d6e63','#a1887f','#bcaaa4','#d7ccc8','#5d4037','#4e342e','#3e2723','#6d4c41','#795548','#efebe9'];
const colorful = ['#1565c0','#2e7d32','#e65100','#6a1b9a','#00695c','#c62828','#ad1457','#283593','#00838f','#558b2f'];

<?php if (!empty($popularBooks)): ?>
new Chart(document.getElementById('chartPopular'),{
	type:'bar',
	data:{
		labels: <?= json_encode(array_map(function($b){ return mb_strimwidth($b['title'],0,30,'…'); }, $popularBooks)) ?>,
		datasets:[{
			label:'Times Borrowed',
			data: <?= json_encode(array_column($popularBooks,'cnt')) ?>,
			backgroundColor: colorful.slice(0, <?= count($popularBooks) ?>),
			borderRadius: 6,
			borderSkipped: false,
		}]
	},
	options:{
		indexAxis:'y',
		responsive:true,
		maintainAspectRatio:false,
		plugins:{legend:{display:false}},
		scales:{
			x:{grid:{display:false},ticks:{stepSize:1,precision:0}},
			y:{grid:{display:false},ticks:{font:{size:11}}}
		}
	}
});
document.getElementById('chartPopular').parentElement.style.height = Math.max(200, <?= count($popularBooks) ?> * 38) + 'px';
<?php endif; ?>

<?php if (!empty($catDistrib)): ?>
new Chart(document.getElementById('chartCategory'),{
	type:'doughnut',
	data:{
		labels: <?= json_encode(array_column($catDistrib,'category')) ?>,
		datasets:[{
			data: <?= json_encode(array_column($catDistrib,'cnt')) ?>,
			backgroundColor: colorful.slice(0, <?= count($catDistrib) ?>),
			borderWidth: 2,
			borderColor: '#fff',
		}]
	},
	options:{
		responsive:true,
		maintainAspectRatio:true,
		plugins:{
			legend:{position:'right',labels:{font:{size:11},padding:10,usePointStyle:true,pointStyle:'circle'}}
		}
	}
});
<?php endif; ?>

<?php if (!empty($monthlyTrend)): ?>
new Chart(document.getElementById('chartMonthly'),{
	type:'line',
	data:{
		labels: <?= json_encode(array_map(function($m){ return date('M Y', strtotime($m['mo'].'-01')); }, $monthlyTrend)) ?>,
		datasets:[{
			label:'Borrows',
			data: <?= json_encode(array_column($monthlyTrend,'cnt')) ?>,
			borderColor:'#5d4037',
			backgroundColor:'rgba(93,64,55,.1)',
			tension:.4,
			fill:true,
			pointBackgroundColor:'#ffd54f',
			pointBorderColor:'#5d4037',
			pointRadius:5,
			pointHoverRadius:7,
		}]
	},
	options:{
		responsive:true,
		maintainAspectRatio:true,
		plugins:{legend:{display:false}},
		scales:{
			x:{grid:{display:false},ticks:{font:{size:10}}},
			y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.04)'},ticks:{stepSize:1,precision:0}}
		}
	}
});
<?php endif; ?>

<?php if (!empty($peakDays)): ?>
new Chart(document.getElementById('chartPeakDays'),{
	type:'bar',
	data:{
		labels: <?= json_encode(array_column($peakDays,'d')) ?>,
		datasets:[{
			label:'Borrows',
			data: <?= json_encode(array_column($peakDays,'cnt')) ?>,
			backgroundColor: brownPalette.slice(0, <?= count($peakDays) ?>),
			borderRadius: 8,
			borderSkipped: false,
		}]
	},
	options:{
		responsive:true,
		maintainAspectRatio:true,
		plugins:{legend:{display:false}},
		scales:{
			x:{grid:{display:false}},
			y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.04)'},ticks:{stepSize:1,precision:0}}
		}
	}
});
<?php endif; ?>
</script>

</body>
</html>
