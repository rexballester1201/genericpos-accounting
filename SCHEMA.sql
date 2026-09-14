-- =============================================================================
-- GenericPOS Accounting — COMPLETE DATABASE SCHEMA
-- =============================================================================
-- A standalone general ledger · CodeIgniter 3 + SPA/PWA
-- MariaDB 10.2.1+ or MySQL 8.0.16+ (the first to enforce CHECK) · utf8mb4 · InnoDB
--
-- Apply to a NEW, EMPTY database (phpMyAdmin → Import, or
--   mysql -u <user> -p <db> < SCHEMA.sql). There is no CREATE DATABASE / USE:
-- select the target database first (cPanel prefixes database names).
--
-- NOTE: NO DROP TABLE AND NO IF NOT EXISTS. Pointed at a database that already
-- has these tables this file fails on the first CREATE, loudly — which is the
-- point. Changes to an existing database go in a MIGRATE-*.sql file.
--
-- ─── WHAT IS NOT HERE ────────────────────────────────────────────────────
--   · NO administrator account — /setup (first run) or
--     `php index.php tools create_admin <email>`.
--   · NO chart of accounts — setup seeds one from Chart_templates.
--   · NO gp_settings rows — that table holds OVERRIDES of config/app.php only.
--
-- ─── RULES THIS SCHEMA IS BUILT ON ───────────────────────────────────────
-- 1. NO DEFAULT CURRENT_TIMESTAMP / ON UPDATE. PHP writes every timestamp, in
--    UTC (index.php pins it). Business dates (entry_date, doc_date) are DATEs
--    in the company's calendar.
-- 2. Table names carry gp_ literally and database.php's dbprefix is EMPTY.
-- 3. utf8mb4, never utf8.
-- 4. MONEY IS AN INTEGER NUMBER OF MINOR UNITS (centavos), in BIGINT columns
--    named *_cents. Never a float, never DECIMAL arithmetic in PHP.
-- 5. A POSTED JOURNAL IS PERMANENT: never updated (except its reversal link)
--    and never deleted. Balances are SUMs over the gp_ledger view, never
--    stored. The audit log is append-only.
-- =============================================================================

SET NAMES utf8mb4;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';


-- #############################################################################
-- SECTION 1 — USERS AND SIGN-IN   (kept from GenericPOS)
-- #############################################################################

-- Roles, lowest to highest: viewer (reports) < bookkeeper (prepares) <
-- accountant (approves and posts, closes months) < admin (chart, years,
-- users, settings). There is no self sign-up; an admin creates every account.
CREATE TABLE `gp_users` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`                VARCHAR(190)  DEFAULT NULL,     -- stored lowercased
  `username`             VARCHAR(32)   NOT NULL,         -- stored lowercased
  `full_name`            VARCHAR(120)  DEFAULT NULL,
  `phone`                VARCHAR(20)   DEFAULT NULL,     -- E.164
  `password_hash`        VARCHAR(255)  DEFAULT NULL,

  `role`                 ENUM('viewer','bookkeeper','accountant','admin') NOT NULL DEFAULT 'viewer',
  `account_state`        ENUM('active','suspended','closed') NOT NULL DEFAULT 'active',
  `auth_method`          VARCHAR(20)   NOT NULL DEFAULT 'password',
  `created_via`          VARCHAR(16)   NOT NULL DEFAULT 'admin',   -- admin | setup | cli | seed

  `is_email_verified`    TINYINT(1)    NOT NULL DEFAULT 0,
  `is_phone_verified`    TINYINT(1)    NOT NULL DEFAULT 0,
  `email_verify_hash`    CHAR(64)      DEFAULT NULL,
  `email_verify_expires` DATETIME      DEFAULT NULL,
  `marketing_opt_in`     TINYINT(1)    NOT NULL DEFAULT 0,
  `staff_note`           VARCHAR(500)  DEFAULT NULL,

  `avatar_file`          VARCHAR(64)   DEFAULT NULL,
  `avatar_updated_at`    DATETIME      DEFAULT NULL,
  `username_changed_at`  DATETIME      DEFAULT NULL,
  `password_changed_at`  DATETIME      DEFAULT NULL,
  `last_login_at`        DATETIME      DEFAULT NULL,
  `last_login_ip`        VARCHAR(45)   DEFAULT NULL,
  `created_at`           DATETIME      NOT NULL,
  `updated_at`           DATETIME      NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email`    (`email`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_phone`    (`phone`),
  KEY `idx_users_role`   (`role`, `account_state`),
  KEY `idx_users_verify` (`email_verify_hash`),
  KEY `idx_users_name`   (`full_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gp_username_history` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED    NOT NULL,
  `username`    VARCHAR(32)     NOT NULL,
  `held_from`   DATETIME        NOT NULL,
  `held_until`  DATETIME        NOT NULL,
  `changed_ip`  VARCHAR(45)     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_uh_user` (`user_id`),
  KEY `idx_uh_name` (`username`, `held_until`),
  CONSTRAINT `fk_uh_user` FOREIGN KEY (`user_id`) REFERENCES `gp_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed sign-in throttle. scope_key = 'acct:<id>' | 'ip:<addr>'. The UNIQUE
-- key is REQUIRED — record_failure() is an atomic upsert.
CREATE TABLE `gp_login_attempts` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope_key`    VARCHAR(190) NOT NULL,
  `attempts`     INT UNSIGNED NOT NULL DEFAULT 0,
  `window_start` DATETIME     NOT NULL,
  `locked_until` DATETIME     DEFAULT NULL,
  `updated_at`   DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attempts_scope`  (`scope_key`),
  KEY `idx_attempts_locked` (`locked_until`),
  KEY `idx_attempts_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Refresh tokens, stored as SHA-256 — a database read is not a session takeover.
CREATE TABLE `gp_refresh_tokens` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED    NOT NULL,
  `token_hash` CHAR(64)        NOT NULL,
  `expires_at` DATETIME        NOT NULL,
  `revoked_at` DATETIME        DEFAULT NULL,
  `user_agent` VARCHAR(255)    DEFAULT NULL,
  `created_ip` VARCHAR(45)     DEFAULT NULL,
  `created_at` DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_refresh_hash` (`token_hash`),
  KEY `idx_refresh_user`    (`user_id`),
  KEY `idx_refresh_expires` (`expires_at`),
  CONSTRAINT `fk_refresh_user` FOREIGN KEY (`user_id`) REFERENCES `gp_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gp_password_resets` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED    NOT NULL,
  `token_hash`   CHAR(64)        NOT NULL,
  `expires_at`   DATETIME        NOT NULL,
  `used_at`      DATETIME        DEFAULT NULL,
  `voided_at`    DATETIME        DEFAULT NULL,
  `requested_ip` VARCHAR(45)     DEFAULT NULL,
  `requested_ua` VARCHAR(255)    DEFAULT NULL,
  `used_ip`      VARCHAR(45)     DEFAULT NULL,
  `created_at`   DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pwreset_hash` (`token_hash`),
  KEY `idx_pwreset_user`    (`user_id`, `used_at`, `voided_at`),
  KEY `idx_pwreset_expires` (`expires_at`),
  CONSTRAINT `fk_pwreset_user` FOREIGN KEY (`user_id`) REFERENCES `gp_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- #############################################################################
-- SECTION 2 — INFRASTRUCTURE   (kept from GenericPOS)
-- #############################################################################

CREATE TABLE `gp_rate_limits` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT             NOT NULL,
  `action`       VARCHAR(64)     NOT NULL,
  `count`        INT UNSIGNED    NOT NULL DEFAULT 0,
  `window_start` DATETIME        NOT NULL,
  `updated_at`   DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rate_user_action` (`user_id`, `action`),
  KEY `idx_rate_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gp_app_state` (
  `k`          VARCHAR(64) NOT NULL,
  `v`          TEXT        NOT NULL,
  `updated_at` DATETIME    NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gap-free sequences: journal numbers ('jn:<prefix>:<fiscal year id>') and
-- document numbers ('doc:<type>'). Incremented INSIDE the posting transaction
-- (INSERT … ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1)), so a
-- rolled-back posting gives its number back.
CREATE TABLE `gp_counters` (
  `name`       VARCHAR(40)     NOT NULL,
  `value`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME        NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The audit trail: every mutation, by whom, from where. APPEND-ONLY; admin_id
-- has no FK so the record outlives a deleted account.
CREATE TABLE `gp_admin_audit_log` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`    INT UNSIGNED    NOT NULL,
  `action`      VARCHAR(64)     NOT NULL,
  `target_type` VARCHAR(32)     DEFAULT NULL,
  `target_id`   BIGINT UNSIGNED DEFAULT NULL,
  `detail`      TEXT            DEFAULT NULL,
  `ip_address`  VARCHAR(45)     DEFAULT NULL,
  `occurred_at` DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_admin`  (`admin_id`, `id`),
  KEY `idx_audit_action` (`action`, `id`),
  KEY `idx_audit_target` (`target_type`, `target_id`),
  KEY `idx_audit_time`   (`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin OVERRIDES of config/app.php. A row is deleted when its value returns
-- to the file default. Editable keys are an allow-list in Settings_model.
CREATE TABLE `gp_settings` (
  `k`          VARCHAR(64)   NOT NULL,
  `value`      TEXT          NOT NULL,
  `value_type` VARCHAR(10)   NOT NULL DEFAULT 'string',
  `group_key`  VARCHAR(32)   NOT NULL DEFAULT 'general',
  `label`      VARCHAR(120)  NOT NULL DEFAULT '',
  `hint`       VARCHAR(500)  NOT NULL DEFAULT '',
  `min_value`  DECIMAL(20,8) DEFAULT NULL,
  `max_value`  DECIMAL(20,8) DEFAULT NULL,
  `updated_by` INT UNSIGNED  DEFAULT NULL,
  `updated_at` DATETIME      NOT NULL,
  PRIMARY KEY (`k`),
  KEY `idx_settings_group` (`group_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- In-app notifications (approvals to decide, entries rejected). url is an
-- in-app ROUTE, never an absolute link.
CREATE TABLE `gp_notifications` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED    NOT NULL,
  `type`       VARCHAR(40)     NOT NULL,
  `title`      VARCHAR(120)    NOT NULL,
  `body`       VARCHAR(500)    NOT NULL DEFAULT '',
  `url`        VARCHAR(120)    DEFAULT NULL,
  `is_read`    TINYINT(1)      NOT NULL DEFAULT 0,
  `read_at`    DATETIME        DEFAULT NULL,
  `created_at` DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user`   (`user_id`, `id`),
  KEY `idx_notif_unread` (`user_id`, `is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `gp_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- #############################################################################
-- SECTION 3 — THE CHART, FISCAL YEARS, DEPARTMENTS, CONTACTS
-- #############################################################################

-- The chart of accounts. Headers group accounts and take no lines; only
-- active non-header accounts are postable. normal_side is derived from type
-- (assets and expenses debit) and flipped for a contra account.
--
-- NOTE: once an account has POSTED lines its type, normal side and control
-- flag are frozen (Account_model); a used account is deactivated, never
-- deleted. Lines reference the id, never a copy of the code, so a renamed or
-- renumbered account can never leave a stale code behind.
CREATE TABLE `gp_accounts` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`                VARCHAR(20)  NOT NULL,
  `name`                VARCHAR(160) NOT NULL,
  `parent_id`           INT UNSIGNED DEFAULT NULL,
  `is_header`           TINYINT(1)   NOT NULL DEFAULT 0,
  `type`                ENUM('asset','liability','equity','income','expense') NOT NULL,
  `subtype`             VARCHAR(20)  DEFAULT NULL,
  `is_contra`           TINYINT(1)   NOT NULL DEFAULT 0,
  `normal_side`         CHAR(1)      NOT NULL,              -- 'D' | 'C'
  `cash_flow`           VARCHAR(12)  DEFAULT NULL,          -- operating | investing | financing | cash
  `control`             VARCHAR(4)   DEFAULT NULL,          -- ar | ap
  `requires_department` TINYINT(1)   NOT NULL DEFAULT 0,
  `tags`                VARCHAR(255) NOT NULL DEFAULT '',
  `description`         VARCHAR(500) DEFAULT NULL,
  `sort_order`          INT          NOT NULL DEFAULT 0,
  `is_active`           TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`          DATETIME     NOT NULL,
  `updated_at`          DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acct_code` (`code`),
  KEY `idx_acct_parent` (`parent_id`, `sort_order`),
  KEY `idx_acct_type`   (`type`, `subtype`),
  CONSTRAINT `fk_acct_parent` FOREIGN KEY (`parent_id`) REFERENCES `gp_accounts` (`id`),
  CONSTRAINT `chk_acct_side` CHECK (`normal_side` IN ('D','C'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fiscal years start on the 1st of fiscal_year_start_month and have twelve
-- monthly periods. A year is closed by its closing entry (closing_journal_id).
CREATE TABLE `gp_fiscal_years` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`               VARCHAR(20)  NOT NULL,              -- 'FY2026' or 'FY2026-27'
  `start_date`         DATE         NOT NULL,
  `end_date`           DATE         NOT NULL,
  `status`             ENUM('open','closed') NOT NULL DEFAULT 'open',
  `closing_journal_id` BIGINT UNSIGNED DEFAULT NULL,
  `closed_by`          INT UNSIGNED DEFAULT NULL,
  `closed_at`          DATETIME     DEFAULT NULL,
  `created_at`         DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fy_name`  (`name`),
  UNIQUE KEY `uq_fy_start` (`start_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE: AN ENTRY POSTS ONLY INTO AN OPEN PERIOD OF AN OPEN YEAR. A date with
-- no period is refused — never "no period set up, post anyway".
--   open → closed (month-end) → open again (an accountant can reopen) → locked (final)
CREATE TABLE `gp_periods` (
  `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `fiscal_year_id` INT UNSIGNED     NOT NULL,
  `period_no`      TINYINT UNSIGNED NOT NULL,              -- 1..12
  `name`           VARCHAR(20)      NOT NULL,              -- 'Jan 2026'
  `start_date`     DATE             NOT NULL,
  `end_date`       DATE             NOT NULL,
  `status`         ENUM('open','closed','locked') NOT NULL DEFAULT 'open',
  `closed_by`      INT UNSIGNED     DEFAULT NULL,
  `closed_at`      DATETIME         DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_period` (`fiscal_year_id`, `period_no`),
  UNIQUE KEY `uq_period_start` (`start_date`),
  KEY `idx_period_dates` (`start_date`, `end_date`),
  CONSTRAINT `fk_period_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `gp_fiscal_years` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Departments, branches or cost centres, on journal lines and budgets.
CREATE TABLE `gp_departments` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(20)  NOT NULL,
  `name`       VARCHAR(120) NOT NULL,
  `parent_id`  INT UNSIGNED DEFAULT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL,
  `updated_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dept_code` (`code`),
  CONSTRAINT `fk_dept_parent` FOREIGN KEY (`parent_id`) REFERENCES `gp_departments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customers and suppliers (one contact can be both). Every journal line on an
-- AR or AP control account names one, so the subsidiary ledgers always equal
-- their control accounts.
CREATE TABLE `gp_contacts` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`               VARCHAR(20)  NOT NULL,
  `name`               VARCHAR(160) NOT NULL,
  `is_customer`        TINYINT(1)   NOT NULL DEFAULT 0,
  `is_supplier`        TINYINT(1)   NOT NULL DEFAULT 0,
  `tin`                VARCHAR(20)  DEFAULT NULL,
  `address`            VARCHAR(500) DEFAULT NULL,
  `contact_person`     VARCHAR(120) DEFAULT NULL,
  `email`              VARCHAR(190) DEFAULT NULL,
  `phone`              VARCHAR(40)  DEFAULT NULL,
  `terms_days`         SMALLINT UNSIGNED DEFAULT NULL,
  `credit_limit_cents` BIGINT       DEFAULT NULL,
  `default_account_id` INT UNSIGNED DEFAULT NULL,           -- revenue (customer) / expense or asset (supplier)
  `ewt_rate_bp`        SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- withholding on payments to this supplier, basis points
  `vat_registered`     TINYINT(1)   NOT NULL DEFAULT 1,
  `notes`              VARCHAR(500) DEFAULT NULL,
  `is_active`          TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`         DATETIME     NOT NULL,
  `updated_at`         DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contact_code` (`code`),
  KEY `idx_contact_name`  (`name`),
  KEY `idx_contact_roles` (`is_customer`, `is_supplier`, `is_active`),
  CONSTRAINT `fk_contact_account` FOREIGN KEY (`default_account_id`) REFERENCES `gp_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- #############################################################################
-- SECTION 4 — JOURNALS
-- #############################################################################

-- A journal (voucher). Life: draft → submitted → posted, or rejected (back to
-- the preparer) or cancelled. Only 'posted' reaches the ledger.
--
-- NOTE: journal_no is assigned AT POSTING — per book prefix, per fiscal year,
-- gap-free (gp_counters inside the posting transaction) — so an abandoned
-- draft never leaves a hole in the books.
--
-- NOTE: a posted journal is corrected by a REVERSAL: a new posted journal with
-- the sides swapped and reversal_of_id pointing back. uq_journal_reversal lets
-- a journal be reversed once.
CREATE TABLE `gp_journals` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `book`           ENUM('general','cash_receipts','cash_disbursements','sales','purchases','adjusting','closing','opening') NOT NULL,
  `journal_no`     VARCHAR(30)  DEFAULT NULL,
  `entry_date`     DATE         NOT NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `period_id`      INT UNSIGNED NOT NULL,
  `reference`      VARCHAR(60)  DEFAULT NULL,       -- OR, cheque or invoice number
  `party_name`     VARCHAR(160) DEFAULT NULL,       -- payee / payor as printed on the voucher
  `description`    VARCHAR(500) NOT NULL,
  `total_cents`    BIGINT       NOT NULL DEFAULT 0, -- = total debits = total credits
  `status`         ENUM('draft','submitted','posted','rejected','cancelled') NOT NULL DEFAULT 'draft',
  `source`         VARCHAR(20)  NOT NULL DEFAULT 'manual',
  `source_id`      BIGINT UNSIGNED DEFAULT NULL,
  `reversal_of_id` BIGINT UNSIGNED DEFAULT NULL,
  `reversed_by_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_by`     INT UNSIGNED NOT NULL,
  `submitted_by`   INT UNSIGNED DEFAULT NULL,
  `submitted_at`   DATETIME     DEFAULT NULL,
  `approved_by`    INT UNSIGNED DEFAULT NULL,       -- approved = posted
  `approved_at`    DATETIME     DEFAULT NULL,
  `rejected_by`    INT UNSIGNED DEFAULT NULL,
  `rejected_at`    DATETIME     DEFAULT NULL,
  `reject_reason`  VARCHAR(300) DEFAULT NULL,
  `cancelled_by`   INT UNSIGNED DEFAULT NULL,
  `cancelled_at`   DATETIME     DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL,
  `updated_at`     DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_journal_no`       (`journal_no`),
  UNIQUE KEY `uq_journal_reversal` (`reversal_of_id`),
  KEY `idx_j_status_date` (`status`, `entry_date`),
  KEY `idx_j_book_date`   (`book`, `entry_date`),
  KEY `idx_j_period`      (`period_id`, `status`),
  KEY `idx_j_source`      (`source`, `source_id`),
  KEY `idx_j_created_by`  (`created_by`, `status`),
  CONSTRAINT `fk_j_fy`     FOREIGN KEY (`fiscal_year_id`) REFERENCES `gp_fiscal_years` (`id`),
  CONSTRAINT `fk_j_period` FOREIGN KEY (`period_id`)      REFERENCES `gp_periods` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One side per line, enforced by the database as well as the code.
CREATE TABLE `gp_journal_lines` (
  `id`            BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `journal_id`    BIGINT UNSIGNED   NOT NULL,
  `line_no`       SMALLINT UNSIGNED NOT NULL,
  `account_id`    INT UNSIGNED      NOT NULL,
  `debit_cents`   BIGINT            NOT NULL DEFAULT 0,
  `credit_cents`  BIGINT            NOT NULL DEFAULT 0,
  `memo`          VARCHAR(255)      DEFAULT NULL,
  `department_id` INT UNSIGNED      DEFAULT NULL,
  `contact_id`    INT UNSIGNED      DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jl_line` (`journal_id`, `line_no`),
  KEY `idx_jl_account` (`account_id`, `journal_id`),
  KEY `idx_jl_contact` (`contact_id`, `account_id`),
  KEY `idx_jl_dept`    (`department_id`, `account_id`),
  CONSTRAINT `chk_jl_side` CHECK (`debit_cents` >= 0 AND `credit_cents` >= 0
                                  AND ((`debit_cents` > 0) + (`credit_cents` > 0)) = 1),
  CONSTRAINT `fk_jl_journal` FOREIGN KEY (`journal_id`)    REFERENCES `gp_journals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jl_account` FOREIGN KEY (`account_id`)    REFERENCES `gp_accounts` (`id`),
  CONSTRAINT `fk_jl_dept`    FOREIGN KEY (`department_id`) REFERENCES `gp_departments` (`id`),
  CONSTRAINT `fk_jl_contact` FOREIGN KEY (`contact_id`)    REFERENCES `gp_contacts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- THE LEDGER: posted lines with their journal's date and book. Every balance,
-- report and analysis reads this view — the one definition of "counts".
CREATE VIEW `gp_ledger` AS
  SELECT l.id AS line_id, l.journal_id, l.line_no, l.account_id,
         l.debit_cents, l.credit_cents, (l.debit_cents - l.credit_cents) AS net_cents,
         l.memo, l.department_id, l.contact_id,
         j.book, j.journal_no, j.entry_date, j.fiscal_year_id, j.period_id,
         j.reference, j.party_name, j.description, j.source, j.source_id
    FROM gp_journal_lines l
    JOIN gp_journals j ON j.id = l.journal_id
   WHERE j.status = 'posted';

-- Saved and recurring entries. lines_json: [{account_code, debit_cents,
-- credit_cents, memo, department_code, contact_code}]. A due recurring
-- template makes a DRAFT, never a posting.
CREATE TABLE `gp_journal_templates` (
  `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(120)     NOT NULL,
  `book`        ENUM('general','cash_receipts','cash_disbursements','sales','purchases','adjusting','closing','opening') NOT NULL DEFAULT 'general',
  `description` VARCHAR(500)     NOT NULL DEFAULT '',
  `reference`   VARCHAR(60)      DEFAULT NULL,
  `party_name`  VARCHAR(160)     DEFAULT NULL,
  `lines_json`  TEXT             NOT NULL,
  `recur_day`   TINYINT UNSIGNED DEFAULT NULL,      -- day of month; NULL = not recurring
  `next_date`   DATE             DEFAULT NULL,
  `is_active`   TINYINT(1)       NOT NULL DEFAULT 1,
  `created_by`  INT UNSIGNED     NOT NULL,
  `created_at`  DATETIME         NOT NULL,
  `updated_at`  DATETIME         NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tpl_next` (`is_active`, `next_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Scanned receipts, invoices and contracts, stored under uploads/attachments/
-- with random names; nothing a user typed is ever part of a path.
CREATE TABLE `gp_attachments` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_type`    ENUM('journal','document','settlement','asset') NOT NULL,
  `owner_id`      BIGINT UNSIGNED NOT NULL,
  `file`          VARCHAR(80)     NOT NULL,
  `original_name` VARCHAR(190)    NOT NULL,
  `mime`          VARCHAR(60)     NOT NULL,
  `bytes`         INT UNSIGNED    NOT NULL,
  `uploaded_by`   INT UNSIGNED    NOT NULL,
  `created_at`    DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_att_owner` (`owner_type`, `owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- #############################################################################
-- SECTION 5 — RECEIVABLES AND PAYABLES
-- #############################################################################

-- Customer invoices and credit notes; supplier bills and debit notes. Posting
-- one writes its journal (sales or purchase book) and takes its number.
-- applied_cents caches the allocations against it; open = total − applied.
CREATE TABLE `gp_documents` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `doc_type`            ENUM('invoice','credit_note','bill','debit_note') NOT NULL,
  `doc_no`              VARCHAR(30)  DEFAULT NULL,
  `contact_id`          INT UNSIGNED NOT NULL,
  `doc_date`            DATE         NOT NULL,
  `due_date`            DATE         DEFAULT NULL,
  `reference`           VARCHAR(60)  DEFAULT NULL,      -- the supplier's invoice number, a PO number
  `description`         VARCHAR(500) DEFAULT NULL,
  `prices_include_tax`  TINYINT(1)   NOT NULL DEFAULT 1,
  `net_cents`           BIGINT       NOT NULL DEFAULT 0,
  `vat_cents`           BIGINT       NOT NULL DEFAULT 0,
  `total_cents`         BIGINT       NOT NULL DEFAULT 0,
  `applied_cents`       BIGINT       NOT NULL DEFAULT 0,
  `status`              ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `journal_id`          BIGINT UNSIGNED DEFAULT NULL,
  `related_document_id` BIGINT UNSIGNED DEFAULT NULL,   -- a credit note's invoice
  `created_by`          INT UNSIGNED NOT NULL,
  `posted_by`           INT UNSIGNED DEFAULT NULL,
  `posted_at`           DATETIME     DEFAULT NULL,
  `created_at`          DATETIME     NOT NULL,
  `updated_at`          DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_no` (`doc_type`, `doc_no`),
  KEY `idx_doc_contact` (`contact_id`, `doc_type`, `status`),
  KEY `idx_doc_due`     (`status`, `due_date`),
  CONSTRAINT `fk_doc_contact` FOREIGN KEY (`contact_id`) REFERENCES `gp_contacts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gp_document_lines` (
  `id`               BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `document_id`      BIGINT UNSIGNED   NOT NULL,
  `line_no`          SMALLINT UNSIGNED NOT NULL,
  `account_id`       INT UNSIGNED      NOT NULL,
  `description`      VARCHAR(255)      NOT NULL DEFAULT '',
  `quantity`         DECIMAL(18,4)     NOT NULL DEFAULT 1,
  `unit_price_cents` BIGINT            NOT NULL DEFAULT 0,
  `amount_cents`     BIGINT            NOT NULL DEFAULT 0,   -- as typed (gross or net per the document)
  `vat_mode`         ENUM('vatable','exempt','zero_rated') NOT NULL DEFAULT 'vatable',
  `net_cents`        BIGINT            NOT NULL DEFAULT 0,
  `vat_cents`        BIGINT            NOT NULL DEFAULT 0,
  `department_id`    INT UNSIGNED      DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_docl_line` (`document_id`, `line_no`),
  CONSTRAINT `fk_docl_doc`     FOREIGN KEY (`document_id`)   REFERENCES `gp_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_docl_account` FOREIGN KEY (`account_id`)    REFERENCES `gp_accounts` (`id`),
  CONSTRAINT `fk_docl_dept`    FOREIGN KEY (`department_id`) REFERENCES `gp_departments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Receipts from customers and payments to suppliers. amount_cents is the cash
-- that moved; withholding_cents is tax withheld on top of it (EWT on a payment,
-- CWT on a receipt), so the documents are settled by amount + withholding.
CREATE TABLE `gp_settlements` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind`              ENUM('receipt','payment') NOT NULL,
  `settle_no`         VARCHAR(30)  DEFAULT NULL,
  `contact_id`        INT UNSIGNED NOT NULL,
  `settle_date`       DATE         NOT NULL,
  `cash_account_id`   INT UNSIGNED NOT NULL,
  `reference`         VARCHAR(60)  DEFAULT NULL,        -- OR number / cheque number
  `amount_cents`      BIGINT       NOT NULL,
  `withholding_cents` BIGINT       NOT NULL DEFAULT 0,
  `description`       VARCHAR(500) DEFAULT NULL,
  `status`            ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `journal_id`        BIGINT UNSIGNED DEFAULT NULL,
  `created_by`        INT UNSIGNED NOT NULL,
  `posted_by`         INT UNSIGNED DEFAULT NULL,
  `posted_at`         DATETIME     DEFAULT NULL,
  `created_at`        DATETIME     NOT NULL,
  `updated_at`        DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settle_no` (`kind`, `settle_no`),
  KEY `idx_settle_contact` (`contact_id`, `kind`, `status`),
  CONSTRAINT `fk_settle_contact` FOREIGN KEY (`contact_id`)      REFERENCES `gp_contacts` (`id`),
  CONSTRAINT `fk_settle_cash`    FOREIGN KEY (`cash_account_id`) REFERENCES `gp_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What settled what: a receipt/payment, or a credit/debit note, against an
-- invoice/bill. Exactly one of settlement_id / credit_document_id is set.
CREATE TABLE `gp_allocations` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_id`        BIGINT UNSIGNED NOT NULL,
  `settlement_id`      BIGINT UNSIGNED DEFAULT NULL,
  `credit_document_id` BIGINT UNSIGNED DEFAULT NULL,
  `amount_cents`       BIGINT          NOT NULL,
  `created_at`         DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_alloc_doc`    (`document_id`),
  KEY `idx_alloc_settle` (`settlement_id`),
  KEY `idx_alloc_credit` (`credit_document_id`),
  CONSTRAINT `chk_alloc_one_source` CHECK ((`settlement_id` IS NULL) <> (`credit_document_id` IS NULL)),
  CONSTRAINT `chk_alloc_positive`   CHECK (`amount_cents` > 0),
  CONSTRAINT `fk_alloc_doc`    FOREIGN KEY (`document_id`)        REFERENCES `gp_documents` (`id`),
  CONSTRAINT `fk_alloc_settle` FOREIGN KEY (`settlement_id`)      REFERENCES `gp_settlements` (`id`),
  CONSTRAINT `fk_alloc_credit` FOREIGN KEY (`credit_document_id`) REFERENCES `gp_documents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- #############################################################################
-- SECTION 6 — BANKING
-- #############################################################################

CREATE TABLE `gp_bank_accounts` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id`     INT UNSIGNED NOT NULL,          -- the ledger's cash account
  `bank_name`      VARCHAR(120) NOT NULL,
  `account_name`   VARCHAR(160) DEFAULT NULL,
  `account_last4`  VARCHAR(8)   DEFAULT NULL,      -- never the full number
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`     DATETIME     NOT NULL,
  `updated_at`     DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bank_account` (`account_id`),
  CONSTRAINT `fk_bank_ledger_account` FOREIGN KEY (`account_id`) REFERENCES `gp_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gp_bank_statements` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bank_account_id`       INT UNSIGNED NOT NULL,
  `statement_date`        DATE         NOT NULL,   -- the statement's closing date
  `opening_balance_cents` BIGINT       NOT NULL,
  `closing_balance_cents` BIGINT       NOT NULL,
  `status`                ENUM('open','reconciled') NOT NULL DEFAULT 'open',
  `reconciled_by`         INT UNSIGNED DEFAULT NULL,
  `reconciled_at`         DATETIME     DEFAULT NULL,
  `created_by`            INT UNSIGNED NOT NULL,
  `created_at`            DATETIME     NOT NULL,
  `updated_at`            DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_statement` (`bank_account_id`, `statement_date`),
  CONSTRAINT `fk_stmt_bank` FOREIGN KEY (`bank_account_id`) REFERENCES `gp_bank_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A line from the bank's statement: + deposit / − withdrawal, from the BANK's
-- point of view of the depositor (a deposit increases the balance).
CREATE TABLE `gp_bank_lines` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `statement_id` INT UNSIGNED    NOT NULL,
  `txn_date`     DATE            NOT NULL,
  `description`  VARCHAR(255)    NOT NULL DEFAULT '',
  `reference`    VARCHAR(60)     DEFAULT NULL,
  `amount_cents` BIGINT          NOT NULL,
  `status`       ENUM('unmatched','matched','ignored') NOT NULL DEFAULT 'unmatched',
  `journal_id`   BIGINT UNSIGNED DEFAULT NULL,     -- an entry created from this line (charges, interest)
  PRIMARY KEY (`id`),
  KEY `idx_bline_stmt` (`statement_id`, `status`),
  CONSTRAINT `fk_bline_stmt` FOREIGN KEY (`statement_id`) REFERENCES `gp_bank_statements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A book line clears once (uq_bmatch_line); several book lines may clear one
-- bank line (a deposit of several receipts).
CREATE TABLE `gp_bank_matches` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bank_line_id`    BIGINT UNSIGNED NOT NULL,
  `journal_line_id` BIGINT UNSIGNED NOT NULL,
  `matched_by`      INT UNSIGNED    NOT NULL,
  `matched_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bmatch_line` (`journal_line_id`),
  KEY `idx_bmatch_bank` (`bank_line_id`),
  CONSTRAINT `fk_bmatch_bank` FOREIGN KEY (`bank_line_id`)    REFERENCES `gp_bank_lines` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bmatch_line` FOREIGN KEY (`journal_line_id`) REFERENCES `gp_journal_lines` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- #############################################################################
-- SECTION 7 — FIXED ASSETS
-- #############################################################################

CREATE TABLE `gp_asset_categories` (
  `id`                 INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `name`               VARCHAR(120)      NOT NULL,
  `asset_account_id`   INT UNSIGNED      NOT NULL,
  `accum_account_id`   INT UNSIGNED      NOT NULL,
  `expense_account_id` INT UNSIGNED      NOT NULL,
  `method`             ENUM('straight_line','declining_balance') NOT NULL DEFAULT 'straight_line',
  `useful_life_months` SMALLINT UNSIGNED NOT NULL,
  `residual_bp`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- residual value, basis points of cost
  `is_active`          TINYINT(1)        NOT NULL DEFAULT 1,
  `created_at`         DATETIME          NOT NULL,
  `updated_at`         DATETIME          NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fa_cat_name` (`name`),
  CONSTRAINT `fk_facat_asset`   FOREIGN KEY (`asset_account_id`)   REFERENCES `gp_accounts` (`id`),
  CONSTRAINT `fk_facat_accum`   FOREIGN KEY (`accum_account_id`)   REFERENCES `gp_accounts` (`id`),
  CONSTRAINT `fk_facat_expense` FOREIGN KEY (`expense_account_id`) REFERENCES `gp_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- opening_accum_cents: depreciation charged before this system (at go-live),
-- already inside the opening balance of the accumulated-depreciation account.
CREATE TABLE `gp_assets` (
  `id`                      INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `asset_no`                VARCHAR(30)       NOT NULL,
  `name`                    VARCHAR(160)      NOT NULL,
  `category_id`             INT UNSIGNED      NOT NULL,
  `acquired_on`             DATE              NOT NULL,
  `depreciation_start`      DATE              NOT NULL,       -- the 1st of the first month depreciated
  `cost_cents`              BIGINT            NOT NULL,
  `residual_cents`          BIGINT            NOT NULL DEFAULT 0,
  `useful_life_months`      SMALLINT UNSIGNED NOT NULL,
  `method`                  ENUM('straight_line','declining_balance') NOT NULL DEFAULT 'straight_line',
  `opening_accum_cents`     BIGINT            NOT NULL DEFAULT 0,
  `department_id`           INT UNSIGNED      DEFAULT NULL,
  `location`                VARCHAR(120)      DEFAULT NULL,
  `serial_no`               VARCHAR(80)       DEFAULT NULL,
  `supplier_id`             INT UNSIGNED      DEFAULT NULL,
  `status`                  ENUM('active','fully_depreciated','disposed') NOT NULL DEFAULT 'active',
  `disposed_on`             DATE              DEFAULT NULL,
  `disposal_proceeds_cents` BIGINT            DEFAULT NULL,
  `disposal_journal_id`     BIGINT UNSIGNED   DEFAULT NULL,
  `acquisition_journal_id`  BIGINT UNSIGNED   DEFAULT NULL,
  `notes`                   VARCHAR(500)      DEFAULT NULL,
  `created_by`              INT UNSIGNED      NOT NULL,
  `created_at`              DATETIME          NOT NULL,
  `updated_at`              DATETIME          NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_asset_no` (`asset_no`),
  KEY `idx_asset_cat` (`category_id`, `status`),
  CONSTRAINT `fk_asset_cat`  FOREIGN KEY (`category_id`)   REFERENCES `gp_asset_categories` (`id`),
  CONSTRAINT `fk_asset_dept` FOREIGN KEY (`department_id`) REFERENCES `gp_departments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One depreciation run per period; one entry per asset per period.
CREATE TABLE `gp_depreciation_runs` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `period_id`   INT UNSIGNED    NOT NULL,
  `journal_id`  BIGINT UNSIGNED DEFAULT NULL,
  `total_cents` BIGINT          NOT NULL DEFAULT 0,
  `run_by`      INT UNSIGNED    NOT NULL,
  `run_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_deprun_period` (`period_id`),
  CONSTRAINT `fk_deprun_period` FOREIGN KEY (`period_id`) REFERENCES `gp_periods` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gp_depreciation_entries` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id`            INT UNSIGNED    NOT NULL,
  `asset_id`          INT UNSIGNED    NOT NULL,
  `period_id`         INT UNSIGNED    NOT NULL,
  `amount_cents`      BIGINT          NOT NULL,
  `accum_after_cents` BIGINT          NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_depentry` (`asset_id`, `period_id`),
  KEY `idx_depentry_run` (`run_id`),
  CONSTRAINT `fk_depentry_run`   FOREIGN KEY (`run_id`)   REFERENCES `gp_depreciation_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_depentry_asset` FOREIGN KEY (`asset_id`) REFERENCES `gp_assets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- #############################################################################
-- SECTION 8 — BUDGETS
-- #############################################################################

CREATE TABLE `gp_budgets` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `name`           VARCHAR(80)  NOT NULL,
  `status`         ENUM('draft','approved') NOT NULL DEFAULT 'draft',
  `is_primary`     TINYINT(1)   NOT NULL DEFAULT 0,   -- the one budget-vs-actual uses by default
  `notes`          VARCHAR(500) DEFAULT NULL,
  `created_by`     INT UNSIGNED NOT NULL,
  `created_at`     DATETIME     NOT NULL,
  `updated_at`     DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_budget_name` (`fiscal_year_id`, `name`),
  CONSTRAINT `fk_budget_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `gp_fiscal_years` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- amount_cents is in the account's NORMAL direction (a positive expense budget
-- is a debit, a positive revenue budget a credit). department_id 0 = company-wide.
CREATE TABLE `gp_budget_lines` (
  `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `budget_id`     INT UNSIGNED     NOT NULL,
  `account_id`    INT UNSIGNED     NOT NULL,
  `department_id` INT UNSIGNED     NOT NULL DEFAULT 0,
  `period_no`     TINYINT UNSIGNED NOT NULL,
  `amount_cents`  BIGINT           NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_budget_line` (`budget_id`, `account_id`, `department_id`, `period_no`),
  CONSTRAINT `fk_bline_budget`  FOREIGN KEY (`budget_id`)  REFERENCES `gp_budgets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bline_account` FOREIGN KEY (`account_id`) REFERENCES `gp_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- #############################################################################
-- SECTION 9 — CO-OPERATIVE: NET-SURPLUS ALLOCATION
-- #############################################################################

-- One allocation per fiscal year, posted by its journal (closing book).
CREATE TABLE `gp_surplus_allocations` (
  `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `fiscal_year_id`    INT UNSIGNED    NOT NULL,
  `net_surplus_cents` BIGINT          NOT NULL,
  `reserve_cents`     BIGINT          NOT NULL,
  `cetf_cents`        BIGINT          NOT NULL,
  `cdf_cents`         BIGINT          NOT NULL,
  `optional_cents`    BIGINT          NOT NULL,
  `isc_cents`         BIGINT          NOT NULL,
  `patronage_cents`   BIGINT          NOT NULL,
  `percentages_json`  TEXT            NOT NULL,
  `journal_id`        BIGINT UNSIGNED DEFAULT NULL,
  `created_by`        INT UNSIGNED    NOT NULL,
  `created_at`        DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alloc_fy` (`fiscal_year_id`),
  CONSTRAINT `fk_surplus_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `gp_fiscal_years` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
