<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';
ensure_curriculum_tables();

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'method_not_allowed']);
        exit;
    }

    $attempt = require_attempt();
    $lessonId = (int) post('lesson_id', 0);
    if ($lessonId <= 0) {
        throw new RuntimeException('ไม่พบบทเรียน');
    }

    $stmt = db()->prepare('SELECT id, content_type, content, allow_seek, video_duration_seconds FROM lessons WHERE id = ? AND course_id = ?');
    $stmt->execute([$lessonId, (int) $attempt['course_id']]);
    $lesson = $stmt->fetch();
    if (!$lesson) {
        throw new RuntimeException('บทเรียนไม่ตรงกับหลักสูตรนี้');
    }

    $itemStmt = db()->prepare('SELECT id FROM curriculum_items WHERE lesson_id = ? AND course_id = ?');
    $itemStmt->execute([$lessonId, (int) $attempt['course_id']]);
    $item = curriculum_item_for_attempt($attempt, (int) $itemStmt->fetchColumn());
    if (!$item || (int) $item['is_accessible'] !== 1) {
        throw new RuntimeException('กรุณาเรียนรายการก่อนหน้าให้ครบก่อน');
    }

    if (lesson_requires_video_completion($lesson)) {
        require_video_completion_evidence($attempt, $lesson);
    }

    mark_lesson_completed((int) $attempt['id'], $lessonId, lesson_requires_video_completion($lesson) ? 'video' : 'manual');
    forget_video_watch_session((int) $attempt['id'], $lessonId);
    $summary = finalize_curriculum_attempt((int) $attempt['id']);
    $nextUrl = attempt_url('lesson.php', $attempt);
    $currentFound = false;
    foreach ($summary['items'] as $summaryItem) {
        if ((int) ($summaryItem['lesson_id'] ?? 0) === $lessonId) {
            $currentFound = true;
            continue;
        }
        if ($currentFound && (int) $summaryItem['is_accessible'] === 1 && (int) $summaryItem['is_completed'] !== 1) {
            $nextUrl = attempt_url('lesson.php', $attempt, ['item' => (int) $summaryItem['id']]);
            break;
        }
    }
    if (!empty($summary['ready'])) {
        $nextUrl = attempt_url('result.php', $attempt);
    }

    echo json_encode([
        'ok' => true,
        'completed' => $summary['completed'],
        'required' => $summary['required'],
        'next_url' => $nextUrl,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
