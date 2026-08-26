<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

if (!database_ready()) {
    render_empty_setup();
    exit;
}

ensure_public_quiz_sharing_tables();
$shareToken = trim((string) ($_GET['share'] ?? $_POST['share'] ?? ''));
$share = public_quiz_share_by_token($shareToken);
$inactiveShare = $share ?: public_quiz_share_by_token($shareToken, true);

if (!$inactiveShare) {
    http_response_code(404);
    render_header('ไม่พบแบบทดสอบ', 'learn');
    ?>
    <section class="mx-auto max-w-3xl px-4 py-16 sm:px-6">
        <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-soft">
            <p class="text-sm font-extrabold text-sea">ลิงก์แบบทดสอบ</p>
            <h1 class="mt-3 text-3xl font-extrabold">ไม่พบแบบทดสอบนี้</h1>
            <p class="mt-3 text-slate-600">กรุณาตรวจสอบลิงก์หรือ QR Code อีกครั้ง</p>
        </div>
    </section>
    <?php
    render_footer();
    exit;
}

if (!$share) {
    http_response_code(403);
    render_header('แบบทดสอบปิดรับคำตอบ', 'learn');
    ?>
    <section class="shared-quiz-page shared-quiz--<?= e(normalize_public_quiz_theme((string) $inactiveShare['theme'])) ?> px-4 py-14 sm:px-6">
        <div class="mx-auto max-w-3xl rounded-2xl bg-white p-8 text-center shadow-soft ring-1 ring-slate-200">
            <p class="shared-quiz-eyebrow">แบบทดสอบสาธารณะ</p>
            <h1 class="mt-3 text-3xl font-extrabold text-slate-900"><?= e((string) $inactiveShare['public_title']) ?></h1>
            <p class="mt-4 text-slate-600">แบบทดสอบนี้ปิดรับคำตอบชั่วคราว กรุณาติดต่อผู้ส่งลิงก์</p>
        </div>
    </section>
    <?php
    render_footer();
    exit;
}

$attemptId = get_int('attempt');
$attemptToken = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$attempt = $attemptId > 0 ? public_quiz_attempt($attemptId, $attemptToken) : null;
if ($attempt && (int) $attempt['share_id'] !== (int) $share['id']) {
    $attempt = null;
}

$error = '';
$learnerName = trim((string) post('learner_name'));
$submittedAnswers = is_array(post('answers', [])) ? post('answers', []) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_valid_csrf_token();
        $action = (string) post('action');
        if ($action === 'start') {
            $attempt = create_public_quiz_attempt($share, $learnerName);
            redirect(public_quiz_attempt_url($share, $attempt));
        }
        if ($action === 'submit') {
            if (!$attempt) {
                throw new RuntimeException('ลิงก์ทำข้อสอบของคุณไม่ถูกต้อง กรุณาเริ่มทำใหม่');
            }
            submit_public_quiz_attempt($attempt, $share, $submittedAnswers);
            redirect(public_quiz_attempt_url($share, $attempt));
        }
        throw new RuntimeException('ไม่พบคำสั่งที่ต้องการ');
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}

$questions = [];
if ($attempt && (string) $attempt['status'] === 'started') {
    $questions = quiz_set_questions((int) $share['quiz_set_id']);
    if ((int) $share['shuffle_questions'] === 1 && count($questions) > 1) {
        shuffle($questions);
    }
}

$theme = normalize_public_quiz_theme((string) $share['theme']);
$pageTitle = (string) $share['public_title'];
render_header($pageTitle, 'learn');
?>
<section class="shared-quiz-page shared-quiz--<?= e($theme) ?> px-4 py-8 sm:px-6 sm:py-12">
    <div class="mx-auto max-w-4xl">
        <header class="shared-quiz-hero relative overflow-hidden rounded-3xl px-6 py-8 text-white shadow-soft sm:px-10 sm:py-10">
            <div class="relative z-10 max-w-3xl">
                <div class="flex flex-wrap gap-2">
                    <span class="shared-quiz-badge">ทำได้โดยไม่ต้องเข้าสู่ระบบ</span>
                    <span class="shared-quiz-badge"><?= (int) $share['question_count'] ?> ข้อ</span>
                    <span class="shared-quiz-badge">ผ่าน <?= e((string) (float) $share['pass_percent']) ?>%</span>
                </div>
                <p class="mt-6 text-sm font-extrabold text-white/80"><?= e((string) $share['course_title']) ?></p>
                <h1 class="mt-2 text-balance text-3xl font-extrabold leading-tight sm:text-4xl"><?= e($pageTitle) ?></h1>
                <?php if (trim((string) $share['welcome_message']) !== ''): ?>
                    <p class="mt-4 max-w-2xl whitespace-pre-line text-pretty text-sm leading-7 text-white/90 sm:text-base"><?= e((string) $share['welcome_message']) ?></p>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($error !== ''): ?>
            <div id="shared-quiz-error" class="mt-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if (!$attempt): ?>
            <div class="shared-quiz-card mt-6 rounded-2xl bg-white p-6 shadow-sm sm:p-8">
                <div class="grid gap-6 sm:grid-cols-[minmax(0,1fr)_220px] sm:items-start">
                    <form method="post" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="start">
                        <input type="hidden" name="share" value="<?= e($shareToken) ?>">
                        <h2 class="text-2xl font-extrabold text-slate-900">พร้อมแล้ว เริ่มทำแบบทดสอบ</h2>
                        <p id="shared-name-help" class="mt-2 text-sm leading-6 text-slate-600"><?= (int) $share['certificate_enabled'] === 1 ? 'ชื่อนี้จะใช้แสดงบนเกียรติบัตรเมื่อคุณผ่าน กรุณาตรวจสอบให้ถูกต้อง' : 'กรอกชื่อเพื่อบันทึกผลคะแนนของคุณ' ?></p>
                        <label for="learner_name" class="mt-6 block text-sm font-extrabold text-slate-800">ชื่อ–นามสกุล</label>
                        <input id="learner_name" name="learner_name" value="<?= e($learnerName) ?>" autocomplete="name" maxlength="255" required aria-describedby="shared-name-help<?= $error !== '' ? ' shared-quiz-error' : '' ?>" <?= $error !== '' ? 'aria-invalid="true"' : '' ?> placeholder="เช่น สมชาย ใจดี" class="shared-quiz-input mt-2 w-full rounded-xl border px-4 py-3 text-base focus:outline-none focus:ring-4">
                        <button type="submit" class="shared-quiz-primary mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-xl px-6 py-3 text-sm font-extrabold text-white focus:outline-none focus:ring-4 sm:w-auto">เริ่มทำแบบทดสอบ</button>
                    </form>
                    <aside class="rounded-2xl bg-slate-50 p-5 ring-1 ring-slate-100" aria-label="รายละเอียดแบบทดสอบ">
                        <dl class="grid gap-4 text-sm">
                            <div><dt class="font-semibold text-slate-500">จำนวนคำถาม</dt><dd class="mt-1 text-xl font-extrabold tabular-nums text-slate-900"><?= (int) $share['question_count'] ?> ข้อ</dd></div>
                            <div><dt class="font-semibold text-slate-500">เกณฑ์ผ่าน</dt><dd class="mt-1 text-xl font-extrabold tabular-nums text-slate-900"><?= e((string) (float) $share['pass_percent']) ?>%</dd></div>
                            <div><dt class="font-semibold text-slate-500">เกียรติบัตร</dt><dd class="mt-1 font-extrabold text-slate-900"><?= (int) $share['certificate_enabled'] === 1 ? 'มี เมื่อผ่านเกณฑ์' : 'ไม่มี' ?></dd></div>
                        </dl>
                    </aside>
                </div>
            </div>
        <?php elseif ((string) $attempt['status'] === 'started'): ?>
            <form method="post" class="mt-6" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="submit">
                <input type="hidden" name="share" value="<?= e($shareToken) ?>">
                <input type="hidden" name="attempt" value="<?= (int) $attempt['id'] ?>">
                <input type="hidden" name="token" value="<?= e((string) $attempt['access_token']) ?>">
                <div class="mb-4 flex flex-col gap-2 rounded-xl bg-white/85 px-4 py-3 text-sm shadow-sm ring-1 ring-white sm:flex-row sm:items-center sm:justify-between">
                    <p><span class="font-semibold text-slate-500">ผู้ทำแบบทดสอบ</span> <strong class="text-slate-900"><?= e((string) $attempt['learner_name']) ?></strong></p>
                    <p class="font-semibold text-slate-600">กรุณาตอบให้ครบทุกข้อก่อนส่ง</p>
                </div>
                <div class="grid gap-4">
                    <?php foreach ($questions as $index => $question): ?>
                        <?php
                        $questionId = (int) $question['id'];
                        $choices = json_decode((string) ($question['choices'] ?? '[]'), true) ?: [];
                        if ((int) $share['shuffle_choices'] === 1 && count($choices) > 1) {
                            shuffle($choices);
                        }
                        $given = $submittedAnswers[(string) $questionId] ?? null;
                        ?>
                        <fieldset class="shared-quiz-question rounded-2xl bg-white p-5 shadow-sm sm:p-6">
                            <legend class="w-full px-0 text-lg font-extrabold leading-8 text-slate-900">
                                <span class="shared-quiz-number mr-2 inline-grid h-8 min-w-8 place-items-center rounded-full px-2 text-sm text-white" aria-hidden="true"><?= $index + 1 ?></span>
                                <?= e((string) $question['prompt']) ?>
                            </legend>
                            <?php if ($question['question_type'] === 'short_answer'): ?>
                                <label for="answer-<?= $questionId ?>" class="sr-only">คำตอบข้อที่ <?= $index + 1 ?></label>
                                <input id="answer-<?= $questionId ?>" name="answers[<?= $questionId ?>]" value="<?= e(is_scalar($given) ? (string) $given : '') ?>" required class="shared-quiz-input mt-5 w-full rounded-xl border px-4 py-3 text-base focus:outline-none focus:ring-4" placeholder="พิมพ์คำตอบของคุณ">
                            <?php else: ?>
                                <div class="mt-5 grid gap-3">
                                    <?php foreach ($choices as $choiceIndex => $choice): ?>
                                        <?php
                                        $choiceText = (string) $choice;
                                        $isMultiple = $question['question_type'] === 'multiple_choice';
                                        $isChecked = $isMultiple
                                            ? is_array($given) && in_array($choiceText, array_map('strval', $given), true)
                                            : (string) $given === $choiceText;
                                        ?>
                                        <label class="shared-quiz-choice flex min-h-11 cursor-pointer items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-sm text-slate-700">
                                            <input type="<?= $isMultiple ? 'checkbox' : 'radio' ?>" name="answers[<?= $questionId ?>]<?= $isMultiple ? '[]' : '' ?>" value="<?= e($choiceText) ?>" <?= !$isMultiple && $choiceIndex === 0 ? 'required' : '' ?> <?= $isChecked ? 'checked' : '' ?> class="h-4 w-4 shrink-0">
                                            <span><?= e($choiceText) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
                <div class="sticky bottom-4 z-20 mt-6 flex justify-end">
                    <button type="submit" class="shared-quiz-primary inline-flex min-h-12 w-full items-center justify-center rounded-xl px-8 py-3 text-base font-extrabold text-white shadow-lg focus:outline-none focus:ring-4 sm:w-auto">ส่งคำตอบและดูคะแนน</button>
                </div>
            </form>
        <?php else: ?>
            <?php $passed = (string) $attempt['status'] === 'passed'; ?>
            <div class="shared-quiz-card mt-6 overflow-hidden rounded-2xl bg-white shadow-sm">
                <div class="p-6 text-center sm:p-10">
                    <div class="mx-auto grid h-16 w-16 place-items-center rounded-full <?= $passed ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?> text-3xl" aria-hidden="true"><?= $passed ? '✓' : '↻' ?></div>
                    <p class="mt-5 text-sm font-extrabold text-slate-500"><?= e((string) $attempt['learner_name']) ?></p>
                    <h2 class="mt-2 text-balance text-3xl font-extrabold text-slate-900"><?= $passed ? 'ยินดีด้วย คุณผ่านเกณฑ์' : 'ยังไม่ผ่าน ลองอีกครั้งได้' ?></h2>
                    <p class="mt-3 text-slate-600">คุณได้ <strong class="text-slate-900"><?= (int) $attempt['score'] ?>/<?= (int) $attempt['total'] ?> คะแนน</strong> หรือ <strong class="text-slate-900"><?= e((string) (float) $attempt['percent']) ?>%</strong></p>
                    <div class="mx-auto mt-7 grid max-w-xl gap-3 sm:grid-cols-2">
                        <div class="rounded-xl bg-slate-50 p-4"><p class="text-xs font-bold text-slate-500">คะแนนของคุณ</p><strong class="mt-1 block text-2xl tabular-nums text-slate-900"><?= e((string) (float) $attempt['percent']) ?>%</strong></div>
                        <div class="rounded-xl bg-slate-50 p-4"><p class="text-xs font-bold text-slate-500">เกณฑ์ผ่าน</p><strong class="mt-1 block text-2xl tabular-nums text-slate-900"><?= e((string) (float) $share['pass_percent']) ?>%</strong></div>
                    </div>
                    <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                        <?php if ($passed && !empty($attempt['certificate_code'])): ?>
                            <a href="certificate_view.php?code=<?= rawurlencode((string) $attempt['certificate_code']) ?>" class="shared-quiz-primary inline-flex min-h-11 items-center justify-center rounded-xl px-6 py-3 text-sm font-extrabold text-white focus:outline-none focus:ring-4">เปิดและบันทึกเกียรติบัตร</a>
                        <?php endif; ?>
                        <a href="shared_quiz.php?share=<?= rawurlencode($shareToken) ?>" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-6 py-3 text-sm font-extrabold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-slate-200"><?= $passed ? 'ทำแบบทดสอบอีกครั้ง' : 'ลองทำใหม่' ?></a>
                    </div>
                    <?php if ($passed && (int) $share['certificate_enabled'] !== 1): ?>
                        <p class="mt-5 text-sm text-slate-500">แบบทดสอบชุดนี้ไม่ได้เปิดการออกเกียรติบัตร</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php render_footer(); ?>
