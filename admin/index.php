<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_admin();
ensure_learning_access_columns();
ensure_curriculum_tables();

if (!database_ready()) {
    redirect('../install.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $action = (string) post('action');
        if ($action === 'toggle_course_publish') {
            $isPublished = (string) post('is_published') === '1';
            $courseTitle = update_course_publish_status((int) post('course_id'), $isPublished);
            flash(($isPublished ? 'เปิดเผยแพร่' : 'ปิดเผยแพร่') . 'หลักสูตร "' . $courseTitle . '" แล้ว');
        } elseif ($action === 'delete_course') {
            $deletedTitle = delete_course((int) post('course_id'));
            flash('ลบหลักสูตร "' . $deletedTitle . '" แล้ว');
        }
    } catch (Throwable $exception) {
        flash($exception->getMessage(), 'error');
    }

    redirect('index.php');
}

$courses = db()->query(
    "SELECT c.*,
        (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lesson_count,
        (SELECT COUNT(*) FROM curriculum_items ci WHERE ci.course_id = c.id) AS curriculum_count,
        (SELECT COUNT(*) FROM curriculum_items ci WHERE ci.course_id = c.id AND ci.item_type = 'quiz_set') AS quiz_set_count,
        (SELECT COUNT(*) FROM attempts a WHERE a.course_id = c.id) AS learner_count
     FROM courses c
     ORDER BY c.created_at DESC, c.id DESC"
)->fetchAll();

$publishedCourseCount = 0;
$totalCurriculumCount = 0;
$totalLessonCount = 0;
$totalQuizSetCount = 0;
$totalLearnerCount = 0;
foreach ($courses as $course) {
    $publishedCourseCount += (int) $course['is_published'] === 1 ? 1 : 0;
    $totalCurriculumCount += (int) $course['curriculum_count'];
    $totalLessonCount += (int) $course['lesson_count'];
    $totalQuizSetCount += (int) $course['quiz_set_count'];
    $totalLearnerCount += (int) $course['learner_count'];
}
$certificateCount = (int) db()->query(
    'SELECT COUNT(*) FROM attempts WHERE certificate_code IS NOT NULL AND certificate_code <> ""'
)->fetchColumn();
$latestCourse = $courses[0] ?? null;

function admin_course_icon(string $icon): string
{
    return match ($icon) {
        'edit' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
        'list' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></svg>',
        'quiz' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 11h6"/><path d="M9 15h4"/><path d="M6 3h12a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M9 7h6"/></svg>',
        'award' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z"/><path d="m9 13-1 8 4-2 4 2-1-8"/></svg>',
        'trash' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M6 7l1 14h10l1-14"/><path d="M9 7V4h6v3"/></svg>',
        default => '',
    };
}

function admin_dashboard_icon(string $icon): string
{
    return match ($icon) {
        'plus' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>',
        'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'score' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 16v-5"/><path d="M12 16V8"/><path d="M16 16v-7"/></svg>',
        'certificate' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M8 8h8"/><path d="M8 12h5"/><path d="m15 17 2 3 2-3"/></svg>',
        'course' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5Z"/><path d="M8 7h8"/><path d="M8 11h6"/></svg>',
        'media' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h18"/><path d="M6 3l2 4"/><path d="M12 3l2 4"/><path d="M18 3l2 4"/><rect x="3" y="3" width="18" height="18" rx="2"/><path d="m10 12 5 3-5 3Z"/></svg>',
        'quiz' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 11h6"/><path d="M9 15h4"/><path d="M7 3h10a2 2 0 0 1 2 2v14l-3-2-3 2-3-2-3 2V5a2 2 0 0 1 2-2Z"/></svg>',
        'logout' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M21 19V5a2 2 0 0 0-2-2h-5"/></svg>',
        default => '',
    };
}

render_header('หลังบ้าน', 'admin');
?>
<section class="admin-dashboard-page mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <div class="admin-dashboard-hero">
        <div class="admin-hero-copy">
            <span class="admin-hero-label">SENA Learning Admin</span>
            <h1>หลังบ้านจัดการเรียนรู้</h1>
            <p>ควบคุมหลักสูตร เนื้อหา ข้อสอบ ผู้เรียน และเกียรติบัตรจากศูนย์กลางเดียว พร้อมดูภาพรวมการใช้งานล่าสุดได้ทันที</p>
            <div class="admin-hero-actions">
                <a class="admin-primary-button" href="course_form.php">
                    <?= admin_dashboard_icon('plus') ?>
                    <span>เพิ่มหลักสูตร</span>
                </a>
                <a class="admin-secondary-button" href="attempts.php">
                    <?= admin_dashboard_icon('score') ?>
                    <span>ดูผู้เรียนและคะแนน</span>
                </a>
            </div>
        </div>
        <div class="admin-hero-panel" aria-label="ภาพรวมหลังบ้าน">
            <div>
                <span>หลักสูตรทั้งหมด</span>
                <strong><?= count($courses) ?></strong>
            </div>
            <div>
                <span>เปิดเผยแพร่</span>
                <strong><?= $publishedCourseCount ?></strong>
            </div>
            <div>
                <span>รายการเข้าเรียน</span>
                <strong><?= $totalLearnerCount ?></strong>
            </div>
            <div>
                <span>เกียรติบัตร</span>
                <strong><?= $certificateCount ?></strong>
            </div>
        </div>
    </div>

    <div class="admin-dashboard-summary">
        <article>
            <span>ลำดับเรียนรู้</span>
            <strong><?= $totalCurriculumCount ?></strong>
            <small>รายการในทุกหลักสูตร</small>
        </article>
        <article>
            <span>บทเรียน / สื่อ</span>
            <strong><?= $totalLessonCount ?></strong>
            <small>บทเรียนที่พร้อมจัดเรียง</small>
        </article>
        <article>
            <span>ชุดข้อสอบ</span>
            <strong><?= $totalQuizSetCount ?></strong>
            <small>ชุดข้อสอบกลางในระบบ</small>
        </article>
        <article>
            <span>อัปเดตล่าสุด</span>
            <strong><?= $latestCourse ? e((string) $latestCourse['title']) : '-' ?></strong>
            <small><?= $latestCourse ? 'หลักสูตรล่าสุดในระบบ' : 'ยังไม่มีหลักสูตร' ?></small>
        </article>
    </div>

    <div class="admin-quick-grid" aria-label="ทางลัดหลังบ้าน">
        <a href="course_form.php" class="admin-quick-card">
            <span class="admin-quick-icon"><?= admin_dashboard_icon('course') ?></span>
            <span class="admin-quick-kicker">หลักสูตร</span>
            <strong>สร้างและตั้งค่า</strong>
            <span>ชื่อหลักสูตร รูปปก เกณฑ์ผ่าน และสถานะเผยแพร่</span>
        </a>
        <a href="<?= $courses ? 'lessons.php?course_id=' . (int) $courses[0]['id'] : 'course_form.php' ?>" class="admin-quick-card">
            <span class="admin-quick-icon"><?= admin_dashboard_icon('media') ?></span>
            <span class="admin-quick-kicker">บทเรียน / สื่อ</span>
            <strong>จัดเนื้อหาเรียนรู้</strong>
            <span>ลากเรียงบทเรียน สื่อ และข้อสอบ พร้อมกำหนดการปลดล็อก</span>
        </a>
        <a href="<?= $courses ? 'questions.php?course_id=' . (int) $courses[0]['id'] : 'course_form.php' ?>" class="admin-quick-card">
            <span class="admin-quick-icon"><?= admin_dashboard_icon('quiz') ?></span>
            <span class="admin-quick-kicker">ข้อสอบ</span>
            <strong>นำเข้า JSON / Excel</strong>
            <span>สร้างชุดข้อสอบกลาง แล้วเลือกนำไปใช้ในส่วนใดก็ได้</span>
        </a>
        <a href="certificate_settings.php" class="admin-quick-card">
            <span class="admin-quick-icon"><?= admin_dashboard_icon('certificate') ?></span>
            <span class="admin-quick-kicker">เกียรติบัตร</span>
            <strong>ออกแบบลากวาง</strong>
            <span>อัปโหลดพื้นหลัง โลโก้ ลายเซ็น และจัดตำแหน่งได้</span>
        </a>
        <a href="users.php" class="admin-quick-card">
            <span class="admin-quick-icon"><?= admin_dashboard_icon('users') ?></span>
            <span class="admin-quick-kicker">ผู้ใช้</span>
            <strong>จัดการบัญชี</strong>
            <span>ค้นหาบัญชี แก้ชื่อ และดูสรุปผลการเรียนรายคน</span>
        </a>
    </div>

    <div class="admin-course-panel">
        <div class="admin-course-panel-header">
            <div>
                <h2>รายการหลักสูตร</h2>
                <p>จัดการเนื้อหา ข้อสอบ เกียรติบัตร และสถานะเผยแพร่จากตารางเดียว</p>
            </div>
            <a class="admin-panel-action" href="course_form.php">
                <?= admin_dashboard_icon('plus') ?>
                <span>เพิ่มหลักสูตร</span>
            </a>
        </div>
        <div class="admin-course-table-wrap">
            <table class="admin-course-table">
                <thead>
                    <tr>
                        <th>หลักสูตร</th>
                        <th>ลำดับเรียนรู้</th>
                        <th>บทเรียน / สื่อ</th>
                        <th>ชุดข้อสอบ</th>
                        <th>ผู้เรียน</th>
                        <th>การเข้าเรียน</th>
                        <th>เผยแพร่</th>
                        <th class="admin-course-actions-head">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courses as $course): ?>
                        <?php
                        $deleteMessage = 'ยืนยันการลบหลักสูตร ' . (string) $course['title'] . '? ข้อมูลบทเรียน ข้อสอบ ความคืบหน้าผู้เรียน และเกียรติบัตรของหลักสูตรนี้จะถูกลบด้วย';
                        $deleteMessageJson = json_encode($deleteMessage, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG);
                        $isPublished = (int) $course['is_published'] === 1;
                        ?>
                        <tr>
                            <td class="admin-course-main-cell">
                                <div class="admin-course-title-block">
                                    <span class="admin-course-category"><?= e(course_category_label((string) ($course['category'] ?? ''))) ?></span>
                                    <strong><?= e($course['title']) ?></strong>
                                    <span><?= e($course['description']) ?></span>
                                </div>
                            </td>
                            <td><span class="admin-metric-pill"><?= (int) $course['curriculum_count'] ?></span></td>
                            <td><span class="admin-metric-pill"><?= (int) $course['lesson_count'] ?></span></td>
                            <td><span class="admin-metric-pill"><?= (int) $course['quiz_set_count'] ?></span></td>
                            <td><span class="admin-metric-pill is-learner"><?= (int) $course['learner_count'] ?></span></td>
                            <td>
                                <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-extrabold <?= course_is_public($course) ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-blue-200 bg-blue-50 text-blue-700' ?>">
                                    <?= e(course_access_label((string) ($course['access_mode'] ?? ''))) ?>
                                </span>
                            </td>
                            <td>
                                <form method="post" class="admin-publish-form">
                                    <input type="hidden" name="action" value="toggle_course_publish">
                                    <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
                                    <input type="hidden" name="is_published" value="<?= $isPublished ? 0 : 1 ?>">
                                    <button class="admin-publish-switch <?= $isPublished ? 'is-on' : 'is-off' ?>" type="submit" aria-label="<?= e(($isPublished ? 'ปิดเผยแพร่ ' : 'เปิดเผยแพร่ ') . (string) $course['title']) ?>">
                                        <span class="admin-publish-track"><span class="admin-publish-knob"></span></span>
                                        <span class="admin-publish-label"><?= $isPublished ? 'เปิด' : 'ปิด' ?></span>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <div class="admin-course-actions">
                                    <a class="admin-action-button" href="course_form.php?id=<?= (int) $course['id'] ?>" title="แก้ไขหลักสูตร">
                                        <?= admin_course_icon('edit') ?><span>แก้ไข</span>
                                    </a>
                                    <a class="admin-action-button" href="lessons.php?course_id=<?= (int) $course['id'] ?>" title="จัดลำดับเรียนรู้">
                                        <?= admin_course_icon('list') ?><span>จัดลำดับ</span>
                                    </a>
                                    <a class="admin-action-button" href="questions.php?course_id=<?= (int) $course['id'] ?>" title="คลังข้อสอบ">
                                        <?= admin_course_icon('quiz') ?><span>ข้อสอบ</span>
                                    </a>
                                    <a class="admin-action-button" href="certificate_settings.php?course_id=<?= (int) $course['id'] ?>" title="เกียรติบัตร">
                                        <?= admin_course_icon('award') ?><span>เกียรติบัตร</span>
                                    </a>
                                    <form method="post" onsubmit="return confirm(<?= e($deleteMessageJson ?: '""') ?>)">
                                        <input type="hidden" name="action" value="delete_course">
                                        <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
                                        <button class="admin-action-button is-danger" type="submit" title="ลบหลักสูตร">
                                            <?= admin_course_icon('trash') ?><span>ลบ</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php render_footer(); ?>
