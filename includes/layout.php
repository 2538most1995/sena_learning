<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function render_header(string $title, string $active = ''): void
{
    $flash = flash();
    $base = app_base_url();
    $isAdminArea = $active === 'admin';
    global $senaLayoutIsAdminArea;
    $senaLayoutIsAdminArea = $isAdminArea;
    $adminUser = $isAdminArea ? current_admin_user() : null;
    $currentUser = $isAdminArea ? null : current_user();
    $homeUrl = $isAdminArea && $adminUser ? $base . '/admin/index.php' : $base . '/index.php';
    $cssVersion = (string) filemtime(__DIR__ . '/../assets/css/app.css');
    ?>
    <!doctype html>
    <html lang="th">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title><?= e($title) ?> | <?= APP_NAME ?></title>
        <link rel="icon" href="<?= e($base) ?>/favicon.ico" sizes="any">
        <link rel="icon" type="image/png" sizes="32x32" href="<?= e($base) ?>/assets/images/favicon-32x32.png">
        <link rel="apple-touch-icon" href="<?= e($base) ?>/assets/images/apple-touch-icon.png">
        <script src="https://cdn.tailwindcss.com"></script>
        <script>
            tailwind.config = {
                theme: {
                    extend: {
                        fontFamily: { sans: ['"Noto Sans Thai"', 'Inter', 'ui-sans-serif', 'system-ui'] },
                        colors: {
                            ink: '#102033',
                            sea: '#0F766E',
                            mint: '#10B981',
                            sun: '#F59E0B'
                        },
                        boxShadow: {
                            soft: '0 18px 60px rgba(16, 32, 51, 0.10)'
                        }
                    }
                }
            }
        </script>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="<?= e($base) ?>/assets/css/app.css?v=<?= e($cssVersion) ?>">
    </head>
    <body class="<?= $isAdminArea ? 'admin-area' : 'public-area' ?> min-h-screen bg-slate-50 text-ink antialiased">
        <a class="skip-link" href="#main-content">ข้ามไปยังเนื้อหา</a>
        <header class="site-header <?= $isAdminArea ? 'site-header-admin' : 'site-header-public' ?> sticky top-0 z-40 bg-[#00324d] text-white shadow-sm">
            <div class="site-header-inner mx-auto flex max-w-[1500px] items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
                <a href="<?= e($homeUrl) ?>" class="site-brand flex min-w-0 items-center gap-3">
                    <img src="<?= e($base) ?>/assets/images/sena-learning-logo.png" alt="" class="site-logo">
                    <span class="site-brand-copy">
                        <span class="site-brand-name block text-xl font-extrabold tracking-normal"><?= APP_NAME ?></span>
                        <span class="site-brand-tagline block text-xs font-medium text-cyan-100"><?= APP_TAGLINE ?></span>
                    </span>
                </a>
                <nav class="site-header-nav flex shrink-0 items-center gap-1 text-xs font-semibold sm:gap-2 sm:text-sm">
                    <?php if (!$isAdminArea): ?>
                        <a class="nav-link <?= $active === 'learn' ? 'nav-active' : '' ?>" href="<?= e($base) ?>/index.php">หน้าเรียน</a>
                    <?php elseif ($adminUser): ?>
                        <a class="nav-link nav-active" href="<?= e($base) ?>/admin/index.php">หลังบ้าน</a>
                    <?php endif; ?>

                    <?php if ($currentUser): ?>
                    <!-- User dropdown -->
                    <div class="relative" id="user-menu-wrapper">
                        <button id="user-menu-btn"
                                onclick="var d=document.getElementById('user-dropdown');d.classList.toggle('hidden')"
                                class="flex items-center gap-2 rounded-lg border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-semibold text-white hover:bg-white/20 transition-colors">
                            <?php if (!empty($currentUser['avatar_url'])): ?>
                                <img src="<?= e((string) $currentUser['avatar_url']) ?>" alt="" class="h-6 w-6 rounded-full object-cover">
                            <?php else: ?>
                                <span class="grid h-6 w-6 place-items-center rounded-full bg-emerald-500 text-xs font-extrabold">
                                    <?= e(mb_substr((string) $currentUser['display_name'], 0, 1, 'UTF-8')) ?>
                                </span>
                            <?php endif; ?>
                            <span class="hidden max-w-[120px] truncate sm:block"><?= e((string) $currentUser['display_name']) ?></span>
                            <svg class="h-3 w-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div id="user-dropdown"
                             class="hidden absolute right-0 top-full mt-2 w-64 rounded-xl border border-slate-200 bg-white shadow-lg z-50 overflow-hidden">
                            <div class="border-b border-slate-100 px-4 py-3">
                                <p class="text-sm font-extrabold text-ink truncate"><?= e((string) $currentUser['display_name']) ?></p>
                                <?php if (!empty($currentUser['email'])): ?>
                                    <p class="text-xs text-slate-500 truncate mt-0.5"><?= e((string) $currentUser['email']) ?></p>
                                <?php endif; ?>
                                <?php if ($currentUser['user_type'] === 'student'): ?>
                                    <span class="mt-1.5 inline-block rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-bold text-blue-700">
                                        🎓 นักศึกษา ศกร.<?= !empty($currentUser['student_id']) ? ' (' . e((string) $currentUser['student_id']) . ')' : '' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="mt-1.5 inline-block rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-700">
                                        ประชาชนทั่วไป
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($currentUser['skr_class_name'])): ?>
                            <div class="border-b border-slate-100 px-4 py-2">
                                <p class="text-xs text-slate-500">กลุ่มเรียน: <span class="font-semibold text-slate-700"><?= e((string) $currentUser['skr_class_name']) ?></span></p>
                                <?php if (!empty($currentUser['skr_district_name'])): ?>
                                <p class="text-xs text-slate-500">อำเภอ: <span class="font-semibold text-slate-700"><?= e((string) $currentUser['skr_district_name']) ?></span></p>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($currentUser['user_type'] === 'general'): ?>
                            <a href="<?= e($base) ?>/auth/profile.php"
                               class="block border-b border-slate-100 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                โปรไฟล์ / เปลี่ยนรหัสผ่าน
                            </a>
                            <?php endif; ?>
                            <a href="<?= e($base) ?>/auth/logout.php"
                               class="block px-4 py-3 text-sm font-semibold text-red-600 hover:bg-red-50">
                                ออกจากระบบ
                            </a>
                        </div>
                    </div>
                    <?php elseif ($adminUser): ?>
                    <div class="flex items-center gap-2">
                        <span class="hidden max-w-[180px] truncate rounded-lg border border-white/20 bg-white/10 px-3 py-2 text-xs font-bold text-white sm:block">
                            <?= e((string) $adminUser['display_name']) ?>
                        </span>
                        <a href="<?= e($base) ?>/admin/logout.php"
                           class="rounded-lg border border-white/20 bg-white/10 px-3 py-2 text-xs font-extrabold text-white transition-colors hover:bg-white/20">
                            ออกจากหลังบ้าน
                        </a>
                    </div>
                    <?php elseif (!$isAdminArea): ?>
                    <!-- Login button -->
                    <a href="<?= e($base) ?>/auth/login.php"
                       class="rounded-lg bg-[#159750] px-3 py-1.5 text-xs font-extrabold text-white hover:bg-emerald-600 transition-colors sm:px-4">
                        เข้าสู่ระบบ
                    </a>
                    <?php else: ?>
                    <span class="hidden text-xs font-bold text-cyan-100 sm:block">สำหรับผู้ดูแลระบบ</span>
                    <?php endif; ?>
                </nav>
            </div>
        </header>
        <main id="main-content">
            <?php if ($flash): ?>
                <div class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8">
                    <div class="rounded-lg border px-4 py-3 text-sm font-semibold <?= $flash['type'] === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?>">
                        <?= e($flash['message']) ?>
                    </div>
                </div>
            <?php endif; ?>
    <?php
}

function render_footer(): void
{
    $base = app_base_url();
    global $senaLayoutIsAdminArea;
    $isAdminArea = !empty($senaLayoutIsAdminArea);
    ?>
        </main>
        <footer class="site-footer <?= $isAdminArea ? 'site-footer-admin' : 'site-footer-public' ?> mt-8 bg-[#00324d] text-white">
            <div class="mx-auto flex max-w-[1500px] flex-col gap-2 px-4 py-5 text-sm text-cyan-50 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                <p>© 2026 <?= APP_NAME ?> สงวนลิขสิทธิ์</p>
                <div class="flex flex-wrap gap-x-4 gap-y-1 sm:gap-x-6">
                    <span class="min-w-0">เรียนได้ทุกอุปกรณ์</span>
                    <span class="min-w-0">เข้าสู่ระบบก่อนเข้าเรียน</span>
                    <span class="min-w-0">ออกเกียรติบัตรเมื่อผ่านเกณฑ์</span>
                </div>
            </div>
        </footer>
        <script>
        // ปิด dropdown เมื่อคลิกข้างนอก
        document.addEventListener('click', function(e) {
            var wrapper = document.getElementById('user-menu-wrapper');
            if (wrapper && !wrapper.contains(e.target)) {
                var d = document.getElementById('user-dropdown');
                if (d) d.classList.add('hidden');
            }
        });
        </script>
    </body>
    </html>
    <?php
}

function render_empty_setup(): void
{
    render_header('ตั้งค่าระบบ', 'learn');
    ?>
    <section class="mx-auto max-w-3xl px-4 py-16 sm:px-6">
        <div class="rounded-lg border border-slate-200 bg-white p-8 shadow-soft">
            <h1 class="text-3xl font-extrabold">ยังไม่ได้ติดตั้งฐานข้อมูล</h1>
            <p class="mt-3 text-slate-600">เปิดหน้า install เพื่อสร้างฐานข้อมูล ตาราง และข้อมูลตัวอย่างสำหรับเริ่มใช้งานบน MAMP</p>
            <a href="install.php" class="mt-6 inline-flex items-center justify-center rounded-lg bg-sea px-5 py-3 text-sm font-bold text-white hover:bg-teal-700">เริ่มติดตั้งระบบ</a>
        </div>
    </section>
    <?php
    render_footer();
}
