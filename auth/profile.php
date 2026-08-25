<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

$user = current_user();
if (!$user) {
    flash('กรุณาเข้าสู่ระบบก่อนเปิดโปรไฟล์', 'error');
    redirect('login.php');
}
if ($user['user_type'] !== 'general') {
    flash('บัญชีนักศึกษาไม่สามารถเปลี่ยนรหัสผ่านในระบบนี้ได้', 'error');
    redirect('../index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = (string) post('current_password');
    $newPassword = (string) post('new_password');
    $confirmPassword = (string) post('confirm_password');
    try {
        require_valid_csrf_token();
        if ($newPassword !== $confirmPassword) {
            throw new RuntimeException('รหัสผ่านใหม่ทั้งสองช่องไม่ตรงกัน');
        }
        change_general_user_password((int) $user['id'], $currentPassword, $newPassword);
        flash('เปลี่ยนรหัสผ่านเรียบร้อยแล้ว');
        redirect('profile.php');
    } catch (Throwable $exception) {
        flash($exception->getMessage(), 'error');
        redirect('profile.php');
    }
}

$hasPassword = !empty($user['password_hash']);
render_header('โปรไฟล์ผู้ใช้', 'learn');
?>
<section class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">
    <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <a href="../index.php" class="text-sm font-bold text-sea">กลับหน้าเรียน</a>
        <h1 class="mt-4 text-3xl font-extrabold">โปรไฟล์ผู้ใช้</h1>
        <dl class="mt-5 grid gap-3 rounded-lg bg-slate-50 p-4 text-sm sm:grid-cols-2">
            <div>
                <dt class="font-bold text-slate-500">ชื่อผู้ใช้</dt>
                <dd class="mt-1 text-slate-900"><?= e((string) $user['display_name']) ?></dd>
            </div>
            <div>
                <dt class="font-bold text-slate-500">อีเมล</dt>
                <dd class="mt-1 text-slate-900"><?= e((string) ($user['email'] ?? '-')) ?></dd>
            </div>
        </dl>

        <form method="post" class="mt-8 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div>
                <h2 class="text-xl font-extrabold">เปลี่ยนรหัสผ่าน</h2>
                <p class="mt-1 text-sm text-slate-500">
                    <?= $hasPassword ? 'กรอกรหัสผ่านเดิมเพื่อยืนยันตัวตน' : 'บัญชีนี้ยังไม่มีรหัสผ่าน คุณสามารถตั้งรหัสผ่านสำหรับเข้าสู่ระบบด้วยอีเมลได้' ?>
                </p>
            </div>
            <?php if ($hasPassword): ?>
            <div>
                <label class="block text-sm font-bold text-slate-700" for="current_password">รหัสผ่านเดิม</label>
                <input id="current_password" name="current_password" type="password" required autocomplete="current-password" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-3 text-sm focus:border-sea focus:outline-none focus:ring-4 focus:ring-teal-100">
            </div>
            <?php endif; ?>
            <div>
                <label class="block text-sm font-bold text-slate-700" for="new_password">รหัสผ่านใหม่</label>
                <input id="new_password" name="new_password" type="password" required minlength="6" autocomplete="new-password" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-3 text-sm focus:border-sea focus:outline-none focus:ring-4 focus:ring-teal-100">
            </div>
            <div>
                <label class="block text-sm font-bold text-slate-700" for="confirm_password">ยืนยันรหัสผ่านใหม่</label>
                <input id="confirm_password" name="confirm_password" type="password" required minlength="6" autocomplete="new-password" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-3 text-sm focus:border-sea focus:outline-none focus:ring-4 focus:ring-teal-100">
            </div>
            <button class="rounded-lg bg-sea px-5 py-3 text-sm font-bold text-white hover:bg-teal-700">บันทึกรหัสผ่านใหม่</button>
        </form>
    </div>
</section>
<?php render_footer(); ?>
