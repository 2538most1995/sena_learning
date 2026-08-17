<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_admin();

$path = __DIR__ . '/../storage/templates/question_import_template.xlsx';
create_question_template_xlsx($path);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="sena-question-import-template.xlsx"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;

