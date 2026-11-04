# Deployment

Production setup, and the things that quietly go wrong if they are skipped.

---

## Requirements

- PHP 8.2+ with `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `gd`, `zip`,
  `curl`, `intl`, `bcmath`
- MySQL 8.0+ or MariaDB 10.4+
- `mysqldump` on the server (for backups)
- Node 20+ **on the build machine only** — the browser receives static files
- A web server that can serve a directory and run PHP

---

## 1. Build the front end

The panel is served from **`/admin`**, on the same host as the API. That is the
arrangement with the fewest moving parts: one origin, so no CORS rules and no
cookie exceptions, and `FRONTEND_URL` can be left unset.

It needs a prefix because Laravel already owns the paths that matter:

| Path | Served by | |
|---|---|---|
| `/`, `/about`, `/pricing`… | Laravel | the public website |
| `/health/{token}` | Laravel | **the health form the QR opens** |
| `/api/*` | Laravel | the API |
| `/admin` | static files | the admin panel |

Putting the panel at the document root — which an earlier version of this page
suggested — takes `/` and `/health/{token}` with it, so the website disappears
and every health-form QR leads to the panel's "page not found".

```bash
cd web
npm ci
ADMIN_BASE=/admin/ npm run build      # writes web/dist
```

On Windows PowerShell:

```powershell
cd web
npm ci
$env:ADMIN_BASE = '/admin/'; npm run build
```

The trailing slash matters. Check it worked — `web/dist/index.html` should
reference `/admin/assets/…`, not `/assets/…`.

Then copy the bundle in beside the API:

```bash
cp -r web/dist api/public/admin
```

`web/dist` is static; Node is needed only on the machine that builds it.

---

## 2. Deploy the API

```bash
cd api
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Set at minimum:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://gym.example.lk
APP_TIMEZONE=Asia/Colombo

DB_DATABASE=gms
DB_USERNAME=gms_app
DB_PASSWORD=<a real password>

FRONTEND_URL=https://gym.example.lk

BIOMETRIC_PUSH_TOKEN=<32+ random characters>
BACKUP_MYSQLDUMP_PATH=/usr/bin/mysqldump
```

`APP_DEBUG=false` matters: with it on, a stack trace containing database
credentials is returned to whoever triggered the error.

`APP_TIMEZONE` must be the gym's own zone. "Today's attendance" and the nightly
roll-over both mean local midnight, not UTC midnight.

Then:

```bash
php artisan migrate --force
php artisan db:seed --force        # roles, plans, admin account
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**Sign in and change the administrator password immediately.** The seeded
credentials are in the output and in this repository.

### Database user

Do not run the application as `root`:

```sql
CREATE USER 'gms_app'@'localhost' IDENTIFIED BY '<password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON gms.* TO 'gms_app'@'localhost';
```

Add `CREATE, ALTER, DROP, INDEX, REFERENCES` temporarily when running
migrations, then remove them.

### Permissions

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

---

## 3. The scheduler — do not skip this

Attendance is collected by a scheduled command. Without cron, **no biometric
attendance is recorded at all**, memberships never expire, reminders are never
sent and no backups run.

```cron
* * * * * cd /var/www/gms/api && php artisan schedule:run >> /dev/null 2>&1
```

Confirm it:

```bash
php artisan schedule:list
```

The **Fingerprint devices** page flags any reader that has not synchronised
recently, which is the symptom of a missing cron entry.

---

## 4. Web server

**The document root is `api/public`.** Laravel serves the website, the health
form and the API; the panel sits inside that directory as static files under
`/admin`.

### Apache

```apache
<VirtualHost *:80>
    ServerName gym.local
    DocumentRoot /var/www/gms/api/public

    <Directory /var/www/gms/api/public>
        AllowOverride All
        Require all granted
    </Directory>

</VirtualHost>
```

**Deep links need no extra configuration.** The panel is a single-page
application, so `/admin/members` is one of its own routes rather than a file,
and a refresh there has to return `index.html`. Laravel handles that itself —
see the `/admin/{path?}` route in `routes/web.php` — so there is no
`FallbackResource` or `try_files` rule to forget.

One caveat, and it is a development one only: **deep links do not work under
`php artisan serve`.** PHP's built-in server finds `public/admin/index.html`,
treats `/admin` as a script path, and hands Laravel a request with the prefix
already stripped. Apache and nginx pass the path intact. Use the dev server on
:5173 while developing, which has its own handling.

Laravel's own `.htaccess` in `api/public` handles everything else, so
`AllowOverride All` is required. `mod_rewrite` must be enabled.

### Nginx

```nginx
server {
    listen 80;
    server_name gym.local;
    root /var/www/gms/api/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
}
```

### Windows, with the WAMP that is already installed

Workable for a single gym, and the quickest route if the office PC already
runs WAMP. Edit `C:\wamp64inpachepache<version>\conf\extra\httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName gym.local
    DocumentRoot "D:/gms/api/public"

    <Directory "D:/gms/api/public">
        AllowOverride All
        Require all granted
    </Directory>

    <Directory "D:/gms/api/public/admin">
        FallbackResource /admin/index.html
        Require all granted
    </Directory>
</VirtualHost>
```

Then restart Apache from the WAMP tray icon.

Two things to change from a development WAMP:

- **Set a MariaDB root password**, or create the `gms_app` user below and use
  that. A blank root password on a machine other people can reach is not a
  configuration, it is an open door.
- **Check the port.** WAMP runs MariaDB on **3307** and MySQL on 3306. Match
  `DB_PORT` to whichever holds the `gms` database.

WAMP's Apache starts manually by default. For an office PC that should come
back after a power cut, install Apache and MariaDB as Windows services set to
**Automatic**, from the WAMP tray menu.

---

## 5. Going live on a domain

Three arrangements work. They differ in one thing: **who can reach the
fingerprint reader**, which sits on the gym LAN and cannot be polled from
anywhere else.

| | Reader | Reachable from outside |
|---|---|---|
| **Hosted, reader pushes out** | push only | everything |
| On-site server, port forwarded | polled locally | everything |
| On-site server, LAN only | polled locally | nothing |

What follows is the first, which needs no server at the gym at all.

### Check the hosting first

This does not work everywhere. Before anything else, confirm:

| Need | Why |
|---|---|
| **PHP 8.2+** with `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `gd`, `zip`, `curl`, `intl`, `bcmath` | The application will not boot without them |
| **MySQL 8 / MariaDB 10.4+** | |
| **Cron**, ideally every minute | Expiries, auto-close, notifications, backups. Five minutes is survivable here because nothing is being polled |
| Document root you can point at a subdirectory | It must point at `api/public`, not the account root |
| `mysqldump` | Only for the built-in backups. Without it, back up from the control panel instead |

Shell access makes `composer install` and `php artisan migrate` far easier. If
there is none, run `composer install` locally and upload `vendor/`, and import
the schema with a SQL dump through phpMyAdmin.

### Deploy

Two ways in. The installer is the one to use on hosting without shell access.

#### With the browser installer

```powershell
# On your machine, build the panel into the API's public directory
cd D:\gms\web
npm ci
$env:ADMIN_BASE = '/admin/'; npm run build
Copy-Item D:\gms\web\dist D:\gms\api\public\admin -Recurse

# A copy to upload, without the development tools
Copy-Item D:\gms\api D:\gms\dist-api -Recurse
cd D:\gms\dist-api
composer install --no-dev --optimize-autoloader
Remove-Item .env -ErrorAction SilentlyContinue
```

Zip `dist-api`, upload, extract, and point the domain's document root at its
`public` directory. Then open **`https://projectkaya.lk/install`**.

It checks the host, creates the database if your user is allowed to, writes
`.env`, builds the schema, seeds the roles and makes your administrator
account. Then it shuts: both a marker file in `storage/app/` and the state of
the database close it, and the routes answer 404 from then on.

> **Install immediately after extracting.** Between upload and setup there is a
> window in which anyone who finds the URL can install it themselves and own
> the system. That window is the reason the page exists at all, and the reason
> to close it in minutes rather than days. On a public site, delete
> `app/Http/Controllers/Install` and the `/install` routes once you are in.

#### By hand

Build and upload exactly as above, then from a shell in the application
directory:

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force
```

Create the first administrator with `php artisan tinker`, or run the installer
once and let it do it.

Either way, set these in `.env`:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://projectkaya.lk
APP_TIMEZONE=Asia/Colombo

DB_HOST=localhost
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

BIOMETRIC_PUSH_TOKEN=<32+ random characters>

# Shared hosting usually sits behind the provider's own proxy. Ask them
# which address it forwards from; without it the client IP recorded on every
# signed health form is the proxy's, the session cookie loses its Secure
# flag, and the public form's rate limit is shared by the whole internet.
TRUSTED_PROXIES=127.0.0.1,::1
```

Leave `FRONTEND_URL` unset — one origin, so there is no CORS to configure.

Cron, through the control panel:

```
* * * * * cd /home/<user>/projectkaya.lk && php artisan schedule:run >> /dev/null 2>&1
```

### Point the reader at it

The terminal cannot be polled across the internet, so it has to send events
itself. On the device, under **Network → HTTP Listening** (sometimes *Event
notification* or *HTTP Host*):

| | |
|---|---|
| URL | `https://projectkaya.lk/api/biometric/push/ENT-01` |
| Header | `X-GMS-Token: <your BIOMETRIC_PUSH_TOKEN>` |

Then set the device's **Synchronisation** to **Push** in the admin panel. That
matters: a device left on *Pull* is polled every minute at `192.168.8.104`,
which the server cannot reach, so it accumulates failures and shows as offline
while working perfectly.

**Test TLS early.** Access-control terminals are not always happy with a modern
certificate chain, and the symptom is silence rather than an error. Scan a
finger and watch whether the event arrives. If it does not, the device log on
its own web page usually says why.

> Do **not** fall back to plain `http://` to get around a TLS failure. The push
> token travels in that request; over HTTP on the public internet it is
> readable by anything on the path, and it is the only thing standing between a
> stranger and writing attendance into your system. Fix the certificate, or
> keep the server on-site.

### What push-only costs you

**There is no safety net.** Polling exists because a notification that silently
stops arriving is indistinguishable from a quiet gym. Without it, attendance
can stop for a week and nothing will say so.

What you still have: these terminals send heartbeats as well as scans, and the
push endpoint records those too, so **Fingerprint devices** keeps showing a
recent *last seen* for a healthy reader. A device that has gone quiet stops
updating, and that is the thing to watch. Check it weekly.

---

## 6. Network layout for the readers

The fingerprint terminals should be on the gym LAN, **not** reachable from the
internet. Two workable arrangements:

**Server on the same LAN** — simplest. The API polls the readers directly.

**Server hosted elsewhere** — the readers cannot be polled across the internet
safely. Either:

- run the sync agent on an on-site machine that has the database connection
  (`php artisan biometric:sync` needs nothing else), or
- use push mode, with the readers posting outbound to the push endpoint.

If you use push, keep pull enabled as well wherever the readers are reachable.
A notification that silently stops arriving is indistinguishable from a quiet
gym.

---

## 7. Backups

Daily and weekly backups run from the scheduler into `storage/app/backups`,
with retention set by `BACKUP_RETENTION_DAYS` (weekly copies are kept).

```bash
php artisan gms:backup --type=MANUAL
```

**Copy them off the server.** A backup on the same disk as the database does
not survive the failure it exists for. Add a nightly `rsync`, S3 upload or
equivalent.

**Back up `storage/app` as well.** The database holds the *record* of a
member's signed agreement; the file itself lives on disk under
`storage/app/members/`. A database-only backup restores a system that knows an
agreement exists and cannot produce it.

**Test a restore before you need one:**

```bash
mysql -u root -p gms_restore_test < storage/app/backups/gms-daily-....sql
```

A backup that has never been restored is a hypothesis.

### A note on credentials

`BackupService` passes the database password to `mysqldump` as a command-line
argument, which is visible in the process list. On a shared host, prefer a
credentials file:

```ini
# ~/.my.cnf, mode 600
[mysqldump]
user=gms_app
password=<password>
```

and drop the `--password=` argument from `app/Services/BackupService.php`.

---

## Upgrading

```bash
cd api
php artisan down
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force   # picks up new permissions
php artisan config:cache && php artisan route:cache
php artisan up

cd ../web && npm ci && npm run build
```

The role seeder is idempotent and safe on every deploy; it is how a new
permission reaches production without anyone editing the database by hand.

If an upgrade changes how a member's current membership or status is derived,
sweep the cached values afterwards — existing rows otherwise keep the old
answer:

```bash
php artisan members:refresh-state
```

---

## Health checks

| Check | Where |
|---|---|
| Application responding | `GET /up` |
| Attendance being collected | Fingerprint devices → sync status |
| Backups succeeding | Settings → Database backups |
| Scheduler running | `php artisan schedule:list`, and the device staleness flag |

Logs are in `api/storage/logs/`, rotated daily.
