<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

ensure_users_table();

$user = current_user();
if ($user) {
    // ถ้าล็อกอินอยู่แล้ว ไปหน้าแรก
    redirect('../index.php');
}

$tab   = (string) ($_GET['tab'] ?? 'general');
$error = flash();
$googleEnabled = GOOGLE_CLIENT_ID !== '' && GOOGLE_CLIENT_SECRET !== '';
$lineEnabled = LINE_CHANNEL_ID !== '' && LINE_CHANNEL_SECRET !== '';
$socialLoginEnabled = $googleEnabled || $lineEnabled;
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>เข้าสู่ระบบ | <?= APP_NAME ?></title>
    <link rel="icon" href="<?= e(app_base_url()) ?>/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(app_base_url()) ?>/assets/images/favicon-32x32.png">
    <link rel="apple-touch-icon" href="<?= e(app_base_url()) ?>/assets/images/apple-touch-icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['"Noto Sans Thai"', 'Inter', 'ui-sans-serif', 'system-ui'] },
                    colors: { ink: '#102033', sea: '#0F766E' }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(app_base_url()) ?>/assets/css/app.css?v=<?= filemtime(__DIR__ . '/../assets/css/app.css') ?>">
</head>
<body class="auth-page min-h-screen flex items-center justify-center px-4 py-10">

<div class="w-full max-w-md">

    <!-- Logo -->
    <a href="<?= e(app_base_url()) ?>/index.php" class="auth-site-brand mb-8 flex items-center justify-center gap-3 text-white">
        <img src="<?= e(app_base_url()) ?>/assets/images/sena-learning-logo.png" alt="" class="auth-site-logo">
        <span class="auth-site-brand-copy">
            <span class="auth-site-brand-name block text-2xl font-extrabold"><?= APP_NAME ?></span>
            <span class="auth-site-brand-tagline block text-sm text-cyan-200"><?= APP_TAGLINE ?></span>
        </span>
    </a>

    <div class="auth-card rounded-2xl shadow-2xl overflow-hidden">

        <!-- Header tabs -->
        <div class="p-6 pb-0">
            <h1 class="text-xl font-extrabold text-ink mb-1">เข้าสู่ระบบ</h1>
            <p class="text-sm text-slate-500 mb-5">เลือกประเภทผู้ใช้งาน</p>

            <div class="flex gap-2 p-1 bg-slate-100 rounded-lg">
                <a href="?tab=general"
                   id="tab-general"
                   class="tab-btn flex-1 text-center text-sm font-semibold py-2 rounded-md <?= $tab === 'general' ? 'active' : '' ?>">
                    ประชาชนทั่วไป
                </a>
                <a href="?tab=student"
                   id="tab-student"
                   class="tab-btn flex-1 text-center text-sm font-semibold py-2 rounded-md <?= $tab === 'student' ? 'active' : '' ?>">
                    นักศึกษา ศกร.
                </a>
            </div>
        </div>

        <!-- Flash message -->
        <?php if ($error): ?>
        <div class="mx-6 mt-4 rounded-lg border <?= $error['type'] === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?> px-4 py-3 text-sm font-semibold">
            <?= e($error['message']) ?>
        </div>
        <?php endif; ?>

        <!-- ─── TAB: ประชาชนทั่วไป ─── -->
        <?php if ($tab === 'general'): ?>
        <div class="p-6 space-y-4">

            <?php if ($socialLoginEnabled): ?>
            <div class="auth-social-grid">
                <?php if ($googleEnabled): ?>
                <a href="google_start.php" class="btn-social btn-google" aria-label="เข้าสู่ระบบด้วย Google">
                    <svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                    <span>Google</span>
                </a>
                <?php endif; ?>
                <?php if ($lineEnabled): ?>
                <a href="line_start.php" class="btn-social btn-line" aria-label="เข้าสู่ระบบด้วย LINE">
                    <span class="line-bubble" aria-hidden="true">LINE</span>
                    <span>LINE</span>
                </a>
                <?php endif; ?>
            </div>
            <div class="divider">หรือเข้าสู่ระบบด้วยอีเมล</div>
            <?php endif; ?>

            <form method="post" action="do_login.php" class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5" for="email">อีเมล</label>
                    <input id="email" name="email" type="email" required
                           class="input-field" placeholder="your@email.com"
                           autocomplete="email">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5" for="password">รหัสผ่าน</label>
                    <div class="password-field">
                        <input id="password" name="password" type="password" required
                               class="input-field" placeholder="••••••••"
                               autocomplete="current-password">
                        <button type="button" class="password-toggle" data-password-toggle="password" aria-label="แสดงรหัสผ่าน" aria-pressed="false">
                            <svg class="password-eye password-eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="password-eye password-eye-closed" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18"/><path d="M10.7 5.2A10.4 10.4 0 0 1 12 5c6 0 9.5 7 9.5 7a17.8 17.8 0 0 1-2.5 3.5"/><path d="M6.3 6.3C3.8 8 2.5 12 2.5 12s3.5 7 9.5 7c1.6 0 3-.4 4.2-1"/></svg>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn-primary">เข้าสู่ระบบ</button>
            </form>

            <p class="text-center text-sm text-slate-500">
                ยังไม่มีบัญชี?
                <a href="register.php" class="font-semibold text-[#00324d] hover:underline">สมัครสมาชิก</a>
            </p>
        </div>

        <!-- ─── TAB: นักศึกษา ศกร. ─── -->
        <?php else: ?>
        <div class="p-6">
            <div class="mb-5 rounded-xl bg-blue-50 border border-blue-200 p-4">
                <p class="text-sm font-semibold text-blue-800">🎓 สำหรับนักศึกษา ศกร.</p>
                <p class="mt-1 text-xs text-blue-700">ใช้เลขบัตรประชาชนเพียงอย่างเดียว ระบบจะค้นหาข้อมูลนักศึกษา ศกร. ให้อัตโนมัติ</p>
            </div>

            <form method="post" action="skr_login.php" class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5" for="citizen_id">เลขบัตรประชาชน 13 หลัก</label>
                    <input id="citizen_id" name="citizen_id" type="text" required
                           class="input-field" placeholder="เช่น 1234567890123"
                           autocomplete="username" inputmode="numeric"
                           maxlength="13" minlength="13"
                           pattern="[0-9]{13}"
                           oninput="this.value=this.value.replace(/\D/g,'').slice(0,13)">
                    <p class="mt-1 text-xs text-slate-400">กรอกตัวเลข 13 หลัก โดยไม่ต้องใส่ขีด</p>
                </div>
                <button type="submit" class="btn-primary">เข้าสู่ระบบ</button>
            </form>

            <p class="mt-5 text-center text-xs text-slate-400">
                ข้อมูลจะถูกดึงจากระบบ ศกร. — หากไม่พบข้อมูลกรุณาติดต่อครูผู้สอน
            </p>
        </div>
        <?php endif; ?>

    </div>

    <p class="mt-6 text-center text-sm text-white/70">
        <a href="<?= e(app_base_url()) ?>/index.php" class="hover:text-white">← กลับหน้าหลัก</a>
    </p>
    <div class="mt-4 border-t border-white/20 pt-4 text-center">
        <a href="<?= e(app_base_url()) ?>/admin/login.php"
           class="inline-flex items-center justify-center rounded-lg border border-white/30 bg-white/10 px-4 py-2 text-sm font-bold text-white transition-colors hover:bg-white/20">
            เข้าสู่ระบบผู้ดูแล
        </a>
    </div>
</div>

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
</body>
</html>
