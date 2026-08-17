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
$search = trim((string) ($_GET['q'] ?? ''));
$type = (string) ($_GET['type'] ?? '');
$conditions = [];
$params = [];
if ($search !== '') {
    $conditions[] = '(u.display_name LIKE ? OR u.email LIKE ? OR u.student_id LIKE ?)';
    $like = '%' . $search . '%';
    $params = [$like, $like, $like];
}
if (in_array($type, ['general', 'student'], true)) {
    $conditions[] = 'u.user_type = ?';
    $params[] = $type;
}

$sql = 'SELECT u.*,
            COUNT(a.id) AS attempt_count,
            COUNT(DISTINCT CASE WHEN a.status = "passed" THEN a.course_id END) AS passed_course_count,
            COUNT(DISTINCT CASE WHEN a.certificate_code IS NOT NULL THEN a.course_id END) AS certificate_count
        FROM users u
        LEFT JOIN attempts a ON a.user_id = u.id';
if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' GROUP BY u.id ORDER BY u.created_at DESC, u.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

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
                                    <input name="password" type="password" required minlength="8" autocomplete="new-password" placeholder="รหัสใหม่อย่างน้อย 8 ตัว" class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-xs">
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

    <div class="mb-4 mt-10 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold">บัญชีผู้เรียน</h2>
            <p class="mt-1 text-sm text-slate-600">นักศึกษา ศกร. ใช้ข้อมูลจากระบบ ศกร. จึงไม่มีการแก้รหัสผ่านจากหน้านี้</p>
        </div>
        <form method="get" class="flex flex-col gap-2 sm:flex-row">
            <input name="q" value="<?= e($search) ?>" placeholder="ค้นหาชื่อ อีเมล หรือรหัสนักศึกษา" class="min-w-0 rounded-lg border border-slate-300 px-3 py-2 text-sm sm:w-72">
            <select name="type" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">ทุกประเภท</option>
                <option value="general" <?= $type === 'general' ? 'selected' : '' ?>>ประชาชนทั่วไป</option>
                <option value="student" <?= $type === 'student' ? 'selected' : '' ?>>นักศึกษา ศกร.</option>
            </select>
            <button class="rounded-lg bg-sea px-4 py-2 text-sm font-bold text-white hover:bg-teal-700">ค้นหา</button>
        </form>
    </div>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-extrabold uppercase tracking-wide text-slate-500">
                    <tr><th class="px-4 py-3">ชื่อผู้ใช้</th><th class="px-4 py-3">ประเภท / บัญชี</th><th class="px-4 py-3">การเรียน</th><th class="px-4 py-3">จัดการรหัสผ่าน</th><th class="px-4 py-3"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                <?php foreach ($users as $user): ?>
                    <tr class="align-top">
                        <td class="px-4 py-4">
                            <form method="post" class="flex min-w-[280px] gap-2">
                                <input type="hidden" name="action" value="save_user_name">
                                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                <input name="display_name" required value="<?= e((string) $user['display_name']) ?>" class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <button class="rounded-lg border border-sea px-3 py-2 text-xs font-bold text-sea hover:bg-teal-50">บันทึก</button>
                            </form>
                        </td>
                        <td class="px-4 py-4 text-slate-600">
                            <span class="rounded-full px-2.5 py-1 text-xs font-bold <?= $user['user_type'] === 'student' ? 'bg-blue-100 text-blue-700' : 'bg-emerald-100 text-emerald-700' ?>"><?= $user['user_type'] === 'student' ? 'นักศึกษา ศกร.' : 'ประชาชนทั่วไป' ?></span>
                            <?php if (!empty($user['email'])): ?><span class="mt-2 block"><?= e((string) $user['email']) ?></span><?php endif; ?>
                            <?php if (!empty($user['student_id'])): ?><span class="mt-2 block">รหัสนักศึกษา: <?= e((string) $user['student_id']) ?></span><?php endif; ?>
                        </td>
                        <td class="px-4 py-4 text-slate-600">
                            <span class="block">เข้าเรียน <?= (int) $user['attempt_count'] ?> ครั้ง</span>
                            <span class="block text-xs text-slate-500">ผ่าน <?= (int) $user['passed_course_count'] ?> วิชา · เกียรติบัตร <?= (int) $user['certificate_count'] ?> ใบ</span>
                        </td>
                        <td class="px-4 py-4">
                            <?php if ($user['user_type'] === 'general'): ?>
                            <form method="post" class="flex min-w-[240px] gap-2">
                                <input type="hidden" name="action" value="reset_user_password">
                                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                <input name="password" type="password" required minlength="6" autocomplete="new-password" placeholder="รหัสใหม่อย่างน้อย 6 ตัว" class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-xs">
                                <button class="rounded-lg border border-sea px-3 py-2 text-xs font-bold text-sea hover:bg-teal-50">บันทึก</button>
                            </form>
                            <?php else: ?>
                            <span class="text-xs text-slate-400">จัดการจากระบบ ศกร.</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-4">
                            <form method="post" onsubmit="return confirm('ลบบัญชีผู้ใช้นี้หรือไม่? ประวัติการเรียนเดิมจะยังคงอยู่')">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                <button class="text-xs font-bold text-red-600 hover:underline">ลบ</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?><tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">ไม่พบผู้ใช้ตามเงื่อนไขที่ค้นหา</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php render_footer(); ?>
