<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

function overview_send(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function overview_error(string $code, string $message, int $statusCode): never
{
    overview_send([
        'success' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ], $statusCode);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    overview_error('method_not_allowed', 'รองรับเฉพาะ GET เท่านั้น', 405);
}

if (learning_export_api_key() === '') {
    overview_error('api_not_configured', 'ยังไม่ได้ตั้งค่า Learning Export API key', 503);
}

if (!learning_export_api_authorized()) {
    overview_error('unauthorized', 'API key ไม่ถูกต้อง', 401);
}

if (!database_ready()) {
    overview_error('database_unavailable', 'ฐานข้อมูลยังไม่พร้อมใช้งาน', 503);
}

ensure_learning_access_columns();

try {
    $pdo = db();
    $userCounts = [
        'student' => 0,
        'general' => 0,
    ];
    foreach ($pdo->query("SELECT user_type, COUNT(*) AS total FROM users GROUP BY user_type")->fetchAll() as $row) {
        $type = (string)($row['user_type'] ?? '');
        if (isset($userCounts[$type])) {
            $userCounts[$type] = (int)$row['total'];
        }
    }

    $courseCount = (int)$pdo->query("SELECT COUNT(*) FROM courses WHERE is_published = 1")->fetchColumn();
    $certificateCount = (int)$pdo->query("SELECT COUNT(*) FROM attempts WHERE certificate_code IS NOT NULL AND status = 'passed'")->fetchColumn();
    $startedCount = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM attempts WHERE user_id IS NOT NULL")->fetchColumn();
    $passedLearners = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM attempts WHERE user_id IS NOT NULL AND certificate_code IS NOT NULL AND status = 'passed'")->fetchColumn();
    $totalUsers = $userCounts['student'] + $userCounts['general'];

    $statusCounts = [
        'learning' => 0,
        'registered' => 0,
        'passed' => 0,
        'not_passed' => 0,
    ];
    foreach ($pdo->query("SELECT status, COUNT(*) AS total FROM attempts GROUP BY status")->fetchAll() as $row) {
        $status = (string)($row['status'] ?? '');
        $count = (int)$row['total'];
        if ($status === 'passed') {
            $statusCounts['passed'] += $count;
        } elseif ($status === 'registered') {
            $statusCounts['registered'] += $count;
        } elseif ($status === 'posttest_done') {
            $statusCounts['not_passed'] += $count;
        } else {
            $statusCounts['learning'] += $count;
        }
    }

    $latestCertificates = $pdo->query(
        "SELECT a.certificate_code, a.learner_name, a.updated_at, c.title AS course_title
         FROM attempts a
         INNER JOIN courses c ON c.id = a.course_id
         WHERE a.certificate_code IS NOT NULL AND a.status = 'passed'
         ORDER BY a.updated_at DESC, a.id DESC
         LIMIT 10"
    )->fetchAll();
    foreach ($latestCertificates as &$row) {
        $row['url'] = app_absolute_url('certificate_view.php?code=' . rawurlencode((string)$row['certificate_code']));
        $row['download_url'] = app_absolute_url('certificate_view.php?code=' . rawurlencode((string)$row['certificate_code']) . '&download=1');
    }
    unset($row);

    overview_send([
        'success' => true,
        'data' => [
            'users' => [
                'total' => $totalUsers,
                'student' => $userCounts['student'],
                'general' => $userCounts['general'],
                'started' => $startedCount,
                'passed' => $passedLearners,
            ],
            'courses' => [
                'published' => $courseCount,
            ],
            'certificates' => [
                'total' => $certificateCount,
                'latest' => $latestCertificates,
            ],
            'status' => $statusCounts,
            'generated_at' => date(DATE_ATOM),
        ],
    ]);
} catch (Throwable $exception) {
    error_log('[learning_overview_api] ' . $exception->getMessage());
    overview_error('server_error', 'ระบบขัดข้อง กรุณาลองใหม่อีกครั้ง', 500);
}
