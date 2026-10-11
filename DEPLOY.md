# Deploying Hawala to Hostinger (shared hosting)

Written for this project specifically. Shared hosting has no Node, no Redis,
no Supervisor and no long-running processes, so assets are built on your
machine and the single cron does all the scheduled work.

Work through it top to bottom the first time. For later updates jump to
[Deploying an update](#deploying-an-update).

---

## 0. Before you start

You need:

- SSH access to the Hostinger account (hPanel → Advanced → SSH Access)
- The domain that will serve the app
- `php` 8.3 and `npm` on your own machine
- A decision on **where backups go** (see [step 9](#9-backups)) — a backup
  sitting on the same server is lost with the server

---

## 1. PHP version and extensions

hPanel → Advanced → **PHP Configuration**:

- Set PHP to **8.3**
- On the *PHP extensions* tab enable: `bcmath`, `intl`, `gd`, `zip`,
  `pdo_mysql`, `fileinfo`

`bcmath` is not optional — every money calculation goes through
`brick/math`, which needs it for exact integer arithmetic.

Check it took:

```bash
php -v
php -m | grep -E 'bcmath|intl|gd|zip|pdo_mysql|fileinfo'
```

---

## 2. Create the database

hPanel → Databases → **MySQL Databases**. Create a database and a user, give
the user all privileges on it, and write down all four values (host is
normally `localhost`).

---

## 3. Build the assets locally

**On your own machine**, in the project:

```bash
npm ci
npm run build
```

This writes `public/build/`. Those files are committed to the repo on
purpose — there is no Node on the server to build them there. Never run
`npm` on Hostinger.

---

## 4. Upload the project

The application must live **outside** the web root so that `.env`, `storage/`
and `vendor/` are never reachable over HTTP.

```
~/domains/<your-domain>/
├── laravel/        ← the whole project goes here
└── public_html/    ← web root (see step 5)
```

Upload over SSH (fastest, and skips the junk):

```bash
# from your machine, inside the project
rsync -av --delete \
  --exclude '.git' --exclude 'node_modules' --exclude '.env' \
  --exclude 'storage/logs/*' --exclude 'tests' \
  ./ <ssh-user>@<host>:~/domains/<your-domain>/laravel/
```

Then install PHP dependencies **on the server**:

```bash
cd ~/domains/<your-domain>/laravel
composer install --no-dev --optimize-autoloader
```

If Composer is unavailable over SSH, run that command locally and include
`vendor/` in the rsync (drop `--exclude 'vendor'` — it is not excluded above,
so `vendor/` ships by default if present).

---

## 5. Point the domain at `public/`

**Preferred** — hPanel → Websites → your domain → set the document root to
`domains/<your-domain>/laravel/public`.

**If the plan does not allow changing it**, copy the contents of `public/`
into `public_html/` and edit `public_html/index.php` so the two requires
point one level further out:

```php
require __DIR__.'/../laravel/vendor/autoload.php';
$app = require_once __DIR__.'/../laravel/bootstrap/app.php';
```

Repeat that copy on every update (step "Deploying an update" covers it).

---

## 6. Configure `.env`

`.env` is not in the repo. Create it on the server from `.env.example`:

```bash
cd ~/domains/<your-domain>/laravel
cp .env.example .env
nano .env
```

```dotenv
APP_NAME="Blue Star Hawala"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<your-domain>
APP_TIMEZONE=UTC          # displayed in Africa/Mogadishu via the general.timezone setting

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=<database>
DB_USERNAME=<user>
DB_PASSWORD=<password>

# Shared hosting: no Redis, no worker process.
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true

LOG_CHANNEL=stack
LOG_LEVEL=warning

# The first and only login on a fresh install. Delete these two lines
# after step 7 has run — they are not needed again.
SUPER_ADMIN_NAME="Administrator"
SUPER_ADMIN_EMAIL=<your-email>
SUPER_ADMIN_PASSWORD=<a-long-random-password>
SUPER_ADMIN_PHONE=+252...

# Where nightly backups are written. See step 9.
BACKUP_DISKS=local

MAIL_MAILER=smtp
MAIL_HOST=<smtp host>
MAIL_PORT=587
MAIL_USERNAME=<smtp user>
MAIL_PASSWORD=<smtp password>
MAIL_FROM_ADDRESS=<no-reply@your-domain>
MAIL_FROM_NAME="${APP_NAME}"
```

`APP_DEBUG=false` matters: a stack trace on a money system leaks balances,
queries and file paths.

---

## 7. Install

```bash
cd ~/domains/<your-domain>/laravel

php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --class=ProductionSeeder --force
php artisan storage:link

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`ProductionSeeder` creates the permissions, the four roles, the six system
ledger accounts, currencies, payout account types, the default fee tiers, the
six (inactive) SMS templates, the default settings, and **one** super-admin
from the `SUPER_ADMIN_*` values. It creates no branches, agents or transfers —
those are yours to enter.

It is safe to re-run and will not overwrite a setting you have since changed.

Now log in as the super admin, then **remove `SUPER_ADMIN_PASSWORD` from
`.env`** and run `php artisan config:cache` again.

**Customer demo server:** run `CustomerDemoSeeder` *instead of*
`ProductionSeeder` (only `SUPER_ADMIN_PASSWORD` is needed). It adds the
Mogadishu (Banadir) branch, the Brussels Office and Mogadishu Office company
accounts ($20,000 opening capital in Mogadishu), and two super admins who also
act as agents: `omar@bluestar.so` (sending agent) and `yusuf@bluestar.so`
(payout agent, Mogadishu). Both agents start at $0, so the demo starts with a
top-up. It also fetches exchange rates once.

Never run `DemoSeeder` on production. It refuses to run when
`APP_ENV=production`, but do not rely on that.

---

## 8. The cron

Everything scheduled runs off one cron entry. hPanel → Advanced → **Cron
Jobs**, every minute (`* * * * *`):

```bash
cd /home/<ssh-user>/domains/<your-domain>/laravel && php artisan schedule:run >> /dev/null 2>&1
```

Use the absolute path — cron does not start in your home directory. That one
line drives:

| What | When |
|---|---|
| `queue:work --stop-when-empty --max-time=50` | every minute — this is the queue; there is no worker process |
| `hawala:expire-claims` | every minute |
| `hawala:fetch-rates` | hourly |
| `hawala:verify-ledger` | 02:00 daily |
| `backup:clean` then `backup:run` | 03:00 / 03:10 daily |
| `activitylog:clean --days=730` | monthly |

Confirm it is wired up:

```bash
php artisan schedule:list
```

SMS messages and notifications are queued, so **if the cron is not running,
no SMS goes out**. Transfers and balances are never queued — they are written
synchronously inside a database transaction — so the money side keeps working
regardless.

---

## 9. Backups

By default `BACKUP_DISKS=local`, which writes to
`storage/app/private/Blue Star Hawala/` on the same server. That protects you
from a bad migration but **not** from losing the server or the account.

**Decide on an off-server destination.** Add a disk to
`config/filesystems.php`, then list it in `.env`:

```dotenv
BACKUP_DISKS=local,s3
```

Any Flysystem-supported target works — S3, Backblaze B2, Wasabi, Dropbox,
Google Drive, or plain SFTP to another host. For S3-compatible storage the
`s3` disk already exists in `config/filesystems.php`; just fill in
`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`,
`AWS_BUCKET` and (for non-AWS providers) `AWS_ENDPOINT`.

Prove it works rather than assuming:

```bash
php artisan backup:run
php artisan backup:list
```

Restoring is a database import plus a file copy — take one test restore
before you trust it.

---

## 10. HTTPS

hPanel → Security → **SSL**: install the free certificate for the domain and
turn on *Force HTTPS*. Then confirm `SESSION_SECURE_COOKIE=true` is set (step
6) so the session cookie is never sent over plain HTTP.

---

## 11. Post-install checklist

Work through these in the app as the super admin:

- [ ] `Setup → Branches` — add your Somali payout branches
- [ ] `Setup → Account Types` — check the payout methods match reality
- [ ] `Setup → Currencies` — activate the European currencies you take, and
      press **Fetch rates** once; check the rates look sane
- [ ] `Setup → Fee Tiers` — set your real fees and use the preview
- [ ] `Setup → Settings` — company name, timezone, commission defaults,
      claim minutes, large-transfer limit, and whether a cancelled transfer
      refunds the fee
- [ ] `Money → Company Accounts` — add your accounts and post opening balances
- [ ] `Users` — create the admins, sending agents and payout agents; give each
      payout agent their payout accounts
- [ ] `Setup → SMS Templates` — write the messages, send a test, then turn
      SMS on in Settings once the gateway is confirmed
- [ ] `Setup → Email` — see "Email" below: connect the company Gmail, send a test,
      read the templates, then switch email on
- [ ] Log in as the super admin and **set up two-factor** (authenticator app) —
      you are asked to straight away. Save the recovery codes somewhere safe.
      Every other user does the same at their first login
- [ ] `Setup → Settings → Security` — check the idle logout time (15 minutes
      by default)
- [ ] Run one small real transfer end to end: send → claim → pay
- [ ] `php artisan hawala:verify-ledger` — must report no problems
- [ ] Check the cron has run (`storage/logs/laravel.log`, or that rates
      refreshed on the hour)

---

## Email (company Gmail)

The system emails payout agents when a transfer arrives, the sending agent the
receipt when it is paid, approvers when an expense needs approving, and so on
(`Setup → Email → Templates` lists every email and who gets it). It sends them
from one company Gmail account with an **app password**.

1. Create the Gmail account. Turn on **2-Step Verification** (Google Account →
   Security), then create an **App password** (Security → App passwords).
2. `Setup → Email → Connection` (super admin): enter the address and the 16
   letters, tick **Send emails**, save. Use **Send test email** first — it uses
   what is in the boxes and shows the exact error if Gmail refuses.
3. `Setup → Email → Templates`: read and adjust the wording. Each has a live
   preview, placeholder buttons, a test send and "Reset to original".
4. Check `APP_URL` in `.env` is the real https address: the links in the emails
   are built from it.

Things to know:

- Emails are queued and sent by the scheduler, so they leave within about a
  minute (same cron as step 8). Nothing extra to run.
- Free Gmail allows about **500 emails a day** (Google Workspace 2,000). A
  transfer emails every active payout agent of its branch, so a busy branch
  adds up; the Email pages warn from 400 in 24 hours. Failed emails are kept in
  `Setup → Email → Sent emails` with the reason and a **Retry** button.
- Hostinger must allow outgoing SMTP on port 587 (or 465). The test email proves
  it; if it times out, ask Hostinger support to allow it.
- The app password is stored encrypted with `APP_KEY` (like the 2FA secrets), so
  do not change `APP_KEY` on a live system — if you must, enter the password again.
- Per person: `Users → Edit → Send this person email notifications` switches
  their emails off (useful for placeholder addresses).
- The default texts leave phone and account numbers out on purpose, because
  email is less private than the system. Add the placeholders only if you want them.

---

## Deploying an update

```bash
# on your machine
npm run build                     # only if CSS/JS changed
git add public/build && git commit -m "build assets"

rsync -av --delete \
  --exclude '.git' --exclude 'node_modules' --exclude '.env' \
  --exclude 'storage' --exclude 'tests' \
  ./ <ssh-user>@<host>:~/domains/<your-domain>/laravel/
```

Note `--exclude 'storage'` here: it keeps the server's logs, cache and
backups. The first upload (step 4) does not exclude it, so the directory
structure exists.

```bash
# on the server
cd ~/domains/<your-domain>/laravel

php artisan down                  # brief maintenance page

composer install --no-dev --optimize-autoloader
php artisan migrate --force

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan up
php artisan hawala:verify-ledger  # confirm the ledger still balances
```

If the document root could not be changed (step 5), also re-copy `public/*`
into `public_html/` and re-apply the two `index.php` path edits.

---

## Troubleshooting

**500 with a blank page.** `storage/logs/laravel.log` has the reason. Usually
permissions: `chmod -R 775 storage bootstrap/cache`.

**"No application encryption key."** On a *fresh* install: `php artisan key:generate --force`, then
`php artisan config:cache`.

**Styles missing, page unstyled.** `public/build` did not upload, or
`APP_URL` is wrong. Check `public/build/manifest.json` exists on the server.

**Nothing queued ever happens (no SMS).** The cron is not running or its path
is wrong. Test it by hand: `cd <path> && php artisan schedule:run`.

**Rates are stale and non-USD transfers are blocked.** Either the cron is
down or the provider is unreachable. `php artisan hawala:fetch-rates` shows
the error. A manual rate can be set per currency in `Setup → Currencies` as a
stopgap.

**Someone is locked out by two-factor** (lost phone, no recovery codes). Any
user with the *reset two-factor* permission can clear it from `Users → (the
user)`. If nobody can, from the server: `php artisan hawala:reset-two-factor
their@email.com` — they then set it up again at their next login.

**Never change `APP_KEY` on a live system.** Two-factor secrets are encrypted
with it; a new key makes every one unreadable and locks everybody out (fixed
only by resetting each user with the command above). That includes
`key:generate --force` — use it on a fresh install only.

**A config change seems ignored.** `config:cache` froze the old values. Run
`php artisan config:clear && php artisan config:cache`.

**`verify-ledger` reports a problem.** Stop and investigate before any more
transfers. It never auto-fixes, and corrections are posted as new reversing
entries — ledger rows are never edited or deleted.
