<?php
require_once __DIR__ . '/mail_config.php';

/**
 * Loads Composer autoload if present.
 */
function require_composer_autoload(): void
{
	$autoload = __DIR__ . '/vendor/autoload.php';
	if (!file_exists($autoload)) {
		throw new RuntimeException('Composer vendor/autoload.php not found. Run composer install (dependencies are required for email verification).');
	}
	require_once $autoload;
}

/**
 * Sends a verification email.
 *
 * @throws RuntimeException on configuration or send errors.
 */
function send_verification_email(string $toEmail, string $toName, string $verifyLink): void
{
	$config = require __DIR__ . '/mail_config.php';
	require_composer_autoload();

	if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
		throw new RuntimeException('PHPMailer is not available. Make sure phpmailer/phpmailer is installed.');
	}

	$mail = new PHPMailer\PHPMailer\PHPMailer(true);

	$smtp = $config['smtp'] ?? [];
	$host = $smtp['host'] ?? '';
	$port = (int)($smtp['port'] ?? 0);
	$user = $smtp['username'] ?? '';
	$pass = $smtp['password'] ?? '';
	$enc  = $smtp['encryption'] ?? 'tls';
	$fromEmail = $smtp['from_email'] ?? $user;
	$fromName  = $smtp['from_name'] ?? 'E-Library';
	$baseUrl = rtrim(($config['app']['base_url'] ?? ''), '/');

	if ($host === '' || $port === 0 || $user === '' || $pass === '' || $fromEmail === '') {
		throw new RuntimeException('SMTP is not configured. Update mail_config.php.');
	}

	$mail->isSMTP();
	$mail->Host = $host;
	$mail->SMTPAuth = true;
	$mail->Username = $user;
	$mail->Password = $pass;

	if ($enc === 'ssl') {
		$mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
	} else {
		$mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
	}
	$mail->Port = $port;

	$mail->setFrom($fromEmail, $fromName);
	$mail->addAddress($toEmail, $toName);

	$mail->isHTML(true);
	$mail->Subject = 'Verify your E-Library email address';

	$brand = htmlspecialchars($fromName, ENT_QUOTES);
	$toNameSafe = htmlspecialchars($toName, ENT_QUOTES);
	$linkSafe = htmlspecialchars($verifyLink, ENT_QUOTES);

	$mail->Body =
		'<!doctype html>'
		. '<html><body style="margin:0;padding:0;background:#f3f4f6;">'
		. '<div style="padding:26px 12px;">'
		. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;margin:0 auto;border-radius:18px;overflow:hidden;box-shadow:0 14px 40px rgba(17,24,39,0.18);">'
		. '<tr><td style="padding:0;">'
		. '<div style="background:linear-gradient(135deg,#5d4037 0%,#3e2723 100%);padding:22px 26px;font-family:Arial,sans-serif;">'
		. '<div style="display:inline-block;padding:6px 10px;border-radius:999px;background:rgba(255,213,79,0.16);color:#ffd54f;font-size:12px;font-weight:700;letter-spacing:1px;">' . $brand . '</div>'
		. '<div style="margin-top:10px;color:#ffffff;font-size:22px;font-weight:800;">Verify your email</div>'
		. '<div style="margin-top:6px;color:#ffffffcc;font-size:13px;line-height:1.5;">One last step to activate your account.</div>'
		. '</div>'
		. '<div style="background:#ffffff;padding:24px 26px;font-family:Arial,sans-serif;">'
		. '<p style="margin:0 0 10px;color:#111827;font-size:14px;line-height:1.55;">Hi ' . $toNameSafe . ',</p>'
		. '<p style="margin:0 0 16px;color:#374151;font-size:14px;line-height:1.65;">Thanks for registering. Please confirm this is your email address by clicking the button below.</p>'
		. '<p style="margin:0 0 18px;">'
		. '<a href="' . $linkSafe . '" style="display:inline-block;padding:12px 18px;background:#5d4037;color:#ffd54f;text-decoration:none;border-radius:12px;font-weight:800;">Verify Email</a>'
		. '</p>'
		. '<div style="border:1px solid #e5e7eb;border-radius:14px;padding:14px 14px;background:#f9fafb;">'
		. '<div style="font-size:12px;color:#6b7280;font-weight:700;margin-bottom:6px;">If the button doesn\'t work, copy this link:</div>'
		. '<div style="font-size:12px;word-break:break-all;line-height:1.5;">'
		. '<a href="' . $linkSafe . '" style="color:#111827;text-decoration:underline;">' . $linkSafe . '</a>'
		. '</div>'
		. '</div>'
		. '<p style="margin:16px 0 0;color:#6b7280;font-size:12px;line-height:1.5;">This link expires in 1 hour. If you didn\'t create this account, you can ignore this email.</p>'
		. '</div>'
		. '<div style="background:#ffffff;padding:14px 26px;border-top:1px solid #f1f5f9;font-family:Arial,sans-serif;color:#9ca3af;font-size:11px;text-align:center;">© ' . date('Y') . ' ' . $brand . '</div>'
		. '</td></tr>'
		. '</table>'
		. '</div>'
		. '</body></html>';

	$mail->AltBody = "Verify your email: $verifyLink";

	try {
		$mail->send();
	} catch (Throwable $e) {
		$extra = '';
		if (property_exists($mail, 'ErrorInfo') && $mail->ErrorInfo) {
			$extra = ' SMTP Error: ' . $mail->ErrorInfo;
		}
		throw new RuntimeException('Mailer failed to send.' . $extra, 0, $e);
	}
}
