# SENA Learning AI Team Guide

## Scope

These instructions apply to the entire repository.

Read `CONTEXT.md` for system behavior and `SKILL.md` for the implementation workflow before making changes.

## Team Model

One AI agent may perform all roles sequentially. When multiple agents are available, assign work by subsystem and keep one coordinator responsible for the final integrated result.

## Roles

### 1. Coordinator

Own the user request from discovery through verification.

Analyze:

- What the user expects to see.
- Which routes, helpers, database fields, and CSS rules are involved.
- Whether the task changes existing data or requires migration.
- What evidence is needed before reporting completion.

Deliver:

- A scoped plan.
- Integration decisions.
- Final test summary and remaining risks.

### 2. Product Flow Analyst

Own learner-facing rules and state transitions.

Analyze:

- `start.php`, `quiz.php`, `lesson.php`, `result.php`, `certificate.php`
- Attempt statuses and redirect behavior
- Video gate behavior
- Pass criteria and certificate eligibility

Watch for:

- Learners bypassing the post-test gate
- Broken redirects
- Incorrect progress states
- Certificates becoming available before passing

### 3. Backend and Data Specialist

Own schema, PDO queries, uploads, and compatibility migrations.

Analyze:

- `config/database.php`
- `includes/helpers.php`
- `sql/schema.sql`
- `sql/seed.sql`

Watch for:

- Unsafe SQL construction
- Missing fresh-install schema changes
- Non-idempotent `ensure_*()` migrations
- Upload MIME validation failures
- Test data or temporary files left behind

### 4. Admin Experience Specialist

Own administrative workflows.

Analyze:

- `admin/course_form.php`
- `admin/lessons.php`
- `admin/questions.php`
- `admin/attempts.php`
- `admin/certificate_settings.php`

Watch for:

- Forms that cannot preserve existing values
- Destructive actions without confirmation
- Settings that save but do not affect learner output
- Missing success or error feedback

### 5. Frontend and QA Specialist

Own rendered output, responsive layout, and interaction checks.

Analyze:

- `index.php`
- `assets/css/app.css`
- Browser-rendered HTML
- Console warnings and errors when Browser access is available

Watch for:

- Missing images
- Broken external URLs
- Overflow on mobile
- CSS placeholders overlaying real images
- Preview and export mismatches

### 6. Certificate Specialist

Own certificate layout integrity.

Analyze:

- `admin/certificate_settings.php`
- `certificate.php`
- Certificate helpers in `includes/helpers.php`
- `assets/css/app.css`

Watch for:

- Different CSS between preview and output
- Image aspect-ratio distortion
- Legacy dimensions producing oversized signatures
- PDF export cloning a different layout from preview
- Layout copying replacing target-course assets unexpectedly

### 7. Security Reviewer

Perform a final pass for input and output boundaries.

Analyze:

- Authentication checks
- Attempt tokens
- Uploaded file handling
- Raw HTML rendering
- External URLs
- SQL parameter binding

Watch for:

- Missing `require_admin()`
- Learner routes that do not call `require_attempt()`
- User text rendered without `e()`
- Upload validation based only on filename
- Unintended raw HTML execution

## Task Routing

| Request Type | Lead Role | Required Review |
| --- | --- | --- |
| Course metadata or cover image | Admin Experience | Backend and Data, Frontend and QA |
| Lesson content or video unlock | Product Flow | Backend and Data, Frontend and QA |
| Quiz import, scoring, or randomization | Backend and Data | Product Flow, Admin Experience |
| Certificate preview, PDF, or layout reuse | Certificate Specialist | Frontend and QA, Backend and Data |
| Schema or migration | Backend and Data | Coordinator, Security Reviewer |
| Login, token, or upload hardening | Security Reviewer | Backend and Data |

## Handoff Format

When handing work to another role, include:

```text
Goal:
Affected files:
Observed behavior:
Expected behavior:
Data mutation risk:
Checks already run:
Open questions:
```

Do not pass assumptions as facts. Point to code or route evidence.

## Review Checklist

### Behavior

- Does the learner flow still work end to end?
- Does the admin setting produce the expected learner-facing result?
- Are redirects and flash messages correct?

### Data

- Does `sql/schema.sql` support a fresh install?
- Does an existing database migrate safely?
- Are mutations tested with rollback or cleanup?

### Security

- Are admin routes protected?
- Are learner attempt tokens validated?
- Are uploads checked by MIME type?
- Is rendered user text escaped?

### UI

- Does the rendered HTML contain the expected controls?
- Are configured images rendered without placeholder overlays?
- Does the layout remain usable on smaller screens?

### Verification

- Run `php -l` on changed PHP files.
- Exercise affected helpers.
- Exercise at least one affected HTTP route when practical.
- Record Browser policy blockers instead of claiming visual QA.
- Confirm temporary servers, rows, and uploads are cleaned up.

## Definition of Done

A task is done only when:

1. The requested behavior is implemented.
2. Existing behavior remains compatible.
3. Fresh installation schema is updated when needed.
4. Existing local database compatibility is handled when needed.
5. Focused verification passes.
6. Test artifacts are removed.
7. The final report states changes, checks, and any remaining limitation.
