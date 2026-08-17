<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_admin();
ensure_curriculum_tables();

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

        if ($action === 'delete_set') {
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

                <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-200 p-4"><strong>คำถามในชุด · <?= count($questions) ?> ข้อ</strong></div>
                    <div class="divide-y divide-slate-100">
                        <?php foreach ($questions as $index => $question): ?>
                            <div class="flex gap-4 p-4">
                                <span class="text-xs font-extrabold text-slate-400"><?= $index + 1 ?></span>
                                <div class="min-w-0 flex-1"><strong class="block text-sm"><?= e($question['prompt']) ?></strong><span class="mt-1 block text-xs text-slate-500"><?= e($questionLabels[$question['question_type']] ?? $question['question_type']) ?></span></div>
                                <form method="post" onsubmit="return confirm('นำคำถามนี้ออกจากชุด?')"><input type="hidden" name="action" value="delete_question"><input type="hidden" name="set_id" value="<?= (int) $selectedSet['id'] ?>"><input type="hidden" name="question_id" value="<?= (int) $question['id'] ?>"><button class="text-xs font-bold text-red-600">นำออก</button></form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="grid gap-6 xl:grid-cols-2">
                    <form method="post" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
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
                            <input type="hidden" name="action" value="import_excel"><input type="hidden" name="set_id" value="<?= (int) $selectedSet['id'] ?>">
                            <h2 class="text-lg font-extrabold text-emerald-950">นำเข้า Excel เข้าชุดนี้</h2>
                            <p class="mt-1 text-xs leading-5 text-emerald-800">ไม่มีคอลัมน์ก่อนเรียน/หลังเรียนแล้ว เลือกชุดปลายทางจากหน้านี้โดยตรง</p>
                            <input name="excel_file" type="file" accept=".xlsx" required class="mt-4 w-full rounded-lg border border-emerald-300 bg-white px-3 py-3 text-sm">
                            <div class="mt-3 flex gap-2"><button class="rounded-lg bg-sea px-4 py-3 text-sm font-bold text-white">นำเข้า Excel</button><a href="question_template.php" class="rounded-lg border border-emerald-300 bg-white px-4 py-3 text-sm font-bold text-emerald-800">ดาวน์โหลดเทมเพลต</a></div>
                        </form>
                        <form method="post" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <input type="hidden" name="action" value="import_json"><input type="hidden" name="set_id" value="<?= (int) $selectedSet['id'] ?>">
                            <h2 class="text-lg font-extrabold">นำเข้า JSON เข้าชุดนี้</h2>
                            <textarea name="json_data" rows="8" required class="mt-3 w-full rounded-lg border border-slate-300 px-3 py-3 font-mono text-xs"><?= e($exampleJson) ?></textarea>
                            <button class="mt-3 rounded-lg bg-ink px-4 py-3 text-sm font-bold text-white">นำเข้า JSON</button>
                        </form>
                        <form method="post" onsubmit="return confirm('ยืนยันการลบชุดข้อสอบนี้?')"><input type="hidden" name="action" value="delete_set"><input type="hidden" name="set_id" value="<?= (int) $selectedSet['id'] ?>"><button class="text-sm font-bold text-red-600">ลบชุดข้อสอบนี้</button></form>
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
        <form method="post" class="curriculum-drawer-form"><input type="hidden" name="action" value="save_set"><label>ชื่อชุดข้อสอบ<input name="title" required placeholder="เช่น แบบทดสอบท้ายส่วนที่ 1"></label><label>คำอธิบาย<textarea name="description" rows="4"></textarea></label><label class="curriculum-form-check"><input type="checkbox" name="shuffle_questions" value="1"><span><strong>สลับลำดับโจทย์</strong><small>สุ่มลำดับคำถามใหม่เมื่อผู้เรียนเปิดชุดข้อสอบ</small></span></label><label class="curriculum-form-check"><input type="checkbox" name="shuffle_choices" value="1"><span><strong>สลับลำดับตัวเลือก</strong><small>สุ่มตัวเลือกใหม่โดยไม่กระทบเฉลย</small></span></label><button class="curriculum-save-button">สร้างชุดข้อสอบ</button></form>
    </aside>
<?php endif; ?>
<?php render_footer(); ?>
