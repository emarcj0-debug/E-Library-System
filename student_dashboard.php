<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_role('student');

$cn = db_connect();
$userId   = (int)($_SESSION['user_id'] ?? 0);
$acctName = $_SESSION['acct_name'];
$msg      = '';
$msgType  = '';

// Validate the logged-in user still exists (prevents FK errors on insert)
if ($cn && $userId > 0) {
	$uChk = mysqli_prepare($cn, "SELECT id FROM tbl_login WHERE id=? LIMIT 1");
	mysqli_stmt_bind_param($uChk, 'i', $userId);
	mysqli_stmt_execute($uChk);
	$uRes = mysqli_stmt_get_result($uChk);
	$uRow = mysqli_fetch_assoc($uRes);
	mysqli_stmt_close($uChk);
	if (!$uRow) {
		// Session points to a missing/deleted user. Force re-login.
		session_unset();
		session_destroy();
		header('Location: login.html?err=1');
		exit;
	}
}

// Session flash (for redirects like confirm_pickup.php)
if (isset($_SESSION['flash_msg'])) {
	$msg = (string)$_SESSION['flash_msg'];
	$msgType = (string)($_SESSION['flash_type'] ?? 'success');
	unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// ── OTP helpers ────────────────────────────────────────────
function otp_generate_4digit(): string {
	return str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}

function otp_hash(string $otp): string {
	return hash('sha256', $otp);
}

function otp_hash_equals(?string $hash, string $otp): bool {
	if (!$hash) return false;
	return hash_equals($hash, otp_hash($otp));
}

function otp_not_expired(?string $expires): bool {
	if (!$expires) return false;
	$ts = strtotime($expires);
	return ($ts !== false) && ($ts >= time());
}

// ── Reservation pickup -> create Borrow record ──────────────
// Student can pick up a READY reservation anytime (no QR), and it becomes a real Borrowed transaction.
if (isset($_POST['pickup_reservation']) && $cn) {
	$resId = (int)($_POST['reservation_id'] ?? 0);
	if ($resId <= 0) {
		$msg = 'Invalid reservation.'; $msgType = 'error';
	} else {
		// Load reservation and ensure it belongs to the student and is Ready
		$stmt = mysqli_prepare($cn, "SELECT id, book_id, status FROM tbl_reservations WHERE id=? AND student_id=? LIMIT 1");
		mysqli_stmt_bind_param($stmt, 'ii', $resId, $userId);
		mysqli_stmt_execute($stmt);
		$res = mysqli_stmt_get_result($stmt);
		$rRow = mysqli_fetch_assoc($res);
		mysqli_stmt_close($stmt);

		if (!$rRow || ($rRow['status'] ?? '') !== 'Ready') {
			$msg = "This reservation isn't ready for pickup."; $msgType = 'warning';
		} else {
			$bookId = (int)$rRow['book_id'];

			// Fetch user's borrow limit
			$blStmt = mysqli_prepare($cn, "SELECT borrow_limit FROM tbl_login WHERE id = ?");
			mysqli_stmt_bind_param($blStmt, 'i', $userId);
			mysqli_stmt_execute($blStmt);
			$blRes = mysqli_stmt_get_result($blStmt);
			$blRow = mysqli_fetch_assoc($blRes);
			$borrowLimit = (int)($blRow['borrow_limit'] ?? 3);
			mysqli_stmt_close($blStmt);

			$cntStmt = mysqli_prepare($cn, "SELECT COUNT(*) AS c FROM tbl_borrow WHERE student_id = ? AND status = 'Borrowed'");
			mysqli_stmt_bind_param($cntStmt, 'i', $userId);
			mysqli_stmt_execute($cntStmt);
			$cntRes = mysqli_stmt_get_result($cntStmt);
			$activeBorrows = (int)mysqli_fetch_assoc($cntRes)['c'];
			mysqli_stmt_close($cntStmt);

			if ($activeBorrows >= $borrowLimit) {
				$msg = "You have reached your borrow limit ($borrowLimit books).";
				$msgType = 'error';
			} else {
				// Create borrow record immediately as Borrowed. Decrement stock now.
				try {
					mysqli_begin_transaction($cn);

					// Ensure book still has available quantity
					$bk = mysqli_prepare($cn, "SELECT quantity FROM tbl_books WHERE id=? FOR UPDATE");
					mysqli_stmt_bind_param($bk, 'i', $bookId);
					mysqli_stmt_execute($bk);
					$bkr = mysqli_stmt_get_result($bk);
					$bkRow = mysqli_fetch_assoc($bkr);
					mysqli_stmt_close($bk);

					if (!$bkRow || (int)$bkRow['quantity'] <= 0) {
						mysqli_rollback($cn);
						$msg = 'This book is currently out of stock. Please wait for the librarian.';
						$msgType = 'error';
					} else {
						$ins = mysqli_prepare($cn, "INSERT INTO tbl_borrow (student_id, book_id, status, issue_confirmed) VALUES (?, ?, 'Borrowed', 1)");
						mysqli_stmt_bind_param($ins, 'ii', $userId, $bookId);
						mysqli_stmt_execute($ins);
						$borrowId = mysqli_insert_id($cn);
						mysqli_stmt_close($ins);

						$dec = mysqli_prepare($cn, "UPDATE tbl_books SET quantity = quantity - 1 WHERE id=? AND quantity > 0");
						mysqli_stmt_bind_param($dec, 'i', $bookId);
						mysqli_stmt_execute($dec);
						mysqli_stmt_close($dec);

						$u = mysqli_prepare($cn, "UPDATE tbl_reservations SET status='Borrowed', borrow_id=? WHERE id=? AND student_id=? AND status='Ready'");
						mysqli_stmt_bind_param($u, 'iii', $borrowId, $resId, $userId);
						mysqli_stmt_execute($u);
						mysqli_stmt_close($u);

						mysqli_commit($cn);
						$msg = "Picked up successfully! Borrow Transaction #" . str_pad((string)$borrowId, 4, '0', STR_PAD_LEFT) . ". Use Return OTP when returning.";
						$msgType = 'success';
					}
				} catch (mysqli_sql_exception $e) {
					@mysqli_rollback($cn);
					$msg = 'Pickup failed. Please try again.';
					$msgType = 'error';
				}
			}
		}
	}
}

// ── Handle borrow request (student) ─────────────────────────
// Student requests a borrow; admin confirms and generates OTP during pickup.
if (isset($_POST['borrow_book']) && $cn) {
	$bookId = (int)$_POST['book_id'];

	if ($userId <= 0) {
		$msg = 'Session expired. Please login again.';
		$msgType = 'error';
	} else {

	// Fetch user's borrow limit
	$blStmt = mysqli_prepare($cn, "SELECT borrow_limit FROM tbl_login WHERE id = ?");
	mysqli_stmt_bind_param($blStmt, 'i', $userId);
	mysqli_stmt_execute($blStmt);
	$blRes = mysqli_stmt_get_result($blStmt);
	$blRow = mysqli_fetch_assoc($blRes);
	$borrowLimit = (int)($blRow['borrow_limit'] ?? 3);
	mysqli_stmt_close($blStmt);

	// Count current active borrows
	$cntStmt = mysqli_prepare($cn, "SELECT COUNT(*) AS c FROM tbl_borrow WHERE student_id = ? AND status = 'Borrowed'");
	mysqli_stmt_bind_param($cntStmt, 'i', $userId);
	mysqli_stmt_execute($cntStmt);
	$cntRes = mysqli_stmt_get_result($cntStmt);
	$activeBorrows = (int)mysqli_fetch_assoc($cntRes)['c'];
	mysqli_stmt_close($cntStmt);

	if ($activeBorrows >= $borrowLimit) {
		$msg = "You have reached your borrow limit ($borrowLimit books). Please return a book first.";
		$msgType = "error";
	} else {
		// Check quantity
		$chk = mysqli_prepare($cn, "SELECT quantity FROM tbl_books WHERE id = ? AND quantity > 0");
		mysqli_stmt_bind_param($chk, 'i', $bookId);
		mysqli_stmt_execute($chk);
		$res = mysqli_stmt_get_result($chk);
		if (mysqli_fetch_assoc($res)) {
			// Already borrowed or pending pickup?
			$dup = mysqli_prepare($cn, "SELECT id FROM tbl_borrow WHERE student_id = ? AND book_id = ? AND status IN ('Borrowed','Pending Pickup') LIMIT 1");
			mysqli_stmt_bind_param($dup, 'ii', $userId, $bookId);
			mysqli_stmt_execute($dup);
			mysqli_stmt_store_result($dup);
			if (mysqli_stmt_num_rows($dup) > 0) {
				$msg = "You already have an active or pending request for this book.";
				$msgType = "warning";
			} else {
				// Create pending transaction; stock is reduced only when issue is confirmed by admin.
				try {
					$ins = mysqli_prepare($cn, "INSERT INTO tbl_borrow (student_id, book_id, status, issue_confirmed) VALUES (?, ?, 'Pending Pickup', 0)");
					mysqli_stmt_bind_param($ins, 'ii', $userId, $bookId);
					mysqli_stmt_execute($ins);
					$borrowId = mysqli_insert_id($cn);
					$msg = "Request submitted! Go to the librarian to receive your pickup OTP. Transaction #" . str_pad((string)$borrowId, 4, '0', STR_PAD_LEFT);
					$msgType = "success";
					mysqli_stmt_close($ins);
				} catch (mysqli_sql_exception $e) {
					// Most common: FK fails because session user doesn't exist anymore.
					$msg = 'Unable to submit request. Please logout and login again.';
					$msgType = 'error';
				}
			}
			mysqli_stmt_close($dup);
		} else {
			$msg = "Book is not available.";
			$msgType = "error";
		}
	}
}
	}

// ── Handle reservation request (student) ────────────────────
if (isset($_POST['reserve_book']) && $cn) {
	$bookId = (int)($_POST['book_id'] ?? 0);
	if ($userId <= 0) {
		$msg = 'Session expired. Please login again.'; $msgType = 'error';
	} else
	if ($bookId <= 0) {
		$msg = 'Invalid book.'; $msgType = 'error';
	} else {
		// Ensure reservations table exists (in case setup.sql wasn't re-imported)
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

		// Don't allow multiple active reservations for same book
		$dup = mysqli_prepare($cn, "SELECT id, status FROM tbl_reservations WHERE student_id=? AND book_id=? AND status IN ('Requested','Ready','Borrowed') LIMIT 1");
		mysqli_stmt_bind_param($dup, 'ii', $userId, $bookId);
		mysqli_stmt_execute($dup);
		$res = mysqli_stmt_get_result($dup);
		$existing = mysqli_fetch_assoc($res);
		mysqli_stmt_close($dup);

		// Also block if they already borrowed / have a pending pickup request for this same book
		$dup2 = mysqli_prepare($cn, "SELECT id FROM tbl_borrow WHERE student_id=? AND book_id=? AND status IN ('Borrowed','Pending Pickup') LIMIT 1");
		mysqli_stmt_bind_param($dup2, 'ii', $userId, $bookId);
		mysqli_stmt_execute($dup2);
		mysqli_stmt_store_result($dup2);
		$hasBorrowFlow = (mysqli_stmt_num_rows($dup2) > 0);
		mysqli_stmt_close($dup2);

		if ($existing) {
			$msg = 'You already have an active reservation for this book.'; $msgType = 'warning';
		} elseif ($hasBorrowFlow) {
			$msg = 'You already have an active/pending borrow for this book.'; $msgType = 'warning';
		} else {
			try {
				$ins = mysqli_prepare($cn, "INSERT INTO tbl_reservations (student_id, book_id, status) VALUES (?, ?, 'Requested')");
				mysqli_stmt_bind_param($ins, 'ii', $userId, $bookId);
				mysqli_stmt_execute($ins);
				$resId = mysqli_insert_id($cn);
				mysqli_stmt_close($ins);

				$msg = "Reservation submitted! Reservation #" . str_pad((string)$resId, 4, '0', STR_PAD_LEFT);
				$msgType = 'success';
			} catch (mysqli_sql_exception $e) {
				$msg = 'Unable to reserve right now. Please logout and login again.';
				$msgType = 'error';
			}
		}
	}
}

// ── Handle return request (student) ─────────────────────────
// Student requests a return; system generates OTP that admin must verify.
if (isset($_POST['return_book']) && $cn) {
	$borrowId = (int)$_POST['borrow_id'];
	$bookId   = (int)$_POST['book_id'];

	$stmt = mysqli_prepare($cn, "SELECT status, return_requested, return_otp_expires FROM tbl_borrow WHERE id=? AND student_id=? LIMIT 1");
	mysqli_stmt_bind_param($stmt, 'ii', $borrowId, $userId);
	mysqli_stmt_execute($stmt);
	$res = mysqli_stmt_get_result($stmt);
	$row = mysqli_fetch_assoc($res);
	mysqli_stmt_close($stmt);

	if (!$row || $row['status'] !== 'Borrowed') {
		$msg = "This book can't be returned right now.";
		$msgType = "error";
	} else {
		$otp = otp_generate_4digit();
		$hash = otp_hash($otp);
		$exp  = date('Y-m-d H:i:s', time() + 10 * 60);

		$u = mysqli_prepare($cn, "UPDATE tbl_borrow SET return_requested=1, return_otp_hash=?, return_otp_expires=? WHERE id=? AND student_id=?");
		mysqli_stmt_bind_param($u, 'ssii', $hash, $exp, $borrowId, $userId);
		mysqli_stmt_execute($u);
		mysqli_stmt_close($u);

		$_SESSION['last_return_otp'] = ['borrow_id' => $borrowId, 'otp' => $otp, 'expires' => $exp];
		$msg = "Return requested. Show this OTP to the librarian: $otp";
		$msgType = "success";
	}
}

// ── Cancel borrow request (student) ─────────────────────────
// Only Pending Pickup can be cancelled by the student.
if (isset($_POST['cancel_borrow']) && $cn) {
	$borrowId = (int)($_POST['borrow_id'] ?? 0);
	if ($borrowId <= 0) {
		$msg = 'Invalid transaction.'; $msgType = 'error';
	} else {
		$u = mysqli_prepare($cn, "DELETE FROM tbl_borrow WHERE id=? AND student_id=? AND status='Pending Pickup'");
		mysqli_stmt_bind_param($u, 'ii', $borrowId, $userId);
		mysqli_stmt_execute($u);
		$affected = mysqli_stmt_affected_rows($u);
		mysqli_stmt_close($u);
		if ($affected > 0) {
			$msg = 'Borrow request cancelled.'; $msgType = 'success';
		} else {
			$msg = "Can't cancel this request (maybe it's already issued)."; $msgType = 'warning';
		}
	}
}

// ── Cancel reservation (student) ────────────────────────────
// Student can cancel Requested/Ready reservations.
if (isset($_POST['cancel_reservation']) && $cn) {
	$resId = (int)($_POST['reservation_id'] ?? 0);
	if ($resId <= 0) {
		$msg = 'Invalid reservation.'; $msgType = 'error';
	} else {
		$u = mysqli_prepare($cn, "UPDATE tbl_reservations SET status='Cancelled' WHERE id=? AND student_id=? AND status IN ('Requested','Ready')");
		mysqli_stmt_bind_param($u, 'ii', $resId, $userId);
		mysqli_stmt_execute($u);
		$affected = mysqli_stmt_affected_rows($u);
		mysqli_stmt_close($u);
		if ($affected > 0) {
			$msg = 'Reservation cancelled.'; $msgType = 'success';
		} else {
			$msg = "Can't cancel this reservation."; $msgType = 'warning';
		}
	}
}

// ── Fetch books ─────────────────────────────────────────────
$search   = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');

$sql = "SELECT * FROM tbl_books WHERE 1=1";
$params = [];
$types  = '';

if ($search !== '') {
	$sql .= " AND (title LIKE ? OR author LIKE ?)";
	$like = "%$search%";
	$params[] = &$like;
	$params[] = &$like;
	$types .= 'ss';
}
if ($category !== '') {
	$sql .= " AND category = ?";
	$params[] = &$category;
	$types .= 's';
}
$sql .= " ORDER BY title ASC";

$books = [];
if ($cn) {
	$stmt = mysqli_prepare($cn, $sql);
	if ($types !== '') {
		mysqli_stmt_bind_param($stmt, $types, ...$params);
	}
	mysqli_stmt_execute($stmt);
	$result = mysqli_stmt_get_result($stmt);
	while ($row = mysqli_fetch_assoc($result)) {
		$books[] = $row;
	}
	mysqli_stmt_close($stmt);
}

// ── Fetch student's reservations ────────────────────────────
$reservations = [];
if ($cn) {
	// Auto-expire ready holds after 24 hours
	@mysqli_query($cn, "UPDATE tbl_reservations SET status='Expired' WHERE status='Ready' AND ready_expires_at IS NOT NULL AND ready_expires_at < NOW() AND student_id=" . (int)$userId);
	$stmt = mysqli_prepare($cn, "SELECT r.*, bk.title, bk.author, bk.cover_image
		FROM tbl_reservations r
		JOIN tbl_books bk ON r.book_id=bk.id
		WHERE r.student_id=?
		ORDER BY r.created_at DESC");
	mysqli_stmt_bind_param($stmt, 'i', $userId);
	mysqli_stmt_execute($stmt);
	$res = mysqli_stmt_get_result($stmt);
	while ($row = mysqli_fetch_assoc($res)) $reservations[] = $row;
	mysqli_stmt_close($stmt);
}

// ── Fetch categories for filter dropdown ────────────────────
$categories = [];
if ($cn) {
	$catResult = mysqli_query($cn, "SELECT DISTINCT category FROM tbl_books ORDER BY category");
	while ($row = mysqli_fetch_assoc($catResult)) {
		$categories[] = $row['category'];
	}
}

// ── Fetch borrowed books ────────────────────────────────────
$borrowed = [];
if ($cn) {
	$bStmt = mysqli_prepare($cn, "SELECT b.id AS borrow_id, b.book_id, b.borrow_date, b.return_date, b.status,
		b.issue_confirmed, b.issue_otp_expires, b.return_requested, b.return_otp_expires,
		bk.title, bk.author
		FROM tbl_borrow b JOIN tbl_books bk ON b.book_id = bk.id
		WHERE b.student_id = ? ORDER BY b.borrow_date DESC");
	mysqli_stmt_bind_param($bStmt, 'i', $userId);
	mysqli_stmt_execute($bStmt);
	$bResult = mysqli_stmt_get_result($bStmt);
	while ($row = mysqli_fetch_assoc($bResult)) {
		$borrowed[] = $row;
	}
	mysqli_stmt_close($bStmt);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>E-Library | Student Dashboard</title>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
	<link rel="stylesheet" href="assets/toast.css">
	<style>
		@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
		* { margin:0; padding:0; box-sizing:border-box; }
		:root{
			--brown:#3e2723;
			--brown2:#5d4037;
			--brown3:#6d4c41;
			--gold:#ffd54f;
			--gold2:#ffb300;
			--bg:#f5f0e8;
			--card:#ffffff;
			--muted:#8d6e63;
			--border:#efe6d5;
			--shadow:0 8px 28px rgba(62,39,35,.10);
			--shadow2:0 2px 12px rgba(62,39,35,.08);
			--radius:16px;
		}
		body { font-family:'Poppins',sans-serif; background:radial-gradient(1200px 700px at 10% 0%, rgba(255,213,79,.18), transparent 55%), var(--bg); color:var(--brown); min-height:100vh; }
		a{color:inherit}
		::selection{background:rgba(255,213,79,.45)}

		/* ── NAV ── */
		nav { background:linear-gradient(135deg,var(--brown),var(--brown2)); padding:0 22px; display:flex; align-items:center; justify-content:space-between; height:68px; box-shadow:0 10px 30px rgba(0,0,0,.25); position:sticky; top:0; z-index:100; border-bottom:1px solid rgba(255,255,255,.06) }
		.nav-brand { display:flex; align-items:center; gap:10px; text-decoration:none; }
		.nav-brand img { width:28px; height:28px; object-fit:contain; filter:drop-shadow(0 4px 12px rgba(255,213,79,.2)); }
		.nav-brand span { font-family:'Poppins',sans-serif; font-size:18px; font-weight:800; color:#fff; letter-spacing:.2px }
		.nav-right { display:flex; align-items:center; gap:12px; }
		.nav-right .greeting { color:rgba(255,255,255,.85); font-size:12px; white-space:nowrap }
		.nav-right .greeting strong { color:#ffd54f; }
		.nav-right a { color:rgba(255,255,255,.86); text-decoration:none; font-size:12px; padding:8px 12px; border-radius:12px; transition:.25s; display:flex; align-items:center; gap:8px; border:1px solid rgba(255,255,255,.10) }
		.nav-right a:hover { background:rgba(255,255,255,.08); border-color:rgba(255,213,79,.35); color:var(--gold); transform:translateY(-1px) }
		.nav-right a.btn-logout { border:1px solid rgba(255,213,79,.35); color:var(--gold); background:rgba(255,213,79,.08) }
		.nav-toggle{display:none;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);color:#fff;border-radius:12px;padding:10px 12px;font-size:14px;cursor:pointer}
		.nav-menu{display:flex;align-items:center;gap:12px}

		/* ── CONTAINER ── */
		.container { max-width:1750px; margin:22px auto; padding:0 18px; }
		.hero{background:linear-gradient(125deg, rgba(255,213,79,.20), rgba(255,179,0,.10)), var(--card); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow2); padding:16px 18px; display:flex; align-items:flex-start; justify-content:space-between; gap:14px; margin-bottom:14px}
		.hero h1{font-size:16px;font-weight:800;color:var(--brown);line-height:1.2}
		.hero p{margin-top:4px;color:var(--muted);font-size:12px;line-height:1.35}
		.pills{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
		.pill{display:inline-flex;align-items:center;gap:8px;padding:8px 10px;border-radius:999px;border:1px solid rgba(62,39,35,.12);background:rgba(255,255,255,.8);font-size:12px;color:var(--brown2);font-weight:700}
		.pill i{color:var(--brown3)}

		/* ── TABS ── */
		.tabs { display:flex; gap:10px; margin:14px 0 14px; flex-wrap:wrap }
		.tab { padding:10px 14px; border-radius:14px; cursor:pointer; font-weight:700; font-size:13px; background:rgba(255,255,255,.7); color:var(--brown2); border:1px solid var(--border); transition:.2s; font-family:'Poppins',sans-serif; display:inline-flex; align-items:center; gap:8px }
		.tab:hover{transform:translateY(-1px); box-shadow:0 10px 22px rgba(62,39,35,.08)}
		.tab.active { background:linear-gradient(135deg, rgba(255,213,79,.85), rgba(255,179,0,.85)); color:var(--brown); border-color:rgba(255,179,0,.45) }

		.panel { display:none; background:var(--card); border-radius:var(--radius); padding:18px; box-shadow:var(--shadow); border:1px solid var(--border) }
		.panel.active { display:block; }

		/* ── SEARCH ── */
		.search-bar { display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
		.search-bar input, .search-bar select { padding:11px 14px; border:2px solid var(--border); border-radius:14px; font-size:13px; font-family:'Poppins',sans-serif; outline:none; transition:.25s; background:#faf8f5; color:var(--brown) }
		.search-bar input:focus, .search-bar select:focus { border-color:var(--brown3); box-shadow:0 0 0 3px rgba(109,76,65,.12); background:#fff }
		.search-bar input { flex:1; min-width:180px; }
		.search-bar button { padding:11px 16px; background:linear-gradient(135deg,var(--brown2),var(--brown)); color:var(--gold); border:none; border-radius:14px; font-weight:700; cursor:pointer; font-family:'Poppins',sans-serif; transition:.25s; display:inline-flex; align-items:center; gap:8px }
		.search-bar button:hover { transform:translateY(-1px); box-shadow:0 10px 24px rgba(0,0,0,.18); }

		/* ── BOOK GRID ── */
		.book-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:14px; }
		.book-card { border:1px solid var(--border); border-radius:var(--radius); padding:16px; transition:.25s; background:linear-gradient(180deg, rgba(255,255,255,.98), rgba(255,255,255,.92)); box-shadow:0 2px 12px rgba(0,0,0,.04) }
		.book-card:hover { transform:translateY(-3px); box-shadow:var(--shadow); }
		.book-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px}
		.book-card .cat-badge { display:inline-flex; align-items:center; gap:6px; background:rgba(255,213,79,.23); color:var(--brown2); padding:4px 10px; border-radius:999px; font-size:11px; font-weight:800; border:1px solid rgba(255,179,0,.30) }
		.book-card h3 { font-family:'Poppins',sans-serif; font-size:16px; color:var(--brown); margin-bottom:6px; font-weight:800; line-height:1.2 }
		.book-card .author { color:var(--muted); font-size:12px; margin-bottom:8px; }
		.book-card .desc { color:var(--muted); font-size:12px; line-height:1.5; margin-bottom:12px; display:-webkit-box; -webkit-line-clamp:2; line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
		.book-card .meta { display:flex; justify-content:space-between; align-items:center; gap:10px; }
		.book-card .qty { font-size:12px; color:var(--brown2); font-weight:800; }
		.book-card .qty.out { color:#c62828; }
		.btn-borrow { padding:8px 14px; background:linear-gradient(135deg,var(--gold),var(--gold2)); color:var(--brown); border:none; border-radius:12px; font-size:12px; font-weight:800; cursor:pointer; transition:.25s; font-family:'Poppins',sans-serif; display:inline-flex; align-items:center; gap:8px; box-shadow:0 6px 16px rgba(255,179,0,.18) }
		.btn-borrow:hover { transform:translateY(-1px); box-shadow:0 10px 20px rgba(255,179,0,.25); }
		.btn-borrow:disabled { opacity:.5; cursor:not-allowed; transform:none; box-shadow:none; }
		.btn-borrow:disabled{filter:grayscale(.1)}

		/* ── TABLE ── */
		.tbl-wrap{overflow:auto}
		.tbl { width:100%; border-collapse:collapse; font-size:13px; min-width:760px }
		.tbl th { background:rgba(245,240,232,.95); color:var(--brown2); padding:12px 14px; text-align:left; font-weight:800; font-size:11px; text-transform:uppercase; letter-spacing:.6px }
		.tbl td { padding:12px 14px; border-bottom:1px solid var(--border); vertical-align:top }
		.tbl tr:last-child td { border-bottom:none; }
		.status-badge { padding:6px 10px; border-radius:999px; font-size:11px; font-weight:900; display:inline-flex; align-items:center; gap:6px; border:1px solid transparent }
		.status-badge.borrowed { background:rgba(255,213,79,.23); color:var(--brown2); border-color:rgba(255,179,0,.30) }
		.status-badge.returned { background:#e8f5e9; color:#2e7d32; border-color:#c8e6c9 }
		.status-badge.pending\ pickup, .status-badge.pending { background:#e3f2fd; color:#1565c0; border-color:#bbdefb }
		.btn-return { padding:8px 12px; background:#e8f5e9; color:#2e7d32; border:1px solid #c8e6c9; border-radius:12px; font-size:12px; font-weight:900; cursor:pointer; transition:.25s; font-family:'Poppins',sans-serif; display:inline-flex; align-items:center; gap:8px }
		.btn-return:hover { background:#c8e6c9; transform:translateY(-1px) }
		.otp-pill{display:inline-flex;align-items:center;gap:8px;background:#fff3e0;border:1px solid #ffcc80;color:#e65100;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700}
		.otp-pill strong{letter-spacing:2px}
		.small-muted{color:#8d6e63;font-size:12px;line-height:1.35}
		.action-stack{display:flex;flex-direction:column;gap:8px}
		.kv{display:flex;flex-direction:column;gap:4px}
		.kv .k{font-size:10px;letter-spacing:.7px;color:var(--muted);font-weight:900;text-transform:uppercase}
		.kv .v{font-size:13px;color:var(--brown);font-weight:800}
		.mobile-cards{display:none;flex-direction:column;gap:12px}
		.mcard{border:1px solid var(--border);border-radius:var(--radius);background:var(--card);box-shadow:var(--shadow2);padding:14px}
		.mcard-top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
		.mcard-title{font-weight:900;color:var(--brown);line-height:1.2}
		.mcard-sub{margin-top:6px;color:var(--muted);font-size:12px}
		.mcard-grid{margin-top:12px;display:grid;grid-template-columns:1fr 1fr;gap:10px}

		/* ── ALERT ── */
		.alert { padding:12px 14px; border-radius:14px; font-size:13px; font-weight:600; margin-bottom:14px; border:1px solid transparent }
		.alert.success { background:#e8f5e9; color:#2e7d32; border:1px solid #a5d6a7; }
		.alert.warning { background:#fff3e0; color:#e65100; border:1px solid #ffcc80; }
		.alert.error   { background:#ffebee; color:#c62828; border:1px solid #ef9a9a; }

		.empty { text-align:center; padding:40px; color:#8d6e63; }
		.empty i { font-size:40px; margin-bottom:12px; display:block; opacity:.4; }

		@media(max-width:860px){
			.tbl{min-width:720px}
		}
		@media(max-width:768px) {
			nav { padding:0 14px; height:66px }
			.nav-right .greeting{display:none}
			.nav-toggle{display:inline-flex}
			.nav-menu{position:absolute;top:66px;left:0;right:0;background:linear-gradient(180deg,var(--brown2),var(--brown));border-top:1px solid rgba(255,255,255,.08);padding:12px 14px;display:none;flex-direction:column;align-items:stretch;gap:10px}
			.nav-menu.open{display:flex}
			.nav-menu a{justify-content:center}
			.container { padding:0 12px; }
			.hero{flex-direction:column;align-items:stretch}
			.pills{justify-content:flex-start}
			.book-grid { grid-template-columns:1fr; }
			.panel{padding:14px}
			/* Borrowed list becomes cards on mobile */
			.tbl-wrap{display:none}
			.mobile-cards{display:flex}
			.mcard-grid{grid-template-columns:1fr}
		}
	</style>
</head>
<body>

<nav>
	<a href="student_dashboard.php" class="nav-brand">
		<img src="Images/Icon.png" alt="Icon">
		<span>E-Library</span>
	</a>
	<div class="nav-right">
		<span class="greeting">Hello, <strong><?= htmlspecialchars($acctName) ?></strong></span>
		<button class="nav-toggle" type="button" onclick="toggleNav()"><i class="fas fa-bars"></i></button>
		<div class="nav-menu" id="navMenu">
			<a href="#" onclick="switchTab('browse'); return false;"><i class="fas fa-book"></i> Browse</a>
			<a href="#" onclick="switchTab('mybooks'); return false;"><i class="fas fa-bookmark"></i> My Books</a>
			<a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
		</div>
	</div>
</nav>

<div class="container">
	<div class="hero">
		<div>
			<h1>Student Dashboard</h1>
			<p>Borrow requests use OTP verification. Request a book, then confirm pickup with the librarian using a 4-digit code.</p>
		</div>
		<div class="pills">
			<div class="pill"><i class="fas fa-user"></i> <?= htmlspecialchars($acctName) ?></div>
			<div class="pill"><i class="fas fa-shield-halved"></i> Verified Access</div>
		</div>
	</div>

	<?php if (!empty($msg)): ?>
		<div class="alert <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
	<?php endif; ?>

	<!-- Tabs -->
	<div class="tabs">
		<button class="tab active" onclick="switchTab('browse')"><i class="fas fa-search"></i>&nbsp; Browse Books</button>
		<button class="tab" onclick="switchTab('mybooks')"><i class="fas fa-bookmark"></i>&nbsp; My Borrowed Books</button>
	</div>

	<!-- ── BROWSE PANEL ── -->
	<div class="panel active" id="browse">
		<form class="search-bar" method="get" action="student_dashboard.php">
			<input type="text" name="search" placeholder="Search by title or author..." value="<?= htmlspecialchars($search) ?>">
			<select name="category">
				<option value="">All Categories</option>
				<?php foreach ($categories as $cat): ?>
					<option value="<?= htmlspecialchars($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit"><i class="fas fa-search"></i> Search</button>
		</form>

		<?php if (empty($books)): ?>
			<div class="empty">
				<i class="fas fa-book-open"></i>
				No books found. Try a different search.
			</div>
		<?php else: ?>
			<div class="book-grid">
				<?php foreach ($books as $book): ?>
					<div class="book-card">
						<div class="book-card-top">
							<span class="cat-badge"><i class="fas fa-tags"></i> <?= htmlspecialchars($book['category']) ?></span>
							<span class="small-muted" style="font-weight:800">#<?= (int)$book['id'] ?></span>
						</div>
						<h3><?= htmlspecialchars($book['title']) ?></h3>
						<p class="author"><i class="fas fa-pen-nib"></i> <?= htmlspecialchars($book['author']) ?></p>
						<p class="desc"><?= htmlspecialchars($book['description'] ?? '') ?></p>
						<div class="meta">
							<span class="qty <?= $book['quantity'] < 1 ? 'out' : '' ?>">
								<?= $book['quantity'] > 0 ? $book['quantity'] . ' available' : 'Out of stock' ?>
							</span>
							<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">
								<form method="post" style="display:inline">
									<input type="hidden" name="book_id" value="<?= $book['id'] ?>">
									<button type="submit" name="borrow_book" class="btn-borrow" <?= $book['quantity'] < 1 ? 'disabled' : '' ?>>
										<i class="fas fa-hand-holding"></i> Borrow
									</button>
								</form>
								<form method="post" style="display:inline">
									<input type="hidden" name="book_id" value="<?= $book['id'] ?>">
									<button type="submit" name="reserve_book" class="btn-borrow" style="background:rgba(255,213,79,.18);border-color:rgba(255,179,0,.35);color:var(--brown2)">
										<i class="fas fa-bookmark"></i> Reserve
									</button>
								</form>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

	<!-- ── MY BOOKS PANEL ── -->
	<div class="panel" id="mybooks">
		<?php if (!empty($reservations)): ?>
			<div class="section" id="reservations" style="margin-bottom:18px">
				<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px">
					<div>
						<div class="small-muted" style="letter-spacing:.12em;text-transform:uppercase">Reservations</div>
						<h2 style="margin:0;font-family:'Poppins',sans-serif;color:var(--brown);font-size:18px">Your reservation holds</h2>
					</div>
					<div class="pill"><i class="fas fa-bell"></i> Ready books expire in 24 hours</div>
				</div>

				<div class="tbl-wrap">
				<table class="tbl">
					<thead>
						<tr>
							<th>Reservation</th>
							<th>Book</th>
							<th>Status</th>
							<th>Ready Until</th>
							<th>Confirm Pickup</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($reservations as $r): ?>
							<?php
								$isReady = (($r['status'] ?? '') === 'Ready');
								$readyExp = !empty($r['ready_expires_at']) ? strtotime($r['ready_expires_at']) : null;
								$hrsLeft = $readyExp ? floor(($readyExp - time())/3600) : null;
							?>
							<tr>
								<td><span class="status-badge" style="border-color:rgba(0,0,0,.08)"><i class="fas fa-bookmark"></i> #<?= str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT) ?></span></td>
								<td>
									<div style="font-weight:800;color:var(--brown)"><?= htmlspecialchars($r['title'] ?? '') ?></div>
									<div class="small-muted"><i class="fas fa-pen-nib"></i> <?= htmlspecialchars($r['author'] ?? '') ?></div>
								</td>
								<td>
									<?php if (($r['status'] ?? '') === 'Ready'): ?>
											<div class="otp-pill" style="background:rgba(76,175,80,.08);border-color:rgba(76,175,80,.22);color:#1b5e20"><i class="fas fa-bell"></i> Ready for pickup — you can pick it up anytime.</div>
										<?php elseif (($r['status'] ?? '') === 'Borrowed'): ?>
											<div class="otp-pill" style="background:rgba(21,101,192,.08);border-color:rgba(21,101,192,.20);color:#0d47a1"><i class="fas fa-book"></i> Picked up / Borrowed</div>
									<?php elseif (($r['status'] ?? '') === 'Requested'): ?>
										<div class="otp-pill" style="background:rgba(255,213,79,.16);border-color:rgba(255,179,0,.28);color:var(--brown2)"><i class="fas fa-hourglass-half"></i> Requested</div>
									<?php elseif (($r['status'] ?? '') === 'Expired'): ?>
										<div class="otp-pill" style="background:rgba(198,40,40,.08);border-color:rgba(198,40,40,.20);color:#b71c1c"><i class="fas fa-times-circle"></i> Expired</div>
									<?php else: ?>
										<div class="otp-pill"><i class="fas fa-info-circle"></i> <?= htmlspecialchars($r['status'] ?? '') ?></div>
									<?php endif; ?>
								</td>
								<td>
									<?php if (!empty($r['ready_expires_at'])): ?>
										<div><?= date('M d, Y h:i A', strtotime($r['ready_expires_at'])) ?></div>
										<?php if ($isReady && $hrsLeft !== null): ?><div class="small-muted" style="margin-top:4px"><?= max(0,$hrsLeft) ?> hour(s) left</div><?php endif; ?>
									<?php else: ?>
										<span class="small-muted">—</span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ($isReady): ?>
										<form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
											<input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
											<button type="submit" name="pickup_reservation" class="btn-return" style="background:#1b5e20" data-confirm="Pick up this reserved book now? This will create a borrow record." data-confirm-title="Pick up reservation" data-confirm-ok="Pick up" data-confirm-cancel="Not now"><i class="fas fa-check"></i> Pick Up Now</button>
										</form>
										<form method="post" style="margin-top:10px" data-confirm="Cancel this reservation?" data-confirm-title="Cancel reservation" data-confirm-ok="Cancel" data-confirm-cancel="Keep" data-confirm-danger="1">
											<input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
											<button type="submit" name="cancel_reservation" class="btn-return" style="background:#8d6e63"><i class="fas fa-times"></i> Cancel Reservation</button>
										</form>
									<?php else: ?>
										<?php if (($r['status'] ?? '') === 'Requested'): ?>
											<form method="post" data-confirm="Cancel this reservation request?" data-confirm-title="Cancel request" data-confirm-ok="Cancel" data-confirm-cancel="Keep" data-confirm-danger="1">
												<input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
												<button type="submit" name="cancel_reservation" class="btn-return" style="background:#8d6e63"><i class="fas fa-times"></i> Cancel Reservation</button>
											</form>
										<?php else: ?>
											<span class="small-muted">—</span>
										<?php endif; ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			</div>
		<?php endif; ?>

		<?php if (empty($borrowed)): ?>
			<div class="empty">
				<i class="fas fa-bookmark"></i>
				You haven't borrowed any books yet.
			</div>
		<?php else: ?>
			<div class="tbl-wrap">
			<table class="tbl">
				<thead>
					<tr>
						<th>Book Title</th>
						<th>Author</th>
						<th>Borrowed</th>
						<th>Returned</th>
						<th>Status</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($borrowed as $b): ?>
						<tr>
							<td><?= htmlspecialchars($b['title']) ?></td>
							<td><?= htmlspecialchars($b['author']) ?></td>
							<td><?= date('M d, Y', strtotime($b['borrow_date'])) ?></td>
							<td><?= $b['return_date'] ? date('M d, Y', strtotime($b['return_date'])) : '—' ?></td>
							<td>
								<?php
									$badgeClass = strtolower(str_replace(' ', '-', $b['status']));
									$badgeIcon = ($b['status'] === 'Borrowed') ? 'fa-hand-holding' : (($b['status'] === 'Returned') ? 'fa-check-circle' : 'fa-hourglass-half');
								?>
								<span class="status-badge <?= $badgeClass ?>"><i class="fas <?= $badgeIcon ?>"></i> <?= htmlspecialchars($b['status']) ?></span>
							</td>
							<td>
								<div class="action-stack">
								<?php if ($b['status'] === 'Pending Pickup'): ?>
									<div class="small-muted"><strong>Pending pickup:</strong> please go to the librarian to generate/confirm your OTP.</div>
									<form method="post" style="margin-top:10px" data-confirm="Cancel this borrow request?" data-confirm-title="Cancel borrow" data-confirm-ok="Cancel" data-confirm-cancel="Keep" data-confirm-danger="1">
										<input type="hidden" name="borrow_id" value="<?= (int)$b['borrow_id'] ?>">
										<button type="submit" name="cancel_borrow" class="btn-return" style="background:#8d6e63"><i class="fas fa-times"></i> Cancel Request</button>
									</form>
									<?php if (!empty($_SESSION['last_issue_otp']) && (int)($_SESSION['last_issue_otp']['borrow_id'] ?? 0) === (int)$b['borrow_id']): ?>
										<div style="margin-top:8px" class="otp-pill"><i class="fas fa-key"></i> OTP: <strong><?= htmlspecialchars($_SESSION['last_issue_otp']['otp']) ?></strong></div>
										<div class="small-muted" style="margin-top:6px">Expires: <?= date('M d, Y h:i A', strtotime($_SESSION['last_issue_otp']['expires'])) ?></div>
									<?php endif; ?>
								<?php elseif ($b['status'] === 'Borrowed'): ?>
									<form method="post" style="display:inline">
										<input type="hidden" name="borrow_id" value="<?= $b['borrow_id'] ?>">
										<input type="hidden" name="book_id" value="<?= $b['book_id'] ?>">
										<button type="submit" name="return_book" class="btn-return"><i class="fas fa-undo"></i> Request Return</button>
									</form>
									<?php if (!empty($_SESSION['last_return_otp']) && (int)($_SESSION['last_return_otp']['borrow_id'] ?? 0) === (int)$b['borrow_id']): ?>
										<div style="margin-top:10px" class="otp-pill"><i class="fas fa-key"></i> Return OTP: <strong><?= htmlspecialchars($_SESSION['last_return_otp']['otp']) ?></strong></div>
										<div class="small-muted" style="margin-top:6px">Expires: <?= date('M d, Y h:i A', strtotime($_SESSION['last_return_otp']['expires'])) ?></div>
									<?php elseif ((int)($b['return_requested'] ?? 0) === 1): ?>
										<div class="small-muted" style="margin-top:8px">Return requested. Show your OTP to the librarian to complete it.</div>
									<?php endif; ?>
								<?php else: ?>
									<span style="color:#8d6e63; font-size:12px;">Done</span>
								<?php endif; ?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>

			<!-- Mobile cards -->
			<div class="mobile-cards">
				<?php foreach ($borrowed as $b): ?>
					<?php
						$badgeClass = strtolower(str_replace(' ', '-', $b['status']));
						$badgeIcon = ($b['status'] === 'Borrowed') ? 'fa-hand-holding' : (($b['status'] === 'Returned') ? 'fa-check-circle' : 'fa-hourglass-half');
					?>
					<div class="mcard">
						<div class="mcard-top">
							<div>
								<div class="mcard-title"><?= htmlspecialchars($b['title']) ?></div>
								<div class="mcard-sub"><i class="fas fa-pen-nib"></i> <?= htmlspecialchars($b['author']) ?></div>
							</div>
							<span class="status-badge <?= $badgeClass ?>"><i class="fas <?= $badgeIcon ?>"></i> <?= htmlspecialchars($b['status']) ?></span>
						</div>
						<div class="mcard-grid">
							<div class="kv"><div class="k">Borrowed</div><div class="v"><?= date('M d, Y', strtotime($b['borrow_date'])) ?></div></div>
							<div class="kv"><div class="k">Returned</div><div class="v"><?= $b['return_date'] ? date('M d, Y', strtotime($b['return_date'])) : '—' ?></div></div>
						</div>
						<div style="margin-top:12px" class="action-stack">
							<?php if ($b['status'] === 'Pending Pickup'): ?>
								<div class="small-muted"><strong>Pending pickup:</strong> go to the librarian to generate/confirm your OTP.</div>
								<form method="post" style="margin-top:10px" data-confirm="Cancel this borrow request?" data-confirm-title="Cancel borrow" data-confirm-ok="Cancel" data-confirm-cancel="Keep" data-confirm-danger="1">
									<input type="hidden" name="borrow_id" value="<?= (int)$b['borrow_id'] ?>">
									<button type="submit" name="cancel_borrow" class="btn-return" style="width:100%;justify-content:center;background:#8d6e63"><i class="fas fa-times"></i> Cancel Request</button>
								</form>
								<?php if (!empty($_SESSION['last_issue_otp']) && (int)($_SESSION['last_issue_otp']['borrow_id'] ?? 0) === (int)$b['borrow_id']): ?>
									<div class="otp-pill"><i class="fas fa-key"></i> OTP: <strong><?= htmlspecialchars($_SESSION['last_issue_otp']['otp']) ?></strong></div>
									<div class="small-muted">Expires: <?= date('M d, Y h:i A', strtotime($_SESSION['last_issue_otp']['expires'])) ?></div>
								<?php endif; ?>
							<?php elseif ($b['status'] === 'Borrowed'): ?>
								<form method="post" style="display:inline">
									<input type="hidden" name="borrow_id" value="<?= $b['borrow_id'] ?>">
									<input type="hidden" name="book_id" value="<?= $b['book_id'] ?>">
									<button type="submit" name="return_book" class="btn-return" style="width:100%;justify-content:center"><i class="fas fa-undo"></i> Request Return</button>
								</form>
								<?php if (!empty($_SESSION['last_return_otp']) && (int)($_SESSION['last_return_otp']['borrow_id'] ?? 0) === (int)$b['borrow_id']): ?>
									<div class="otp-pill"><i class="fas fa-key"></i> Return OTP: <strong><?= htmlspecialchars($_SESSION['last_return_otp']['otp']) ?></strong></div>
									<div class="small-muted">Expires: <?= date('M d, Y h:i A', strtotime($_SESSION['last_return_otp']['expires'])) ?></div>
								<?php elseif ((int)($b['return_requested'] ?? 0) === 1): ?>
									<div class="small-muted">Return requested. Show your OTP to the librarian to complete it.</div>
								<?php endif; ?>
							<?php else: ?>
								<span class="small-muted">Done</span>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

</div>

	<script src="assets/toast.js"></script>
	<script>
function switchTab(name) {
	document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
	document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
	document.getElementById(name).classList.add('active');
	if (event && event.currentTarget) event.currentTarget.classList.add('active');
	// Close mobile nav if open
	const menu = document.getElementById('navMenu');
	if (menu) menu.classList.remove('open');
}

function toggleNav(){
	const menu = document.getElementById('navMenu');
	if (!menu) return;
	menu.classList.toggle('open');
}
</script>

</body>
</html>
