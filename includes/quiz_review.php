<?php
declare(strict_types=1);

function render_quiz_review(array $questions, string $title, string $answerLabel = 'คำตอบของคุณ'): void
{
    if (!$questions) {
        return;
    }
    $incorrect = count(array_filter($questions, fn ($question) => (int) $question['is_correct'] !== 1));
    ?>
    <details class="quiz-review" open>
        <summary><?= e($title) ?> · ตอบผิด <?= $incorrect ?> จาก <?= count($questions) ?> ข้อ</summary>
        <p class="quiz-review-note">เรียงตามลำดับข้อในชุดข้อสอบ อาจต่างจากลำดับที่สุ่มตอนทำ</p>
        <ol class="quiz-review-list">
            <?php foreach ($questions as $index => $question): ?>
                <?php
                $isCorrect = (int) $question['is_correct'] === 1;
                $given = json_decode((string) $question['submitted_answers'], true);
                $given = is_array($given) ? $given : ($given === null ? [] : [$given]);
                $correct = json_decode((string) $question['correct_answers'], true);
                $correct = is_array($correct) ? $correct : [];
                ?>
                <li class="quiz-review-question <?= $isCorrect ? 'is-correct' : 'is-incorrect' ?>">
                    <p class="quiz-review-prompt">ข้อที่ <?= $index + 1 ?> · <?= e((string) $question['prompt']) ?></p>
                    <p class="quiz-review-status"><?= $isCorrect ? '✓ ตอบถูก' : '✗ ตอบผิด' ?></p>
                    <dl>
                        <dt><?= e($answerLabel) ?></dt>
                        <dd><?= e($given ? implode(' / ', array_map('strval', $given)) : 'ไม่ได้ตอบ') ?></dd>
                        <dt><?= $question['question_type'] === 'short_answer' ? 'คำตอบที่ยอมรับ' : 'คำตอบที่ถูกต้อง' ?></dt>
                        <dd><?= e(implode(' / ', array_map('strval', $correct))) ?></dd>
                    </dl>
                    <?php if (trim((string) ($question['explanation'] ?? '')) !== ''): ?>
                        <p class="quiz-review-explanation"><strong>คำอธิบาย:</strong> <?= e((string) $question['explanation']) ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </details>
    <?php
}
