# Deploying WOrder

What a server needs, and what to run on every deploy. Collected from `CLAUDE.md` and the deploy
notes of earlier PRs; each PR still lists the steps specific to it.

## Server requirements

- **PHP 8.4 or later** (openspout ^5 for the Excel export, symfony/html-sanitizer 8 for comments,
  which uses PHP 8.4's HTML5 parser). PENDING: the production PHP version is not confirmed yet;
  see `CLAUDE.md` › Conventions for the fallback if it is 8.3.
- PHP extensions Laravel needs, plus `pdo_pgsql`, `dom`, `fileinfo` and `zip` (attachment type
  detection), and `intl` (file sizes in the activity log).
- **PostgreSQL** (CI runs 16).
- Node is needed only to build the frontend (`npm run build`), not to run it.

## Environment (`.env`)

Start from `.env.example`. Required on every server:

- `APP_NAME=WOrder`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` (`php artisan key:generate` once).
- `DB_*` for PostgreSQL.
- `DEFAULT_USER_PASSWORD`: the first password of admin-created accounts. Creating users fails
  without it.
- `DISPLAY_TIMEZONE` (default `Asia/Makassar`). `app.timezone` stays UTC.
- `MEDIA_DISK=attachments`: the private disk (`storage/app/attachments`). Never serve it publicly.

Optional, with their defaults in `.env.example` and `config/`: `REGISTRATION_ENABLED` (the kill
switch for self-registration, the first response to a wave of spam sign-ups),
`ACTIVITYLOG_CLEAN_AFTER_DAYS`, `WO_NUMBER_FORMAT`, `WO_EXPORT_MAX_ROWS`,
`WO_COMMENT_EDIT_MINUTES`, the attachment limits (`WO_ATTACHMENT_*`, `WO_INVOICE_MAX_FILES`,
`WO_BAST_MAX_FILES`, `WO_PAYMENT_PROOF_MAX_FILES`, `MEDIA_MAX_FILE_SIZE_MB`), and the comment file
limits (`WO_COMMENT_MAX_IMAGES`, `WO_COMMENT_IMAGE_MAX_SIZE_KB`, `WO_COMMENT_MAX_DOCUMENTS`,
`WO_COMMENT_MAX_PENDING_UPLOADS`, `WO_COMMENT_UPLOAD_PRUNE_HOURS`).

## Upload limits (PHP and web server)

The app's size limits only work if PHP and the web server let the request through:

- `upload_max_filesize` at least the largest attachment limit (`WO_ATTACHMENT_MAX_SIZE_KB`,
  default 10 MB).
- `post_max_size` large enough for a work order create form with several documents
  (e.g. 10 × 10 MB) and for an invoice with its files.
- nginx `client_max_body_size` (or the equivalent) at least `post_max_size`.
- `MEDIA_MAX_FILE_SIZE_MB` (default 50) is media-library's ceiling above every collection limit.

## Scheduler (required)

Add the Laravel scheduler to cron, running every minute as the app's user:

```cron
* * * * * cd /path/to/work-order && php artisan schedule:run >> /dev/null 2>&1
```

It runs (`routes/console.php`):

- `activitylog:clean` daily: keeps `ACTIVITYLOG_CLEAN_AFTER_DAYS` days of audit history.
- `work-orders:prune-comment-uploads` hourly: deletes comment images and documents that were
  uploaded but never posted, older than `WO_COMMENT_UPLOAD_PRUNE_HOURS` (default 24). Without the
  scheduler these files stay on the disk.

## Every deploy

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force
php artisan optimize
```

- **Migrations** run in the same deploy as the code; some backfill or convert existing rows (for
  example the rich-text comment migration turns plain-text comments into escaped HTML).
- **`RolePermissionSeeder`** creates any new permission and syncs `admin` to all of them. It
  leaves other roles alone: grants in `INITIAL_ROLES` apply only when a role is first created, so on
  an existing database new grants for other roles are made on the Role page (each PR's deploy notes
  name them, e.g. `work-orders.confirm-payment` for keuangan).
- **v1 → v2 roles (FLOW.md v2 step 2): no in-place upgrade.** A database seeded with the v1 roles
  (`pemohon`, `koordinator`, `pelaksana`, `keuangan`) cannot be upgraded to the v2 roles: the seeder
  never rewrites existing roles, and a leftover `pelaksana` would keep `work-orders.process` and, with
  department-based visibility gone, act on every work order. `RolePermissionSeeder` therefore refuses
  to run while any v1 role exists. Start from a fresh database instead (no production data exists
  yet); locally that is `php artisan migrate:fresh --seeder=DemoSeeder`.
- `php artisan optimize` caches config, routes, and events; rerun it whenever `.env` changes.
- Queues: nothing is queued yet, so no worker is needed.

## Never in production

- `DemoSeeder` (it refuses to run there), `migrate:fresh`, `migrate:refresh`, `db:wipe`, or any
  command that deletes data.
- `APP_DEBUG=true`: it shows stack traces instead of the error pages.
