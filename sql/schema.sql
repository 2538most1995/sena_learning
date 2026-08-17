CREATE DATABASE IF NOT EXISTS sena_learning CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sena_learning;

CREATE TABLE IF NOT EXISTS courses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    category VARCHAR(60) NOT NULL DEFAULT 'lifelong',
    cover_url VARCHAR(500) NULL,
    pass_percent DECIMAL(5,2) NOT NULL DEFAULT 80,
    allow_retake TINYINT(1) NOT NULL DEFAULT 0,
    shuffle_pre_choices TINYINT(1) NOT NULL DEFAULT 0,
    shuffle_post_choices TINYINT(1) NOT NULL DEFAULT 0,
    certificate_title VARCHAR(255) NOT NULL DEFAULT 'เกียรติบัตรการผ่านหลักสูตร',
    access_mode VARCHAR(20) NOT NULL DEFAULT 'login_required',
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lessons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    content_type ENUM('html','video','embed','link') NOT NULL DEFAULT 'html',
    content MEDIUMTEXT NOT NULL,
    allow_seek TINYINT(1) NOT NULL DEFAULT 1,
    video_duration_seconds INT UNSIGNED NULL,
    sort_order INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT lessons_course_fk FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS questions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id INT UNSIGNED NOT NULL,
    quiz_type ENUM('pre','post') NOT NULL,
    question_type ENUM('single_choice','multiple_choice','true_false','short_answer') NOT NULL DEFAULT 'single_choice',
    prompt TEXT NOT NULL,
    choices JSON NULL,
    correct_answers JSON NOT NULL,
    explanation TEXT NULL,
    sort_order INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT questions_course_fk FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_type ENUM('general','student') NOT NULL DEFAULT 'general',
    email VARCHAR(255) NULL,
    password_hash VARCHAR(255) NULL,
    google_id VARCHAR(100) NULL,
    line_id VARCHAR(100) NULL,
    student_id VARCHAR(20) NULL,
    citizen_id_masked VARCHAR(13) NULL,
    display_name VARCHAR(255) NOT NULL,
    avatar_url VARCHAR(500) NULL,
    skr_group_code VARCHAR(20) NULL,
    skr_class_name VARCHAR(255) NULL,
    skr_district_id INT NULL,
    skr_district_name VARCHAR(100) NULL,
    skr_level VARCHAR(10) NULL,
    skr_level_name VARCHAR(100) NULL,
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY users_email_unique (email),
    UNIQUE KEY users_google_id_unique (google_id),
    UNIQUE KEY users_line_id_unique (line_id),
    UNIQUE KEY users_student_id_unique (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    learner_name VARCHAR(255) NOT NULL,
    access_token VARCHAR(64) NOT NULL UNIQUE,
    pre_score INT NULL,
    pre_total INT NULL,
    post_score INT NULL,
    post_total INT NULL,
    certificate_code VARCHAR(80) NULL,
    status ENUM('registered','pretest_done','learning','posttest_done','passed') NOT NULL DEFAULT 'registered',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT attempts_course_fk FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT attempts_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_sets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    shuffle_questions TINYINT(1) NOT NULL DEFAULT 0,
    shuffle_choices TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT quiz_sets_course_fk FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_set_questions (
    quiz_set_id INT UNSIGNED NOT NULL,
    question_id INT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 1,
    PRIMARY KEY (quiz_set_id, question_id),
    KEY quiz_set_question_lookup (question_id),
    CONSTRAINT quiz_set_questions_set_fk FOREIGN KEY (quiz_set_id) REFERENCES quiz_sets(id) ON DELETE CASCADE,
    CONSTRAINT quiz_set_questions_question_fk FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS curriculum_sections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    sort_order INT NOT NULL DEFAULT 10,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY curriculum_sections_course_order (course_id, sort_order, id),
    CONSTRAINT curriculum_sections_course_fk FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS curriculum_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id INT UNSIGNED NOT NULL,
    section_id INT UNSIGNED NOT NULL,
    item_type ENUM('lesson','quiz_set') NOT NULL,
    lesson_id INT UNSIGNED NULL,
    quiz_set_id INT UNSIGNED NULL,
    sort_order INT NOT NULL DEFAULT 10,
    requires_previous TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY curriculum_lesson_unique (lesson_id),
    KEY curriculum_quiz_set_lookup (quiz_set_id),
    KEY curriculum_course_order (course_id, sort_order, id),
    CONSTRAINT curriculum_course_fk FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT curriculum_section_fk FOREIGN KEY (section_id) REFERENCES curriculum_sections(id) ON DELETE CASCADE,
    CONSTRAINT curriculum_lesson_fk FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
    CONSTRAINT curriculum_quiz_set_fk FOREIGN KEY (quiz_set_id) REFERENCES quiz_sets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lesson_progress (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT UNSIGNED NOT NULL,
    lesson_id INT UNSIGNED NOT NULL,
    completed_at TIMESTAMP NULL,
    completion_source VARCHAR(20) NOT NULL DEFAULT 'legacy',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY lesson_progress_unique (attempt_id, lesson_id),
    CONSTRAINT lesson_progress_attempt_fk FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE,
    CONSTRAINT lesson_progress_lesson_fk FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question_progress (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT UNSIGNED NOT NULL,
    curriculum_item_id INT UNSIGNED NULL,
    question_id INT UNSIGNED NOT NULL,
    submitted_answers JSON NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY question_progress_item_unique (attempt_id, curriculum_item_id, question_id),
    KEY question_progress_attempt_lookup (attempt_id),
    KEY question_progress_item_lookup (curriculum_item_id),
    KEY question_progress_question_lookup (question_id),
    CONSTRAINT question_progress_attempt_fk FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE,
    CONSTRAINT question_progress_item_fk FOREIGN KEY (curriculum_item_id) REFERENCES curriculum_items(id) ON DELETE CASCADE,
    CONSTRAINT question_progress_question_fk FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS certificate_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id INT UNSIGNED NOT NULL UNIQUE,
    background_image VARCHAR(500) NULL,
    logo_image VARCHAR(500) NULL,
    signature_image VARCHAR(500) NULL,
    issuer_name VARCHAR(255) NOT NULL DEFAULT 'SENA Learning Center',
    signature_name VARCHAR(255) NOT NULL DEFAULT 'ผู้อำนวยการหลักสูตร',
    title_text VARCHAR(255) NOT NULL DEFAULT 'เกียรติบัตรการผ่านหลักสูตร',
    body_text TEXT NULL,
    positions JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT certificate_settings_course_fk FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
