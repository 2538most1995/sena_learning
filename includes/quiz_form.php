<?php
declare(strict_types=1);

// Keep randomized question and choice order stable while the learner fills gaps.
function quiz_form_questions(array $questions, string $key, bool $shuffleQuestions, bool $shuffleChoices): array
{
    $order = $_SESSION['quiz_form_order'][$key] ?? [];
    $ids = array_map(fn ($question) => (int) $question['id'], $questions);
    $sortedIds = $ids;
    sort($sortedIds);
    $previousIds = $order['ids'] ?? [];
    sort($previousIds);
    if ($previousIds !== $sortedIds) {
        if ($shuffleQuestions) {
            shuffle($ids);
        }
        $order = ['ids' => $ids, 'choices' => []];
    }
    $byId = array_column($questions, null, 'id');
    $result = [];
    foreach ($order['ids'] ?? [] as $id) {
        $question = $byId[$id];
        $choices = json_decode((string) ($question['choices'] ?? '[]'), true) ?: [];
        $savedChoices = $order['choices'][$id] ?? [];
        $original = $choices;
        sort($original);
        $previous = $savedChoices;
        sort($previous);
        if ($original !== $previous) {
            if ($shuffleChoices) {
                shuffle($choices);
            }
            $order['choices'][$id] = $choices;
        } else {
            $choices = $savedChoices;
        }
        $question['choices'] = json_encode($choices, JSON_UNESCAPED_UNICODE);
        $result[] = $question;
    }
    $_SESSION['quiz_form_order'][$key] = $order;
    return $result;
}

function quiz_form_missing(array $questions, array $submitted): array
{
    $missing = [];
    foreach ($questions as $index => $question) {
        $answer = $submitted[(int) $question['id']] ?? '';
        $values = is_array($answer) ? $answer : [$answer];
        $filled = array_filter($values, fn ($value) => is_scalar($value) && trim((string) $value) !== '');
        if (!$filled) {
            $missing[(int) $question['id']] = $index + 1;
        }
    }
    return $missing;
}

function render_quiz_form_notice(array $missing): void
{
    ?>
    <div data-quiz-notice role="alert" tabindex="-1" class="mb-6 rounded-xl border border-red-300 bg-red-50 p-4 text-red-800" <?= !$missing ? 'hidden' : '' ?>>
        <p>ยังตอบไม่ครบ กรุณาตอบข้อที่เหลือ คำตอบเดิมยังอยู่</p>
        <div data-quiz-missing><?php foreach ($missing as $id => $number): ?>
            <a class="mr-3 underline" href="#quiz-question-<?= (int) $id ?>">ข้อที่ <?= (int) $number ?></a>
        <?php endforeach; ?></div>
    </div>
    <?php
}
