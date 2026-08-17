<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_admin();
ensure_curriculum_tables();

$courseId = get_int('course_id');
$stmt = db()->prepare('SELECT * FROM courses WHERE id = ?');
$stmt->execute([$courseId]);
$course = $stmt->fetch();
if (!$course) {
    flash('ไม่พบหลักสูตร', 'error');
    redirect('index.php');
}

$allowedLessonTypes = ['html', 'video', 'embed', 'link'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) post('action');

        if ($action === 'reorder') {
            $locations = json_decode((string) post('item_locations'), true);
            if (!is_array($locations)) {
                throw new RuntimeException('ลำดับรายการไม่ถูกต้อง');
            }
            $owned = db()->prepare('SELECT id FROM curriculum_items WHERE course_id = ?');
            $owned->execute([$courseId]);
            $ownedIds = array_map('intval', array_column($owned->fetchAll(), 'id'));
            $incomingIds = array_map(fn ($item) => (int) ($item['id'] ?? 0), $locations);
            sort($ownedIds);
            $checkIds = $incomingIds;
            sort($checkIds);
            if ($ownedIds !== $checkIds) {
                throw new RuntimeException('ไม่สามารถจัดลำดับรายการข้ามหลักสูตรได้');
            }
            $sectionStmt = db()->prepare('SELECT id FROM curriculum_sections WHERE course_id = ?');
            $sectionStmt->execute([$courseId]);
            $ownedSections = array_flip(array_map('intval', array_column($sectionStmt->fetchAll(), 'id')));
            $update = db()->prepare('UPDATE curriculum_items SET section_id = ?, sort_order = ?, updated_at = NOW() WHERE id = ? AND course_id = ?');
            $sectionOrders = [];
            foreach ($locations as $location) {
                $sectionId = (int) ($location['section_id'] ?? 0);
                if (!isset($ownedSections[$sectionId])) {
                    throw new RuntimeException('ไม่พบส่วนที่ต้องการจัดลำดับ');
                }
                $sectionOrders[$sectionId] = ($sectionOrders[$sectionId] ?? 0) + 10;
                $update->execute([$sectionId, $sectionOrders[$sectionId], (int) $location['id'], $courseId]);
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($action === 'save_section') {
            $title = trim((string) post('title'));
            if ($title === '') {
                throw new RuntimeException('กรุณากรอกชื่อส่วน');
            }
            $sectionId = (int) post('section_id');
            if ($sectionId > 0) {
                $stmt = db()->prepare('UPDATE curriculum_sections SET title = ?, description = ? WHERE id = ? AND course_id = ?');
                $stmt->execute([$title, trim((string) post('description')), $sectionId, $courseId]);
                flash('บันทึกข้อมูลส่วนแล้ว');
            } else {
                $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM curriculum_sections WHERE course_id = ?');
                $stmt->execute([$courseId]);
                $order = (int) $stmt->fetchColumn();
                $stmt = db()->prepare('INSERT INTO curriculum_sections (course_id, title, description, sort_order) VALUES (?, ?, ?, ?)');
                $stmt->execute([$courseId, $title, trim((string) post('description')), $order]);
                flash('เพิ่มส่วนใหม่แล้ว');
            }
        } elseif ($action === 'delete_section') {
            $sectionId = (int) post('section_id');
            $stmt = db()->prepare('SELECT COUNT(*) FROM curriculum_items WHERE section_id = ? AND course_id = ?');
            $stmt->execute([$sectionId, $courseId]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new RuntimeException('กรุณาย้ายหรือลบรายการภายในส่วนนี้ก่อน');
            }
            $stmt = db()->prepare('SELECT COUNT(*) FROM curriculum_sections WHERE course_id = ?');
            $stmt->execute([$courseId]);
            if ((int) $stmt->fetchColumn() <= 1) {
                throw new RuntimeException('หลักสูตรต้องมีอย่างน้อยหนึ่งส่วน');
            }
            db()->prepare('DELETE FROM curriculum_sections WHERE id = ? AND course_id = ?')->execute([$sectionId, $courseId]);
            flash('ลบส่วนแล้ว');
        } elseif ($action === 'move_section') {
            $sectionId = (int) post('section_id');
            $direction = post('direction') === 'up' ? -1 : 1;
            $sections = curriculum_sections($courseId);
            $index = array_search($sectionId, array_map(fn ($section) => (int) $section['id'], $sections), true);
            $swapIndex = $index === false ? -1 : $index + $direction;
            if ($index !== false && isset($sections[$swapIndex])) {
                $stmt = db()->prepare('UPDATE curriculum_sections SET sort_order = ? WHERE id = ? AND course_id = ?');
                $stmt->execute([(int) $sections[$swapIndex]['sort_order'], $sectionId, $courseId]);
                $stmt->execute([(int) $sections[$index]['sort_order'], (int) $sections[$swapIndex]['id'], $courseId]);
            }
        } elseif ($action === 'toggle_requirement') {
            db()->prepare('UPDATE curriculum_items SET requires_previous = ? WHERE id = ? AND course_id = ?')
                ->execute([isset($_POST['requires_previous']) ? 1 : 0, (int) post('item_id'), $courseId]);
            flash('อัปเดตเงื่อนไขการเรียนแล้ว');
        } elseif ($action === 'delete_item') {
            $stmt = db()->prepare('SELECT item_type, lesson_id FROM curriculum_items WHERE id = ? AND course_id = ?');
            $stmt->execute([(int) post('item_id'), $courseId]);
            $item = $stmt->fetch();
            if (!$item) {
                throw new RuntimeException('ไม่พบรายการที่ต้องการลบ');
            }
            if ($item['item_type'] === 'lesson') {
                db()->prepare('DELETE FROM lessons WHERE id = ? AND course_id = ?')->execute([(int) $item['lesson_id'], $courseId]);
            } else {
                db()->prepare('DELETE FROM curriculum_items WHERE id = ? AND course_id = ?')->execute([(int) post('item_id'), $courseId]);
            }
            flash('นำรายการออกจากลำดับแล้ว');
        } elseif ($action === 'save_lesson') {
            $title = trim((string) post('title'));
            $contentType = (string) post('content_type', 'html');
            $content = trim((string) post('content'));
            $sectionId = (int) post('section_id');
            if ($title === '' || $content === '' || !in_array($contentType, $allowedLessonTypes, true)) {
                throw new RuntimeException('กรุณากรอกข้อมูลบทเรียนหรือสื่อให้ครบ');
            }
            $stmt = db()->prepare('SELECT COUNT(*) FROM curriculum_sections WHERE id = ? AND course_id = ?');
            $stmt->execute([$sectionId, $courseId]);
            if ((int) $stmt->fetchColumn() !== 1) {
                throw new RuntimeException('ไม่พบส่วนที่เลือก');
            }
            $supportsDuration = in_array($contentType, ['video', 'embed'], true);
            $supportsSeekControl = $contentType === 'video' || ($contentType === 'embed' && is_youtube_content($content));
            $allowSeek = $supportsSeekControl && !isset($_POST['allow_seek']) ? 0 : 1;
            $durationValue = trim((string) post('video_duration_seconds'));
            $videoDurationSeconds = $supportsDuration && $durationValue !== ''
                ? max(0, (int) $durationValue)
                : null;
            if ($videoDurationSeconds === 0) {
                $videoDurationSeconds = null;
            }
            $lessonId = (int) post('lesson_id');
            if ($lessonId > 0) {
                db()->prepare('UPDATE lessons SET title = ?, content_type = ?, content = ?, allow_seek = ?, video_duration_seconds = ? WHERE id = ? AND course_id = ?')
                    ->execute([$title, $contentType, $content, $allowSeek, $videoDurationSeconds, $lessonId, $courseId]);
            } else {
                db()->prepare('INSERT INTO lessons (course_id, title, content_type, content, allow_seek, video_duration_seconds, sort_order) VALUES (?, ?, ?, ?, ?, ?, 1)')
                    ->execute([$courseId, $title, $contentType, $content, $allowSeek, $videoDurationSeconds]);
                $lessonId = (int) db()->lastInsertId();
            }
            $itemId = sync_curriculum_item($courseId, 'lesson', $lessonId, $sectionId);
            db()->prepare('UPDATE curriculum_items SET section_id = ?, requires_previous = ? WHERE id = ? AND course_id = ?')
                ->execute([$sectionId, isset($_POST['requires_previous']) ? 1 : 0, $itemId, $courseId]);
            flash('บันทึกบทเรียนหรือสื่อแล้ว');
        } elseif ($action === 'place_quiz_set') {
            $quizSetId = (int) post('quiz_set_id');
            $sectionId = (int) post('section_id');
            $stmt = db()->prepare('SELECT COUNT(*) FROM curriculum_sections WHERE id = ? AND course_id = ?');
            $stmt->execute([$sectionId, $courseId]);
            if ((int) $stmt->fetchColumn() !== 1) {
                throw new RuntimeException('ไม่พบส่วนที่เลือก');
            }
            $stmt = db()->prepare(
                'SELECT COUNT(*) FROM quiz_sets qs
                 WHERE qs.id = ?
                   AND EXISTS (SELECT 1 FROM quiz_set_questions qsq WHERE qsq.quiz_set_id = qs.id)'
            );
            $stmt->execute([$quizSetId]);
            if ((int) $stmt->fetchColumn() !== 1) {
                throw new RuntimeException('กรุณาเลือกชุดข้อสอบที่มีคำถามแล้ว');
            }
            $itemId = sync_curriculum_item($courseId, 'quiz_set', $quizSetId, $sectionId);
            db()->prepare('UPDATE curriculum_items SET section_id = ?, requires_previous = ? WHERE id = ? AND course_id = ?')
                ->execute([$sectionId, isset($_POST['requires_previous']) ? 1 : 0, $itemId, $courseId]);
            flash('เพิ่มชุดข้อสอบลงในลำดับแล้ว');
        }
    } catch (Throwable $e) {
        if ((string) post('action') === 'reorder') {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash($e->getMessage(), 'error');
    }
    redirect('lessons.php?course_id=' . $courseId);
}

$sections = curriculum_sections($courseId);
$items = curriculum_items($courseId);
$itemsBySection = [];
foreach ($items as $item) {
    $itemsBySection[(int) $item['section_id']][] = $item;
}
$lessonCount = count(array_filter($items, fn ($item) => $item['item_type'] === 'lesson'));
$quizSetCount = count($items) - $lessonCount;
$allQuizSets = quiz_sets($courseId);
$availableQuizSets = array_values(array_filter($allQuizSets, fn ($set) => (int) $set['question_count'] > 0));
$panel = (string) ($_GET['panel'] ?? '');
$selectedSectionId = get_int('section_id', (int) ($sections[0]['id'] ?? 0));
$editingLesson = null;
$editingSection = null;
if ($panel === 'lesson' && get_int('edit') > 0) {
    $stmt = db()->prepare('SELECT l.*, ci.section_id, ci.requires_previous FROM lessons l INNER JOIN curriculum_items ci ON ci.lesson_id = l.id WHERE l.id = ? AND l.course_id = ?');
    $stmt->execute([get_int('edit'), $courseId]);
    $editingLesson = $stmt->fetch() ?: null;
    $selectedSectionId = (int) ($editingLesson['section_id'] ?? $selectedSectionId);
}
if ($panel === 'section' && get_int('edit') > 0) {
    $stmt = db()->prepare('SELECT * FROM curriculum_sections WHERE id = ? AND course_id = ?');
    $stmt->execute([get_int('edit'), $courseId]);
    $editingSection = $stmt->fetch() ?: null;
}

$lessonLabels = ['html' => 'บทเรียน HTML / ข้อความ', 'video' => 'วิดีโอ URL', 'embed' => 'Embed code', 'link' => 'ลิงก์ภายนอก'];
render_header('จัดลำดับบทเรียนและสื่อ', 'admin');
?>
<section class="curriculum-admin">
    <div class="curriculum-admin-shell">
        <div class="curriculum-page-heading">
            <div>
                <a href="index.php" class="curriculum-back-link">← กลับหลังบ้าน</a>
                <h1>จัดลำดับบทเรียนและสื่อ</h1>
                <p><?= e($course['title']) ?></p>
            </div>
            <div class="curriculum-heading-actions">
                <a href="questions.php?course_id=<?= $courseId ?>" class="curriculum-secondary-button">คลังชุดข้อสอบ</a>
                <a href="lessons.php?course_id=<?= $courseId ?>&panel=section" class="curriculum-primary-button">+ เพิ่มส่วน</a>
            </div>
        </div>
        <div class="curriculum-summary">
            <div><strong><?= count($sections) ?></strong><span>ส่วน</span></div>
            <div><strong><?= $lessonCount ?></strong><span>บทเรียน / สื่อ</span></div>
            <div><strong><?= $quizSetCount ?></strong><span>ชุดข้อสอบในลำดับ</span></div>
            <p>แบ่งเนื้อหาเป็นส่วน เพิ่มสื่อหรือเลือกชุดข้อสอบจากคลังกลาง แล้วลากรายการข้ามส่วนเพื่อจัดลำดับใหม่</p>
        </div>

        <?php foreach ($sections as $sectionIndex => $section): ?>
            <?php $sectionItems = $itemsBySection[(int) $section['id']] ?? []; ?>
            <div class="curriculum-board curriculum-section-board">
                <div class="curriculum-board-header">
                    <div>
                        <span class="curriculum-section-dot"></span>
                        <strong>ส่วนที่ <?= $sectionIndex + 1 ?> · <?= e($section['title']) ?></strong>
                    </div>
                    <div class="curriculum-section-actions">
                        <span><?= count($sectionItems) ?> รายการ</span>
                        <form method="post"><input type="hidden" name="action" value="move_section"><input type="hidden" name="section_id" value="<?= (int) $section['id'] ?>"><button name="direction" value="up" title="เลื่อนส่วนขึ้น">↑</button><button name="direction" value="down" title="เลื่อนส่วนลง">↓</button></form>
                        <a href="lessons.php?course_id=<?= $courseId ?>&panel=section&edit=<?= (int) $section['id'] ?>">แก้ไขส่วน</a>
                    </div>
                </div>
                <div class="curriculum-list" data-section-id="<?= (int) $section['id'] ?>">
                    <?php if (!$sectionItems): ?><div class="curriculum-empty"><strong>ยังไม่มีรายการในส่วนนี้</strong><p>เพิ่มบทเรียน หรือเลือกชุดข้อสอบจากคลังกลาง</p></div><?php endif; ?>
                    <?php foreach ($sectionItems as $index => $item): ?>
                        <?php $isQuizSet = $item['item_type'] === 'quiz_set'; ?>
                        <?php
                        $rowDescription = $isQuizSet
                            ? (int) $item['quiz_question_total'] . ' ข้อ · ชุดข้อสอบกลาง'
                            : ($lessonLabels[$item['content_type']] ?? $item['content_type']);
                        if (!$isQuizSet && !empty($item['video_duration_seconds'])) {
                            $durationLabel = format_learning_duration((int) ($item['video_duration_seconds'] ?? 0));
                            $rowDescription .= $durationLabel !== '' ? ' · ' . $durationLabel : '';
                        } elseif (!$isQuizSet && lesson_requires_video_completion($item)) {
                            $rowDescription .= ' · ยังไม่ระบุเวลา';
                        }
                        if (!$isQuizSet && lesson_requires_video_completion($item)) {
                            $rowDescription .= (int) ($item['allow_seek'] ?? 1) === 1 ? ' · กรอได้' : ' · ห้ามกรอข้าม';
                        }
                        ?>
                        <article class="curriculum-row <?= $isQuizSet ? 'is-question' : '' ?>" draggable="true" data-item-id="<?= (int) $item['id'] ?>">
                            <span class="curriculum-drag" title="ลากเพื่อจัดลำดับ">⋮⋮</span>
                            <span class="curriculum-order"><?= $index + 1 ?></span>
                            <span class="curriculum-type-icon <?= $isQuizSet ? 'is-question' : '' ?>">
                                <?php if ($isQuizSet): ?><svg viewBox="0 0 24 24"><path d="M8 7h8M8 12h8M8 17h5"/><rect x="4" y="3.75" width="16" height="16.5" rx="2"/></svg>
                                <?php else: ?><svg viewBox="0 0 24 24"><path d="m9.25 8 6 4-6 4V8Z"/><rect x="3.75" y="5.25" width="16.5" height="13.5" rx="2"/></svg><?php endif; ?>
                            </span>
                            <div class="curriculum-row-copy">
                                <strong><?= e($item['title']) ?></strong>
                                <span><?= e($rowDescription) ?></span>
                            </div>
                            <div class="curriculum-row-controls">
                                <form method="post" class="curriculum-switch-form"><input type="hidden" name="action" value="toggle_requirement"><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>"><label class="curriculum-switch"><span>ต้องเรียนก่อน</span><input type="checkbox" name="requires_previous" value="1" <?= (int) $item['requires_previous'] === 1 ? 'checked' : '' ?> onchange="this.form.submit()"><i></i></label></form>
                                <?php if ($isQuizSet): ?>
                                    <a class="curriculum-icon-button" href="questions.php?course_id=<?= $courseId ?>&set_id=<?= (int) $item['quiz_set_id'] ?>" title="จัดการชุดข้อสอบ"><svg viewBox="0 0 24 24"><path d="m14.5 5.5 4 4M5 19l3.7-.8L19 7.9a1.4 1.4 0 0 0 0-2l-.9-.9a1.4 1.4 0 0 0-2 0L5.8 15.3 5 19Z"/></svg></a>
                                <?php else: ?>
                                    <a class="curriculum-icon-button" href="lessons.php?course_id=<?= $courseId ?>&panel=lesson&section_id=<?= (int) $section['id'] ?>&edit=<?= (int) $item['lesson_id'] ?>" title="แก้ไข"><svg viewBox="0 0 24 24"><path d="m14.5 5.5 4 4M5 19l3.7-.8L19 7.9a1.4 1.4 0 0 0 0-2l-.9-.9a1.4 1.4 0 0 0-2 0L5.8 15.3 5 19Z"/></svg></a>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('ยืนยันการนำรายการนี้ออกจากลำดับ?')"><input type="hidden" name="action" value="delete_item"><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>"><button class="curriculum-icon-button is-danger" title="ลบ"><svg viewBox="0 0 24 24"><path d="M4.75 7.25h14.5M9 7.25v-2h6v2m-8.5 0 .75 12h9.5l.75-12M10 10.5v5.5m4-5.5v5.5"/></svg></button></form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="curriculum-board-footer">
                    <a href="lessons.php?course_id=<?= $courseId ?>&panel=lesson&section_id=<?= (int) $section['id'] ?>" class="curriculum-primary-button">+ เพิ่มบทเรียน / สื่อ</a>
                    <a href="lessons.php?course_id=<?= $courseId ?>&panel=quiz_set&section_id=<?= (int) $section['id'] ?>" class="curriculum-outline-button">+ เลือกชุดข้อสอบ</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (in_array($panel, ['lesson', 'quiz_set', 'section'], true)): ?>
        <a class="curriculum-drawer-backdrop" href="lessons.php?course_id=<?= $courseId ?>" aria-label="ปิด"></a>
        <aside class="curriculum-drawer">
            <div class="curriculum-drawer-header"><div><span><?= $panel === 'section' ? 'ส่วนของหลักสูตร' : ($panel === 'quiz_set' ? 'คลังข้อสอบกลาง' : 'บทเรียน / สื่อ') ?></span><h2><?= ($editingLesson || $editingSection) ? 'แก้ไขรายการ' : 'เพิ่มรายการใหม่' ?></h2></div><a href="lessons.php?course_id=<?= $courseId ?>" aria-label="ปิด">×</a></div>
            <?php if ($panel === 'section'): ?>
                <form method="post" class="curriculum-drawer-form"><input type="hidden" name="action" value="save_section"><input type="hidden" name="section_id" value="<?= (int) ($editingSection['id'] ?? 0) ?>"><label>ชื่อส่วน<input name="title" required value="<?= e($editingSection['title'] ?? '') ?>" placeholder="เช่น ส่วนที่ 1 พื้นฐานสำคัญ"></label><label>คำอธิบาย<textarea name="description" rows="4"><?= e($editingSection['description'] ?? '') ?></textarea></label><button class="curriculum-save-button">บันทึกส่วน</button></form>
                <?php if ($editingSection): ?><form method="post" class="curriculum-delete-section" onsubmit="return confirm('ยืนยันการลบส่วนนี้?')"><input type="hidden" name="action" value="delete_section"><input type="hidden" name="section_id" value="<?= (int) $editingSection['id'] ?>"><button>ลบส่วนนี้</button></form><?php endif; ?>
            <?php elseif ($panel === 'quiz_set'): ?>
                <form method="post" class="curriculum-drawer-form"><input type="hidden" name="action" value="place_quiz_set"><input type="hidden" name="section_id" value="<?= $selectedSectionId ?>"><label>เลือกชุดข้อสอบ<select name="quiz_set_id" required><option value="">เลือกจากคลังข้อสอบกลางทุกคอร์ส</option><?php foreach ($availableQuizSets as $set): ?><option value="<?= (int) $set['id'] ?>"><?= e($set['title']) ?> · <?= (int) $set['question_count'] ?> ข้อ<?= !empty($set['course_title']) ? ' · ' . e((string) $set['course_title']) : '' ?><?= (int) ($set['usage_count'] ?? 0) > 0 ? ' · ใช้แล้ว ' . (int) $set['usage_count'] . ' จุด' : '' ?></option><?php endforeach; ?></select></label><?php if (!$availableQuizSets): ?><p class="curriculum-drawer-hint">ยังไม่มีชุดข้อสอบที่มีคำถามพร้อมใช้</p><?php endif; ?><label class="curriculum-form-check"><input type="checkbox" name="requires_previous" value="1" checked><span><strong>ต้องเรียนรายการก่อนหน้าให้ครบ</strong><small>ผู้เรียนจะเปิดชุดข้อสอบเมื่อผ่านลำดับก่อนหน้าแล้ว</small></span></label><button class="curriculum-save-button">เพิ่มชุดข้อสอบในส่วนนี้</button><a class="curriculum-drawer-link" href="questions.php?course_id=<?= $courseId ?>">เปิดคลังเพื่อสร้างชุดข้อสอบ</a></form>
            <?php else: ?>
                <form method="post" class="curriculum-drawer-form lesson-editor-form">
                    <input type="hidden" name="action" value="save_lesson">
                    <input type="hidden" name="lesson_id" value="<?= (int) ($editingLesson['id'] ?? 0) ?>">
                    <input type="hidden" name="section_id" value="<?= $selectedSectionId ?>">
                    <label>ชื่อบทเรียนหรือสื่อ<input name="title" required value="<?= e($editingLesson['title'] ?? '') ?>"></label>
                    <label>ชนิดสื่อ<select name="content_type"><?php foreach ($lessonLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= ($editingLesson['content_type'] ?? 'html') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                    <label>เนื้อหา / URL / Embed code<textarea name="content" rows="11" required><?= e($editingLesson['content'] ?? '') ?></textarea></label>
                    <div class="curriculum-video-settings" data-video-settings hidden>
                        <div class="curriculum-video-settings-heading">
                            <strong>ตั้งค่าวิดีโอ</strong>
                            <span>วิดีโอ URL และ YouTube Embed ตรวจเวลาได้อัตโนมัติ ส่วน Embed อื่นกรอกเวลาเองได้</span>
                        </div>
                        <label class="curriculum-form-check">
                            <input type="checkbox" name="allow_seek" value="1" <?= (int) ($editingLesson['allow_seek'] ?? 1) === 1 ? 'checked' : '' ?>>
                            <span><strong>อนุญาตให้กรอวิดีโอได้</strong><small>เมื่อปิด ผู้เรียนย้อนกลับได้ แต่จะลากข้ามไปยังช่วงที่ยังไม่ได้ดูไม่ได้ รองรับวิดีโอ URL และ YouTube Embed</small></span>
                        </label>
                        <label>ความยาววิดีโอ (วินาที)
                            <input type="number" min="1" step="1" name="video_duration_seconds" value="<?= e((string) ($editingLesson['video_duration_seconds'] ?? '')) ?>" placeholder="กดตรวจเวลาจากวิดีโอ">
                        </label>
                        <button type="button" class="curriculum-detect-duration" data-detect-video-duration>ตรวจเวลาจากวิดีโอ</button>
                        <p class="curriculum-video-duration-status" data-video-duration-status>ระบบจะแสดงเวลาเรียนรวมของหลักสูตรจากค่านี้</p>
                    </div>
                    <label class="curriculum-form-check"><input type="checkbox" name="requires_previous" value="1" <?= (int) ($editingLesson['requires_previous'] ?? 1) === 1 ? 'checked' : '' ?>><span><strong>ต้องเรียนรายการก่อนหน้าให้ครบ</strong><small>ผู้เรียนจะเปิดรายการนี้ได้เมื่อผ่านลำดับก่อนหน้าแล้ว</small></span></label>
                    <button class="curriculum-save-button">บันทึกบทเรียน / สื่อ</button>
                </form>
            <?php endif; ?>
        </aside>
    <?php endif; ?>
</section>
<script>
(() => {
    const lists = [...document.querySelectorAll('.curriculum-list')];
    let dragging = null;
    const save = async () => {
        const locations = lists.flatMap((list) => [...list.querySelectorAll('.curriculum-row')].map((row) => ({ id: Number(row.dataset.itemId), section_id: Number(list.dataset.sectionId) })));
        const form = new FormData();
        form.append('action', 'reorder');
        form.append('item_locations', JSON.stringify(locations));
        const response = await fetch(window.location.href, { method: 'POST', body: form });
        if (response.ok) window.location.reload();
    };
    document.querySelectorAll('.curriculum-row').forEach((row) => {
        row.addEventListener('dragstart', () => { dragging = row; row.classList.add('is-dragging'); });
        row.addEventListener('dragend', () => { row.classList.remove('is-dragging'); dragging = null; save(); });
    });
    lists.forEach((list) => list.addEventListener('dragover', (event) => {
        event.preventDefault();
        if (!dragging) return;
        const row = event.target.closest('.curriculum-row');
        if (!row) return list.appendChild(dragging);
        const rect = row.getBoundingClientRect();
        list.insertBefore(dragging, event.clientY > rect.top + rect.height / 2 ? row.nextSibling : row);
    }));
})();

(() => {
    const form = document.querySelector('.lesson-editor-form');
    if (!form) return;
    const typeInput = form.querySelector('[name="content_type"]');
    const contentInput = form.querySelector('[name="content"]');
    const durationInput = form.querySelector('[name="video_duration_seconds"]');
    const settings = form.querySelector('[data-video-settings]');
    const detectButton = form.querySelector('[data-detect-video-duration]');
    const status = form.querySelector('[data-video-duration-status]');
    const initialContent = contentInput.value.trim();
    let youtubeApiPromise = null;

    const formatDuration = (seconds) => {
        const total = Math.max(1, Math.round(seconds));
        const minutes = Math.floor(total / 60);
        const remaining = total % 60;
        return minutes > 0 ? `${minutes}:${String(remaining).padStart(2, '0')} นาที` : `${remaining} วินาที`;
    };
    const setStatus = (message, isError = false) => {
        status.textContent = message;
        status.classList.toggle('is-error', isError);
    };
    const updateVisibility = () => {
        settings.hidden = !['video', 'embed'].includes(typeInput.value);
    };
    const youtubeIdFromContent = (value) => {
        const match = value.match(/(?:youtu\.be\/|youtube(?:-nocookie)?\.com\/(?:embed\/|shorts\/|live\/|watch\?(?:[^#]*&)?v=))([A-Za-z0-9_-]{6,})/i);
        return match ? match[1] : '';
    };
    const loadYouTubeApi = () => {
        if (window.YT && window.YT.Player) return Promise.resolve();
        if (youtubeApiPromise) return youtubeApiPromise;
        youtubeApiPromise = new Promise((resolve) => {
            const previousReady = window.onYouTubeIframeAPIReady;
            window.onYouTubeIframeAPIReady = () => {
                if (typeof previousReady === 'function') previousReady();
                resolve();
            };
            if (!document.querySelector('script[src="https://www.youtube.com/iframe_api"]')) {
                const script = document.createElement('script');
                script.src = 'https://www.youtube.com/iframe_api';
                document.head.appendChild(script);
            }
        });
        return youtubeApiPromise;
    };
    const detectYouTubeDuration = async (videoId) => {
        await loadYouTubeApi();
        return new Promise((resolve, reject) => {
            const holder = document.createElement('div');
            holder.style.cssText = 'position:fixed;left:-9999px;top:-9999px;width:1px;height:1px;';
            document.body.appendChild(holder);
            let player;
            const finish = (callback, value) => {
                if (player && typeof player.destroy === 'function') player.destroy();
                holder.remove();
                callback(value);
            };
            player = new YT.Player(holder, {
                videoId,
                events: {
                    onReady: (event) => {
                        const seconds = Math.round(event.target.getDuration());
                        seconds > 0 ? finish(resolve, seconds) : finish(reject, new Error('ไม่พบความยาววิดีโอ'));
                    },
                    onError: () => finish(reject, new Error('YouTube ไม่สามารถอ่านวิดีโอนี้ได้'))
                }
            });
        });
    };
    const detectNativeDuration = (url) => new Promise((resolve, reject) => {
        const video = document.createElement('video');
        video.preload = 'metadata';
        video.onloadedmetadata = () => {
            const seconds = Math.round(video.duration);
            video.removeAttribute('src');
            seconds > 0 ? resolve(seconds) : reject(new Error('ไม่พบความยาววิดีโอ'));
        };
        video.onerror = () => reject(new Error('ไม่สามารถอ่านข้อมูลวิดีโอจาก URL นี้ได้'));
        video.src = url;
    });

    detectButton.addEventListener('click', async () => {
        const content = contentInput.value.trim();
        if (!content) {
            setStatus('กรุณาวาง URL หรือ Embed code ก่อนตรวจเวลา', true);
            return;
        }
        detectButton.disabled = true;
        setStatus('กำลังตรวจความยาววิดีโอ...');
        try {
            const youtubeId = youtubeIdFromContent(content);
            const seconds = youtubeId
                ? await detectYouTubeDuration(youtubeId)
                : await detectNativeDuration(content);
            durationInput.value = String(seconds);
            setStatus(`ตรวจพบ ${formatDuration(seconds)} และจะนำไปรวมเป็นเวลาเรียนของหลักสูตร`);
        } catch (error) {
            setStatus(`${error.message} กรุณากรอกจำนวนวินาทีด้วยตนเอง`, true);
        } finally {
            detectButton.disabled = false;
        }
    });
    contentInput.addEventListener('input', () => {
        if (contentInput.value.trim() !== initialContent) {
            durationInput.value = '';
            setStatus('เนื้อหาวิดีโอเปลี่ยนแล้ว กรุณาตรวจเวลาใหม่ก่อนบันทึก');
        }
    });
    contentInput.addEventListener('blur', () => {
        if (!settings.hidden && contentInput.value.trim() && !durationInput.value) {
            detectButton.click();
        }
    });
    typeInput.addEventListener('change', updateVisibility);
    updateVisibility();
})();
</script>
<?php render_footer(); ?>
