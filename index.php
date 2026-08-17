<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

if (!database_ready()) {
    render_empty_setup();
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$loggedInUser = current_user();
ensure_learning_access_columns();
ensure_curriculum_tables();

$courseCategories = course_categories();
$courseSearch = trim((string) ($_GET['q'] ?? ''));
$selectedCategory = (string) ($_GET['category'] ?? '');
if (!array_key_exists($selectedCategory, $courseCategories)) {
    $selectedCategory = '';
}
$courseOrderSql = "ORDER BY CASE title
        WHEN 'การพัฒนาที่ยั่งยืนในชีวิตประจำวัน' THEN 1
        WHEN 'ความปลอดภัยไซเบอร์สำหรับทุกคน' THEN 2
        WHEN 'การเงินส่วนบุคคลอย่างชาญฉลาด' THEN 3
        WHEN 'สุขภาพดีเริ่มที่ตัวเรา' THEN 4
        ELSE 10
     END, created_at ASC, id ASC";
$allCourses = db()->query(
    "SELECT * FROM courses
     WHERE is_published = 1
     {$courseOrderSql}"
)->fetchAll();

$courseWhere = ['is_published = 1'];
$courseParams = [];
if ($courseSearch !== '') {
    $courseWhere[] = '(title LIKE ? OR description LIKE ?)';
    $searchLike = '%' . $courseSearch . '%';
    $courseParams[] = $searchLike;
    $courseParams[] = $searchLike;
}
if ($selectedCategory !== '') {
    $courseWhere[] = 'category = ?';
    $courseParams[] = $selectedCategory;
}
$courseStmt = db()->prepare(
    'SELECT * FROM courses
     WHERE ' . implode(' AND ', $courseWhere) . "
     {$courseOrderSql}"
);
$courseStmt->execute($courseParams);
$courses = $courseStmt->fetchAll();

$totalCourses = count($allCourses);
$visibleCourseCount = count($courses);
$hasCourseFilters = $courseSearch !== '' || $selectedCategory !== '';
$userAttempts = $loggedInUser
    ? latest_user_attempts_by_course((int) $loggedInUser['id'])
    : session_guest_attempts();
$certificateAttempts = $loggedInUser
    ? user_certificate_attempts_by_course((int) $loggedInUser['id'])
    : session_guest_attempts(true);
$courseProgress = [];
$courseStats = [];
$coursesById = [];
$categoryCounts = array_fill_keys(array_keys($courseCategories), 0);
$resumeAttempt = null;

foreach ($allCourses as $course) {
    $courseId = (int) $course['id'];
    $coursesById[$courseId] = $course;
    $courseStats[$courseId] = course_stats($courseId);
    $categoryCounts[normalize_course_category((string) ($course['category'] ?? ''))]++;
}
foreach ($userAttempts as $courseId => $attempt) {
    $courseProgress[$courseId] = attempt_progress_percent($attempt);
    if ($resumeAttempt === null && $attempt['status'] !== 'passed') {
        $resumeAttempt = $attempt;
    }
}

$progressPercent = $totalCourses > 0 ? (int) round(array_sum($courseProgress) / $totalCourses) : 0;
$personalCompleted = count(array_filter($userAttempts, fn (array $attempt): bool => $attempt['status'] === 'passed'));
$personalInProgress = count($userAttempts) - $personalCompleted;
$personalNotStarted = max(0, $totalCourses - count($userAttempts));
$publicCourseCount = count(array_filter($allCourses, 'course_is_public'));
$restrictedCourseCount = $totalCourses - $publicCourseCount;
$learnerTypeLabel = $loggedInUser
    ? ($loggedInUser['user_type'] === 'student' ? 'นักศึกษา ศกร.' : 'ประชาชนทั่วไป')
    : 'เข้าชมโดยไม่ต้องล็อกอิน';
$learnerInitial = $loggedInUser
    ? mb_substr((string) $loggedInUser['display_name'], 0, 1, 'UTF-8')
    : 'S';
$thumbs = ['thumb-green', 'thumb-blue', 'thumb-amber', 'thumb-rose'];

function learner_course_status(?array $attempt): array
{
    if (!$attempt) {
        return ['ยังไม่ได้เริ่ม', 'not-started'];
    }
    if ($attempt['status'] === 'passed') {
        return ['ผ่านแล้ว', 'passed'];
    }
    if ($attempt['status'] === 'posttest_done') {
        return ['ทบทวนผลการเรียน', 'review'];
    }
    return ['กำลังเรียน', 'learning'];
}

function learner_continue_url(array $attempt): string
{
    return match ($attempt['status']) {
        'posttest_done', 'passed' => attempt_url('result.php', $attempt),
        default => attempt_url('lesson.php', $attempt),
    };
}

function learner_library_url(string $search, string $category): string
{
    $params = [];
    if ($search !== '') {
        $params['q'] = $search;
    }
    if ($category !== '') {
        $params['category'] = $category;
    }

    return 'index.php' . ($params ? '?' . http_build_query($params) : '') . '#learning-library';
}

render_header('หน้าเรียน', 'learn');
?>
<section class="learner-dashboard page-safe">
    <div class="learner-shell">
        <section class="learner-hero">
            <div class="learner-welcome">
                <div class="learner-avatar"><?= e($learnerInitial) ?></div>
                <div>
                    <p class="learner-eyebrow"><?= $loggedInUser ? 'พื้นที่การเรียนรู้ของคุณ' : 'คลังการเรียนรู้ SENA Learning' ?></p>
                    <h1><?= $loggedInUser ? 'ยินดีต้อนรับ, ' . e((string) $loggedInUser['display_name']) : 'หลักสูตรทั้งหมด' ?></h1>
                    <span class="learner-account-badge"><?= e($learnerTypeLabel) ?></span>
                    <p class="learner-welcome-copy"><?= $loggedInUser
                        ? 'เลือกหลักสูตรที่สนใจและเรียนต่อจากจุดเดิม ระบบจะบันทึกผลการเรียนและเกียรติบัตรไว้ในบัญชีนี้เท่านั้น'
                        : 'เลือกเรียนหลักสูตรสาธารณะได้ทันทีโดยกรอกชื่อ–นามสกุล ส่วนหลักสูตรสมาชิกจะต้องเข้าสู่ระบบก่อน' ?></p>
                </div>
            </div>

            <div class="learner-overview">
                <div class="learner-ring" style="--value: <?= $progressPercent ?>%">
                    <div>
                        <strong><?= $loggedInUser ? $progressPercent . '%' : $totalCourses ?></strong>
                        <span><?= $loggedInUser ? 'คืบหน้าโดยรวม' : 'หลักสูตรทั้งหมด' ?></span>
                    </div>
                </div>
                <div class="learner-stats">
                    <div class="learner-stat">
                        <span class="learner-stat-icon learner-stat-completed">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 12 3 3 7-7"/><circle cx="12" cy="12" r="9"/></svg>
                        </span>
                        <strong><?= $loggedInUser ? $personalCompleted : $publicCourseCount ?></strong>
                        <span><?= $loggedInUser ? 'เรียนจบแล้ว' : 'เรียนได้ทันที' ?></span>
                    </div>
                    <div class="learner-stat">
                        <span class="learner-stat-icon learner-stat-learning">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11v17H6.5A2.5 2.5 0 0 0 4 22V5.5ZM20 5.5A2.5 2.5 0 0 0 17.5 3H13v17h4.5A2.5 2.5 0 0 1 20 22V5.5Z"/></svg>
                        </span>
                        <strong><?= $loggedInUser ? $personalInProgress : $restrictedCourseCount ?></strong>
                        <span><?= $loggedInUser ? 'กำลังเรียน' : 'สำหรับสมาชิก' ?></span>
                    </div>
                    <div class="learner-stat">
                        <span class="learner-stat-icon learner-stat-waiting">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                        </span>
                        <strong><?= $loggedInUser ? $personalNotStarted : count($userAttempts) ?></strong>
                        <span><?= $loggedInUser ? 'ยังไม่ได้เริ่ม' : 'ประวัติในเบราว์เซอร์นี้' ?></span>
                    </div>
                </div>
            </div>
        </section>

        <?php if ($resumeAttempt): ?>
            <?php
            $resumeCourseId = (int) $resumeAttempt['course_id'];
            $resumeCourse = $coursesById[$resumeCourseId] ?? null;
            $resumeStats = $courseStats[$resumeCourseId] ?? ['lessons' => 0, 'questions' => 0];
            $resumeProgress = $courseProgress[$resumeCourseId] ?? 0;
            ?>
            <?php if ($resumeCourse): ?>
            <section class="learner-section learner-resume">
                <div class="learner-section-heading">
                    <div>
                        <p class="learner-eyebrow">เรียนต่อจากครั้งล่าสุด</p>
                        <h2>กลับมาเรียนต่อได้ทันที</h2>
                    </div>
                </div>
                <div class="learner-resume-grid">
                    <div class="learner-resume-cover">
                        <div class="course-thumb <?= empty($resumeCourse['cover_url']) ? 'thumb-green' : 'has-cover-image' ?>">
                            <?php if (!empty($resumeCourse['cover_url'])): ?>
                                <img src="<?= e(public_upload_url((string) $resumeCourse['cover_url'])) ?>" alt="ภาพปก <?= e((string) $resumeCourse['title']) ?>">
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11v17H6.5A2.5 2.5 0 0 0 4 22V5.5ZM20 5.5A2.5 2.5 0 0 0 17.5 3H13v17h4.5A2.5 2.5 0 0 1 20 22V5.5Z"/></svg>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="learner-resume-content">
                        <h3><?= e((string) $resumeCourse['title']) ?></h3>
                        <p><?= e((string) $resumeCourse['description']) ?></p>
                        <div class="learner-meta">
                            <span><?= (int) $resumeStats['lessons'] ?> บทเรียน</span>
                            <span><?= (int) $resumeStats['questions'] ?> ข้อสอบ</span>
                            <?php if (!empty($resumeStats['video_duration_seconds'])): ?><span>เวลาเรียน <?= e(format_learning_duration((int) $resumeStats['video_duration_seconds'])) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="learner-resume-action">
                        <div class="learner-progress-label"><span>ความคืบหน้า</span><strong><?= $resumeProgress ?>%</strong></div>
                        <div class="learner-progress"><span style="width: <?= $resumeProgress ?>%"></span></div>
                        <a class="learner-btn learner-btn-primary" href="<?= e(learner_continue_url($resumeAttempt)) ?>">เรียนต่อ</a>
                    </div>
                </div>
            </section>
            <?php endif; ?>
        <?php endif; ?>

        <section id="learning-library" class="learner-course-section">
            <div class="learner-section-heading">
                <div>
                    <p class="learner-eyebrow">คลังการเรียนรู้</p>
                    <h2>หลักสูตรทั้งหมด</h2>
                    <p>เลือกหลักสูตรที่สนใจ ระบบจะแจ้งชัดเจนว่าเรียนได้ทันทีหรือต้องเข้าสู่ระบบ</p>
                </div>
                <span class="learner-course-count">
                    <?= $hasCourseFilters ? $visibleCourseCount . ' จาก ' . $totalCourses : $totalCourses ?> หลักสูตร
                </span>
            </div>

            <form class="learner-course-filter" method="get" action="index.php#learning-library">
                <div class="learner-search-field">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input name="q" value="<?= e($courseSearch) ?>" placeholder="ค้นหาชื่อหลักสูตรหรือคำอธิบาย">
                </div>
                <select name="category" aria-label="เลือกหมวดหมู่หลักสูตร">
                    <option value="">ทุกหมวดหมู่</option>
                    <?php foreach ($courseCategories as $categoryValue => $categoryLabel): ?>
                        <option value="<?= e($categoryValue) ?>" <?= $selectedCategory === $categoryValue ? 'selected' : '' ?>>
                            <?= e($categoryLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="learner-btn learner-btn-primary" type="submit">ค้นหา</button>
                <?php if ($hasCourseFilters): ?>
                    <a class="learner-btn learner-btn-muted" href="index.php#learning-library">ล้างตัวกรอง</a>
                <?php endif; ?>
            </form>

            <div class="learner-category-tabs" aria-label="หมวดหมู่หลักสูตร">
                <a class="<?= $selectedCategory === '' ? 'active' : '' ?>" href="<?= e(learner_library_url($courseSearch, '')) ?>">
                    ทุกหมวดหมู่ <span><?= $totalCourses ?></span>
                </a>
                <?php foreach ($courseCategories as $categoryValue => $categoryLabel): ?>
                    <a class="<?= $selectedCategory === $categoryValue ? 'active' : '' ?>" href="<?= e(learner_library_url($courseSearch, $categoryValue)) ?>">
                        <?= e($categoryLabel) ?> <span><?= (int) $categoryCounts[$categoryValue] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($courses): ?>
            <div class="learner-course-grid">
                <?php foreach ($courses as $index => $course): ?>
                    <?php
                    $courseId = (int) $course['id'];
                    $stats = $courseStats[$courseId];
                    $attempt = $userAttempts[$courseId] ?? null;
                    $certificateAttempt = $certificateAttempts[$courseId] ?? null;
                    $rowProgress = $courseProgress[$courseId] ?? 0;
                    [$statusText, $statusClass] = learner_course_status($attempt);
                    $isPublicCourse = course_is_public($course);
                    ?>
                    <article class="learner-course-card">
                        <div class="course-thumb <?= empty($course['cover_url']) ? e($thumbs[$index % count($thumbs)]) : 'has-cover-image' ?>">
                            <?php if (!empty($course['cover_url'])): ?>
                                <img src="<?= e(public_upload_url((string) $course['cover_url'])) ?>" alt="ภาพปก <?= e((string) $course['title']) ?>">
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11v17H6.5A2.5 2.5 0 0 0 4 22V5.5ZM20 5.5A2.5 2.5 0 0 0 17.5 3H13v17h4.5A2.5 2.5 0 0 1 20 22V5.5Z"/></svg>
                            <?php endif; ?>
                        </div>
                        <div class="learner-course-body">
                            <div class="learner-course-topline">
                                <span class="learner-status learner-status-<?= e($statusClass) ?>"><?= e($statusText) ?></span>
                                <span class="learner-access-badge <?= $isPublicCourse ? 'is-public' : 'is-locked' ?>"><?= e(course_access_label((string) ($course['access_mode'] ?? ''))) ?></span>
                            </div>
                            <span class="learner-category-badge"><?= e(course_category_label((string) ($course['category'] ?? ''))) ?></span>
                            <h3><?= e((string) $course['title']) ?></h3>
                            <p><?= e((string) $course['description']) ?></p>
                            <div class="learner-meta">
                                <span><?= (int) $stats['lessons'] ?> บทเรียน</span>
                                <span><?= (int) $stats['questions'] ?> ข้อสอบ</span>
                                <?php if (!empty($stats['video_duration_seconds'])): ?><span>เวลาเรียน <?= e(format_learning_duration((int) $stats['video_duration_seconds'])) ?></span><?php endif; ?>
                            </div>
                            <div class="learner-progress"><span style="width: <?= $rowProgress ?>%"></span></div>
                            <div class="learner-course-actions">
                                <?php if (!$attempt): ?>
                                    <?php if ($loggedInUser): ?>
                                        <form action="start.php" method="post">
                                            <input type="hidden" name="course_id" value="<?= $courseId ?>">
                                            <button class="learner-btn learner-btn-outline">เริ่มเรียน</button>
                                        </form>
                                    <?php elseif ($isPublicCourse): ?>
                                        <a class="learner-btn learner-btn-outline" href="start.php?course_id=<?= $courseId ?>">กรอกชื่อและเริ่มเรียน</a>
                                    <?php else: ?>
                                        <a class="learner-btn learner-btn-primary" href="auth/login.php">เข้าสู่ระบบเพื่อเริ่มเรียน</a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <a class="learner-btn learner-btn-outline" href="<?= e(learner_continue_url($attempt)) ?>"><?= $attempt['status'] === 'passed' ? 'ดูผลการเรียน' : 'เรียนต่อ' ?></a>
                                <?php endif; ?>
                                <?php if ($certificateAttempt): ?>
                                    <a class="learner-btn learner-btn-certificate" href="<?= e(attempt_url('certificate.php', $certificateAttempt)) ?>">เปิดเกียรติบัตร</a>
                                <?php endif; ?>
                                <?php if ($attempt && $attempt['status'] === 'passed' && (int) $course['allow_retake'] === 1): ?>
                                    <?php if ($loggedInUser): ?>
                                        <form action="start.php" method="post">
                                            <input type="hidden" name="course_id" value="<?= $courseId ?>">
                                            <button class="learner-btn learner-btn-muted">เรียนซ้ำ</button>
                                        </form>
                                    <?php else: ?>
                                        <a class="learner-btn learner-btn-muted" href="start.php?course_id=<?= $courseId ?>">เรียนซ้ำ</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
                <div class="learner-empty">
                    <?= $hasCourseFilters ? 'ไม่พบหลักสูตรที่ตรงกับคำค้นหรือหมวดหมู่ที่เลือก' : 'ยังไม่มีหลักสูตรที่เปิดให้เรียนในขณะนี้' ?>
                    <?php if ($hasCourseFilters): ?>
                        <a href="index.php#learning-library">ล้างตัวกรอง</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="learner-certificates">
            <div class="learner-section-heading">
                <div>
                    <p class="learner-eyebrow">ความสำเร็จของคุณ</p>
                    <h2>เกียรติบัตรที่ได้รับ</h2>
                    <p>เกียรติบัตรจะแสดงเฉพาะหลักสูตรที่คุณผ่านเกณฑ์แล้วเท่านั้น</p>
                </div>
                <span class="learner-course-count"><?= count($certificateAttempts) ?> ใบ</span>
            </div>
            <?php if ($certificateAttempts): ?>
                <div class="learner-certificate-list">
                    <?php foreach ($certificateAttempts as $certificateAttempt): ?>
                        <article class="learner-certificate-row">
                            <span class="learner-certificate-icon">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z"/><path d="m9 13-1 8 4-2 4 2-1-8"/></svg>
                            </span>
                            <div>
                                <strong><?= e((string) $certificateAttempt['course_title']) ?></strong>
                                <span>ออกเมื่อ <?= e(date('d/m/Y', strtotime((string) $certificateAttempt['updated_at']))) ?></span>
                            </div>
                            <a class="learner-btn learner-btn-outline" href="<?= e(attempt_url('certificate.php', $certificateAttempt)) ?>">เปิดเกียรติบัตร</a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="learner-empty">เมื่อผ่านหลักสูตร เกียรติบัตรของคุณจะแสดงในส่วนนี้</div>
            <?php endif; ?>
        </section>
    </div>
</section>
<?php render_footer(); ?>
