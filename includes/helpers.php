<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/xlsx.php';

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function app_base_url(): string
{
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $dir = dirname($scriptName);
    $dir = preg_replace('#/(?:admin|auth)$#', '', $dir) ?: '';
    $dir = rtrim($dir, '/');

    return $dir === '' || $dir === '.' ? '' : $dir;
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function flash(?string $message = null, string $type = 'success'): ?array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function post(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

function get_int(string $key, int $default = 0): int
{
    return isset($_GET[$key]) ? (int) $_GET[$key] : $default;
}

function generate_token(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

function ensure_admin_users_table(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS admin_users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            display_name VARCHAR(255) NOT NULL,
            last_login_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if ((int) db()->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() === 0) {
        $stmt = db()->prepare(
            'INSERT INTO admin_users (username, password_hash, display_name) VALUES (?, ?, ?)'
        );
        $stmt->execute([
            mb_strtolower(trim(ADMIN_USERNAME), 'UTF-8'),
            password_hash(ADMIN_PIN, PASSWORD_BCRYPT),
            'ผู้ดูแลระบบหลัก',
        ]);
    }

    $checked = true;
}

function normalize_admin_username(string $username): string
{
    return mb_strtolower(trim($username), 'UTF-8');
}

function current_admin_user(): ?array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['admin_user_id'])) {
        return null;
    }

    ensure_admin_users_table();
    $stmt = db()->prepare('SELECT * FROM admin_users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['admin_user_id']]);
    $admin = $stmt->fetch();
    if (!$admin) {
        unset($_SESSION['admin_user_id'], $_SESSION['admin_ok']);
        return null;
    }

    return (array) $admin;
}

function current_admin(): bool
{
    return current_admin_user() !== null;
}

function require_admin(): void
{
    if (!current_admin()) {
        redirect('login.php');
    }
}

function login_admin_user(string $username, string $password): ?array
{
    ensure_admin_users_table();
    $stmt = db()->prepare('SELECT * FROM admin_users WHERE username = ?');
    $stmt->execute([normalize_admin_username($username)]);
    $admin = $stmt->fetch();
    if (!$admin || !password_verify($password, (string) $admin['password_hash'])) {
        return null;
    }

    db()->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?')
        ->execute([(int) $admin['id']]);
    return (array) $admin;
}

function create_admin_user(string $username, string $password, string $displayName): array
{
    ensure_admin_users_table();
    $username = normalize_admin_username($username);
    $displayName = trim($displayName);
    if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
        throw new RuntimeException('ชื่อผู้ใช้ admin ต้องมี 3-50 ตัว และใช้ a-z, 0-9, จุด, ขีด หรือขีดล่าง');
    }
    if ($displayName === '' || strlen($password) < 8) {
        throw new RuntimeException('กรุณากรอกชื่อแสดงผล และใช้รหัสผ่าน admin อย่างน้อย 8 ตัวอักษร');
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO admin_users (username, password_hash, display_name) VALUES (?, ?, ?)'
        );
        $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT), $displayName]);
    } catch (PDOException $exception) {
        if ((string) $exception->getCode() === '23000') {
            throw new RuntimeException('ชื่อผู้ใช้ admin นี้มีอยู่แล้ว');
        }
        throw $exception;
    }

    $stmt = db()->prepare('SELECT * FROM admin_users WHERE id = ?');
    $stmt->execute([(int) db()->lastInsertId()]);
    return (array) $stmt->fetch();
}

function update_admin_user_password(int $adminId, string $password): void
{
    ensure_admin_users_table();
    if ($adminId <= 0 || strlen($password) < 8) {
        throw new RuntimeException('รหัสผ่าน admin ใหม่ต้องมีอย่างน้อย 8 ตัวอักษร');
    }

    $stmt = db()->prepare('UPDATE admin_users SET password_hash = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([password_hash($password, PASSWORD_BCRYPT), $adminId]);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('ไม่พบบัญชี admin ที่ต้องการแก้ไข');
    }
}

function delete_admin_user(int $adminId, int $currentAdminId): void
{
    ensure_admin_users_table();
    if ($adminId <= 0 || $adminId === $currentAdminId) {
        throw new RuntimeException('ไม่สามารถลบบัญชี admin ที่กำลังใช้งานอยู่');
    }
    if ((int) db()->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() <= 1) {
        throw new RuntimeException('ระบบต้องมีบัญชี admin อย่างน้อย 1 บัญชี');
    }

    $stmt = db()->prepare('DELETE FROM admin_users WHERE id = ?');
    $stmt->execute([$adminId]);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('ไม่พบบัญชี admin ที่ต้องการลบ');
    }
}

function fetch_course(int $courseId): ?array
{
    ensure_learning_access_columns();
    $stmt = db()->prepare('SELECT * FROM courses WHERE id = ? AND is_published = 1');
    $stmt->execute([$courseId]);
    $course = $stmt->fetch();
    return $course ?: null;
}

function course_categories(): array
{
    return [
        'lifelong' => 'การเรียนรู้ตลอดชีวิต',
        'self_development' => 'การเรียนรู้เพื่อการพัฒนาตนเอง',
        'qualification_level' => 'การเรียนรู้เพื่อคุณวุฒิตามระดับ',
    ];
}

function default_course_category(): string
{
    return 'lifelong';
}

function normalize_course_category(?string $category): string
{
    $category = (string) $category;
    return array_key_exists($category, course_categories()) ? $category : default_course_category();
}

function course_category_label(?string $category): string
{
    $category = normalize_course_category($category);
    return course_categories()[$category];
}

function normalize_course_access_mode(?string $accessMode): string
{
    return $accessMode === 'public' ? 'public' : 'login_required';
}

function course_access_label(?string $accessMode): string
{
    return normalize_course_access_mode($accessMode) === 'public'
        ? 'สาธารณะ'
        : 'ต้องเข้าสู่ระบบ';
}

function course_is_public(array $course): bool
{
    return normalize_course_access_mode((string) ($course['access_mode'] ?? '')) === 'public';
}

function update_course_publish_status(int $courseId, bool $isPublished): string
{
    if ($courseId <= 0) {
        throw new RuntimeException('ไม่พบหลักสูตรที่ต้องการปรับสถานะ');
    }

    $stmt = db()->prepare('SELECT title FROM courses WHERE id = ?');
    $stmt->execute([$courseId]);
    $title = $stmt->fetchColumn();
    if ($title === false) {
        throw new RuntimeException('ไม่พบหลักสูตรที่ต้องการปรับสถานะ');
    }

    $update = db()->prepare('UPDATE courses SET is_published = ? WHERE id = ?');
    $update->execute([$isPublished ? 1 : 0, $courseId]);

    return (string) $title;
}

function delete_course(int $courseId): string
{
    if ($courseId <= 0) {
        throw new RuntimeException('ไม่พบหลักสูตรที่ต้องการลบ');
    }

    $stmt = db()->prepare('SELECT title FROM courses WHERE id = ?');
    $stmt->execute([$courseId]);
    $title = $stmt->fetchColumn();
    if ($title === false) {
        throw new RuntimeException('ไม่พบหลักสูตรที่ต้องการลบ');
    }

    $sharedQuizSets = db()->prepare(
        'SELECT COUNT(DISTINCT qs.id)
         FROM quiz_sets qs
         INNER JOIN curriculum_items ci ON ci.quiz_set_id = qs.id
         WHERE qs.course_id = ? AND ci.course_id <> ?'
    );
    $sharedQuizSets->execute([$courseId, $courseId]);
    if ((int) $sharedQuizSets->fetchColumn() > 0) {
        throw new RuntimeException('หลักสูตรนี้มีชุดข้อสอบที่ถูกใช้อยู่ในคอร์สอื่น กรุณานำชุดข้อสอบออกจากคอร์สอื่นก่อนลบ');
    }

    $delete = db()->prepare('DELETE FROM courses WHERE id = ?');
    $delete->execute([$courseId]);
    if ($delete->rowCount() === 0) {
        throw new RuntimeException('ไม่พบหลักสูตรที่ต้องการลบ');
    }

    return (string) $title;
}

function course_stats(int $courseId): array
{
    ensure_lesson_video_settings_columns();
    $pdo = db();
    $lessons = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(video_duration_seconds), 0) FROM lessons WHERE course_id = ?');
    $lessons->execute([$courseId]);
    $lessonStats = $lessons->fetch(PDO::FETCH_NUM);

    $pre = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE course_id = ? AND quiz_type = 'pre'");
    $pre->execute([$courseId]);

    $post = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE course_id = ? AND quiz_type = 'post'");
    $post->execute([$courseId]);
    $questions = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE course_id = ?');
    $questions->execute([$courseId]);

    return [
        'lessons' => (int) ($lessonStats[0] ?? 0),
        'video_duration_seconds' => (int) ($lessonStats[1] ?? 0),
        'pre_questions' => (int) $pre->fetchColumn(),
        'post_questions' => (int) $post->fetchColumn(),
        'questions' => (int) $questions->fetchColumn(),
    ];
}

function format_learning_duration(?int $seconds): string
{
    if (($seconds ?? 0) <= 0) {
        return '';
    }

    $minutes = max(1, (int) ceil($seconds / 60));
    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;
    if ($hours === 0) {
        return $minutes . ' นาที';
    }
    if ($remainingMinutes === 0) {
        return $hours . ' ชม.';
    }

    return $hours . ' ชม. ' . $remainingMinutes . ' นาที';
}

function get_attempt(int $attemptId): ?array
{
    ensure_learning_access_columns();
    $stmt = db()->prepare(
        'SELECT a.*, c.title AS course_title, c.pass_percent, c.certificate_title, c.allow_retake
         FROM attempts a
         INNER JOIN courses c ON c.id = a.course_id
         WHERE a.id = ?'
    );
    $stmt->execute([$attemptId]);
    $attempt = $stmt->fetch();
    return $attempt ?: null;
}

function require_attempt(): array
{
    $attemptId = get_int('attempt');
    $token = (string) ($_GET['token'] ?? '');

    if ($attemptId <= 0 || $token === '') {
        flash('ไม่พบข้อมูลการเข้าเรียน กรุณาเริ่มจากหน้าแรก', 'error');
        redirect('index.php');
    }

    $stmt = db()->prepare(
        'SELECT a.*, c.title AS course_title, c.description AS course_description, c.pass_percent, c.certificate_title, c.allow_retake, c.access_mode
         FROM attempts a
         INNER JOIN courses c ON c.id = a.course_id
         WHERE a.id = ? AND a.access_token = ?'
    );
    $stmt->execute([$attemptId, $token]);
    $attempt = $stmt->fetch();

    if ($attempt && $attempt['user_id'] !== null) {
        $user = current_user();
        if (!$user || (int) $attempt['user_id'] !== (int) $user['id']) {
            $attempt = false;
        }
    } elseif ($attempt) {
        if (!course_is_public($attempt) || !guest_attempt_is_remembered($attemptId, $token)) {
            $attempt = false;
        }
    }

    if (!$attempt) {
        flash('ลิงก์การเข้าเรียนไม่ถูกต้องหรือหมดอายุ', 'error');
        redirect('index.php');
    }

    return $attempt;
}

function attempt_url(string $page, array $attempt, array $extra = []): string
{
    $query = array_merge([
        'attempt' => $attempt['id'],
        'token' => $attempt['access_token'],
    ], $extra);

    return $page . '?' . http_build_query($query);
}

function app_absolute_url(string $path = ''): string
{
    if ($path !== '' && preg_match('#^https?://#i', $path)) {
        return $path;
    }

    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
}

function learning_export_api_key(): string
{
    return defined('LEARNING_EXPORT_API_KEY') ? trim((string) LEARNING_EXPORT_API_KEY) : '';
}

function request_api_key(): string
{
    $key = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($key !== '') {
        return $key;
    }

    $authorization = trim((string) (
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ''
    ));
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim((string) $matches[1]);
    }

    return trim((string) ($_GET['api_key'] ?? $_POST['api_key'] ?? ''));
}

function learning_export_api_authorized(): bool
{
    $expected = learning_export_api_key();
    $incoming = request_api_key();

    return $expected !== '' && $incoming !== '' && hash_equals($expected, $incoming);
}

function get_or_create_attempt(int $courseId, int $userId, string $learnerName): array
{
    ensure_learning_access_columns();
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT a.*, c.title AS course_title, c.pass_percent, c.certificate_title, c.allow_retake
         FROM attempts a
         INNER JOIN courses c ON c.id = a.course_id
         WHERE a.course_id = ? AND a.user_id = ?
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT 1'
    );
    $stmt->execute([$courseId, $userId]);
    $latest = $stmt->fetch();

    if ($latest && $latest['status'] !== 'passed') {
        return $latest;
    }
    if ($latest && (int) $latest['allow_retake'] !== 1) {
        throw new RuntimeException('หลักสูตรนี้ไม่เปิดให้เรียนซ้ำ คุณสามารถเปิดดูผลการเรียนเดิมได้');
    }

    $token = generate_token(12);
    $stmt = $pdo->prepare(
        'INSERT INTO attempts (course_id, user_id, learner_name, access_token, status)
         VALUES (?, ?, ?, ?, "registered")'
    );
    $stmt->execute([$courseId, $userId, trim($learnerName), $token]);

    return get_attempt((int) $pdo->lastInsertId());
}

function remember_guest_attempt(array $attempt): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $attemptId = (int) ($attempt['id'] ?? 0);
    $token = (string) ($attempt['access_token'] ?? '');
    if ($attemptId > 0 && $token !== '') {
        $_SESSION['guest_attempt_tokens'][$attemptId] = $token;
    }
}

function guest_attempt_is_remembered(int $attemptId, string $token): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $remembered = (string) ($_SESSION['guest_attempt_tokens'][$attemptId] ?? '');
    return $remembered !== '' && hash_equals($remembered, $token);
}

function session_guest_attempts(bool $certificatesOnly = false): array
{
    ensure_learning_access_columns();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $tokens = $_SESSION['guest_attempt_tokens'] ?? [];
    if (!is_array($tokens) || $tokens === []) {
        return [];
    }

    $ids = array_values(array_filter(array_map('intval', array_keys($tokens)), static fn (int $id): bool => $id > 0));
    if ($ids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $certificateSql = $certificatesOnly ? ' AND a.certificate_code IS NOT NULL' : '';
    $stmt = db()->prepare(
        "SELECT a.*, c.title AS course_title, c.pass_percent, c.certificate_title, c.allow_retake, c.access_mode
         FROM attempts a
         INNER JOIN courses c ON c.id = a.course_id
         WHERE a.user_id IS NULL AND c.access_mode = 'public' AND a.id IN ({$placeholders}){$certificateSql}
         ORDER BY a.created_at DESC, a.id DESC"
    );
    $stmt->execute($ids);

    $attempts = [];
    foreach ($stmt->fetchAll() as $attempt) {
        $attemptId = (int) $attempt['id'];
        $rememberedToken = (string) ($tokens[$attemptId] ?? '');
        if ($rememberedToken === '' || !hash_equals($rememberedToken, (string) $attempt['access_token'])) {
            continue;
        }
        $courseId = (int) $attempt['course_id'];
        if (!isset($attempts[$courseId])) {
            $attempts[$courseId] = $attempt;
        }
    }

    return $attempts;
}

function get_or_create_guest_attempt(int $courseId, string $learnerName): array
{
    ensure_learning_access_columns();
    $course = fetch_course($courseId);
    if (!$course || !course_is_public($course)) {
        throw new RuntimeException('หลักสูตรนี้ต้องเข้าสู่ระบบก่อนเริ่มเรียน');
    }

    $learnerName = preg_replace('/\s+/u', ' ', trim($learnerName)) ?: '';
    if (mb_strlen($learnerName, 'UTF-8') < 3 || mb_strlen($learnerName, 'UTF-8') > 255 || !preg_match('/\S+\s+\S+/u', $learnerName)) {
        throw new RuntimeException('กรุณากรอกชื่อและนามสกุลให้ครบถ้วน เพื่อใช้ออกเกียรติบัตร');
    }

    $latest = session_guest_attempts()[$courseId] ?? null;
    if ($latest && trim((string) $latest['learner_name']) === $learnerName) {
        if ($latest['status'] !== 'passed') {
            return $latest;
        }
        if ((int) $latest['allow_retake'] !== 1) {
            throw new RuntimeException('หลักสูตรนี้ไม่เปิดให้เรียนซ้ำ คุณสามารถเปิดดูผลการเรียนเดิมได้');
        }
    }

    $token = generate_token(12);
    $stmt = db()->prepare(
        'INSERT INTO attempts (course_id, user_id, learner_name, access_token, status)
         VALUES (?, NULL, ?, ?, "registered")'
    );
    $stmt->execute([$courseId, $learnerName, $token]);
    $attempt = get_attempt((int) db()->lastInsertId());
    if (!$attempt) {
        throw new RuntimeException('ไม่สามารถสร้างรายการเข้าเรียนได้');
    }
    remember_guest_attempt($attempt);

    return $attempt;
}

function latest_user_attempts_by_course(int $userId): array
{
    ensure_learning_access_columns();
    $stmt = db()->prepare(
        'SELECT a.*, c.title AS course_title, c.pass_percent, c.certificate_title, c.allow_retake
         FROM attempts a
         INNER JOIN courses c ON c.id = a.course_id
         WHERE a.user_id = ?
         ORDER BY a.created_at DESC, a.id DESC'
    );
    $stmt->execute([$userId]);
    $attempts = [];
    foreach ($stmt->fetchAll() as $attempt) {
        $courseId = (int) $attempt['course_id'];
        if (!isset($attempts[$courseId])) {
            $attempts[$courseId] = $attempt;
        }
    }

    return $attempts;
}

function user_certificate_attempts_by_course(int $userId): array
{
    ensure_learning_access_columns();
    $stmt = db()->prepare(
        'SELECT a.*, c.title AS course_title, c.pass_percent, c.certificate_title, c.allow_retake
         FROM attempts a
         INNER JOIN courses c ON c.id = a.course_id
         WHERE a.user_id = ? AND a.certificate_code IS NOT NULL
         ORDER BY a.created_at ASC, a.id ASC'
    );
    $stmt->execute([$userId]);
    $attempts = [];
    foreach ($stmt->fetchAll() as $attempt) {
        $courseId = (int) $attempt['course_id'];
        if (!isset($attempts[$courseId])) {
            $attempts[$courseId] = $attempt;
        }
    }

    return $attempts;
}

function certificate_attempt_for_attempt(array $attempt): ?array
{
    if (!empty($attempt['certificate_code'])) {
        return $attempt;
    }
    if (empty($attempt['course_id'])) {
        return null;
    }

    if (empty($attempt['user_id'])) {
        $attempts = session_guest_attempts(true);
        return $attempts[(int) $attempt['course_id']] ?? null;
    }

    $attempts = user_certificate_attempts_by_course((int) $attempt['user_id']);
    return $attempts[(int) $attempt['course_id']] ?? null;
}

function certificate_attempt_by_code(string $certificateCode): ?array
{
    ensure_learning_access_columns();
    $certificateCode = trim($certificateCode);
    if ($certificateCode === '') {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT a.*,
                c.title AS course_title,
                c.description AS course_description,
                c.category AS course_category,
                c.cover_url AS course_cover_url,
                c.pass_percent,
                c.certificate_title,
                c.allow_retake,
                "course" AS certificate_source
         FROM attempts a
         INNER JOIN courses c ON c.id = a.course_id
         WHERE a.certificate_code = ? AND a.status = "passed"
         LIMIT 1'
    );
    $stmt->execute([$certificateCode]);
    $attempt = $stmt->fetch();
    if ($attempt) {
        return $attempt;
    }

    ensure_public_quiz_sharing_tables();
    $stmt = db()->prepare(
        'SELECT pqa.*,
                qs.course_id,
                pqs.public_title AS course_title,
                pqs.welcome_message AS course_description,
                c.category AS course_category,
                c.cover_url AS course_cover_url,
                pqs.pass_percent,
                c.certificate_title,
                1 AS allow_retake,
                pqa.submitted_at AS issued_at,
                "public_quiz" AS certificate_source
         FROM public_quiz_attempts pqa
         INNER JOIN public_quiz_shares pqs ON pqs.id = pqa.share_id
         INNER JOIN quiz_sets qs ON qs.id = pqs.quiz_set_id
         INNER JOIN courses c ON c.id = qs.course_id
         WHERE pqa.certificate_code = ? AND pqa.status = "passed"
         LIMIT 1'
    );
    $stmt->execute([$certificateCode]);
    $attempt = $stmt->fetch();

    return $attempt ?: null;
}

function certificate_code_for_attempt(array $attempt): ?string
{
    $certificateAttempt = certificate_attempt_for_attempt($attempt);
    if ($certificateAttempt) {
        return null;
    }

    return 'SENA-' . date('Ymd') . '-' . strtoupper(substr(generate_token(4), 0, 8));
}

function attempt_progress_percent(array $attempt): int
{
    if (($attempt['status'] ?? '') === 'passed') {
        return 100;
    }

    $summary = curriculum_summary($attempt);
    if ((int) $summary['required'] === 0) {
        return 0;
    }

    return (int) round(((int) $summary['completed'] / (int) $summary['required']) * 100);
}

function answer_to_text(array $answer): string
{
    if (isset($answer['value'])) {
        return trim((string) $answer['value']);
    }

    if (isset($answer['values']) && is_array($answer['values'])) {
        $values = array_map('strval', $answer['values']);
        sort($values);
        return implode('|', $values);
    }

    return '';
}

function normalize_answer(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return $value;
}

function score_quiz(int $courseId, string $quizType, array $submitted): array
{
    $stmt = db()->prepare(
        'SELECT * FROM questions
         WHERE course_id = ? AND quiz_type = ?
         ORDER BY sort_order, id'
    );
    $stmt->execute([$courseId, $quizType]);
    $questions = $stmt->fetchAll();
    $total = count($questions);
    $correct = 0;
    $details = [];

    foreach ($questions as $question) {
        $qid = (string) $question['id'];
        $given = $submitted[$qid] ?? null;
        $correctAnswers = json_decode($question['correct_answers'], true) ?: [];
        $isCorrect = false;

        if ($question['question_type'] === 'multiple_choice') {
            $givenValues = is_array($given) ? array_map('strval', $given) : [];
            sort($givenValues);
            $expected = array_map('strval', $correctAnswers);
            sort($expected);
            $isCorrect = $givenValues === $expected;
        } else {
            $givenText = normalize_answer((string) $given);
            foreach ($correctAnswers as $answer) {
                if ($givenText === normalize_answer((string) $answer)) {
                    $isCorrect = true;
                    break;
                }
            }
        }

        if ($isCorrect) {
            $correct++;
        }

        $details[] = [
            'question_id' => (int) $question['id'],
            'prompt' => $question['prompt'],
            'given' => $given,
            'correct_answers' => $correctAnswers,
            'is_correct' => $isCorrect,
        ];
    }

    $percent = $total > 0 ? round(($correct / $total) * 100, 2) : 0;

    return compact('total', 'correct', 'percent', 'details');
}

function ensure_course_quiz_shuffle_columns(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $columns = [
        'shuffle_pre_choices' => "ALTER TABLE courses ADD shuffle_pre_choices TINYINT(1) NOT NULL DEFAULT 0 AFTER pass_percent",
        'shuffle_post_choices' => "ALTER TABLE courses ADD shuffle_post_choices TINYINT(1) NOT NULL DEFAULT 0 AFTER shuffle_pre_choices",
    ];

    foreach ($columns as $column => $sql) {
        $stmt = db()->query("SHOW COLUMNS FROM courses LIKE " . db()->quote($column));
        if (!$stmt->fetch()) {
            db()->exec($sql);
        }
    }

    $checked = true;
}

function save_course_quiz_shuffle_settings(int $courseId, bool $shufflePreChoices, bool $shufflePostChoices): void
{
    ensure_course_quiz_shuffle_columns();
    $stmt = db()->prepare(
        'UPDATE courses
         SET shuffle_pre_choices = ?, shuffle_post_choices = ?
         WHERE id = ?'
    );
    $stmt->execute([$shufflePreChoices ? 1 : 0, $shufflePostChoices ? 1 : 0, $courseId]);
}

function quiz_choices_should_shuffle(int $courseId, string $quizType): bool
{
    ensure_course_quiz_shuffle_columns();
    $column = $quizType === 'post' ? 'shuffle_post_choices' : 'shuffle_pre_choices';
    $stmt = db()->prepare("SELECT {$column} FROM courses WHERE id = ?");
    $stmt->execute([$courseId]);

    return (int) $stmt->fetchColumn() === 1;
}

function shuffle_quiz_choices(array $choices, bool $enabled): array
{
    if ($enabled && count($choices) > 1) {
        shuffle($choices);
    }

    return $choices;
}

function save_score(int $attemptId, string $quizType, array $result): void
{
    $pdo = db();
    $columnScore = $quizType === 'pre' ? 'pre_score' : 'post_score';
    $columnTotal = $quizType === 'pre' ? 'pre_total' : 'post_total';
    $status = $quizType === 'pre' ? 'pretest_done' : 'posttest_done';

    $stmt = $pdo->prepare(
        "UPDATE attempts
         SET {$columnScore} = ?, {$columnTotal} = ?, status = ?, updated_at = NOW()
         WHERE id = ?"
    );
    $stmt->execute([$result['correct'], $result['total'], $status, $attemptId]);

    if ($quizType === 'post') {
        $attempt = get_attempt($attemptId);
        $passed = $attempt && $result['percent'] >= (float) $attempt['pass_percent'];
        $status = $passed ? 'passed' : 'posttest_done';
        $certCode = $passed && $attempt ? certificate_code_for_attempt($attempt) : null;
        $stmt = $pdo->prepare(
            'UPDATE attempts
             SET status = ?, certificate_code = COALESCE(certificate_code, ?), updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$status, $certCode, $attemptId]);
    }
}

function format_score(?int $score, ?int $total): string
{
    if ($score === null || $total === null) {
        return '-';
    }

    return $score . '/' . $total;
}

function default_certificate_positions(): array
{
    return [
        'background' => ['x' => 50, 'y' => 50, 'w' => 1024, 'h' => 724],
        'logo' => ['x' => 50, 'y' => 12],
        'title' => ['x' => 50, 'y' => 25],
        'name' => ['x' => 50, 'y' => 37],
        'body' => ['x' => 50, 'y' => 51],
        'course' => ['x' => 50, 'y' => 64],
        'signature_image' => ['x' => 50, 'y' => 75],
        'signature' => ['x' => 50, 'y' => 78],
        'issuer' => ['x' => 50, 'y' => 84.5],
        'code' => ['x' => 50, 'y' => 91],
    ];
}

function get_certificate_settings(int $courseId): array
{
    $stmt = db()->prepare('SELECT * FROM certificate_settings WHERE course_id = ?');
    $stmt->execute([$courseId]);
    $settings = $stmt->fetch() ?: [];

    $settings['issuer_name'] = $settings['issuer_name'] ?? CERTIFICATE_ISSUER;
    $settings['signature_name'] = $settings['signature_name'] ?? CERTIFICATE_SIGNATURE;
    $settings['title_text'] = $settings['title_text'] ?? 'เกียรติบัตรการผ่านหลักสูตร';
    $settings['body_text'] = $settings['body_text'] ?? 'เพื่อแสดงว่าได้ผ่านการเรียนรู้ในหลักสูตร {{course}} โดยผ่านแบบทดสอบหลังเรียนตามเกณฑ์ที่กำหนด';
    $settings['positions'] = clean_certificate_positions((string) ($settings['positions'] ?? ''));
    $settings['positions']['logo'] = normalize_certificate_image_position(
        $settings['positions']['logo'],
        $settings['logo_image'] ?? null
    );
    $settings['positions']['signature_image'] = normalize_certificate_image_position(
        $settings['positions']['signature_image'],
        $settings['signature_image'] ?? null
    );

    return $settings;
}

function normalize_certificate_image_position(array $position, ?string $path): array
{
    if (empty($position['w']) || !$path) {
        return $position;
    }

    $imagePath = __DIR__ . '/../' . ltrim($path, '/');
    if (!is_file($imagePath)) {
        return $position;
    }

    $size = @getimagesize($imagePath);
    if (!$size || empty($size[0]) || empty($size[1])) {
        return $position;
    }

    $position['h'] = round(((float) $position['w'] * (float) $size[1]) / (float) $size[0], 2);
    return $position;
}

function public_upload_url(?string $path): string
{
    if (!$path) {
        return '';
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    return app_base_url() . '/' . ltrim($path, '/');
}

function save_course_cover_upload(int $courseId): ?string
{
    if (empty($_FILES['cover_image']['tmp_name']) || !is_uploaded_file($_FILES['cover_image']['tmp_name'])) {
        return null;
    }

    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    $mime = mime_content_type($_FILES['cover_image']['tmp_name']) ?: '';
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('ภาพปกหลักสูตรต้องเป็น PNG, JPG หรือ WEBP');
    }

    $dir = __DIR__ . '/../storage/uploads/course-covers';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $filename = 'course-' . $courseId . '-cover-' . time() . '.' . $allowed[$mime];
    $target = $dir . '/' . $filename;
    if (!move_uploaded_file($_FILES['cover_image']['tmp_name'], $target)) {
        throw new RuntimeException('อัปโหลดภาพปกหลักสูตรไม่สำเร็จ');
    }

    return 'storage/uploads/course-covers/' . $filename;
}

function save_certificate_upload(string $field, int $courseId): ?string
{
    if (empty($_FILES[$field]['tmp_name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
        return null;
    }

    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    $mime = mime_content_type($_FILES[$field]['tmp_name']) ?: '';
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('ไฟล์ ' . $field . ' ต้องเป็น PNG, JPG หรือ WEBP');
    }

    $dir = __DIR__ . '/../storage/uploads/certificates';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $filename = 'course-' . $courseId . '-' . $field . '-' . time() . '.' . $allowed[$mime];
    $target = $dir . '/' . $filename;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
        throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ');
    }

    return 'storage/uploads/certificates/' . $filename;
}

function clean_certificate_positions(string $json): array
{
    $incoming = json_decode($json, true);
    if (!is_array($incoming)) {
        return default_certificate_positions();
    }

    $positions = default_certificate_positions();
    foreach ($incoming as $key => $item) {
        if (!is_array($item)) {
            continue;
        }

        $defaultX = isset($positions[$key]['x']) ? $positions[$key]['x'] : 50.0;
        $defaultY = isset($positions[$key]['y']) ? $positions[$key]['y'] : 50.0;
        $x = max(0, min(100, (float) ($item['x'] ?? $defaultX)));
        $y = max(0, min(100, (float) ($item['y'] ?? $defaultY)));

        $positions[$key] = [
            'x' => round($x, 2),
            'y' => round($y, 2),
            'w' => clean_certificate_dimension($item['w'] ?? null),
            'h' => clean_certificate_dimension($item['h'] ?? null),
            'fontSize' => isset($item['fontSize']) ? (float) $item['fontSize'] : null,
            'rotate' => isset($item['rotate']) ? (float) $item['rotate'] : null,
            'text' => isset($item['text']) ? (string) $item['text'] : null,
            'color' => isset($item['color']) ? (string) $item['color'] : null,
            'fontFamily' => isset($item['fontFamily']) ? (string) $item['fontFamily'] : null,
            'fontWeight' => isset($item['fontWeight']) ? (string) $item['fontWeight'] : null,
            'textAlign' => isset($item['textAlign']) ? (string) $item['textAlign'] : null,
            'src' => isset($item['src']) ? (string) $item['src'] : null,
        ];
    }

    return $positions;
}

function clean_certificate_dimension($value): ?float
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }

    return round(max(10, min(1500, (float) $value)), 2);
}

function save_certificate_settings(int $courseId, array $data): void
{
    $current = get_certificate_settings($courseId);
    $uploads = [];

    foreach (['background_image', 'logo_image', 'signature_image'] as $field) {
        $uploaded = save_certificate_upload($field, $courseId);
        if ($uploaded !== null) {
            $uploads[$field] = $uploaded;
        } elseif ($field === 'background_image' && array_key_exists('existing_background_image', $data)) {
            $uploads[$field] = trim((string) $data['existing_background_image']) ?: null;
        } else {
            $uploads[$field] = !empty($data['existing_' . $field])
                ? $data['existing_' . $field]
                : ($current[$field] ?? null);
        }
    }

    $positions = clean_certificate_positions((string) ($data['positions'] ?? ''));
    $stmt = db()->prepare(
        'INSERT INTO certificate_settings
            (course_id, background_image, logo_image, signature_image, issuer_name, signature_name, title_text, body_text, positions)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            background_image = VALUES(background_image),
            logo_image = VALUES(logo_image),
            signature_image = VALUES(signature_image),
            issuer_name = VALUES(issuer_name),
            signature_name = VALUES(signature_name),
            title_text = VALUES(title_text),
            body_text = VALUES(body_text),
            positions = VALUES(positions),
            updated_at = NOW()'
    );
    $stmt->execute([
        $courseId,
        $uploads['background_image'],
        $uploads['logo_image'],
        $uploads['signature_image'],
        trim((string) ($data['issuer_name'] ?? CERTIFICATE_ISSUER)) ?: CERTIFICATE_ISSUER,
        trim((string) ($data['signature_name'] ?? CERTIFICATE_SIGNATURE)) ?: CERTIFICATE_SIGNATURE,
        trim((string) ($data['title_text'] ?? 'เกียรติบัตรการผ่านหลักสูตร')) ?: 'เกียรติบัตรการผ่านหลักสูตร',
        trim((string) ($data['body_text'] ?? '')),
        json_encode($positions, JSON_UNESCAPED_UNICODE),
    ]);
}

function copy_certificate_layout_positions(int $sourceCourseId, int $targetCourseId): void
{
    if ($sourceCourseId <= 0 || $sourceCourseId === $targetCourseId) {
        throw new RuntimeException('กรุณาเลือกหลักสูตรต้นแบบที่แตกต่างจากหลักสูตรปัจจุบัน');
    }

    $stmt = db()->prepare('SELECT positions FROM certificate_settings WHERE course_id = ?');
    $stmt->execute([$sourceCourseId]);
    $sourcePositions = $stmt->fetchColumn();
    if (!$sourcePositions) {
        throw new RuntimeException('หลักสูตรต้นแบบนี้ยังไม่มีการตั้งค่าตำแหน่งเกียรติบัตร');
    }

    $sourceSettings = get_certificate_settings($sourceCourseId);
    $positions = clean_certificate_positions(json_encode($sourceSettings['positions'], JSON_UNESCAPED_UNICODE));

    $stmt = db()->prepare(
        'INSERT INTO certificate_settings (course_id, positions)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE
            positions = VALUES(positions),
            updated_at = NOW()'
    );
    $stmt->execute([
        $targetCourseId,
        json_encode($positions, JSON_UNESCAPED_UNICODE),
    ]);
}

function certificate_text(string $template, array $attempt): string
{
    $date = date('d/m/Y');
    $replacements = [
        '{{name}}' => (string) $attempt['learner_name'],
        '{{course}}' => (string) $attempt['course_title'],
        '{{code}}' => (string) ($attempt['certificate_code'] ?? ''),
        '{{date}}' => $date,
    ];

    return strtr($template, $replacements);
}

function ensure_lesson_progress_completion_source(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $stmt = db()->query("SHOW COLUMNS FROM lesson_progress LIKE 'completion_source'");
    if (!$stmt->fetch()) {
        db()->exec("ALTER TABLE lesson_progress ADD completion_source VARCHAR(20) NOT NULL DEFAULT 'legacy' AFTER completed_at");
    }

    $checked = true;
}

function ensure_lesson_video_settings_columns(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    if (!database_column_exists('lessons', 'allow_seek')) {
        db()->exec('ALTER TABLE lessons ADD allow_seek TINYINT(1) NOT NULL DEFAULT 1 AFTER content');
    }
    if (!database_column_exists('lessons', 'video_duration_seconds')) {
        db()->exec('ALTER TABLE lessons ADD video_duration_seconds INT UNSIGNED NULL AFTER allow_seek');
    }

    $checked = true;
}

function mark_lesson_completed(int $attemptId, int $lessonId, string $source = 'manual'): void
{
    ensure_lesson_progress_completion_source();
    $stmt = db()->prepare(
        'INSERT INTO lesson_progress (attempt_id, lesson_id, completed_at, completion_source)
         VALUES (?, ?, NOW(), ?)
         ON DUPLICATE KEY UPDATE completed_at = NOW(), completion_source = VALUES(completion_source), updated_at = NOW()'
    );
    $stmt->execute([$attemptId, $lessonId, $source]);
}

function video_watch_session_key(int $attemptId, int $lessonId): string
{
    return $attemptId . ':' . $lessonId;
}

function start_video_watch_session(int $attemptId, int $lessonId, int $requiredDurationSeconds = 0): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $key = video_watch_session_key($attemptId, $lessonId);
    if (!isset($_SESSION['video_watch_sessions'][$key]) || !is_array($_SESSION['video_watch_sessions'][$key])) {
        $_SESSION['video_watch_sessions'][$key] = [
            'token' => generate_token(16),
            'started_at' => time(),
            'required_duration_seconds' => $requiredDurationSeconds,
        ];
    } else {
        $_SESSION['video_watch_sessions'][$key]['required_duration_seconds'] = max(
            (int) ($_SESSION['video_watch_sessions'][$key]['required_duration_seconds'] ?? 0),
            $requiredDurationSeconds
        );
    }

    return (string) $_SESSION['video_watch_sessions'][$key]['token'];
}

function forget_video_watch_session(int $attemptId, int $lessonId): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    unset($_SESSION['video_watch_sessions'][video_watch_session_key($attemptId, $lessonId)]);
}

function require_video_completion_evidence(array $attempt, array $lesson): void
{
    if (!lesson_requires_video_completion($lesson) || (int) ($lesson['allow_seek'] ?? 1) === 1) {
        return;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $attemptId = (int) $attempt['id'];
    $lessonId = (int) $lesson['id'];
    $key = video_watch_session_key($attemptId, $lessonId);
    $watchSession = $_SESSION['video_watch_sessions'][$key] ?? null;
    if (!is_array($watchSession) || !hash_equals((string) ($watchSession['token'] ?? ''), (string) post('watch_token'))) {
        throw new RuntimeException('กรุณาเปิดบทเรียนวิดีโอจากหน้าเรียนก่อน');
    }

    $serverDuration = max((int) ($lesson['video_duration_seconds'] ?? 0), (int) ($watchSession['required_duration_seconds'] ?? 0));
    $postedDuration = max(0.0, (float) post('duration_seconds', 0));
    $duration = $serverDuration > 0 ? (float) $serverDuration : $postedDuration;
    if ($duration <= 0.0) {
        return;
    }

    $watchedSeconds = max(0.0, (float) post('watched_seconds', 0));
    $maxPosition = max(0.0, (float) post('max_position', 0));
    $startedAt = (int) ($watchSession['started_at'] ?? time());
    $elapsedSeconds = max(0, time() - $startedAt);
    $requiredSeconds = max(1.0, $duration * 0.9);
    $graceSeconds = min(5.0, max(1.5, $duration * 0.05));

    if (
        $watchedSeconds + $graceSeconds < $requiredSeconds
        || $maxPosition + $graceSeconds < $requiredSeconds
        || $elapsedSeconds + $graceSeconds < $requiredSeconds
    ) {
        throw new RuntimeException('กรุณาดูวิดีโอให้ครบก่อน ระบบยังไม่บันทึกการเรียนจบ');
    }
}

function ensure_curriculum_tables(): void
{
    ensure_lesson_video_settings_columns();
    static $checked = false;
    if ($checked) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS quiz_sets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            course_id INT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            shuffle_questions TINYINT(1) NOT NULL DEFAULT 0,
            shuffle_choices TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT quiz_sets_course_fk FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    db()->exec(
        "CREATE TABLE IF NOT EXISTS quiz_set_questions (
            quiz_set_id INT UNSIGNED NOT NULL,
            question_id INT UNSIGNED NOT NULL,
            sort_order INT NOT NULL DEFAULT 1,
            PRIMARY KEY (quiz_set_id, question_id),
            KEY quiz_set_question_lookup (question_id),
            CONSTRAINT quiz_set_questions_set_fk FOREIGN KEY (quiz_set_id) REFERENCES quiz_sets(id) ON DELETE CASCADE,
            CONSTRAINT quiz_set_questions_question_fk FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    db()->exec(
        "CREATE TABLE IF NOT EXISTS curriculum_sections (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            course_id INT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            sort_order INT NOT NULL DEFAULT 10,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY curriculum_sections_course_order (course_id, sort_order, id),
            CONSTRAINT curriculum_sections_course_fk FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    db()->exec(
        "CREATE TABLE IF NOT EXISTS curriculum_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            course_id INT UNSIGNED NOT NULL,
            section_id INT UNSIGNED NULL,
            item_type ENUM('lesson','quiz_set') NOT NULL,
            lesson_id INT UNSIGNED NULL,
            quiz_set_id INT UNSIGNED NULL,
            sort_order INT NOT NULL DEFAULT 10,
            requires_previous TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY curriculum_lesson_unique (lesson_id),
            KEY curriculum_quiz_set_lookup (quiz_set_id),
            KEY curriculum_course_order (course_id, sort_order, id),
            CONSTRAINT curriculum_course_fk FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
            CONSTRAINT curriculum_lesson_fk FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
            CONSTRAINT curriculum_quiz_set_fk FOREIGN KEY (quiz_set_id) REFERENCES quiz_sets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    db()->exec(
        "CREATE TABLE IF NOT EXISTS question_progress (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            attempt_id INT UNSIGNED NOT NULL,
            curriculum_item_id INT UNSIGNED NULL,
            question_id INT UNSIGNED NOT NULL,
            submitted_answers JSON NULL,
            is_correct TINYINT(1) NOT NULL DEFAULT 0,
            completed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY question_progress_item_unique (attempt_id, curriculum_item_id, question_id),
            KEY question_progress_attempt_lookup (attempt_id),
            KEY question_progress_item_lookup (curriculum_item_id),
            KEY question_progress_question_lookup (question_id),
            CONSTRAINT question_progress_attempt_fk FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE,
            CONSTRAINT question_progress_item_fk FOREIGN KEY (curriculum_item_id) REFERENCES curriculum_items(id) ON DELETE CASCADE,
            CONSTRAINT question_progress_question_fk FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!database_column_exists('curriculum_items', 'section_id')) {
        db()->exec('ALTER TABLE curriculum_items ADD section_id INT UNSIGNED NULL AFTER course_id');
    }
    if (!database_column_exists('curriculum_items', 'quiz_set_id')) {
        db()->exec('ALTER TABLE curriculum_items ADD quiz_set_id INT UNSIGNED NULL AFTER lesson_id');
    }
    if (!database_column_exists('quiz_sets', 'shuffle_questions')) {
        db()->exec('ALTER TABLE quiz_sets ADD shuffle_questions TINYINT(1) NOT NULL DEFAULT 0 AFTER description');
    }
    if (!database_column_exists('question_progress', 'curriculum_item_id')) {
        db()->exec('ALTER TABLE question_progress ADD curriculum_item_id INT UNSIGNED NULL AFTER attempt_id');
    }
    db()->exec(
        'UPDATE question_progress qp
         INNER JOIN attempts a ON a.id = qp.attempt_id
         INNER JOIN quiz_set_questions qsq ON qsq.question_id = qp.question_id
         INNER JOIN curriculum_items ci ON ci.quiz_set_id = qsq.quiz_set_id AND ci.course_id = a.course_id
         SET qp.curriculum_item_id = ci.id
         WHERE qp.curriculum_item_id IS NULL'
    );
    add_database_index_if_missing('quiz_set_questions', 'quiz_set_question_lookup', 'question_id');
    add_database_index_if_missing('curriculum_items', 'curriculum_quiz_set_lookup', 'quiz_set_id');
    add_database_index_if_missing('question_progress', 'question_progress_attempt_lookup', 'attempt_id');
    add_database_index_if_missing('question_progress', 'question_progress_item_lookup', 'curriculum_item_id');
    add_database_index_if_missing('question_progress', 'question_progress_question_lookup', 'question_id');
    drop_database_index_if_exists('quiz_set_questions', 'quiz_set_question_unique');
    drop_database_index_if_exists('curriculum_items', 'curriculum_quiz_set_unique');
    drop_database_index_if_exists('question_progress', 'question_progress_unique');
    if (!database_index_exists('question_progress', 'question_progress_item_unique')) {
        db()->exec('ALTER TABLE question_progress ADD UNIQUE KEY question_progress_item_unique (attempt_id, curriculum_item_id, question_id)');
    }
    db()->exec("ALTER TABLE curriculum_items MODIFY item_type ENUM('lesson','question','quiz_set') NOT NULL");

    $courses = db()->query('SELECT id FROM courses ORDER BY id')->fetchAll();
    foreach ($courses as $course) {
        $courseId = (int) $course['id'];
        $sectionId = default_curriculum_section_id($courseId);
        db()->prepare('UPDATE curriculum_items SET section_id = ? WHERE course_id = ? AND section_id IS NULL')
            ->execute([$sectionId, $courseId]);
        migrate_legacy_curriculum_questions($courseId, $sectionId);
        seed_empty_curriculum($courseId, $sectionId);
        sync_missing_curriculum_items($courseId);
        sync_unassigned_questions_to_bank($courseId);
    }

    $checked = true;
}

function database_column_exists(string $table, string $column): bool
{
    $stmt = db()->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . db()->quote($column));
    return (bool) $stmt->fetch();
}

function database_index_exists(string $table, string $index): bool
{
    $stmt = db()->query('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ' . db()->quote($index));
    return (bool) $stmt->fetch();
}

function drop_database_index_if_exists(string $table, string $index): void
{
    if (database_index_exists($table, $index)) {
        db()->exec('ALTER TABLE `' . $table . '` DROP INDEX `' . $index . '`');
    }
}

function add_database_index_if_missing(string $table, string $index, string $column): void
{
    if (!database_index_exists($table, $index)) {
        db()->exec('ALTER TABLE `' . $table . '` ADD INDEX `' . $index . '` (`' . $column . '`)');
    }
}

function default_curriculum_section_id(int $courseId): int
{
    $stmt = db()->prepare('SELECT id FROM curriculum_sections WHERE course_id = ? ORDER BY sort_order, id LIMIT 1');
    $stmt->execute([$courseId]);
    $id = (int) $stmt->fetchColumn();
    if ($id > 0) {
        return $id;
    }

    $stmt = db()->prepare('INSERT INTO curriculum_sections (course_id, title, sort_order) VALUES (?, ?, 10)');
    $stmt->execute([$courseId, 'ส่วนที่ 1 เนื้อหาหลัก']);
    return (int) db()->lastInsertId();
}

function curriculum_sections(int $courseId): array
{
    ensure_curriculum_tables();
    $stmt = db()->prepare('SELECT * FROM curriculum_sections WHERE course_id = ? ORDER BY sort_order, id');
    $stmt->execute([$courseId]);
    return $stmt->fetchAll();
}

function create_quiz_set_from_questions(int $courseId, string $title, array $questionIds): int
{
    $questionIds = array_values(array_unique(array_map('intval', $questionIds)));
    if (!$questionIds) {
        return 0;
    }

    $stmt = db()->prepare('INSERT INTO quiz_sets (course_id, title) VALUES (?, ?)');
    $stmt->execute([$courseId, $title]);
    $quizSetId = (int) db()->lastInsertId();
    $insert = db()->prepare('INSERT IGNORE INTO quiz_set_questions (quiz_set_id, question_id, sort_order) VALUES (?, ?, ?)');
    foreach ($questionIds as $index => $questionId) {
        $insert->execute([$quizSetId, $questionId, $index + 1]);
    }
    return $quizSetId;
}

function migrate_legacy_curriculum_questions(int $courseId, int $sectionId): void
{
    if (!database_column_exists('curriculum_items', 'question_id')) {
        return;
    }

    $stmt = db()->prepare('SELECT id, item_type, question_id, sort_order, requires_previous FROM curriculum_items WHERE course_id = ? ORDER BY sort_order, id');
    $stmt->execute([$courseId]);
    $groups = [];
    $current = [];
    foreach ($stmt->fetchAll() as $item) {
        if ($item['item_type'] === 'question' && !empty($item['question_id'])) {
            $current[] = $item;
            continue;
        }
        if ($current) {
            $groups[] = $current;
            $current = [];
        }
    }
    if ($current) {
        $groups[] = $current;
    }

    foreach ($groups as $index => $group) {
        $quizSetId = create_quiz_set_from_questions(
            $courseId,
            'ชุดข้อสอบเดิม ' . ($index + 1),
            array_column($group, 'question_id')
        );
        $first = $group[0];
        $stmt = db()->prepare(
            "UPDATE curriculum_items
             SET section_id = ?, item_type = 'quiz_set', lesson_id = NULL, quiz_set_id = ?, question_id = NULL
             WHERE id = ? AND course_id = ?"
        );
        $stmt->execute([$sectionId, $quizSetId, (int) $first['id'], $courseId]);
        $delete = db()->prepare('DELETE FROM curriculum_items WHERE id = ? AND course_id = ?');
        foreach (array_slice($group, 1) as $item) {
            $delete->execute([(int) $item['id'], $courseId]);
        }
    }
}

function seed_empty_curriculum(int $courseId, int $sectionId): void
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM curriculum_items WHERE course_id = ?');
    $stmt->execute([$courseId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    $order = 0;
    foreach (['pre' => 'ชุดข้อสอบเริ่มต้น', 'post' => 'ชุดข้อสอบทบทวน'] as $legacyType => $title) {
        if ($legacyType === 'post') {
            $lessons = db()->prepare('SELECT id FROM lessons WHERE course_id = ? ORDER BY sort_order, id');
            $lessons->execute([$courseId]);
            foreach ($lessons->fetchAll() as $lesson) {
                $order += 10;
                insert_curriculum_item($courseId, $sectionId, 'lesson', (int) $lesson['id'], $order);
            }
        }
        $questions = db()->prepare('SELECT id FROM questions WHERE course_id = ? AND quiz_type = ? ORDER BY sort_order, id');
        $questions->execute([$courseId, $legacyType]);
        $quizSetId = create_quiz_set_from_questions($courseId, $title, array_column($questions->fetchAll(), 'id'));
        if ($quizSetId > 0) {
            $order += 10;
            insert_curriculum_item($courseId, $sectionId, 'quiz_set', $quizSetId, $order);
        }
    }
}

function sync_unassigned_questions_to_bank(int $courseId): void
{
    $stmt = db()->prepare(
        'SELECT q.id FROM questions q
         WHERE q.course_id = ?
           AND NOT EXISTS (SELECT 1 FROM quiz_set_questions qsq WHERE qsq.question_id = q.id)
         ORDER BY q.sort_order, q.id'
    );
    $stmt->execute([$courseId]);
    $ids = array_column($stmt->fetchAll(), 'id');
    if ($ids) {
        create_quiz_set_from_questions($courseId, 'คลังข้อสอบเดิม', $ids);
    }
}

function sync_missing_curriculum_items(int $courseId): void
{
    $sectionId = default_curriculum_section_id($courseId);
    $max = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM curriculum_items WHERE course_id = ? AND section_id = ?');
    $max->execute([$courseId, $sectionId]);
    $order = (int) $max->fetchColumn();
    $stmt = db()->prepare(
        'SELECT l.id FROM lessons l
         WHERE l.course_id = ?
           AND NOT EXISTS (SELECT 1 FROM curriculum_items ci WHERE ci.lesson_id = l.id)
         ORDER BY l.sort_order, l.id'
    );
    $stmt->execute([$courseId]);
    foreach ($stmt->fetchAll() as $lesson) {
        $order += 10;
        insert_curriculum_item($courseId, $sectionId, 'lesson', (int) $lesson['id'], $order);
    }
}

function insert_curriculum_item(int $courseId, int $sectionId, string $itemType, int $sourceId, int $sortOrder, bool $requiresPrevious = true): int
{
    $stmt = db()->prepare(
        'INSERT INTO curriculum_items (course_id, section_id, item_type, lesson_id, quiz_set_id, sort_order, requires_previous)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $courseId,
        $sectionId,
        $itemType,
        $itemType === 'lesson' ? $sourceId : null,
        $itemType === 'quiz_set' ? $sourceId : null,
        $sortOrder,
        $requiresPrevious ? 1 : 0,
    ]);
    return (int) db()->lastInsertId();
}

function sync_curriculum_item(int $courseId, string $itemType, int $sourceId, ?int $sectionId = null): int
{
    ensure_curriculum_tables();
    $column = $itemType === 'quiz_set' ? 'quiz_set_id' : 'lesson_id';
    if ($itemType !== 'quiz_set') {
        $stmt = db()->prepare("SELECT id FROM curriculum_items WHERE {$column} = ?");
        $stmt->execute([$sourceId]);
        $id = (int) $stmt->fetchColumn();
        if ($id > 0) {
            return $id;
        }
    }
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM curriculum_items WHERE course_id = ? AND section_id = ?');
    $sectionId = $sectionId ?: default_curriculum_section_id($courseId);
    $stmt->execute([$courseId, $sectionId]);
    return insert_curriculum_item($courseId, $sectionId, $itemType, $sourceId, (int) $stmt->fetchColumn());
}

function quiz_sets(?int $courseId = null): array
{
    ensure_curriculum_tables();
    $sql = 'SELECT qs.*, c.title AS course_title, COUNT(DISTINCT qsq.question_id) AS question_count,
            EXISTS(SELECT 1 FROM curriculum_items ci WHERE ci.quiz_set_id = qs.id) AS is_used,
            COUNT(DISTINCT ci.id) AS usage_count,
            MAX(ci.course_id = ?) AS is_used_in_current_course
         FROM quiz_sets qs
         LEFT JOIN courses c ON c.id = qs.course_id
         LEFT JOIN quiz_set_questions qsq ON qsq.quiz_set_id = qs.id
         LEFT JOIN curriculum_items ci ON ci.quiz_set_id = qs.id
         GROUP BY qs.id
         ORDER BY (qs.course_id = ?) DESC, qs.created_at, qs.id';
    $stmt = db()->prepare($sql);
    $currentCourseId = $courseId ?? 0;
    $stmt->execute([$currentCourseId, $currentCourseId]);
    return $stmt->fetchAll();
}

function quiz_set_questions(int $quizSetId): array
{
    $stmt = db()->prepare(
        'SELECT q.*, qsq.sort_order AS set_sort_order
         FROM quiz_set_questions qsq
         INNER JOIN questions q ON q.id = qsq.question_id
         WHERE qsq.quiz_set_id = ?
         ORDER BY qsq.sort_order, q.id'
    );
    $stmt->execute([$quizSetId]);
    return $stmt->fetchAll();
}

function public_quiz_themes(): array
{
    return [
        'ocean' => ['label' => 'ทะเลเสนา', 'description' => 'ฟ้าอมเขียว สุภาพ อ่านง่าย'],
        'sunrise' => ['label' => 'แสงอรุณ', 'description' => 'ส้มอุ่น สดใส เป็นกันเอง'],
        'forest' => ['label' => 'สวนเรียนรู้', 'description' => 'เขียวธรรมชาติ สงบ มีสมาธิ'],
        'orchid' => ['label' => 'กล้วยไม้', 'description' => 'ม่วงนุ่มนวล ดูสร้างสรรค์'],
        'festival' => ['label' => 'งานวัด', 'description' => 'ชมพู–ทอง สนุกและมีพลัง'],
    ];
}

function normalize_public_quiz_theme(?string $theme): string
{
    $theme = trim((string) $theme);
    return array_key_exists($theme, public_quiz_themes()) ? $theme : 'ocean';
}

function ensure_public_quiz_sharing_tables(): void
{
    ensure_curriculum_tables();
    static $checked = false;
    if ($checked) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS public_quiz_shares (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            quiz_set_id INT UNSIGNED NOT NULL UNIQUE,
            share_token VARCHAR(64) NOT NULL UNIQUE,
            public_title VARCHAR(255) NOT NULL,
            welcome_message TEXT NULL,
            pass_percent DECIMAL(5,2) NOT NULL DEFAULT 80,
            certificate_enabled TINYINT(1) NOT NULL DEFAULT 1,
            theme VARCHAR(30) NOT NULL DEFAULT 'ocean',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT public_quiz_shares_set_fk FOREIGN KEY (quiz_set_id) REFERENCES quiz_sets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    db()->exec(
        "CREATE TABLE IF NOT EXISTS public_quiz_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            share_id INT UNSIGNED NOT NULL,
            learner_name VARCHAR(255) NOT NULL,
            access_token VARCHAR(64) NOT NULL UNIQUE,
            score INT NULL,
            total INT NULL,
            percent DECIMAL(5,2) NULL,
            status ENUM('started','submitted','passed') NOT NULL DEFAULT 'started',
            certificate_code VARCHAR(80) NULL UNIQUE,
            started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            submitted_at TIMESTAMP NULL,
            KEY public_quiz_attempts_share_lookup (share_id, started_at),
            CONSTRAINT public_quiz_attempts_share_fk FOREIGN KEY (share_id) REFERENCES public_quiz_shares(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    db()->exec(
        "CREATE TABLE IF NOT EXISTS public_quiz_answers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            attempt_id INT UNSIGNED NOT NULL,
            question_id INT UNSIGNED NOT NULL,
            submitted_answers JSON NULL,
            is_correct TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY public_quiz_answer_unique (attempt_id, question_id),
            KEY public_quiz_answer_question_lookup (question_id),
            CONSTRAINT public_quiz_answers_attempt_fk FOREIGN KEY (attempt_id) REFERENCES public_quiz_attempts(id) ON DELETE CASCADE,
            CONSTRAINT public_quiz_answers_question_fk FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $checked = true;
}

function public_quiz_share_for_set(int $quizSetId): ?array
{
    ensure_public_quiz_sharing_tables();
    $stmt = db()->prepare(
        'SELECT pqs.*, qs.title AS quiz_set_title, qs.description AS quiz_set_description,
                qs.shuffle_questions, qs.shuffle_choices, qs.course_id,
                c.title AS course_title,
                (SELECT COUNT(*) FROM quiz_set_questions qsq WHERE qsq.quiz_set_id = qs.id) AS question_count,
                (SELECT COUNT(*) FROM public_quiz_attempts pqa WHERE pqa.share_id = pqs.id AND pqa.status != "started") AS attempt_count,
                (SELECT COUNT(*) FROM public_quiz_attempts pqa WHERE pqa.share_id = pqs.id AND pqa.status = "passed") AS passed_count
         FROM public_quiz_shares pqs
         INNER JOIN quiz_sets qs ON qs.id = pqs.quiz_set_id
         INNER JOIN courses c ON c.id = qs.course_id
         WHERE pqs.quiz_set_id = ?'
    );
    $stmt->execute([$quizSetId]);
    $share = $stmt->fetch();
    return $share ?: null;
}

function public_quiz_share_by_token(string $shareToken, bool $includeInactive = false): ?array
{
    ensure_public_quiz_sharing_tables();
    $shareToken = trim($shareToken);
    if ($shareToken === '' || preg_match('/^[a-f0-9]{48}$/', $shareToken) !== 1) {
        return null;
    }

    $sql = 'SELECT pqs.*, qs.title AS quiz_set_title, qs.description AS quiz_set_description,
                   qs.shuffle_questions, qs.shuffle_choices, qs.course_id,
                   c.title AS course_title,
                   (SELECT COUNT(*) FROM quiz_set_questions qsq WHERE qsq.quiz_set_id = qs.id) AS question_count
            FROM public_quiz_shares pqs
            INNER JOIN quiz_sets qs ON qs.id = pqs.quiz_set_id
            INNER JOIN courses c ON c.id = qs.course_id
            WHERE pqs.share_token = ?';
    if (!$includeInactive) {
        $sql .= ' AND pqs.is_active = 1';
    }
    $stmt = db()->prepare($sql . ' LIMIT 1');
    $stmt->execute([$shareToken]);
    $share = $stmt->fetch();
    return $share ?: null;
}

function save_public_quiz_share(int $quizSetId, array $data): array
{
    ensure_public_quiz_sharing_tables();
    $title = trim((string) ($data['public_title'] ?? ''));
    $welcomeMessage = trim((string) ($data['welcome_message'] ?? ''));
    $passPercent = (float) ($data['pass_percent'] ?? 80);
    if ($title === '' || mb_strlen($title, 'UTF-8') > 255) {
        throw new RuntimeException('กรุณากรอกชื่อแบบทดสอบไม่เกิน 255 ตัวอักษร');
    }
    if (mb_strlen($welcomeMessage, 'UTF-8') > 1000) {
        throw new RuntimeException('ข้อความต้อนรับต้องไม่เกิน 1,000 ตัวอักษร');
    }
    if ($passPercent < 1 || $passPercent > 100) {
        throw new RuntimeException('เกณฑ์ผ่านต้องอยู่ระหว่าง 1 ถึง 100 เปอร์เซ็นต์');
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM quiz_set_questions WHERE quiz_set_id = ?');
    $stmt->execute([$quizSetId]);
    if ((int) $stmt->fetchColumn() === 0) {
        throw new RuntimeException('กรุณาเพิ่มคำถามอย่างน้อย 1 ข้อก่อนสร้างลิงก์แชร์');
    }

    $current = public_quiz_share_for_set($quizSetId);
    $shareToken = (string) ($current['share_token'] ?? generate_token(24));
    $stmt = db()->prepare(
        'INSERT INTO public_quiz_shares
            (quiz_set_id, share_token, public_title, welcome_message, pass_percent, certificate_enabled, theme, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            public_title = VALUES(public_title),
            welcome_message = VALUES(welcome_message),
            pass_percent = VALUES(pass_percent),
            certificate_enabled = VALUES(certificate_enabled),
            theme = VALUES(theme),
            is_active = VALUES(is_active),
            updated_at = NOW()'
    );
    $stmt->execute([
        $quizSetId,
        $shareToken,
        $title,
        $welcomeMessage,
        round($passPercent, 2),
        !empty($data['certificate_enabled']) ? 1 : 0,
        normalize_public_quiz_theme((string) ($data['theme'] ?? 'ocean')),
        !empty($data['is_active']) ? 1 : 0,
    ]);

    return (array) public_quiz_share_for_set($quizSetId);
}

function public_quiz_share_url(array $share): string
{
    return app_absolute_url('shared_quiz.php?share=' . rawurlencode((string) $share['share_token']));
}

function create_public_quiz_attempt(array $share, string $learnerName): array
{
    ensure_public_quiz_sharing_tables();
    $learnerName = trim(preg_replace('/\s+/u', ' ', $learnerName) ?? $learnerName);
    if ($learnerName === '' || mb_strlen($learnerName, 'UTF-8') > 255) {
        throw new RuntimeException('กรุณากรอกชื่อ–นามสกุลให้ถูกต้อง');
    }
    if ((int) ($share['is_active'] ?? 0) !== 1) {
        throw new RuntimeException('แบบทดสอบนี้ยังไม่เปิดให้ทำ');
    }

    $accessToken = generate_token(32);
    $stmt = db()->prepare(
        "INSERT INTO public_quiz_attempts (share_id, learner_name, access_token, status)
         VALUES (?, ?, ?, 'started')"
    );
    $stmt->execute([(int) $share['id'], $learnerName, $accessToken]);
    return (array) public_quiz_attempt((int) db()->lastInsertId(), $accessToken);
}

function public_quiz_attempt(int $attemptId, string $accessToken): ?array
{
    ensure_public_quiz_sharing_tables();
    if ($attemptId <= 0 || preg_match('/^[a-f0-9]{64}$/', $accessToken) !== 1) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT pqa.*, pqs.quiz_set_id, pqs.share_token, pqs.public_title, pqs.welcome_message,
                pqs.pass_percent, pqs.certificate_enabled, pqs.theme, pqs.is_active,
                qs.course_id, qs.title AS quiz_set_title, qs.shuffle_questions, qs.shuffle_choices,
                c.title AS course_title
         FROM public_quiz_attempts pqa
         INNER JOIN public_quiz_shares pqs ON pqs.id = pqa.share_id
         INNER JOIN quiz_sets qs ON qs.id = pqs.quiz_set_id
         INNER JOIN courses c ON c.id = qs.course_id
         WHERE pqa.id = ? AND pqa.access_token = ?
         LIMIT 1'
    );
    $stmt->execute([$attemptId, $accessToken]);
    $attempt = $stmt->fetch();
    return $attempt ?: null;
}

function public_quiz_attempt_url(array $share, array $attempt): string
{
    return 'shared_quiz.php?' . http_build_query([
        'share' => $share['share_token'],
        'attempt' => $attempt['id'],
        'token' => $attempt['access_token'],
    ]);
}

function submit_public_quiz_attempt(array $attempt, array $share, array $submitted): array
{
    ensure_public_quiz_sharing_tables();
    $questions = quiz_set_questions((int) $share['quiz_set_id']);
    if (!$questions) {
        throw new RuntimeException('แบบทดสอบนี้ยังไม่มีคำถาม');
    }
    foreach ($questions as $question) {
        $given = $submitted[(string) $question['id']] ?? '';
        if ((is_array($given) && $given === []) || (!is_array($given) && trim((string) $given) === '')) {
            throw new RuntimeException('กรุณาตอบคำถามให้ครบทุกข้อ');
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT status FROM public_quiz_attempts WHERE id = ? FOR UPDATE');
        $lock->execute([(int) $attempt['id']]);
        if ((string) $lock->fetchColumn() !== 'started') {
            throw new RuntimeException('แบบทดสอบนี้ถูกส่งคำตอบแล้ว');
        }

        $insert = $pdo->prepare(
            'INSERT INTO public_quiz_answers (attempt_id, question_id, submitted_answers, is_correct)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE submitted_answers = VALUES(submitted_answers), is_correct = VALUES(is_correct)'
        );
        $correct = 0;
        foreach ($questions as $question) {
            $given = $submitted[(string) $question['id']];
            $isCorrect = score_curriculum_question($question, $given);
            $insert->execute([
                (int) $attempt['id'],
                (int) $question['id'],
                json_encode($given, JSON_UNESCAPED_UNICODE),
                $isCorrect ? 1 : 0,
            ]);
            if ($isCorrect) {
                $correct++;
            }
        }
        $total = count($questions);
        $percent = round(($correct / $total) * 100, 2);
        $passed = $percent >= (float) $share['pass_percent'];
        $certificateCode = $passed && (int) $share['certificate_enabled'] === 1
            ? 'SENA-Q-' . date('Ymd') . '-' . strtoupper(substr(generate_token(5), 0, 10))
            : null;
        $update = $pdo->prepare(
            'UPDATE public_quiz_attempts
             SET score = ?, total = ?, percent = ?, status = ?, certificate_code = ?, submitted_at = NOW()
             WHERE id = ?'
        );
        $update->execute([
            $correct,
            $total,
            $percent,
            $passed ? 'passed' : 'submitted',
            $certificateCode,
            (int) $attempt['id'],
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $saved = public_quiz_attempt((int) $attempt['id'], (string) $attempt['access_token']);
    if (!$saved) {
        throw new RuntimeException('ไม่สามารถอ่านผลแบบทดสอบได้');
    }
    return $saved;
}

function curriculum_items(int $courseId, ?int $attemptId = null): array
{
    ensure_curriculum_tables();
    $stmt = db()->prepare(
        'SELECT ci.*,
            cs.title AS section_title, cs.sort_order AS section_sort_order,
            l.title AS lesson_title, l.content_type, l.content, l.allow_seek, l.video_duration_seconds,
            qs.title AS quiz_set_title, qs.description AS quiz_set_description, qs.shuffle_questions, qs.shuffle_choices,
            (SELECT COUNT(*) FROM quiz_set_questions qsq WHERE qsq.quiz_set_id = ci.quiz_set_id) AS quiz_question_total,
            (SELECT COUNT(*) FROM quiz_set_questions qsq INNER JOIN question_progress qp ON qp.question_id = qsq.question_id AND qp.attempt_id = ? AND (qp.curriculum_item_id = ci.id OR qp.curriculum_item_id IS NULL) WHERE qsq.quiz_set_id = ci.quiz_set_id AND qp.completed_at IS NOT NULL) AS quiz_question_completed,
            (SELECT COUNT(*) FROM quiz_set_questions qsq INNER JOIN question_progress qp ON qp.question_id = qsq.question_id AND qp.attempt_id = ? AND (qp.curriculum_item_id = ci.id OR qp.curriculum_item_id IS NULL) WHERE qsq.quiz_set_id = ci.quiz_set_id AND qp.is_correct = 1) AS quiz_question_correct,
            lp.completed_at AS lesson_completed_at, lp.completion_source
         FROM curriculum_items ci
         INNER JOIN curriculum_sections cs ON cs.id = ci.section_id
         LEFT JOIN lessons l ON l.id = ci.lesson_id
         LEFT JOIN quiz_sets qs ON qs.id = ci.quiz_set_id
         LEFT JOIN lesson_progress lp ON lp.lesson_id = ci.lesson_id AND lp.attempt_id = ?
         WHERE ci.course_id = ?
         ORDER BY cs.sort_order, cs.id, ci.sort_order, ci.id'
    );
    $stmt->execute([$attemptId ?? 0, $attemptId ?? 0, $attemptId ?? 0, $courseId]);
    $items = $stmt->fetchAll();
    $allPreviousCompleted = true;
    foreach ($items as &$item) {
        $item['title'] = $item['item_type'] === 'lesson'
            ? (string) $item['lesson_title']
            : (string) $item['quiz_set_title'];
        $isCompleted = $item['item_type'] === 'quiz_set'
            ? (int) $item['quiz_question_total'] > 0 && (int) $item['quiz_question_completed'] === (int) $item['quiz_question_total']
            : !empty($item['lesson_completed_at']);
        if ($item['item_type'] === 'lesson' && lesson_requires_video_completion($item)) {
            $isCompleted = $isCompleted && ($item['completion_source'] ?? '') === 'video';
        }
        $item['is_completed'] = $isCompleted ? 1 : 0;
        $item['is_locked'] = ((int) $item['requires_previous'] === 1 && !$allPreviousCompleted) ? 1 : 0;
        $item['is_accessible'] = (int) $item['is_locked'] === 0 ? 1 : 0;
        $allPreviousCompleted = $allPreviousCompleted && $isCompleted;
    }
    unset($item);

    return $items;
}

function curriculum_item_for_attempt(array $attempt, int $itemId): ?array
{
    foreach (curriculum_items((int) $attempt['course_id'], (int) $attempt['id']) as $item) {
        if ((int) $item['id'] === $itemId) {
            return $item;
        }
    }

    return null;
}

function curriculum_summary(array $attempt): array
{
    $items = curriculum_items((int) $attempt['course_id'], (int) $attempt['id']);
    $completed = array_filter($items, fn ($item) => (int) $item['is_completed'] === 1);
    $questionTotal = array_sum(array_map(fn ($item) => (int) ($item['quiz_question_total'] ?? 0), $items));
    $questionCorrect = array_sum(array_map(fn ($item) => (int) ($item['quiz_question_correct'] ?? 0), $items));

    return [
        'items' => $items,
        'required' => count($items),
        'completed' => count($completed),
        'ready' => count($items) === count($completed),
        'question_total' => $questionTotal,
        'question_correct' => $questionCorrect,
    ];
}

function score_curriculum_question(array $question, mixed $given): bool
{
    $correctAnswers = json_decode((string) ($question['correct_answers'] ?? '[]'), true) ?: [];
    if (($question['question_type'] ?? '') === 'multiple_choice') {
        $givenValues = is_array($given) ? array_map('strval', $given) : [];
        $expected = array_map('strval', $correctAnswers);
        sort($givenValues);
        sort($expected);
        return $givenValues === $expected;
    }

    $givenText = normalize_answer((string) $given);
    foreach ($correctAnswers as $answer) {
        if ($givenText === normalize_answer((string) $answer)) {
            return true;
        }
    }

    return false;
}

function save_curriculum_quiz_set_answers(int $attemptId, array $item, array $submitted): array
{
    if ($item['item_type'] !== 'quiz_set' || empty($item['quiz_set_id'])) {
        throw new RuntimeException('ไม่พบชุดข้อสอบในลำดับการเรียน');
    }

    $questions = quiz_set_questions((int) $item['quiz_set_id']);
    $curriculumItemId = (int) ($item['id'] ?? 0);
    if ($curriculumItemId <= 0) {
        throw new RuntimeException('ไม่พบตำแหน่งชุดข้อสอบในลำดับการเรียน');
    }

    $stmt = db()->prepare(
        'INSERT INTO question_progress (attempt_id, curriculum_item_id, question_id, submitted_answers, is_correct, completed_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            submitted_answers = VALUES(submitted_answers),
            is_correct = VALUES(is_correct),
            completed_at = NOW(),
            updated_at = NOW()'
    );
    $correct = 0;
    foreach ($questions as $question) {
        $given = $submitted[(string) $question['id']] ?? '';
        if ((is_array($given) && $given === []) || (!is_array($given) && trim((string) $given) === '')) {
            throw new RuntimeException('กรุณาตอบคำถามให้ครบทุกข้อ');
        }
    }
    foreach ($questions as $question) {
        $given = $submitted[(string) $question['id']];
        $isCorrect = score_curriculum_question($question, $given);
        $stmt->execute([
            $attemptId,
            $curriculumItemId,
            (int) $question['id'],
            json_encode($given, JSON_UNESCAPED_UNICODE),
            $isCorrect ? 1 : 0,
        ]);
        if ($isCorrect) {
            $correct++;
        }
    }
    return ['correct' => $correct, 'total' => count($questions)];
}

function finalize_curriculum_attempt(int $attemptId): array
{
    $attempt = get_attempt($attemptId);
    if (!$attempt) {
        throw new RuntimeException('ไม่พบข้อมูลผู้เรียน');
    }

    $summary = curriculum_summary($attempt);
    if (!$summary['ready']) {
        db()->prepare("UPDATE attempts SET status = 'learning', updated_at = NOW() WHERE id = ? AND status != 'passed'")
            ->execute([$attemptId]);
        return $summary;
    }

    $percent = $summary['question_total'] > 0
        ? round(($summary['question_correct'] / $summary['question_total']) * 100, 2)
        : 100;
    $passed = $percent >= (float) $attempt['pass_percent'];
    $certCode = $passed ? certificate_code_for_attempt($attempt) : null;
    $stmt = db()->prepare(
        'UPDATE attempts
         SET post_score = ?, post_total = ?, status = ?, certificate_code = COALESCE(certificate_code, ?), updated_at = NOW()
         WHERE id = ?'
    );
    $stmt->execute([
        $summary['question_correct'],
        $summary['question_total'],
        $passed ? 'passed' : 'posttest_done',
        $certCode,
        $attemptId,
    ]);
    $summary['percent'] = $percent;
    $summary['passed'] = $passed;

    return $summary;
}

function is_youtube_content(?string $content): bool
{
    return preg_match('~(?:youtube(?:-nocookie)?\.com|youtu\.be)~i', (string) $content) === 1;
}

function youtube_embed_url(?string $content): ?string
{
    $url = trim((string) $content);
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }

    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = trim((string) ($parts['path'] ?? ''), '/');
    parse_str((string) ($parts['query'] ?? ''), $query);

    $videoId = '';
    if (preg_match('~(^|\.)youtu\.be$~i', $host) === 1) {
        $videoId = explode('/', $path)[0] ?? '';
    } elseif (preg_match('~(^|\.)youtube(?:-nocookie)?\.com$~i', $host) === 1) {
        $segments = explode('/', $path);
        if (($segments[0] ?? '') === 'watch') {
            $videoId = (string) ($query['v'] ?? '');
        } elseif (in_array($segments[0] ?? '', ['embed', 'shorts', 'live', 'v'], true)) {
            $videoId = (string) ($segments[1] ?? '');
        }
    }

    if (preg_match('~^[A-Za-z0-9_-]{6,}$~', $videoId) !== 1) {
        return null;
    }

    return 'https://www.youtube.com/embed/' . rawurlencode($videoId);
}

function lesson_uses_youtube_player(array $lesson): bool
{
    $contentType = (string) ($lesson['content_type'] ?? '');
    if ($contentType === 'video') {
        return youtube_embed_url($lesson['content'] ?? '') !== null;
    }

    return $contentType === 'embed' && is_youtube_content($lesson['content'] ?? '');
}

function lesson_requires_video_completion(array $lesson): bool
{
    if (($lesson['content_type'] ?? '') === 'video') {
        return true;
    }

    return ($lesson['content_type'] ?? '') === 'embed' && is_youtube_content($lesson['content'] ?? '');
}

function complete_non_video_lessons(array $attempt): void
{
    $stmt = db()->prepare('SELECT id, content_type, content FROM lessons WHERE course_id = ?');
    $stmt->execute([(int) $attempt['course_id']]);
    foreach ($stmt->fetchAll() as $lesson) {
        if (!lesson_requires_video_completion($lesson)) {
            mark_lesson_completed((int) $attempt['id'], (int) $lesson['id'], 'auto');
        }
    }
}

function video_completion_status(array $attempt): array
{
    ensure_lesson_progress_completion_source();
    $stmt = db()->prepare(
        "SELECT l.id, l.title, l.content_type, l.content, lp.completed_at, lp.completion_source
         FROM lessons l
         LEFT JOIN lesson_progress lp
            ON lp.lesson_id = l.id AND lp.attempt_id = ?
         WHERE l.course_id = ?
         ORDER BY l.sort_order, l.id"
    );
    $stmt->execute([(int) $attempt['id'], (int) $attempt['course_id']]);
    $videos = array_values(array_filter(
        $stmt->fetchAll(),
        fn ($lesson) => lesson_requires_video_completion($lesson)
    ));
    foreach ($videos as &$video) {
        $hasCompletedAt = !empty($video['completed_at']);
        $isTrackedYoutube = lesson_uses_youtube_player($video);
        $hasVideoSource = ($video['completion_source'] ?? '') === 'video';
        $video['is_completed'] = $hasCompletedAt && (!$isTrackedYoutube || $hasVideoSource) ? 1 : 0;
    }
    unset($video);
    $completed = array_filter($videos, fn ($video) => (int) $video['is_completed'] === 1);

    return [
        'required' => count($videos),
        'completed' => count($completed),
        'videos' => $videos,
        'ready' => count($videos) === count($completed),
    ];
}

function post_test_unlocked(array $attempt): bool
{
    $status = video_completion_status($attempt);
    return $status['ready'];
}

function import_questions(int $courseId, string $quizType, string $json, ?int $quizSetId = null): int
{
    $items = json_decode($json, true);
    if (!is_array($items)) {
        throw new RuntimeException('รูปแบบ JSON ไม่ถูกต้อง');
    }

    $pdo = db();
    $insert = $pdo->prepare(
        'INSERT INTO questions (course_id, quiz_type, question_type, prompt, choices, correct_answers, explanation, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $count = 0;
    foreach ($items as $index => $item) {
        $type = $item['type'] ?? 'single_choice';
        $allowed = ['single_choice', 'multiple_choice', 'true_false', 'short_answer'];
        if (!in_array($type, $allowed, true)) {
            throw new RuntimeException('พบชนิดข้อสอบที่ไม่รองรับ: ' . $type);
        }

        $choices = $item['choices'] ?? [];
        $answers = $item['answers'] ?? [];
        if (!is_array($answers)) {
            $answers = [$answers];
        }

        $insert->execute([
            $courseId,
            $quizType,
            $type,
            (string) ($item['prompt'] ?? ''),
            json_encode($choices, JSON_UNESCAPED_UNICODE),
            json_encode($answers, JSON_UNESCAPED_UNICODE),
            (string) ($item['explanation'] ?? ''),
            (int) ($item['sort_order'] ?? $index + 1),
        ]);
        if ($quizSetId) {
            add_question_to_quiz_set($quizSetId, (int) db()->lastInsertId());
        }
        $count++;
    }

    return $count;
}

function import_questions_from_excel(int $courseId, string $defaultQuizType, string $path, ?int $quizSetId = null): int
{
    $rows = read_xlsx_rows($path);
    if (count($rows) < 2) {
        throw new RuntimeException('ไฟล์ Excel ไม่มีข้อมูลข้อสอบ');
    }

    $headers = array_map(fn ($value) => mb_strtolower(trim((string) $value), 'UTF-8'), $rows[0]);
    $required = ['quiz_type', 'type', 'prompt', 'choices', 'answers', 'explanation', 'sort_order'];
    $indexes = [];
    foreach ($required as $field) {
        $index = array_search($field, $headers, true);
        if ($index !== false) {
            $indexes[$field] = $index;
        }
    }

    if (!isset($indexes['prompt'], $indexes['answers'])) {
        throw new RuntimeException('ไฟล์ Excel ต้องมีคอลัมน์ prompt และ answers');
    }

    $items = [];
    foreach (array_slice($rows, 1) as $rowNumber => $row) {
        $prompt = trim((string) ($row[$indexes['prompt']] ?? ''));
        if ($prompt === '') {
            continue;
        }

        $type = trim((string) ($row[$indexes['type']] ?? 'single_choice'));
        $quiz = isset($indexes['quiz_type']) ? trim((string) ($row[$indexes['quiz_type']] ?? $defaultQuizType)) : $defaultQuizType;
        $choicesText = (string) ($row[$indexes['choices']] ?? '');
        $answersText = (string) ($row[$indexes['answers']] ?? '');

        $choices = array_values(array_filter(array_map('trim', explode('|', $choicesText)), fn ($value) => $value !== ''));
        $answers = array_values(array_filter(array_map('trim', explode('|', $answersText)), fn ($value) => $value !== ''));

        if (!in_array($quiz, ['pre', 'post'], true)) {
            $quiz = $defaultQuizType;
        }

        if (!in_array($type, ['single_choice', 'multiple_choice', 'true_false', 'short_answer'], true)) {
            throw new RuntimeException('ชนิดข้อสอบไม่ถูกต้องที่แถว ' . ($rowNumber + 2));
        }

        if ($type === 'true_false' && !$choices) {
            $choices = ['ถูก', 'ผิด'];
        }

        if (!$answers) {
            throw new RuntimeException('ยังไม่มีเฉลยที่แถว ' . ($rowNumber + 2));
        }

        $items[] = [
            'quiz' => $quiz,
            'type' => $type,
            'prompt' => $prompt,
            'choices' => $choices,
            'answers' => $answers,
            'explanation' => (string) ($row[$indexes['explanation']] ?? ''),
            'sort_order' => (int) ($row[$indexes['sort_order']] ?? ($rowNumber + 1)),
        ];
    }

    $pdo = db();
    $insert = $pdo->prepare(
        'INSERT INTO questions (course_id, quiz_type, question_type, prompt, choices, correct_answers, explanation, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($items as $item) {
        $insert->execute([
            $courseId,
            $item['quiz'],
            $item['type'],
            $item['prompt'],
            json_encode($item['choices'], JSON_UNESCAPED_UNICODE),
            json_encode($item['answers'], JSON_UNESCAPED_UNICODE),
            $item['explanation'],
            $item['sort_order'],
        ]);
        if ($quizSetId) {
            add_question_to_quiz_set($quizSetId, (int) db()->lastInsertId());
        }
    }

    return count($items);
}

function add_question_to_quiz_set(int $quizSetId, int $questionId): void
{
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM quiz_set_questions WHERE quiz_set_id = ?');
    $stmt->execute([$quizSetId]);
    $order = (int) $stmt->fetchColumn();
    db()->prepare('INSERT IGNORE INTO quiz_set_questions (quiz_set_id, question_id, sort_order) VALUES (?, ?, ?)')
        ->execute([$quizSetId, $questionId, $order]);
}

function cert_item_style(array $item): string
{
    $styles = [];
    $styles[] = "left:" . $item['x'] . "%";
    $styles[] = "top:" . $item['y'] . "%";
    if (!empty($item['w'])) {
        $styles[] = "width:" . $item['w'] . "px";
    }
    if (!empty($item['h'])) {
        $styles[] = "height:" . $item['h'] . "px";
    }
    if (!empty($item['fontSize'])) {
        $styles[] = "font-size:" . $item['fontSize'] . "px";
    }
    $rotate = !empty($item['rotate']) ? (float) $item['rotate'] : 0;
    $styles[] = "transform:translate(-50%, -50%) rotate({$rotate}deg)";
    if (!empty($item['textAlign'])) {
        $styles[] = "text-align:" . $item['textAlign'];
    }

    return implode(';', $styles);
}


// ─────────────────────────────────────────────────────────────────────────────
// User authentication helpers
// ─────────────────────────────────────────────────────────────────────────────

function ensure_users_table(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_type ENUM('general','student') NOT NULL DEFAULT 'general',
            email VARCHAR(255) NULL,
            password_hash VARCHAR(255) NULL,
            google_id VARCHAR(100) NULL,
            line_id VARCHAR(100) NULL,
            student_id VARCHAR(20) NULL,
            citizen_id_masked VARCHAR(13) NULL,
            display_name VARCHAR(255) NOT NULL,
            avatar_url VARCHAR(500) NULL,
            skr_group_code VARCHAR(20) NULL,
            skr_class_name VARCHAR(255) NULL,
            skr_district_id INT NULL,
            skr_district_name VARCHAR(100) NULL,
            skr_level VARCHAR(10) NULL,
            skr_level_name VARCHAR(100) NULL,
            last_login_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY users_email_unique (email),
            UNIQUE KEY users_google_id_unique (google_id),
            UNIQUE KEY users_line_id_unique (line_id),
            UNIQUE KEY users_student_id_unique (student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!database_column_exists('users', 'line_id')) {
        db()->exec('ALTER TABLE users ADD line_id VARCHAR(100) NULL AFTER google_id');
    }
    if (!database_index_exists('users', 'users_line_id_unique')) {
        db()->exec('ALTER TABLE users ADD UNIQUE KEY users_line_id_unique (line_id)');
    }

    if (!database_column_exists('attempts', 'user_id')) {
        db()->exec('ALTER TABLE attempts ADD user_id INT UNSIGNED NULL AFTER course_id');
        try {
            db()->exec(
                'ALTER TABLE attempts ADD CONSTRAINT attempts_user_fk
                 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL'
            );
        } catch (Throwable $e) {
            // constraint may already exist
        }
    }

    $checked = true;
}

function ensure_learning_access_columns(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    ensure_users_table();
    if (!database_column_exists('courses', 'allow_retake')) {
        db()->exec('ALTER TABLE courses ADD allow_retake TINYINT(1) NOT NULL DEFAULT 0 AFTER pass_percent');
    }
    if (!database_column_exists('courses', 'category')) {
        db()->exec("ALTER TABLE courses ADD category VARCHAR(60) NOT NULL DEFAULT 'lifelong' AFTER description");
    }
    if (!database_column_exists('courses', 'access_mode')) {
        db()->exec("ALTER TABLE courses ADD access_mode VARCHAR(20) NOT NULL DEFAULT 'login_required' AFTER certificate_title");
    }

    $checked = true;
}

function current_user(): ?array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    ensure_users_table();
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch();
    if ($user) {
        $normalizedName = normalize_user_display_name((string) $user['display_name']);
        if ($normalizedName !== (string) $user['display_name']) {
            update_user_display_name((int) $user['id'], $normalizedName);
            $user['display_name'] = $normalizedName;
        }
    }
    return $user ?: null;
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        flash('กรุณาเข้าสู่ระบบก่อนเข้าเรียน', 'error');
        redirect('auth/login.php');
    }

    return $user;
}

function login_user(array $user): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
        ->execute([(int) $user['id']]);
}

function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generate_token(32);
    }

    return $_SESSION['csrf_token'];
}

function require_valid_csrf_token(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $submitted = (string) post('csrf_token');
    $expected = (string) ($_SESSION['csrf_token'] ?? '');
    if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
        throw new RuntimeException('แบบฟอร์มหมดอายุ กรุณาลองอีกครั้ง');
    }
}

function begin_oauth_login(string $provider): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!in_array($provider, ['google', 'line'], true)) {
        throw new InvalidArgumentException('Unsupported OAuth provider');
    }

    $transaction = [
        'state' => generate_token(32),
        'nonce' => generate_token(32),
        'created_at' => time(),
    ];
    $_SESSION['oauth_login'][$provider] = $transaction;

    return $transaction;
}

function consume_oauth_login(string $provider, string $returnedState, int $maxAgeSeconds = 600): ?array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $transaction = $_SESSION['oauth_login'][$provider] ?? null;
    unset($_SESSION['oauth_login'][$provider]);

    if (!is_array($transaction) || $returnedState === '') {
        return null;
    }
    $savedState = (string) ($transaction['state'] ?? '');
    $createdAt = (int) ($transaction['created_at'] ?? 0);
    if ($savedState === '' || !hash_equals($savedState, $returnedState)) {
        return null;
    }
    if ($createdAt <= 0 || time() - $createdAt > $maxAgeSeconds) {
        return null;
    }

    return $transaction;
}

function logout_user(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    unset($_SESSION['user_id'], $_SESSION['last_attempt_id'], $_SESSION['last_attempt_token']);
}

function normalize_user_display_name(string $displayName): string
{
    $displayName = trim(preg_replace('/\s+/u', ' ', $displayName) ?? $displayName);
    return preg_replace('/^(ด\.ช\.|ด\.ญ\.|น\.ส\.|เด็กชาย|เด็กหญิง|นางสาว|นาย|นาง)\s+/u', '$1', $displayName) ?? $displayName;
}

function update_user_display_name(int $userId, string $displayName): void
{
    $displayName = normalize_user_display_name($displayName);
    if ($userId <= 0 || $displayName === '') {
        throw new RuntimeException('กรุณากรอกชื่อผู้ใช้');
    }

    $pdo = db();
    $pdo->prepare('UPDATE users SET display_name = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$displayName, $userId]);
    $pdo->prepare('UPDATE attempts SET learner_name = ?, updated_at = NOW() WHERE user_id = ?')
        ->execute([$displayName, $userId]);
}

function normalize_existing_user_display_names(): int
{
    ensure_users_table();
    $users = db()->query('SELECT id, display_name FROM users ORDER BY id')->fetchAll();
    $updated = 0;
    foreach ($users as $user) {
        $normalizedName = normalize_user_display_name((string) $user['display_name']);
        if ($normalizedName !== (string) $user['display_name']) {
            update_user_display_name((int) $user['id'], $normalizedName);
            $updated++;
        }
    }

    return $updated;
}

function register_general_user(string $email, string $password, string $displayName): array
{
    ensure_users_table();
    $email = mb_strtolower(trim($email), 'UTF-8');
    $displayName = normalize_user_display_name($displayName);

    if ($email === '' || $displayName === '' || strlen($password) < 6) {
        throw new \RuntimeException('ข้อมูลไม่ครบถ้วนหรือรหัสผ่านสั้นเกินไป (ขั้นต่ำ 6 ตัวอักษร)');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new \RuntimeException('รูปแบบอีเมลไม่ถูกต้อง');
    }

    $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        throw new \RuntimeException('อีเมลนี้มีบัญชีอยู่แล้ว กรุณาเข้าสู่ระบบ');
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = db()->prepare(
        "INSERT INTO users (user_type, email, password_hash, display_name) VALUES ('general', ?, ?, ?)"
    );
    $stmt->execute([$email, $hash, $displayName]);
    $id = (int) db()->lastInsertId();
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return (array) $stmt->fetch();
}

function login_general_user(string $email, string $password): ?array
{
    ensure_users_table();
    $email = mb_strtolower(trim($email), 'UTF-8');

    $stmt = db()->prepare("SELECT * FROM users WHERE email = ? AND user_type = 'general'");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || empty($user['password_hash'])) {
        return null;
    }
    if (!password_verify($password, (string) $user['password_hash'])) {
        return null;
    }
    return (array) $user;
}

function update_general_user_password(int $userId, string $password): void
{
    ensure_users_table();
    if ($userId <= 0 || strlen($password) < 6) {
        throw new RuntimeException('รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร');
    }

    $stmt = db()->prepare(
        "UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ? AND user_type = 'general'"
    );
    $stmt->execute([password_hash($password, PASSWORD_BCRYPT), $userId]);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('ไม่พบบัญชีประชาชนทั่วไปที่ต้องการแก้ไข');
    }
}

function change_general_user_password(int $userId, string $currentPassword, string $newPassword): void
{
    ensure_users_table();
    $stmt = db()->prepare("SELECT user_type, password_hash FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || $user['user_type'] !== 'general') {
        throw new RuntimeException('บัญชีนักศึกษาไม่สามารถเปลี่ยนรหัสผ่านในระบบนี้ได้');
    }
    if (!empty($user['password_hash']) && !password_verify($currentPassword, (string) $user['password_hash'])) {
        throw new RuntimeException('รหัสผ่านเดิมไม่ถูกต้อง');
    }

    update_general_user_password($userId, $newPassword);
}

function delete_user_account(int $userId): void
{
    ensure_users_table();
    if ($userId <= 0) {
        throw new RuntimeException('ไม่พบบัญชีผู้ใช้ที่ต้องการลบ');
    }

    $stmt = db()->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('ไม่พบบัญชีผู้ใช้ที่ต้องการลบ');
    }
}

function upsert_google_user(array $profile): array
{
    ensure_users_table();
    $googleId = (string) ($profile['id'] ?? $profile['sub'] ?? '');
    $emailVerified = filter_var($profile['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $email = $emailVerified
        ? mb_strtolower(trim((string) ($profile['email'] ?? '')), 'UTF-8')
        : '';
    $name     = normalize_user_display_name((string) ($profile['name'] ?? $email));
    $avatar   = (string) ($profile['picture'] ?? '');

    if ($googleId === '') {
        throw new \RuntimeException('No Google ID');
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE google_id = ?');
    $stmt->execute([$googleId]);
    $user = $stmt->fetch();

    if ($user) {
        db()->prepare(
            'UPDATE users SET display_name = ?, avatar_url = ?, email = COALESCE(email, ?), updated_at = NOW() WHERE google_id = ?'
        )->execute([$name, $avatar, $email ?: null, $googleId]);
    } else {
        $emailCol = $email !== '' ? $email : null;
        if ($emailCol !== null) {
            $stmt2 = db()->prepare('SELECT id FROM users WHERE email = ?');
            $stmt2->execute([$emailCol]);
            if ($existing = $stmt2->fetch()) {
                db()->prepare(
                    'UPDATE users SET google_id = ?, avatar_url = ?, updated_at = NOW() WHERE id = ?'
                )->execute([$googleId, $avatar, (int) $existing['id']]);
                $stmt3 = db()->prepare('SELECT * FROM users WHERE id = ?');
                $stmt3->execute([(int) $existing['id']]);
                return (array) $stmt3->fetch();
            }
        }
        $stmt = db()->prepare(
            "INSERT INTO users (user_type, email, google_id, display_name, avatar_url) VALUES ('general', ?, ?, ?, ?)"
        );
        $stmt->execute([$emailCol, $googleId, $name, $avatar]);
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE google_id = ?');
    $stmt->execute([$googleId]);
    return (array) $stmt->fetch();
}

function upsert_line_user(array $profile): array
{
    ensure_users_table();
    $lineId = (string) ($profile['userId'] ?? $profile['sub'] ?? '');
    $email = mb_strtolower(trim((string) ($profile['email'] ?? '')), 'UTF-8');
    $name = normalize_user_display_name((string) ($profile['displayName'] ?? $profile['name'] ?? $email));
    $avatar = (string) ($profile['pictureUrl'] ?? $profile['picture'] ?? '');

    if ($lineId === '') {
        throw new RuntimeException('ไม่พบ LINE user id');
    }
    if ($name === '') {
        $name = 'LINE User';
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE line_id = ?');
    $stmt->execute([$lineId]);
    $user = $stmt->fetch();

    if ($user) {
        $emailForUpdate = null;
        if ($email !== '' && empty($user['email'])) {
            $emailStmt = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
            $emailStmt->execute([$email, (int) $user['id']]);
            if (!$emailStmt->fetch()) {
                $emailForUpdate = $email;
            }
        }
        db()->prepare(
            'UPDATE users
             SET display_name = ?, avatar_url = ?, email = COALESCE(email, ?), updated_at = NOW()
             WHERE line_id = ?'
        )->execute([$name, $avatar, $emailForUpdate, $lineId]);
    } else {
        $emailCol = $email !== '' ? $email : null;
        if ($emailCol !== null) {
            $stmt2 = db()->prepare('SELECT id FROM users WHERE email = ?');
            $stmt2->execute([$emailCol]);
            if ($existing = $stmt2->fetch()) {
                db()->prepare(
                    'UPDATE users SET line_id = ?, avatar_url = ?, updated_at = NOW() WHERE id = ?'
                )->execute([$lineId, $avatar, (int) $existing['id']]);
                $stmt3 = db()->prepare('SELECT * FROM users WHERE id = ?');
                $stmt3->execute([(int) $existing['id']]);
                return (array) $stmt3->fetch();
            }
        }
        $stmt = db()->prepare(
            "INSERT INTO users (user_type, email, line_id, display_name, avatar_url) VALUES ('general', ?, ?, ?, ?)"
        );
        $stmt->execute([$emailCol, $lineId, $name, $avatar]);
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE line_id = ?');
    $stmt->execute([$lineId]);
    return (array) $stmt->fetch();
}

function lookup_skr_student(string $studentId): ?array
{
    $studentId = trim($studentId);
    if ($studentId === '') {
        return null;
    }

    $apiKey = student_api_key();
    if ($apiKey === '') {
        throw new RuntimeException('ยังไม่ได้ตั้งค่า API key สำหรับระบบนักศึกษา ศกร.');
    }

    $query = http_build_query([
        'student_id'       => $studentId,
        'status'           => 'active',
        'limit'            => 10,
        'include_sensitive' => 1,
    ]);
    $lastError = '';
    $hasSuccessfulResponse = false;

    foreach (student_api_source_urls() as $sourceUrl) {
        if (!student_api_url_is_secure($sourceUrl)) {
            $lastError = 'Student API must use HTTPS outside localhost';
            continue;
        }

        $separator = str_contains($sourceUrl, '?') ? '&' : '?';
        [$raw, $httpCode, $error] = fetch_student_api_url(
            $sourceUrl . $separator . $query,
            $apiKey
        );
        if ($raw === false || trim($raw) === '') {
            $lastError = $error !== '' ? $error : 'empty response';
            continue;
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            $lastError = 'invalid JSON response';
            continue;
        }
        if ($httpCode < 200 || $httpCode >= 300 || empty($json['success'])) {
            $lastError = (string) ($json['message'] ?? ('HTTP ' . $httpCode));
            continue;
        }
        $hasSuccessfulResponse = true;

        foreach (($json['data'] ?? []) as $student) {
            if (!is_array($student)) {
                continue;
            }
            $apiStudentId = trim((string) ($student['student_id'] ?? ''));
            if ($apiStudentId !== '' && hash_equals($studentId, $apiStudentId)) {
                return $student;
            }
        }

        // A local development snapshot may be older than the next configured
        // source. Keep looking before reporting that the student is missing.
    }

    if ($hasSuccessfulResponse) {
        return null;
    }

    error_log('Student API lookup failed: ' . $lastError);
    throw new RuntimeException('ไม่สามารถเชื่อมต่อระบบข้อมูลนักศึกษา ศกร. ได้ในขณะนี้');
}

function lookup_skr_student_by_citizen_id(string $citizenId): ?array
{
    $citizenId = normalize_skr_citizen_id($citizenId);
    if (!preg_match('/^\d{13}$/', $citizenId)) {
        return null;
    }

    $apiKey = student_api_key();
    if ($apiKey === '') {
        throw new RuntimeException('ยังไม่ได้ตั้งค่า API key สำหรับระบบนักศึกษา ศกร.');
    }

    $query = http_build_query([
        'q'                 => $citizenId,
        'status'            => 'active',
        'limit'             => 10,
        'include_sensitive' => 1,
    ]);
    $lastError = '';
    $hasSuccessfulResponse = false;

    foreach (student_api_source_urls() as $sourceUrl) {
        if (!student_api_url_is_secure($sourceUrl)) {
            $lastError = 'Student API must use HTTPS outside localhost';
            continue;
        }

        $separator = str_contains($sourceUrl, '?') ? '&' : '?';
        [$raw, $httpCode, $error] = fetch_student_api_url(
            $sourceUrl . $separator . $query,
            $apiKey
        );
        if ($raw === false || trim($raw) === '') {
            $lastError = $error !== '' ? $error : 'empty response';
            continue;
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            $lastError = 'invalid JSON response';
            continue;
        }
        if ($httpCode < 200 || $httpCode >= 300 || empty($json['success'])) {
            $lastError = (string) ($json['message'] ?? ('HTTP ' . $httpCode));
            continue;
        }
        $hasSuccessfulResponse = true;

        foreach (($json['data'] ?? []) as $student) {
            if (!is_array($student)) {
                continue;
            }
            $apiCitizenId = normalize_skr_citizen_id((string) ($student['citizen_id'] ?? ''));
            if ($apiCitizenId !== '' && hash_equals($citizenId, $apiCitizenId)) {
                return $student;
            }
        }
    }

    if ($hasSuccessfulResponse) {
        return null;
    }

    error_log('Student API citizen lookup failed: ' . $lastError);
    throw new RuntimeException('ไม่สามารถเชื่อมต่อระบบข้อมูลนักศึกษา ศกร. ได้ในขณะนี้');
}

function normalize_skr_citizen_id(string $citizenId): string
{
    $citizenId = strtoupper(trim($citizenId));
    return preg_replace('/[^A-Z0-9]/', '', $citizenId) ?? '';
}

function student_api_source_urls(): array
{
    $configuredUrl = trim((string) STUDENT_API_URL);
    if ($configuredUrl !== '') {
        return [$configuredUrl];
    }

    $origin = student_api_same_origin();
    if ($origin === '') {
        return [];
    }

    $host = (string) (parse_url($origin, PHP_URL_HOST) ?: '');
    $isLocal = in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
    $urls = [];
    if ($isLocal) {
        $urls[] = STUDENT_API_LOCAL_URL;
        $urls[] = $origin . '/sena_care_school%203/api/students.php';
    }
    $urls[] = $origin . '/sena_care_school/api/students.php';
    $urls[] = $origin . '/sena_care_school_api/students.php';

    return array_values(array_unique($urls));
}

function student_api_key(): string
{
    $configuredKey = trim((string) STUDENT_API_KEY);
    if ($configuredKey !== '') {
        return $configuredKey;
    }

    $origin = student_api_same_origin();
    $host = strtolower((string) (parse_url($origin, PHP_URL_HOST) ?: ''));
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return STUDENT_API_LOCAL_KEY;
    }

    return '';
}

function student_api_same_origin(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        ? 'https'
        : 'http';
    $host = normalize_student_api_host((string) ($_SERVER['SERVER_NAME'] ?? ''));
    $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);

    if ($host === '') {
        $scheme = (string) (parse_url(APP_URL, PHP_URL_SCHEME) ?: 'http');
        $host = normalize_student_api_host((string) (parse_url(APP_URL, PHP_URL_HOST) ?: ''));
        $port = (int) (parse_url(APP_URL, PHP_URL_PORT) ?: 0);
    }
    if ($host === '') {
        return '';
    }

    $displayHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
    $defaultPort = $scheme === 'https' ? 443 : 80;
    $portSuffix = $port > 0 && $port !== $defaultPort ? ':' . $port : '';

    return $scheme . '://' . $displayHost . $portSuffix;
}

function normalize_student_api_host(string $host): string
{
    $host = trim($host, " \t\n\r\0\x0B[]");
    if ($host === '') {
        return '';
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return strtolower($host);
    }
    if (!preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/i', $host)) {
        return '';
    }

    return strtolower($host);
}

function student_api_url_is_secure(string $url): bool
{
    $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?: ''));
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));

    return $scheme === 'https'
        || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true));
}

function fetch_student_api_url(string $url, string $apiKey): array
{
    $headers = [
        'X-API-Key: ' . $apiKey,
        'Accept: application/json',
    ];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }
        return [$raw, $httpCode, $error];
    }

    $context = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'header'          => implode("\r\n", $headers) . "\r\n",
            'timeout'         => 10,
            'ignore_errors'   => true,
            'follow_location' => 0,
            'max_redirects'   => 0,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    $httpCode = 0;
    if (!empty($http_response_header) && preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match)) {
        $httpCode = (int) $match[1];
    }

    return [$raw, $httpCode, $raw === false ? 'file_get_contents failed' : ''];
}

function upsert_skr_user(array $apiStudent): array
{
    ensure_users_table();
    $studentId   = (string) ($apiStudent['student_id'] ?? '');
    $prename     = trim((string) ($apiStudent['prename'] ?? ''));
    $firstName   = trim((string) ($apiStudent['first_name'] ?? ''));
    $lastName    = trim((string) ($apiStudent['last_name'] ?? ''));
    $displayName = $firstName !== ''
        ? $prename . $firstName . ($lastName !== '' ? ' ' . $lastName : '')
        : (string) ($apiStudent['full_name'] ?? '');
    $citizenId   = normalize_skr_citizen_id((string) ($apiStudent['citizen_id'] ?? ''));
    $displayName = normalize_user_display_name($displayName);

    $maskedCitizenId = $citizenId !== ''
        ? str_repeat('*', max(0, strlen($citizenId) - 4)) . substr($citizenId, -4)
        : null;

    $stmt = db()->prepare('SELECT * FROM users WHERE student_id = ?');
    $stmt->execute([$studentId]);
    $user = $stmt->fetch();

    if ($user) {
        db()->prepare(
            'UPDATE users
             SET display_name = ?, citizen_id_masked = ?,
                 skr_group_code = ?, skr_class_name = ?,
                 skr_district_id = ?, skr_district_name = ?,
                 skr_level = ?, skr_level_name = ?, updated_at = NOW()
             WHERE student_id = ?'
        )->execute([
            $displayName, $maskedCitizenId,
            $apiStudent['group_code'] ?? null, $apiStudent['class_name'] ?? null,
            isset($apiStudent['district_id']) ? (int) $apiStudent['district_id'] : null,
            $apiStudent['district_name'] ?? null,
            $apiStudent['level'] ?? null, $apiStudent['level_name'] ?? null,
            $studentId,
        ]);
    } else {
        $stmt = db()->prepare(
            "INSERT INTO users
                (user_type, student_id, citizen_id_masked, display_name,
                 skr_group_code, skr_class_name, skr_district_id, skr_district_name, skr_level, skr_level_name)
             VALUES ('student', ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $studentId, $maskedCitizenId, $displayName,
            $apiStudent['group_code'] ?? null, $apiStudent['class_name'] ?? null,
            isset($apiStudent['district_id']) ? (int) $apiStudent['district_id'] : null,
            $apiStudent['district_name'] ?? null,
            $apiStudent['level'] ?? null, $apiStudent['level_name'] ?? null,
        ]);
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE student_id = ?');
    $stmt->execute([$studentId]);
    $user = (array) $stmt->fetch();
    update_user_display_name((int) $user['id'], $displayName);
    $user['display_name'] = $displayName;
    return $user;
}

function link_attempt_to_user(int $attemptId, int $userId): void
{
    ensure_users_table();
    db()->prepare('UPDATE attempts SET user_id = ? WHERE id = ?')
        ->execute([$userId, $attemptId]);
}
