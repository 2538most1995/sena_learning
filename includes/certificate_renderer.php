<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function certificate_renderer_element_style(array $position, array $styles = []): string
{
    $styles[] = cert_item_style($position);
    return implode(';', array_filter($styles));
}

function certificate_renderer_asset_url(?string $path): string
{
    if (!$path) {
        return '';
    }

    if (preg_match('#^(?:data:|blob:|https?://)#i', $path)) {
        return $path;
    }

    return public_upload_url($path);
}

function certificate_renderer_text_style(
    array $position,
    string $defaultColor,
    string $defaultFontFamily,
    string $defaultFontWeight
): string {
    return certificate_renderer_element_style($position, [
        'text-align:center',
        'color:' . ($position['color'] ?? $defaultColor),
        'font-family:' . ($position['fontFamily'] ?? $defaultFontFamily),
        'font-weight:' . ($position['fontWeight'] ?? $defaultFontWeight),
    ]);
}

function render_certificate_editor_handles(?string $deleteId = null, bool $allowFreeResize = false): void
{
    ?>
    <div class="resize-handle handle-tl" data-handle="tl"></div>
    <div class="resize-handle handle-tr" data-handle="tr"></div>
    <div class="resize-handle handle-bl" data-handle="bl"></div>
    <div class="resize-handle handle-br" data-handle="br"></div>
    <?php if ($allowFreeResize): ?>
        <div class="resize-handle axis-resize-handle handle-top" data-handle="top" title="ย่อหรือขยายด้านบน"></div>
        <div class="resize-handle axis-resize-handle handle-right" data-handle="right" title="ย่อหรือขยายด้านขวา"></div>
        <div class="resize-handle axis-resize-handle handle-bottom" data-handle="bottom" title="ย่อหรือขยายด้านล่าง"></div>
        <div class="resize-handle axis-resize-handle handle-left" data-handle="left" title="ย่อหรือขยายด้านซ้าย"></div>
    <?php endif; ?>
    <div class="rotate-handle" data-handle="rotate"></div>
    <?php if ($deleteId !== null): ?>
        <?php $encodedId = json_encode($deleteId, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '""'; ?>
        <button
            type="button"
            class="delete-badge"
            aria-label="ลบองค์ประกอบ"
            onclick="deleteElementDirectly(<?= e($encodedId) ?>)"
        >×</button>
    <?php endif; ?>
    <?php
}

function render_certificate_text_content(
    string $text,
    bool $interactive = false,
    ?string $syncTarget = null
): void {
    $className = 'certificate-content' . ($interactive ? ' editable-content' : '');
    ?>
    <span
        class="<?= e($className) ?>"
        <?= $interactive ? 'contenteditable="true"' : '' ?>
        <?= $syncTarget !== null ? 'data-sync="' . e($syncTarget) . '"' : '' ?>
    ><?= e($text) ?></span>
    <?php
}

function render_certificate_canvas(array $settings, array $attempt, array $options = []): void
{
    $interactive = !empty($options['interactive']);
    $showPlaceholders = !empty($options['show_placeholders']);
    $canvasId = (string) ($options['id'] ?? 'certificate-preview');
    $canvasClasses = trim(
        'certificate-designer certificate-layout-canvas certificate-render-canvas relative overflow-hidden bg-white border border-slate-300 '
        . (string) ($options['canvas_classes'] ?? '')
    );
    $elementClasses = 'certificate-element' . ($interactive ? ' interactive-element' : '');
    $positions = $settings['positions'] ?? default_certificate_positions();
    $backgroundUrl = certificate_renderer_asset_url($settings['background_image'] ?? null);
    $logoUrl = certificate_renderer_asset_url($settings['logo_image'] ?? null);
    $signatureUrl = certificate_renderer_asset_url($settings['signature_image'] ?? null);
    $issueDate = date('d/m/Y', strtotime((string) ($attempt['issued_at'] ?? 'now')));
    $bodyText = certificate_text((string) ($settings['body_text'] ?? ''), $attempt);
    ?>
    <div id="<?= e($canvasId) ?>" class="<?= e($canvasClasses) ?>" style="background-color:#ffffff;">
        <?php if ($backgroundUrl !== '' || $interactive): ?>
            <div
                class="<?= e($elementClasses) ?> cert-background"
                data-id="background"
                style="<?= e(certificate_renderer_element_style($positions['background'], [
                    'z-index:1',
                    $backgroundUrl === '' ? 'display:none' : '',
                ])) ?>"
            >
                <img src="<?= e($backgroundUrl) ?>" alt="" draggable="false">
                <?php if ($interactive): ?>
                    <?php render_certificate_editor_handles(null, true); ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($logoUrl !== '' || $showPlaceholders): ?>
            <div
                class="<?= e($elementClasses) ?> cert-logo"
                data-id="logo"
                style="<?= e(certificate_renderer_element_style($positions['logo'])) ?>"
            >
                <?php if ($logoUrl !== ''): ?>
                    <img src="<?= e($logoUrl) ?>" alt="โลโก้" draggable="false">
                <?php else: ?>
                    <span>โลโก้</span>
                <?php endif; ?>
                <?php if ($interactive): ?>
                    <?php render_certificate_editor_handles(); ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div
            class="<?= e($elementClasses) ?> cert-title"
            data-id="title"
            style="<?= e(certificate_renderer_text_style($positions['title'], '#92400e', 'Sarabun', '800')) ?>"
        >
            <?php render_certificate_text_content((string) ($settings['title_text'] ?? ''), $interactive, 'title_text'); ?>
            <?php if ($interactive): ?>
                <?php render_certificate_editor_handles(); ?>
            <?php endif; ?>
        </div>

        <div
            class="<?= e($elementClasses) ?> cert-name"
            data-id="name"
            style="<?= e(certificate_renderer_text_style($positions['name'], '#0f766e', 'Sarabun', '900')) ?>"
        >
            <?php render_certificate_text_content((string) ($attempt['learner_name'] ?? 'นายสมชาย เรียนรู้ดี')); ?>
            <?php if ($interactive): ?>
                <?php render_certificate_editor_handles(); ?>
            <?php endif; ?>
        </div>

        <div
            class="<?= e($elementClasses) ?> cert-body"
            data-id="body"
            style="<?= e(certificate_renderer_element_style($positions['body'], [
                'text-align:center',
                'color:' . ($positions['body']['color'] ?? '#475569'),
                'font-family:' . ($positions['body']['fontFamily'] ?? 'Sarabun'),
                'font-weight:' . ($positions['body']['fontWeight'] ?? 'normal'),
                'line-height:1.6',
            ])) ?>"
        >
            <?php render_certificate_text_content($bodyText, $interactive, 'body_text'); ?>
            <?php if ($interactive): ?>
                <?php render_certificate_editor_handles(); ?>
            <?php endif; ?>
        </div>

        <div
            class="<?= e($elementClasses) ?> cert-course"
            data-id="course"
            style="<?= e(certificate_renderer_text_style($positions['course'], '#0f172a', 'Sarabun', '800')) ?>"
        >
            <?php render_certificate_text_content((string) ($attempt['course_title'] ?? 'ชื่อหลักสูตร')); ?>
            <?php if ($interactive): ?>
                <?php render_certificate_editor_handles(); ?>
            <?php endif; ?>
        </div>

        <?php if ($signatureUrl !== '' || $showPlaceholders): ?>
            <div
                class="<?= e($elementClasses) ?> cert-signature-image"
                data-id="signature_image"
                style="<?= e(certificate_renderer_element_style($positions['signature_image'])) ?>"
            >
                <?php if ($signatureUrl !== ''): ?>
                    <img src="<?= e($signatureUrl) ?>" alt="ลายเซ็น" draggable="false">
                <?php else: ?>
                    <span>ลายเซ็น</span>
                <?php endif; ?>
                <?php if ($interactive): ?>
                    <?php render_certificate_editor_handles(); ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div
            class="<?= e($elementClasses) ?> cert-signature"
            data-id="signature"
            style="<?= e(certificate_renderer_text_style($positions['signature'], '#0f172a', 'Sarabun', 'bold')) ?>"
        >
            <?php render_certificate_text_content((string) ($settings['signature_name'] ?? ''), $interactive, 'signature_name'); ?>
            <?php if ($interactive): ?>
                <?php render_certificate_editor_handles(); ?>
            <?php endif; ?>
        </div>

        <div
            class="<?= e($elementClasses) ?> cert-issuer"
            data-id="issuer"
            style="<?= e(certificate_renderer_text_style($positions['issuer'], '#64748b', 'Sarabun', 'bold')) ?>"
        >
            <?php render_certificate_text_content((string) ($settings['issuer_name'] ?? ''), $interactive, 'issuer_name'); ?>
            <?php if ($interactive): ?>
                <?php render_certificate_editor_handles(); ?>
            <?php endif; ?>
        </div>

        <div
            class="<?= e($elementClasses) ?> cert-code"
            data-id="code"
            style="<?= e(certificate_renderer_text_style($positions['code'], '#94a3b8', 'Sarabun', '500')) ?>"
        >
            <?php render_certificate_text_content('รหัส ' . (string) ($attempt['certificate_code'] ?? 'DEMO') . ' | วันที่ออก ' . $issueDate); ?>
            <?php if ($interactive): ?>
                <?php render_certificate_editor_handles(); ?>
            <?php endif; ?>
        </div>

        <?php foreach ($positions as $itemId => $item): ?>
            <?php if (strpos((string) $itemId, 'custom_text_') === 0): ?>
                <div
                    class="<?= e($elementClasses) ?> certificate-text-item cert-custom-text"
                    data-id="<?= e((string) $itemId) ?>"
                    style="<?= e(certificate_renderer_text_style($item, '#0f172a', 'Sarabun', '400')) ?>"
                >
                    <?php render_certificate_text_content((string) ($item['text'] ?? ''), $interactive); ?>
                    <?php if ($interactive): ?>
                        <?php render_certificate_editor_handles((string) $itemId); ?>
                    <?php endif; ?>
                </div>
            <?php elseif (strpos((string) $itemId, 'custom_image_') === 0): ?>
                <?php $imageUrl = certificate_renderer_asset_url($item['src'] ?? null); ?>
                <div
                    class="<?= e($elementClasses) ?> cert-custom-image"
                    data-id="<?= e((string) $itemId) ?>"
                    style="<?= e(certificate_renderer_element_style($item)) ?>"
                >
                    <?php if ($imageUrl !== ''): ?>
                        <img src="<?= e($imageUrl) ?>" alt="" draggable="false">
                    <?php else: ?>
                        <span>รูปภาพ</span>
                    <?php endif; ?>
                    <?php if ($interactive): ?>
                        <?php render_certificate_editor_handles((string) $itemId); ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php
}
