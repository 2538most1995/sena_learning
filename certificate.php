<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/certificate_renderer.php';

$attempt = require_attempt();
if ($attempt['status'] !== 'passed') {
    flash('ต้องเรียนครบทุกลำดับและผ่านเกณฑ์คะแนนก่อนออกเกียรติบัตร', 'error');
    redirect(attempt_url('result.php', $attempt));
}
$certificateAttempt = certificate_attempt_for_attempt($attempt);
if (!$certificateAttempt) {
    flash('ยังไม่พบเกียรติบัตรของหลักสูตรนี้', 'error');
    redirect(attempt_url('result.php', $attempt));
}
if ((int) $certificateAttempt['id'] !== (int) $attempt['id']) {
    redirect(attempt_url('certificate.php', $certificateAttempt));
}

$settings = get_certificate_settings((int) $attempt['course_id']);

render_header('เกียรติบัตร', 'learn');

?>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&family=Charm:wght@400;700&family=Mitr:wght@300;400;500;600&family=Chakra+Petch:wght@300;400;500;600;700&family=Sriracha&display=swap" rel="stylesheet">
<script src="<?= e(app_base_url()) ?>/assets/vendor/html2canvas-1.4.1.min.js"></script>
<script src="<?= e(app_base_url()) ?>/assets/vendor/jspdf-2.5.1.umd.min.js"></script>
<section class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
    <div class="no-print mb-5 flex flex-col gap-3 sm:flex-row sm:justify-between">
        <a href="<?= e(attempt_url('result.php', $attempt)) ?>" class="inline-flex justify-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-white sm:w-auto">กลับผลการเรียน</a>
        <div class="flex flex-col gap-2 sm:flex-row">
            <button type="button" id="certificate-image-button" onclick="exportCertificatePNG()" class="inline-flex justify-center rounded-lg border border-sea bg-white px-4 py-2 text-sm font-bold text-sea hover:bg-teal-50 sm:w-auto">บันทึกเป็นรูป PNG</button>
            <button type="button" id="certificate-pdf-button" onclick="exportCertificatePDF()" class="inline-flex justify-center rounded-lg bg-sea px-4 py-2 text-sm font-bold text-white hover:bg-teal-700 sm:w-auto">ดาวน์โหลด PDF สำหรับพิมพ์</button>
        </div>
    </div>

    <div id="certificate-in-app-browser-notice" class="no-print mb-5 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" hidden>
        <p class="font-bold">คุณกำลังเปิดหน้านี้ผ่านเบราว์เซอร์ในแอป</p>
        <p class="mt-1">Facebook หรือ LINE อาจไม่อนุญาตให้ดาวน์โหลดไฟล์โดยตรง ระบบจะแสดงปุ่มบันทึกลงเครื่องหรือแชร์ไฟล์ให้แทน</p>
    </div>

    <div class="certificate-preview-shell rounded-lg bg-slate-100 p-6 print:bg-white print:p-0">
        <div class="certificate-preview-viewport">
            <img id="certificate-screen-preview" class="certificate-screen-preview" src="" alt="เกียรติบัตร <?= e($attempt['course_title']) ?>" hidden>
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
<div id="certificate-export-dialog" class="no-print fixed inset-0 z-50 flex items-center justify-center bg-slate-900/70 p-4" style="display:none" role="dialog" aria-modal="true" aria-labelledby="certificate-export-dialog-title" hidden>
    <div class="max-h-full w-full max-w-3xl overflow-auto rounded-xl bg-white p-5 shadow-xl">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 id="certificate-export-dialog-title" class="text-lg font-bold text-slate-900">บันทึกเกียรติบัตร</h2>
                <p id="certificate-export-dialog-message" class="mt-1 text-sm text-slate-600"></p>
            </div>
            <button type="button" id="certificate-export-dialog-close" class="rounded-lg border border-slate-300 px-3 py-1 text-sm font-bold text-slate-700 hover:bg-slate-50">ปิด</button>
        </div>
        <div id="certificate-export-image-wrap" class="mt-4 rounded-lg border border-slate-200 bg-slate-100 p-2" hidden>
            <img id="certificate-export-image" src="" alt="รูปเกียรติบัตรสำหรับบันทึก" class="h-auto w-full">
        </div>
        <div class="mt-4 flex flex-col gap-2 sm:flex-row">
            <button type="button" id="certificate-export-share-button" class="inline-flex justify-center rounded-lg bg-sea px-4 py-2 text-sm font-bold text-white hover:bg-teal-700">บันทึกลงเครื่อง / แชร์</button>
        </div>
    </div>
</div>
<script>
(() => {
    const certificate = document.getElementById('certificate-output');
    const screenPreview = document.getElementById('certificate-screen-preview');
    const previewLoading = document.getElementById('certificate-preview-loading');
    const pdfButton = document.getElementById('certificate-pdf-button');
    const imageButton = document.getElementById('certificate-image-button');
    const inAppBrowserNotice = document.getElementById('certificate-in-app-browser-notice');
    const exportDialog = document.getElementById('certificate-export-dialog');
    const exportDialogTitle = document.getElementById('certificate-export-dialog-title');
    const exportDialogMessage = document.getElementById('certificate-export-dialog-message');
    const exportDialogClose = document.getElementById('certificate-export-dialog-close');
    const exportImageWrap = document.getElementById('certificate-export-image-wrap');
    const exportImage = document.getElementById('certificate-export-image');
    const exportShareButton = document.getElementById('certificate-export-share-button');
    if (!certificate || !screenPreview || !previewLoading || !pdfButton || !imageButton || !exportDialog || !exportDialogTitle || !exportDialogMessage || !exportDialogClose || !exportImageWrap || !exportImage || !exportShareButton) return;

    const userAgent = navigator.userAgent || '';
    const isLineBrowser = /\bLine\//i.test(userAgent);
    const isFacebookBrowser = /FBAN|FBAV|FB_IAB|FBIOS/i.test(userAgent);
    const isInAppBrowser = isLineBrowser || isFacebookBrowser;
    const pngFilename = <?= json_encode('เกียรติบัตร-' . $attempt['learner_name'] . '.png', JSON_UNESCAPED_UNICODE) ?>;
    const pdfFilename = <?= json_encode('เกียรติบัตร-' . $attempt['learner_name'] . '.pdf', JSON_UNESCAPED_UNICODE) ?>;
    const screenPreviewScale = 3;
    const exportScale = 4;
    const pdfMaxBytes = 1000 * 1000;
    let exportFile = null;
    let exportFilename = '';

    if (isInAppBrowser && inAppBrowserNotice) {
        inAppBrowserNotice.hidden = false;
    }

    const canShareFile = (file) => {
        return typeof navigator.share === 'function'
            && typeof navigator.canShare === 'function'
            && navigator.canShare({ files: [file] });
    };

    const createExportFile = (blob, filename) => {
        try {
            return new File([blob], filename, { type: blob.type });
        } catch (error) {
            return blob;
        }
    };

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

        for (const variant of variants) {
            const canvas = resizeCanvas(sourceCanvas, variant.width);
            const imageData = canvas.toDataURL('image/jpeg', variant.quality);
            const pdf = new window.jspdf.jsPDF({
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

    const formatFileSize = (bytes) => `${Math.ceil(bytes / 1024)} KB`;

    const showExportDialog = ({ title, message, file, filename, previewSrc = '' }) => {
        exportFile = file;
        exportFilename = filename;
        exportDialogTitle.textContent = title;
        exportDialogMessage.textContent = `${message} ขนาดไฟล์ ${formatFileSize(file.size)}`;
        exportImage.src = previewSrc;
        exportImageWrap.hidden = !previewSrc;
        exportDialog.hidden = false;
        exportDialog.style.display = 'flex';
    };

    const closeExportDialog = () => {
        exportDialog.style.display = 'none';
        exportDialog.hidden = true;
        exportImage.src = '';
        exportFile = null;
        exportFilename = '';
    };

    exportDialogClose.addEventListener('click', closeExportDialog);
    exportDialog.addEventListener('click', event => {
        if (event.target === exportDialog) {
            closeExportDialog();
        }
    });
    exportShareButton.addEventListener('click', async () => {
        if (!exportFile || !exportFilename) return;
        if (!canShareFile(exportFile)) {
            downloadBlob(exportFile, exportFilename);
            return;
        }
        try {
            await navigator.share({ files: [exportFile], title: exportDialogTitle.textContent });
        } catch (error) {
            if (error.name !== 'AbortError') {
                alert('ไม่สามารถเปิดเมนูบันทึกหรือแชร์ไฟล์ได้');
            }
        }
    });

    const waitForImages = () => Promise.all(
        Array.from(certificate.querySelectorAll('img')).map((image) => {
            if (image.complete) return Promise.resolve();
            return new Promise((resolve) => {
                image.addEventListener('load', resolve, { once: true });
                image.addEventListener('error', resolve, { once: true });
            });
        })
    );

    const renderPreviewToCanvas = async (scale = 3) => {
        await waitForImages();
        if (document.fonts && document.fonts.ready) {
            await document.fonts.ready;
        }

        return await html2canvas(certificate, {
            scale: scale,
            useCORS: true,
            logging: false,
            backgroundColor: null,
            onclone: clonedDocument => {
                const clonedOutput = clonedDocument.getElementById('certificate-output');
                if (clonedOutput) {
                    clonedOutput.style.transform = 'none';
                }
            }
        });
    };

    const updateScreenPreview = async () => {
        try {
            const canvas = await renderPreviewToCanvas(screenPreviewScale);
            screenPreview.src = canvas.toDataURL('image/png');
            screenPreview.hidden = false;
            previewLoading.hidden = true;
        } catch (error) {
            previewLoading.textContent = error.message || 'ไม่สามารถแสดงภาพตัวอย่างเกียรติบัตรได้';
        }
    };

    updateScreenPreview();

    window.exportCertificatePDF = async () => {
        const originalLabel = pdfButton.textContent;
        pdfButton.disabled = true;
        pdfButton.textContent = 'กำลังสร้าง PDF...';
        try {
            if (!window.html2canvas || !window.jspdf) {
                throw new Error('ไม่สามารถโหลดเครื่องมือสร้าง PDF ได้');
            }
            const canvas = await renderPreviewToCanvas(exportScale);
            const pdfBlob = createOptimizedPdfBlob(canvas);
            if (isInAppBrowser) {
                showExportDialog({
                    title: 'บันทึกเกียรติบัตร PDF',
                    message: 'กดบันทึกลงเครื่อง / แชร์ เพื่อเลือกตำแหน่งบันทึกไฟล์ PDF',
                    file: createExportFile(pdfBlob, pdfFilename),
                    filename: pdfFilename,
                });
            } else {
                downloadBlob(pdfBlob, pdfFilename);
            }
        } catch (error) {
            alert(error.message || 'สร้างไฟล์ PDF ไม่สำเร็จ');
        } finally {
            pdfButton.disabled = false;
            pdfButton.textContent = originalLabel;
        }
    };

    window.exportCertificatePNG = async () => {
        const originalLabel = imageButton.textContent;
        imageButton.disabled = true;
        imageButton.textContent = 'กำลังสร้างรูปภาพ...';
        try {
            if (!window.html2canvas) {
                throw new Error('ไม่สามารถโหลดเครื่องมือสร้างรูปภาพได้');
            }
            const canvas = await renderPreviewToCanvas(exportScale);
            const imageUrl = canvas.toDataURL('image/png');
            if (isInAppBrowser) {
                canvas.toBlob(blob => {
                    if (!blob) {
                        alert('สร้างไฟล์รูปภาพไม่สำเร็จ');
                        return;
                    }
                    showExportDialog({
                        title: 'บันทึกรูปเกียรติบัตร PNG',
                        message: 'กดบันทึกลงเครื่อง / แชร์ เพื่อบันทึกรูปภาพ หรือแตะค้างที่รูป',
                        file: createExportFile(blob, pngFilename),
                        filename: pngFilename,
                        previewSrc: imageUrl,
                    });
                }, 'image/png');
            } else {
                const link = document.createElement('a');
                link.download = pngFilename;
                link.href = imageUrl;
                link.click();
            }
        } catch (error) {
            alert(error.message || 'สร้างไฟล์รูปภาพไม่สำเร็จ');
        } finally {
            imageButton.disabled = false;
            imageButton.textContent = originalLabel;
        }
    };
})();
</script>
<?php render_footer(); ?>
