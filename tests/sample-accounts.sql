-- =============================================================================
-- sample-accounts.sql — five sign-ins for testing a fresh deployment
-- =============================================================================
--
--   mysql -u <user> -p <database> < tests/sample-accounts.sql
--
-- WHAT THIS IS
-- One account per role, plus a suspended one, so that a deployment can be
-- smoke-tested the way a person will actually use it: sign in, see what the
-- role is allowed to see, and confirm that a suspended account is refused.
--
-- ┌───────────────────────────────────────────────────────────────────────────┐
-- │  THE PASSWORDS BELOW ARE PUBLIC. They are in this file, in the repository │
-- │  and in the documentation. Treat every one of these accounts as known to  │
-- │  the world.                                                               │
-- │                                                                           │
-- │  · Use them on a test or staging deployment.                              │
-- │  · On a system that will hold real books, remove them the moment the      │
-- │    smoke test is done — see REMOVING THEM at the foot of this file.       │
-- │  · Never leave one active on a system reachable from the internet.        │
-- └───────────────────────────────────────────────────────────────────────────┘
--
--   Email                        Password                   Role         State
--   ---------------------------  -------------------------  -----------  ---------
--   qa.viewer@example.test       Qa-Viewer-2026-aa11        viewer       active
--   qa.bookkeeper@example.test   Qa-Bookkeeper-2026-bb22    bookkeeper   active
--   qa.accountant@example.test   Qa-Accountant-2026-cc33    accountant   active
--   qa.admin@example.test        Qa-Admin-2026-dd44         admin        active
--   qa.suspended@example.test    Qa-Suspended-2026-ee55     viewer       SUSPENDED
--
-- The addresses use the reserved .test domain (RFC 2606), so nothing this
-- system sends can ever reach a real mailbox.
--
-- The hashes are bcrypt at cost 12, which is what User_model uses. If your
-- installation raised `password_hash_cost` in Settings, these still verify —
-- the cost is stored in the hash.
--
-- WHAT IT WILL NOT DO
-- It refuses to run against a database that already has a posted journal
-- entry, because known credentials have no business on a real set of books.
-- Re-running it is harmless: the email and username are unique, so a second
-- run inserts nothing.
--
-- =============================================================================

-- Set this to 1 ONLY if you are certain this is a test system that happens to
-- have entries in it already. Leave it at 0 on anything else.
SET @force := 0;

SET @now := NOW();

-- How many of these already exist, so a second run can tell that it added
-- nothing and say so rather than writing a record of work it did not do.
SET @before := (SELECT COUNT(*) FROM `gp_users` WHERE `email` LIKE 'qa.%@example.test');


-- ── 1 · viewer — reads every report, changes nothing ────────────────────────
INSERT IGNORE INTO `gp_users`
  (`email`, `username`, `full_name`, `password_hash`, `role`, `account_state`,
   `auth_method`, `created_via`, `is_email_verified`, `created_at`, `updated_at`)
SELECT 'qa.viewer@example.test', 'qa_viewer', 'QA Viewer (test account)',
       '$2y$12$Mb8vVB4Nz79HYzO0xn0w5uuFQbQxjnLWIIvc5j1n5HsQzZXeSphzG',
       'viewer', 'active', 'password', 'seed', 1, @now, @now
  FROM DUAL
 WHERE @force = 1 OR NOT EXISTS (SELECT 1 FROM `gp_journals` WHERE `status` = 'posted');


-- ── 2 · bookkeeper — prepares and submits, approves nothing ─────────────────
INSERT IGNORE INTO `gp_users`
  (`email`, `username`, `full_name`, `password_hash`, `role`, `account_state`,
   `auth_method`, `created_via`, `is_email_verified`, `created_at`, `updated_at`)
SELECT 'qa.bookkeeper@example.test', 'qa_bookkeeper', 'QA Bookkeeper (test account)',
       '$2y$12$3ECQzTOvDKiqAggGF6KBK.2nH1g7iS27.0ssILWK6c.lwNpRkcuRu',
       'bookkeeper', 'active', 'password', 'seed', 1, @now, @now
  FROM DUAL
 WHERE @force = 1 OR NOT EXISTS (SELECT 1 FROM `gp_journals` WHERE `status` = 'posted');


-- ── 3 · accountant — approves, posts, reverses, closes a month ──────────────
INSERT IGNORE INTO `gp_users`
  (`email`, `username`, `full_name`, `password_hash`, `role`, `account_state`,
   `auth_method`, `created_via`, `is_email_verified`, `created_at`, `updated_at`)
SELECT 'qa.accountant@example.test', 'qa_accountant', 'QA Accountant (test account)',
       '$2y$12$5MU3vWxCtsB6IEFacGYDyeeYneb9flMjcOZ6iU4kdZQoIAg9JthYC',
       'accountant', 'active', 'password', 'seed', 1, @now, @now
  FROM DUAL
 WHERE @force = 1 OR NOT EXISTS (SELECT 1 FROM `gp_journals` WHERE `status` = 'posted');


-- ── 4 · administrator — users, settings, imports, the audit log ─────────────
INSERT IGNORE INTO `gp_users`
  (`email`, `username`, `full_name`, `password_hash`, `role`, `account_state`,
   `auth_method`, `created_via`, `is_email_verified`, `created_at`, `updated_at`)
SELECT 'qa.admin@example.test', 'qa_admin', 'QA Administrator (test account)',
       '$2y$12$jPwZKPUfKts5kkEGXQBCc./BMdQyHMdpdrgRdhFw53tIvxCZ7egeG',
       'admin', 'active', 'password', 'seed', 1, @now, @now
  FROM DUAL
 WHERE @force = 1 OR NOT EXISTS (SELECT 1 FROM `gp_journals` WHERE `status` = 'posted');


-- ── 5 · suspended — the password is right and sign-in must still fail ───────
-- This one is the point of the set: it proves the guard, not the login form.
INSERT IGNORE INTO `gp_users`
  (`email`, `username`, `full_name`, `password_hash`, `role`, `account_state`,
   `auth_method`, `created_via`, `is_email_verified`, `created_at`, `updated_at`)
SELECT 'qa.suspended@example.test', 'qa_suspended', 'QA Suspended (test account)',
       '$2y$12$oMoslzCSdL4DZbgubcvcBuoOxxNpj2tRqj1RhjRvpOTjQyyvrCNqm',
       'viewer', 'suspended', 'password', 'seed', 1, @now, @now
  FROM DUAL
 WHERE @force = 1 OR NOT EXISTS (SELECT 1 FROM `gp_journals` WHERE `status` = 'posted');


-- ── the audit trail ─────────────────────────────────────────────────────────
-- Nobody signed in to do this, so it is recorded against the system actor
-- (admin_id 0), which the audit screen shows as "System" — the same way the
-- command line and the scheduled job are recorded. A set of accounts that
-- appeared with no record of how would be exactly the thing this system is
-- built to make impossible.
INSERT INTO `gp_admin_audit_log`
  (`admin_id`, `action`, `target_type`, `target_id`, `detail`, `ip_address`, `occurred_at`)
SELECT 0, 'sql.sample_accounts', 'user', NULL,
       CONCAT('{"added":',
              (SELECT COUNT(*) FROM `gp_users` WHERE `email` LIKE 'qa.%@example.test') - @before,
              ',"via":"tests/sample-accounts.sql","remove_before":"production"}'),
       NULL, @now
  FROM DUAL
 WHERE (SELECT COUNT(*) FROM `gp_users` WHERE `email` LIKE 'qa.%@example.test') > @before;


-- ── did it work? ────────────────────────────────────────────────────────────
-- Five rows: the file ran. No rows: this database already has posted entries,
-- and it refused — which is what it is supposed to do.
SELECT `id`, `email`, `role`, `account_state`, `created_via`
  FROM `gp_users`
 WHERE `email` LIKE 'qa.%@example.test'
 ORDER BY `id`;


-- =============================================================================
-- WHAT TO TEST WITH THEM
-- =============================================================================
--   1. Each of the four active accounts signs in.
--   2. qa.suspended does NOT sign in, though the password is correct.
--   3. qa.viewer sees Reports and no Save button anywhere.
--   4. qa.bookkeeper can write a draft entry and submit it, and cannot approve
--      it — not even their own.
--   5. qa.accountant can approve and post that entry, and cannot reach
--      Administration.
--   6. qa.admin can reach Users, Settings, Imports and the Audit log, and can
--      see this file's own row in the audit log, recorded as System.
--   7. Reports › Integrity check passes.
--   8. Reverse the entry from step 5, then remove these accounts.
--
-- =============================================================================
-- REMOVING THEM
-- =============================================================================
-- Do this before the system holds anything real. Suspending is always safe;
-- deleting is only safe while these accounts have never prepared or posted
-- anything, because entries name the person who made them and the name would
-- be lost.
--
-- Suspend, and end any session they still hold:
--
--   UPDATE `gp_users` SET `account_state` = 'suspended', `updated_at` = NOW()
--    WHERE `email` LIKE 'qa.%@example.test';
--
--   DELETE FROM `gp_refresh_tokens`
--    WHERE `user_id` IN (SELECT `id` FROM `gp_users` WHERE `email` LIKE 'qa.%@example.test');
--
--   INSERT INTO `gp_admin_audit_log`
--     (`admin_id`, `action`, `target_type`, `target_id`, `detail`, `ip_address`, `occurred_at`)
--   VALUES (0, 'sql.sample_accounts_off', 'user', NULL, '{"suspended":"qa.*@example.test"}', NULL, NOW());
--
-- Delete outright — ONLY on a system where they never touched the books:
--
--   DELETE FROM `gp_users` WHERE `email` LIKE 'qa.%@example.test';
--
-- Check nothing is left:
--
--   SELECT COUNT(*) AS still_there FROM `gp_users` WHERE `email` LIKE 'qa.%@example.test';
-- =============================================================================
