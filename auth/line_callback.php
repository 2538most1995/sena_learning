<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

ensure_users_table();

if (!LINE_CHANNEL_ID || !LINE_CHANNEL_SECRET) {
    flash('LINE Login ยังไม่ได้เปิดใช้งาน', 'error');
    redirect('login.php');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$returnedState = (string) ($_GET['state'] ?? '');
$savedState = (string) ($_SESSION['line_oauth_state'] ?? '');
$savedNonce = (string) ($_SESSION['line_oauth_nonce'] ?? '');
unset($_SESSION['line_oauth_state'], $_SESSION['line_oauth_nonce']);

if ($returnedState === '' || $savedState === '' || !hash_equals($savedState, $returnedState)) {
    flash('คำขอ LINE ไม่ถูกต้อง กรุณาลองใหม่', 'error');
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
    $idTokenProfile = line_verify_id_token((string) $tokenResponse['id_token'], $savedNonce);
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
    redirect('../index.php');
} catch (Throwable $e) {
    flash('เกิดข้อผิดพลาด: ' . $e->getMessage(), 'error');
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

function line_verify_id_token(string $idToken, string $expectedNonce): array
{
    $payload = line_post('https://api.line.me/oauth2/v2.1/verify', [
        'id_token' => $idToken,
        'client_id' => LINE_CHANNEL_ID,
    ]);
    if (!$payload) {
        return line_decode_id_token($idToken, $expectedNonce);
    }
    if ($expectedNonce !== '' && !hash_equals($expectedNonce, (string) ($payload['nonce'] ?? ''))) {
        return [];
    }

    return $payload;
}

function line_decode_id_token(string $idToken, string $expectedNonce): array
{
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) {
        return [];
    }

    $payload = json_decode(base64_url_decode($parts[1]), true);
    if (!is_array($payload)) {
        return [];
    }

    if (($payload['iss'] ?? '') !== 'https://access.line.me') {
        return [];
    }
    if ((string) ($payload['aud'] ?? '') !== LINE_CHANNEL_ID) {
        return [];
    }
    if (!empty($payload['exp']) && (int) $payload['exp'] < time()) {
        return [];
    }
    if ($expectedNonce !== '' && !hash_equals($expectedNonce, (string) ($payload['nonce'] ?? ''))) {
        return [];
    }

    return $payload;
}

function base64_url_decode(string $value): string
{
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    return (string) base64_decode(strtr($value, '-_', '+/'), true);
}
