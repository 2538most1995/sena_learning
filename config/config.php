<?php
declare(strict_types=1);

// Shared hosting can keep its complete legacy production configuration in
// config/private.php. This file is intentionally ignored by Git so a deploy
// cannot replace production database credentials with the MAMP defaults.
$privateConfigFile = __DIR__ . '/private.php';
if (is_file($privateConfigFile)) {
    require $privateConfigFile;

    // Older production configuration files predate optional LINE login.
    defined('LINE_CHANNEL_ID') || define('LINE_CHANNEL_ID', '');
    defined('LINE_CHANNEL_SECRET') || define('LINE_CHANNEL_SECRET', '');
    defined('LINE_REDIRECT_URI') || define('LINE_REDIRECT_URI', rtrim(APP_URL, '/') . '/auth/line_callback.php');

    date_default_timezone_set('Asia/Bangkok');
    return;
}

const APP_NAME = 'SENA Learning';
const APP_TAGLINE = 'ระบบจัดการเรียนรู้ สกร.ระดับอำเภอเสนา';
define('APP_URL', rtrim((string) (
    getenv('SENA_LEARNING_APP_URL')
    ?: getenv('REDIRECT_SENA_LEARNING_APP_URL')
    ?: ($_SERVER['SENA_LEARNING_APP_URL'] ?? '')
    ?: ($_SERVER['REDIRECT_SENA_LEARNING_APP_URL'] ?? '')
    ?: 'http://localhost:8888'
), '/'));

const DB_HOST = '127.0.0.1';
const DB_PORT = '8889';
const DB_NAME = 'sena_learning';
const DB_USER = 'root';
const DB_PASS = 'root';

const ADMIN_USERNAME = 'admin';
const ADMIN_PIN = '123456';
const CERTIFICATE_ISSUER = 'SENA Learning Center';
const CERTIFICATE_SIGNATURE = 'ผู้อำนวยการหลักสูตร';

// Student API (sena_care_school)
// Production reads the API on the same website at /sena_care_school/api/students.php.
// Environment variables take precedence. Shared hosting can use the private
// PHP config file when nginx or PHP-FPM environment variables are unavailable.
$studentApiConfigFile = __DIR__ . '/student_api.php';
$studentApiConfig = is_file($studentApiConfigFile) ? require $studentApiConfigFile : [];
if (!is_array($studentApiConfig)) {
    $studentApiConfig = [];
}
define('STUDENT_API_URL', trim((string) (
    getenv('SENA_LEARNING_STUDENT_API_URL')
    ?: getenv('REDIRECT_SENA_LEARNING_STUDENT_API_URL')
    ?: ($_SERVER['SENA_LEARNING_STUDENT_API_URL'] ?? '')
    ?: ($_SERVER['REDIRECT_SENA_LEARNING_STUDENT_API_URL'] ?? '')
    ?: ($studentApiConfig['url'] ?? '')
    ?: ''
)));
define('STUDENT_API_KEY', trim((string) (
    getenv('SENA_LEARNING_STUDENT_API_KEY')
    ?: getenv('REDIRECT_SENA_LEARNING_STUDENT_API_KEY')
    ?: ($_SERVER['SENA_LEARNING_STUDENT_API_KEY'] ?? '')
    ?: ($_SERVER['REDIRECT_SENA_LEARNING_STUDENT_API_KEY'] ?? '')
    ?: ($studentApiConfig['key'] ?? '')
    ?: ''
)));
const STUDENT_API_LOCAL_URL = 'http://localhost:8888/sena_care_school_api/students.php';
const STUDENT_API_LOCAL_KEY = 'sena_learning_key_2026';

// Learning progress export API. Used by trusted external websites to read
// student course progress and certificate links from this application.
$learningApiConfigFile = __DIR__ . '/learning_api.php';
$learningApiConfig = is_file($learningApiConfigFile) ? require $learningApiConfigFile : [];
if (!is_array($learningApiConfig)) {
    $learningApiConfig = [];
}
define('LEARNING_EXPORT_API_KEY', trim((string) (
    getenv('SENA_LEARNING_EXPORT_API_KEY')
    ?: getenv('REDIRECT_SENA_LEARNING_EXPORT_API_KEY')
    ?: ($_SERVER['SENA_LEARNING_EXPORT_API_KEY'] ?? '')
    ?: ($_SERVER['REDIRECT_SENA_LEARNING_EXPORT_API_KEY'] ?? '')
    ?: ($learningApiConfig['key'] ?? '')
    ?: ''
)));

// Social login OAuth (ตั้งค่าเพื่อเปิดใช้งาน หรือปล่อยว่างเพื่อปิด)
// Environment variables take precedence. Shared hosting can use a private
// config/oauth.php file returning keys such as google_client_id and line_channel_id.
$oauthConfigFile = __DIR__ . '/oauth.php';
$oauthConfig = is_file($oauthConfigFile) ? require $oauthConfigFile : [];
if (!is_array($oauthConfig)) {
    $oauthConfig = [];
}
define('GOOGLE_CLIENT_ID', trim((string) (
    getenv('SENA_LEARNING_GOOGLE_CLIENT_ID')
    ?: getenv('REDIRECT_SENA_LEARNING_GOOGLE_CLIENT_ID')
    ?: ($_SERVER['SENA_LEARNING_GOOGLE_CLIENT_ID'] ?? '')
    ?: ($_SERVER['REDIRECT_SENA_LEARNING_GOOGLE_CLIENT_ID'] ?? '')
    ?: ($oauthConfig['google_client_id'] ?? '')
    ?: ''
)));
define('GOOGLE_CLIENT_SECRET', trim((string) (
    getenv('SENA_LEARNING_GOOGLE_CLIENT_SECRET')
    ?: getenv('REDIRECT_SENA_LEARNING_GOOGLE_CLIENT_SECRET')
    ?: ($_SERVER['SENA_LEARNING_GOOGLE_CLIENT_SECRET'] ?? '')
    ?: ($_SERVER['REDIRECT_SENA_LEARNING_GOOGLE_CLIENT_SECRET'] ?? '')
    ?: ($oauthConfig['google_client_secret'] ?? '')
    ?: ''
)));
define('GOOGLE_REDIRECT_URI', trim((string) (
    getenv('SENA_LEARNING_GOOGLE_REDIRECT_URI')
    ?: getenv('REDIRECT_SENA_LEARNING_GOOGLE_REDIRECT_URI')
    ?: ($_SERVER['SENA_LEARNING_GOOGLE_REDIRECT_URI'] ?? '')
    ?: ($_SERVER['REDIRECT_SENA_LEARNING_GOOGLE_REDIRECT_URI'] ?? '')
    ?: ($oauthConfig['google_redirect_uri'] ?? '')
    ?: APP_URL . '/auth/google_callback.php'
)));
define('LINE_CHANNEL_ID', trim((string) (
    getenv('SENA_LEARNING_LINE_CHANNEL_ID')
    ?: getenv('REDIRECT_SENA_LEARNING_LINE_CHANNEL_ID')
    ?: ($_SERVER['SENA_LEARNING_LINE_CHANNEL_ID'] ?? '')
    ?: ($_SERVER['REDIRECT_SENA_LEARNING_LINE_CHANNEL_ID'] ?? '')
    ?: ($oauthConfig['line_channel_id'] ?? '')
    ?: ''
)));
define('LINE_CHANNEL_SECRET', trim((string) (
    getenv('SENA_LEARNING_LINE_CHANNEL_SECRET')
    ?: getenv('REDIRECT_SENA_LEARNING_LINE_CHANNEL_SECRET')
    ?: ($_SERVER['SENA_LEARNING_LINE_CHANNEL_SECRET'] ?? '')
    ?: ($_SERVER['REDIRECT_SENA_LEARNING_LINE_CHANNEL_SECRET'] ?? '')
    ?: ($oauthConfig['line_channel_secret'] ?? '')
    ?: ''
)));
define('LINE_REDIRECT_URI', trim((string) (
    getenv('SENA_LEARNING_LINE_REDIRECT_URI')
    ?: getenv('REDIRECT_SENA_LEARNING_LINE_REDIRECT_URI')
    ?: ($_SERVER['SENA_LEARNING_LINE_REDIRECT_URI'] ?? '')
    ?: ($_SERVER['REDIRECT_SENA_LEARNING_LINE_REDIRECT_URI'] ?? '')
    ?: ($oauthConfig['line_redirect_uri'] ?? '')
    ?: APP_URL . '/auth/line_callback.php'
)));

date_default_timezone_set('Asia/Bangkok');
