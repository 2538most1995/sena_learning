<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/certificate_renderer.php';

if (!database_ready()) {
    render_empty_setup();
    exit;
}

$certificateCode = trim((string) ($_GET['code'] ?? ''));
$attempt = certificate_attempt_by_code($certificateCode);

if (!$attempt) {
    http_response_code(404);
    render_header('ไม่พบเกียรติบัตร', 'learn');
    ?>
    <section class="mx-auto max-w-3xl px-4 py-16 sm:px-6">
        <div class="rounded-lg border border-slate-200 bg-white p-8 text-center shadow-soft">
            <p class="text-sm font-bold text-sea">ตรวจสอบเกียรติบัตร</p>
            <h1 class="mt-3 text-3xl font-extrabold">ไม่พบเกียรติบัตรนี้</h1>
            <p class="mt-3 text-slate-600">กรุณาตรวจสอบรหัสเกียรติบัตรอีกครั้ง หรือติดต่อผู้ดูแลระบบ</p>
        </div>
    </section>
    <?php
    render_footer();
    exit;
}

$settings = get_certificate_settings((int) $attempt['course_id']);
$isPublicQuizCertificate = ($attempt['certificate_source'] ?? '') === 'public_quiz';
if ($isPublicQuizCertificate) {
    if ((string) $settings['title_text'] === 'เกียรติบัตรการผ่านหลักสูตร') {
        $settings['title_text'] = 'เกียรติบัตรการผ่านแบบทดสอบ';
    }
    if ((string) $settings['body_text'] === 'เพื่อแสดงว่าได้ผ่านการเรียนรู้ในหลักสูตร {{course}} โดยผ่านแบบทดสอบหลังเรียนตามเกณฑ์ที่กำหนด') {
        $settings['body_text'] = 'เพื่อแสดงว่าได้ทำแบบทดสอบ {{course}} และผ่านเกณฑ์คะแนนที่กำหนด';
    }
}
$certificateTypeLabel = $isPublicQuizCertificate ? 'เกียรติบัตรผ่านแบบทดสอบ' : 'เกียรติบัตรผ่านหลักสูตร';

render_header('เกียรติบัตร ' . (string) $attempt['learner_name'], 'learn');
?>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&family=Charm:wght@400;700&family=Mitr:wght@300;400;500;600&family=Chakra+Petch:wght@300;400;500;600;700&family=Sriracha&display=swap" rel="stylesheet">
<script src="<?= e(app_base_url()) ?>/assets/vendor/html2canvas-1.4.1.min.js"></script>
<script src="<?= e(app_base_url()) ?>/assets/vendor/jspdf-2.5.1.umd.min.js"></script>
<section class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
    <div class="mb-5 rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm">
        <p class="text-sm font-bold text-sea"><?= e($certificateTypeLabel) ?></p>
        <h1 class="mt-1 text-2xl font-extrabold text-ink"><?= e((string) $attempt['learner_name']) ?></h1>
        <p class="mt-1 text-sm text-slate-600"><?= e((string) $attempt['course_title']) ?></p>
        <p class="mt-2 text-xs font-semibold text-slate-500">รหัสเกียรติบัตร <?= e((string) $attempt['certificate_code']) ?></p>
        <div class="mt-4 flex flex-col gap-2 sm:flex-row">
            <button type="button" id="certificate-image-button" onclick="exportCertificatePNG()" class="inline-flex justify-center rounded-lg border border-sea bg-white px-4 py-2 text-sm font-bold text-sea hover:bg-teal-50 sm:w-auto">บันทึกเป็นรูป PNG</button>
            <button type="button" id="certificate-pdf-button" onclick="exportCertificatePDF()" class="inline-flex justify-center rounded-lg bg-sea px-4 py-2 text-sm font-bold text-white hover:bg-teal-700 sm:w-auto">ดาวน์โหลด PDF สำหรับพิมพ์</button>
        </div>
    </div>

    <div class="certificate-preview-shell rounded-lg bg-slate-100 p-6 print:bg-white print:p-0">
        <div class="certificate-preview-viewport">
            <img id="certificate-screen-preview" class="certificate-screen-preview" src="" alt="เกียรติบัตร <?= e((string) $attempt['course_title']) ?>" hidden>
            <div id="certificate-preview-loading" class="certificate-preview-loading">กำลังเตรียมภาพเกียรติบัตร...</div>
        </div>
    </div>

    <div class="certificate-render-source" aria-hidden="true">
        <?php render_certificate_canvas($settings, $attempt, [
            'id' => 'certificate-output',
            'canvas_classes' => 'certificate-output',
        ]); ?>
    </div>
</section>
<script>
(() => {
    const certificate = document.getElementById('certificate-output');
    const screenPreview = document.getElementById('certificate-screen-preview');
    const previewLoading = document.getElementById('certificate-preview-loading');
    const pdfButton = document.getElementById('certificate-pdf-button');
    const imageButton = document.getElementById('certificate-image-button');
    if (!certificate || !screenPreview || !previewLoading || !pdfButton || !imageButton || typeof html2canvas !== 'function') return;

    const autoDownload = new URLSearchParams(window.location.search).get('download') === '1';
    const pngFilename = <?= json_encode('เกียรติบัตร-' . $attempt['learner_name'] . '.png', JSON_UNESCAPED_UNICODE) ?>;
    const pdfFilename = <?= json_encode('เกียรติบัตร-' . $attempt['learner_name'] . '.pdf', JSON_UNESCAPED_UNICODE) ?>;

    const waitForImages = () => Promise.all(
        Array.from(certificate.querySelectorAll('img')).map((image) => {
            if (image.complete) return Promise.resolve();
            return new Promise((resolve) => {
                image.addEventListener('load', resolve, { once: true });
                image.addEventListener('error', resolve, { once: true });
            });
        })
    );

    const renderCanvas = async (scale = 3) => {
        await waitForImages();
        if (document.fonts && document.fonts.ready) {
            await document.fonts.ready;
        }
        return await html2canvas(certificate, {
            scale,
            useCORS: true,
            logging: false,
            backgroundColor: null,
            onclone: (clonedDocument) => {
                const clonedOutput = clonedDocument.getElementById('certificate-output');
                if (clonedOutput) {
                    clonedOutput.style.transform = 'none';
                }
            }
        });
    };

    const downloadBlob = (blob, filename) => {
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    };

    window.exportCertificatePNG = async () => {
        imageButton.disabled = true;
        try {
            const canvas = await renderCanvas(4);
            canvas.toBlob((blob) => {
                if (blob) downloadBlob(blob, pngFilename);
            }, 'image/png');
        } catch (error) {
            alert(error.message || 'ไม่สามารถบันทึก PNG ได้');
        } finally {
            imageButton.disabled = false;
        }
    };

    window.exportCertificatePDF = async () => {
        pdfButton.disabled = true;
        try {
            const canvas = await renderCanvas(4);
            const jsPDF = window.jspdf && window.jspdf.jsPDF;
            if (!jsPDF) throw new Error('ไม่พบตัวสร้าง PDF');
            const pdf = new jsPDF({
                orientation: canvas.width >= canvas.height ? 'landscape' : 'portrait',
                unit: 'px',
                format: [canvas.width, canvas.height],
                compress: true
            });
            pdf.addImage(canvas.toDataURL('image/jpeg', 0.95), 'JPEG', 0, 0, canvas.width, canvas.height);
            pdf.save(pdfFilename);
        } catch (error) {
            alert(error.message || 'ไม่สามารถดาวน์โหลด PDF ได้');
        } finally {
            pdfButton.disabled = false;
        }
    };

    const renderPreview = async () => {
        try {
            const canvas = await renderCanvas(3);
            screenPreview.src = canvas.toDataURL('image/png');
            screenPreview.hidden = false;
            previewLoading.hidden = true;
            if (autoDownload) {
                window.setTimeout(() => window.exportCertificatePDF(), 250);
            }
        } catch (error) {
            previewLoading.textContent = error.message || 'ไม่สามารถแสดงภาพเกียรติบัตรได้';
        }
    };

    renderPreview();
})();
</script>
<?php render_footer(); ?>
