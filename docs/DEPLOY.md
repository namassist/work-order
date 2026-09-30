# Deploying WOrder

What a server needs, and what to run on every deploy. Collected from `CLAUDE.md` and the deploy
notes of earlier PRs; each PR still lists the steps specific to it.

## Server requirements

- **PHP 8.4 or later** (openspout ^5 for the Excel export, symfony/html-sanitizer 8 for comments,
  which uses PHP 8.4's HTML5 parser). PENDING: the production PHP version is not confirmed yet;
  see `CLAUDE.md` › Conventions for the fallback if it is 8.3.
- PHP extensions Laravel needs, plus `pdo_pgsql`, `dom`, `fileinfo` and `zip` (attachment type
  detection), `intl` (file sizes in the activity log), and `gd` and `mbstring` (BAST PDFs, see
  below).
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
`ACTIVITYLOG_CLEAN_AFTER_DAYS`, `WO_NUMBER_FORMAT`, `BAST_NUMBER_FORMAT`, `WO_EXPORT_MAX_ROWS`,
`WO_COMMENT_EDIT_MINUTES`, the attachment limits (`WO_ATTACHMENT_*`, `WO_INVOICE_MAX_FILES`,
`WO_PAYMENT_PROOF_MAX_FILES`, `MEDIA_MAX_FILE_SIZE_MB`), the BAST template image limits
(`BAST_TEMPLATE_MAX_IMAGES`, `BAST_TEMPLATE_IMAGE_MAX_SIZE_KB`), and the comment file
limits (`WO_COMMENT_MAX_IMAGES`, `WO_COMMENT_IMAGE_MAX_SIZE_KB`, `WO_COMMENT_MAX_DOCUMENTS`,
`WO_COMMENT_MAX_PENDING_UPLOADS`, `WO_COMMENT_UPLOAD_PRUNE_HOURS`).

## BAST PDFs (dompdf)

BASTs (FLOW.md §8) are rendered by **dompdf** (`dompdf/dompdf` ^3), a pure-PHP engine: no browser,
no binary, no separate service. It needs:

- `gd` (PNG and JPEG images: the letterhead) and `mbstring`, plus `dom`.
- A writable `storage/app/dompdf/` (font cache, temporary files, and an empty chroot). The app
  creates the directories; the deploy user and PHP must be able to write there.
- Nothing else: fonts are the DejaVu fonts bundled with dompdf.

The app locks it down (`App\Support\Bast\DompdfBastPdfRenderer`): remote loading, JavaScript, and
inline PHP are off, `data:` is the only protocol allowed (so it neither fetches a URL nor reads a
local file), and template images are embedded by the application. `BastPdfRendererTest` proves no
connection is attempted.

**Keep dompdf updated.** It parses HTML and CSS and has had security advisories in the past. CI
runs `composer audit --locked` (in `composer ci:check`), so a known vulnerability in dompdf or any
other package fails the build; update it (`composer update dompdf/dompdf`) as soon as a fix is out.

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
php artisan db:seed --class=BastTemplateSeeder --force
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
- **v2 status flow (FLOW.md v2 step 3): no in-place upgrade either.** The v1 statuses Dikerjakan,
  Penagihan, and Selesai have no v2 counterpart, and step 2's `work-orders.process` was split into
  new permissions no existing role holds. The step 3 migration refuses a database with work orders,
  status history, or manual BAST files of the v1 flow, and `RolePermissionSeeder` refuses one that
  still has `work-orders.process`. Start from a fresh database (no production data exists yet).
- **Daily reports (FLOW.md v2 step 4).** New permission `work-orders.report` (PIC Timesheet: post
  and edit daily reports, and add documents during Pelaksanaan, which `work-orders.submit-review` no
  longer allows). On an existing database, grant it to PIC Timesheet on the Role page, or reseed.
  Settings (`WO_REPORT_*`, `config/work_order.php` › `daily_reports`): working days, holidays
  (`WO_REPORT_HOLIDAYS`, ISO dates, comma-separated; update it every year), cutoff, back-dating, edit
  window, file and link limits, and the optional link domain allowlist (`WO_REPORT_LINK_DOMAINS`).
- **BAST templates and generation (FLOW.md v2 step 5).** New permission `bast-templates.manage`
  (system admin only by default; `RolePermissionSeeder` syncs it to admin). `BastTemplateSeeder`
  publishes the default template (PROVISIONAL, a generic Indonesian layout until the business sends
  its official sample) as version 1 when no version exists, and does nothing afterwards; without an
  active version Rental cannot submit a BAST. BAST numbers use `BAST_NUMBER_FORMAT` (default
  `BAST/{YYYY}/{MM}/{SEQ:4}`; `{DEPT_CODE}` is the requester department's code). Work orders that
  reached Approval BAST before this step have no BAST; start from a fresh database (no production
  data exists yet).
- **Payment segregation** is off by default; set `WO_PAYMENT_SEGREGATION=true` to require that the
  person who issued or last corrected an invoice never confirms its payment.
- `php artisan optimize` caches config, routes, and events; rerun it whenever `.env` changes.
- Queues: nothing is queued yet, so no worker is needed.

## Never in production

- `DemoSeeder` (it refuses to run there), `migrate:fresh`, `migrate:refresh`, `db:wipe`, or any
  command that deletes data.
- `APP_DEBUG=true`: it shows stack traces instead of the error pages.
