<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$installed = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        db(false)->exec(file_get_contents(__DIR__ . '/sql/schema.sql'));

        $count = db()->query('SELECT COUNT(*) FROM courses')->fetchColumn();
        if ((int) $count === 0) {
            db(false)->exec(file_get_contents(__DIR__ . '/sql/seed.sql'));
        }

        $installed = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

render_header('ติดตั้งระบบ', 'learn');
?>
<section class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
    <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-soft sm:p-8">
        <h1 class="text-3xl font-extrabold">ติดตั้ง SENA Learning</h1>
        <p class="mt-3 text-slate-600">ระบบจะสร้างฐานข้อมูล `<?= DB_NAME ?>` ตารางหลัก และข้อมูลตัวอย่างหนึ่งหลักสูตร ใช้ค่าฐานข้อมูลจาก `config/config.php`</p>

        <?php if ($installed): ?>
            <div class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">
                ติดตั้งเรียบร้อยแล้ว พร้อมเริ่มใช้งาน
            </div>
            <div class="mt-6 flex flex-wrap gap-3">
                <a href="index.php" class="rounded-lg bg-sea px-5 py-3 text-sm font-bold text-white hover:bg-teal-700">ไปหน้าเรียน</a>
                <a href="admin/index.php" class="rounded-lg border border-slate-300 px-5 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50">ไปหลังบ้าน</a>
            </div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="mt-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="post" class="mt-8">
                <button class="rounded-lg bg-sea px-5 py-3 text-sm font-bold text-white hover:bg-teal-700">สร้างฐานข้อมูลและข้อมูลตัวอย่าง</button>
            </form>
        <?php endif; ?>
    </div>
</section>
<?php render_footer(); ?>

