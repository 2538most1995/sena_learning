<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

function api_send(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(string $code, string $message, int $statusCode): never
{
    api_send([
        'success' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ], $statusCode);
}

function api_truthy_param(string $name): bool
{
    $value = mb_strtolower(trim((string) ($_GET[$name] ?? '')), 'UTF-8');
    return in_array($value, ['1', 'true', 'yes', 'y'], true);
}

function api_attempt_status_label(?array $attempt): string
{
    if (!$attempt) {
        return 'ยังไม่ได้เริ่ม';
    }

    return match ((string) $attempt['status']) {
        'passed' => 'ผ่านแล้ว',
        'posttest_done' => 'ยังไม่ผ่านเกณฑ์',
        'registered' => 'ลงทะเบียนแล้ว',
        default => 'กำลังเรียน',
    };
}

function api_attempt_status(?array $attempt): string
{
    if (!$attempt) {
        return 'not_started';
    }

    return match ((string) $attempt['status']) {
        'passed' => 'passed',
        'posttest_done' => 'not_passed',
        'registered' => 'registered',
        default => 'learning',
    };
}

function api_certificate_payload(?array $attempt): ?array
{
    if (!$attempt || empty($attempt['certificate_code'])) {
        return null;
    }

    $code = (string) $attempt['certificate_code'];
    return [
        'code' => $code,
        'url' => app_absolute_url('certificate_view.php?code=' . rawurlencode($code)),
        'download_url' => app_absolute_url('certificate_view.php?code=' . rawurlencode($code) . '&download=1'),
        'attempt_id' => (int) $attempt['id'],
        'course_id' => (int) $attempt['course_id'],
        'learner_name' => (string) $attempt['learner_name'],
    ];
}

function api_post_percent(?array $attempt): ?float
{
    if (!$attempt || $attempt['post_score'] === null || $attempt['post_total'] === null) {
        return null;
    }

    $total = (int) $attempt['post_total'];
    if ($total <= 0) {
        return null;
    }

    return round(((int) $attempt['post_score'] / $total) * 100, 2);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    api_error('method_not_allowed', 'รองรับเฉพาะ GET เท่านั้น', 405);
}

if (learning_export_api_key() === '') {
    api_error('api_not_configured', 'ยังไม่ได้ตั้งค่า Learning Export API key', 503);
}

if (!learning_export_api_authorized()) {
    api_error('unauthorized', 'API key ไม่ถูกต้อง', 401);
}

if (!database_ready()) {
    api_error('database_unavailable', 'ฐานข้อมูลยังไม่พร้อมใช้งาน', 503);
}

ensure_learning_access_columns();
ensure_curriculum_tables();

$studentId = trim((string) ($_GET['student_id'] ?? ''));
if (!preg_match('/^\d{10}$/', $studentId)) {
    api_error('invalid_student_id', 'กรุณาส่ง student_id เป็นรหัสนักศึกษา 10 หลัก', 400);
}

$passedOnly = api_truthy_param('passed_only');
$includeAll = api_truthy_param('include_all');

$userStmt = db()->prepare(
    "SELECT * FROM users
     WHERE user_type = 'student' AND student_id = ?
     LIMIT 1"
);
$userStmt->execute([$studentId]);
$student = $userStmt->fetch();
if (!$student) {
    api_error('student_not_found', 'ไม่พบนักศึกษารหัสนี้ในระบบ SENA Learning', 404);
}

$userId = (int) $student['id'];
$latestAttempts = latest_user_attempts_by_course($userId);
$certificateAttempts = user_certificate_attempts_by_course($userId);
$courseIds = array_values(array_unique(array_merge(
    array_map('intval', array_keys($latestAttempts)),
    array_map('intval', array_keys($certificateAttempts))
)));

if ($includeAll) {
    $courseStmt = db()->query(
        'SELECT * FROM courses
         WHERE is_published = 1
         ORDER BY created_at ASC, id ASC'
    );
    $courses = $courseStmt->fetchAll();
} elseif ($courseIds) {
    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
    $courseStmt = db()->prepare(
        "SELECT * FROM courses
         WHERE id IN ({$placeholders})
         ORDER BY created_at ASC, id ASC"
    );
    $courseStmt->execute($courseIds);
    $courses = $courseStmt->fetchAll();
} else {
    $courses = [];
}

$courseRows = [];
$progressTotal = 0;
$startedCount = 0;
$passedCount = 0;
$inProgressCount = 0;

foreach ($courses as $course) {
    $courseId = (int) $course['id'];
    $attempt = $latestAttempts[$courseId] ?? null;
    $certificateAttempt = $certificateAttempts[$courseId] ?? null;

    if ($passedOnly && !$certificateAttempt) {
        continue;
    }

    $summary = $attempt
        ? curriculum_summary($attempt)
        : ['required' => count(curriculum_items($courseId, 0)), 'completed' => 0];
    $progressPercent = $attempt ? attempt_progress_percent($attempt) : 0;
    $certificate = api_certificate_payload($certificateAttempt);
    $isPassed = $certificate !== null;
    $status = api_attempt_status($attempt);

    $progressTotal += $progressPercent;
    if ($attempt) {
        $startedCount++;
    }
    if ($isPassed) {
        $passedCount++;
    } elseif ($attempt) {
        $inProgressCount++;
    }

    $coverUrl = public_upload_url((string) ($course['cover_url'] ?? ''));
    $courseRows[] = [
        'course_id' => $courseId,
        'title' => (string) $course['title'],
        'description' => (string) $course['description'],
        'category' => normalize_course_category((string) ($course['category'] ?? '')),
        'category_label' => course_category_label((string) ($course['category'] ?? '')),
        'cover_url' => $coverUrl !== '' ? app_absolute_url($coverUrl) : null,
        'status' => $status,
        'status_label' => api_attempt_status_label($attempt),
        'passed' => $isPassed,
        'progress_percent' => $progressPercent,
        'curriculum' => [
            'completed' => (int) $summary['completed'],
            'required' => (int) $summary['required'],
        ],
        'score' => [
            'post_score' => $attempt && $attempt['post_score'] !== null ? (int) $attempt['post_score'] : null,
            'post_total' => $attempt && $attempt['post_total'] !== null ? (int) $attempt['post_total'] : null,
            'post_percent' => api_post_percent($attempt),
            'pass_percent' => (float) $course['pass_percent'],
        ],
        'certificate' => $certificate,
        'attempt' => $attempt ? [
            'attempt_id' => (int) $attempt['id'],
            'created_at' => (string) $attempt['created_at'],
            'updated_at' => (string) $attempt['updated_at'],
        ] : null,
    ];
}

$courseCount = count($courseRows);

api_send([
    'success' => true,
    'data' => [
        'student' => [
            'user_id' => $userId,
            'user_type' => (string) $student['user_type'],
            'student_id' => (string) $student['student_id'],
            'display_name' => (string) $student['display_name'],
            'group_code' => $student['skr_group_code'] !== null ? (string) $student['skr_group_code'] : null,
            'class_name' => $student['skr_class_name'] !== null ? (string) $student['skr_class_name'] : null,
            'district_id' => $student['skr_district_id'] !== null ? (int) $student['skr_district_id'] : null,
            'district_name' => $student['skr_district_name'] !== null ? (string) $student['skr_district_name'] : null,
            'level' => $student['skr_level'] !== null ? (string) $student['skr_level'] : null,
            'level_name' => $student['skr_level_name'] !== null ? (string) $student['skr_level_name'] : null,
        ],
        'summary' => [
            'courses_returned' => $courseCount,
            'started_courses' => $startedCount,
            'in_progress_courses' => $inProgressCount,
            'passed_courses' => $passedCount,
            'average_progress_percent' => $courseCount > 0 ? (int) round($progressTotal / $courseCount) : 0,
        ],
        'courses' => $courseRows,
    ],
    'meta' => [
        'passed_only' => $passedOnly,
        'include_all' => $includeAll,
        'generated_at' => date(DATE_ATOM),
    ],
]);
