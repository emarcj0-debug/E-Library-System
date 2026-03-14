<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/toast_helper.php';
require_role('student');

$msg = 'Pickup confirmation via desk QR is no longer used. Please go to Student Dashboard → My Books → Reservations and click “Pick Up Now”.';
toast_and_redirect($msg, 'info', 'student_dashboard.php#reservations', 4000);
