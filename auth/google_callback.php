<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

ensure_users_table();

if (!GOOGLE_CLIENT_ID || !GOOGLE_CLIENT_SECRET) {
    flash('Google OAuth ยังไม่ได้เปิดใช้งาน', 'error');
    redirect('../auth/login.php');
}

$returnedState = (string) ($_GET['state'] ?? '');
$oauth = consume_oauth_login('google', $returnedState);
if ($oauth === null) {
    flash('คำขอไม่ถูกต้อง กรุณาลองใหม่', 'error');
    redirect('login.php');
}

if (isset($_GET['error'])) {
    flash('ยกเลิกการเข้าสู่ระบบด้วย Google แล้ว', 'error');
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
    $tokenError = (string) ($tokenResponse['error'] ?? 'connection_failed');
    $tokenErrorDescription = (string) ($tokenResponse['error_description'] ?? '');
    error_log('Google token exchange failed: ' . $tokenError . ($tokenErrorDescription !== '' ? ' - ' . $tokenErrorDescription : ''));

    $message = match ($tokenError) {
        'invalid_client' => 'Google Client Secret ไม่ตรงกับ OAuth Client กรุณาตรวจการตั้งค่าฝั่งเซิร์ฟเวอร์',
        'invalid_grant' => 'คำขอเข้าสู่ระบบ Google หมดอายุหรือถูกใช้แล้ว กรุณากดเข้าสู่ระบบด้วย Google ใหม่อีกครั้ง',
        'redirect_uri_mismatch' => 'Callback URL ของ Google ไม่ตรงกัน กรุณาตรวจ Authorized redirect URI',
        default => 'เซิร์ฟเวอร์ไม่สามารถเชื่อมต่อ Google เพื่อยืนยันการเข้าสู่ระบบได้ กรุณาลองใหม่',
    };
    flash($message, 'error');
    redirect('login.php');
}

// Get user profile
$profile = google_get(
    'https://openidconnect.googleapis.com/v1/userinfo',
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
    redirect(post_login_redirect_path());
} catch (Throwable $e) {
    error_log('Google login failed: ' . $e->getMessage());
    flash('ไม่สามารถเข้าสู่ระบบด้วย Google ได้ กรุณาลองใหม่', 'error');
    redirect('login.php');
}

// ─── helpers ─────────────────────────────────────────────────────────────────

function google_post(string $url, array $data): array
{
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl !== false) {
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($data),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
            ]);
            $raw = curl_exec($curl);
            $curlError = curl_error($curl);
            curl_close($curl);
            if (is_string($raw) && $raw !== '') {
                return (array) (json_decode($raw, true) ?: []);
            }
            if ($curlError !== '') {
                error_log('Google token endpoint connection failed: ' . $curlError);
            }
        }
    }

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
