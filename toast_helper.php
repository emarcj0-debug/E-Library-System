<?php
/**
 * toast_helper.php
 *
 * Server-side helper for showing client-side toasts (instead of alert())
 * while still supporting redirects.
 *
 * Usage:
 *   require_once __DIR__ . '/toast_helper.php';
 *   toast_and_redirect('Saved!', 'success', 'student_dashboard.php');
 */

function toast_script_include(): string {
	return "<link rel=\"stylesheet\" href=\"assets/toast.css\">\n" .
		"<script src=\"assets/toast.js\"></script>\n";
}

/**
 * Echoes a minimal HTML page that shows a toast message and then optionally redirects.
 *
 * @param string $message
 * @param string $type success|error|warning|info
 * @param string|null $redirectUrl
 * @param int $durationMs
 */
function toast_and_redirect(string $message, string $type = 'info', ?string $redirectUrl = null, int $durationMs = 2500): void {
	$type = in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info';
	$payload = [
		'message' => $message,
		'type' => $type,
		'duration' => $durationMs,
		'redirect' => $redirectUrl,
	];

	header('Content-Type: text/html; charset=UTF-8');
	echo "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"UTF-8\">";
	echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">";
	echo "<title>Notification</title>";
	echo toast_script_include();
	echo "</head><body style=\"margin:0; font-family: 'Poppins', Arial, sans-serif; background:#f5f0e8;\">";
	echo "<script>";
	echo "(function(){var p=" . json_encode($payload) . ";";
	echo "if(window.ELibraryToast&&window.ELibraryToast.toast){window.ELibraryToast.toast(p.message,{type:p.type,duration:p.duration,redirect:p.redirect});}";
	echo "else {";
	echo "alert(p.message); if(p.redirect){window.location.href=p.redirect;}";
	echo "}";
	echo "})();";
	echo "</script>";
	echo "</body></html>";
	exit;
}
