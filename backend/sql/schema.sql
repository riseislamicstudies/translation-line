-- سکوی رایز — اسکیمای MariaDB 10.6 (تبدیل‌شده از Postgres/Supabase)
-- نکته‌ها: همهٔ زمان‌ها UTC هستند (DATETIME(3)); شناسه‌ها UUID به‌صورت CHAR(36)؛ آرایه/JSON به‌صورت JSON.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------- احراز هویت (جایگزین auth.users و نشست‌های Supabase) ----------
CREATE TABLE IF NOT EXISTS auth_users (
  id            CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  email         VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  password_hash VARCHAR(255) CHARACTER SET ascii NOT NULL,
  banned_until  DATETIME(3) NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_login_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY auth_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sessions (
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,   -- sha256 توکن
  user_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  expires_at DATETIME(3) NOT NULL,
  last_seen  DATETIME(3) NULL,
  ua         VARCHAR(255) NULL,
  PRIMARY KEY (token_hash),
  KEY sessions_user (user_id),
  KEY sessions_exp (expires_at),
  CONSTRAINT sessions_user_fk FOREIGN KEY (user_id) REFERENCES auth_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- جدول‌های برنامه ----------
CREATE TABLE IF NOT EXISTS profiles (
  id         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  full_name  VARCHAR(255) NOT NULL DEFAULT '',
  role       ENUM('none','admin','reviewer','translator','supervisor','writer','news_editor','journal_writer') NOT NULL DEFAULT 'none',
  specialties JSON NOT NULL DEFAULT '[]',
  notify     TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  avatar_url TEXT NULL,
  avatar_original_url TEXT NULL,   -- عکس پروفایل با کیفیت اصلی (avatar_url بندانگشتی است)
  PRIMARY KEY (id),
  CONSTRAINT profiles_auth_fk FOREIGN KEY (id) REFERENCES auth_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS prompts (
  id         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name       VARCHAR(190) NOT NULL,
  version    INT NOT NULL,
  model      VARCHAR(190) NOT NULL DEFAULT 'gemini-2.5-flash',
  body       MEDIUMTEXT NOT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 0,
  created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  active_key TINYINT AS (IF(is_active = 1, 1, NULL)) PERSISTENT,   -- معادل one_active_prompt
  PRIMARY KEY (id),
  UNIQUE KEY prompts_name_version (name, version),
  UNIQUE KEY one_active_prompt (active_key),
  CONSTRAINT prompts_created_by_fk FOREIGN KEY (created_by) REFERENCES profiles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS documents (
  id                  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title               TEXT NOT NULL,
  source_filename     TEXT NULL,
  source_path         TEXT NULL,
  status              ENUM('content_review','review_rejected','translating','translation_failed','in_pool','in_progress','awaiting_qa','changes_requested','approved','published','news_edit') NOT NULL DEFAULT 'content_review',
  word_count          INT NOT NULL DEFAULT 0,
  specialty           TEXT NULL,
  uploaded_by         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  reviewed_by         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  claimed_by          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  claimed_at          DATETIME(3) NULL,
  claim_expires_at    DATETIME(3) NULL,
  supervisor_id       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  prompt_id           CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  ai_model            TEXT NULL,
  ai_started_at       DATETIME(3) NULL,
  ai_finished_at      DATETIME(3) NULL,
  ai_error            TEXT NULL,
  glossary            JSON NOT NULL DEFAULT '[]',
  ai_notes            JSON NOT NULL DEFAULT '[]',
  review_note         TEXT NULL,
  qa_note             TEXT NULL,
  created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  priority            SMALLINT NULL,
  priority_by         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  priority_at         DATETIME(3) NULL,
  source_kind         ENUM('scholaria','news','journal') NOT NULL DEFAULT 'scholaria',
  replaced_by         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  source_url          TEXT NULL,
  source_name         TEXT NULL,
  source_published_at DATETIME(3) NULL,
  editor_note         TEXT NULL,
  news_edited_by      CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  news_edited_at      DATETIME(3) NULL,
  ai_review_state     VARCHAR(40) NULL,
  ai_review_error     TEXT NULL,
  ai_review_at        DATETIME(3) NULL,
  senior_only         TINYINT(1) NOT NULL DEFAULT 0,
  published_by        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  published_at        DATETIME(3) NULL,
  publish_title       TEXT NULL,
  wp_post_id          BIGINT NULL,
  wp_url              TEXT NULL,
  wp_status           VARCHAR(40) NULL,
  wp_error            TEXT NULL,
  wp_sent_at          DATETIME(3) NULL,
  unpublish_note      TEXT NULL,
  pub_slug            TEXT NULL,
  pub_excerpt         TEXT NULL,
  pub_tags            JSON NOT NULL DEFAULT '[]',
  pub_category        BIGINT NULL,
  pub_seo_title       TEXT NULL,
  pub_seo_desc        TEXT NULL,
  pub_seo_focus       TEXT NULL,
  pub_at              DATETIME(3) NULL,
  news_score          SMALLINT NULL,
  news_score_reason   TEXT NULL,
  news_curated_at     DATETIME(3) NULL,
  pub_author          TEXT NULL,
  pub_author_id       BIGINT NULL,
  source_url_key      CHAR(32) CHARACTER SET ascii AS (IF(source_url IS NULL, NULL, MD5(source_url))) PERSISTENT, -- معادل documents_source_url_uniq
  PRIMARY KEY (id),
  UNIQUE KEY documents_source_url_uniq (source_url_key),
  KEY documents_claimed_by_idx (claimed_by),
  KEY documents_priority_idx (priority),
  KEY documents_source_kind_idx (source_kind),
  KEY documents_status_idx (status),
  CONSTRAINT documents_priority_ck CHECK (priority IS NULL OR (priority >= 1 AND priority <= 5)),
  CONSTRAINT documents_news_score_ck CHECK (news_score IS NULL OR (news_score >= 0 AND news_score <= 100)),
  CONSTRAINT documents_claimed_by_fk   FOREIGN KEY (claimed_by)     REFERENCES profiles(id),
  CONSTRAINT documents_news_edited_fk  FOREIGN KEY (news_edited_by) REFERENCES profiles(id) ON DELETE SET NULL,
  CONSTRAINT documents_priority_by_fk  FOREIGN KEY (priority_by)    REFERENCES profiles(id),
  CONSTRAINT documents_prompt_fk       FOREIGN KEY (prompt_id)      REFERENCES prompts(id),
  CONSTRAINT documents_published_by_fk FOREIGN KEY (published_by)   REFERENCES profiles(id) ON DELETE SET NULL,
  CONSTRAINT documents_replaced_by_fk  FOREIGN KEY (replaced_by)    REFERENCES documents(id) ON DELETE SET NULL,
  CONSTRAINT documents_reviewed_by_fk  FOREIGN KEY (reviewed_by)    REFERENCES profiles(id),
  CONSTRAINT documents_supervisor_fk   FOREIGN KEY (supervisor_id)  REFERENCES profiles(id),
  CONSTRAINT documents_uploaded_by_fk  FOREIGN KEY (uploaded_by)    REFERENCES profiles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS segments (
  id               CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  document_id      CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `position`       INT NOT NULL,
  kind             ENUM('heading','paragraph','list_item','footnote','quote') NOT NULL DEFAULT 'paragraph',
  `level`          TINYINT NULL,                    -- سطح تیتر (۱ تا ۶) برای kind=heading
  source_text      MEDIUMTEXT NOT NULL,
  ai_text          MEDIUMTEXT NULL,
  current_text     MEDIUMTEXT NULL,
  flagged          TINYINT(1) NOT NULL DEFAULT 0,
  seconds_spent    INT NOT NULL DEFAULT 0,
  last_edited_by   CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  last_edited_at   DATETIME(3) NULL,
  ai_review_text   MEDIUMTEXT NULL,
  ai_review_note   TEXT NULL,
  ai_review_round  INT NULL,
  ai_review_at     DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY segments_doc_pos (document_id, `position`),
  CONSTRAINT segments_doc_fk    FOREIGN KEY (document_id)    REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT segments_editor_fk FOREIGN KEY (last_edited_by) REFERENCES profiles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS segment_revisions (
  id          BIGINT NOT NULL AUTO_INCREMENT,
  segment_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  document_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  revision_no INT NOT NULL,
  `text`      MEDIUMTEXT NOT NULL,
  source      ENUM('ai','translator','supervisor') NOT NULL,
  author_id   CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY segment_revisions_seg_rev (segment_id, revision_no),
  KEY segment_revisions_doc_idx (document_id),
  CONSTRAINT revisions_segment_fk  FOREIGN KEY (segment_id)  REFERENCES segments(id)  ON DELETE CASCADE,
  CONSTRAINT revisions_document_fk FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT revisions_author_fk   FOREIGN KEY (author_id)   REFERENCES profiles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS segment_comments (
  id          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  segment_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  document_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  author_id   CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  body        TEXT NOT NULL,
  resolved    TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  batch_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  batch_lead  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  CONSTRAINT comments_segment_fk  FOREIGN KEY (segment_id)  REFERENCES segments(id)  ON DELETE CASCADE,
  CONSTRAINT comments_document_fk FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT comments_author_fk   FOREIGN KEY (author_id)   REFERENCES profiles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
  id          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  type        VARCHAR(80) NOT NULL,
  document_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  payload     JSON NOT NULL DEFAULT '{}',
  read_at     DATETIME(3) NULL,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY notifications_user_idx (user_id, created_at),
  CONSTRAINT notifications_user_fk FOREIGN KEY (user_id)     REFERENCES profiles(id)  ON DELETE CASCADE,
  CONSTRAINT notifications_doc_fk  FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS document_events (
  id          BIGINT NOT NULL AUTO_INCREMENT,
  document_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actor_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  from_status VARCHAR(40) NULL,
  to_status   VARCHAR(40) NOT NULL,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY document_events_doc (document_id),
  CONSTRAINT events_document_fk FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS priority_history (
  id          BIGINT NOT NULL AUTO_INCREMENT,
  document_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  priority    SMALLINT NOT NULL,
  changed_by  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  changed_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  CONSTRAINT priority_history_ck CHECK (priority >= 1 AND priority <= 5),
  CONSTRAINT priority_history_doc_fk FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT priority_history_by_fk  FOREIGN KEY (changed_by)  REFERENCES profiles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_config (
  `key`      VARCHAR(190) NOT NULL,
  `value`    MEDIUMTEXT NOT NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_secrets (
  name       VARCHAR(190) NOT NULL,
  `value`    MEDIUMTEXT NOT NULL,
  updated_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (name),
  CONSTRAINT app_secrets_by_fk FOREIGN KEY (updated_by) REFERENCES profiles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invites (
  id         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  token      VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  role       VARCHAR(40) NOT NULL DEFAULT 'writer',
  label      TEXT NULL,
  created_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  expires_at DATETIME(3) NULL,
  max_uses   INT NULL,
  uses       INT NOT NULL DEFAULT 0,
  revoked    TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY invites_token_key (token),
  CONSTRAINT invites_role_ck CHECK (role = 'writer'),
  CONSTRAINT invites_max_ck CHECK (max_uses IS NULL OR max_uses > 0),
  CONSTRAINT invites_by_fk FOREIGN KEY (created_by) REFERENCES profiles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS profile_contacts (
  id         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  phone      VARCHAR(80) NULL,
  email      VARCHAR(255) NULL,
  first_name VARCHAR(255) NULL,
  last_name  VARCHAR(255) NULL,
  invite_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  PRIMARY KEY (id),
  KEY profile_contacts_invite_idx (invite_id),
  CONSTRAINT contacts_profile_fk FOREIGN KEY (id)        REFERENCES profiles(id) ON DELETE CASCADE,
  CONSTRAINT contacts_invite_fk  FOREIGN KEY (invite_id) REFERENCES invites(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS glossary (
  id          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_term TEXT NOT NULL,
  target_term TEXT NOT NULL,
  note        TEXT NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_by  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  source_key  CHAR(32) CHARACTER SET ascii AS (MD5(LOWER(TRIM(source_term)))) PERSISTENT, -- معادل glossary_source_uniq
  PRIMARY KEY (id),
  UNIQUE KEY glossary_source_uniq (source_key),
  CONSTRAINT glossary_src_ck CHECK (CHAR_LENGTH(TRIM(source_term)) > 0),
  CONSTRAINT glossary_tgt_ck CHECK (CHAR_LENGTH(TRIM(target_term)) > 0),
  CONSTRAINT glossary_by_fk FOREIGN KEY (created_by) REFERENCES auth_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS news_sources (
  id              CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name            VARCHAR(255) NOT NULL,
  kind            ENUM('rss','web','push') NOT NULL DEFAULT 'rss',
  feed_url        TEXT NULL,
  link_pattern    TEXT NULL,
  body_selector   TEXT NULL,
  check_every_min INT NOT NULL DEFAULT 30,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  last_checked_at DATETIME(3) NULL,
  last_ok_at      DATETIME(3) NULL,
  last_error      TEXT NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  CONSTRAINT news_sources_every_ck CHECK (check_every_min BETWEEN 5 AND 1440),
  CONSTRAINT news_sources_name_ck  CHECK (CHAR_LENGTH(TRIM(name)) > 0),
  CONSTRAINT news_sources_url_ck   CHECK (kind = 'push' OR (feed_url IS NOT NULL AND feed_url REGEXP '^https?://'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS news_rules (
  id         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  kind       ENUM('include','exclude') NOT NULL,
  keyword    VARCHAR(500) NOT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  CONSTRAINT news_rules_kw_ck CHECK (CHAR_LENGTH(TRIM(keyword)) > 0),
  CONSTRAINT news_rules_src_fk FOREIGN KEY (source_id) REFERENCES news_sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS news_items (
  id           CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  url          TEXT NOT NULL,
  url_hash     VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title        TEXT NULL,
  status       ENUM('accepted','rejected','error') NOT NULL,
  reason       TEXT NULL,
  document_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  published_at DATETIME(3) NULL,
  fetched_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY news_items_url_hash_key (url_hash),
  KEY news_items_fetched_idx (fetched_at),
  KEY news_items_source_idx (source_id, fetched_at),
  CONSTRAINT news_items_src_fk FOREIGN KEY (source_id)   REFERENCES news_sources(id) ON DELETE SET NULL,
  CONSTRAINT news_items_doc_fk FOREIGN KEY (document_id) REFERENCES documents(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS translator_levels (
  user_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  level      ENUM('regular','senior') NOT NULL DEFAULT 'regular',
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (user_id),
  CONSTRAINT translator_levels_fk FOREIGN KEY (user_id) REFERENCES profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS document_images (
  id             CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  document_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  path           VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  filename       TEXT NULL,
  mime           VARCHAR(100) NOT NULL,
  size_bytes     INT NOT NULL,
  kind           ENUM('featured','inline') NOT NULL DEFAULT 'inline',
  alt_text       TEXT NULL,
  caption        TEXT NULL,
  after_position INT NULL,
  uploaded_by    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  approved       TINYINT(1) NOT NULL DEFAULT 0,
  approved_by    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  approved_at    DATETIME(3) NULL,
  wp_media_id    BIGINT NULL,
  created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  wp_url         TEXT NULL,
  featured_flag  TINYINT AS (IF(kind + 0 = 1, 1, NULL)) PERSISTENT, -- ۱ = 'featured' (ستون پایه نباید کلید خارجی باشد؛ MariaDB خطای 1901 می‌دهد)
  PRIMARY KEY (id),
  UNIQUE KEY document_images_path_key (path),
  UNIQUE KEY document_images_one_featured (document_id, featured_flag),  -- حداکثر یک تصویر شاخص برای هر سند
  KEY document_images_doc (document_id),
  CONSTRAINT images_doc_fk      FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT images_uploader_fk FOREIGN KEY (uploaded_by) REFERENCES profiles(id)  ON DELETE SET NULL,
  CONSTRAINT images_approver_fk FOREIGN KEY (approved_by) REFERENCES profiles(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS push_subscriptions (
  id         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  endpoint   TEXT NOT NULL,
  endpoint_key CHAR(32) CHARACTER SET ascii AS (MD5(endpoint)) PERSISTENT,
  p256dh     TEXT NOT NULL,
  auth       TEXT NOT NULL,
  ua         TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY push_subscriptions_endpoint_key (endpoint_key),
  CONSTRAINT push_user_fk FOREIGN KEY (user_id) REFERENCES auth_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- جدول‌های تازه (جایگزین ابزارهای Supabase) ----------
-- صف کارهای پس‌زمینه (ترجمه، بازبینی هوش مصنوعی، خبر، push). به‌جای تابع‌های لبهٔ طولانی.
CREATE TABLE IF NOT EXISTS jobs (
  id          BIGINT NOT NULL AUTO_INCREMENT,
  kind        VARCHAR(60) NOT NULL,                 -- translate | ai_review | ai_rewrite | news_poll | push
  ref_id      CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  payload     JSON NOT NULL DEFAULT '{}',
  state       ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  attempts    INT NOT NULL DEFAULT 0,
  run_after   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  locked_until DATETIME(3) NULL,
  progress    JSON NULL,
  error       TEXT NULL,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY jobs_pick (state, run_after),
  KEY jobs_ref (kind, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- محدودیت تلاش ورود (جلوگیری از حدس رمز)
CREATE TABLE IF NOT EXISTS login_attempts (
  id         BIGINT NOT NULL AUTO_INCREMENT,
  ip         VARCHAR(64) CHARACTER SET ascii NOT NULL,
  email      VARCHAR(255) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY login_attempts_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
