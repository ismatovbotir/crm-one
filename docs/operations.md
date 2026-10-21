# Operations guide (production)

Roadmap step 10. Covers deployment checks, backups, scheduler and queue worker supervision,
monitoring, and log retention. Everything runs on our own server. The CRM sends nothing to 1C:
1C pulls from the CRM API (outbox + ack).

## 1. Production `.env` checklist

Run `php artisan crm:prod-check` after every deploy. It exits with code 1 while a blocking item is open.

| Setting | Value | Why |
|---|---|---|
| `APP_ENV` | `production` | Turns off development shortcuts. |
| `APP_DEBUG` | `false` | Debug pages leak internals. The local "any credentials" login only works with `APP_ENV=local` and `APP_DEBUG=true`. |
| `APP_KEY` | generated once, never changed | 2FA secrets and recovery codes are encrypted with it. |
| `APP_URL` | `https://crm.example.uz` | Used for links in e-mails, Telegram and prints. |
| `APP_TIMEZONE` | `UTC` (default) | Business dates use `Asia/Tashkent` where the specs say so. |
| `DB_CONNECTION` | `mysql` (MySQL 8, utf8mb4) | See `docs/db-review-2026-10.md`. SQLite is for development only. |
| `SESSION_DRIVER` | `redis` (or `database`) | |
| `SESSION_SECURE_COOKIE` | `true` | Cookies only over HTTPS. |
| `CACHE_STORE` | `redis` (or `file`) | The database store works but adds queries. Never `array`. |
| `QUEUE_CONNECTION` | `database` or `redis` | A supervised worker is needed (section 3). |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | SMTP | Portal login codes and agreement e-mails. |
| `LOG_STACK` | `daily` | Rotated log files. |
| `LOG_DAILY_DAYS` | `30` | Log retention (section 5). |
| `LOG_LEVEL` | `warning` | |
| `BACKUP_PATH` | e.g. `/srv/backups/crm` | Outside `public/`, ideally on another disk. |
| `BACKUP_KEEP` | `14` | Archives kept. |
| `BACKUP_AT` | `02:30` | Daily backup time, Asia/Tashkent. |
| `HEALTH_TOKEN` | long random string | Needed to see the detailed `/api/health` report. |
| `SECURITY_ENFORCE_TWO_FACTOR` | `true`, or switch it on in Settings > Setup | 2FA for admin, accountant and finance. |
| `ONEC_ENABLED`, `ONEC_INBOUND_ENABLED` | `true` once 1C is connected | Also issue the 1C token in Settings > Integrations. |

Deploy steps:
1. `composer install --no-dev --optimize-autoloader`
2. `npm ci && npm run build`
3. `php artisan migrate --force`
4. `php artisan storage:link` (first deploy only)
5. `php artisan optimize`
6. `php artisan queue:restart`
7. `php artisan crm:prod-check`

Then open **Settings > Setup**. It shows the go-live checklist (company, CEO signatory, bank account, templates, 1C token, warehouses, roles, scheduler, 2FA, demo data).

Before go-live, remove demo data with `php artisan crm:wipe-demo --force`. It refuses if records belong to real users. Add `--catalog` to also remove items, units, categories, stock and serial numbers.

## 2. Backups and restore

```
php artisan crm:backup                     # one archive now
php artisan crm:backup --list              # existing archives
php artisan crm:backup --verify=PATH.zip   # check checksums without restoring
php artisan crm:restore PATH.zip --force   # restore (asks for confirmation)
```

- **What is backed up.** One `crm-backup-YYYYMMDD-HHMMSS.zip` per run in `BACKUP_PATH`. It holds:
  - the database:
    - MySQL: `mysqldump --single-transaction` into `database.sql`;
    - PostgreSQL: `pg_dump`;
    - SQLite: a consistent `VACUUM INTO` copy;
  - all files under `storage/app/private` and `storage/app/public`: signed agreements, attachments, payment proofs, item pictures, serial import files, logo. Temporary uploads are left out.
  - `manifest.json` with a SHA-256 per entry.
- **Client tools.** The MySQL/PostgreSQL client tools must be on PATH. You can also set them in `BACKUP_MYSQLDUMP` / `BACKUP_MYSQL` / `BACKUP_PG_DUMP` / `BACKUP_PSQL`.
- **Schedule.** Daily at `BACKUP_AT`. The newest `BACKUP_KEEP` archives are kept. Turn the schedule off with `BACKUP_SCHEDULE=false` if backups are taken elsewhere.
- **Off-site copy.** This is your responsibility. Copy `BACKUP_PATH` to another machine or storage every day (rsync, robocopy, backup agent). The CRM never uploads archives anywhere.
- **Restore steps:**
  1. The archive is verified first: checksums, allowed entry names only, same database driver.
  2. The app goes into maintenance mode.
  3. The database is replaced. With SQLite, the old file is kept as `database.sqlite.before-restore-*`.
  4. Files are put back and the app comes back up.
  5. Then run `php artisan migrate --force` (if the backup is older than the code) and `php artisan optimize`.
- **Restore test.** Restore the latest archive on a staging server once a month.
- **Do not restore over a newer 1C exchange without care.** After a restore, outbox events created after the backup time are gone. 1C pulls the outbox again; check the 1C exchange log for documents dated after the backup.

## 3. Scheduler and queue worker supervision

The scheduler runs every minute. It handles:
- offer expiry, lead reminders, notification scans;
- agreement lifecycle and the nightly ledger check;
- tender ticks, 1C snapshot expiry;
- housekeeping, the daily backup, and a heartbeat shown in Settings > Setup and `/api/health`.

The queue worker processes 1C inbound batches (queue `onec-inbound`) and queued notifications (`default`).

### Linux (recommended)

Cron (`crontab -u www-data -e`):

```
* * * * * cd /var/www/crm && php artisan schedule:run >> /dev/null 2>&1
```

Supervisor (`/etc/supervisor/conf.d/crm-worker.conf`):

```
[program:crm-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/crm/artisan queue:work --queue=onec-inbound,telegram,default --sleep=3 --tries=3 --max-time=3600
directory=/var/www/crm
user=www-data
numprocs=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/www/crm/storage/logs/worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
```

Apply it with `supervisorctl reread && supervisorctl update`. After each deploy, run `php artisan queue:restart`; workers finish their job and restart with the new code.

A systemd timer is an alternative to cron. Create `crm-scheduler.service` with `ExecStart=/usr/bin/php /var/www/crm/artisan schedule:run`, plus `crm-scheduler.timer` with `OnCalendar=*-*-* *:*:00`.

### Windows Server

- **Scheduler.** In Task Scheduler, create a task "CRM scheduler":
  - run whether the user is logged on or not;
  - trigger daily, repeat every 1 minute for 1 day;
  - action `C:\php\php.exe` with arguments `artisan schedule:run`, start in `C:\inetpub\crm`.
- **Worker.** Run `php artisan queue:work --queue=onec-inbound,telegram,default --max-time=3600` as a Windows service with NSSM:
  1. `nssm install crm-worker C:\php\php.exe "artisan queue:work --queue=onec-inbound,telegram,default --max-time=3600"`
  2. Set AppDirectory to the CRM folder and restart on exit.

### Checks

- Settings > Setup shows "Scheduler running" when the heartbeat is less than 5 minutes old.
- `/api/health` reports `scheduler` and `queue`. The queue check fails when the oldest pending job is older than 15 minutes.

## 4. Health check

`GET /api/health`: stateless, no session, limited to 30 requests per minute, never cached.

- **Responses.** Without a token the body is only `{"status":"ok"}` (HTTP 200) or `{"status":"fail"}` (HTTP 503).
- **Detailed report.** Send `X-Health-Token: <HEALTH_TOKEN>`. If no token is configured, requests from the server itself also get details.
- **Checks:**
  - `database`;
  - `cache` (write/read);
  - `storage` (private disk writable);
  - `scheduler` (heartbeat at most 5 minutes old);
  - `queue` (oldest pending job at most 15 minutes old; failed jobs in the last 24 h only warn);
  - `backup` (newest archive at most 26 hours old);
  - `onec_outbox` (events available for 1C to pull; warns if the oldest is over 24 h, because 1C pulls on its own schedule).
- **Monitoring.** Point Uptime Kuma, Zabbix or similar at `https://crm.example.uz/api/health` and alert on non-200.
- **Liveness.** Laravel's `/up` stays available as a simple liveness probe.

## 5. Log and data retention

| Data | Retention | Mechanism |
|---|---|---|
| Application logs (`storage/logs/laravel-*.log`) | `LOG_DAILY_DAYS` (30) | `LOG_STACK=daily`. Rotation deletes older files. |
| Worker log | 5 x 10 MB | Supervisor `stdout_logfile_backups`. |
| 1C inbound batches, payloads, snapshots, integration logs | per model prune rules (Spec 02) | `model:prune`, daily 03:30. |
| 1C outbox events (acked) | per model prune rule (Spec 06) | `model:prune`, daily 03:50. |
| Failed queue jobs | 30 days | `queue:prune-failed`, daily. |
| Login codes, Telegram link tokens, webhook update ids | expired rows | `auth:prune`, daily. |
| Serial import files | retention in `config/serials.php`. Batch metadata is kept. | `stock:prune-serial-imports`, daily. |
| Read notifications | `RETENTION_NOTIFICATIONS_DAYS` (90). Unread ones are kept. | `crm:prune-notifications`, daily. |
| Backups | `BACKUP_KEEP` archives | `crm:backup`. |
| Activity log, status transitions, accounting entries, payments, serial history | **kept** (audit trail, legal retention) | not pruned (personal fields of old closed leads are scrubbed, see "Closed lead retention") |

## 6. Incident basics

- Check the latest `storage/logs/laravel-*.log` first. For 1C exchange problems, also check Settings > Integrations > log.
- "Scheduler not running" in Setup: check cron or Task Scheduler and the PHP path.
- Queue backlog: `php artisan queue:failed` and `php artisan queue:retry all`. Check that the worker is up (`supervisorctl status`).
- Lost 2FA phone: an admin opens Users and clicks **Reset 2FA** on that person. This is audited; admins cannot reset their own. The person sets 2FA up again at the next sign-in.
- If the only admin is locked out, run this on the server (records `2fa_disabled` in `auth_events`):
  `php artisan tinker --execute 'app(App\Services\Auth\TwoFactor::class)->disable(App\Models\User::where("email", "admin@company.uz")->firstOrFail());'`

## Telegram log retention (DBA review, 2026-10-20)

- `php artisan telegram:prune` runs every night at 04:20 (scheduler). It deletes rows older than the retention period:
  - `telegram_updates` (webhook de-duplication; Telegram never re-sends updates after 24 hours);
  - `telegram_messages` without a lead (the bot's message id log; there is no message text in the CRM);
  - `telegram_posts` with status `sent` or `failed` (company-group notifications; `queued` and `retrying` are kept).
- Kept forever: message rows linked to a lead, leads, `telegram_links`, chats, contacts and destinations.
- Retention: Settings > Telegram, "Keep Telegram logs (days)", 30 to 3650, default 365 (`operations.retention.telegram_days`, env `RETENTION_TELEGRAM_DAYS`).


## Closed lead retention (Spec 04 section 19)

- `php artisan leads:anonymise` runs every night at 04:25. It removes personal data (contact, tax ID, Telegram identity, free-text notes) from lost and disqualified leads closed more than `operations.retention.closed_leads_months` months ago (default 24, env `RETENTION_CLOSED_LEADS_MONTHS`, minimum 6). Company, need, source, score and status history stay.
- Check first with `php artisan leads:anonymise --dry-run`. The change is not reversible; restore from a backup if a period was set too short.
- The activity log is otherwise append-only; this job scrubs the personal fields of those leads in it on purpose.

## 7. Database schema: fresh install and migrations (squashed 2026-10-20)

The schema is created by 10 squashed migrations in `database/migrations` (`2026_10_20_000100` ... `2026_10_20_001000`), grouped by domain. The 107 earlier migrations are kept for reference in `database/migrations_archive_pre_squash/` and are never run.

### Fresh install (MySQL 8.0.16+ or SQLite)

1. Create an empty schema: `create database crm character set utf8mb4 collate utf8mb4_unicode_ci;`.
2. Grant the app user `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES` on that schema only.
3. `php artisan migrate --force`. This creates all tables and seeds the standard departments, reference lists and geo places.
4. Demo data (non-production only): `php artisan db:seed --force`.

### MySQL notes

- CHECK constraints are created on MySQL only (`App\Support\SchemaChecks`). SQLite skips them, so they are tested only by the MySQL CI job below.
- 1C identifiers (`external_id`, `onec_guid`, `*_snapshot_id`, `batch_id`, `event_id`, `request_id`, ...) are `ascii` / `ascii_general_ci` on MySQL. They are case-insensitive as before, and non-ASCII values are rejected in strict mode.
- Index and constraint names must stay at or under 64 characters. Pass an explicit name for composite indexes.
- Before renaming or dropping a column on MySQL, check generated columns (`information_schema.columns.generation_expression`) and `information_schema.check_constraints`. MySQL refuses the change with errors 3108 or 3959. Known generated columns: `contacts.primary_key`, `partners.crm_tax_key`, `agreements.active_order_key`, `catalog_visibility_rules.category_key` / `item_key`.
- After go-live, adding a FK to a large existing table rebuilds the table (ALGORITHM=COPY). Use a new nullable column plus `foreign_key_checks=0` with `ALGORITHM=INPLACE`; see `docs/db-review-2026-10-19.md` M-2.
- Deploy migrations in maintenance mode with workers and the scheduler stopped, and with `lock_wait_timeout` set low for the session.

### CI suggestion

Add a job that starts `mysql:8.0` (utf8mb4_unicode_ci, strict mode) and runs:

```
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=crm_test DB_USERNAME=root DB_PASSWORD=secret vendor/bin/phpunit
php artisan migrate:fresh --force && php artisan migrate:rollback --force --step=10 && php artisan migrate --force
```

`phpunit.xml` does not force `DB_CONNECTION`, so environment variables override the SQLite default.

### Rules for new migrations (from the first production deploy on)

- Baseline = the 10 squashed files. Never edit them after the first production deploy; before that, only with a re-run of the SQLite + MySQL equivalence check.
- One new migration per change, dated after `2026_10_20_001000`. Never edit a migration that has run on any shared database.
- Name files `YYYY_MM_DD_HHMMSS_<verb>_<what>.php` and indexes `<table>_<cols>_<index|unique>` (explicit short name if longer than 64).
- Follow docs/db-review-2026-10.md section 4.0: ASCII codes, restrict FKs on financial/audit data, CHECKs via `SchemaChecks`, generated-column uniques instead of partial uniques, no data backfills hidden in schema migrations without chunking.
