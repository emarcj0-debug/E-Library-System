<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_role('student');

$msg = 'Pickup confirmation via desk QR is no longer used. Please go to Student Dashboard → My Books → Reservations and click “Pick Up Now”.';
echo "<script>alert(" . json_encode($msg) . "); window.location.href='student_dashboard.php#reservations';</script>";
exit;
