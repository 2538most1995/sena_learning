<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_admin();
ensure_learning_access_columns();

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
    foreach (['skr_district_name'] as $key) {
        $value = trim((string) ($attempt[$key] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }

    return implode(' · ', $parts);
}

function admin_attempt_student_level(array $attempt): string
{
    $levelName = trim((string) ($attempt['skr_level_name'] ?? ''));
    if ($levelName !== '') {
        return $levelName;
    }

    $level = trim((string) ($attempt['skr_level'] ?? ''));
    return $level !== '' ? $level : 'ไม่ระบุระดับ';
}

function admin_attempt_audience_label(string $audience): string
{
    return $audience === 'student' ? 'นักศึกษา' : 'ประชาชน';
}

function admin_attempt_audience_class(string $audience): string
{
    return $audience === 'student'
        ? 'bg-blue-50 text-blue-700 ring-1 ring-blue-100'
        : 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100';
}

function admin_attempt_filter_url(int $courseId, string $audience, array $filters = []): string
{
    $query = ['course_id' => $courseId, 'audience' => $audience];
    foreach ($filters as $key => $value) {
        if (trim((string) $value) !== '') {
            $query[$key] = (string) $value;
        }
    }

    return 'attempts.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function admin_attempt_certificate_link(array $attempt): string
{
    $certificateCode = trim((string) ($attempt['certificate_code'] ?? ''));
    if ($certificateCode === '' || (string) ($attempt['status'] ?? '') !== 'passed') {
        return '<span class="attempt-certificate-empty">ยังไม่มี</span>';
    }

    $url = '../certificate_view.php?code=' . rawurlencode($certificateCode);
    $label = 'ดูเกียรติบัตรของ ' . admin_attempt_learner_name($attempt);

    return '<a class="attempt-certificate-link" href="' . e($url) . '" target="_blank" rel="noopener" aria-label="' . e($label . ' (เปิดแท็บใหม่)') . '">ดูเกียรติบัตร</a>';
}

$courseId = get_int('course_id');
$audience = (string) ($_GET['audience'] ?? 'all');
if (!in_array($audience, ['all', 'general', 'student'], true)) {
    $audience = 'all';
}
$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100, 'UTF-8');
$groupFilter = trim((string) ($_GET['group'] ?? ''));
$levelFilter = trim((string) ($_GET['level'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$certificateFilter = trim((string) ($_GET['certificate'] ?? ''));
$allowedStatuses = ['registered', 'pretest_done', 'learning', 'posttest_done', 'passed'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}
if (!in_array($certificateFilter, ['', 'issued', 'none'], true)) {
    $certificateFilter = '';
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
$groupOptions = [];
$levelOptions = [];

if ($courseId > 0) {
    $courseStmt = db()->prepare('SELECT * FROM courses WHERE id = ?');
    $courseStmt->execute([$courseId]);
    $selectedCourse = $courseStmt->fetch() ?: null;

    if ($selectedCourse) {
        $studentOptionStmt = db()->prepare(
            'SELECT DISTINCT u.skr_class_name, u.skr_group_code, u.skr_level, u.skr_level_name
             FROM attempts a
             INNER JOIN users u ON u.id = a.user_id AND u.user_type = "student"
             WHERE a.course_id = ?
             ORDER BY u.skr_class_name ASC, u.skr_group_code ASC, u.skr_level_name ASC, u.skr_level ASC'
        );
        $studentOptionStmt->execute([$courseId]);
        foreach ($studentOptionStmt->fetchAll() as $studentOption) {
            $groupValue = trim((string) ($studentOption['skr_class_name'] ?? ''));
            if ($groupValue === '') {
                $groupValue = trim((string) ($studentOption['skr_group_code'] ?? ''));
            }
            if ($groupValue !== '') {
                $groupOptions[$groupValue] = admin_attempt_student_group($studentOption);
            }

            $levelValue = trim((string) ($studentOption['skr_level_name'] ?? ''));
            if ($levelValue === '') {
                $levelValue = trim((string) ($studentOption['skr_level'] ?? ''));
            }
            if ($levelValue !== '') {
                $levelOptions[$levelValue] = admin_attempt_student_level($studentOption);
            }
        }
        if (!isset($groupOptions[$groupFilter])) {
            $groupFilter = '';
        }
        if (!isset($levelOptions[$levelFilter])) {
            $levelFilter = '';
        }
        if ($audience === 'general') {
            $groupFilter = '';
            $levelFilter = '';
        }

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

        $where = [
            'a.course_id = ?',
            'NOT EXISTS (
                SELECT 1
                FROM attempts preferred_attempt
                WHERE preferred_attempt.course_id = a.course_id
                  AND (
                    (a.user_id IS NOT NULL AND preferred_attempt.user_id = a.user_id)
                    OR (
                        a.user_id IS NULL AND preferred_attempt.user_id IS NULL
                        AND TRIM(preferred_attempt.learner_name) = TRIM(a.learner_name)
                    )
                  )
                  AND (
                    (CASE WHEN preferred_attempt.status = "passed" AND preferred_attempt.certificate_code IS NOT NULL AND preferred_attempt.certificate_code <> "" THEN 1 ELSE 0 END)
                        > (CASE WHEN a.status = "passed" AND a.certificate_code IS NOT NULL AND a.certificate_code <> "" THEN 1 ELSE 0 END)
                    OR (
                        (CASE WHEN preferred_attempt.status = "passed" AND preferred_attempt.certificate_code IS NOT NULL AND preferred_attempt.certificate_code <> "" THEN 1 ELSE 0 END)
                            = (CASE WHEN a.status = "passed" AND a.certificate_code IS NOT NULL AND a.certificate_code <> "" THEN 1 ELSE 0 END)
                        AND (
                            preferred_attempt.created_at > a.created_at
                            OR (preferred_attempt.created_at = a.created_at AND preferred_attempt.id > a.id)
                        )
                    )
                  )
            )',
        ];
        $params = [$courseId];
        if ($audience !== 'all') {
            $where[] = 'COALESCE(u.user_type, "general") = ?';
            $params[] = $audience;
        }
        if ($search !== '') {
            $where[] = '(COALESCE(NULLIF(u.display_name, ""), a.learner_name) LIKE ?
                OR u.student_id LIKE ? OR u.email LIKE ? OR u.skr_class_name LIKE ?
                OR u.skr_group_code LIKE ? OR u.skr_level_name LIKE ?)';
            $searchValue = '%' . $search . '%';
            array_push($params, $searchValue, $searchValue, $searchValue, $searchValue, $searchValue, $searchValue);
        }
        if ($groupFilter !== '') {
            $where[] = 'COALESCE(NULLIF(TRIM(u.skr_class_name), ""), NULLIF(TRIM(u.skr_group_code), "")) = ?';
            $params[] = $groupFilter;
        }
        if ($levelFilter !== '') {
            $where[] = 'COALESCE(NULLIF(TRIM(u.skr_level_name), ""), NULLIF(TRIM(u.skr_level), "")) = ?';
            $params[] = $levelFilter;
        }
        if ($statusFilter !== '') {
            $where[] = 'a.status = ?';
            $params[] = $statusFilter;
        }
        if ($certificateFilter === 'issued') {
            $where[] = 'a.status = "passed" AND a.certificate_code IS NOT NULL AND a.certificate_code <> ""';
        } elseif ($certificateFilter === 'none') {
            $where[] = '(a.status <> "passed" OR a.certificate_code IS NULL OR a.certificate_code = "")';
        }

        $attemptStmt = db()->prepare(
            'SELECT a.*, COALESCE(u.user_type, "general") AS user_type, u.display_name, u.email, u.student_id,
                    u.skr_group_code, u.skr_class_name, u.skr_district_name, u.skr_level, u.skr_level_name
             FROM attempts a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY
                COALESCE(NULLIF(u.skr_class_name, ""), NULLIF(u.skr_group_code, ""), "ไม่ระบุกลุ่มเรียน") ASC,
                COALESCE(NULLIF(u.display_name, ""), a.learner_name) ASC,
                a.created_at DESC'
        );
        $attemptStmt->execute($params);
        $attempts = $attemptStmt->fetchAll();

        if ($audience === 'student') {
            foreach ($attempts as $attempt) {
                $studentGroups[admin_attempt_student_group($attempt)][] = $attempt;
            }
        }
    }
}

$activeFilters = [
    'q' => $search,
    'group' => $groupFilter,
    'level' => $levelFilter,
    'status' => $statusFilter,
    'certificate' => $certificateFilter,
];
$hasActiveFilters = count(array_filter($activeFilters, static fn ($value): bool => (string) $value !== '')) > 0;

render_header($selectedCourse ? 'ผู้เรียน ' . (string) $selectedCourse['title'] : 'ผู้เรียนและคะแนน', 'admin');
?>
<section class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <a href="<?= $selectedCourse ? 'attempts.php' : 'index.php' ?>" class="text-sm font-bold text-sea"><?= $selectedCourse ? 'กลับรายการหลักสูตร' : 'กลับหลังบ้าน' ?></a>
            <h1 class="mt-3 text-3xl font-extrabold"><?= $selectedCourse ? e((string) $selectedCourse['title']) : 'ผู้เรียนและคะแนน' ?></h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                <?= $selectedCourse ? 'ดูชื่อ กลุ่มเรียน ระดับ คะแนน สถานะ และเกียรติบัตรของผู้เรียนในหลักสูตรนี้' : 'เลือกหลักสูตรเพื่อดูรายชื่อผู้เรียน คะแนน สถานะ และกลุ่มเรียนของนักศึกษา' ?>
            </p>
        </div>
        <?php if ($selectedCourse): ?>
            <nav class="attempt-tabs" aria-label="กรองตามประเภทผู้เรียน">
                <a class="<?= $audience === 'all' ? 'is-active' : '' ?>" href="<?= e(admin_attempt_filter_url($courseId, 'all', $activeFilters)) ?>" <?= $audience === 'all' ? 'aria-current="page"' : '' ?>>
                    <span>ทั้งหมด</span>
                    <strong><?= (int) $audienceCounts['general'] + (int) $audienceCounts['student'] ?></strong>
                </a>
                <a class="<?= $audience === 'general' ? 'is-active' : '' ?>" href="<?= e(admin_attempt_filter_url($courseId, 'general', $activeFilters)) ?>" <?= $audience === 'general' ? 'aria-current="page"' : '' ?>>
                    <span>ประชาชน</span>
                    <strong><?= (int) $audienceCounts['general'] ?></strong>
                </a>
                <a class="<?= $audience === 'student' ? 'is-active' : '' ?>" href="<?= e(admin_attempt_filter_url($courseId, 'student', $activeFilters)) ?>" <?= $audience === 'student' ? 'aria-current="page"' : '' ?>>
                    <span>นักศึกษา</span>
                    <strong><?= (int) $audienceCounts['student'] ?></strong>
                </a>
            </nav>
        <?php endif; ?>
    </div>

    <?php if (!$selectedCourse): ?>
        <div class="attempt-course-grid">
            <?php foreach ($courseSummaries as $course): ?>
                <a class="attempt-course-card" href="attempts.php?course_id=<?= (int) $course['id'] ?>&amp;audience=all">
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
    <?php else: ?>
        <form class="attempt-filter-panel" method="get" action="attempts.php" aria-label="ตัวกรองรายชื่อผู้เรียน">
            <input type="hidden" name="course_id" value="<?= (int) $courseId ?>">
            <input type="hidden" name="audience" value="<?= e($audience) ?>">
            <div class="attempt-filter-field attempt-filter-search">
                <label for="attempt-search">ค้นหาผู้เรียน</label>
                <input id="attempt-search" type="search" name="q" value="<?= e($search) ?>" placeholder="ชื่อ รหัส อีเมล หรือกลุ่มเรียน">
            </div>
            <?php if ($audience !== 'general'): ?>
                <div class="attempt-filter-field">
                    <label for="attempt-group">กลุ่มเรียน</label>
                    <select id="attempt-group" name="group">
                        <option value="">ทุกกลุ่มเรียน</option>
                        <?php foreach ($groupOptions as $value => $label): ?>
                            <option value="<?= e((string) $value) ?>" <?= $groupFilter === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="attempt-filter-field">
                    <label for="attempt-level">ระดับ</label>
                    <select id="attempt-level" name="level">
                        <option value="">ทุกระดับ</option>
                        <?php foreach ($levelOptions as $value => $label): ?>
                            <option value="<?= e((string) $value) ?>" <?= $levelFilter === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="attempt-filter-field">
                <label for="attempt-status">สถานะ</label>
                <select id="attempt-status" name="status">
                    <option value="">ทุกสถานะ</option>
                    <?php foreach ($allowedStatuses as $status): ?>
                        <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(admin_attempt_status_label($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="attempt-filter-field">
                <label for="attempt-certificate">เกียรติบัตร</label>
                <select id="attempt-certificate" name="certificate">
                    <option value="">ทั้งหมด</option>
                    <option value="issued" <?= $certificateFilter === 'issued' ? 'selected' : '' ?>>ออกแล้ว</option>
                    <option value="none" <?= $certificateFilter === 'none' ? 'selected' : '' ?>>ยังไม่มี</option>
                </select>
            </div>
            <div class="attempt-filter-actions">
                <button type="submit">กรองข้อมูล</button>
                <?php if ($hasActiveFilters): ?>
                    <a href="<?= e(admin_attempt_filter_url($courseId, $audience)) ?>">ล้างตัวกรอง</a>
                <?php endif; ?>
            </div>
        </form>

        <p class="attempt-result-summary" role="status">
            พบ <strong><?= count($attempts) ?></strong> คน<?= $hasActiveFilters ? ' ตามตัวกรองที่เลือก' : '' ?>
        </p>

        <?php if ($audience === 'student'): ?>
            <div class="space-y-5">
                <?php foreach ($studentGroups as $groupName => $groupAttempts): ?>
                    <section class="attempt-group-panel">
                        <div class="attempt-group-header">
                            <div>
                                <span>กลุ่มเรียน</span>
                                <h2><?= e((string) $groupName) ?></h2>
                            </div>
                            <strong><?= count($groupAttempts) ?> คน</strong>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="attempt-table">
                                <thead>
                                    <tr>
                                        <th>ชื่อ-สกุล</th>
                                        <th>รหัสนักศึกษา</th>
                                        <th>ระดับ</th>
                                        <th>พื้นที่</th>
                                        <th>ก่อนเรียน</th>
                                        <th>หลังเรียน</th>
                                        <th>สถานะ</th>
                                        <th>เกียรติบัตร</th>
                                        <th>วันที่เริ่ม</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($groupAttempts as $attempt): ?>
                                        <tr>
                                            <td class="attempt-name-cell"><?= e(admin_attempt_learner_name($attempt)) ?></td>
                                            <td><?= e((string) ($attempt['student_id'] ?? '-')) ?></td>
                                            <td><?= e(admin_attempt_student_level($attempt)) ?></td>
                                            <td class="text-slate-500"><?= e(admin_attempt_context_text($attempt) ?: '-') ?></td>
                                            <td><?= e(format_score($attempt['pre_score'] !== null ? (int) $attempt['pre_score'] : null, $attempt['pre_total'] !== null ? (int) $attempt['pre_total'] : null)) ?></td>
                                            <td><?= e(format_score($attempt['post_score'] !== null ? (int) $attempt['post_score'] : null, $attempt['post_total'] !== null ? (int) $attempt['post_total'] : null)) ?></td>
                                            <td><span class="rounded-md px-2 py-1 text-xs font-bold <?= admin_attempt_status_class((string) $attempt['status']) ?>"><?= e(admin_attempt_status_label((string) $attempt['status'])) ?></span></td>
                                            <td><?= admin_attempt_certificate_link($attempt) ?></td>
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
                        <strong>ไม่พบรายชื่อนักศึกษา</strong>
                        <span><?= $hasActiveFilters ? 'ลองเปลี่ยนหรือล้างตัวกรองเพื่อดูรายชื่ออื่น' : 'เมื่อนักศึกษาเริ่มเรียน รายชื่อและกลุ่มเรียนจะแสดงที่นี่' ?></span>
                    </div>
                <?php endif; ?>
            </div>
        <?php elseif ($audience === 'general'): ?>
            <div class="attempt-group-panel">
                <div class="attempt-group-header">
                    <div>
                        <span>ประเภทผู้เรียน</span>
                        <h2>ประชาชนทั่วไป</h2>
                    </div>
                    <strong><?= count($attempts) ?> คน</strong>
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
                                <th>เกียรติบัตร</th>
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
                                    <td><?= admin_attempt_certificate_link($attempt) ?></td>
                                    <td class="text-slate-500"><?= e((string) $attempt['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$attempts): ?>
                                <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500"><?= $hasActiveFilters ? 'ไม่พบรายชื่อตามตัวกรองที่เลือก' : 'ยังไม่มีประชาชนทั่วไปในหลักสูตรนี้' ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="attempt-group-panel">
                <div class="attempt-group-header">
                    <div>
                        <span>ผู้เรียนในหลักสูตร</span>
                        <h2>ทุกประเภทผู้เรียน</h2>
                    </div>
                    <strong><?= count($attempts) ?> คน</strong>
                </div>
                <div class="overflow-x-auto">
                    <table class="attempt-table attempt-table-all">
                        <thead>
                            <tr>
                                <th>ชื่อ-สกุล</th>
                                <th>ประเภท</th>
                                <th>รหัส / อีเมล</th>
                                <th>กลุ่มเรียน</th>
                                <th>ระดับ</th>
                                <th>หลังเรียน</th>
                                <th>สถานะ</th>
                                <th>เกียรติบัตร</th>
                                <th>วันที่เริ่ม</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attempts as $attempt): ?>
                                <?php $attemptAudience = (string) ($attempt['user_type'] ?? 'general'); ?>
                                <tr>
                                    <td class="attempt-name-cell"><?= e(admin_attempt_learner_name($attempt)) ?></td>
                                    <td><span class="rounded-md px-2 py-1 text-xs font-bold <?= admin_attempt_audience_class($attemptAudience) ?>"><?= e(admin_attempt_audience_label($attemptAudience)) ?></span></td>
                                    <td class="text-slate-500"><?= e($attemptAudience === 'student' ? (string) ($attempt['student_id'] ?: '-') : (string) ($attempt['email'] ?: '-')) ?></td>
                                    <td><?= e($attemptAudience === 'student' ? admin_attempt_student_group($attempt) : '-') ?></td>
                                    <td><?= e($attemptAudience === 'student' ? admin_attempt_student_level($attempt) : '-') ?></td>
                                    <td><?= e(format_score($attempt['post_score'] !== null ? (int) $attempt['post_score'] : null, $attempt['post_total'] !== null ? (int) $attempt['post_total'] : null)) ?></td>
                                    <td><span class="rounded-md px-2 py-1 text-xs font-bold <?= admin_attempt_status_class((string) $attempt['status']) ?>"><?= e(admin_attempt_status_label((string) $attempt['status'])) ?></span></td>
                                    <td><?= admin_attempt_certificate_link($attempt) ?></td>
                                    <td class="text-slate-500"><?= e((string) $attempt['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$attempts): ?>
                                <tr><td colspan="9" class="px-4 py-10 text-center text-slate-500"><?= $hasActiveFilters ? 'ไม่พบรายชื่อตามตัวกรองที่เลือก' : 'ยังไม่มีผู้เรียนในหลักสูตรนี้' ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
