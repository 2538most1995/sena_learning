# SENA Learning Project Context

## Purpose

SENA Learning is a learning web application for local MAMP hosting. The home page lists all published courses. Each course can be public or limited to signed-in members. Public learners enter their full name before starting and receive a certificate after passing.

Administrators manage courses, course covers, lessons, questions, learners, scores, and certificate layouts.

## Technology

| Layer | Implementation |
| --- | --- |
| Runtime | PHP with strict types |
| Database | MySQL through PDO |
| UI | Server-rendered HTML, Tailwind CDN, custom CSS |
| Local environment | MAMP under `/Applications/MAMP/htdocs/sena_learning` |
| Spreadsheet import | Custom XLSX reader and template generator |
| Certificate export | `html2canvas` and `jsPDF` in the admin designer |

Configuration is defined in `config/config.php`. Database access is centralized in `config/database.php`.

Production learner login reads student data from the `sena_care_school` API on the same website. Configure:

```text
SENA_LEARNING_APP_URL=https://example.com/sena_learning
SENA_LEARNING_STUDENT_API_URL=https://example.com/sena_care_school/api/students.php
SENA_LEARNING_STUDENT_API_KEY=replace-with-a-server-secret
```

`SENA_LEARNING_STUDENT_API_URL` is optional when the API uses the default same-origin path. The production API URL must use HTTPS. The key must match `STUDENT_API_KEY` or one entry in `STUDENT_API_KEYS` on `sena_care_school`.

On shared hosting where nginx or PHP-FPM environment variables are unavailable,
configure the server-side `config/student_api.php` file instead. Environment
variables take precedence when both methods are present. The PHP file returns
configuration data without rendering the API key in a direct browser request.

Trusted external websites can read learner course progress through:

```text
GET /sena_learning/api/student_courses.php?student_id=XXXXXXXXXX
X-API-Key: value from SENA_LEARNING_EXPORT_API_KEY or config/learning_api.php
```

Optional query flags:

- `passed_only=1`: return only courses with an issued certificate.
- `include_all=1`: include published courses that the student has not started yet.

The response includes course progress, post-test score, pass status, and a
public certificate URL for passed courses.

## Main Routes

### Learner Routes

| Route | Responsibility |
| --- | --- |
| `index.php` | Landing page, course list, cover thumbnails, progress summary |
| `start.php` | Resume/create a member attempt or collect a public learner's full name and create a session-bound guest attempt |
| `quiz.php` | Render and score pre-tests or post-tests |
| `lesson.php` | Render lessons and enforce tracked-video completion |
| `mark_lesson.php` | Record lesson completion through JSON POST |
| `result.php` | Show post-test result and certificate eligibility |
| `certificate.php` | Render certificate output and export PNG/PDF |
| `certificate_view.php` | Public read-only certificate view by certificate code |
| `shared_quiz.php` | Permanent public quiz link, guest name collection, scoring, result, and certificate handoff |
| `api/student_courses.php` | Authenticated JSON export for student course progress and certificates |

### Admin Routes

| Route | Responsibility |
| --- | --- |
| `admin/login.php` | Database-backed admin login |
| `admin/index.php` | Admin dashboard |
| `admin/course_form.php` | Create and edit courses, URL covers, uploaded covers |
| `admin/lessons.php` | Create, edit, and delete lessons |
| `admin/questions.php` | Manage questions, imports, and choice-shuffle settings |
| `admin/attempts.php` | Review learners and scores |
| `admin/users.php` | Manage admin accounts, learner names, learner password resets, and learning totals |
| `admin/certificate_settings.php` | Design certificates and reuse layouts |

## Database Model

### `courses`

Stores course metadata:

- `title`, `description`, `cover_url`
- `pass_percent`
- `shuffle_pre_choices`, `shuffle_post_choices`
- `certificate_title`
- `allow_retake`
- `access_mode`: `public` or `login_required`
- `is_published`

`cover_url` accepts either an external URL or a local relative upload path.

### `lessons`

Stores ordered course content:

- `content_type`: `html`, `video`, `embed`, or `link`
- `content`
- `allow_seek`: allow learners to seek freely; when disabled, learners may rewind but cannot skip unwatched segments
- `video_duration_seconds`: detected or manually entered video duration used for course learning-time summaries
- `sort_order`

### `questions`

Stores pre-test and post-test questions:

- `quiz_type`: `pre` or `post`
- `question_type`: `single_choice`, `multiple_choice`, `true_false`, or `short_answer`
- `choices` JSON
- `correct_answers` JSON
- `explanation`
- `sort_order`

### `attempts`

Stores learner sessions. Member attempts link to `users.id`; public attempts use a null `user_id` and are bound to the learner's browser session plus access token:

- user id, learner name, and access token
- pre-test and post-test scores
- state machine status
- certificate code after passing

Status progression:

```text
registered -> pretest_done -> learning -> posttest_done
                                      \-> passed
```

### `admin_users`

Stores separate admin accounts with hashed passwords. The initial account is bootstrapped from `ADMIN_USERNAME` and `ADMIN_PIN` only when this table is empty.

### `lesson_progress`

Records lesson completion per attempt. `completion_source` distinguishes legacy, automatic, manual, and tracked-video completion.

### `curriculum_items`

Stores the ordered learning path for a course:

- `section_id`: the curriculum section containing the item
- `item_type`: `lesson` or `quiz_set`
- `lesson_id` or `quiz_set_id`
- `sort_order`
- `requires_previous`: lock the item until all preceding items are complete

Legacy lessons and contiguous pre/post question rows are backfilled into sections and reusable quiz sets by `ensure_curriculum_tables()`.

### `curriculum_sections`

Stores editable curriculum sections. Admins can create, rename, reorder, and remove empty sections.

### `quiz_sets` and `quiz_set_questions`

Store reusable central quiz sets and their ordered questions. Quiz placement is independent from legacy pre/post labels.

### `public_quiz_shares`, `public_quiz_attempts`, and `public_quiz_answers`

Store permanent public share tokens for reusable quiz sets, theme/pass/certificate mode settings, guest quiz attempts, scores, and submitted answers. Share links have no automatic expiry and may be manually deactivated without changing their token. Each issued attempt records whether it used the course certificate template or the quiz-specific template so later configuration changes do not switch an issued certificate's template source.

### `public_quiz_certificate_settings`

Stores the versioned certificate assets, text, signer information, and normalized layout positions for a public quiz share. Quiz-specific templates use the same `1024 × 724` renderer as course certificates but do not modify `certificate_settings` for the owner course.

### `question_progress`

Records each learner answer, correctness, and completion timestamp for questions answered inside a curriculum quiz set.

### `certificate_settings`

Stores certificate assets, text, and JSON layout positions for each course.

## Business Rules

### Authentication

- Learners sign in before opening the course list or learner routes.
- Learner routes require `attempt`, `token`, and the matching signed-in user.
- Admin routes require a separate `admin_users` login session and admin navigation is hidden from learner-only sessions.
- Public learners may change their own password. Students sign in with their 13-digit citizen ID, which is verified against the external ศกร. system.
- Courses may allow retakes. A retake never issues a second certificate after the learner has received one for that course.
- Admin routes require `require_admin()`.

### Ordered Curriculum and Video Gate

- Lessons, media, and reusable quiz sets share one ordered curriculum divided into editable sections.
- Admins can drag curriculum items across sections and toggle whether preceding items are required.
- Non-video lessons are completed when the learner clicks the completion button.
- Quiz sets are completed inline in the learning path after the learner answers every question in the set.
- Native video lessons are marked complete on the browser `ended` event.
- YouTube embeds use the iframe API and are marked complete on `YT.PlayerState.ENDED`.
- After tracked video completion, the player saves progress and opens the next incomplete curriculum item automatically.
- Admins can allow free seeking or prevent learners from skipping unwatched video segments.
- Admins can detect video durations from native video URLs and YouTube media, with manual seconds as a fallback.
- Learner course cards show the total learning time from saved lesson video durations.
- Server-side completion endpoints reject attempts to bypass locked items.

### Quiz Behavior

- Admins create central quiz sets and place those sets anywhere in the curriculum.
- Legacy pre/post fields remain only for database and import compatibility.
- Scoring is server-side in `score_curriculum_question()`.
- Multiple-choice answers are sorted before comparison.
- Short answers are trimmed, lowercased, and whitespace-normalized.
- Admins can publish a permanent no-login link for a quiz set and download its QR Code.
- Public quiz attempts use their own access token and never bypass a normal course attempt or curriculum gate.
- Public quizzes support five visual themes and may issue a certificate from the owner course template when the configured pass threshold is met.
- Public quiz certificate mode is `none`, `course`, or `custom`. Custom mode has an independent certificate designer and can copy a course template as a visual starting point without replacing quiz-specific title or body text.

### Course Cover Images

- Admins can paste an external image URL or upload PNG, JPG, or WEBP.
- Uploaded files are stored under `storage/uploads/course-covers/`.
- Uploaded files take precedence when a URL and file are submitted together.
- The landing page uses `.course-thumb img` with `object-fit: cover`.
- Without a cover, the generated gradient placeholder remains visible.

### Certificates

- Certificate assets are stored under `storage/uploads/certificates/`.
- The designer stores positions in JSON.
- Designer preview, learner output, PNG export, and PDF export use the same fixed `1024 × 724` design surface.
- Admins can copy an existing layout from another course.
- Learner output and PDF export must match designer preview.
- Legacy signature dimensions are normalized from the source image aspect ratio.

## Key Helpers

| Helper Group | Important Functions |
| --- | --- |
| HTTP and auth | `e()`, `post()`, `get_int()`, `flash()`, `require_admin()`, `require_attempt()` |
| Attempts | `get_or_create_attempt()`, `attempt_url()`, `finalize_curriculum_attempt()` |
| Curriculum | `ensure_curriculum_tables()`, `curriculum_items()`, `curriculum_summary()` |
| Quiz | `save_curriculum_question_answer()`, `score_curriculum_question()` |
| Lessons | `mark_lesson_completed()`, `lesson_requires_video_completion()` |
| Covers and assets | `public_upload_url()`, `save_course_cover_upload()`, `save_certificate_upload()` |
| Certificates | `get_certificate_settings()`, `clean_certificate_positions()`, `copy_certificate_layout_positions()` |
| Compatibility migrations | `ensure_curriculum_tables()`, `ensure_course_quiz_shuffle_columns()`, `ensure_lesson_progress_completion_source()`, `ensure_lesson_video_settings_columns()`, `ensure_learning_access_columns()` |

## Storage

```text
storage/
├── templates/
├── uploads/
│   ├── certificates/
│   └── course-covers/
└── certificates/
```

Treat uploaded files as user data. Test uploads must be removed after verification.

## Known Constraints

- Tailwind loads from CDN.
- Certificate designer export libraries load from CDN.
- Admin passwords are hashed in `admin_users`; the bootstrap password should be changed after first login.
- Some migrations are applied lazily by `ensure_*()` helpers for compatibility with an existing local database.
- Browser automation may block local URLs. Use route-level HTTP checks when in-app visual QA is unavailable and report the limitation.
