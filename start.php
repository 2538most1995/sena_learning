<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

if (!database_ready()) {
    render_empty_setup();
    exit;
}

ensure_learning_access_columns();
$courseId = $_SERVER['REQUEST_METHOD'] === 'POST' ? (int) post('course_id') : get_int('course_id');
$course = $courseId > 0 ? fetch_course($courseId) : null;
if (!$course) {
    flash('ไม่พบหลักสูตรที่เลือก', 'error');
    redirect('index.php');
}

$loggedInUser = current_user();
if (!$loggedInUser && !course_is_public($course)) {
    remember_login_course($courseId);
    flash('หลักสูตรนี้สงวนสิทธิ์สำหรับสมาชิก กรุณาเข้าสู่ระบบก่อนเริ่มเรียน', 'error');
    redirect('auth/login.php');
}

if ($loggedInUser && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $latestAttempt = latest_user_attempts_by_course((int) $loggedInUser['id'])[$courseId] ?? null;
    if ($latestAttempt) {
        $destination = in_array((string) $latestAttempt['status'], ['posttest_done', 'passed'], true)
            ? 'result.php'
            : 'lesson.php';
        redirect(attempt_url($destination, $latestAttempt));
    }

    try {
        $accountName = trim((string) $loggedInUser['display_name']);
        if ($accountName === '') {
            throw new RuntimeException('กรุณาตรวจสอบชื่อในบัญชีผู้ใช้');
        }
        $attempt = get_or_create_attempt($courseId, (int) $loggedInUser['id'], $accountName);
        redirect(attempt_url('lesson.php', $attempt));
    } catch (RuntimeException $exception) {
        flash($exception->getMessage(), 'error');
        redirect('index.php');
    }
}

$error = '';
$learnerName = trim((string) post('learner_name'));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($loggedInUser) {
            $accountName = trim((string) $loggedInUser['display_name']);
            if ($accountName === '') {
                throw new RuntimeException('กรุณาตรวจสอบชื่อในบัญชีผู้ใช้');
            }
            $attempt = get_or_create_attempt($courseId, (int) $loggedInUser['id'], $accountName);
        } else {
            $attempt = get_or_create_guest_attempt($courseId, $learnerName);
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['last_attempt_id'] = (int) $attempt['id'];
        $_SESSION['last_attempt_token'] = (string) $attempt['access_token'];
        redirect(attempt_url('lesson.php', $attempt));
    } catch (RuntimeException $exception) {
        if ($loggedInUser) {
            flash($exception->getMessage(), 'error');
            redirect('index.php');
        }
        $error = $exception->getMessage();
    }
}

render_header('กรอกชื่อก่อนเริ่มเรียน', 'learn');
?>
<section class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft">
        <div class="bg-gradient-to-r from-teal-800 to-emerald-600 px-6 py-7 text-white sm:px-9">
            <p class="text-sm font-extrabold text-emerald-100">หลักสูตรสาธารณะ</p>
            <h1 class="mt-2 text-2xl font-extrabold sm:text-3xl"><?= e((string) $course['title']) ?></h1>
            <p class="mt-3 max-w-2xl text-sm leading-7 text-emerald-50"><?= e((string) $course['description']) ?></p>
        </div>
        <form method="post" class="p-6 sm:p-9" novalidate>
            <input type="hidden" name="course_id" value="<?= $courseId ?>">
            <div>
                <h2 class="text-xl font-extrabold text-slate-900">กรอกชื่อ–นามสกุลเพื่อเริ่มเรียน</h2>
                <p id="learner-name-help" class="mt-2 text-sm leading-6 text-slate-600">ชื่อนี้จะปรากฏบนเกียรติบัตร กรุณากรอกให้ถูกต้องและครบถ้วน</p>
            </div>
            <?php if ($error !== ''): ?>
                <div id="learner-name-error" class="mt-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700" role="alert"><?= e($error) ?></div>
            <?php endif; ?>
            <div class="mt-6">
                <label for="learner_name" class="text-sm font-extrabold text-slate-800">ชื่อ–นามสกุล</label>
                <input id="learner_name" name="learner_name" value="<?= e($learnerName) ?>" autocomplete="name" maxlength="255" required aria-describedby="learner-name-help<?= $error !== '' ? ' learner-name-error' : '' ?>" <?= $error !== '' ? 'aria-invalid="true"' : '' ?> placeholder="เช่น สมชาย ใจดี" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-base focus:border-sea focus:outline-none focus:ring-4 focus:ring-teal-100">
            </div>
            <div class="mt-7 flex flex-col gap-3 sm:flex-row">
                <button type="submit" class="inline-flex justify-center rounded-xl bg-sea px-6 py-3 text-sm font-extrabold text-white hover:bg-teal-700 focus:outline-none focus:ring-4 focus:ring-teal-200">ยืนยันชื่อและเริ่มเรียน</button>
                <a href="index.php#learning-library" class="inline-flex justify-center rounded-xl border border-slate-300 px-6 py-3 text-sm font-extrabold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-slate-200">กลับไปดูหลักสูตรทั้งหมด</a>
            </div>
        </form>
    </div>
</section>
<?php render_footer(); ?>
