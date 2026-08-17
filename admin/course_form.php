<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_admin();
ensure_learning_access_columns();

$id = get_int('id');
$course = [
    'title' => '',
    'description' => '',
    'category' => default_course_category(),
    'cover_url' => '',
    'pass_percent' => 80,
    'allow_retake' => 0,
    'certificate_title' => 'เกียรติบัตรการผ่านหลักสูตร',
    'access_mode' => 'login_required',
    'is_published' => 1,
];
$courseCategories = course_categories();

if ($id > 0) {
    $stmt = db()->prepare('SELECT * FROM courses WHERE id = ?');
    $stmt->execute([$id]);
    $course = $stmt->fetch() ?: $course;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = [
            trim((string) post('title')),
            trim((string) post('description')),
            normalize_course_category((string) post('category')),
            trim((string) post('cover_url')),
            (float) post('pass_percent', 80),
            isset($_POST['allow_retake']) ? 1 : 0,
            trim((string) post('certificate_title')),
            normalize_course_access_mode((string) post('access_mode')),
            isset($_POST['is_published']) ? 1 : 0,
        ];

        if ($id > 0) {
            $uploadedCover = save_course_cover_upload($id);
            if ($uploadedCover !== null) {
                $data[3] = $uploadedCover;
            }

            $stmt = db()->prepare(
                'UPDATE courses
                 SET title = ?, description = ?, category = ?, cover_url = ?, pass_percent = ?, allow_retake = ?, certificate_title = ?, access_mode = ?, is_published = ?
                 WHERE id = ?'
            );
            $stmt->execute([...$data, $id]);
            flash('บันทึกหลักสูตรแล้ว');
        } else {
            $stmt = db()->prepare(
                'INSERT INTO courses (title, description, category, cover_url, pass_percent, allow_retake, certificate_title, access_mode, is_published)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute($data);
            $id = (int) db()->lastInsertId();

            $uploadedCover = save_course_cover_upload($id);
            if ($uploadedCover !== null) {
                $stmt = db()->prepare('UPDATE courses SET cover_url = ? WHERE id = ?');
                $stmt->execute([$uploadedCover, $id]);
            }

            flash('เพิ่มหลักสูตรแล้ว');
        }
    } catch (Throwable $exception) {
        flash($exception->getMessage(), 'error');
        redirect($id > 0 ? 'course_form.php?id=' . $id : 'course_form.php');
    }

    redirect('index.php');
}

render_header($id > 0 ? 'แก้ไขหลักสูตร' : 'เพิ่มหลักสูตร', 'admin');
?>
<section class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
    <form method="post" enctype="multipart/form-data" class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <h1 class="text-3xl font-extrabold"><?= $id > 0 ? 'แก้ไขหลักสูตร' : 'เพิ่มหลักสูตร' ?></h1>
        <div class="mt-6 space-y-5">
            <div>
                <label class="text-sm font-bold text-slate-700" for="title">ชื่อหลักสูตร</label>
                <input id="title" name="title" required value="<?= e($course['title']) ?>" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-3 text-sm focus:border-sea focus:outline-none focus:ring-4 focus:ring-teal-100">
            </div>
            <div>
                <label class="text-sm font-bold text-slate-700" for="description">คำอธิบาย</label>
                <textarea id="description" name="description" rows="5" required class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-3 text-sm focus:border-sea focus:outline-none focus:ring-4 focus:ring-teal-100"><?= e($course['description']) ?></textarea>
            </div>
            <div>
                <label class="text-sm font-bold text-slate-700" for="category">หมวดหมู่หลักสูตร</label>
                <select id="category" name="category" required class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-3 text-sm focus:border-sea focus:outline-none focus:ring-4 focus:ring-teal-100">
                    <?php foreach ($courseCategories as $categoryValue => $categoryLabel): ?>
                        <option value="<?= e($categoryValue) ?>" <?= normalize_course_category((string) ($course['category'] ?? '')) === $categoryValue ? 'selected' : '' ?>>
                            <?= e($categoryLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label class="text-sm font-bold text-slate-700" for="pass_percent">เกณฑ์ผ่าน (%)</label>
                    <input id="pass_percent" name="pass_percent" type="number" min="0" max="100" step="0.01" value="<?= e((string) $course['pass_percent']) ?>" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-3 text-sm focus:border-sea focus:outline-none focus:ring-4 focus:ring-teal-100">
                </div>
                <div>
                    <label class="text-sm font-bold text-slate-700" for="cover_url">ภาพปก URL</label>
                    <input id="cover_url" name="cover_url" value="<?= e($course['cover_url']) ?>" placeholder="https://example.com/course-cover.jpg" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-3 text-sm focus:border-sea focus:outline-none focus:ring-4 focus:ring-teal-100">
                </div>
            </div>
            <div>
                <label class="text-sm font-bold text-slate-700" for="cover_image">หรืออัปโหลดภาพปก</label>
                <input id="cover_image" name="cover_image" type="file" accept="image/png,image/jpeg,image/webp" class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-3 text-sm">
                <p class="mt-2 text-xs text-slate-500">ไฟล์ที่อัปโหลดใหม่จะถูกใช้แทน URL ด้านบน รองรับ PNG, JPG และ WEBP</p>
                <?php if (!empty($course['cover_url'])): ?>
                    <img src="<?= e(public_upload_url((string) $course['cover_url'])) ?>" alt="ภาพปกหลักสูตรปัจจุบัน" class="mt-3 h-28 w-52 rounded-lg border border-slate-200 object-cover">
                <?php endif; ?>
            </div>
            <div>
                <label class="text-sm font-bold text-slate-700" for="certificate_title">ชื่อบนเกียรติบัตร</label>
                <input id="certificate_title" name="certificate_title" required value="<?= e($course['certificate_title']) ?>" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-3 text-sm focus:border-sea focus:outline-none focus:ring-4 focus:ring-teal-100">
            </div>
            <fieldset class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <legend class="px-1 text-sm font-extrabold text-slate-800">สิทธิ์การเข้าเรียน</legend>
                <div class="mt-2 grid gap-3 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-emerald-200 bg-white p-4 text-sm text-slate-700">
                        <input type="radio" name="access_mode" value="public" required class="mt-0.5 text-sea focus:ring-sea" <?= normalize_course_access_mode((string) ($course['access_mode'] ?? '')) === 'public' ? 'checked' : '' ?>>
                        <span><strong class="block text-slate-900">สาธารณะ</strong><small class="mt-1 block leading-5 text-slate-500">ไม่ต้องล็อกอิน ผู้เรียนกรอกชื่อ–นามสกุลเพื่อออกเกียรติบัตร</small></span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-blue-200 bg-white p-4 text-sm text-slate-700">
                        <input type="radio" name="access_mode" value="login_required" required class="mt-0.5 text-sea focus:ring-sea" <?= normalize_course_access_mode((string) ($course['access_mode'] ?? '')) === 'login_required' ? 'checked' : '' ?>>
                        <span><strong class="block text-slate-900">ต้องเข้าสู่ระบบ</strong><small class="mt-1 block leading-5 text-slate-500">เฉพาะผู้มีบัญชีหรือนักศึกษาที่ผ่านการเข้าสู่ระบบ</small></span>
                    </label>
                </div>
            </fieldset>
            <label class="flex items-center gap-3 text-sm font-bold text-slate-700">
                <input type="checkbox" name="is_published" value="1" class="rounded text-sea focus:ring-sea" <?= (int) $course['is_published'] === 1 ? 'checked' : '' ?>>
                เปิดเผยแพร่หลักสูตร
            </label>
            <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm font-bold text-slate-700">
                <input type="checkbox" name="allow_retake" value="1" class="mt-0.5 rounded text-sea focus:ring-sea" <?= (int) $course['allow_retake'] === 1 ? 'checked' : '' ?>>
                <span>
                    อนุญาตให้เรียนซ้ำ
                    <small class="mt-1 block font-medium leading-5 text-slate-500">ผู้เรียนที่เคยผ่านแล้วเริ่มเรียนใหม่ได้ แต่ระบบจะใช้เกียรติบัตรใบเดิมและไม่ออกซ้ำ</small>
                </span>
            </label>
        </div>
        <div class="mt-6 flex flex-wrap gap-3">
            <button class="rounded-lg bg-sea px-5 py-3 text-sm font-bold text-white hover:bg-teal-700">บันทึก</button>
            <a href="index.php" class="rounded-lg border border-slate-300 px-5 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50">ยกเลิก</a>
        </div>
    </form>
</section>
<?php render_footer(); ?>
