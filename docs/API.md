# API reference

159 endpoints. Generated from the application's route table.

## Authentication

Two ways in, for two kinds of caller. Everything except login and the device
push endpoint needs one of them.

**Browsers — a cookie.** `POST /api/auth/login` sets an httpOnly, SameSite=Lax
cookie and returns the user, **not** the token: anything in the body is readable
by script, and a session script can read is a session XSS can steal. Send the
cookie plus a custom header on any write:

```
Cookie: gms_session=…        (set and sent by the browser)
X-GMS-Request: 1             (required on POST/PUT/PATCH/DELETE)
Accept: application/json
```

A cookie-authenticated write without that header is refused with **419**. A
cross-site form cannot set one, which is the point.

**Integrations — a bearer token.** Pass `issue_token: true` to login and manage
the token yourself. Bearer callers are exempt from the header rule, because
nothing sends a bearer token automatically.

```
Authorization: Bearer <token>
Accept: application/json
```

Either way the credential is the same Sanctum token: issued per device, valid
for 30 days, ended after 12 hours unused, and revoked the moment the account is
deactivated. See [SECURITY.md](SECURITY.md).

Permissions are enforced on every request. A caller without the permission
listed below receives `403` with a message suitable for display.

### Conventions

- List endpoints are paginated: `?page=`, `?per_page=` (max 200).
- Dates are `YYYY-MM-DD`; timestamps are ISO 8601 in the application timezone.
- Money is a number with two decimal places.
- Validation failures return `422` with `{ "message": ..., "errors": { field: [...] } }`.
- Business-rule refusals return `422` with `{ "message": ... }` — a sentence
  that can be shown to the user as it is.
- `419` means a cookie-authenticated write arrived without the required header.
- Every response carries `nosniff`, `X-Frame-Options`, a content policy and a
  referrer policy; HSTS is added over HTTPS.


## Authentication

_SRS §29_

| Method | Endpoint | Permission |
|---|---|---|
| POST | `/api/auth/login` | **public** |
| POST | `/api/auth/logout` | any signed-in user |
| GET | `/api/auth/me` | any signed-in user |
| POST | `/api/auth/password` | any signed-in user |
| GET | `/api/auth/sessions` | any signed-in user |
| DELETE | `/api/auth/sessions/{id}` | any signed-in user |
| POST | `/api/auth/sessions/revoke-others` | any signed-in user |

## Dashboard

_SRS §15_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/dashboard` | any signed-in user |
| GET | `/api/dashboard/summary` | any signed-in user |

## Members

_SRS §4_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/members` | `members.view` |
| POST | `/api/members` | `members.create` |
| DELETE | `/api/members/{member}` | `members.delete` |
| GET | `/api/members/{member}` | `members.view` |
| PUT | `/api/members/{member}` | `members.update` |
| GET | `/api/members/{member}/documents` | `documents.view` |
| POST | `/api/members/{member}/documents` | `documents.manage` |
| DELETE | `/api/members/{member}/documents/{document}` | `documents.manage` |
| GET | `/api/members/{member}/documents/{document}` | `documents.view` |
| PUT | `/api/members/{member}/documents/{document}` | `documents.manage` |
| GET | `/api/members/{member}/payments` | `members.view` |
| DELETE | `/api/members/{member}/photo` | `members.update` |
| POST | `/api/members/{member}/photo` | `members.update` |
| GET | `/api/members/{member}/schedule` | `workouts.view` |
| GET | `/api/members/duplicate-check` | `members.view` |

## Membership plans

_SRS §5_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/membership-plans` | `plans.view` |
| POST | `/api/membership-plans` | `plans.manage` |
| DELETE | `/api/membership-plans/{membershipPlan}` | `plans.manage` |
| GET | `/api/membership-plans/{membershipPlan}` | `plans.view` |
| PUT | `/api/membership-plans/{membershipPlan}` | `plans.manage` |

## Memberships & renewals

_SRS §5, §6_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/memberships` | `memberships.view` |
| POST | `/api/memberships` | `memberships.create` |
| DELETE | `/api/memberships/{membership}` | `memberships.cancel` |
| GET | `/api/memberships/{membership}` | `memberships.view` |
| POST | `/api/memberships/{membership}/activate` | `memberships.create` |
| POST | `/api/memberships/{membership}/renew` | `memberships.renew` |
| GET | `/api/memberships/{membership}/renewal-preview` | `memberships.view` |
| POST | `/api/memberships/{membership}/suspend` | `memberships.cancel` |

## Payments & refunds

_SRS §17_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/payments` | `payments.view` |
| POST | `/api/payments` | `payments.create` |
| GET | `/api/payments/{payment}` | `payments.view` |
| GET | `/api/payments/{payment}/receipt` | `payments.view` |
| POST | `/api/payments/{payment}/refund` | `payments.refund` |

## Invoices & receipts

_SRS §18_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/invoices` | `invoices.view` |
| GET | `/api/invoices/{invoice}` | `invoices.view` |
| GET | `/api/invoices/{invoice}/pdf` | `invoices.view` |

## Expenses

_SRS §17_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/expenses` | `expenses.view` |
| POST | `/api/expenses` | `expenses.manage` |
| DELETE | `/api/expenses/{expense}` | `expenses.manage` |
| GET | `/api/expenses/{expense}` | `expenses.view` |
| PUT | `/api/expenses/{expense}` | `expenses.manage` |

## Expense categories

_SRS §17_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/expense-categories` | `expenses.view` |
| POST | `/api/expense-categories` | `expense-categories.manage` |
| DELETE | `/api/expense-categories/{expenseCategory}` | `expense-categories.manage` |
| PUT | `/api/expense-categories/{expenseCategory}` | `expense-categories.manage` |

## Attendance

_SRS §9–12_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/attendance` | `attendance.view` |
| POST | `/api/attendance` | `attendance.manage` |
| DELETE | `/api/attendance/{attendance}` | `attendance.manage` |
| PUT | `/api/attendance/{attendance}` | `attendance.manage` |
| GET | `/api/attendance/live` | `attendance.view` |
| GET | `/api/attendance/member/{member}` | `attendance.view` |
| GET | `/api/attendance/validate/{member}` | `attendance.view` |

## Biometric devices & enrolment

_SRS §8, §13, §23, §24_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/biometric/devices` | `devices.view` |
| POST | `/api/biometric/devices` | `devices.manage` |
| DELETE | `/api/biometric/devices/{device}` | `devices.manage` |
| GET | `/api/biometric/devices/{device}` | `devices.view` |
| PUT | `/api/biometric/devices/{device}` | `devices.manage` |
| POST | `/api/biometric/devices/{device}/test` | `devices.view` |
| POST | `/api/biometric/devices/{device}/open-door` | `devices.open_door` |
| GET | `/api/biometric/drivers` | `devices.view` |
| POST | `/api/biometric/push/{deviceCode}` | **public** |
| POST | `/api/biometric/sync` | `devices.sync` |
| GET | `/api/biometric/sync-logs` | `devices.view` |
| GET | `/api/biometric/sync-status` | `devices.view` |
| GET | `/api/biometric/users` | `devices.view` |
| POST | `/api/biometric/users` | `enrollment.manage` |
| DELETE | `/api/biometric/users/{biometricUser}` | `enrollment.manage` |
| POST | `/api/biometric/users/{biometricUser}/match` | `enrollment.manage` |
| POST | `/api/biometric/users/{biometricUser}/change-id` | `enrollment.manage` |
| POST | `/api/biometric/users/{biometricUser}/verify` | `enrollment.manage` |
| POST | `/api/biometric/users/sync` | `devices.sync` |

## Reports

_SRS §16, §17_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/reports/attendance/daily` | `reports.view` |
| GET | `/api/reports/attendance/export` | `reports.view` |
| GET | `/api/reports/attendance/member/{member}` | `reports.view` |
| GET | `/api/reports/attendance/members` | `reports.view` |
| GET | `/api/reports/attendance/monthly` | `reports.view` |
| GET | `/api/reports/expenses` | `expenses.view` |
| GET | `/api/reports/export/{report}` | depends on the report — see the table below |
| GET | `/api/reports/exports` | any signed-in user (lists only what they may download) |
| GET | `/api/reports/outstanding` | `reports.view` |
| GET | `/api/reports/profit-loss` | `reports.view` |
| GET | `/api/reports/revenue` | `reports.view` |

## Trainers

_SRS §19_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/trainers` | `trainers.view` |
| POST | `/api/trainers` | `trainers.manage` |
| DELETE | `/api/trainers/{trainer}` | `trainers.manage` |
| GET | `/api/trainers/{trainer}` | `trainers.view` |
| PUT | `/api/trainers/{trainer}` | `trainers.manage` |
| GET | `/api/trainers/{trainer}/members` | `trainers.view` |

## Workout plans

_SRS §20_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/workout-plans` | `workouts.view` or `workouts.manage` |
| POST | `/api/workout-plans` | `workouts.view` or `workouts.manage` |
| DELETE | `/api/workout-plans/{workout_plan}` | `workouts.view` or `workouts.manage` |
| GET | `/api/workout-plans/{workout_plan}` | `workouts.view` or `workouts.manage` |
| PUT | `/api/workout-plans/{workout_plan}` | `workouts.view` or `workouts.manage` |

## Notifications

_SRS §21_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/notifications` | any signed-in user |
| POST | `/api/notifications/{notification}/read` | any signed-in user |
| POST | `/api/notifications/read-all` | any signed-in user |
| GET | `/api/notifications/unread-count` | any signed-in user |

## Branches

_SRS §22_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/branches` | any signed-in user |
| POST | `/api/branches` | `branches.manage` |
| DELETE | `/api/branches/{branch}` | `branches.manage` |
| GET | `/api/branches/{branch}` | any signed-in user |
| PUT | `/api/branches/{branch}` | `branches.manage` |

## Staff accounts

_SRS §3_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/users` | `users.manage` |
| POST | `/api/users` | `users.manage` |
| DELETE | `/api/users/{user}` | `users.manage` |
| GET | `/api/users/{user}` | `users.manage` |
| PUT | `/api/users/{user}` | `users.manage` |

## Roles

_SRS §3_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/roles` | `users.manage` |

## Audit log

_SRS §25_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/audit-logs` | `audit.view` |
| GET | `/api/audit-logs/events` | `audit.view` |
| GET | `/api/audit-logs/record/{type}/{id}` | `audit.view` |

## Settings

_SRS §10, §23_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/settings` | `settings.manage` |
| PUT | `/api/settings` | `settings.manage` |

## Gym identity & imagery

_SRS §23_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/branding` | **public** |
| PUT | `/api/branding` | `branding.manage` |
| GET | `/api/branding/assets` | `branding.manage` or `website.manage` |
| POST | `/api/branding/assets` | `branding.manage` or `website.manage` |
| DELETE | `/api/branding/assets/{asset}` | `branding.manage` or `website.manage` |
| PUT | `/api/branding/assets/{asset}` | `branding.manage` or `website.manage` |

## Public website content

_SRS §23_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/website` | `website.manage` |
| PUT | `/api/website` | `website.manage` |
| GET | `/api/website/posts` | `website.manage` |
| POST | `/api/website/posts` | `website.manage` |
| DELETE | `/api/website/posts/{post}` | `website.manage` |
| PUT | `/api/website/posts/{post}` | `website.manage` |
| GET | `/api/website/testimonials` | `website.manage` |
| POST | `/api/website/testimonials` | `website.manage` |
| DELETE | `/api/website/testimonials/{testimonial}` | `website.manage` |
| PUT | `/api/website/testimonials/{testimonial}` | `website.manage` |
| GET | `/api/website/timetable` | `website.manage` |
| POST | `/api/website/timetable` | `website.manage` |
| DELETE | `/api/website/timetable/{entry}` | `website.manage` |
| PUT | `/api/website/timetable/{entry}` | `website.manage` |

## Website enquiries

_SRS §23_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/leads` | `leads.view` |
| DELETE | `/api/leads/{lead}` | `leads.manage` |
| PUT | `/api/leads/{lead}` | `leads.manage` |
| POST | `/api/leads/{lead}/convert` | `leads.manage` |

## Backups

_SRS §30_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/backups` | `backups.manage` |
| POST | `/api/backups` | `backups.manage` |
| DELETE | `/api/backups/{backupLog}` | `backups.manage` |
| GET | `/api/backups/{backupLog}/download` | `backups.manage` |

## Data reset

_SRS §30_

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/data-reset` | `data.reset` |
| POST | `/api/data-reset` | `data.reset` |

---

## Member self-service

These endpoints were built for a member phone app that has since been dropped.
They are kept because they are tested, carry no cost while unused, and are the
branch any future member-facing client would speak to — a web portal as much
as a native app.

Its own branch of the API, and deliberately not nested inside the staff routes.
Every endpoint returns only the caller's own data and **takes no member id from
the request**, so there is no id to check and no way to ask about anybody else.

| Method | Endpoint | Permission |
|---|---|---|
| POST | `/api/member/auth/request-code` | **public** |
| POST | `/api/member/auth/verify` | **public** |
| POST | `/api/member/auth/logout` | member token |
| GET | `/api/member/me` | member token |
| GET | `/api/member/attendance` | member token |
| GET | `/api/member/payments` | member token |
| GET | `/api/member/receipts/{payment}` | member token (their own receipt only) |
| GET | `/api/member/timetable` | member token |
| GET | `/api/member/gym` | member token |

### Members have no password

`request-code` sends a six-digit code to the telephone or email already on the
member's record; `verify` exchanges it for a bearer token. The code expires in
minutes, survives a handful of wrong guesses before destroying itself, and is
single-use — all three limits are what make six digits safe.

An unknown identifier returns **the same response as a real one**. A different
answer would turn this endpoint into a way to ask "is this person a member
here?".

### The wall between members and staff

Sanctum's token table is polymorphic, so a member's token is a valid token as
far as `auth:sanctum` is concerned. Two middleware check the **type** of the
token holder rather than what it can do:

- `staff.only` wraps the entire staff API — a member token gets `403`.
- `member.only` wraps `/api/member/*` — a staff token gets `403`, and a member
  blacklisted since signing in is cut off with their tokens deleted.

Most staff routes would refuse a member anyway for want of a permission, but
`auth/me` and the session endpoints have no permission gate — and "most" is not
a security boundary.

---

## Notable endpoints

### `GET /api/attendance/validate/{member}`

Answers "would this member be let in right now?" **without recording
anything**. The check-in desk calls it before the member scans, so staff can
start a renewal conversation instead of recording a refused entry.

```json
{
  "valid": false,
  "message": "Access denied: membership is awaiting payment.",
  "currently_checked_in": false,
  "member": { "id": 21, "full_name": "...", "membership_number": "GM-2026-00021", "status": "INACTIVE" },
  "membership": { "reference": "MS-2026-00021", "plan_name": "Monthly", "end_date": "2026-10-11", "days_remaining": 29 }
}
```

### `POST /api/attendance`

Records a manual check-in or check-out. Returns the outcome rather than failing,
because a refused entry is a normal result the desk needs a message for:

```json
{
  "message": "Welcome, Nimal Perera. Checked in at 18:42.",
  "accepted": true,
  "result": "PROCESSED",
  "data": { "...attendance record..." }
}
```

`result` is one of `PROCESSED`, `DUPLICATE`, `UNMATCHED`, `REJECTED`, `ERROR`.

### `GET /api/memberships/{membership}/renewal-preview`

Quotes the dates and price of a renewal before committing, so the figures
reception reads out are the figures that will be written. The rules live on the
server only.

### `POST /api/biometric/sync`

Runs a synchronisation immediately and returns the result — an administrator
pressed the button to find out whether the reader is working, not to receive a
job id. Safe to call repeatedly; duplicate events are absorbed.

### `POST /api/biometric/push/{deviceCode}`

The only endpoint a fingerprint reader talks to. Authenticated by shared secret
(`X-GMS-Token`), compared in constant time. See
[BIOMETRIC-SETUP.md](BIOMETRIC-SETUP.md).

### `GET /api/invoices/{invoice}/pdf` and `GET /api/payments/{payment}/receipt`

Stream a PDF inline, so the browser's print dialog opens straight onto it.

### `GET /api/reports/export/{report}`

Every report exports as CSV through one endpoint. `GET /api/reports/exports`
lists the reports **this caller** may download, which is what the interface uses
to decide which buttons to show.

| Report | Contains | Permission |
|---|---|---|
| `attendance-members` | Visits per member | `reports.view` |
| `attendance-daily` | Day by day | `reports.view` |
| `revenue-payments` | Every payment, row by row | `reports.financial` |
| `revenue-daily` | Takings per day | `reports.financial` |
| `outstanding` | Unsettled invoices | `reports.financial` |
| `expenses` | Every expense, row by row | `expenses.view` |
| `expenses-by-category` | Spending per category | `expenses.view` |
| `profit-loss` | Income, expenses and profit per month | `reports.financial` |

Takes `?from=` and `?to=`. Rows are streamed rather than assembled in memory,
so a year of attendance at the scale in SRS §30 does not have to fit in a PHP
request, and each file opens with a UTF-8 byte-order mark so Excel reads names
correctly.

`GET /api/reports/attendance/export` still works and is the same exporter
underneath.

### The public website

The website is **not** part of this API. It is server-rendered by the same
Laravel application on ordinary web routes — `/`, `/pricing`, `/blog/{slug}`
and so on — so a search engine is given real HTML and a visitor does not
download the admin bundle to read the opening hours. See
[WEBSITE.md](WEBSITE.md).

The endpoints above cover the admin side of it:

- `GET|PUT /api/website` — the editable copy, all of it under the
  `website.manage` permission.
- `/api/website/posts`, `/api/website/testimonials`, `/api/website/timetable` —
  ordinary CRUD.
- Images go through the existing `/api/branding/assets` endpoints. Which image
  *types* a caller may touch depends on their permission: `branding.manage`
  covers the logo and sign-in screen, `website.manage` covers the site's hero
  and gallery. Editing the website's photographs does not confer the ability to
  replace the logo printed on every receipt.

Prices, trainers, the gym's address and its name are **not** editable here.
The website reads those live from membership plans, trainers, branches and
branding, so there is no second copy to drift.

### `POST /contact` (public, not `/api`)

The enquiry form. Creates a lead, throttled at 10 per 10 minutes, with a
honeypot field that silently absorbs form spam. Returns a redirect, not JSON —
it is submitted by a plain HTML form so that it works without JavaScript.

### `POST /api/leads/{lead}/convert`

Turns an enquiry into a member, carrying the name and telephone across and
linking the two records. That link is what lets a gym answer whether the
website is paying for itself.

### `GET /api/reports/profit-loss`

Takings against costs, grouped by calendar month. Requires **both**
`reports.financial` and `expenses.view` — a margin shown to someone who cannot
see the cost base is not one they can check. Uses net revenue, not gross:
money refunded was never the gym's to count.

Months with costs and no income still appear, and `margin_percent` is `null`
rather than zero when nothing came in.

### `GET /api/data-reset` and `POST /api/data-reset`

The `GET` is a preview: it returns each scope with a live row count per table,
so the confirmation can name exactly what is about to be destroyed rather than
asking "are you sure?".

The `POST` requires all three of:

- the `data.reset` permission, which **only Super Admin holds** — a Gym Admin
  can run the business but cannot erase its history;
- `password`, the caller's own, re-entered and checked with `Hash::check`;
- `confirm: "RESET"`, typed rather than clicked.

A full backup runs first. If it fails, **nothing is deleted** and the response
says so. Scopes are cumulative — `MEMBERS` includes everything `TRANSACTIONS`
removes — and staff accounts, roles, branches, settings and branding survive
every scope, so the system is still usable afterwards.

```json
{
  "message": "Deleted 1,284 rows.",
  "backup": { "filename": "gms-manual-2026-09-13_081106.sql", "size_bytes": 442368 },
  "deleted": { "attendance": 369, "biometric_event_logs": 915 }
}
```
