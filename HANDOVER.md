# Handover

What somebody taking this over needs to know: where things are, how to install
and back it up, what runs on a schedule, and what is still open.

For what the system does, see [README.md](README.md). For how to use it, see
the guide in [docs/guide/](docs/guide/) — it is also the Help screen in the app.

## The shape of it

| Part | Where |
|---|---|
| API and business rules | `application/` (CodeIgniter 3.1.9, PHP 8.2) |
| The one way into the ledger | `application/models/Journal_model.php` |
| Statements and analysis | `application/libraries/Statement_lib.php`, `Analysis_lib.php` |
| Screens | `js/<page>.js` + `pages/<page>.html`, one pair per screen |
| Routing | `application/config/routes.php`, plus one file per module in `application/config/routes/` |
| The database | `SCHEMA.sql` — the whole thing, with the reasoning in its comments |
| Decisions | `PLAN.md` |
| The guide | `docs/guide/*.md` → `help/guide.json` (`node docs/build-guide.mjs`) |
| Tests | `tests/` (see `tests/README.md`) |

## Installing

1. PHP 8.2 with `mysqli`, `mbstring`, `fileinfo`, `gd`; MariaDB 10.2.1+ or
   MySQL 8.0.16+; Apache with `mod_rewrite` and `AllowOverride All`.
2. Put the files where Apache serves them. **The project root is the document
   root**, and `.htaccess` is what keeps `application/`, `docs/`, `tests/`,
   `SCHEMA.sql` and every `.md`, `.log`, `.sql` and `.env` from being served.
   If `.htaccess` is ignored, the source and the schema are public. Check it
   after install: `curl -I https://…/SCHEMA.sql` must answer 403.
3. `mysql -u root <db> < SCHEMA.sql`.
4. `cp application/config/secrets.example.php application/config/secrets.php`
   and fill in:
   - `GP_DB_HOST`, `GP_DB_NAME`, `GP_DB_USER`, `GP_DB_PASS`
   - `GP_JWT_SECRET` — at least 32 random characters. **It must not be the
     same as any other application's on the same host**: a token signed with a
     shared secret would be accepted here as the user with that id.
   - `GP_SETUP_KEY` — any long string; it is needed once, on the setup screen.
     Clear it afterwards.
   - the SMTP settings, if the system is to send mail.
5. Open the site and work through the setup screen.
6. Make `uploads/` writable by the web server. Attachments live in
   `uploads/attachments/`, behind a deny-all `.htaccess` the system writes
   itself, and are served only through the API after a sign-in check.

## The scheduled job

```
*/15 * * * * php /path/to/accounting/index.php tools cron >> /var/log/accounting-cron.log 2>&1
```

On Windows, a Task Scheduler task running the same command. It drafts
recurring saved entries and prunes the auth tables, spent password-reset links
and long-read notifications. It takes a database lock, so two runs cannot
overlap, and exits non-zero if a part failed.

## Backups

- **The database, daily, kept off the server.** It is the books.
- **`uploads/`** — the attachments are files, not rows.
- **`application/config/secrets.php`** — keep one copy somewhere safe and out
  of version control.
- Restoring is `mysql < dump.sql`, then the files, then
  `php index.php tools cache`.

## Upgrading the code

1. Back up first.
2. Put the new files in place.
3. If `SCHEMA.sql` changed, apply the change by hand — there is no migration
   runner. The schema's comments say what each table is for.
4. `php index.php tools cache` (the sign-in page's branding).
5. `node docs/build-guide.mjs` if the guide changed.
6. Bump `CACHE_VERSION` in `sw.js` whenever `index.html`, `css/`, `js/` or
   `pages/` changed, or browsers will keep serving the old app from cache.
7. Run the suites in `tests/` against a scratch copy of the live database.

## Watching it

- **`application/logs/`** — PHP and application errors, one file a day.
  Anything starting `[audit]`, `[Journal_model]` or `[cron]` is worth reading.
- **Administration › Audit log** — every change, who made it, from where.
- **Reports › Integrity check** — sixteen ties over the stored records. Run it
  after an upgrade, before an audit, and whenever a total looks wrong.
- **`php index.php maildiag <address>`** — whether mail actually leaves.

## What the security rests on

- Sessions are JWTs signed with `GP_JWT_SECRET`; refresh tokens rotate, and a
  replayed one ends every session of that account.
- Roles are read from the database on every request, not from the token.
- Every write is rate-limited per user and audited in the same transaction as
  the change; a failed audit row fails the change.
- Development mode (errors on screen) needs both the `Host` header **and** a
  request from the machine itself.
- Attachments are never served by the web server; the deny-all rule plus the
  guarded endpoint is what keeps them private.
- `application/config/secrets.php`, `application/logs/`, `application/cache/`
  and `uploads/` are all outside version control.

## What is still open

- **No migration runner.** Schema changes are applied by hand.
- **The audit trail is append-only in code, not in the database.** Somebody
  with direct SQL access can still edit rows; that is what backups and
  database permissions are for.
- **Strict SQL mode is on** (`stricton` in `application/config/database.php`),
  so a bad value is refused rather than silently truncated. If an old query
  starts failing after an upgrade, that is why.
- **Attachments have no virus scanning.** Only the types in
  `Attachments::TYPES` are accepted, nothing is executed, and everything is
  served with `nosniff` and a sandbox, but a malicious PDF is still a
  malicious PDF when somebody opens it.
- **No multi-currency.** One currency, fixed at installation.
- **No inventory costing.** Inventory is an account, not a perpetual system;
  the cost of sales is posted by entry.
- **The mailer is synchronous.** A slow SMTP server slows the request that
  triggered the mail.

## If something goes wrong

1. **The sign-in page greets the wrong company.** Somebody seeded a demo
   database on this machine. `php index.php tools cache`.
2. **"Something went wrong on the server."** `application/logs/` has the
   reason with a timestamp; the audit log says what did and did not happen.
3. **A month will not close.** Entries are still waiting in it; the screen
   links to them.
4. **A report does not tie.** Every tie-out report names the entries that
   caused the difference — start there, then the integrity check.
5. **Nobody can sign in.** Check the database is up, then
   `application/logs/`; a lost administrator can be recreated with
   `php index.php tools create_admin <email>`.
