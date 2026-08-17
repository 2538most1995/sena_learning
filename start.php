<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$loggedInUser = require_user();
$courseId = (int) post('course_id');
$learnerName = trim((string) $loggedInUser['display_name']);

if ($courseId <= 0 || $learnerName === '') {
    flash('กรุณาเลือกหลักสูตรและตรวจสอบชื่อในบัญชีผู้ใช้', 'error');
    redirect('index.php');
}

$course = fetch_course($courseId);
if (!$course) {
    flash('ไม่พบหลักสูตรที่เลือก', 'error');
    redirect('index.php');
}

try {
    $attempt = get_or_create_attempt($courseId, (int) $loggedInUser['id'], $learnerName);
} catch (RuntimeException $e) {
    flash($e->getMessage(), 'error');
    redirect('index.php');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['last_attempt_id']    = (int) $attempt['id'];
$_SESSION['last_attempt_token'] = (string) $attempt['access_token'];
redirect(attempt_url('lesson.php', $attempt));
