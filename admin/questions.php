<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_admin();
ensure_curriculum_tables();
ensure_public_quiz_sharing_tables();

$courseId = get_int('course_id');
$stmt = db()->prepare('SELECT * FROM courses WHERE id = ?');
$stmt->execute([$courseId]);
$course = $stmt->fetch();
if (!$course) {
    flash('ไม่พบหลักสูตร', 'error');
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_valid_csrf_token();
        $action = (string) post('action');
        if ($action === 'save_set') {
            $title = trim((string) post('title'));
            if ($title === '') {
                throw new RuntimeException('กรุณากรอกชื่อชุดข้อสอบ');
            }
            $setId = (int) post('set_id');
            if ($setId > 0) {
                db()->prepare('UPDATE quiz_sets SET title = ?, description = ?, shuffle_questions = ?, shuffle_choices = ? WHERE id = ?')
                    ->execute([$title, trim((string) post('description')), isset($_POST['shuffle_questions']) ? 1 : 0, isset($_POST['shuffle_choices']) ? 1 : 0, $setId]);
                flash('บันทึกชุดข้อสอบแล้ว');
            } else {
                db()->prepare('INSERT INTO quiz_sets (course_id, title, description, shuffle_questions, shuffle_choices) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$courseId, $title, trim((string) post('description')), isset($_POST['shuffle_questions']) ? 1 : 0, isset($_POST['shuffle_choices']) ? 1 : 0]);
                $setId = (int) db()->lastInsertId();
                flash('สร้างชุดข้อสอบกลางแล้ว');
            }
            redirect('questions.php?course_id=' . $courseId . '&set_id=' . $setId);
        }

        $setId = (int) post('set_id');
        $stmt = db()->prepare('SELECT * FROM quiz_sets WHERE id = ?');
        $stmt->execute([$setId]);
        $set = $stmt->fetch();
        if (!$set) {
            throw new RuntimeException('ไม่พบชุดข้อสอบ');
        }

        if ($action === 'save_share') {
            save_public_quiz_share($setId, [
                'public_title' => post('public_title'),
                'welcome_message' => post('welcome_message'),
                'pass_percent' => post('pass_percent'),
                'certificate_mode' => post('certificate_mode', 'course'),
                'theme' => post('theme'),
                'is_active' => isset($_POST['is_active']),
            ]);
            flash('บันทึกลิงก์แชร์แบบทดสอบแล้ว ลิงก์นี้ไม่มีวันหมดอายุ');
        } elseif ($action === 'delete_set') {
            if (public_quiz_share_for_set($setId)) {
                throw new RuntimeException('ชุดข้อสอบนี้มีลิงก์แชร์และผลสอบสาธารณะ จึงไม่สามารถลบได้ คุณสามารถปิดลิงก์ได้จากส่วนแชร์แบบทดสอบ');
            }
            $stmt = db()->prepare('SELECT COUNT(*) FROM curriculum_items WHERE quiz_set_id = ?');
            $stmt->execute([$setId]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new RuntimeException('ชุดข้อสอบนี้ถูกใช้ในลำดับการเรียน กรุณานำออกจากลำดับก่อน');
            }
            db()->prepare('DELETE FROM quiz_sets WHERE id = ?')->execute([$setId]);
            flash('ลบชุดข้อสอบแล้ว');
            redirect('questions.php?course_id=' . $courseId);
        } elseif ($action === 'delete_question') {
            $questionId = (int) post('question_id');
            $usageStmt = db()->prepare('SELECT COUNT(*) FROM quiz_set_questions WHERE question_id = ?');
            $usageStmt->execute([$questionId]);
            $usageCount = (int) $usageStmt->fetchColumn();
            db()->prepare('DELETE FROM quiz_set_questions WHERE quiz_set_id = ? AND question_id = ?')
                ->execute([$setId, $questionId]);
            if ($usageCount <= 1) {
                db()->prepare('DELETE FROM questions WHERE id = ?')->execute([$questionId]);
                flash('ลบคำถามแล้ว');
            } else {
                flash('นำคำถามออกจากชุดแล้ว');
            }
        } elseif ($action === 'import_excel') {
            if (empty($_FILES['excel_file']['tmp_name']) || !is_uploaded_file($_FILES['excel_file']['tmp_name'])) {
                throw new RuntimeException('กรุณาเลือกไฟล์ Excel');
            }
            if (strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
                throw new RuntimeException('รองรับเฉพาะไฟล์ .xlsx เท่านั้น');
            }
            $count = import_questions_from_excel($courseId, 'post', $_FILES['excel_file']['tmp_name'], $setId);
            flash('นำเข้าคำถามจาก Excel จำนวน ' . $count . ' ข้อแล้ว');
        } elseif ($action === 'import_json') {
            $count = import_questions($courseId, 'post', (string) post('json_data'), $setId);
            flash('นำเข้าคำถาม JSON จำนวน ' . $count . ' ข้อแล้ว');
        } elseif ($action === 'save_question') {
            $type = (string) post('question_type', 'single_choice');
            $allowed = ['single_choice', 'multiple_choice', 'true_false', 'short_answer'];
            $choices = array_values(array_filter(array_map('trim', explode("\n", (string) post('choices'))), fn ($value) => $value !== ''));
            $answers = array_values(array_filter(array_map('trim', explode("\n", (string) post('answers'))), fn ($value) => $value !== ''));
            if ($type === 'true_false' && !$choices) {
                $choices = ['ถูก', 'ผิด'];
            }
            if (!in_array($type, $allowed, true) || trim((string) post('prompt')) === '' || !$answers) {
                throw new RuntimeException('กรุณากรอกคำถามและเฉลยให้ครบ');
            }
            db()->prepare(
                "INSERT INTO questions (course_id, quiz_type, question_type, prompt, choices, correct_answers, explanation, sort_order)
                 VALUES (?, 'post', ?, ?, ?, ?, ?, 1)"
            )->execute([
                $courseId,
                $type,
                trim((string) post('prompt')),
                json_encode($choices, JSON_UNESCAPED_UNICODE),
                json_encode($answers, JSON_UNESCAPED_UNICODE),
                trim((string) post('explanation')),
            ]);
            add_question_to_quiz_set($setId, (int) db()->lastInsertId());
            flash('เพิ่มคำถามในชุดแล้ว');
        }
        redirect('questions.php?course_id=' . $courseId . '&set_id=' . $setId);
    } catch (Throwable $e) {
        flash($e->getMessage(), 'error');
        redirect('questions.php?course_id=' . $courseId . ((int) post('set_id') > 0 ? '&set_id=' . (int) post('set_id') : ''));
    }
}

$sets = quiz_sets($courseId);
$selectedSetId = get_int('set_id', (int) ($sets[0]['id'] ?? 0));
$selectedSet = null;
foreach ($sets as $set) {
    if ((int) $set['id'] === $selectedSetId) {
        $selectedSet = $set;
        break;
    }
}
$questions = $selectedSet ? quiz_set_questions((int) $selectedSet['id']) : [];
$selectedShare = $selectedSet ? public_quiz_share_for_set((int) $selectedSet['id']) : null;
$shareUrl = $selectedShare ? public_quiz_share_url($selectedShare) : '';
$currentCertificateMode = normalize_public_quiz_certificate_mode((string) ($selectedShare['certificate_mode'] ?? 'course'));
$quizThemes = public_quiz_themes();
$questionLabels = ['single_choice' => 'ปรนัย 1 คำตอบ', 'multiple_choice' => 'เลือกได้หลายคำตอบ', 'true_false' => 'ถูก / ผิด', 'short_answer' => 'คำตอบสั้น'];
$exampleJson = json_encode([[
    'type' => 'single_choice',
    'prompt' => 'ข้อใดคือเป้าหมายของบทเรียนนี้',
    'choices' => ['ทบทวนความเข้าใจ', 'ลบข้อมูล', 'ปิดหลักสูตร'],
    'answers' => ['ทบทวนความเข้าใจ'],
    'explanation' => 'ชุดข้อสอบใช้ทบทวนความเข้าใจของผู้เรียน',
]], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

render_header('คลังชุดข้อสอบกลาง', 'admin');
?>
<section class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <a href="lessons.php?course_id=<?= $courseId ?>" class="text-sm font-bold text-sea">← กลับไปจัดลำดับบทเรียน</a>
            <h1 class="mt-3 text-3xl font-extrabold">คลังชุดข้อสอบกลาง</h1>
            <p class="mt-2 text-slate-600"><?= e($course['title']) ?> · สร้างชุดข้อสอบครั้งเดียว แล้วเลือกใช้ซ้ำหรือใช้ร่วมกับคอร์สอื่นได้</p>
        </div>
        <a href="questions.php?course_id=<?= $courseId ?>&panel=new_set" class="rounded-lg bg-sea px-4 py-3 text-sm font-bold text-white">+ สร้างชุดข้อสอบ</a>
    </div>

    <div class="grid gap-6 lg:grid-cols-[300px_minmax(0,1fr)]">
        <aside class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-4 py-3 text-sm font-extrabold">ชุดข้อสอบทุกคอร์ส</div>
            <div class="divide-y divide-slate-100">
                <?php foreach ($sets as $set): ?>
                    <a href="questions.php?course_id=<?= $courseId ?>&set_id=<?= (int) $set['id'] ?>" class="block px-4 py-4 <?= (int) $set['id'] === $selectedSetId ? 'bg-teal-50' : 'hover:bg-slate-50' ?>">
                        <strong class="block text-sm text-slate-800"><?= e($set['title']) ?></strong>
                        <span class="mt-1 block text-xs text-slate-500"><?= (int) $set['question_count'] ?> ข้อ<?= !empty($set['course_title']) ? ' · ' . e((string) $set['course_title']) : '' ?><?= (int) ($set['usage_count'] ?? 0) > 0 ? ' · ใช้แล้ว ' . (int) $set['usage_count'] . ' จุด' : ' · พร้อมเลือกใช้' ?></span>
                    </a>
                <?php endforeach; ?>
                <?php if (!$sets): ?><p class="p-5 text-sm text-slate-500">ยังไม่มีชุดข้อสอบ</p><?php endif; ?>
            </div>
        </aside>

        <div class="space-y-6">
            <?php if ($selectedSet): ?>
                <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <form method="post" class="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_set"><input type="hidden" name="set_id" value="<?= (int) $selectedSet['id'] ?>">
                        <label class="text-sm font-bold text-slate-700">ชื่อชุดข้อสอบ<input name="title" required value="<?= e($selectedSet['title']) ?>" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-3 text-sm"></label>
                        <label class="text-sm font-bold text-slate-700">คำอธิบาย<input name="description" value="<?= e($selectedSet['description'] ?? '') ?>" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-3 text-sm"></label>
                        <div class="flex flex-wrap gap-3 pb-3 text-xs font-bold text-slate-600">
                            <label class="flex items-center gap-2"><input type="checkbox" name="shuffle_questions" value="1" <?= (int) ($selectedSet['shuffle_questions'] ?? 0) === 1 ? 'checked' : '' ?>> สลับโจทย์</label>
                            <label class="flex items-center gap-2"><input type="checkbox" name="shuffle_choices" value="1" <?= (int) $selectedSet['shuffle_choices'] === 1 ? 'checked' : '' ?>> สลับตัวเลือก</label>
                        </div>
                        <button class="rounded-lg bg-sea px-4 py-3 text-sm font-bold text-white sm:col-span-3">บันทึกข้อมูลชุดข้อสอบ</button>
                    </form>
                </div>

                <section class="quiz-share-panel overflow-hidden rounded-2xl border border-cyan-200 bg-white shadow-sm" aria-labelledby="quiz-share-heading">
                    <div class="quiz-share-panel__header px-5 py-5 text-white sm:px-6">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-cyan-100">Public quiz</p>
                                <h2 id="quiz-share-heading" class="mt-1 text-2xl font-extrabold">แชร์แบบทดสอบให้ทำได้ทันที</h2>
                                <p class="mt-2 max-w-2xl text-sm leading-6 text-cyan-50">ผู้รับลิงก์ไม่ต้องเข้าสู่ระบบ ลิงก์ไม่หมดอายุ และสามารถออกเกียรติบัตรเมื่อผ่านเกณฑ์</p>
                            </div>
                            <?php if ($selectedShare): ?>
                                <span class="inline-flex w-fit items-center gap-2 rounded-full bg-white/15 px-3 py-1.5 text-xs font-extrabold ring-1 ring-white/25">
                                    <span class="h-2 w-2 rounded-full <?= (int) $selectedShare['is_active'] === 1 ? 'bg-emerald-300' : 'bg-slate-300' ?>" aria-hidden="true"></span>
                                    <?= (int) $selectedShare['is_active'] === 1 ? 'เปิดรับคำตอบ' : 'ปิดรับคำตอบ' ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($selectedShare): ?>
                        <div class="grid gap-5 border-b border-cyan-100 bg-cyan-50/60 p-5 sm:grid-cols-[minmax(0,1fr)_auto] sm:p-6">
                            <div class="min-w-0">
                                <label for="public-share-url" class="text-sm font-extrabold text-slate-800">ลิงก์ถาวรสำหรับแชร์</label>
                                <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                                    <input id="public-share-url" value="<?= e($shareUrl) ?>" readonly class="min-w-0 flex-1 rounded-xl border border-cyan-200 bg-white px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-4 focus:ring-cyan-100">
                                    <button type="button" id="copy-public-share" class="rounded-xl bg-sea px-5 py-3 text-sm font-extrabold text-white hover:bg-teal-700 focus:outline-none focus:ring-4 focus:ring-teal-200">คัดลอกลิงก์</button>
                                    <a href="<?= e($shareUrl) ?>" target="_blank" rel="noopener" class="inline-flex justify-center rounded-xl border border-cyan-200 bg-white px-5 py-3 text-sm font-extrabold text-sea hover:bg-cyan-50 focus:outline-none focus:ring-4 focus:ring-cyan-100">เปิดดู ↗</a>
                                </div>
                                <p id="share-copy-status" class="mt-2 min-h-5 text-xs font-semibold text-teal-700" role="status" aria-live="polite"></p>
                                <div class="mt-3 flex flex-wrap gap-2 text-xs font-bold text-slate-600">
                                    <span class="rounded-full bg-white px-3 py-1.5 ring-1 ring-cyan-100">ส่งคำตอบแล้ว <?= (int) $selectedShare['attempt_count'] ?> คน</span>
                                    <span class="rounded-full bg-white px-3 py-1.5 ring-1 ring-cyan-100">ผ่านเกณฑ์ <?= (int) $selectedShare['passed_count'] ?> คน</span>
                                    <span class="rounded-full bg-white px-3 py-1.5 ring-1 ring-cyan-100">ไม่มีวันหมดอายุ</span>
                                </div>
                            </div>
                            <div class="quiz-share-qr rounded-2xl bg-white p-3 text-center shadow-sm ring-1 ring-cyan-100">
                                <div id="public-share-qr" class="mx-auto grid min-h-36 min-w-36 place-items-center" aria-label="QR Code สำหรับแบบทดสอบ"></div>
                                <button type="button" id="download-public-qr" class="mt-2 text-xs font-extrabold text-sea underline decoration-cyan-300 underline-offset-4">ดาวน์โหลด QR Code</button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="grid gap-5 p-5 sm:p-6 lg:grid-cols-2">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_share">
                        <input type="hidden" name="set_id" value="<?= (int) $selectedSet['id'] ?>">
                        <div>
                            <label for="public_title" class="text-sm font-extrabold text-slate-800">ชื่อที่ผู้ทำข้อสอบจะเห็น</label>
                            <input id="public_title" name="public_title" required maxlength="255" value="<?= e((string) ($selectedShare['public_title'] ?? $selectedSet['title'])) ?>" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-sm focus:border-sea focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label for="pass_percent" class="text-sm font-extrabold text-slate-800">เกณฑ์ผ่าน (%)</label>
                            <input id="pass_percent" name="pass_percent" type="number" min="1" max="100" step="0.01" required value="<?= e((string) ($selectedShare['pass_percent'] ?? $course['pass_percent'] ?? 80)) ?>" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-sm tabular-nums focus:border-sea focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div class="lg:col-span-2">
                            <label for="welcome_message" class="text-sm font-extrabold text-slate-800">ข้อความต้อนรับ</label>
                            <textarea id="welcome_message" name="welcome_message" rows="3" maxlength="1000" aria-describedby="welcome-message-help" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-sm focus:border-sea focus:outline-none focus:ring-4 focus:ring-teal-100"><?= e((string) ($selectedShare['welcome_message'] ?? $selectedSet['description'] ?? '')) ?></textarea>
                            <p id="welcome-message-help" class="mt-1 text-xs text-slate-500">ใช้แนะนำวัตถุประสงค์หรือคำชี้แจงก่อนเริ่มทำแบบทดสอบ</p>
                        </div>
                        <fieldset class="lg:col-span-2">
                            <legend class="text-sm font-extrabold text-slate-800">รูปแบบการตกแต่ง</legend>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                                <?php foreach ($quizThemes as $themeValue => $themeInfo): ?>
                                    <?php $currentTheme = normalize_public_quiz_theme((string) ($selectedShare['theme'] ?? 'ocean')); ?>
                                    <label class="quiz-theme-option quiz-theme-option--<?= e($themeValue) ?> cursor-pointer rounded-xl border border-slate-200 p-3 has-[:checked]:ring-2 has-[:checked]:ring-sea">
                                        <span class="flex items-center gap-2">
                                            <input type="radio" name="theme" value="<?= e($themeValue) ?>" <?= $currentTheme === $themeValue ? 'checked' : '' ?>>
                                            <strong class="text-sm text-slate-800"><?= e($themeInfo['label']) ?></strong>
                                        </span>
                                        <small class="mt-2 block leading-5 text-slate-500"><?= e($themeInfo['description']) ?></small>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                        <fieldset class="lg:col-span-2" aria-describedby="certificate-mode-help">
                            <legend class="text-sm font-extrabold text-slate-800">การออกเกียรติบัตรเมื่อผ่านเกณฑ์</legend>
                            <p id="certificate-mode-help" class="mt-1 text-xs leading-5 text-slate-500">เลือกแหล่งแม่แบบให้ตรงกับประเภทของกิจกรรม เกียรติบัตรที่ออกแล้วจะจดจำแม่แบบที่ใช้ในขณะออก</p>
                            <div class="mt-3 grid gap-3 md:grid-cols-3">
                                <label class="certificate-mode-option flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 has-[:checked]:border-sea has-[:checked]:bg-teal-50 has-[:checked]:ring-2 has-[:checked]:ring-teal-100">
                                    <input type="radio" name="certificate_mode" value="none" class="mt-1 h-4 w-4" <?= $currentCertificateMode === 'none' ? 'checked' : '' ?>>
                                    <span><strong class="block text-sm text-slate-800">ไม่ออกเกียรติบัตร</strong><small class="mt-1 block leading-5 text-slate-500">แสดงเฉพาะคะแนนและผลผ่านเกณฑ์</small></span>
                                </label>
                                <label class="certificate-mode-option flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 has-[:checked]:border-sea has-[:checked]:bg-teal-50 has-[:checked]:ring-2 has-[:checked]:ring-teal-100">
                                    <input type="radio" name="certificate_mode" value="course" class="mt-1 h-4 w-4" <?= $currentCertificateMode === 'course' ? 'checked' : '' ?>>
                                    <span><strong class="block text-sm text-slate-800">ใช้แม่แบบหลักสูตร</strong><small class="mt-1 block leading-5 text-slate-500"><?= e((string) $selectedSet['course_title']) ?></small></span>
                                </label>
                                <label class="certificate-mode-option flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 has-[:checked]:border-sea has-[:checked]:bg-teal-50 has-[:checked]:ring-2 has-[:checked]:ring-teal-100">
                                    <input type="radio" name="certificate_mode" value="custom" class="mt-1 h-4 w-4" <?= $currentCertificateMode === 'custom' ? 'checked' : '' ?>>
                                    <span><strong class="block text-sm text-slate-800">แม่แบบเฉพาะแบบทดสอบ</strong><small class="mt-1 block leading-5 text-slate-500">แยกหัวข้อ ข้อความ โลโก้ ลายเซ็น และตำแหน่งทั้งหมด</small></span>
                                </label>
                            </div>
                            <?php if ($selectedShare): ?>
                                <a href="certificate_settings.php?share_id=<?= (int) $selectedShare['id'] ?>" class="mt-3 inline-flex min-h-11 items-center justify-center rounded-xl border border-amber-300 bg-amber-50 px-5 py-2.5 text-sm font-extrabold text-amber-900 hover:bg-amber-100 focus:outline-none focus:ring-4 focus:ring-amber-100">ออกแบบเกียรติบัตรเฉพาะแบบทดสอบ ↗</a>
                            <?php else: ?>
                                <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">บันทึกเพื่อสร้างลิงก์แชร์ก่อน แล้วระบบจะแสดงปุ่มออกแบบแม่แบบเฉพาะ</p>
                            <?php endif; ?>
                        </fieldset>
                        <div class="lg:col-span-2">
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                                <input type="checkbox" name="is_active" value="1" class="mt-1 h-4 w-4" <?= !$selectedShare || (int) $selectedShare['is_active'] === 1 ? 'checked' : '' ?>>
                                <span><strong class="block text-sm text-slate-800">เปิดรับคำตอบ</strong><small class="mt-1 block leading-5 text-slate-500">ปิดได้ชั่วคราวโดยลิงก์เดิมไม่เปลี่ยน</small></span>
                            </label>
                        </div>
                        <button class="rounded-xl bg-sea px-5 py-3 text-sm font-extrabold text-white shadow-sm hover:bg-teal-700 focus:outline-none focus:ring-4 focus:ring-teal-200 lg:col-span-2"><?= $selectedShare ? 'บันทึกการตั้งค่าการแชร์' : 'สร้างลิงก์แชร์ถาวร' ?></button>
                    </form>
                </section>

                <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-200 p-4"><strong>คำถามในชุด · <?= count($questions) ?> ข้อ</strong></div>
                    <div class="divide-y divide-slate-100">
                        <?php foreach ($questions as $index => $question): ?>
                            <div class="flex gap-4 p-4">
                                <span class="text-xs font-extrabold text-slate-400"><?= $index + 1 ?></span>
                                <div class="min-w-0 flex-1"><strong class="block text-sm"><?= e($question['prompt']) ?></strong><span class="mt-1 block text-xs text-slate-500"><?= e($questionLabels[$question['question_type']] ?? $question['question_type']) ?></span></div>
                                <form method="post" onsubmit="return confirm('นำคำถามนี้ออกจากชุด?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_question"><input type="hidden" name="set_id" value="<?= (int) $selectedSet['id'] ?>"><input type="hidden" name="question_id" value="<?= (int) $question['id'] ?>"><button class="text-xs font-bold text-red-600">นำออก</button></form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="grid gap-6 xl:grid-cols-2">
                    <form method="post" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_question"><input type="hidden" name="set_id" value="<?= (int) $selectedSet['id'] ?>">
                        <h2 class="text-lg font-extrabold">เพิ่มคำถามในชุด</h2>
                        <div class="mt-4 space-y-3">
                            <select name="question_type" class="w-full rounded-lg border border-slate-300 px-3 py-3 text-sm"><?php foreach ($questionLabels as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
                            <textarea name="prompt" rows="3" required placeholder="คำถาม" class="w-full rounded-lg border border-slate-300 px-3 py-3 text-sm"></textarea>
                            <textarea name="choices" rows="4" placeholder="ตัวเลือก บรรทัดละ 1 ตัวเลือก" class="w-full rounded-lg border border-slate-300 px-3 py-3 text-sm"></textarea>
                            <textarea name="answers" rows="3" required placeholder="เฉลย บรรทัดละ 1 คำตอบ" class="w-full rounded-lg border border-slate-300 px-3 py-3 text-sm"></textarea>
                            <input name="explanation" placeholder="คำอธิบายเฉลย" class="w-full rounded-lg border border-slate-300 px-3 py-3 text-sm">
                        </div>
                        <button class="mt-4 w-full rounded-lg bg-sea px-4 py-3 text-sm font-bold text-white">เพิ่มคำถาม</button>
                    </form>

                    <div class="space-y-6">
                        <form method="post" enctype="multipart/form-data" class="rounded-lg border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="import_excel"><input type="hidden" name="set_id" value="<?= (int) $selectedSet['id'] ?>">
                            <h2 class="text-lg font-extrabold text-emerald-950">นำเข้า Excel เข้าชุดนี้</h2>
                            <p class="mt-1 text-xs leading-5 text-emerald-800">ไม่มีคอลัมน์ก่อนเรียน/หลังเรียนแล้ว เลือกชุดปลายทางจากหน้านี้โดยตรง</p>
                            <input name="excel_file" type="file" accept=".xlsx" required class="mt-4 w-full rounded-lg border border-emerald-300 bg-white px-3 py-3 text-sm">
                            <div class="mt-3 flex gap-2"><button class="rounded-lg bg-sea px-4 py-3 text-sm font-bold text-white">นำเข้า Excel</button><a href="question_template.php" class="rounded-lg border border-emerald-300 bg-white px-4 py-3 text-sm font-bold text-emerald-800">ดาวน์โหลดเทมเพลต</a></div>
                        </form>
                        <form method="post" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="import_json"><input type="hidden" name="set_id" value="<?= (int) $selectedSet['id'] ?>">
                            <h2 class="text-lg font-extrabold">นำเข้า JSON เข้าชุดนี้</h2>
                            <textarea name="json_data" rows="8" required class="mt-3 w-full rounded-lg border border-slate-300 px-3 py-3 font-mono text-xs"><?= e($exampleJson) ?></textarea>
                            <button class="mt-3 rounded-lg bg-ink px-4 py-3 text-sm font-bold text-white">นำเข้า JSON</button>
                        </form>
                        <form method="post" onsubmit="return confirm('ยืนยันการลบชุดข้อสอบนี้?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_set"><input type="hidden" name="set_id" value="<?= (int) $selectedSet['id'] ?>"><button class="text-sm font-bold text-red-600">ลบชุดข้อสอบนี้</button></form>
                    </div>
                </div>
            <?php else: ?>
                <div class="rounded-lg border border-slate-200 bg-white p-8 text-center shadow-sm"><h2 class="text-xl font-extrabold">เริ่มสร้างชุดข้อสอบกลาง</h2><p class="mt-2 text-sm text-slate-500">สร้างชุดก่อน แล้วเพิ่มคำถามหรือนำเข้า Excel ได้ทันที</p></div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php if (($_GET['panel'] ?? '') === 'new_set'): ?>
    <a class="curriculum-drawer-backdrop" href="questions.php?course_id=<?= $courseId ?>" aria-label="ปิด"></a>
    <aside class="curriculum-drawer">
        <div class="curriculum-drawer-header"><div><span>คลังข้อสอบกลาง</span><h2>สร้างชุดข้อสอบ</h2></div><a href="questions.php?course_id=<?= $courseId ?>">×</a></div>
        <form method="post" class="curriculum-drawer-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_set"><label>ชื่อชุดข้อสอบ<input name="title" required placeholder="เช่น แบบทดสอบท้ายส่วนที่ 1"></label><label>คำอธิบาย<textarea name="description" rows="4"></textarea></label><label class="curriculum-form-check"><input type="checkbox" name="shuffle_questions" value="1"><span><strong>สลับลำดับโจทย์</strong><small>สุ่มลำดับคำถามใหม่เมื่อผู้เรียนเปิดชุดข้อสอบ</small></span></label><label class="curriculum-form-check"><input type="checkbox" name="shuffle_choices" value="1"><span><strong>สลับลำดับตัวเลือก</strong><small>สุ่มตัวเลือกใหม่โดยไม่กระทบเฉลย</small></span></label><button class="curriculum-save-button">สร้างชุดข้อสอบ</button></form>
    </aside>
<?php endif; ?>
<?php if ($selectedShare): ?>
<script src="<?= e(app_base_url()) ?>/assets/vendor/qrcode-1.0.0.min.js"></script>
<script>
(() => {
    const shareUrlInput = document.getElementById('public-share-url');
    const copyButton = document.getElementById('copy-public-share');
    const copyStatus = document.getElementById('share-copy-status');
    const qrContainer = document.getElementById('public-share-qr');
    const downloadButton = document.getElementById('download-public-qr');
    if (!shareUrlInput || !copyButton || !copyStatus || !qrContainer || !downloadButton) return;

    const shareUrl = shareUrlInput.value;
    const setStatus = (message, isError = false) => {
        copyStatus.textContent = message;
        copyStatus.classList.toggle('text-red-700', isError);
        copyStatus.classList.toggle('text-teal-700', !isError);
    };

    copyButton.addEventListener('click', async () => {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(shareUrl);
            } else {
                shareUrlInput.focus();
                shareUrlInput.select();
                if (!document.execCommand('copy')) throw new Error('copy failed');
            }
            setStatus('คัดลอกลิงก์แล้ว พร้อมนำไปแชร์ได้ทันที');
        } catch (error) {
            setStatus('คัดลอกอัตโนมัติไม่ได้ กรุณาเลือกลิงก์แล้วคัดลอกด้วยตนเอง', true);
        }
    });

    if (typeof QRCode !== 'function') {
        qrContainer.textContent = 'ไม่สามารถโหลด QR Code ได้';
        downloadButton.disabled = true;
        setStatus('ยังคัดลอกลิงก์ไปแชร์ได้ตามปกติ แต่ไม่สามารถสร้าง QR Code ในขณะนี้', true);
        return;
    }

    new QRCode(qrContainer, {
        text: shareUrl,
        width: 144,
        height: 144,
        colorDark: '#083344',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.H,
    });

    downloadButton.addEventListener('click', () => {
        const canvas = qrContainer.querySelector('canvas');
        const image = qrContainer.querySelector('img');
        const dataUrl = canvas ? canvas.toDataURL('image/png') : (image ? image.src : '');
        if (!dataUrl) {
            setStatus('ยังไม่สามารถดาวน์โหลด QR Code ได้ กรุณาลองใหม่', true);
            return;
        }
        const link = document.createElement('a');
        link.href = dataUrl;
        link.download = <?= json_encode('QR-' . (string) $selectedShare['public_title'] . '.png', JSON_UNESCAPED_UNICODE) ?>;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setStatus('ดาวน์โหลด QR Code แล้ว');
    });
})();
</script>
<?php endif; ?>
<?php render_footer(); ?>
