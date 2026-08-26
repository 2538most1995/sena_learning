<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/certificate_renderer.php';
require_admin();

$courses = db()->query('SELECT id, title FROM courses ORDER BY title')->fetchAll();
$shareId = get_int('share_id');
$isPublicQuizDesigner = $shareId > 0;
$quizShare = null;
if ($isPublicQuizDesigner) {
    ensure_public_quiz_sharing_tables();
    $quizShare = public_quiz_share_by_id($shareId);
    if (!$quizShare) {
        flash('ไม่พบแบบทดสอบสาธารณะที่ต้องการออกแบบเกียรติบัตร', 'error');
        redirect('index.php');
    }
}
$courseId = $isPublicQuizDesigner
    ? (int) $quizShare['course_id']
    : get_int('course_id', (int) ($courses[0]['id'] ?? 0));

$stmt = db()->prepare('SELECT * FROM courses WHERE id = ?');
$stmt->execute([$courseId]);
$course = $stmt->fetch();
if (!$course && $courses && !$isPublicQuizDesigner) {
    redirect('certificate_settings.php?course_id=' . (int) $courses[0]['id']);
}

$designerQuery = $isPublicQuizDesigner ? 'share_id=' . $shareId : 'course_id=' . $courseId;
$certificateSubjectTitle = $isPublicQuizDesigner
    ? (string) $quizShare['public_title']
    : (string) ($course['title'] ?? 'เกียรติบัตร');
$backUrl = $isPublicQuizDesigner
    ? 'questions.php?course_id=' . (int) $quizShare['course_id'] . '&set_id=' . (int) $quizShare['quiz_set_id']
    : 'index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $course) {
    try {
        require_valid_csrf_token();
        if ((string) ($_POST['certificate_action'] ?? '') === 'copy_layout') {
            if ($isPublicQuizDesigner) {
                copy_course_certificate_to_public_quiz((int) ($_POST['layout_source_course_id'] ?? 0), $shareId);
                flash('คัดลอกรูปแบบ ภาพ และตำแหน่งจากหลักสูตรแล้ว โดยคงข้อความรับรองของแบบทดสอบนี้ไว้');
            } else {
                copy_certificate_layout_positions((int) ($_POST['layout_source_course_id'] ?? 0), (int) $course['id']);
                flash('นำตำแหน่งจากหลักสูตรต้นแบบมาใช้เรียบร้อยแล้ว สามารถปรับเพิ่มเติมได้ทันที');
            }
        } else {
            if ($isPublicQuizDesigner) {
                save_public_quiz_certificate_settings($shareId, $_POST);
                flash('บันทึกแม่แบบเกียรติบัตรเฉพาะแบบทดสอบเรียบร้อยแล้ว');
            } else {
                save_certificate_settings((int) $course['id'], $_POST);
                flash('บันทึกการตั้งค่าเกียรติบัตรเรียบร้อยแล้ว');
            }
        }
        redirect('certificate_settings.php?' . $designerQuery);
    } catch (Throwable $exception) {
        flash($exception->getMessage(), 'error');
        redirect('certificate_settings.php?' . $designerQuery);
    }
}

$settings = $course
    ? ($isPublicQuizDesigner ? get_public_quiz_certificate_settings($shareId) : get_certificate_settings((int) $course['id']))
    : [];
$layoutCourses = [];
if ($course) {
    $sql =
        'SELECT c.id, c.title
         FROM courses c
         INNER JOIN certificate_settings cs ON cs.course_id = c.id
         WHERE cs.positions IS NOT NULL';
    $params = [];
    if (!$isPublicQuizDesigner) {
        $sql .= ' AND c.id <> ?';
        $params[] = (int) $course['id'];
    }
    $stmt = db()->prepare($sql . ' ORDER BY c.title');
    $stmt->execute($params);
    $layoutCourses = $stmt->fetchAll();
}

// ค้นหาไฟล์รูปภาพทั้งหมดที่เคยอัปโหลดไว้แล้วในโฟลเดอร์สำหรับนำมาใช้ซ้ำ
$existingImages = [];
$uploadDir = __DIR__ . '/../storage/uploads/certificates';
if (is_dir($uploadDir)) {
    $files = scandir($uploadDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && is_file($uploadDir . '/' . $file)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp'])) {
                $existingImages[] = 'storage/uploads/certificates/' . $file;
            }
        }
    }
}

$positions = $settings['positions'] ?? default_certificate_positions();
if (!isset($positions['background'])) {
    $positions['background'] = ['x' => 50, 'y' => 50, 'w' => 1024, 'h' => 724];
}
$sampleAttempt = [
    'learner_name' => 'นายสมชาย เรียนรู้ดี',
    'course_title' => $certificateSubjectTitle,
    'certificate_code' => ($isPublicQuizDesigner ? 'SENA-Q-' : 'SENA-') . date('Ymd') . '-DEMO',
];

render_header(($isPublicQuizDesigner ? 'ออกแบบเกียรติบัตรแบบทดสอบ' : 'ระบบออกแบบเกียรติบัตรอัจฉริยะ'), 'admin');
?>
<!-- นำเข้า Google Fonts ภาษาไทย และ CDN Libraries สำหรับ Export -->
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&family=Charm:wght@400;700&family=Mitr:wght@300;400;500;600&family=Chakra+Petch:wght@300;400;500;600;700&family=Sriracha&display=swap" rel="stylesheet">
<script src="<?= e(app_base_url()) ?>/assets/vendor/html2canvas-1.4.1.min.js"></script>
<script src="<?= e(app_base_url()) ?>/assets/vendor/jspdf-2.5.1.umd.min.js"></script>

<style>
    /* สไตล์คีย์หลักของ Workspace ออกแบบ */
    .designer-workspace {
        background: #0f172a; /* ธีมสีเข้มสไตล์ Canva/Figma */
        min-height: calc(100vh - 120px);
    }
    .canvas-container {
        position: relative;
        background: #1e293b;
        box-shadow: inset 0 0 40px rgba(0, 0, 0, 0.4);
        overflow: auto;
    }
    .a4-canvas {
        background: #ffffff;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6);
        transition: transform 0.2s ease;
        position: relative;
        overflow: hidden;
        width: 1024px !important;
        height: 724px !important;
    }
    /* องค์ประกอบการลาก วาง ขยาย หมุน */
    .interactive-element {
        position: absolute;
        cursor: move;
        user-select: none;
        transform-origin: center center;
        touch-action: none;
        white-space: normal;
        word-break: break-word;
        box-sizing: border-box;
    }
    .interactive-element.selected-active {
        outline: 2px solid #0f766e;
        outline-offset: 4px;
    }
    .interactive-element span,
    .interactive-element .editable-content {
        display: inline-block;
        text-align: inherit;
        box-sizing: border-box;
        white-space: pre-wrap;
    }
    .interactive-element .editable-content {
        pointer-events: none;
    }
    .interactive-element.is-editing-text .editable-content {
        cursor: text;
        outline: 1px dashed #0f766e;
        outline-offset: 3px;
        pointer-events: auto;
        user-select: text;
    }
    .interactive-element.cert-body .editable-content {
        width: 100%;
    }
    /* มุมขยายขนาด (Resize Handles) */
    .resize-handle {
        position: absolute;
        width: 10px;
        height: 10px;
        background: #ffffff;
        border: 2px solid #0f766e;
        border-radius: 50%;
        display: none;
        z-index: 10;
    }
    .interactive-element.selected-active .resize-handle {
        display: block;
    }
    .handle-tl { top: -5px; left: -5px; cursor: nwse-resize; }
    .handle-tr { top: -5px; right: -5px; cursor: nesw-resize; }
    .handle-bl { bottom: -5px; left: -5px; cursor: nesw-resize; }
    .handle-br { bottom: -5px; right: -5px; cursor: nwse-resize; }
    .axis-resize-handle {
        background: #f97316;
        border-color: #ffffff;
        border-radius: 999px;
        pointer-events: auto;
        z-index: 30;
    }
    .interactive-element.cert-background > img {
        pointer-events: none;
    }
    .handle-top,
    .handle-bottom {
        height: 10px;
        width: 34px;
    }
    .handle-left,
    .handle-right {
        height: 34px;
        width: 10px;
    }
    .handle-top {
        cursor: ns-resize;
        left: 50%;
        top: -5px;
        transform: translateX(-50%);
    }
    .handle-bottom {
        bottom: -5px;
        cursor: ns-resize;
        left: 50%;
        transform: translateX(-50%);
    }
    .handle-left {
        cursor: ew-resize;
        left: -5px;
        top: 50%;
        transform: translateY(-50%);
    }
    .handle-right {
        cursor: ew-resize;
        right: -5px;
        top: 50%;
        transform: translateY(-50%);
    }

    /* ปุ่มหมุน (Rotation Handle) */
    .rotate-handle {
        position: absolute;
        width: 12px;
        height: 12px;
        background: #ea580c;
        border: 2px solid #ffffff;
        border-radius: 50%;
        top: -25px;
        left: 50%;
        transform: translateX(-50%);
        cursor: grab;
        display: none;
        z-index: 10;
    }
    .rotate-handle::after {
        content: '';
        position: absolute;
        width: 2px;
        height: 14px;
        background: #ea580c;
        left: 50%;
        top: 10px;
        transform: translateX(-50%);
    }
    .interactive-element.selected-active .rotate-handle {
        display: block;
    }
    /* ปุ่มลบองค์ประกอบเล็กติดกับตัวเลือก */
    .delete-badge {
        position: absolute;
        top: -30px;
        right: -10px;
        background: #ef4444;
        color: white;
        border-radius: 50%;
        width: 20px;
        height: 20px;
        display: none;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        cursor: pointer;
        font-weight: bold;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
    }
    .interactive-element.selected-active .delete-badge {
        display: flex;
    }
    /* คลังเครื่องมือด้านข้าง */
    .sidebar-tab {
        transition: all 0.2s;
    }

</style>

<div class="designer-workspace text-slate-100 flex flex-col xl:flex-row">
    <!-- แถบด้านซ้าย: แผงควบคุมและฟอร์มเครื่องมือ -->
    <div class="w-full xl:w-[440px] bg-slate-800 border-r border-slate-700 flex flex-col max-h-none xl:max-h-[calc(100vh-64px)] overflow-y-auto">
        <div class="p-6 border-b border-slate-700">
            <a href="<?= e($backUrl) ?>" class="text-xs font-bold text-teal-400 hover:underline">← <?= $isPublicQuizDesigner ? 'กลับไปตั้งค่าการแชร์' : 'กลับหลังบ้าน' ?></a>
            <h1 class="text-2xl font-black mt-2 text-white flex items-center gap-2">
                🎨 <?= $isPublicQuizDesigner ? 'เกียรติบัตรเฉพาะแบบทดสอบ' : 'ออกแบบเกียรติบัตร A4' ?>
            </h1>
            <?php if ($isPublicQuizDesigner): ?>
                <p class="mt-2 rounded-lg border border-amber-400/20 bg-amber-400/10 px-3 py-2 text-sm font-bold text-amber-200"><?= e($certificateSubjectTitle) ?></p>
            <?php endif; ?>
            <p class="text-xs text-slate-400 mt-1">ลาก วาง ย่อขยาย หมุน และส่งออกเป็น PNG/PDF ขนาด A4 ได้ทันที</p>
            <p class="text-xs text-teal-300 mt-2">คลิกหรือลากข้อความเพื่อจัดตำแหน่ง ดับเบิลคลิกข้อความเมื่อต้องการแก้ไขในเกียรติบัตร</p>
        </div>

        <form id="settings-form" method="post" enctype="multipart/form-data" class="p-6 space-y-6">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <!-- เก็บพิกัด JSON ขนาด และการหมุนทั้งหมด -->
            <input type="hidden" id="positions" name="positions" value="<?= e(json_encode($positions, JSON_UNESCAPED_UNICODE)) ?>">

            <!-- ส่วนหัวข้อหลักสูตร -->
            <?php if (!$isPublicQuizDesigner): ?>
            <div>
                <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider" for="course_id">หลักสูตรที่กำลังตั้งค่า</label>
                <select id="course_id" name="course_id" onchange="window.location.href='?course_id=' + this.value" class="mt-2 w-full rounded-lg border border-slate-600 bg-slate-700 text-white px-3 py-2 text-sm focus:border-teal-400 focus:outline-none">
                    <?php foreach ($courses as $item): ?>
                        <option value="<?= (int) $item['id'] ?>" <?= (int) $item['id'] === $courseId ? 'selected' : '' ?>><?= e($item['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <div class="rounded-lg border border-slate-700 bg-slate-900/50 p-4">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">ประเภทแม่แบบ</p>
                <strong class="mt-1 block text-sm text-white">แบบทดสอบสาธารณะ · แยกจากหลักสูตร</strong>
                <p class="mt-1 text-xs leading-5 text-slate-400">การแก้ไขหน้านี้ไม่เปลี่ยนแม่แบบเกียรติบัตรของหลักสูตร <?= e((string) $course['title']) ?></p>
            </div>
            <?php endif; ?>

            <!-- ใช้ layout ที่จัดไว้แล้วจากหลักสูตรอื่น -->
            <div class="rounded-lg border border-teal-500/30 bg-teal-950/30 p-4">
                <h3 class="text-sm font-bold text-teal-300"><?= $isPublicQuizDesigner ? 'เริ่มจากแม่แบบหลักสูตร' : 'ใช้ตำแหน่งจากหลักสูตรอื่น' ?></h3>
                <p class="mt-1 text-xs leading-5 text-slate-400"><?= $isPublicQuizDesigner ? 'คัดลอกรูปภาพ ผู้ลงนาม รูปแบบ และตำแหน่งมาเป็นจุดเริ่มต้น โดยยังคงหัวข้อและข้อความรับรองเฉพาะแบบทดสอบนี้ไว้' : 'คัดลอกตำแหน่ง ขนาด การหมุน ฟอนต์ และสี โดยยังคงรูปภาพและข้อความหลักของหลักสูตรนี้ไว้' ?></p>
                <?php if ($layoutCourses): ?>
                    <label class="mt-3 block text-xs font-semibold text-slate-400" for="layout_source_course_id">เลือกหลักสูตรต้นแบบ</label>
                    <select id="layout_source_course_id" name="layout_source_course_id" class="mt-1.5 w-full rounded-lg border border-slate-600 bg-slate-700 px-3 py-2 text-xs text-white focus:border-teal-400 focus:outline-none">
                        <option value="">-- เลือกแบบตำแหน่งที่ต้องการ --</option>
                        <?php foreach ($layoutCourses as $layoutCourse): ?>
                            <option value="<?= (int) $layoutCourse['id'] ?>"><?= e($layoutCourse['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button
                        type="submit"
                        name="certificate_action"
                        value="copy_layout"
                        onclick="if (!document.getElementById('layout_source_course_id').value) { alert('กรุณาเลือกหลักสูตรต้นแบบก่อน'); return false; } return confirm('นำตำแหน่งจากหลักสูตรต้นแบบมาใช้แทนตำแหน่งปัจจุบันหรือไม่?')"
                        class="mt-3 w-full rounded-lg border border-teal-500/40 bg-teal-600/20 px-3 py-2 text-xs font-bold text-teal-200 transition-colors hover:bg-teal-600/30">
                        <?= $isPublicQuizDesigner ? 'คัดลอกแม่แบบหลักสูตรที่เลือก' : 'ใช้ตำแหน่งจากหลักสูตรที่เลือก' ?>
                    </button>
                <?php else: ?>
                    <p class="mt-3 rounded-md bg-slate-900/50 px-3 py-2 text-xs text-slate-500">ยังไม่มีหลักสูตรอื่นที่บันทึกตำแหน่งไว้</p>
                <?php endif; ?>
            </div>

            <!-- เครื่องมือปรับแต่งองค์ประกอบที่เลือก (Dynamic Inspector) -->
            <div id="inspector-panel" class="rounded-lg border border-slate-700 bg-slate-900/60 p-4 space-y-4">
                <h3 class="text-sm font-bold text-teal-400 flex items-center gap-2">
                    ⚙️ ตัวช่วยปรับแต่งองค์ประกอบ
                </h3>
                <div id="inspector-empty" class="text-xs text-slate-500 py-3 text-center">
                    คลิกเลือกองค์ประกอบในเกียรติบัตร เพื่อเริ่มต้นปรับตำแหน่ง ขนาด ฟอนต์ หรือสี
                </div>
                <div id="inspector-controls" class="hidden space-y-3">
                    <!-- แก้ไขข้อความในช่องกรอก -->
                    <div id="control-text-group">
                        <label class="block text-xs font-semibold text-slate-400">เนื้อหาข้อความ</label>
                        <textarea id="elem-text" rows="2" class="mt-1 w-full rounded-lg border border-slate-600 bg-slate-700 text-white px-3 py-2 text-xs focus:outline-none focus:border-teal-400"></textarea>
                    </div>

                    <!-- ขนาดอักษร / ขนาดกว้าง-ยาวรูปภาพ -->
                    <div id="control-size-group">
                        <label id="size-label" class="block text-xs font-semibold text-slate-400">ขนาด</label>
                        <div class="flex items-center gap-3">
                            <input type="range" id="elem-size" min="10" max="1500" class="w-full accent-teal-500">
                            <span id="elem-size-val" class="text-xs font-mono font-bold bg-slate-800 px-2 py-1 rounded">16px</span>
                        </div>
                    </div>

                    <!-- ความสูงพื้นหลังแบบอิสระ ไม่เปลี่ยนความกว้าง -->
                    <div id="control-background-height-group" class="hidden">
                        <label class="block text-xs font-semibold text-slate-400">ความสูงรูปพื้นหลัง</label>
                        <div class="flex items-center gap-3">
                            <input type="range" id="elem-background-height" min="10" max="1500" class="w-full accent-teal-500">
                            <span id="elem-background-height-val" class="text-xs font-mono font-bold bg-slate-800 px-2 py-1 rounded">724px</span>
                        </div>
                        <p class="mt-1 text-[11px] text-slate-500">ปรับความกว้างและความสูงแยกกันได้ ภาพจะย่อหรือยืดจริงตามค่าที่กำหนด</p>
                    </div>

                    <!-- การหมุนแบบละเอียด -->
                    <div>
                        <label class="block text-xs font-semibold text-slate-400">องศาการหมุน</label>
                        <div class="flex items-center gap-3">
                            <input type="range" id="elem-rotate" min="0" max="360" class="w-full accent-teal-500">
                            <span id="elem-rotate-val" class="text-xs font-mono font-bold bg-slate-800 px-2 py-1 rounded">0°</span>
                        </div>
                    </div>

                    <!-- สีตัวอักษร -->
                    <div id="control-color-group">
                        <label class="block text-xs font-semibold text-slate-400">สีข้อความ</label>
                        <div class="flex items-center gap-3 mt-1">
                            <input type="color" id="elem-color" class="h-8 w-12 rounded border border-slate-600 bg-transparent cursor-pointer">
                            <input type="text" id="elem-color-hex" class="w-24 rounded border border-slate-600 bg-slate-700 text-white px-2 py-1 text-xs text-center font-mono">
                        </div>
                    </div>

                    <!-- ฟอนต์ที่เลือก -->
                    <div id="control-font-group">
                        <label class="block text-xs font-semibold text-slate-400">ตระกูลฟอนต์ (Font Family)</label>
                        <select id="elem-font" class="mt-1 w-full rounded-lg border border-slate-600 bg-slate-700 text-white px-3 py-2 text-xs focus:outline-none">
                            <option value="Sarabun">Sarabun (ทางการ)</option>
                            <option value="Charm">Charm (เขียนมือ/คลาสสิก)</option>
                            <option value="Mitr">Mitr (ร่วมสมัย/โมเดิร์น)</option>
                            <option value="Chakra Petch">Chakra Petch (กึ่งเทคโนโลยี)</option>
                            <option value="Sriracha">Sriracha (ลายมือน่ารัก)</option>
                        </select>
                    </div>

                    <!-- ความหนาอักษร -->
                    <div id="control-weight-group">
                        <label class="block text-xs font-semibold text-slate-400">ความหนาตัวอักษร</label>
                        <select id="elem-weight" class="mt-1 w-full rounded-lg border border-slate-600 bg-slate-700 text-white px-3 py-2 text-xs focus:outline-none">
                            <option value="normal">ปกติ (400)</option>
                            <option value="500">ปานกลาง (500)</option>
                            <option value="bold">หนา (700)</option>
                            <option value="800">หนามาก (800)</option>
                        </select>
                    </div>

                    <!-- จัดตำแหน่งข้อความ -->
                    <div id="control-align-group">
                        <label class="block text-xs font-semibold text-slate-400">จัดตำแหน่งข้อความ</label>
                        <div class="flex gap-1 mt-1">
                            <button type="button" data-align="left" class="align-btn flex-1 rounded-lg border border-slate-600 bg-slate-700 text-white px-3 py-2 text-xs font-bold hover:bg-slate-600 transition-colors" onclick="setTextAlign('left')">
                                ◀ ซ้าย
                            </button>
                            <button type="button" data-align="center" class="align-btn flex-1 rounded-lg border border-slate-600 bg-slate-700 text-white px-3 py-2 text-xs font-bold hover:bg-slate-600 transition-colors" onclick="setTextAlign('center')">
                                ◆ กลาง
                            </button>
                            <button type="button" data-align="right" class="align-btn flex-1 rounded-lg border border-slate-600 bg-slate-700 text-white px-3 py-2 text-xs font-bold hover:bg-slate-600 transition-colors" onclick="setTextAlign('right')">
                                ▶ ขวา
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- อัปโหลดภาพ (พื้นหลัง, โลโก้, ลายเซ็น) -->
            <div class="rounded-lg border border-slate-700 bg-slate-900/40 p-4 space-y-4">
                <h3 class="text-sm font-bold text-white flex items-center gap-2">
                    📁 ไฟล์รูปภาพหลัก
                </h3>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-400" for="background_image">ภาพพื้นหลังเกียรติบัตร (แนะนำสัดส่วน A4 แนวนอน)</label>
                        <input id="background_image" name="background_image" type="file" accept="image/png,image/jpeg,image/webp" class="mt-1 block w-full text-xs text-slate-400 file:mr-3 file:py-1 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-teal-600 file:text-white hover:file:bg-teal-700 cursor-pointer" onchange="previewUploadedImage(this,'background')">
                        <?php if (!empty($existingImages)): ?>
                            <select name="existing_background_image" id="existing_background_image" onchange="selectExistingImage(this, 'background')" class="mt-1.5 w-full rounded-lg border border-slate-600 bg-slate-700 text-white px-2.5 py-1.5 text-xs focus:border-teal-400 focus:outline-none">
                                <option value="">-- ไม่ใช้ภาพพื้นหลัง (พื้นสีขาว) --</option>
                                <?php foreach ($existingImages as $img): ?>
                                    <option value="<?= e($img) ?>" <?= ($settings['background_image'] ?? '') === $img ? 'selected' : '' ?>><?= e(basename($img)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400" for="logo_image">ภาพตราสัญลักษณ์ (ตราโลโก้สถาบัน)</label>
                        <input id="logo_image" name="logo_image" type="file" accept="image/png,image/jpeg,image/webp" class="mt-1 block w-full text-xs text-slate-400 file:mr-3 file:py-1 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-teal-600 file:text-white hover:file:bg-teal-700 cursor-pointer" onchange="previewUploadedImage(this,'logo')">
                        <?php if (!empty($existingImages)): ?>
                            <select name="existing_logo_image" id="existing_logo_image" onchange="selectExistingImage(this, 'logo')" class="mt-1.5 w-full rounded-lg border border-slate-600 bg-slate-700 text-white px-2.5 py-1.5 text-xs focus:border-teal-400 focus:outline-none">
                                <option value="">-- หรือเลือกภาพตราสัญลักษณ์เดิม --</option>
                                <?php foreach ($existingImages as $img): ?>
                                    <option value="<?= e($img) ?>" <?= ($settings['logo_image'] ?? '') === $img ? 'selected' : '' ?>><?= e(basename($img)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400" for="signature_image">ภาพลายเซ็นผู้ลงนาม</label>
                        <input id="signature_image" name="signature_image" type="file" accept="image/png,image/jpeg,image/webp" class="mt-1 block w-full text-xs text-slate-400 file:mr-3 file:py-1 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-teal-600 file:text-white hover:file:bg-teal-700 cursor-pointer" onchange="previewUploadedImage(this,'signature')">
                        <?php if (!empty($existingImages)): ?>
                            <select name="existing_signature_image" id="existing_signature_image" onchange="selectExistingImage(this, 'signature')" class="mt-1.5 w-full rounded-lg border border-slate-600 bg-slate-700 text-white px-2.5 py-1.5 text-xs focus:border-teal-400 focus:outline-none">
                                <option value="">-- หรือเลือกลายเซ็นผู้ลงนามเดิม --</option>
                                <?php foreach ($existingImages as $img): ?>
                                    <option value="<?= e($img) ?>" <?= ($settings['signature_image'] ?? '') === $img ? 'selected' : '' ?>><?= e(basename($img)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ข้อความรับรองและฟิลด์ซิงค์ฐานข้อมูลเริ่มต้น -->
            <div class="rounded-lg border border-slate-700 bg-slate-900/40 p-4 space-y-4">
                <h3 class="text-sm font-bold text-white flex items-center gap-2">
                    📝 ข้อความพื้นฐาน (เพื่อจัดเก็บในระบบ)
                </h3>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-400" for="title_text">หัวข้อเกียรติบัตร</label>
                        <textarea id="title_text" name="title_text" rows="2" class="mt-1 w-full rounded-lg border border-slate-600 bg-slate-700 text-white px-3 py-2 text-xs focus:outline-none focus:border-teal-400" oninput="syncCanvasText('title','title_text',this.value)"><?= e($settings['title_text'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400" for="body_text">ข้อความคำรับรอง</label>
                        <textarea id="body_text" name="body_text" rows="3" class="mt-1 w-full rounded-lg border border-slate-600 bg-slate-700 text-white px-3 py-2 text-xs focus:outline-none focus:border-teal-400" oninput="syncCanvasText('body','body_text',this.value)"><?= e($settings['body_text'] ?? '') ?></textarea>
                        <p class="text-[10px] text-slate-400 mt-1">ใช้ตัวแปรได้: {{name}} (ผู้รับ), {{course}} (<?= $isPublicQuizDesigner ? 'ชื่อแบบทดสอบ' : 'วิชา' ?>), {{code}} (รหัสเกียรติบัตร), {{date}} (วันที่)</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400" for="signature_name">ชื่อผู้ลงนาม</label>
                        <textarea id="signature_name" name="signature_name" rows="2" class="mt-1 w-full rounded-lg border border-slate-600 bg-slate-700 text-white px-3 py-2 text-xs focus:outline-none focus:border-teal-400" oninput="syncCanvasText('signature','signature_name',this.value)"><?= e($settings['signature_name'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400" for="issuer_name">หน่วยงานที่ออกให้</label>
                        <textarea id="issuer_name" name="issuer_name" rows="2" class="mt-1 w-full rounded-lg border border-slate-600 bg-slate-700 text-white px-3 py-2 text-xs focus:outline-none focus:border-teal-400" oninput="syncCanvasText('issuer','issuer_name',this.value)"><?= e($settings['issuer_name'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- ปุ่มบันทึกข้อมูลหลักสูตร -->
            <button type="submit" class="w-full rounded-lg bg-teal-600 hover:bg-teal-700 px-4 py-3 text-sm font-bold text-white shadow-lg flex items-center justify-center gap-2 transition-colors">
                💾 <?= $isPublicQuizDesigner ? 'บันทึกแม่แบบเฉพาะแบบทดสอบ' : 'บันทึกแบบเกียรติบัตรลงระบบ' ?>
            </button>
        </form>
    </div>

    <!-- ส่วนขวา: พื้นที่ Canvas ออกแบบในกระดาษ A4 Landscape -->
    <div class="flex-1 p-6 flex flex-col items-center justify-center min-w-0">
        <!-- แถบปุ่มควมคุมรวดเร็ว (Toolbar) -->
        <div class="w-full max-w-5xl mb-4 flex flex-wrap gap-2 items-center justify-between">
            <div class="flex flex-wrap gap-2">
                <button type="button" onclick="addCustomTextBox()" class="rounded-lg bg-teal-600/20 text-teal-300 border border-teal-500/30 px-3 py-2 text-xs font-bold hover:bg-teal-600/30 flex items-center gap-1.5 transition-colors">
                    ➕ เพิ่มข้อความ
                </button>
                <button type="button" onclick="triggerCustomImageUpload()" class="rounded-lg bg-teal-600/20 text-teal-300 border border-teal-500/30 px-3 py-2 text-xs font-bold hover:bg-teal-600/30 flex items-center gap-1.5 transition-colors">
                    🖼️ เพิ่มรูปภาพอิสระ
                </button>
                <input type="file" id="custom-image-input" accept="image/*" class="hidden" onchange="handleCustomImageUpload(event)">
                <button type="button" id="btn-delete-elem" onclick="deleteSelectedElement()" class="rounded-lg bg-red-950/40 text-red-400 border border-red-500/20 px-3 py-2 text-xs font-bold hover:bg-red-900/30 flex items-center gap-1.5 transition-colors disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                    🗑️ ลบองค์ประกอบ
                </button>
            </div>
            
            <div class="flex gap-2">
                <button type="button" onclick="exportAsPNG()" class="rounded-lg bg-slate-700 hover:bg-slate-600 border border-slate-600 px-3 py-2 text-xs font-bold text-white flex items-center gap-1.5 transition-colors">
                    🖼️ ดาวน์โหลด PNG
                </button>
                <button type="button" onclick="exportAsPDF()" class="rounded-lg bg-gradient-to-r from-teal-600 to-emerald-600 hover:from-teal-700 hover:to-emerald-700 px-4 py-2 text-xs font-bold text-white flex items-center gap-1.5 transition-colors shadow-md">
                    📄 ส่งออก PDF (A4)
                </button>
            </div>
        </div>

        <!-- หน้าต่าง Canvas สำหรับลากวาง ออกแบบด้วยอัตราส่วนทองคำ A4 แนวนอน (1.414:1) -->
        <div class="canvas-container p-6 rounded-2xl w-full max-w-5xl border border-slate-700">
            <?php render_certificate_canvas($settings, $sampleAttempt, [
                'id' => 'certificate-preview',
                'interactive' => true,
                'show_placeholders' => true,
                'canvas_classes' => 'a4-canvas shrink-0 select-none rounded-lg',
            ]); ?>
        </div>
    </div>
</div>

<script>
// โมดูลหลักสำหรับจัดตำแหน่ง ลากขยาย หมุน และส่งออกเกียรติบัตร
(() => {
    const preview = document.getElementById('certificate-preview');
    const positionsInput = document.getElementById('positions');
    if (!preview || !positionsInput) return;

    // โหลดตำแหน่งและพารามิเตอร์เริ่มต้นจากฐานข้อมูล
    let positions = JSON.parse(positionsInput.value || '{}');

    // เก็บรายการสถานะปัจจุบัน
    let selectedElement = null;
    let isInteracting = false;
    let interactionType = null; // 'drag', 'resize', 'rotate'
    let activeHandle = null;

    // ข้อมูลพารามิเตอร์การเริ่มต้นลากขยาย
    let startPointerX = 0;
    let startPointerY = 0;
    let startElemX = 0;
    let startElemY = 0;
    let startElemW = 0;
    let startElemH = 0;
    let startFontSize = 16;
    let startRotation = 0;
    let startDistance = 0;
    let startImageAspectRatio = 1;
    const MAX_IMAGE_WIDTH = 1500;
    const MAX_IMAGE_HEIGHT = 1500;

    // ฟังก์ชันอัปเดตสัญกรณ์พิกัด JSON ใน hidden input เพื่อเตรียมส่งบันทึกฐานข้อมูล
    const saveAllPositions = () => {
        positionsInput.value = JSON.stringify(positions);
    };

    // ค้นหาหรือระบุข้อมูลพิกัดในหน่วยร้อยละ (Percentage) ของกระดาษ A4
    const setElementPositionState = (id, x, y, w = null, h = null, fontSize = null, rotate = null, extraProps = {}) => {
        if (!positions[id]) positions[id] = { x: 50, y: 50 };
        positions[id].x = Math.round(x * 100) / 100;
        positions[id].y = Math.round(y * 100) / 100;
        if (w !== null) positions[id].w = Math.round(w);
        if (h !== null) positions[id].h = Math.round(h);
        if (fontSize !== null) positions[id].fontSize = Math.round(fontSize);
        if (rotate !== null) positions[id].rotate = Math.round(rotate);
        
        // บันทึกฟิลด์เสริมสำหรับส่วนข้อความอิสระ (Custom Elements)
        Object.assign(positions[id], extraProps);
        saveAllPositions();
    };

    const getImageAspectRatio = (elem) => {
        const img = elem.querySelector('img');
        if (img && img.naturalWidth && img.naturalHeight) {
            return img.naturalWidth / img.naturalHeight;
        }

        const width = elem.offsetWidth;
        const height = elem.offsetHeight;
        return width > 0 && height > 0 ? width / height : 1;
    };

    const resizeImageElement = (elem, width, aspectRatio = null) => {
        const safeWidth = Math.max(10, Math.min(MAX_IMAGE_WIDTH, width));
        const ratio = aspectRatio || getImageAspectRatio(elem);
        const safeHeight = Math.max(10, Math.round(safeWidth / (ratio || 1)));
        elem.style.width = `${safeWidth}px`;
        elem.style.height = `${safeHeight}px`;
        return { width: safeWidth, height: safeHeight };
    };

    const resizeBackgroundElement = (elem, width, height) => {
        const safeWidth = Math.max(10, Math.min(MAX_IMAGE_WIDTH, width));
        const safeHeight = Math.max(10, Math.min(MAX_IMAGE_HEIGHT, height));
        elem.style.width = `${safeWidth}px`;
        elem.style.height = `${safeHeight}px`;
        return { width: safeWidth, height: safeHeight };
    };

    // เลือกองค์ประกอบเพื่อแสดงแฮนเดิลและแผงควบคุม Inspector ปรับเปลี่ยน
    const selectElement = (elem) => {
        if (selectedElement) {
            selectedElement.classList.remove('selected-active');
            selectedElement.classList.remove('is-editing-text');
        }
        selectedElement = elem;
        
        if (elem) {
            elem.classList.add('selected-active');
            document.getElementById('btn-delete-elem').removeAttribute('disabled');
            showInspector(elem);
        } else {
            document.getElementById('btn-delete-elem').setAttribute('disabled', 'true');
            hideInspector();
        }
    };

    // การเปิดใช้แผงควบคุม Inspector ด้านข้างดึงข้อมูลขององค์ประกอบมาแก้ไข
    const showInspector = (elem) => {
        const id = elem.dataset.id;
        const state = positions[id] || { x: 50, y: 50, rotate: 0 };
        
        document.getElementById('inspector-empty').classList.add('hidden');
        const controls = document.getElementById('inspector-controls');
        controls.classList.remove('hidden');

        // ตรวจสอบชนิดประเภทองค์ประกอบ (ข้อความ หรือ รูปภาพ)
        const isText = elem.querySelector('[contenteditable="true"]') || elem.classList.contains('cert-title') || elem.classList.contains('cert-name') || elem.classList.contains('cert-body') || elem.classList.contains('cert-course') || elem.classList.contains('cert-signature') || elem.classList.contains('cert-issuer') || elem.classList.contains('cert-code') || elem.classList.contains('cert-custom-text');
        
        if (isText) {
            document.getElementById('control-background-height-group').classList.add('hidden');
            document.getElementById('control-text-group').classList.remove('hidden');
            document.getElementById('control-color-group').classList.remove('hidden');
            document.getElementById('control-font-group').classList.remove('hidden');
            document.getElementById('control-weight-group').classList.remove('hidden');
            document.getElementById('size-label').textContent = 'ขนาดตัวอักษร';
            
            const editableTextSpan = elem.querySelector('.editable-content') || elem.querySelector('span');
            document.getElementById('elem-text').value = editableTextSpan ? editableTextSpan.innerText.trim() : elem.innerText.trim();
            
            // ขนาดฟอนต์
            const currentFS = parseFloat(window.getComputedStyle(elem).fontSize);
            document.getElementById('elem-size').min = "8";
            document.getElementById('elem-size').max = "100";
            document.getElementById('elem-size').value = state.fontSize || currentFS;
            document.getElementById('elem-size-val').textContent = (state.fontSize || Math.round(currentFS)) + 'px';

            // สีตัวอักษร
            const rgbColor = window.getComputedStyle(elem).color;
            const hexColor = rgbToHex(rgbColor) || '#475569';
            document.getElementById('elem-color').value = state.color || hexColor;
            document.getElementById('elem-color-hex').value = state.color || hexColor;

            // ตระกูลฟอนต์
            const fontFamily = (state.fontFamily || 'Sarabun').replace(/['"]/g, '');
            document.getElementById('elem-font').value = fontFamily;
            
            // ความหนา
            document.getElementById('elem-weight').value = state.fontWeight || 'normal';

            // จัดตำแหน่งข้อความ
            document.getElementById('control-align-group').classList.remove('hidden');
            const currentAlign = state.textAlign || elem.style.textAlign || 'center';
            highlightAlignButton(currentAlign);
        } else {
            // หากเป็นกรณีรูปภาพ เช่น โลโก้, ลายเซ็น หรือรูปภาพอิสระ
            document.getElementById('control-text-group').classList.add('hidden');
            document.getElementById('control-color-group').classList.add('hidden');
            document.getElementById('control-font-group').classList.add('hidden');
            document.getElementById('control-weight-group').classList.add('hidden');
            document.getElementById('control-align-group').classList.add('hidden');
            document.getElementById('size-label').textContent = id === 'background'
                ? 'ความกว้างรูปพื้นหลัง'
                : 'ขนาดรูปภาพ (กว้าง)';
            
            const currentW = elem.offsetWidth;
            document.getElementById('elem-size').min = "10";
            document.getElementById('elem-size').max = String(MAX_IMAGE_WIDTH);
            document.getElementById('elem-size').value = state.w || currentW;
            document.getElementById('elem-size-val').textContent = (state.w || currentW) + 'px';

            const backgroundHeightGroup = document.getElementById('control-background-height-group');
            if (id === 'background') {
                const currentH = elem.offsetHeight;
                backgroundHeightGroup.classList.remove('hidden');
                document.getElementById('elem-background-height').max = String(MAX_IMAGE_HEIGHT);
                document.getElementById('elem-background-height').value = state.h || currentH;
                document.getElementById('elem-background-height-val').textContent = (state.h || currentH) + 'px';
            } else {
                backgroundHeightGroup.classList.add('hidden');
            }
        }

        // องศาการหมุน
        document.getElementById('elem-rotate').value = state.rotate || 0;
        document.getElementById('elem-rotate-val').textContent = (state.rotate || 0) + '°';
    };

    const hideInspector = () => {
        document.getElementById('inspector-empty').classList.remove('hidden');
        document.getElementById('inspector-controls').classList.add('hidden');
    };

    // ฟังก์ชันแปลงค่าสีจาก RGB เป็น Hex
    const rgbToHex = (rgb) => {
        const result = rgb.match(/\d+/g);
        if (!result || result.length < 3) return null;
        return "#" + ((1 << 24) + (parseInt(result[0]) << 16) + (parseInt(result[1]) << 8) + parseInt(result[2])).toString(16).slice(1);
    };

    // เชื่อมต่อ Event Listener ในแผงควบคุม Inspector เพื่อปรับองค์ประกอบแบบเรียลไทม์
    document.getElementById('elem-text').addEventListener('input', (e) => {
        if (!selectedElement) return;
        const text = e.target.value;
        const targetSpan = selectedElement.querySelector('.editable-content') || selectedElement.querySelector('span') || selectedElement;
        targetSpan.textContent = text;
        
        const id = selectedElement.dataset.id;
        if (!positions[id]) positions[id] = { x: 50, y: 50 };
        positions[id].text = text;
        saveAllPositions();

        // ซิงค์ข้อมูลกับฟอร์มหลักสูตรพื้นฐานที่อยู่ทางซ้ายมือเพื่อให้ซิงค์เข้าฟิลด์ DB หลัก
        const syncId = targetSpan.dataset.sync;
        if (syncId) {
            const formInput = document.getElementById(syncId);
            if (formInput) formInput.value = text;
        }
    });

    document.getElementById('elem-size').addEventListener('input', (e) => {
        if (!selectedElement) return;
        const val = parseInt(e.target.value);
        document.getElementById('elem-size-val').textContent = val + 'px';
        
        const id = selectedElement.dataset.id;
        const isText = selectedElement.querySelector('[contenteditable="true"]') || selectedElement.classList.contains('cert-title') || selectedElement.classList.contains('cert-name') || selectedElement.classList.contains('cert-body') || selectedElement.classList.contains('cert-course') || selectedElement.classList.contains('cert-signature') || selectedElement.classList.contains('cert-issuer') || selectedElement.classList.contains('cert-code') || selectedElement.classList.contains('cert-custom-text');
        
        if (isText) {
            selectedElement.style.fontSize = val + 'px';
            if (!positions[id]) positions[id] = { x: 50, y: 50 };
            positions[id].fontSize = val;
        } else if (id === 'background') {
            const state = positions.background || { x: 50, y: 50 };
            const size = resizeBackgroundElement(
                selectedElement,
                val,
                state.h || selectedElement.offsetHeight
            );
            positions.background = {
                ...state,
                w: size.width,
                h: size.height,
            };
        } else {
            const size = resizeImageElement(selectedElement, val);
            if (!positions[id]) positions[id] = { x: 50, y: 50 };
            positions[id].w = size.width;
            positions[id].h = size.height;
        }
        saveAllPositions();
    });

    document.getElementById('elem-background-height').addEventListener('input', (e) => {
        if (!selectedElement || selectedElement.dataset.id !== 'background') return;
        const height = Math.max(10, Math.min(MAX_IMAGE_HEIGHT, parseInt(e.target.value)));
        const state = positions.background || { x: 50, y: 50, w: selectedElement.offsetWidth };

        selectedElement.style.height = `${height}px`;
        positions.background = {
            ...state,
            h: height,
        };
        document.getElementById('elem-background-height-val').textContent = height + 'px';
        saveAllPositions();
    });

    document.getElementById('elem-rotate').addEventListener('input', (e) => {
        if (!selectedElement) return;
        const val = parseInt(e.target.value);
        document.getElementById('elem-rotate-val').textContent = val + '°';
        
        const id = selectedElement.dataset.id;
        if (!positions[id]) positions[id] = { x: 50, y: 50 };
        positions[id].rotate = val;
        
        selectedElement.style.transform = `translate(-50%, -50%) rotate(${val}deg)`;
        saveAllPositions();
    });

    document.getElementById('elem-color').addEventListener('input', (e) => {
        if (!selectedElement) return;
        const color = e.target.value;
        document.getElementById('elem-color-hex').value = color;
        selectedElement.style.color = color;
        
        const id = selectedElement.dataset.id;
        if (!positions[id]) positions[id] = { x: 50, y: 50 };
        positions[id].color = color;
        saveAllPositions();
    });

    document.getElementById('elem-color-hex').addEventListener('input', (e) => {
        if (!selectedElement) return;
        const color = e.target.value;
        if (/^#[0-9A-F]{6}$/i.test(color)) {
            document.getElementById('elem-color').value = color;
            selectedElement.style.color = color;
            
            const id = selectedElement.dataset.id;
            if (!positions[id]) positions[id] = { x: 50, y: 50 };
            positions[id].color = color;
            saveAllPositions();
        }
    });

    document.getElementById('elem-font').addEventListener('change', (e) => {
        if (!selectedElement) return;
        const font = e.target.value;
        selectedElement.style.fontFamily = font;
        
        const id = selectedElement.dataset.id;
        if (!positions[id]) positions[id] = { x: 50, y: 50 };
        positions[id].fontFamily = font;
        saveAllPositions();
    });

    document.getElementById('elem-weight').addEventListener('change', (e) => {
        if (!selectedElement) return;
        const weight = e.target.value;
        selectedElement.style.fontWeight = weight;
        
        const id = selectedElement.dataset.id;
        if (!positions[id]) positions[id] = { x: 50, y: 50 };
        positions[id].fontWeight = weight;
        saveAllPositions();
    });

    // จัดตำแหน่งข้อความ (ซ้าย กลาง ขวา)
    const highlightAlignButton = (align) => {
        document.querySelectorAll('.align-btn').forEach(btn => {
            if (btn.dataset.align === align) {
                btn.classList.remove('bg-slate-700');
                btn.classList.add('bg-teal-600', 'border-teal-500');
            } else {
                btn.classList.remove('bg-teal-600', 'border-teal-500');
                btn.classList.add('bg-slate-700');
            }
        });
    };

    window.setTextAlign = (align) => {
        if (!selectedElement) return;
        selectedElement.style.textAlign = align;
        
        const id = selectedElement.dataset.id;
        if (!positions[id]) positions[id] = { x: 50, y: 50 };
        positions[id].textAlign = align;
        
        saveAllPositions();
        highlightAlignButton(align);
    };

    // เริ่มต้นเปิดการตรวจจับ Event Pointer สำหรับทุกองค์ประกอบ (Interactive Elements)
    const initElementEvents = (elem) => {
        elem.addEventListener('pointerdown', (e) => {
            if (elem.classList.contains('is-editing-text') && e.target.closest('[contenteditable="true"]')) {
                // ข้ามการลากเฉพาะตอนที่ผู้ใช้เปิดโหมดพิมพ์ข้อความด้วยการดับเบิลคลิกแล้ว
                return;
            }
            
            e.preventDefault();
            selectElement(elem);
            isInteracting = true;
            elem.setPointerCapture(e.pointerId);

            const rect = elem.getBoundingClientRect();
            const canvasRect = preview.getBoundingClientRect();
            const id = elem.dataset.id;
            const state = positions[id] || { x: 50, y: 50, rotate: 0 };

            startPointerX = e.clientX;
            startPointerY = e.clientY;
            startElemX = parseFloat(elem.style.left) || 50;
            startElemY = parseFloat(elem.style.top) || 50;
            startElemW = elem.offsetWidth;
            startElemH = elem.offsetHeight;
            startRotation = state.rotate || 0;
            startImageAspectRatio = getImageAspectRatio(elem);

            const handle = e.target.closest('.resize-handle');
            const rotateH = e.target.closest('.rotate-handle');

            if (handle) {
                // การขยายขนาด
                interactionType = 'resize';
                activeHandle = handle.dataset.handle;
                
                const style = window.getComputedStyle(elem);
                startFontSize = parseFloat(style.fontSize) || 16;
                
                // คำนวณระยะห่างแรกจากศูนย์กลางองค์ประกอบสำหรับการขยายแบบ Radial
                const centerX = rect.left + rect.width / 2;
                const centerY = rect.top + rect.height / 2;
                startDistance = Math.hypot(e.clientX - centerX, e.clientY - centerY);
            } else if (rotateH) {
                // การหมุนองศา
                interactionType = 'rotate';
            } else {
                // ลากย้ายพิกัดตำแหน่ง
                interactionType = 'drag';
                elem.style.cursor = 'grabbing';
            }
        });

        elem.addEventListener('pointermove', (e) => {
            if (!isInteracting || selectedElement !== elem) return;
            e.preventDefault();

            const canvasRect = preview.getBoundingClientRect();
            const id = elem.dataset.id;
            const state = positions[id] || { x: 50, y: 50, rotate: 0 };

            if (interactionType === 'drag') {
                const deltaX = ((e.clientX - startPointerX) / canvasRect.width) * 100;
                const deltaY = ((e.clientY - startPointerY) / canvasRect.height) * 100;
                const newX = Math.max(0, Math.min(100, startElemX + deltaX));
                const newY = Math.max(0, Math.min(100, startElemY + deltaY));
                
                elem.style.left = `${newX}%`;
                elem.style.top = `${newY}%`;
                setElementPositionState(id, newX, newY, state.w, state.h, state.fontSize, state.rotate);
            } else if (interactionType === 'resize') {
                if (id === 'background') {
                    const rotationRadians = startRotation * Math.PI / 180;
                    const pointerDeltaX = e.clientX - startPointerX;
                    const pointerDeltaY = e.clientY - startPointerY;
                    const localDeltaX = pointerDeltaX * Math.cos(rotationRadians) + pointerDeltaY * Math.sin(rotationRadians);
                    const localDeltaY = -pointerDeltaX * Math.sin(rotationRadians) + pointerDeltaY * Math.cos(rotationRadians);
                    const resizeLeft = activeHandle === 'left' || activeHandle === 'tl' || activeHandle === 'bl';
                    const resizeRight = activeHandle === 'right' || activeHandle === 'tr' || activeHandle === 'br';
                    const resizeTop = activeHandle === 'top' || activeHandle === 'tl' || activeHandle === 'tr';
                    const resizeBottom = activeHandle === 'bottom' || activeHandle === 'bl' || activeHandle === 'br';
                    const widthDirection = resizeLeft ? -1 : (resizeRight ? 1 : 0);
                    const heightDirection = resizeTop ? -1 : (resizeBottom ? 1 : 0);
                    const size = resizeBackgroundElement(
                        elem,
                        startElemW + (widthDirection * localDeltaX),
                        startElemH + (heightDirection * localDeltaY)
                    );
                    const centerShiftLocalX = widthDirection * (size.width - startElemW) / 2;
                    const centerShiftLocalY = heightDirection * (size.height - startElemH) / 2;
                    const centerShiftX = centerShiftLocalX * Math.cos(rotationRadians) - centerShiftLocalY * Math.sin(rotationRadians);
                    const centerShiftY = centerShiftLocalX * Math.sin(rotationRadians) + centerShiftLocalY * Math.cos(rotationRadians);
                    const newX = Math.max(0, Math.min(100, startElemX + (centerShiftX / canvasRect.width) * 100));
                    const newY = Math.max(0, Math.min(100, startElemY + (centerShiftY / canvasRect.height) * 100));

                    elem.style.left = `${newX}%`;
                    elem.style.top = `${newY}%`;
                    setElementPositionState(id, newX, newY, size.width, size.height, null, state.rotate);
                    document.getElementById('elem-size').value = Math.round(size.width);
                    document.getElementById('elem-size-val').textContent = Math.round(size.width) + 'px';
                    document.getElementById('elem-background-height').value = Math.round(size.height);
                    document.getElementById('elem-background-height-val').textContent = Math.round(size.height) + 'px';
                    return;
                }

                const rect = elem.getBoundingClientRect();
                const centerX = rect.left + rect.width / 2;
                const centerY = rect.top + rect.height / 2;
                const currentDistance = Math.hypot(e.clientX - centerX, e.clientY - centerY);
                
                // คำนวณอัตราส่วนการขยาย
                const ratio = currentDistance / (startDistance || 1);
                const isText = elem.querySelector('[contenteditable="true"]') || elem.classList.contains('cert-title') || elem.classList.contains('cert-name') || elem.classList.contains('cert-body') || elem.classList.contains('cert-course') || elem.classList.contains('cert-signature') || elem.classList.contains('cert-issuer') || elem.classList.contains('cert-code') || elem.classList.contains('cert-custom-text');

                if (isText) {
                    const newFS = Math.max(8, Math.min(150, startFontSize * ratio));
                    elem.style.fontSize = `${newFS}px`;
                    setElementPositionState(id, state.x, state.y, null, null, newFS, state.rotate);
                    document.getElementById('elem-size').value = Math.round(newFS);
                    document.getElementById('elem-size-val').textContent = Math.round(newFS) + 'px';
                } else {
                    const newW = Math.max(10, Math.min(MAX_IMAGE_WIDTH, startElemW * ratio));
                    const size = resizeImageElement(elem, newW, startImageAspectRatio);
                    setElementPositionState(id, state.x, state.y, size.width, size.height, null, state.rotate);
                    document.getElementById('elem-size').value = Math.round(size.width);
                    document.getElementById('elem-size-val').textContent = Math.round(size.width) + 'px';
                }
            } else if (interactionType === 'rotate') {
                const rect = elem.getBoundingClientRect();
                const centerX = rect.left + rect.width / 2;
                const centerY = rect.top + rect.height / 2;
                const angle = Math.atan2(e.clientY - centerY, e.clientX - centerX);
                
                // แปลงเป็นองศา (+90 เป็นค่าชดเชยหัวลูกศรดึงด้านบน)
                let deg = angle * (180 / Math.PI) + 90;
                if (deg < 0) deg += 360;
                deg = Math.round(deg % 360);

                elem.style.transform = `translate(-50%, -50%) rotate(${deg}deg)`;
                setElementPositionState(id, state.x, state.y, state.w, state.h, state.fontSize, deg);
                document.getElementById('elem-rotate').value = deg;
                document.getElementById('elem-rotate-val').textContent = deg + '°';
            }
        });

        const stopInteraction = (e) => {
            if (!isInteracting || selectedElement !== elem) return;
            elem.releasePointerCapture(e.pointerId);
            isInteracting = false;
            elem.style.cursor = 'move';
            saveAllPositions();
        };

        elem.addEventListener('pointerup', stopInteraction);
        elem.addEventListener('pointercancel', stopInteraction);

        // ซิงค์ข้อความเมื่อพิมพ์แก้ไขแบบ Inline (contenteditable="true")
        const editableSpan = elem.querySelector('[contenteditable="true"]');
        if (editableSpan) {
            elem.addEventListener('dblclick', (e) => {
                if (e.target.closest('.resize-handle, .rotate-handle, .delete-badge')) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                selectElement(elem);
                elem.classList.add('is-editing-text');
                editableSpan.focus();

                const selection = window.getSelection();
                if (selection) {
                    const range = document.createRange();
                    range.selectNodeContents(editableSpan);
                    range.collapse(false);
                    selection.removeAllRanges();
                    selection.addRange(range);
                }
            });
            editableSpan.addEventListener('blur', () => {
                elem.classList.remove('is-editing-text');
            });
            editableSpan.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    editableSpan.blur();
                }
            });
            editableSpan.addEventListener('input', () => {
                const text = editableSpan.innerText;
                const id = elem.dataset.id;
                if (!positions[id]) positions[id] = { x: 50, y: 50 };
                positions[id].text = text;
                saveAllPositions();
                
                // ซิงค์กับฟิลด์ข้างซ้าย
                const syncField = editableSpan.dataset.sync;
                if (syncField) {
                    const inputElem = document.getElementById(syncField);
                    if (inputElem) inputElem.value = text;
                }
            });
        }
    };

    // ทำการผูกอีเวนต์เริ่มต้นกับทุกองค์ประกอบที่มีอยู่
    preview.querySelectorAll('.interactive-element').forEach(initElementEvents);

    // ปลดตัวเลือกออกเมื่อคลิกพื้นที่ว่างของ Canvas
    preview.addEventListener('pointerdown', (e) => {
        if (e.target === preview || e.target.id === 'certificate-border') {
            selectElement(null);
        }
    });

    // -------------------------------------------------------------
    // ฟังก์ชันสร้างและเพิ่มกล่องข้อความใหม่แบบไดนามิก (Add Text Element)
    // -------------------------------------------------------------
    window.addCustomTextBox = () => {
        const id = 'custom_text_' + Date.now();
        const text = 'พิมพ์ข้อความของคุณตรงนี้';
        
        const elem = document.createElement('div');
        elem.className = 'certificate-element interactive-element certificate-text-item cert-custom-text';
        elem.dataset.id = id;
        elem.style.left = '50%';
        elem.style.top = '50%';
        elem.style.color = '#475569';
        elem.style.fontFamily = 'Sarabun';
        elem.style.fontSize = '18px';
        elem.style.fontWeight = 'normal';
        elem.style.transform = 'translate(-50%, -50%) rotate(0deg)';
        elem.style.whiteSpace = 'pre-wrap';
        
        elem.innerHTML = `
            <span class="editable-content" contenteditable="true">${text}</span>
            <div class="resize-handle handle-tl" data-handle="tl"></div>
            <div class="resize-handle handle-tr" data-handle="tr"></div>
            <div class="resize-handle handle-bl" data-handle="bl"></div>
            <div class="resize-handle handle-br" data-handle="br"></div>
            <div class="rotate-handle" data-handle="rotate"></div>
            <div class="delete-badge" onclick="deleteElementDirectly('${id}')">×</div>
        `;
        
        preview.appendChild(elem);
        initElementEvents(elem);
        
        // เซฟสถานะพิกัดข้อความใหม่ลงโครงร่าง JSON
        setElementPositionState(id, 50, 50, null, null, 18, 0, {
            text: text,
            color: '#475569',
            fontFamily: 'Sarabun',
            fontWeight: 'normal'
        });
        
        // เลือกกล่องข้อความใหม่ทันที
        selectElement(elem);
    };

    // -------------------------------------------------------------
    // ฟังก์ชันสร้างและเพิ่มรูปภาพอิสระแบบไดนามิก (Add Image Element)
    // -------------------------------------------------------------
    window.triggerCustomImageUpload = () => {
        document.getElementById('custom-image-input').click();
    };

    window.handleCustomImageUpload = (event) => {
        const file = event.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = (e) => {
            const id = 'custom_image_' + Date.now();
            const src = e.target.result; // เก็บ base64 ไว้พรีวิวและเซฟในบราว์เซอร์ชั่วคราว
            
            const elem = document.createElement('div');
            elem.className = 'certificate-element interactive-element cert-custom-image';
            elem.dataset.id = id;
            elem.style.left = '50%';
            elem.style.top = '50%';
            elem.style.width = '120px';
            elem.style.height = '120px';
            elem.style.transform = 'translate(-50%, -50%) rotate(0deg)';
            
            elem.innerHTML = `
                <img src="${src}" alt="custom image" class="w-full h-full object-contain pointer-events-none">
                <div class="resize-handle handle-tl" data-handle="tl"></div>
                <div class="resize-handle handle-tr" data-handle="tr"></div>
                <div class="resize-handle handle-bl" data-handle="bl"></div>
                <div class="resize-handle handle-br" data-handle="br"></div>
                <div class="rotate-handle" data-handle="rotate"></div>
                <div class="delete-badge" onclick="deleteElementDirectly('${id}')">×</div>
            `;
            
            preview.appendChild(elem);
            initElementEvents(elem);
            
            // บันทึกภาพลง JSON
            setElementPositionState(id, 50, 50, 120, 120, null, 0, {
                src: src
            });
            
            selectElement(elem);
            event.target.value = ''; // เคลียร์ฟอร์มอัปโหลด
        };
        reader.readAsDataURL(file);
    };

    // -------------------------------------------------------------
    // ฟังก์ชันลบองค์ประกอบเกียรติบัตรอิสระ
    // -------------------------------------------------------------
    window.deleteSelectedElement = () => {
        if (!selectedElement) return;
        const id = selectedElement.dataset.id;
        
        // ห้ามลบองค์ประกอบหลักของระบบ (ให้ลบเฉพาะกล่องที่เริ่มด้วย custom_)
        if (!id.startsWith('custom_')) {
            alert('คุณสามารถลบได้เฉพาะองค์ประกอบที่เพิ่มเข้ามาใหม่เท่านั้นครับ องค์ประกอบหลักระบบสามารถย้ายหรือจัดขนาดหลบซ่อนได้ครับ');
            return;
        }

        deleteElementDirectly(id);
    };

    window.deleteElementDirectly = (id) => {
        const elem = preview.querySelector(`[data-id="${id}"]`);
        if (elem) {
            elem.remove();
        }
        
        delete positions[id];
        saveAllPositions();
        
        if (selectedElement && selectedElement.dataset.id === id) {
            selectElement(null);
        }
    };

    // -------------------------------------------------------------
    // ฟังก์ชันส่งออกเกียรติบัตรเป็นภาพ PNG (Export PNG)
    // -------------------------------------------------------------
    const waitForPreviewImages = () => Promise.all(
        Array.from(preview.querySelectorAll('img')).map(img => {
            if (img.complete) return Promise.resolve();
            return new Promise(resolve => {
                img.addEventListener('load', resolve, { once: true });
                img.addEventListener('error', resolve, { once: true });
            });
        })
    );

    const renderPreviewToCanvas = async () => {
        // เอาการเลือก Selection active ออกก่อนเพื่อความสะอาดของรูปภาพ
        selectElement(null);
        await waitForPreviewImages();
        if (document.fonts && document.fonts.ready) {
            await document.fonts.ready;
        }

        const imageBoxSizes = Array.from(preview.querySelectorAll('.cert-background, .cert-logo, .cert-signature-image, .cert-custom-image')).map(elem => ({
            id: elem.dataset.id,
            width: elem.offsetWidth,
            height: elem.offsetHeight
        }));

        return html2canvas(preview, {
            scale: 4, // เพิ่มความละเอียดสำหรับโลโก้และงานพิมพ์ โดยไม่เปลี่ยนตำแหน่งองค์ประกอบ
            useCORS: true,
            logging: false,
            backgroundColor: null,
            onclone: clonedDocument => {
                const clonedPreview = clonedDocument.getElementById('certificate-preview');
                if (!clonedPreview) return;
                imageBoxSizes.forEach(size => {
                    const elem = clonedPreview.querySelector(`[data-id="${size.id}"]`);
                    if (!elem) return;
                    elem.style.width = `${size.width}px`;
                    elem.style.height = `${size.height}px`;
                });
            }
        });
    };

    window.exportAsPNG = () => {
        renderPreviewToCanvas().then(canvas => {
            const link = document.createElement('a');
            link.download = <?= json_encode('เกียรติบัตร-' . $certificateSubjectTitle . '.png', JSON_UNESCAPED_UNICODE) ?>;
            link.href = canvas.toDataURL('image/png');
            link.click();
        });
    };

    const pdfMaxBytes = 1000 * 1000;
    const resizeCanvas = (sourceCanvas, width) => {
        if (sourceCanvas.width <= width) return sourceCanvas;
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = Math.round((sourceCanvas.height * width) / sourceCanvas.width);
        const context = canvas.getContext('2d');
        context.imageSmoothingEnabled = true;
        context.imageSmoothingQuality = 'high';
        context.drawImage(sourceCanvas, 0, 0, canvas.width, canvas.height);
        return canvas;
    };

    const createOptimizedPdfBlob = (sourceCanvas) => {
        const variants = [
            { width: 2560, quality: 0.82 },
            { width: 2304, quality: 0.78 },
            { width: 2048, quality: 0.76 },
            { width: 1800, quality: 0.72 },
            { width: 1600, quality: 0.68 },
            { width: 1440, quality: 0.64 },
            { width: 1280, quality: 0.60 },
        ];
        const { jsPDF } = window.jspdf;

        for (const variant of variants) {
            const canvas = resizeCanvas(sourceCanvas, variant.width);
            const imageData = canvas.toDataURL('image/jpeg', variant.quality);
            const pdf = new jsPDF({
                orientation: 'landscape',
                unit: 'mm',
                format: 'a4',
                compress: true,
            });
            pdf.addImage(imageData, 'JPEG', 0, 0, 297, 210, undefined, 'FAST');
            const blob = pdf.output('blob');
            if (blob.size <= pdfMaxBytes) {
                return blob;
            }
        }

        throw new Error('ไม่สามารถลดขนาด PDF ให้ต่ำกว่า 1 MB ได้ กรุณาบันทึกเป็นรูป PNG แทน');
    };

    const downloadBlob = (blob, filename) => {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.download = filename;
        link.href = url;
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    };

    // -------------------------------------------------------------
    // ฟังก์ชันส่งออกเกียรติบัตรเป็นไฟล์ PDF ขนาด A4 (Export PDF)
    // -------------------------------------------------------------
    window.exportAsPDF = () => {
        renderPreviewToCanvas()
            .then(canvas => downloadBlob(
                createOptimizedPdfBlob(canvas),
                <?= json_encode('เกียรติบัตร-' . $certificateSubjectTitle . '.pdf', JSON_UNESCAPED_UNICODE) ?>
            ))
            .catch(error => alert(error.message || 'สร้างไฟล์ PDF ไม่สำเร็จ'));
    };
})();

// -------------------------------------------------------------
// ฟังก์ชัน Preview รูปภาพทันทีที่อัปโหลด (ก่อนกด Save)
// -------------------------------------------------------------
window.previewUploadedImage = (input, type) => {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (e) => {
        const src = e.target.result;
        const preview = document.getElementById('certificate-preview');
        if (type === 'background') {
            const bgElem = preview.querySelector('[data-id="background"]');
            if (bgElem) {
                let img = bgElem.querySelector('img');
                if (!img) {
                    img = document.createElement('img');
                    img.alt = 'background';
                    img.className = 'w-full h-full object-fill pointer-events-none';
                    bgElem.appendChild(img);
                }
                img.src = src;
                bgElem.style.display = 'block';
            }
        } else if (type === 'logo') {
            const logoElem = preview.querySelector('[data-id="logo"]');
            if (logoElem) {
                // เอา placeholder span ออกก่อนถ้ามี
                const placeholder = logoElem.querySelector('span');
                if (placeholder) placeholder.remove();
                // อัปเดตหรือสร้าง img
                let img = logoElem.querySelector('img');
                if (!img) {
                    img = document.createElement('img');
                    img.alt = 'logo';
                    img.className = 'w-full h-full object-contain pointer-events-none';
                    logoElem.insertBefore(img, logoElem.firstChild);
                }
                img.src = src;
            }
        } else if (type === 'signature') {
            const sigElem = preview.querySelector('[data-id="signature_image"]');
            if (sigElem) {
                const placeholder = sigElem.querySelector('span');
                if (placeholder) placeholder.remove();
                let img = sigElem.querySelector('img');
                if (!img) {
                    img = document.createElement('img');
                    img.alt = 'signature';
                    img.className = 'w-full h-full object-contain pointer-events-none';
                    sigElem.insertBefore(img, sigElem.firstChild);
                }
                img.src = src;
            }
        }
    };
    reader.readAsDataURL(file);
};

// -------------------------------------------------------------
// ฟังก์ชันเปลี่ยนมาใช้รูปภาพที่มีอยู่แล้วในระบบและอัปเดต Preview ทันที
// -------------------------------------------------------------
window.selectExistingImage = (select, type) => {
    const src = select.value;
    const preview = document.getElementById('certificate-preview');
    if (!src) {
        if (type === 'background') {
            const bgElem = preview.querySelector('[data-id="background"]');
            if (bgElem) bgElem.style.display = 'none';
        }
        return;
    }

    // แปลงเส้นทางให้เป็นพับลิก URL สำหรับแสดงผลบน Preview
    const publicUrl = '<?= app_base_url() ?>/' + src;

    if (type === 'background') {
        const bgElem = preview.querySelector('[data-id="background"]');
        if (bgElem) {
            let img = bgElem.querySelector('img');
            if (!img) {
                img = document.createElement('img');
                img.alt = 'background';
                img.className = 'w-full h-full object-fill pointer-events-none';
                bgElem.appendChild(img);
            }
            img.src = publicUrl;
            bgElem.style.display = 'block';
        }
    } else if (type === 'logo') {
        const logoElem = preview.querySelector('[data-id="logo"]');
        if (logoElem) {
            const placeholder = logoElem.querySelector('span');
            if (placeholder) placeholder.remove();
            let img = logoElem.querySelector('img');
            if (!img) {
                img = document.createElement('img');
                img.alt = 'logo';
                img.className = 'w-full h-full object-contain pointer-events-none';
                logoElem.insertBefore(img, logoElem.firstChild);
            }
            img.src = publicUrl;
        }
    } else if (type === 'signature') {
        const sigElem = preview.querySelector('[data-id="signature_image"]');
        if (sigElem) {
            const placeholder = sigElem.querySelector('span');
            if (placeholder) placeholder.remove();
            let img = sigElem.querySelector('img');
            if (!img) {
                img = document.createElement('img');
                img.alt = 'signature';
                img.className = 'w-full h-full object-contain pointer-events-none';
                sigElem.insertBefore(img, sigElem.firstChild);
            }
            img.src = publicUrl;
        }
    }
};

// -------------------------------------------------------------
// ฟังก์ชัน Sync ข้อความจากฟอร์มซ้ายไปยัง Canvas แบบ Real-time
// dataId  = data-id ขององค์ประกอบบน canvas (title, body, signature, issuer)
// fieldId = id ของ textarea (title_text, body_text, ...)
// -------------------------------------------------------------
window.syncCanvasText = (dataId, fieldId, text) => {
    const preview = document.getElementById('certificate-preview');
    const elem = preview.querySelector(`[data-id="${dataId}"]`);
    if (!elem) return;
    const span = elem.querySelector('.editable-content') || elem.querySelector('span');
    if (span) span.textContent = text;
};
</script>

<?php render_footer(); ?>
