<?php
declare(strict_types=1);

return [
    // Google Cloud Console > OAuth 2.0 Client IDs
    // Authorized redirect URI: https://your-domain.example/sena_learning/auth/google_callback.php
    'google_client_id' => '',
    'google_client_secret' => '',
    // Optional: set this when the callback must differ from APP_URL.
    'google_redirect_uri' => '',

    // LINE Developers > LINE Login channel
    // Callback URL: https://your-domain.example/sena_learning/auth/line_callback.php
    'line_channel_id' => '',
    'line_channel_secret' => '',
    // Optional: set this when the callback must differ from APP_URL.
    'line_redirect_uri' => '',
];
