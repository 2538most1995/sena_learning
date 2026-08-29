<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

ensure_users_table();

if (!LINE_CHANNEL_ID || !LINE_CHANNEL_SECRET) {
    flash('LINE Login ยังไม่ได้เปิดใช้งาน', 'error');
    redirect('login.php');
}

$returnedState = (string) ($_GET['state'] ?? '');
$oauth = consume_oauth_login('line', $returnedState);
if ($oauth === null) {
    flash('คำขอ LINE ไม่ถูกต้อง กรุณาลองใหม่', 'error');
    redirect('login.php');
}
$savedNonce = (string) ($oauth['nonce'] ?? '');

if (isset($_GET['error'])) {
    flash('ยกเลิกการเข้าสู่ระบบด้วย LINE แล้ว', 'error');
    redirect('login.php');
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    flash('ไม่ได้รับรหัสจาก LINE กรุณาลองใหม่', 'error');
    redirect('login.php');
}

$tokenResponse = line_post('https://api.line.me/oauth2/v2.1/token', [
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => LINE_REDIRECT_URI,
    'client_id' => LINE_CHANNEL_ID,
    'client_secret' => LINE_CHANNEL_SECRET,
]);

if (empty($tokenResponse['access_token'])) {
    flash('ไม่สามารถแลกรับ token จาก LINE ได้', 'error');
    redirect('login.php');
}

$profile = line_get('https://api.line.me/v2/profile', (string) $tokenResponse['access_token']);
$idTokenProfile = [];
if (!empty($tokenResponse['id_token'])) {
    $idTokenProfile = line_verify_id_token(
        (string) $tokenResponse['id_token'],
        $savedNonce,
        (string) ($profile['userId'] ?? '')
    );
}

$lineProfile = array_filter([
    'userId' => (string) ($profile['userId'] ?? $idTokenProfile['sub'] ?? ''),
    'displayName' => (string) ($profile['displayName'] ?? $idTokenProfile['name'] ?? ''),
    'pictureUrl' => (string) ($profile['pictureUrl'] ?? $idTokenProfile['picture'] ?? ''),
    'email' => (string) ($idTokenProfile['email'] ?? ''),
]);

if (empty($lineProfile['userId'])) {
    flash('ไม่สามารถดึงข้อมูลผู้ใช้จาก LINE ได้', 'error');
    redirect('login.php');
}

try {
    $user = upsert_line_user($lineProfile);
    login_user($user);
    flash('เข้าสู่ระบบด้วย LINE สำเร็จ ยินดีต้อนรับ ' . $user['display_name']);
    redirect(post_login_redirect_path());
} catch (Throwable $e) {
    error_log('LINE login failed: ' . $e->getMessage());
    flash('ไม่สามารถเข้าสู่ระบบด้วย LINE ได้ กรุณาลองใหม่', 'error');
    redirect('login.php');
}

function line_post(string $url, array $data): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    return $raw ? (array) (json_decode($raw, true) ?: []) : [];
}

function line_get(string $url, string $accessToken): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer {$accessToken}\r\n",
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    return $raw ? (array) (json_decode($raw, true) ?: []) : [];
}

function line_verify_id_token(string $idToken, string $expectedNonce, string $expectedUserId): array
{
    $verification = [
        'id_token' => $idToken,
        'client_id' => LINE_CHANNEL_ID,
        'nonce' => $expectedNonce,
    ];
    if ($expectedUserId !== '') {
        $verification['user_id'] = $expectedUserId;
    }
    $payload = line_post('https://api.line.me/oauth2/v2.1/verify', $verification);
    if (!$payload) {
        return [];
    }
    $subject = (string) ($payload['sub'] ?? '');
    if ($subject === '' || ($expectedNonce !== '' && !hash_equals($expectedNonce, (string) ($payload['nonce'] ?? '')))) {
        return [];
    }
    if ($expectedUserId !== '' && !hash_equals($expectedUserId, $subject)) {
        return [];
    }

    return $payload;
}
