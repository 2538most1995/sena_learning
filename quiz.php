<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

$attempt = require_attempt();
flash('ข้อสอบถูกรวมไว้ในลำดับการเรียนแล้ว ระบบพาคุณกลับไปยังรายการที่ต้องทำต่อ');
redirect(attempt_url('lesson.php', $attempt));
