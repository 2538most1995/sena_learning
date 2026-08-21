<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_admin();
ensure_admin_users_table();
ensure_users_table();
normalize_existing_user_display_names();
$currentAdmin = current_admin_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) post('action');
        if ($action === 'create_admin') {
            create_admin_user((string) post('username'), (string) post('password'), (string) post('display_name'));
            flash('เพิ่มบัญชี admin เรียบร้อยแล้ว');
        } elseif ($action === 'reset_admin_password') {
            update_admin_user_password((int) post('admin_id'), (string) post('password'));
            flash('ตั้งรหัสผ่าน admin ใหม่เรียบร้อยแล้ว');
        } elseif ($action === 'delete_admin') {
            delete_admin_user((int) post('admin_id'), (int) ($currentAdmin['id'] ?? 0));
            flash('ลบบัญชี admin เรียบร้อยแล้ว');
        } elseif ($action === 'save_user_name') {
            update_user_display_name((int) post('user_id'), (string) post('display_name'));
            flash('บันทึกชื่อผู้ใช้แล้ว');
        } elseif ($action === 'reset_user_password') {
            update_general_user_password((int) post('user_id'), (string) post('password'));
            flash('ตั้งรหัสผ่านประชาชนทั่วไปใหม่เรียบร้อยแล้ว');
        } elseif ($action === 'delete_user') {
            delete_user_account((int) post('user_id'));
            flash('ลบบัญชีผู้เรียนแล้ว โดยเก็บประวัติการเรียนเดิมไว้ในระบบ');
        } else {
            throw new RuntimeException('ไม่พบคำสั่งที่ต้องการ');
        }
    } catch (Throwable $exception) {
        flash($exception->getMessage(), 'error');
    }
    redirect('users.php');
}

$admins = db()->query('SELECT * FROM admin_users ORDER BY created_at, id')->fetchAll();
$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100, 'UTF-8');
$type = (string) ($_GET['type'] ?? '');
$groupFilter = trim((string) ($_GET['group'] ?? ''));
$levelFilter = trim((string) ($_GET['level'] ?? ''));
$progressFilter = trim((string) ($_GET['progress'] ?? ''));
$viewUserId = get_int('view_user');
if (!in_array($type, ['', 'general', 'student'], true)) {
    $type = '';
}
if (!in_array($progressFilter, ['', 'started', 'passed', 'certificate', 'not_started'], true)) {
    $progressFilter = '';
}

$groupOptions = [];
$levelOptions = [];
foreach (db()->query(
    'SELECT DISTINCT skr_class_name, skr_group_code, skr_level_name, skr_level
     FROM users
     WHERE user_type = "student"
     ORDER BY skr_class_name, skr_group_code, skr_level_name, skr_level'
)->fetchAll() as $studentOption) {
    $groupValue = trim((string) ($studentOption['skr_class_name'] ?? ''));
    if ($groupValue === '') {
        $groupValue = trim((string) ($studentOption['skr_group_code'] ?? ''));
    }
    if ($groupValue !== '') {
        $groupLabel = $groupValue;
        $groupCode = trim((string) ($studentOption['skr_group_code'] ?? ''));
        if ($groupCode !== '' && strpos($groupLabel, $groupCode) === false) {
            $groupLabel .= ' (' . $groupCode . ')';
        }
        $groupOptions[$groupValue] = $groupLabel;
    }

    $levelValue = trim((string) ($studentOption['skr_level_name'] ?? ''));
    if ($levelValue === '') {
        $levelValue = trim((string) ($studentOption['skr_level'] ?? ''));
    }
    if ($levelValue !== '') {
        $levelOptions[$levelValue] = $levelValue;
    }
}
if (!isset($groupOptions[$groupFilter])) {
    $groupFilter = '';
}
if (!isset($levelOptions[$levelFilter])) {
    $levelFilter = '';
}
if ($type === 'general') {
    $groupFilter = '';
    $levelFilter = '';
}

$conditions = [];
$params = [];
if ($search !== '') {
    $conditions[] = '(u.display_name LIKE ? OR u.email LIKE ? OR u.student_id LIKE ?
        OR u.skr_class_name LIKE ? OR u.skr_group_code LIKE ? OR u.skr_level_name LIKE ?)';
    $like = '%' . $search . '%';
    $params = [$like, $like, $like, $like, $like, $like];
}
if ($type !== '') {
    $conditions[] = 'u.user_type = ?';
    $params[] = $type;
}
if ($groupFilter !== '') {
    $conditions[] = 'COALESCE(NULLIF(TRIM(u.skr_class_name), ""), NULLIF(TRIM(u.skr_group_code), "")) = ?';
    $params[] = $groupFilter;
}
if ($levelFilter !== '') {
    $conditions[] = 'COALESCE(NULLIF(TRIM(u.skr_level_name), ""), NULLIF(TRIM(u.skr_level), "")) = ?';
    $params[] = $levelFilter;
}

$sql = 'SELECT u.*,
            COUNT(DISTINCT a.course_id) AS learned_course_count,
            COUNT(DISTINCT CASE WHEN a.status = "passed" THEN a.course_id END) AS completed_course_count,
            COUNT(DISTINCT CASE WHEN a.status = "passed" AND a.certificate_code IS NOT NULL AND a.certificate_code <> "" THEN a.course_id END) AS certificate_count,
            MAX(a.updated_at) AS latest_learning_at
        FROM users u
        LEFT JOIN attempts a ON a.user_id = u.id';
if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' GROUP BY u.id';
if ($progressFilter === 'started') {
    $sql .= ' HAVING COUNT(DISTINCT a.course_id) > 0';
} elseif ($progressFilter === 'passed') {
    $sql .= ' HAVING COUNT(DISTINCT CASE WHEN a.status = "passed" THEN a.course_id END) > 0';
} elseif ($progressFilter === 'certificate') {
    $sql .= ' HAVING COUNT(DISTINCT CASE WHEN a.status = "passed" AND a.certificate_code IS NOT NULL AND a.certificate_code <> "" THEN a.course_id END) > 0';
} elseif ($progressFilter === 'not_started') {
    $sql .= ' HAVING COUNT(DISTINCT a.course_id) = 0';
}
$sql .= ' ORDER BY learned_course_count DESC, u.created_at DESC, u.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$activeFilters = [
    'q' => $search,
    'type' => $type,
    'group' => $groupFilter,
    'level' => $levelFilter,
    'progress' => $progressFilter,
];
$hasActiveFilters = count(array_filter($activeFilters, static fn ($value): bool => (string) $value !== '')) > 0;

function admin_users_url(array $params = []): string
{
    $query = [];
    foreach ($params as $key => $value) {
        if (trim((string) $value) !== '') {
            $query[$key] = (string) $value;
        }
    }

    return 'users.php' . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
}

function admin_user_progress_status_label(string $status): string
{
    return [
        'registered' => 'ลงทะเบียน',
        'pretest_done' => 'ทำก่อนเรียนแล้ว',
        'learning' => 'กำลังเรียน',
        'posttest_done' => 'ทำหลังเรียนแล้ว',
        'passed' => 'จบและผ่าน',
    ][$status] ?? $status;
}

function admin_user_progress_status_class(string $status): string
{
    return $status === 'passed'
        ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100'
        : 'bg-slate-100 text-slate-700 ring-1 ring-slate-200';
}

$selectedUser = null;
$selectedUserCourses = [];
if ($viewUserId > 0) {
    $selectedUserStmt = db()->prepare(
        'SELECT u.*,
                COUNT(DISTINCT a.course_id) AS learned_course_count,
                COUNT(DISTINCT CASE WHEN a.status = "passed" THEN a.course_id END) AS completed_course_count,
                COUNT(DISTINCT CASE WHEN a.status = "passed" AND a.certificate_code IS NOT NULL AND a.certificate_code <> "" THEN a.course_id END) AS certificate_count
         FROM users u
         LEFT JOIN attempts a ON a.user_id = u.id
         WHERE u.id = ?
         GROUP BY u.id'
    );
    $selectedUserStmt->execute([$viewUserId]);
    $selectedUser = $selectedUserStmt->fetch() ?: null;

    if ($selectedUser) {
        $selectedCoursesStmt = db()->prepare(
            'SELECT a.*, c.title AS course_title, c.category AS course_category
             FROM attempts a
             INNER JOIN courses c ON c.id = a.course_id
             WHERE a.user_id = ?
             ORDER BY c.title ASC,
                CASE
                    WHEN a.status = "passed" AND a.certificate_code IS NOT NULL AND a.certificate_code <> "" THEN 3
                    WHEN a.status = "passed" THEN 2
                    ELSE 1
                END DESC,
                a.updated_at DESC,
                a.id DESC'
        );
        $selectedCoursesStmt->execute([$viewUserId]);
        foreach ($selectedCoursesStmt->fetchAll() as $courseAttempt) {
            $courseId = (int) $courseAttempt['course_id'];
            if (!isset($selectedUserCourses[$courseId])) {
                $selectedUserCourses[$courseId] = $courseAttempt;
            }
        }
    }
}

render_header('จัดการผู้ใช้', 'admin');
?>
<section class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    <div>
        <a href="index.php" class="text-sm font-bold text-sea">กลับหลังบ้าน</a>
        <h1 class="mt-3 text-3xl font-extrabold">จัดการผู้ใช้</h1>
        <p class="mt-2 text-sm text-slate-600">จัดการบัญชี admin และบัญชีผู้เรียน รหัสผ่านเก็บแบบเข้ารหัส จึงไม่สามารถแสดงรหัสเดิมได้ แต่สามารถตั้งรหัสใหม่ได้ทันที</p>
    </div>

    <div class="mt-8 grid gap-5 lg:grid-cols-[360px_1fr]">
        <form method="post" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <input type="hidden" name="action" value="create_admin">
            <h2 class="text-xl font-extrabold">เพิ่มบัญชี admin</h2>
            <p class="mt-1 text-xs text-slate-500">ชื่อผู้ใช้ใช้ตัวอักษรอังกฤษ ตัวเลข จุด ขีด หรือขีดล่าง รหัสผ่านขั้นต่ำ 8 ตัวอักษร</p>
            <label class="mt-4 block text-sm font-bold text-slate-700" for="admin_display_name">ชื่อแสดงผล</label>
            <input id="admin_display_name" name="display_name" required class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <label class="mt-3 block text-sm font-bold text-slate-700" for="admin_username">ชื่อผู้ใช้</label>
            <input id="admin_username" name="username" required autocomplete="off" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <label class="mt-3 block text-sm font-bold text-slate-700" for="admin_password">รหัสผ่านเริ่มต้น</label>
            <input id="admin_password" name="password" type="password" required minlength="8" autocomplete="new-password" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <button class="mt-4 w-full rounded-lg bg-sea px-4 py-2.5 text-sm font-bold text-white hover:bg-teal-700">เพิ่ม admin</button>
        </form>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-xl font-extrabold">บัญชี admin</h2>
                <p class="mt-1 text-xs text-slate-500">บัญชีที่กำลังใช้งานอยู่จะไม่สามารถลบตัวเองได้</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-extrabold uppercase tracking-wide text-slate-500">
                        <tr><th class="px-4 py-3">บัญชี</th><th class="px-4 py-3">เข้าสู่ระบบล่าสุด</th><th class="px-4 py-3">ตั้งรหัสใหม่</th><th class="px-4 py-3"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($admins as $admin): ?>
                        <tr class="align-top">
                            <td class="px-4 py-4">
                                <strong class="block"><?= e((string) $admin['display_name']) ?></strong>
                                <span class="text-xs text-slate-500"><?= e((string) $admin['username']) ?></span>
                                <?php if ((int) $admin['id'] === (int) ($currentAdmin['id'] ?? 0)): ?><span class="ml-1 rounded-full bg-teal-100 px-2 py-0.5 text-[11px] font-bold text-teal-700">กำลังใช้งาน</span><?php endif; ?>
                            </td>
                            <td class="px-4 py-4 text-slate-500"><?= e((string) ($admin['last_login_at'] ?? '-')) ?></td>
                            <td class="px-4 py-4">
                                <form method="post" class="flex min-w-[230px] gap-2">
                                    <input type="hidden" name="action" value="reset_admin_password">
                                    <input type="hidden" name="admin_id" value="<?= (int) $admin['id'] ?>">
                                    <input name="password" type="password" required minlength="8" autocomplete="new-password" placeholder="รหัสใหม่อย่างน้อย 8 ตัว" aria-label="ตั้งรหัสผ่านใหม่ให้บัญชี <?= e((string) $admin['username']) ?>" class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-xs">
                                    <button class="rounded-lg border border-sea px-3 py-2 text-xs font-bold text-sea hover:bg-teal-50">บันทึก</button>
                                </form>
                            </td>
                            <td class="px-4 py-4">
                                <?php if ((int) $admin['id'] !== (int) ($currentAdmin['id'] ?? 0)): ?>
                                <form method="post" onsubmit="return confirm('ลบบัญชี admin นี้หรือไม่?')">
                                    <input type="hidden" name="action" value="delete_admin">
                                    <input type="hidden" name="admin_id" value="<?= (int) $admin['id'] ?>">
                                    <button class="text-xs font-bold text-red-600 hover:underline">ลบ</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="learner-list" class="mb-4 mt-10">
        <h2 class="text-2xl font-extrabold">บัญชีผู้เรียน</h2>
        <p class="mt-1 text-sm text-slate-600">ค้นหารายบุคคล ดูจำนวนหลักสูตรที่เริ่มเรียน จำนวนที่จบและผ่าน รวมถึงเกียรติบัตรที่ได้รับ</p>
    </div>

    <form method="get" class="attempt-filter-panel admin-user-filter-panel" aria-label="ตัวกรองผู้เรียนรายบุคคล">
        <div class="attempt-filter-field attempt-filter-search">
            <label for="user-search">ค้นหาผู้เรียน</label>
            <input id="user-search" type="search" name="q" value="<?= e($search) ?>" placeholder="ชื่อ อีเมล รหัส หรือกลุ่มเรียน">
        </div>
        <div class="attempt-filter-field">
            <label for="user-type">ประเภท</label>
            <select id="user-type" name="type">
                <option value="">ทุกประเภท</option>
                <option value="general" <?= $type === 'general' ? 'selected' : '' ?>>ประชาชนทั่วไป</option>
                <option value="student" <?= $type === 'student' ? 'selected' : '' ?>>นักศึกษา ศกร.</option>
            </select>
        </div>
        <div class="attempt-filter-field">
            <label for="user-group">กลุ่มเรียน</label>
            <select id="user-group" name="group">
                <option value="">ทุกกลุ่มเรียน</option>
                <?php foreach ($groupOptions as $value => $label): ?>
                    <option value="<?= e((string) $value) ?>" <?= $groupFilter === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="attempt-filter-field">
            <label for="user-level">ระดับ</label>
            <select id="user-level" name="level">
                <option value="">ทุกระดับ</option>
                <?php foreach ($levelOptions as $value => $label): ?>
                    <option value="<?= e((string) $value) ?>" <?= $levelFilter === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="attempt-filter-field">
            <label for="user-progress">ผลการเรียน</label>
            <select id="user-progress" name="progress">
                <option value="">ทั้งหมด</option>
                <option value="started" <?= $progressFilter === 'started' ? 'selected' : '' ?>>เริ่มเรียนแล้ว</option>
                <option value="passed" <?= $progressFilter === 'passed' ? 'selected' : '' ?>>จบและผ่านแล้ว</option>
                <option value="certificate" <?= $progressFilter === 'certificate' ? 'selected' : '' ?>>มีเกียรติบัตร</option>
                <option value="not_started" <?= $progressFilter === 'not_started' ? 'selected' : '' ?>>ยังไม่เริ่มเรียน</option>
            </select>
        </div>
        <div class="attempt-filter-actions">
            <button type="submit">กรองข้อมูล</button>
            <?php if ($hasActiveFilters): ?><a href="users.php#learner-list">ล้างตัวกรอง</a><?php endif; ?>
        </div>
    </form>

    <p class="admin-user-result-summary" role="status">พบ <strong><?= count($users) ?></strong> คน<?= $hasActiveFilters ? ' ตามตัวกรองที่เลือก' : '' ?></p>

    <?php if ($selectedUser): ?>
        <section id="learner-detail" class="admin-user-detail-card" aria-labelledby="learner-detail-heading">
            <div class="admin-user-detail-header">
                <div>
                    <span class="admin-user-detail-kicker">ประวัติการเรียนรายบุคคล</span>
                    <h2 id="learner-detail-heading"><?= e((string) $selectedUser['display_name']) ?></h2>
                    <p>
                        <?= $selectedUser['user_type'] === 'student' ? 'นักศึกษา ศกร.' : 'ประชาชนทั่วไป' ?>
                        <?php if (!empty($selectedUser['student_id'])): ?> · รหัส <?= e((string) $selectedUser['student_id']) ?><?php endif; ?>
                        <?php if (!empty($selectedUser['email'])): ?> · <?= e((string) $selectedUser['email']) ?><?php endif; ?>
                    </p>
                    <?php if (!empty($selectedUser['skr_class_name']) || !empty($selectedUser['skr_level_name'])): ?>
                        <p>กลุ่มเรียน <?= e((string) ($selectedUser['skr_class_name'] ?: '-')) ?> · ระดับ <?= e((string) ($selectedUser['skr_level_name'] ?: '-')) ?></p>
                    <?php endif; ?>
                </div>
                <a href="<?= e(admin_users_url($activeFilters)) ?>#learner-list">ปิดรายละเอียด</a>
            </div>
            <div class="admin-user-detail-metrics" aria-label="สรุปผลการเรียน">
                <div><span>หลักสูตรที่เริ่ม</span><strong><?= (int) $selectedUser['learned_course_count'] ?></strong><small>หลักสูตรไม่ซ้ำ</small></div>
                <div><span>จบและผ่าน</span><strong><?= (int) $selectedUser['completed_course_count'] ?></strong><small>ผ่านตามเกณฑ์</small></div>
                <div><span>เกียรติบัตร</span><strong><?= (int) $selectedUser['certificate_count'] ?></strong><small>ใบที่เปิดดูได้</small></div>
            </div>
            <?php if ($selectedUserCourses): ?>
                <div class="overflow-x-auto">
                    <table class="attempt-table admin-user-course-table">
                        <thead>
                            <tr><th>หลักสูตร</th><th>สถานะ</th><th>คะแนนหลังเรียน</th><th>อัปเดตล่าสุด</th><th>เกียรติบัตร</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($selectedUserCourses as $courseAttempt): ?>
                                <?php
                                $certificateCode = trim((string) ($courseAttempt['certificate_code'] ?? ''));
                                $hasCertificate = (string) $courseAttempt['status'] === 'passed' && $certificateCode !== '';
                                ?>
                                <tr>
                                    <td class="attempt-name-cell">
                                        <span class="admin-user-course-category"><?= e(course_category_label((string) ($courseAttempt['course_category'] ?? ''))) ?></span>
                                        <?= e((string) $courseAttempt['course_title']) ?>
                                    </td>
                                    <td><span class="rounded-md px-2 py-1 text-xs font-bold <?= admin_user_progress_status_class((string) $courseAttempt['status']) ?>"><?= e(admin_user_progress_status_label((string) $courseAttempt['status'])) ?></span></td>
                                    <td><?= e(format_score($courseAttempt['post_score'] !== null ? (int) $courseAttempt['post_score'] : null, $courseAttempt['post_total'] !== null ? (int) $courseAttempt['post_total'] : null)) ?></td>
                                    <td class="text-slate-500"><?= e((string) $courseAttempt['updated_at']) ?></td>
                                    <td>
                                        <?php if ($hasCertificate): ?>
                                            <a class="attempt-certificate-link" href="../certificate_view.php?code=<?= e(rawurlencode($certificateCode)) ?>" target="_blank" rel="noopener" aria-label="ดูเกียรติบัตรหลักสูตร <?= e((string) $courseAttempt['course_title']) ?> ของ <?= e((string) $selectedUser['display_name']) ?> (เปิดแท็บใหม่)">ดูเกียรติบัตร</a>
                                        <?php else: ?>
                                            <span class="attempt-certificate-empty">ยังไม่มี</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="attempt-empty-state admin-user-detail-empty"><strong>ยังไม่เริ่มเรียนหลักสูตร</strong><span>เมื่อผู้เรียนเริ่มหลักสูตร ประวัติจะปรากฏในส่วนนี้</span></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <div class="admin-user-list-panel">
        <div class="overflow-x-auto">
            <table class="admin-user-list-table">
                <thead>
                    <tr><th>ชื่อผู้เรียน</th><th>ประเภท / บัญชี</th><th>กลุ่มเรียน / ระดับ</th><th>สรุปการเรียน</th><th>จัดการรหัสผ่าน</th><th>จัดการ</th></tr>
                </thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td>
                            <form method="post" class="admin-user-name-form">
                                <input type="hidden" name="action" value="save_user_name">
                                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                <input name="display_name" required value="<?= e((string) $user['display_name']) ?>" aria-label="ชื่อผู้เรียน <?= e((string) $user['display_name']) ?>">
                                <button>บันทึกชื่อ</button>
                            </form>
                        </td>
                        <td>
                            <span class="rounded-full px-2.5 py-1 text-xs font-bold <?= $user['user_type'] === 'student' ? 'bg-blue-100 text-blue-700' : 'bg-emerald-100 text-emerald-700' ?>"><?= $user['user_type'] === 'student' ? 'นักศึกษา ศกร.' : 'ประชาชนทั่วไป' ?></span>
                            <?php if (!empty($user['email'])): ?><span class="admin-user-account-line"><?= e((string) $user['email']) ?></span><?php endif; ?>
                            <?php if (!empty($user['student_id'])): ?><span class="admin-user-account-line">รหัส <?= e((string) $user['student_id']) ?></span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($user['user_type'] === 'student'): ?>
                                <strong class="admin-user-group-name"><?= e((string) ($user['skr_class_name'] ?: 'ไม่ระบุกลุ่มเรียน')) ?></strong>
                                <span class="admin-user-account-line"><?= e((string) ($user['skr_level_name'] ?: 'ไม่ระบุระดับ')) ?></span>
                            <?php else: ?>
                                <span class="text-slate-400">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="admin-user-learning-summary">
                                <span><strong><?= (int) $user['learned_course_count'] ?></strong> เริ่มเรียน</span>
                                <span><strong><?= (int) $user['completed_course_count'] ?></strong> จบและผ่าน</span>
                                <span><strong><?= (int) $user['certificate_count'] ?></strong> เกียรติบัตร</span>
                            </div>
                            <a class="admin-user-detail-link" href="<?= e(admin_users_url(array_merge($activeFilters, ['view_user' => (int) $user['id']]))) ?>#learner-detail">ดูประวัติรายคน</a>
                        </td>
                        <td>
                            <?php if ($user['user_type'] === 'general'): ?>
                                <form method="post" class="admin-user-password-form">
                                    <input type="hidden" name="action" value="reset_user_password">
                                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                    <input name="password" type="password" required minlength="6" autocomplete="new-password" placeholder="รหัสใหม่อย่างน้อย 6 ตัว" aria-label="ตั้งรหัสผ่านใหม่ให้ <?= e((string) $user['display_name']) ?>">
                                    <button>บันทึก</button>
                                </form>
                            <?php else: ?>
                                <span class="text-xs text-slate-400">จัดการจากระบบ ศกร.</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" onsubmit="return confirm('ลบบัญชีผู้ใช้นี้หรือไม่? ประวัติการเรียนเดิมจะยังคงอยู่')">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                <button class="admin-user-delete-button">ลบ</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?><tr><td colspan="6" class="admin-user-empty-row">ไม่พบผู้เรียนตามตัวกรองที่เลือก</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php render_footer(); ?>
