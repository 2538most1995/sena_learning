<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/quiz_review.php';
require_admin();
ensure_learning_access_columns();
ensure_curriculum_tables();

$stmt = db()->prepare(
    'SELECT a.*, c.title AS course_title,
            COALESCE(NULLIF(u.display_name, ""), a.learner_name) AS display_name
     FROM attempts a
     INNER JOIN courses c ON c.id = a.course_id
     LEFT JOIN users u ON u.id = a.user_id
     WHERE a.id = ?'
);
$stmt->execute([get_int('attempt')]);
$attempt = $stmt->fetch();
if (!$attempt) {
    http_response_code(404);
    render_header('ไม่พบข้อมูลผู้เรียน', 'admin');
    ?>
    <section class="mx-auto max-w-4xl px-4 py-10 sm:px-6">
        <h1 class="text-2xl font-extrabold">ไม่พบข้อมูลการเข้าเรียนนี้</h1>
        <a href="attempts.php" class="mt-4 inline-block font-bold text-sea hover:underline">กลับรายการผู้เรียน</a>
    </section>
    <?php
    render_footer();
    exit;
}

$reviews = admin_attempt_quiz_reviews((int) $attempt['id']);
$answered = 0;
$correct = 0;
foreach ($reviews as $review) {
    $answered += count($review['questions']);
    $correct += count(array_filter($review['questions'], fn ($question) => (int) $question['is_correct'] === 1));
}

render_header('คำตอบรายข้อ · ' . $attempt['display_name'], 'admin');
?>
<section class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
    <a href="attempts.php?course_id=<?= (int) $attempt['course_id'] ?>" class="text-sm font-bold text-sea hover:underline">กลับรายชื่อผู้เรียนในหลักสูตร</a>
    <h1 class="mt-3 text-3xl font-extrabold">คำตอบรายข้อของ <?= e((string) $attempt['display_name']) ?></h1>
    <p class="mt-3 text-slate-600"><?= e((string) $attempt['course_title']) ?></p>
    <p class="mt-2 text-sm text-slate-500">รอบเข้าเรียน #<?= (int) $attempt['id'] ?> · เริ่ม <?= e((string) $attempt['created_at']) ?></p>

    <dl class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <?php foreach (['มีคำตอบบันทึกไว้' => $answered, 'ตอบถูก' => $correct, 'ตอบผิด' => $answered - $correct] as $label => $count): ?>
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <dt class="text-sm font-bold text-slate-600"><?= e($label) ?></dt>
                <dd class="mt-2 text-2xl font-extrabold"><?= $count ?> ข้อ</dd>
            </div>
        <?php endforeach; ?>
    </dl>

    <?php if (!$reviews): ?>
        <div class="mt-6 rounded-xl border border-slate-200 bg-white p-6">
            <h2 class="text-lg font-bold">ยังไม่มีคำตอบรายข้อที่บันทึกไว้</h2>
            <p class="mt-2 text-slate-600">ผู้เรียนอาจยังไม่ได้ส่งแบบทดสอบ หรือเป็นข้อมูลเดิมที่มีเฉพาะคะแนนรวม</p>
        </div>
    <?php else: ?>
        <p class="mt-5 text-sm text-slate-600">แสดงคำตอบล่าสุดที่บันทึกไว้ของรอบเข้าเรียนนี้ แยกตามชุดข้อสอบ หากมีการทำใหม่ คำตอบเดิมอาจถูกแทนที่หรือล้างตามขั้นตอนของระบบ</p>
        <?php foreach ($reviews as $review): ?>
            <?php render_quiz_review($review['questions'], 'เฉลย · ' . $review['title'], 'คำตอบของผู้เรียน'); ?>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
