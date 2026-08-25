<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../index.php');
}
try {
    require_valid_csrf_token();
} catch (RuntimeException $e) {
    flash($e->getMessage(), 'error');
    redirect('../index.php');
}

logout_user();
flash('ออกจากระบบเรียบร้อยแล้ว');
redirect('../index.php');
