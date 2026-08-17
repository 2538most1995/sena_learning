<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_admin();

function admin_attempt_status_label(string $status): string
{
    return [
        'registered' => 'ลงทะเบียน',
        'pretest_done' => 'ทำก่อนเรียนแล้ว',
        'learning' => 'กำลังเรียน',
        'posttest_done' => 'ทำหลังเรียนแล้ว',
        'passed' => 'ผ่านแล้ว',
    ][$status] ?? $status;
}

function admin_attempt_status_class(string $status): string
{
    return $status === 'passed'
        ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100'
        : 'bg-slate-100 text-slate-700 ring-1 ring-slate-200';
}

function admin_attempt_learner_name(array $attempt): string
{
    $displayName = trim((string) ($attempt['display_name'] ?? ''));
    if ($displayName !== '') {
        return $displayName;
    }

    return (string) ($attempt['learner_name'] ?? '');
}

function admin_attempt_student_group(array $attempt): string
{
    $className = trim((string) ($attempt['skr_class_name'] ?? ''));
    $groupCode = trim((string) ($attempt['skr_group_code'] ?? ''));

    if ($className !== '' && $groupCode !== '' && strpos($className, $groupCode) === false) {
        return $className . ' (' . $groupCode . ')';
    }
    if ($className !== '') {
        return $className;
    }
    if ($groupCode !== '') {
        return $groupCode;
    }

    return 'ไม่ระบุกลุ่มเรียน';
}

function admin_attempt_context_text(array $attempt): string
{
    $parts = [];
    foreach (['skr_level_name', 'skr_district_name'] as $key) {
        $value = trim((string) ($attempt[$key] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }

    return implode(' · ', $parts);
}

$courseId = get_int('course_id');
$audience = (string) ($_GET['audience'] ?? 'general');
if (!in_array($audience, ['general', 'student'], true)) {
    $audience = 'general';
}

$courseSummaryStmt = db()->query(
    'SELECT c.id, c.title, c.description, c.category, c.is_published,
            COUNT(a.id) AS attempt_count,
            COUNT(DISTINCT CASE WHEN COALESCE(u.user_type, "general") = "general" THEN COALESCE(CAST(a.user_id AS CHAR), CONCAT("legacy:", a.learner_name)) END) AS general_count,
            COUNT(DISTINCT CASE WHEN COALESCE(u.user_type, "general") = "student" THEN COALESCE(CAST(a.user_id AS CHAR), CONCAT("legacy:", a.learner_name)) END) AS student_count,
            SUM(CASE WHEN a.status = "passed" THEN 1 ELSE 0 END) AS passed_count,
            SUM(CASE WHEN a.certificate_code IS NOT NULL AND a.certificate_code <> "" THEN 1 ELSE 0 END) AS certificate_count,
            MAX(a.created_at) AS latest_attempt_at
     FROM courses c
     LEFT JOIN attempts a ON a.course_id = c.id
     LEFT JOIN users u ON u.id = a.user_id
     GROUP BY c.id
     ORDER BY latest_attempt_at DESC, c.created_at DESC, c.id DESC'
);
$courseSummaries = $courseSummaryStmt->fetchAll();

$selectedCourse = null;
$attempts = [];
$audienceCounts = ['general' => 0, 'student' => 0];
$studentGroups = [];

if ($courseId > 0) {
    $courseStmt = db()->prepare('SELECT * FROM courses WHERE id = ?');
    $courseStmt->execute([$courseId]);
    $selectedCourse = $courseStmt->fetch() ?: null;

    if ($selectedCourse) {
        $countStmt = db()->prepare(
            'SELECT COALESCE(u.user_type, "general") AS learner_type,
                    COUNT(DISTINCT COALESCE(CAST(a.user_id AS CHAR), CONCAT("legacy:", a.learner_name))) AS learner_count
             FROM attempts a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.course_id = ?
             GROUP BY learner_type'
        );
        $countStmt->execute([$courseId]);
        foreach ($countStmt->fetchAll() as $row) {
            $type = (string) $row['learner_type'];
            if (isset($audienceCounts[$type])) {
                $audienceCounts[$type] = (int) $row['learner_count'];
            }
        }

        $attemptStmt = db()->prepare(
            'SELECT a.*, u.user_type, u.display_name, u.email, u.student_id, u.skr_group_code,
                    u.skr_class_name, u.skr_district_name, u.skr_level_name
             FROM attempts a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.course_id = ? AND COALESCE(u.user_type, "general") = ?
             ORDER BY
                COALESCE(NULLIF(u.skr_class_name, ""), NULLIF(u.skr_group_code, ""), "ไม่ระบุกลุ่มเรียน") ASC,
                COALESCE(NULLIF(u.display_name, ""), a.learner_name) ASC,
                a.created_at DESC'
        );
        $attemptStmt->execute([$courseId, $audience]);
        $attempts = $attemptStmt->fetchAll();

        if ($audience === 'student') {
            foreach ($attempts as $attempt) {
                $studentGroups[admin_attempt_student_group($attempt)][] = $attempt;
            }
        }
    }
}

render_header($selectedCourse ? 'ผู้เรียน ' . (string) $selectedCourse['title'] : 'ผู้เรียนและคะแนน', 'admin');
?>
<section class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <a href="<?= $selectedCourse ? 'attempts.php' : 'index.php' ?>" class="text-sm font-bold text-sea"><?= $selectedCourse ? 'กลับรายการหลักสูตร' : 'กลับหลังบ้าน' ?></a>
            <h1 class="mt-3 text-3xl font-extrabold"><?= $selectedCourse ? e((string) $selectedCourse['title']) : 'ผู้เรียนและคะแนน' ?></h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                <?= $selectedCourse ? 'แยกข้อมูลผู้เรียนตามประเภทบัญชี และจัดกลุ่มนักศึกษาตามกลุ่มเรียนจากระบบ ศกร.' : 'เลือกหลักสูตรเพื่อดูรายชื่อผู้เรียน คะแนน สถานะ และกลุ่มเรียนของนักศึกษา' ?>
            </p>
        </div>
        <?php if ($selectedCourse): ?>
            <div class="attempt-tabs" aria-label="ประเภทผู้เรียน">
                <a class="<?= $audience === 'general' ? 'is-active' : '' ?>" href="attempts.php?course_id=<?= (int) $courseId ?>&audience=general">
                    <span>ประชาชน</span>
                    <strong><?= (int) $audienceCounts['general'] ?></strong>
                </a>
                <a class="<?= $audience === 'student' ? 'is-active' : '' ?>" href="attempts.php?course_id=<?= (int) $courseId ?>&audience=student">
                    <span>นักศึกษา</span>
                    <strong><?= (int) $audienceCounts['student'] ?></strong>
                </a>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$selectedCourse): ?>
        <div class="attempt-course-grid">
            <?php foreach ($courseSummaries as $course): ?>
                <a class="attempt-course-card" href="attempts.php?course_id=<?= (int) $course['id'] ?>&audience=general">
                    <span class="attempt-course-category"><?= e(course_category_label((string) ($course['category'] ?? ''))) ?></span>
                    <strong><?= e((string) $course['title']) ?></strong>
                    <span class="attempt-course-desc"><?= e((string) $course['description']) ?></span>
                    <span class="attempt-course-metrics">
                        <span><b><?= (int) $course['general_count'] ?></b> ประชาชน</span>
                        <span><b><?= (int) $course['student_count'] ?></b> นักศึกษา</span>
                        <span><b><?= (int) $course['passed_count'] ?></b> ผ่าน</span>
                    </span>
                    <span class="attempt-course-footer">
                        <span><?= (int) $course['attempt_count'] ?> รายการเข้าเรียน</span>
                        <span class="attempt-open-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </span>
                    </span>
                </a>
            <?php endforeach; ?>
            <?php if (!$courseSummaries): ?>
                <div class="attempt-empty-state">
                    <strong>ยังไม่มีหลักสูตร</strong>
                    <span>เพิ่มหลักสูตรก่อน แล้วรายการผู้เรียนจะแสดงในหน้านี้</span>
                </div>
            <?php endif; ?>
        </div>
    <?php elseif ($audience === 'student'): ?>
        <div class="space-y-5">
            <?php foreach ($studentGroups as $groupName => $groupAttempts): ?>
                <section class="attempt-group-panel">
                    <div class="attempt-group-header">
                        <div>
                            <span>กลุ่มเรียน</span>
                            <h2><?= e((string) $groupName) ?></h2>
                        </div>
                        <strong><?= count($groupAttempts) ?> รายการ</strong>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="attempt-table">
                            <thead>
                                <tr>
                                    <th>ชื่อ-สกุล</th>
                                    <th>รหัสนักศึกษา</th>
                                    <th>ข้อมูลกลุ่ม</th>
                                    <th>ก่อนเรียน</th>
                                    <th>หลังเรียน</th>
                                    <th>สถานะ</th>
                                    <th>รหัสเกียรติบัตร</th>
                                    <th>วันที่เริ่ม</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($groupAttempts as $attempt): ?>
                                    <tr>
                                        <td class="attempt-name-cell"><?= e(admin_attempt_learner_name($attempt)) ?></td>
                                        <td><?= e((string) ($attempt['student_id'] ?? '-')) ?></td>
                                        <td class="text-slate-500"><?= e(admin_attempt_context_text($attempt) ?: '-') ?></td>
                                        <td><?= e(format_score($attempt['pre_score'] !== null ? (int) $attempt['pre_score'] : null, $attempt['pre_total'] !== null ? (int) $attempt['pre_total'] : null)) ?></td>
                                        <td><?= e(format_score($attempt['post_score'] !== null ? (int) $attempt['post_score'] : null, $attempt['post_total'] !== null ? (int) $attempt['post_total'] : null)) ?></td>
                                        <td><span class="rounded-md px-2 py-1 text-xs font-bold <?= admin_attempt_status_class((string) $attempt['status']) ?>"><?= e(admin_attempt_status_label((string) $attempt['status'])) ?></span></td>
                                        <td><?= e((string) ($attempt['certificate_code'] ?: '-')) ?></td>
                                        <td class="text-slate-500"><?= e((string) $attempt['created_at']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endforeach; ?>
            <?php if (!$studentGroups): ?>
                <div class="attempt-empty-state">
                    <strong>ยังไม่มีนักศึกษาในหลักสูตรนี้</strong>
                    <span>เมื่อนักศึกษาเริ่มเรียน รายชื่อและกลุ่มเรียนจะแสดงที่นี่</span>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="attempt-group-panel">
            <div class="attempt-group-header">
                <div>
                    <span>ประเภทผู้เรียน</span>
                    <h2>ประชาชนทั่วไป</h2>
                </div>
                <strong><?= count($attempts) ?> รายการ</strong>
            </div>
            <div class="overflow-x-auto">
                <table class="attempt-table">
                    <thead>
                        <tr>
                            <th>ผู้เรียน</th>
                            <th>อีเมล</th>
                            <th>ก่อนเรียน</th>
                            <th>หลังเรียน</th>
                            <th>สถานะ</th>
                            <th>รหัสเกียรติบัตร</th>
                            <th>วันที่เริ่ม</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attempts as $attempt): ?>
                            <tr>
                                <td class="attempt-name-cell"><?= e(admin_attempt_learner_name($attempt)) ?></td>
                                <td class="text-slate-500"><?= e((string) ($attempt['email'] ?: '-')) ?></td>
                                <td><?= e(format_score($attempt['pre_score'] !== null ? (int) $attempt['pre_score'] : null, $attempt['pre_total'] !== null ? (int) $attempt['pre_total'] : null)) ?></td>
                                <td><?= e(format_score($attempt['post_score'] !== null ? (int) $attempt['post_score'] : null, $attempt['post_total'] !== null ? (int) $attempt['post_total'] : null)) ?></td>
                                <td><span class="rounded-md px-2 py-1 text-xs font-bold <?= admin_attempt_status_class((string) $attempt['status']) ?>"><?= e(admin_attempt_status_label((string) $attempt['status'])) ?></span></td>
                                <td><?= e((string) ($attempt['certificate_code'] ?: '-')) ?></td>
                                <td class="text-slate-500"><?= e((string) $attempt['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$attempts): ?>
                            <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">ยังไม่มีประชาชนทั่วไปในหลักสูตรนี้</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
