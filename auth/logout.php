<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

logout_user();
flash('ออกจากระบบเรียบร้อยแล้ว');
redirect('../index.php');
