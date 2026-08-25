<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

ensure_users_table();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('login.php?tab=student');
}

try {
    require_valid_csrf_token();
} catch (RuntimeException $e) {
    flash($e->getMessage(), 'error');
    redirect('login.php?tab=student');
}

$citizenId = normalize_skr_citizen_id((string) post('citizen_id'));

if (!preg_match('/^\d{13}$/', $citizenId)) {
    flash('กรุณากรอกเลขบัตรประชาชน 13 หลักให้ถูกต้อง', 'error');
    redirect('login.php?tab=student');
}

// เรียก API
try {
    $apiStudent = lookup_skr_student_by_citizen_id($citizenId);
} catch (RuntimeException $e) {
    error_log('Student login API error: ' . $e->getMessage());
    flash('ขณะนี้ไม่สามารถเชื่อมต่อระบบข้อมูลนักศึกษา ศกร. ได้ กรุณาลองใหม่ภายหลัง', 'error');
    redirect('login.php?tab=student');
}

if (!$apiStudent) {
    flash('ไม่พบข้อมูลนักศึกษาที่ตรงกับเลขบัตรประชาชนนี้ กรุณาตรวจสอบอีกครั้งหรือติดต่อครูผู้สอน', 'error');
    redirect('login.php?tab=student');
}

// ตรวจสอบรหัสเต็ม 13 ตัวจาก API แบบ server-to-server
$apiCitizenId = normalize_skr_citizen_id((string) ($apiStudent['citizen_id'] ?? ''));

if (!preg_match('/^[A-Z0-9]{13}$/', $apiCitizenId) || !hash_equals($apiCitizenId, $citizenId)) {
    flash('เลขประจำตัวประชาชนหรือรหัสบัตรไม่ตรงกับข้อมูลในระบบ กรุณาตรวจสอบอีกครั้ง', 'error');
    redirect('login.php?tab=student');
}

// เพิ่มข้อมูล citizen_id เต็มเพื่อ mask ในฐานข้อมูล
$apiStudent['citizen_id'] = $citizenId;

try {
    $user = upsert_skr_user($apiStudent);
    login_user($user);
    flash('เข้าสู่ระบบสำเร็จ ยินดีต้อนรับ ' . $user['display_name']);
    redirect('../index.php');
} catch (Throwable $e) {
    flash('เกิดข้อผิดพลาดในการเข้าสู่ระบบ กรุณาลองใหม่อีกครั้ง', 'error');
    redirect('login.php?tab=student');
}
