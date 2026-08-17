<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$attempt = require_attempt();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'retake') {
    db()->prepare("DELETE FROM question_progress WHERE attempt_id = ?")->execute([(int) $attempt['id']]);
    db()->prepare("UPDATE attempts SET status = 'learning', post_score = NULL, post_total = NULL WHERE id = ?")->execute([(int) $attempt['id']]);
    
    $target = post('target');
    if ($target === 'quiz') {
        $stmt = db()->prepare("SELECT id FROM curriculum_items WHERE course_id = ? AND item_type = 'quiz_set' ORDER BY sort_order ASC LIMIT 1");
        $stmt->execute([(int) $attempt['course_id']]);
        $quizItem = $stmt->fetchColumn();
        if ($quizItem) {
            redirect(attempt_url('lesson.php', $attempt, ['item' => $quizItem]));
        }
    }
    redirect(attempt_url('lesson.php', $attempt));
}

$postPercent = ((int) $attempt['post_total'] > 0) ? round(((int) $attempt['post_score'] / (int) $attempt['post_total']) * 100, 2) : 100;
$passed = $attempt['status'] === 'passed';
$certificateAttempt = $passed ? certificate_attempt_for_attempt($attempt) : null;

render_header('ผลการเรียน', 'learn');
?>
<section class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
    <div class="rounded-lg border border-slate-200 bg-white p-6 text-center shadow-soft sm:p-10">
        <p class="text-sm font-bold text-sea"><?= e($attempt['course_title']) ?></p>
        <h1 class="mt-3 text-4xl font-extrabold"><?= $passed ? 'ยินดีด้วย คุณผ่านหลักสูตร' : 'ยังไม่ผ่านเกณฑ์ ลองทบทวนอีกครั้ง' ?></h1>
        <p class="mt-4 text-slate-600">คะแนนข้อสอบรวมของ <?= e($attempt['learner_name']) ?> คือ <?= e(format_score($attempt['post_score'] !== null ? (int) $attempt['post_score'] : null, $attempt['post_total'] !== null ? (int) $attempt['post_total'] : null)) ?> หรือ <?= e((string) $postPercent) ?>%</p>

        <div class="mt-8 grid gap-4 sm:grid-cols-3">
            <div class="rounded-lg bg-slate-50 p-5">
                <p class="text-sm text-slate-500">รายการเรียนรู้</p>
                <?php $summary = curriculum_summary($attempt); ?>
                <strong class="mt-2 block text-2xl"><?= (int) $summary['completed'] ?>/<?= (int) $summary['required'] ?></strong>
            </div>
            <div class="rounded-lg bg-slate-50 p-5">
                <p class="text-sm text-slate-500">คะแนนข้อสอบรวม</p>
                <strong class="mt-2 block text-2xl"><?= e(format_score($attempt['post_score'] !== null ? (int) $attempt['post_score'] : null, $attempt['post_total'] !== null ? (int) $attempt['post_total'] : null)) ?></strong>
            </div>
            <div class="rounded-lg bg-slate-50 p-5">
                <p class="text-sm text-slate-500">เกณฑ์ผ่าน</p>
                <strong class="mt-2 block text-2xl"><?= e((string) $attempt['pass_percent']) ?>%</strong>
            </div>
        </div>

        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <?php if ($passed): ?>
                <?php if ($certificateAttempt): ?>
                    <a href="<?= e(attempt_url('certificate.php', $certificateAttempt)) ?>" class="rounded-lg bg-sun px-5 py-3 text-sm font-bold text-white hover:bg-amber-600"><?= (int) $certificateAttempt['id'] === (int) $attempt['id'] ? 'เปิดเกียรติบัตร' : 'เปิดเกียรติบัตรใบเดิม' ?></a>
                <?php endif; ?>
            <?php else: ?>
                <form method="post" class="flex flex-wrap justify-center gap-3">
                    <input type="hidden" name="action" value="retake">
                    <button type="submit" name="target" value="lesson" class="rounded-lg border border-slate-300 px-5 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors">กลับไปทบทวนบทเรียน</button>
                    <button type="submit" name="target" value="quiz" class="rounded-lg bg-sea px-5 py-3 text-sm font-bold text-white hover:bg-teal-700 transition-colors">ทำข้อสอบใหม่อีกครั้ง</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php render_footer(); ?>
