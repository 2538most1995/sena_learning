<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) post('username'));
    $password = (string) post('password');
    $admin = login_admin_user($username, $password);
    if ($admin) {
        session_regenerate_id(true);
        $_SESSION['admin_ok'] = true;
        $_SESSION['admin_user_id'] = (int) $admin['id'];
        redirect('index.php');
    }

    flash('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง', 'error');
}

render_header('เข้าสู่หลังบ้าน', 'admin');
?>
<section class="mx-auto max-w-md px-4 py-16 sm:px-6">
    <form method="post" class="admin-login-card rounded-lg border border-slate-200 bg-white p-6 shadow-soft">
        <h1 class="text-2xl font-extrabold">เข้าสู่ระบบหลังบ้าน</h1>
        <p class="mt-2 text-sm text-slate-600">สำหรับผู้ดูแลระบบเท่านั้น กรุณากรอกชื่อผู้ใช้และรหัสผ่าน</p>
        <label class="mt-6 block text-sm font-bold text-slate-700" for="username">ชื่อผู้ใช้</label>
        <input id="username" name="username" type="text" required autocomplete="username" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-3 text-sm focus:border-sea focus:outline-none focus:ring-4 focus:ring-teal-100">
        <label class="mt-4 block text-sm font-bold text-slate-700" for="password">รหัสผ่าน</label>
        <div class="password-field mt-2">
            <input id="password" name="password" type="password" required autocomplete="current-password" class="input-field w-full rounded-lg border border-slate-300 px-3 py-3 text-sm focus:border-sea focus:outline-none focus:ring-4 focus:ring-teal-100">
            <button type="button" class="password-toggle" data-password-toggle="password" aria-label="แสดงรหัสผ่าน" aria-pressed="false">
                <svg class="password-eye password-eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="password-eye password-eye-closed" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18"/><path d="M10.7 5.2A10.4 10.4 0 0 1 12 5c6 0 9.5 7 9.5 7a17.8 17.8 0 0 1-2.5 3.5"/><path d="M6.3 6.3C3.8 8 2.5 12 2.5 12s3.5 7 9.5 7c1.6 0 3-.4 4.2-1"/></svg>
            </button>
        </div>
        <button class="mt-4 w-full rounded-lg bg-sea px-4 py-3 text-sm font-bold text-white hover:bg-teal-700">เข้าสู่หลังบ้าน</button>
    </form>
</section>
<script>
document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle || '');
        if (!input) return;
        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        button.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
        button.setAttribute('aria-label', isHidden ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
    });
});
</script>
<?php render_footer(); ?>
