<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

if (!LINE_CHANNEL_ID || !LINE_CHANNEL_SECRET) {
    flash('LINE Login ยังไม่ได้เปิดใช้งาน กรุณาตั้งค่า LINE channel id และ secret', 'error');
    redirect('login.php');
}

$oauth = begin_oauth_login('line');

$params = http_build_query([
    'response_type' => 'code',
    'client_id' => LINE_CHANNEL_ID,
    'redirect_uri' => LINE_REDIRECT_URI,
    'state' => $oauth['state'],
    'scope' => 'profile openid email',
    'nonce' => $oauth['nonce'],
    'bot_prompt' => 'normal',
]);

redirect('https://access.line.me/oauth2/v2.1/authorize?' . $params);
