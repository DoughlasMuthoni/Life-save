# Deploying lifesave

Target: `douglas.waterliftsolarsavings.africa` on ordinary shared PHP/MySQL hosting (CLAUDE.md §17)
— no Docker, no Redis, no permanently-running process required. Everything here assumes a
typical cPanel-style host, but the steps translate directly to any shared host that gives you
SSH or a file manager, a MySQL database, and cron.

## 1. Prerequisites on the host

Confirm these before starting — if any are missing, most hosts can still add them, but it's
worth checking first:

- **PHP 8.3 or newer**, with the extensions Laravel needs: `mbstring`, `openssl`, `pdo_mysql`,
  `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `curl`. Shared hosts usually enable
  all of these by default for a "PHP application" preset.
- **MySQL 8+** (or MariaDB 10.6+), with a database and a **least-privilege user** created for
  this app — not the host's root/admin MySQL user.
- **Composer** available on the server (most hosts have it; if not, you can vendor the
  dependencies locally and upload `vendor/` instead — see §3).
- **Cron** access (every shared host has this — usually a "Cron Jobs" panel in cPanel).
- **SSH or a file manager** to get files onto the server and run a handful of `artisan`
  commands. If you only have FTP + a web-based cron panel, everything below still works, it's
  just more manual (see the callouts).
- **The domain's SSL certificate** — most hosts offer free AutoSSL/Let's Encrypt; make sure
  it's issued for `douglas.waterliftsolarsavings.africa` before going live.

## 2. Build locally, before uploading

Do these on your own machine first — don't try to run `npm run build` on the shared host,
many don't have Node available:

```bash
composer install --no-dev --optimize-autoloader
npm install
npm run build
php artisan test   # confirm everything still passes before shipping
```

`public/build/` now contains the compiled, versioned CSS/JS — that directory needs to be
uploaded along with the PHP code (it's git-ignored, so a plain `git pull` on the server won't
produce it).

## 3. Get the code onto the server

Two reasonable paths — pick whichever your host supports:

**A. Git-based** (if the host gives you SSH and can reach your git remote):
```bash
git clone <your-repo-url> lifesave
cd lifesave
composer install --no-dev --optimize-autoloader
```
Then upload the locally-built `public/build/` directory on top (scp/rsync/FTP), since it's
git-ignored.

**B. Upload everything** (if you only have FTP or a file manager): zip the project locally
*after* running step 2 (so `vendor/` and `public/build/` are both present), excluding `.git`,
`node_modules`, and your local `.env`, then upload and unzip on the server.

Either way, the web server's **document root must point at the `public/` folder**, not the
project root — this is the single most common shared-hosting Laravel mistake. In cPanel this
is usually a "Domains" setting where you choose the document root for
`douglas.waterliftsolarsavings.africa`.

## 4. Configure `.env` on the server

Create `.env` on the server (copy `.env.example` as a starting point) with production values:

```env
APP_NAME="Life OS"
APP_ENV=production
APP_KEY=                          # leave blank, generate in step 5
APP_DEBUG=false
APP_URL=https://douglas.waterliftsolarsavings.africa

LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1                 # or whatever the host's MySQL panel gives you
DB_PORT=3306
DB_DATABASE=<production database name>
DB_USERNAME=<least-privilege db user>
DB_PASSWORD=<its password>

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true        # not in .env.example — local dev runs over plain HTTP
QUEUE_CONNECTION=database
CACHE_STORE=database

AI_PROVIDER=claude
ANTHROPIC_API_KEY=<your real key>
ANTHROPIC_MODEL=claude-sonnet-5
```

`APP_DEBUG=false` and `APP_ENV=production` both matter for real reasons, not just convention:
debug mode would leak stack traces (including query values) to anyone who hits an error page,
and `APP_ENV=production` is what turns on the forced-HTTPS URL generation and lets the trusted-
hosts middleware correctly scope itself to `APP_URL`'s host (see `bootstrap/app.php` /
`AppServiceProvider`).

Never commit this file. `.env` is already git-ignored.

## 5. First-time server setup

Run these once, over SSH (or your host's terminal/cron-run-once panel if no SSH):

```bash
php artisan key:generate          # writes APP_KEY into .env
php artisan migrate --force       # --force is required outside 'local'/'testing'
php artisan storage:link
php artisan app:create-owner-account   # creates the single owner account interactively
```

Then set permissions so the web server can write to the directories Laravel needs:

```bash
chmod -R 775 storage bootstrap/cache
```

(The exact user/group depends on the host — cPanel accounts usually don't need anything
fancier than this; ask your host's support if PHP can't write to `storage/logs`.)

## 6. Cron — the one entry that makes background work run

Shared hosting can't run a permanent queue worker, so everything scheduled (the daily database
backup added in this project, and any future queued work) runs through Laravel's Scheduler,
which itself needs exactly **one** cron entry to fire every minute:

```
* * * * * cd /home/<cpanel-user>/lifesave && php artisan schedule:run >> /dev/null 2>&1
```

Add this in your host's "Cron Jobs" panel (cPanel: Advanced → Cron Jobs). Use the *absolute*
path to the project on the server. That single line is enough — `routes/console.php` already
schedules `app:backup-database` daily at 02:00; nothing else needs its own cron entry.

## 7. Verify

- Visit `https://douglas.waterliftsolarsavings.africa/up` — Laravel's built-in health check, should
  return 200.
- Visit `/login`, sign in with the owner account created in step 5.
- Check `/dashboard` loads and shows real (zeroed, since it's a fresh database) figures.
- Confirm the PWA installs: on a phone browser, look for the "Add to Home Screen" / install
  prompt.
- Tail `storage/logs/laravel.log` after your first real action to make sure nothing's silently
  erroring.

## 8. Shipping future changes

There's no CI/CD pipeline here — deploys are manual, matching "boring, inspectable" shared
hosting:

```bash
# locally
git pull                          # or however you get the latest code
composer install --no-dev --optimize-autoloader
npm run build
php artisan test

# upload changed files (rsync/scp/git pull on the server), then on the server:
php artisan migrate --force       # only if there are new migrations
php artisan optimize:clear        # drop cached config/routes/views from the previous deploy
php artisan optimize               # re-cache for production
```

Any migration that touches financial tables should be reviewed against CLAUDE.md §22 before
it ever reaches this step — that's a code-review concern, not a deploy-script one.

## 9. Backups

`php artisan app:backup-database` runs daily via cron (step 6), writing a gzipped
`mysqldump` to `storage/app/backups/` and keeping the last 14 days. That directory is
git-ignored and lives only on the server — **it is not a substitute for an off-server backup**.
Most hosts also offer their own account-level backup feature (cPanel's Backup Wizard, or
similar); turn that on too so a backup exists somewhere other than the same disk as the
database it's backing up.

To restore: `gunzip -c backup-<timestamp>.sql.gz | mysql -u <user> -p <database>`.
