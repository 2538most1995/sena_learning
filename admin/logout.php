<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

unset($_SESSION['admin_ok'], $_SESSION['admin_user_id']);
flash('ออกจากระบบหลังบ้านแล้ว');
redirect('../index.php');
