<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$attempt = require_attempt();
ensure_curriculum_tables();
db()->prepare("UPDATE attempts SET status = 'learning', updated_at = NOW() WHERE id = ? AND status IN ('registered','pretest_done')")
    ->execute([(int) $attempt['id']]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $item = curriculum_item_for_attempt($attempt, (int) post('item_id'));
        if (!$item || (int) $item['is_accessible'] !== 1) {
            throw new RuntimeException('กรุณาเรียนรายการก่อนหน้าให้ครบก่อน');
        }

        if (post('action') === 'complete_lesson') {
            if ($item['item_type'] !== 'lesson' || lesson_requires_video_completion($item)) {
                throw new RuntimeException('วิดีโอต้องดูจนจบก่อนจึงจะบันทึกได้');
            }
            mark_lesson_completed((int) $attempt['id'], (int) $item['lesson_id'], 'manual');
            finalize_curriculum_attempt((int) $attempt['id']);
            flash('บันทึกว่าเรียนรายการนี้จบแล้ว');
        } elseif (post('action') === 'answer_quiz_set') {
            if ($item['item_type'] !== 'quiz_set') {
                throw new RuntimeException('ไม่พบชุดข้อสอบในลำดับนี้');
            }
            $result = save_curriculum_quiz_set_answers((int) $attempt['id'], $item, is_array($_POST['answers'] ?? null) ? $_POST['answers'] : []);
            finalize_curriculum_attempt((int) $attempt['id']);
            flash('บันทึกชุดข้อสอบแล้ว ได้คะแนน ' . $result['correct'] . '/' . $result['total'] . ' และปลดล็อกรายการถัดไปแล้ว');
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'error');
    }
    redirect(attempt_url('lesson.php', $attempt));
}

$summary = curriculum_summary($attempt);
$items = $summary['items'];
$selected = null;
$requestedItemId = get_int('item');
foreach ($items as $item) {
    if ((int) $item['id'] === $requestedItemId && ((int) $item['is_accessible'] === 1 || (int) $item['is_completed'] === 1)) {
        $selected = $item;
        break;
    }
}
if (!$selected) {
    foreach ($items as $item) {
        if ((int) $item['is_accessible'] === 1 && (int) $item['is_completed'] !== 1) {
            $selected = $item;
            break;
        }
    }
}
if (!$selected && $items) {
    $selected = $items[0];
}

$progressPercent = $summary['required'] > 0 ? (int) round(($summary['completed'] / $summary['required']) * 100) : 100;
$questionLabels = [
    'single_choice' => 'เลือก 1 คำตอบ',
    'multiple_choice' => 'เลือกได้หลายคำตอบ',
    'true_false' => 'ถูก / ผิด',
    'short_answer' => 'คำตอบสั้น',
];
$videoWatchToken = '';
if ($selected && $selected['item_type'] === 'lesson' && lesson_requires_video_completion($selected)) {
    $videoWatchToken = start_video_watch_session(
        (int) $attempt['id'],
        (int) $selected['lesson_id'],
        (int) ($selected['video_duration_seconds'] ?? 0)
    );
}

render_header('บทเรียน', 'learn');
?>
<section class="learner-curriculum-page">
    <aside class="learner-curriculum-sidebar">
        <a href="index.php" class="learner-course-back">← กลับหน้าหลัก</a>
        <h1><?= e($attempt['course_title']) ?></h1>
        <p><?= e($attempt['learner_name']) ?></p>
        <div class="learner-progress-card">
            <div><span>ความคืบหน้า</span><strong><?= $progressPercent ?>%</strong></div>
            <div class="learner-progress-track"><i style="width: <?= $progressPercent ?>%"></i></div>
            <small><?= (int) $summary['completed'] ?> จาก <?= (int) $summary['required'] ?> รายการ</small>
        </div>
        <nav class="learner-curriculum-nav">
            <?php foreach ($items as $index => $item): ?>
                <?php
                $isSelected = $selected && (int) $selected['id'] === (int) $item['id'];
                $isQuizSet = $item['item_type'] === 'quiz_set';
                $classes = $isSelected ? 'is-selected ' : '';
                $classes .= (int) $item['is_completed'] === 1 ? 'is-completed ' : '';
                $classes .= (int) $item['is_locked'] === 1 ? 'is-locked ' : '';
                ?>
                <?php if ((int) $item['is_locked'] === 1): ?>
                    <span class="learner-nav-item <?= $classes ?>">
                <?php else: ?>
                    <a class="learner-nav-item <?= $classes ?>" href="<?= e(attempt_url('lesson.php', $attempt, ['item' => (int) $item['id']])) ?>">
                <?php endif; ?>
                    <i><?= (int) $item['is_completed'] === 1 ? '✓' : ($index + 1) ?></i>
                    <span>
                        <strong><?= e($item['title']) ?></strong>
                        <small>
                            <?= $isQuizSet ? 'ชุดข้อสอบ · ' . (int) $item['quiz_question_total'] . ' ข้อ' : e((string) $item['content_type']) ?>
                            <?php if (!$isQuizSet && !empty($item['video_duration_seconds'])): ?> · <?= e(format_learning_duration((int) $item['video_duration_seconds'])) ?><?php endif; ?>
                        </small>
                    </span>
                    <?php if ((int) $item['is_locked'] === 1): ?><b>🔒</b><?php endif; ?>
                <?= (int) $item['is_locked'] === 1 ? '</span>' : '</a>' ?>
            <?php endforeach; ?>
        </nav>
    </aside>

    <div class="learner-curriculum-main">
        <?php if (!$items): ?>
            <div class="learner-content-card learner-empty-state">
                <h2>ยังไม่มีเนื้อหาในหลักสูตรนี้</h2>
                <p>ผู้ดูแลระบบกำลังจัดเตรียมบทเรียน กรุณากลับมาใหม่อีกครั้ง</p>
            </div>
        <?php elseif ($summary['ready']): ?>
            <?php $final = finalize_curriculum_attempt((int) $attempt['id']); ?>
            <div class="learner-complete-card">
                <span>เรียนครบทุกลำดับแล้ว</span>
                <h2><?= !empty($final['passed']) ? 'ยอดเยี่ยม คุณผ่านหลักสูตรนี้' : 'เรียนครบแล้ว ลองทบทวนข้อสอบอีกครั้ง' ?></h2>
                <p>คะแนนข้อสอบรวม <?= (int) $summary['question_correct'] ?>/<?= (int) $summary['question_total'] ?> ข้อ</p>
                <a href="<?= e(attempt_url('result.php', $attempt)) ?>">ดูสรุปผลการเรียน</a>
            </div>
        <?php elseif ($selected): ?>
            <div class="learner-content-heading">
                <div>
                    <span>รายการที่ <?= array_search((int) $selected['id'], array_map(fn ($item) => (int) $item['id'], $items), true) + 1 ?> / <?= count($items) ?></span>
                    <h2><?= e($selected['title']) ?></h2>
                </div>
                <?php if ((int) $selected['is_completed'] === 1): ?><strong>เรียนจบแล้ว</strong><?php endif; ?>
            </div>

            <article class="lesson-content learner-content-card">
                <?php if ($selected['item_type'] === 'quiz_set'): ?>
                    <?php
                    $quizQuestions = quiz_set_questions((int) $selected['quiz_set_id']);
                    if ((int) ($selected['shuffle_questions'] ?? 0) === 1) {
                        shuffle($quizQuestions);
                    }
                    ?>
                    <div class="learner-question-type">ชุดข้อสอบกลาง · <?= count($quizQuestions) ?> ข้อ</div>
                    <h3><?= e($selected['title']) ?></h3>
                    <?php if (!empty($selected['quiz_set_description'])): ?><p class="learner-quiz-description"><?= e($selected['quiz_set_description']) ?></p><?php endif; ?>
                    <?php if ((int) $selected['is_completed'] === 1): ?>
                        <div class="learner-answer-state is-correct">
                            ทำชุดข้อสอบนี้แล้ว ได้คะแนน <?= (int) $selected['quiz_question_correct'] ?>/<?= (int) $selected['quiz_question_total'] ?> ข้อ
                        </div>
                    <?php endif; ?>
                    <form method="post" class="mt-8">
                        <input type="hidden" name="action" value="answer_quiz_set">
                        <input type="hidden" name="item_id" value="<?= (int) $selected['id'] ?>">
                        <div class="grid gap-6">
                        <?php foreach ($quizQuestions as $index => $question): ?>
                            <?php
                            $choices = json_decode((string) ($question['choices'] ?? '[]'), true) ?: [];
                            if ((int) ($selected['shuffle_choices'] ?? 0) === 1) {
                                shuffle($choices);
                            }
                            ?>
                            <fieldset class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                                <legend class="text-sm font-bold text-slate-500">ข้อที่ <?= $index + 1 ?></legend>
                                <h4 class="mt-2 text-lg font-bold text-ink"><?= e($question['prompt']) ?></h4>
                                <?php if ($question['question_type'] === 'short_answer'): ?>
                                    <input name="answers[<?= (int) $question['id'] ?>]" required placeholder="พิมพ์คำตอบของคุณ" class="mt-4 w-full rounded-lg border border-slate-300 p-4 focus:border-sea focus:outline-none focus:ring-1 focus:ring-sea">
                                <?php else: ?>
                                    <div class="mt-4 grid gap-3">
                            <?php foreach ($choices as $choice): ?>
                                <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-100 p-4 transition-colors hover:bg-slate-50 has-[:checked]:border-sea has-[:checked]:bg-teal-50/50">
                                    <input
                                        type="<?= $question['question_type'] === 'multiple_choice' ? 'checkbox' : 'radio' ?>"
                                        name="answers[<?= (int) $question['id'] ?>]<?= $question['question_type'] === 'multiple_choice' ? '[]' : '' ?>"
                                        value="<?= e((string) $choice) ?>"
                                        class="h-4 w-4 text-sea border-slate-300 focus:ring-sea">
                                    <span class="text-slate-700"><?= e((string) $choice) ?></span>
                                </label>
                            <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </fieldset>
                        <?php endforeach; ?>
                        </div>
                        <div class="mt-8 flex justify-end">
                            <button class="rounded-lg bg-sea px-8 py-3 font-bold text-white shadow-soft transition-all hover:bg-teal-700 hover:shadow-lg"><?= (int) $selected['is_completed'] === 1 ? 'ส่งคำตอบใหม่อีกครั้ง' : 'ส่งชุดข้อสอบและไปต่อ' ?></button>
                        </div>
                    </form>
                <?php else: ?>
                    <?php if ($selected['content_type'] === 'video'): ?>
                        <?php $youtubeEmbedUrl = youtube_embed_url($selected['content']); ?>
                        <?php $allowSeek = (int) ($selected['allow_seek'] ?? 1) === 1; ?>
                        <?php if ($youtubeEmbedUrl !== null): ?>
                            <div data-youtube-lesson="<?= (int) $selected['lesson_id'] ?>" data-allow-seek="<?= (int) ($selected['allow_seek'] ?? 1) ?>" data-watch-token="<?= e($videoWatchToken) ?>">
                                <iframe
                                    src="<?= e($youtubeEmbedUrl) ?>"
                                    title="<?= e('YouTube video: ' . $selected['title']) ?>"
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; fullscreen; gyroscope; picture-in-picture; web-share"
                                    referrerpolicy="strict-origin-when-cross-origin"
                                    allowfullscreen></iframe>
                                <?php if (!$allowSeek): ?>
                                    <button type="button" class="locked-youtube-fullscreen" data-youtube-fullscreen aria-label="ขยายวิดีโอเต็มจอ">เต็มจอ</button>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <?php if ($allowSeek): ?>
                                <video controls src="<?= e($selected['content']) ?>" data-lesson-video="<?= (int) $selected['lesson_id'] ?>" data-allow-seek="1" data-watch-token="<?= e($videoWatchToken) ?>"></video>
                            <?php else: ?>
                                <div class="locked-video-player">
                                    <video preload="metadata" src="<?= e($selected['content']) ?>" data-lesson-video="<?= (int) $selected['lesson_id'] ?>" data-allow-seek="0" data-watch-token="<?= e($videoWatchToken) ?>"></video>
                                    <div class="locked-video-controls" data-locked-video-controls>
                                        <button type="button" class="locked-video-toggle" data-video-toggle aria-label="เล่นหรือพักวิดีโอ">เล่น</button>
                                        <div class="locked-video-progress" aria-hidden="true"><span data-video-progress></span></div>
                                        <span class="locked-video-time" data-video-time>0:00 / 0:00</span>
                                        <button type="button" class="locked-video-mute" data-video-mute aria-label="เปิดหรือปิดเสียง">เสียง</button>
                                        <button type="button" class="locked-video-fullscreen" data-video-fullscreen aria-label="เต็มจอ">เต็มจอ</button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php elseif ($selected['content_type'] === 'embed'): ?>
                        <?php $usesYoutubePlayer = lesson_uses_youtube_player($selected); ?>
                        <?php $allowSeek = (int) ($selected['allow_seek'] ?? 1) === 1; ?>
                        <?php if ($usesYoutubePlayer): ?><div data-youtube-lesson="<?= (int) $selected['lesson_id'] ?>" data-allow-seek="<?= (int) ($selected['allow_seek'] ?? 1) ?>" data-watch-token="<?= e($videoWatchToken) ?>"><?php endif; ?>
                        <?= $selected['content'] ?>
                        <?php if ($usesYoutubePlayer && !$allowSeek): ?>
                            <button type="button" class="locked-youtube-fullscreen" data-youtube-fullscreen aria-label="ขยายวิดีโอเต็มจอ">เต็มจอ</button>
                        <?php endif; ?>
                        <?php if ($usesYoutubePlayer): ?></div><?php endif; ?>
                    <?php elseif ($selected['content_type'] === 'link'): ?>
                        <div class="learner-link-card">
                            <p>เปิดสื่อการเรียนรู้ในแท็บใหม่ แล้วกลับมากดยืนยันเมื่อเรียนจบ</p>
                            <a target="_blank" rel="noopener" href="<?= e($selected['content']) ?>">เปิดสื่อการเรียนรู้ ↗</a>
                        </div>
                    <?php else: ?>
                        <?= $selected['content'] ?>
                    <?php endif; ?>

                    <?php if (lesson_requires_video_completion($selected)): ?>
                        <?php
                        $videoDurationLabel = format_learning_duration((int) ($selected['video_duration_seconds'] ?? 0));
                        ?>
                        <div class="learner-video-note">
                            <strong><?= (int) $selected['is_completed'] === 1 ? 'ดูวิดีโอนี้จบแล้ว' : 'ดูวิดีโอให้จบเพื่อปลดล็อกรายการถัดไป' ?></strong>
                            <span><?= (int) $selected['is_completed'] === 1 ? 'บันทึกความคืบหน้าแล้ว' : 'ระบบจะบันทึกให้อัตโนมัติเมื่อวิดีโอจบ' ?><?= $videoDurationLabel !== '' ? ' · เวลาเรียน ' . e($videoDurationLabel) : '' ?></span>
                        </div>
                    <?php elseif ((int) $selected['is_completed'] !== 1): ?>
                        <form method="post" class="learner-complete-action">
                            <input type="hidden" name="action" value="complete_lesson">
                            <input type="hidden" name="item_id" value="<?= (int) $selected['id'] ?>">
                            <button>เรียนจบแล้ว ไปขั้นต่อไป</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </article>
        <?php endif; ?>
    </div>
</section>
<script>
(() => {
    const endpoint = <?= json_encode(attempt_url('mark_lesson.php', $attempt), JSON_UNESCAPED_SLASHES) ?>;
    const completingLessons = new Set();
    const markComplete = async (lessonId, evidence = {}) => {
        if (completingLessons.has(lessonId)) return;
        completingLessons.add(lessonId);
        const form = new FormData();
        form.append('lesson_id', String(lessonId));
        Object.entries(evidence).forEach(([key, value]) => form.append(key, String(value ?? '')));
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                body: form,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const result = await response.json();
            if (response.ok && result.next_url) {
                window.location.href = result.next_url;
                return;
            }
            if (response.ok) window.location.reload();
        } finally {
            completingLessons.delete(lessonId);
        }
    };

    const createWatchTracker = (durationGetter) => {
        const forwardGraceSeconds = 0.6;
        let lastPosition = 0;
        let lastClock = performance.now();
        let watchedSeconds = 0;
        let furthestAllowed = 0;

        const syncPosition = (position) => {
            lastPosition = Math.max(0, Number(position) || 0);
            lastClock = performance.now();
        };
        const canUsePosition = (position) => Math.max(0, Number(position) || 0) <= furthestAllowed + forwardGraceSeconds;
        const allowedPosition = () => Math.max(0, furthestAllowed);
        const syncIfAllowed = (position) => {
            if (!canUsePosition(position)) return false;
            syncPosition(position);
            return true;
        };
        const record = (position, isPlaying, isSeeking, isCorrecting) => {
            const currentPosition = Math.max(0, Number(position) || 0);
            const now = performance.now();
            const elapsedSeconds = Math.max(0, (now - lastClock) / 1000);
            const mediaDelta = currentPosition - lastPosition;
            const startsFromAllowedRange = lastPosition <= furthestAllowed + forwardGraceSeconds;
            if (isPlaying && startsFromAllowedRange && !isSeeking && !isCorrecting && mediaDelta > 0 && mediaDelta <= Math.max(1.25, elapsedSeconds * 1.5)) {
                const acceptedSeconds = Math.min(mediaDelta, elapsedSeconds * 1.25);
                watchedSeconds += Math.max(0, acceptedSeconds);
                furthestAllowed = Math.max(furthestAllowed, currentPosition);
            }
            syncPosition(currentPosition);
            return furthestAllowed;
        };
        const evidence = (watchToken) => ({
            watch_token: watchToken || '',
            watched_seconds: watchedSeconds.toFixed(2),
            duration_seconds: Math.max(0, Number(durationGetter()) || 0).toFixed(2),
            max_position: furthestAllowed.toFixed(2)
        });

        return { allowedPosition, canUsePosition, evidence, furthestAllowed: () => furthestAllowed, record, syncIfAllowed, syncPosition };
    };

    const formatMediaTime = (seconds) => {
        const value = Math.max(0, Math.floor(Number(seconds) || 0));
        const minutes = Math.floor(value / 60);
        const remainingSeconds = String(value % 60).padStart(2, '0');
        return `${minutes}:${remainingSeconds}`;
    };

    const fallbackFullscreenClass = 'is-viewport-fullscreen';
    const landscapeFullscreenClass = 'is-landscape-fullscreen';
    const setLandscapeFullscreen = (element, enabled) => {
        element.classList.toggle(landscapeFullscreenClass, enabled);
        document.documentElement.classList.toggle('has-landscape-fullscreen', enabled);
        document.body?.classList.toggle('has-landscape-fullscreen', enabled);
    };
    const setFallbackFullscreen = (element, enabled) => {
        element.classList.toggle(fallbackFullscreenClass, enabled);
        document.documentElement.classList.toggle('has-viewport-fullscreen', enabled);
        document.body?.classList.toggle('has-viewport-fullscreen', enabled);
    };
    const activeFullscreenElement = () => (
        document.fullscreenElement
        || document.webkitFullscreenElement
        || document.msFullscreenElement
        || document.querySelector(`.${fallbackFullscreenClass}`)
    );
    const lockLandscapeOrientation = () => {
        const lock = screen.orientation?.lock;
        if (typeof lock !== 'function') return;
        try {
            const result = lock.call(screen.orientation, 'landscape');
            if (result && typeof result.catch === 'function') result.catch(() => {});
        } catch (error) {
            // Some mobile browsers expose orientation lock but reject it outside native fullscreen.
        }
    };
    const unlockOrientation = () => {
        const unlock = screen.orientation?.unlock;
        if (typeof unlock !== 'function') return;
        try {
            unlock.call(screen.orientation);
        } catch (error) {
            // Ignore browser-specific unlock failures.
        }
    };
    const clearFullscreenPresentation = () => {
        document.querySelectorAll(`.${landscapeFullscreenClass}`).forEach((element) => {
            setLandscapeFullscreen(element, false);
        });
        document.querySelectorAll(`.${fallbackFullscreenClass}`).forEach((element) => {
            setFallbackFullscreen(element, false);
        });
        unlockOrientation();
    };
    const openFullscreen = (element) => {
        const useFallbackFullscreen = () => {
            setLandscapeFullscreen(element, true);
            setFallbackFullscreen(element, true);
            lockLandscapeOrientation();
        };
        setLandscapeFullscreen(element, true);
        const request = element.requestFullscreen || element.webkitRequestFullscreen || element.msRequestFullscreen;
        if (!request) {
            useFallbackFullscreen();
            return;
        }
        try {
            const result = element.requestFullscreen
                ? element.requestFullscreen({ navigationUI: 'hide' })
                : request.call(element);
            if (result && typeof result.catch === 'function') {
                result.then(() => lockLandscapeOrientation()).catch(useFallbackFullscreen);
            } else {
                lockLandscapeOrientation();
            }
        } catch (error) {
            useFallbackFullscreen();
        }
    };
    const closeFullscreen = () => {
        const fallbackElement = document.querySelector(`.${fallbackFullscreenClass}`);
        if (fallbackElement) {
            setFallbackFullscreen(fallbackElement, false);
            setLandscapeFullscreen(fallbackElement, false);
            unlockOrientation();
        }
        const exit = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen;
        if (exit && (document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement)) {
            const result = exit.call(document);
            if (result && typeof result.catch === 'function') result.catch(() => {});
        }
    };
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && document.querySelector(`.${fallbackFullscreenClass}`)) closeFullscreen();
    });
    ['fullscreenchange', 'webkitfullscreenchange', 'msfullscreenchange'].forEach((eventName) => {
        document.addEventListener(eventName, () => {
            if (!document.fullscreenElement && !document.webkitFullscreenElement && !document.msFullscreenElement) {
                clearFullscreenPresentation();
            }
        });
    });

    const installLockedNativeControls = (video) => {
        const shell = video.closest('.locked-video-player');
        if (!shell) return;
        const toggle = shell.querySelector('[data-video-toggle]');
        const mute = shell.querySelector('[data-video-mute]');
        const fullscreen = shell.querySelector('[data-video-fullscreen]');
        const progress = shell.querySelector('[data-video-progress]');
        const time = shell.querySelector('[data-video-time]');
        const update = () => {
            if (toggle) toggle.textContent = video.paused || video.ended ? 'เล่น' : 'พัก';
            if (mute) mute.textContent = video.muted ? 'เปิดเสียง' : 'เสียง';
            if (fullscreen) fullscreen.textContent = activeFullscreenElement() === shell ? 'ออกเต็มจอ' : 'เต็มจอ';
            if (progress) {
                const duration = Number(video.duration) || 0;
                const ratio = duration > 0 ? Math.min(100, Math.max(0, (video.currentTime / duration) * 100)) : 0;
                progress.style.width = `${ratio}%`;
            }
            if (time) time.textContent = `${formatMediaTime(video.currentTime)} / ${formatMediaTime(video.duration)}`;
        };
        const togglePlayback = () => {
            if (video.paused || video.ended) {
                video.play().catch(() => {});
            } else {
                video.pause();
            }
        };
        video.controls = false;
        video.disablePictureInPicture = true;
        video.setAttribute('controlsList', 'nodownload noplaybackrate noremoteplayback');
        video.addEventListener('click', togglePlayback);
        toggle?.addEventListener('click', togglePlayback);
        mute?.addEventListener('click', () => {
            video.muted = !video.muted;
            update();
        });
        fullscreen?.addEventListener('click', () => {
            if (activeFullscreenElement() === shell) {
                closeFullscreen();
            } else {
                openFullscreen(shell);
            }
            window.setTimeout(update, 0);
        });
        document.addEventListener('fullscreenchange', update);
        document.addEventListener('webkitfullscreenchange', update);
        document.addEventListener('msfullscreenchange', update);
        ['loadedmetadata', 'timeupdate', 'play', 'pause', 'volumechange', 'ended'].forEach((eventName) => {
            video.addEventListener(eventName, update);
        });
        update();
    };

    document.querySelectorAll('[data-lesson-video]').forEach((video) => {
        const allowSeek = video.dataset.allowSeek !== '0';
        const tracker = createWatchTracker(() => video.duration);
        let correctingSeek = false;
        const correctSeek = () => {
            if (allowSeek || correctingSeek || tracker.canUsePosition(video.currentTime)) return false;
            correctingSeek = true;
            video.currentTime = tracker.allowedPosition();
            window.setTimeout(() => {
                tracker.syncPosition(video.currentTime);
                correctingSeek = false;
            }, 0);
            return true;
        };
        if (!allowSeek) {
            installLockedNativeControls(video);
            video.playbackRate = 1;
            video.addEventListener('ratechange', () => {
                if (video.playbackRate !== 1) video.playbackRate = 1;
            });
        }
        if (!allowSeek && !tracker.canUsePosition(video.currentTime)) {
            video.currentTime = tracker.allowedPosition();
        }
        tracker.syncPosition(video.currentTime);
        video.addEventListener('timeupdate', () => {
            tracker.record(video.currentTime, !video.paused && !video.ended, video.seeking, correctingSeek);
            correctSeek();
        });
        video.addEventListener('seeking', () => {
            correctSeek();
        });
        video.addEventListener('seeked', () => {
            if (!correctSeek()) tracker.syncIfAllowed(video.currentTime);
        });
        video.addEventListener('play', () => {
            if (!correctSeek()) tracker.syncIfAllowed(video.currentTime);
        });
        video.addEventListener('ended', () => {
            tracker.record(video.currentTime, true, false, correctingSeek);
            markComplete(Number(video.dataset.lessonVideo), tracker.evidence(video.dataset.watchToken));
        });
    });

    const loadYouTubeApi = () => new Promise((resolve) => {
        if (window.YT && window.YT.Player) return resolve();
        const previousReady = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = () => {
            if (typeof previousReady === 'function') previousReady();
            resolve();
        };
        if (!document.querySelector('script[src="https://www.youtube.com/iframe_api"]')) {
            const script = document.createElement('script');
            script.src = 'https://www.youtube.com/iframe_api';
            document.head.appendChild(script);
        }
    });

    const players = [];
    document.querySelectorAll('[data-youtube-lesson]').forEach((wrapper) => {
        const iframe = wrapper.querySelector('iframe');
        const lessonId = Number(wrapper.dataset.youtubeLesson);
        const allowSeek = wrapper.dataset.allowSeek !== '0';
        if (!iframe || !lessonId) return;
        const allowValues = new Set(
            String(iframe.getAttribute('allow') || '')
                .split(';')
                .map((value) => value.trim())
                .filter(Boolean)
        );
        [
            'accelerometer',
            'autoplay',
            'clipboard-write',
            'encrypted-media',
            'fullscreen',
            'gyroscope',
            'picture-in-picture',
            'web-share',
        ].forEach((value) => allowValues.add(value));
        iframe.setAttribute('allow', [...allowValues].join('; '));
        iframe.setAttribute('allowfullscreen', '');
        const fullscreenButton = wrapper.querySelector('[data-youtube-fullscreen]');
        if (fullscreenButton) {
            const updateFullscreenLabel = () => {
                const activeElement = activeFullscreenElement();
                fullscreenButton.textContent = activeElement === wrapper ? 'ออกเต็มจอ' : 'เต็มจอ';
            };
            fullscreenButton.addEventListener('click', () => {
                const activeElement = activeFullscreenElement();
                if (activeElement === wrapper) {
                    closeFullscreen();
                } else {
                    openFullscreen(wrapper);
                }
                window.setTimeout(updateFullscreenLabel, 0);
            });
            document.addEventListener('fullscreenchange', updateFullscreenLabel);
            document.addEventListener('webkitfullscreenchange', updateFullscreenLabel);
            document.addEventListener('msfullscreenchange', updateFullscreenLabel);
            updateFullscreenLabel();
        }
        const url = new URL(iframe.src, window.location.href);
        url.searchParams.set('enablejsapi', '1');
        url.searchParams.set('origin', window.location.origin);
        url.searchParams.set('playsinline', '1');
        url.searchParams.set('rel', '0');
        if (!allowSeek) {
            url.searchParams.set('controls', '0');
            url.searchParams.set('disablekb', '1');
            url.searchParams.set('modestbranding', '1');
        }
        iframe.src = url.toString();
        iframe.id = iframe.id || `youtube-lesson-${lessonId}`;
        players.push({ iframeId: iframe.id, lessonId, allowSeek });
    });
    if (players.length) {
        loadYouTubeApi().then(() => players.forEach(({ iframeId, lessonId, allowSeek }) => {
            let correctingSeek = false;
            let durationSeconds = 0;
            const wrapper = document.querySelector(`[data-youtube-lesson="${lessonId}"]`);
            const tracker = createWatchTracker(() => durationSeconds);
            const correctSeek = (player) => {
                const currentTime = Number(player.getCurrentTime()) || 0;
                if (allowSeek || correctingSeek || tracker.canUsePosition(currentTime)) return false;
                correctingSeek = true;
                const allowedPosition = tracker.allowedPosition();
                player.seekTo(allowedPosition, true);
                window.setTimeout(() => {
                    tracker.syncPosition(Number(player.getCurrentTime()) || allowedPosition);
                    correctingSeek = false;
                }, 250);
                return true;
            };
            new YT.Player(iframeId, {
                events: {
                    onReady: (event) => {
                        durationSeconds = Number(event.target.getDuration()) || 0;
                        if (!allowSeek && typeof event.target.setPlaybackRate === 'function') {
                            event.target.setPlaybackRate(1);
                        }
                        const initialTime = Number(event.target.getCurrentTime()) || 0;
                        if (!allowSeek && !tracker.canUsePosition(initialTime)) {
                            event.target.seekTo(tracker.allowedPosition(), true);
                            tracker.syncPosition(tracker.allowedPosition());
                        } else {
                            tracker.syncPosition(initialTime);
                        }
                        window.setInterval(() => {
                            const currentTime = Number(event.target.getCurrentTime()) || 0;
                            const state = event.target.getPlayerState();
                            durationSeconds = Number(event.target.getDuration()) || durationSeconds;
                            const isPlaying = state === YT.PlayerState.PLAYING;
                            tracker.record(currentTime, isPlaying, false, correctingSeek);
                            if (!allowSeek) {
                                if (typeof event.target.getPlaybackRate === 'function' && event.target.getPlaybackRate() !== 1) {
                                    event.target.setPlaybackRate(1);
                                }
                                correctSeek(event.target);
                            }
                        }, 250);
                    },
                    onStateChange: (event) => {
                        if (!allowSeek && event.data !== YT.PlayerState.ENDED && correctSeek(event.target)) {
                            return;
                        }
                        if (event.data === YT.PlayerState.PLAYING) {
                            tracker.syncIfAllowed(Number(event.target.getCurrentTime()) || 0);
                        }
                        if (event.data === YT.PlayerState.ENDED) {
                            tracker.record(Number(event.target.getCurrentTime()) || durationSeconds, true, false, correctingSeek);
                            markComplete(lessonId, tracker.evidence(wrapper?.dataset.watchToken));
                        }
                    }
                }
            });
        }));
    }
})();
</script>
<?php render_footer(); ?>
