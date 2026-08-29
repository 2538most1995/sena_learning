<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

ensure_users_table();

if (current_user()) {
    redirect(post_login_redirect_path());
}

$error = flash();
$old   = $_SESSION['register_old'] ?? [];
unset($_SESSION['register_old']);

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_valid_csrf_token();
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
        redirect('register.php');
    }
    $name     = trim((string) post('display_name'));
    $email    = trim((string) post('email'));
    $password = (string) post('password');
    $confirm  = (string) post('confirm_password');

    if ($password !== $confirm) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['register_old'] = ['display_name' => $name, 'email' => $email];
        flash('รหัสผ่านทั้งสองช่องไม่ตรงกัน', 'error');
        redirect('register.php');
    }

    try {
        $user = register_general_user($email, $password, $name);
        login_user($user);
        flash('สมัครสมาชิกและเข้าสู่ระบบสำเร็จ ยินดีต้อนรับ ' . $user['display_name']);
        redirect(post_login_redirect_path());
    } catch (RuntimeException $e) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['register_old'] = ['display_name' => $name, 'email' => $email];
        flash($e->getMessage(), 'error');
        redirect('register.php');
    }
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>สมัครสมาชิก | <?= APP_NAME ?></title>
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

    <a href="<?= e(app_base_url()) ?>/index.php" class="auth-site-brand mb-8 flex items-center justify-center gap-3 text-white">
        <img src="<?= e(app_base_url()) ?>/assets/images/sena-learning-logo.png" alt="" class="auth-site-logo">
        <span class="auth-site-brand-copy">
            <span class="auth-site-brand-name block text-2xl font-extrabold"><?= APP_NAME ?></span>
            <span class="auth-site-brand-tagline block text-sm text-cyan-200"><?= APP_TAGLINE ?></span>
        </span>
    </a>

    <div class="auth-card rounded-2xl shadow-2xl p-6 space-y-5">
        <div>
            <h1 class="text-xl font-extrabold text-ink">สมัครสมาชิก</h1>
            <p class="text-sm text-slate-500 mt-1">สำหรับประชาชนทั่วไป</p>
        </div>

        <?php if ($error): ?>
        <div role="<?= $error['type'] === 'error' ? 'alert' : 'status' ?>" class="rounded-lg border <?= $error['type'] === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?> px-4 py-3 text-sm font-semibold">
            <?= e($error['message']) ?>
        </div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1.5" for="reg-name">
                    ชื่อ-นามสกุล <span class="text-red-500">*</span>
                </label>
                <input id="reg-name" name="display_name" type="text" required
                       class="input-field"
                       autocomplete="name" aria-describedby="reg-name-help"
                       placeholder="เช่น นางสาวอารีย์ รักการเรียน"
                       value="<?= e((string) ($old['display_name'] ?? '')) ?>">
                <p id="reg-name-help" class="mt-1 text-xs text-slate-500">ชื่อที่จะแสดงในเกียรติบัตร</p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1.5" for="reg-email">
                    อีเมล <span class="text-red-500">*</span>
                </label>
                <input id="reg-email" name="email" type="email" required
                       class="input-field" placeholder="your@email.com"
                       value="<?= e((string) ($old['email'] ?? '')) ?>"
                       autocomplete="email">
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1.5" for="reg-password">
                    รหัสผ่าน <span class="text-red-500">*</span>
                </label>
                <input id="reg-password" name="password" type="password" required
                       class="input-field" placeholder="อย่างน้อย 6 ตัวอักษร"
                       autocomplete="new-password" minlength="6" aria-describedby="strength-text"
                       oninput="checkStrength(this.value)">
                <div class="mt-2 flex gap-1">
                    <div id="s1" class="strength-bar flex-1 bg-slate-200"></div>
                    <div id="s2" class="strength-bar flex-1 bg-slate-200"></div>
                    <div id="s3" class="strength-bar flex-1 bg-slate-200"></div>
                </div>
                <p id="strength-text" class="mt-1 text-xs text-slate-500" aria-live="polite"></p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1.5" for="reg-confirm">
                    ยืนยันรหัสผ่าน <span class="text-red-500">*</span>
                </label>
                <input id="reg-confirm" name="confirm_password" type="password" required
                       class="input-field" placeholder="พิมพ์รหัสผ่านอีกครั้ง"
                       autocomplete="new-password">
            </div>
            <button type="submit" class="btn-primary">สมัครสมาชิก</button>
        </form>

        <p class="text-center text-sm text-slate-500">
            มีบัญชีแล้ว?
            <a href="login.php" class="font-semibold text-[#00324d] hover:underline">เข้าสู่ระบบ</a>
        </p>
    </div>

    <p class="mt-6 text-center text-sm text-white/70">
        <a href="<?= e(app_base_url()) ?>/index.php" class="hover:text-white">← กลับหน้าหลัก</a>
    </p>
</div>

<script>
function checkStrength(val) {
    const bars = [document.getElementById('s1'), document.getElementById('s2'), document.getElementById('s3')];
    const text = document.getElementById('strength-text');
    const len = val.length;
    let score = 0;
    if (len >= 6) score++;
    if (len >= 10) score++;
    if (/[A-Z]/.test(val) || /\d/.test(val) || /[^a-zA-Z0-9]/.test(val)) score++;
    const colors = ['bg-red-400', 'bg-amber-400', 'bg-emerald-500'];
    const labels = ['', 'อ่อน', 'ปานกลาง', 'แข็งแรง'];
    bars.forEach((b, i) => {
        b.className = 'strength-bar flex-1 ' + (i < score ? colors[score - 1] : 'bg-slate-200');
    });
    text.textContent = labels[score] || '';
    text.className = 'mt-1 text-xs ' + (['', 'text-red-500', 'text-amber-500', 'text-emerald-600'][score] || 'text-slate-400');
}
</script>
</body>
</html>
