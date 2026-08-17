<?php
declare(strict_types=1);

function xlsx_col(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function xlsx_xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function create_question_template_xlsx(string $path): void
{
    $headers = ['type', 'prompt', 'choices', 'answers', 'explanation', 'sort_order'];
    $rows = [
        ['single_choice', 'ระบบนี้ต้องเข้าสู่ระบบก่อนเรียนหรือไม่', 'ต้องเข้าสู่ระบบ|ไม่ต้องเข้าสู่ระบบ|ต้องรออนุมัติ', 'ต้องเข้าสู่ระบบ', 'ระบบบันทึกผลการเรียนแยกตามบัญชี', '1'],
        ['multiple_choice', 'องค์ประกอบใดควรมีในระบบจัดการเรียนรู้', 'บทเรียน/สื่อ|ชุดข้อสอบ|เกียรติบัตร|ปิดไม่ให้ผู้เรียนทั่วไปเข้า', 'บทเรียน/สื่อ|ชุดข้อสอบ|เกียรติบัตร', 'เลือกได้มากกว่า 1 ข้อ', '2'],
        ['short_answer', 'พิมพ์คำว่า SENA เพื่อยืนยันการเรียนรู้', '', 'SENA|sena', 'คำตอบแบบสั้นรองรับได้หลายคำตอบ', '3'],
    ];

    $sheetRows = [];
    $allRows = array_merge([$headers], $rows);
    foreach ($allRows as $rowIndex => $row) {
        $cells = [];
        foreach ($row as $colIndex => $value) {
            $ref = xlsx_col($colIndex + 1) . ($rowIndex + 1);
            $cells[] = '<c r="' . $ref . '" t="inlineStr"><is><t>' . xlsx_xml((string) $value) . '</t></is></c>';
        }
        $sheetRows[] = '<row r="' . ($rowIndex + 1) . '">' . implode('', $cells) . '</row>';
    }

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('ไม่สามารถสร้างไฟล์เทมเพลต Excel ได้');
    }

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="questions" sheetId="1" r:id="rId1"/></sheets>
</workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Noto Sans Thai"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border/></borders><cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellXfs></styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<cols><col min="1" max="1" width="20" customWidth="1"/><col min="2" max="2" width="52" customWidth="1"/><col min="3" max="4" width="48" customWidth="1"/><col min="5" max="5" width="42" customWidth="1"/><col min="6" max="6" width="12" customWidth="1"/></cols>
<sheetData>' . implode('', $sheetRows) . '</sheetData>
</worksheet>');
    $zip->close();
}

function xlsx_shared_strings(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) {
        return [];
    }

    $doc = simplexml_load_string($xml);
    if (!$doc) {
        return [];
    }

    $strings = [];
    foreach ($doc->si as $si) {
        if (isset($si->t)) {
            $strings[] = (string) $si->t;
            continue;
        }
        $value = '';
        foreach ($si->r as $run) {
            $value .= (string) $run->t;
        }
        $strings[] = $value;
    }

    return $strings;
}

function read_xlsx_rows(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('ไม่สามารถเปิดไฟล์ Excel ได้');
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        $zip->close();
        throw new RuntimeException('ไม่พบ sheet แรกในไฟล์ Excel');
    }

    $shared = xlsx_shared_strings($zip);
    $zip->close();

    $doc = simplexml_load_string($sheetXml);
    if (!$doc) {
        throw new RuntimeException('อ่านข้อมูลใน Excel ไม่สำเร็จ');
    }

    $rows = [];
    foreach ($doc->sheetData->row as $row) {
        $values = [];
        foreach ($row->c as $cell) {
            $ref = (string) $cell['r'];
            preg_match('/([A-Z]+)/', $ref, $matches);
            $colLetters = $matches[1] ?? 'A';
            $colIndex = 0;
            foreach (str_split($colLetters) as $char) {
                $colIndex = ($colIndex * 26) + (ord($char) - 64);
            }
            $type = (string) $cell['t'];
            if ($type === 's') {
                $value = $shared[(int) $cell->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) $cell->is->t;
            } else {
                $value = isset($cell->v) ? (string) $cell->v : '';
            }
            $values[$colIndex - 1] = trim($value);
        }
        if ($values) {
            ksort($values);
            $rows[] = $values;
        }
    }

    return $rows;
}
