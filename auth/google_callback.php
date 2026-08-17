<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

ensure_users_table();

if (!GOOGLE_CLIENT_ID || !GOOGLE_CLIENT_SECRET) {
    flash('Google OAuth ยังไม่ได้เปิดใช้งาน', 'error');
    redirect('../auth/login.php');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Validate state
$returnedState = (string) ($_GET['state'] ?? '');
$savedState    = (string) ($_SESSION['oauth_state'] ?? '');
unset($_SESSION['oauth_state']);

if ($returnedState === '' || $returnedState !== $savedState) {
    flash('คำขอไม่ถูกต้อง กรุณาลองใหม่', 'error');
    redirect('login.php');
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    flash('ไม่ได้รับรหัสจาก Google กรุณาลองใหม่', 'error');
    redirect('login.php');
}

// Exchange code for token
$tokenResponse = google_post('https://oauth2.googleapis.com/token', [
    'code'          => $code,
    'client_id'     => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'grant_type'    => 'authorization_code',
]);

if (empty($tokenResponse['access_token'])) {
    flash('ไม่สามารถแลกรับ token จาก Google ได้', 'error');
    redirect('login.php');
}

// Get user profile
$profile = google_get(
    'https://www.googleapis.com/oauth2/v3/userinfo',
    (string) $tokenResponse['access_token']
);

if (empty($profile['sub'])) {
    flash('ไม่สามารถดึงข้อมูลผู้ใช้จาก Google ได้', 'error');
    redirect('login.php');
}

try {
    $user = upsert_google_user($profile);
    login_user($user);
    flash('เข้าสู่ระบบด้วย Google สำเร็จ ยินดีต้อนรับ ' . $user['display_name']);
    redirect('../index.php');
} catch (Throwable $e) {
    flash('เกิดข้อผิดพลาด: ' . $e->getMessage(), 'error');
    redirect('login.php');
}

// ─── helpers ─────────────────────────────────────────────────────────────────

function google_post(string $url, array $data): array
{
    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    return $raw ? (array) (json_decode($raw, true) ?: []) : [];
}

function google_get(string $url, string $accessToken): array
{
    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "Authorization: Bearer {$accessToken}\r\n",
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    return $raw ? (array) (json_decode($raw, true) ?: []) : [];
}
