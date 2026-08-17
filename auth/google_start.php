<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

if (!GOOGLE_CLIENT_ID || !GOOGLE_CLIENT_SECRET) {
    flash('Google OAuth ยังไม่ได้เปิดใช้งาน กรุณาตั้งค่า Google client id และ secret', 'error');
    redirect('login.php');
}

$state = generate_token(16);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['oauth_state'] = $state;

$params = http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'access_type'   => 'online',
    'prompt'        => 'select_account',
]);

redirect('https://accounts.google.com/o/oauth2/v2/auth?' . $params);
