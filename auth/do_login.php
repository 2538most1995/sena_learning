<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

ensure_users_table();

if (current_user()) {
    redirect('../index.php');
}

// Handle POST: email/password login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_valid_csrf_token();
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
        redirect('login.php?tab=general');
    }
    $email    = trim((string) post('email'));
    $password = (string) post('password');

    $user = login_general_user($email, $password);
    if (!$user) {
        flash('อีเมลหรือรหัสผ่านไม่ถูกต้อง', 'error');
        redirect('login.php?tab=general');
    }

    login_user($user);
    flash('เข้าสู่ระบบสำเร็จ ยินดีต้อนรับ ' . $user['display_name']);
    redirect('../index.php');
}

redirect('login.php');
