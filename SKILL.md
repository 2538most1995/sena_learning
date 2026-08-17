---
name: maintain-sena-learning
description: Maintain and extend the SENA Learning PHP/MySQL application. Use when working on learner flows, courses, cover images, lessons, video completion gates, quizzes, choice shuffling, Excel imports, certificates, uploads, admin pages, schema changes, or local MAMP validation in this repository.
---

# SENA Learning Expert Playbook

## Start Here

1. Read `CONTEXT.md` before changing behavior.
2. Read `AGENTS.md` when the task touches more than one subsystem or requires review.
3. Inspect the affected PHP route and the relevant helpers before editing.
4. Preserve existing user data. Treat the database as a live local database.
5. Make the smallest complete change and verify the rendered behavior.

## Project Rules

- Use PHP with `declare(strict_types=1);`.
- Reuse `db()`, `e()`, `post()`, `get_int()`, `flash()`, `redirect()`, and existing helpers.
- Use prepared statements for values supplied by users.
- Escape rendered text with `e()` unless HTML rendering is intentional.
- Validate uploaded files by MIME type, not only by extension.
- Store uploaded files under `storage/uploads/`.
- Keep new installations updated in `sql/schema.sql`.
- Add idempotent `ensure_*()` migrations for an existing database when a new column is required.
- Do not delete or overwrite existing uploaded files unless the user explicitly requests cleanup.
- Keep learner links protected by `attempt`, `token`, and the matching signed-in user.

## Core Invariants

### Learner Journey

Keep the flow:

`start.php -> lesson.php -> result.php -> certificate.php`

- A learner signs in before starting.
- An attempt is identified by `attempts.id`, `attempts.access_token`, and `attempts.user_id`.
- Lessons, media, and reusable quiz sets share one ordered curriculum divided into editable sections.
- Items with `requires_previous = 1` remain locked until preceding items are completed.
- Tracked videos are completed only by the browser video-ended event.
- A certificate is available only when `attempts.status = 'passed'`.

### Quiz Scoring

- Render each quiz set inline and require every question to be answered before completion.
- Keep central quiz-set placement independent from the legacy pre/post storage field.
- Render inline curriculum answers without changing the stored choice values.
- Preserve the stored choice text because scoring compares submitted values with `correct_answers`.
- Support `single_choice`, `multiple_choice`, `true_false`, and `short_answer`.

### Certificate Designer

- Store positions as percentages for `x` and `y`.
- Store image dimensions in pixels and preserve source aspect ratio.
- Keep preview, PNG export, PDF export, and learner certificate output aligned.
- Normalize legacy image dimensions when loading settings.
- Copy certificate layouts without replacing target-course images or primary text unless requested.

### Course Covers

- Accept either an external `http://` or `https://` URL or an uploaded PNG, JPG, or WEBP file.
- Prefer a newly uploaded file over the URL entered in the same save request.
- Render uploaded local paths with `public_upload_url()`.
- Fall back to the generated `.course-thumb` placeholder when no cover is configured.

## Change Workflow

### 1. Discover

Use focused searches:

```bash
rg -n "SEARCH_TERM" -g '*.php' -g '*.css' -g '*.sql' .
rg --files -g '!vendor' -g '!node_modules'
```

Check the database shape when schema behavior matters:

```bash
php -r 'require "config/database.php"; foreach (db()->query("SHOW COLUMNS FROM courses") as $r) echo $r["Field"], "\n";'
```

### 2. Edit

- Put reusable domain logic in `includes/helpers.php`.
- Keep route orchestration in the route file.
- Update `sql/schema.sql` for fresh installs.
- Add CSS in `assets/css/app.css`.
- Avoid unrelated refactors while fixing a user-facing issue.

### 3. Verify

Run PHP lint for every changed PHP file:

```bash
php -l includes/helpers.php
php -l admin/course_form.php
php -l index.php
```

For database-changing features:

```bash
php -r 'require "includes/helpers.php"; /* call the idempotent ensure_* migration */'
```

For a route-level check, start a temporary server only if the normal MAMP Apache route is unavailable:

```bash
php -S 127.0.0.1:8018
curl -sS 'http://127.0.0.1:8018/index.php' -o /tmp/sena-index.html
```

Stop the temporary server after validation.

### 4. Protect Test Data

- Use a transaction and roll back when testing helper behavior.
- If an HTTP test must create a row or upload a file, register cleanup before running the request.
- Re-read the database after cleanup.
- Never leave temporary uploads in `storage/uploads/`.

## Feature Map

| Area | Main Files |
| --- | --- |
| Public landing and course thumbnails | `index.php`, `assets/css/app.css` |
| Start attempt | `start.php`, `includes/helpers.php` |
| Lessons and video gate | `lesson.php`, `mark_lesson.php`, `includes/helpers.php` |
| Quiz rendering and scoring | `quiz.php`, `admin/questions.php`, `includes/helpers.php` |
| Excel question import | `includes/xlsx.php`, `admin/question_template.php` |
| Course management and cover images | `admin/course_form.php`, `includes/helpers.php` |
| Certificate output | `certificate.php`, `assets/css/app.css` |
| Certificate designer | `admin/certificate_settings.php`, `includes/helpers.php` |
| Database installation | `install.php`, `sql/schema.sql`, `sql/seed.sql` |

## High-Risk Checks

Run targeted verification when touching:

- `save_score()`: confirm pass/fail status and certificate generation.
- `video_completion_status()`: confirm post-test remains locked until tracked videos finish.
- `public_upload_url()`: confirm both local upload paths and external URLs render correctly.
- `save_course_cover_upload()` or certificate uploads: confirm MIME rejection and cleanup.
- `clean_certificate_positions()`: confirm designer values survive a save round-trip.
- Quiz shuffling: confirm all choices remain present and scoring still matches the original answer.

## Completion Standard

Before reporting completion:

1. Lint changed PHP files.
2. Exercise the affected helper or route.
3. Verify cleanup after test mutations.
4. Report Browser limitations if local visual QA is blocked.
5. State any untested paths clearly.
