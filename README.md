# Gym Management System

Membership, payments and fingerprint-based attendance for a multi-branch gym,
built to the attached SRS. Phase 1 (core) and Phase 2 (biometric attendance)
are implemented; the MVP scope in SRS §33 is complete.

| Layer | Technology |
|---|---|
| Frontend | React 19 + TypeScript + Tailwind CSS 4 (Vite) — works on a phone, tablet or desk |
| Backend | PHP 8.4 + Laravel 13 REST API (Sanctum tokens) |
| Database | MySQL / MariaDB |
| Biometrics | Hikvision ISAPI over HTTP digest — pluggable driver |
| PDFs | dompdf (invoices and receipts) |

```
D:\gms
├── api/           Laravel REST API, the public website, biometric agent, jobs
├── web/           React single-page application (the admin panel)
├── docs/          Setup, device integration and operations guides
└── build-release.ps1   Packs a deployable zip: panel + API, no dev data
```

The admin panel is responsive and is used on phones and tablets as well as at
a desk; there are no separate native applications.

---

## Quick start

Prerequisites: PHP 8.4 (with `pdo_mysql`, `gd`, `zip`, `intl`), Composer,
Node 20+, MySQL 8 or MariaDB 10.4+.

### 1. API

```bash
cd api
composer install
cp .env.example .env
php artisan key:generate
```

Create the database and point `.env` at it:

```sql
CREATE DATABASE gms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```
DB_DATABASE=gms
DB_USERNAME=root
DB_PASSWORD=
APP_TIMEZONE=Asia/Colombo
```

Then:

```bash
php artisan migrate --seed      # schema, roles, plans, admin account
php artisan storage:link        # required — member photos are served from here
php artisan serve               # http://127.0.0.1:8000
```

The seeder prints the administrator credentials:

```
admin@gms.local / ChangeMe123!
```

**Change this before the system is reachable by anyone else.**

Sample members, memberships, payments and attendance, for a demonstration:

```bash
php artisan db:seed --class=DemoDataSeeder
```

### 2. Web

```bash
cd web
npm install
npm run dev                     # http://localhost:5173
```

### 3. Make it yours

Sign in and go to **Settings → Branding** to set the gym's name and tagline,
upload a logo, and add photographs of your own gym for the sign-in screen. With
several images the panel fades between them. With none, it is drawn with the
brand gradient — no asset to load, sharp at any size.

The name and logo then appear in the sidebar, on the sign-in screen, and on
printed receipts and invoices.

The dev server proxies `/api` to `http://127.0.0.1:8000`, so both halves share
one origin and CORS behaves in development exactly as it will behind a reverse
proxy in production.

### 4. Background work

Attendance synchronisation, membership expiry, notifications and backups all
run from the scheduler. **Nothing biometric is collected automatically until
this is running:**

```bash
cd api
php artisan schedule:work       # development
```

In production, one cron entry (see [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)):

```cron
* * * * * cd /path/to/api && php artisan schedule:run >> /dev/null 2>&1
```

---

## Running both together

```powershell
.\dev.ps1
```

Finds a free port for the API, writes it into `web/.env` so the panel proxies
to the right place, and opens each service in its own window. `-ApiPort 8010`
forces a port.

Rerunning it is safe: an API or a panel already running is reused rather than
treated as a clash, so bringing one back after it died does not leave two of
the other on different ports.

By hand, if you prefer:

| Part | Command | Default |
|---|---|---|
| API + website | `cd api && php artisan serve` | http://127.0.0.1:8000 |
| Admin panel | `cd web && npm run dev` | http://localhost:5173 |

The admin panel proxies `/api` and `/storage` to the API, so the browser stays
on one origin.

### If every API call returns 404

**Something else is listening on port 8000.** It is a popular port, and Windows
will happily let two servers bind it — requests then go to whichever wins the
race, so the admin panel works intermittently and 404s the rest of the time.
The giveaway is a 404 body that is not this API's format: ours is plain
`{"message": "..."}`.

Find out what is there:

```powershell
Get-NetTCPConnection -LocalPort 8000 -State Listen |
  ForEach-Object { Get-CimInstance Win32_Process -Filter "ProcessId=$($_.OwningProcess)" } |
  Select-Object ProcessId, CommandLine
```

Then either stop it, or run this API elsewhere and point the panel at it:

```
web/.env    API_PROXY_TARGET=http://127.0.0.1:8010
```

Restart the Vite dev server after changing `web/.env` — the proxy target is
read once at start-up.

### If the database refuses to connect

**Check the port before anything else.** MariaDB under WAMP listens on **3307**
where MySQL would use 3306, and `.env` shipping the usual 3306 produces
`SQLSTATE[HY000] [2002]` with nothing in it to suggest the port is the problem.

```powershell
Get-NetTCPConnection -State Listen | Where-Object LocalPort -in 3306,3307
```

---

## What runs on a schedule

| Command | When | Purpose |
|---|---|---|
| `biometric:sync` | every minute | Pull attendance from every reader (SRS §13) |
| `memberships:expire` | 00:15 daily | Expire terms past their grace period (SRS §6) |
| `biometric:sync-access` | 00:30 daily | Stop the readers admitting lapsed members |
| `attendance:auto-close` | 23:30 daily | Close visits with no check-out (SRS §11) |
| `notifications:dispatch --queue-reminders` | 08:00 daily | Queue expiry reminders (SRS §21) |
| `notifications:dispatch` | every 15 min | Deliver queued messages |
| `gms:backup --type=DAILY` | 02:00 daily | Database backup (SRS §30) |
| `gms:backup --type=WEEKLY` | Sunday 03:00 | Weekly backup, retained longer |

All are safe to run by hand, and safe to run twice.

---

## Roles

| Role | Can do |
|---|---|
| Super Administrator | Everything, across all branches |
| Gym Administrator | All operations including the gym's branding; not user/role administration, branch creation, or resetting data |
| Receptionist | Members, memberships, renewals, payments, fingerprint enrolment, attendance, gym schedules — attendance reports only, not money |
| Trainer | Their assigned members' schedules and attendance — no access to money, no member documents, and no sight of other trainers' members |
| Accountant | Payments, refunds, invoices, expenses and the chart of accounts, all financial reports, audit log — read-only elsewhere, and no member documents |

Permissions are enforced by the API on every request. The interface hides what
a user cannot reach, but that is a convenience, not the control.

---

## Your public website

The system also serves a marketing website for the gym, edited entirely from
**Website** in the admin panel — front-page headline, About text, facilities,
opening hours, photo gallery, news posts, testimonials and a class timetable.

Everything the system already knows is read **live**: prices come from your
membership plans, the team from your trainers, the address from your branches,
the name and logo from your branding. Change a price in the admin panel and the
website is already correct — there is no second copy to keep in step.

It is designed dark and high-contrast around your own photographs, with a
crossfading banner, scroll animations, counters and a gallery lightbox — all of
which switch themselves off for visitors who have asked their device for
reduced motion, and none of which are needed for the site to work.

The contact form creates an entry under **Enquiries**, where reception can mark
it contacted, add notes, and turn it into a member in one click.

It stays offline until you publish it, so nothing goes on the internet under
your gym's name before you are ready. Full detail in
[docs/WEBSITE.md](docs/WEBSITE.md).

---

## Money in, money out

**Payments** records what members pay. **Expenses** records what the gym pays —
rent, salaries, equipment, utilities — against your own categories, which you
edit under **Expenses → Categories**. Mark the ones that recur every month and
the reports will separate what the gym costs to keep open from what it spent
once.

Together they give **Reports → Profit & loss**: income against costs, month by
month, with the margin. It uses net revenue, so money refunded is not counted as
earned.

Expenses are visible to administrators and accountants. Reception does not see
them — the cost base is the gym's margin.

### Every report exports

Each tab on the Reports screen has its own **Export CSV**, and the tabs that
show both a chart and a table offer both:

| Tab | Exports |
|---|---|
| Attendance | Visits per member · day by day |
| Revenue | Every payment · takings per day |
| Expenses | Every expense · spending per category |
| Profit & loss | Month by month, with a total row |
| Outstanding | Unsettled invoices |

Files open straight in Excel — they carry a UTF-8 marker, so names with accents
come through intact. You only see the buttons for reports you have permission to
download.

---

## Signing in and staying safe

Your session is held in a cookie the page cannot read, so it cannot be copied
out of the browser by anything running on it. Other websites cannot use it: the
browser will not attach it to a request they start, and the server refuses any
write it does not recognise as coming from this application.

A session that goes unused ends on its own — 12 hours by default, so a terminal
left signed in at the front desk does not stay open overnight. Repeated wrong
passwords lock out further attempts for a minute.

Click your name at the bottom of the sidebar to open **Your account**, where you
can change your password and see every device your account is signed in on, with
where and when. Anything you do not recognise can be signed out on the spot, or
use **Sign out everywhere else** to end them all at once.

Full detail, including what to configure before going live, is in
[docs/SECURITY.md](docs/SECURITY.md).

---

## Starting over

**Settings → Reset data** (Super Administrator only) clears the gym back to a
chosen point — useful after a trial run, or when handing a fresh system to a new
site.

| Scope | Removes |
|---|---|
| Attendance records | Check-ins, check-outs and raw device events |
| …and transactions | The above, plus payments, invoices, membership terms and recorded expenses |
| …and members | The above, plus members, documents, fingerprints and workout plans |
| Everything | The above, plus trainers, plans, expense categories and registered devices |

Staff accounts, roles, branches, settings and branding are never touched, so you
can sign straight back in and keep working.

It asks for your own password and for the word `RESET`, and it takes a full
database backup first — **if the backup fails, nothing is deleted** and it tells
you why. Restoring that backup is a manual job (see
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)); treat a reset as a one-way door.

---

## Documentation

- **[docs/BIOMETRIC-SETUP.md](docs/BIOMETRIC-SETUP.md)** — connecting the
  Hikvision reader, enrolling fingerprints, push notifications, and the
  questions from SRS §35 that need answering before installation.
- **[docs/HEALTH-SCREENING.md](docs/HEALTH-SCREENING.md)** — the PAR-Q form
  members sign on their own phone, what makes it evidence, and why a "yes"
  cannot be ignored.
- **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** — production deployment, cron,
  backups, HTTPS.
- **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)** — how the pieces fit, the
  decisions behind them, and the database schema.
- **[docs/API.md](docs/API.md)** — endpoint reference.
- **[docs/SECURITY.md](docs/SECURITY.md)** — how sessions and cookies work,
  what protects them, and the checklist to work through before going live.
- **[docs/WEBSITE.md](docs/WEBSITE.md)** — the public website, what is editable
  and what is read live from the system.

---

## Tests

```bash
cd api
php artisan test
```

113 tests covering membership dates and renewal chaining, the attendance state
machine, biometric synchronisation and idempotency, the payment ledger, member
documents, gym schedules, branding, and role-based access.

The suite runs against MySQL, not SQLite — see the comment in `phpunit.xml`.
Create the test database once:

```sql
CREATE DATABASE gms_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

---

## Not built

Out of the agreed scope, and listed here so nobody assumes otherwise:

- **Class booking** — group classes with capacity, member bookings and a
  waitlist. SRS §34, not built. The website publishes a class **timetable**,
  which is a poster on the wall: it tells members what runs when, and holds no
  reservations. "Gym schedules" in the admin panel is a member's own weekly
  training programme, which is a third, separate thing.
- **SMS and WhatsApp** notifications are queued with the right recipient and
  body, and left pending until a provider is configured — no provider is
  chosen in the SRS (§21).
- **Member mobile app, online payments, class booking, QR attendance** — SRS
  §34, future phases.
