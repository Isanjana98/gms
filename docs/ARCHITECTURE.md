# Architecture

How the system is put together, and why. Section references are to the SRS.

---

## Shape

```
   React SPA  ──HTTPS──►  Laravel API  ──►  MySQL / MariaDB
  (browser)               (web tier)            ▲
                                                │
                          Biometric agent ──────┘
                          (php artisan biometric:sync)
                                 │
                          ISAPI over LAN
                                 ▼
                          Fingerprint readers
```

SRS §13 asks for a separate integration agent rather than the web application
talking to devices directly. That is what the scheduled `biometric:sync`
command is: a distinct process, which can run on a machine on the gym LAN
alongside the readers while the web tier never needs to reach them. It shares
the codebase — so one set of models, one set of business rules, one place where
an attendance record is created — but not the request lifecycle.

The readers are also allowed to push events to `/api/biometric/push/{code}`.
That is an optimisation layered on top of polling, never a replacement for it.

---

## The decision the rest of the design rests on

**The device is the source of truth, and reading the same event twice must be
free.**

Every scan is stored in `biometric_event_logs` with
`event_hash = sha256(device, biometric user, UTC timestamp, direction)` under a
unique index. Ingestion therefore cannot double-count, no matter how the event
arrives or how many times.

Everything awkward about biometric integration then stops being a special case:

| Problem | What it becomes |
|---|---|
| Sync window overlap (readers report late) | Just re-read; duplicates are absorbed |
| Administrator presses "Sync" twice | Second run reports duplicates |
| Reader buffered 3 days through an outage (§14) | A larger ordinary pull |
| Event arrives by push *and* by pull | Stored once |
| A sync crashes halfway | Re-run it |

The raw log also keeps the unmodified device payload, so an attendance dispute
can be settled against what the hardware actually reported.

`biometric_event_logs` is not in the SRS §28 table list. It is the mechanism
that makes SRS §13 and §14 work, so it is there deliberately.

---

## Layers

**Controllers** validate input, apply branch scoping, and shape responses.
They contain no business rules.

**Services** hold the rules, and are the only things that write:

| Service | Responsible for |
|---|---|
| `MembershipService` | Term dates, renewal chaining, activation, expiry sweep |
| `PaymentService` | The ledger; keeps every derived total in step with it |
| `InvoiceService` | Invoices, and recomputing totals from line items |
| `Attendance\AttendanceService` | Scan → visit; validation, direction, duplicates |
| `Biometric\BiometricSyncService` | Pulling, ingesting, reconciling users |
| `Biometric\EnrollmentService` | The five steps of SRS §8 |
| `ReportService`, `DashboardService` | Aggregation, in SQL |

**Models** carry relationships, casts and query scopes. Auditing is a model
concern (see below), not a controller one.

---

## Decisions worth knowing about

### Money is an append-only ledger

`payments` rows are never edited. A refund is a new row with a negative amount
and `refund_of_payment_id` set. The receipt a member was handed still exists in
the ledger exactly as printed, and the sum of a member's rows is always their
true position.

`memberships.paid_amount` and `invoices.paid_amount` are caches recomputed from
that ledger after every write, never incremented in place — a stored total that
has drifted from its own source is a bug nobody notices until an accountant does.

A membership activates the moment its balance reaches zero (SRS §32 steps 3
and 6), so payment and access cannot disagree.

### A membership term ends the day before, not on, the anniversary

A one-month membership bought on 1 January runs to 31 January. Ending it on
1 February would hand the member a free day on every renewal, which compounds
over a year of monthly terms. A renewal likewise starts the day *after* the
previous expiry — reusing the expiry date would sell them a day they already had.

Month arithmetic uses `addMonthsNoOverflow`, so 31 January plus one month is
28 February and not 3 March.

### Renewing early does not cost the member days

A renewal continues from the current expiry date, not from today, so somebody
renewing a week early keeps the week they paid for. Past
`MEMBERSHIP_RENEW_CHAIN_GRACE_DAYS` (default 30) after expiry that continuity
is a fiction, and the new term simply starts today.

### Plan details are copied onto the membership at sale time

`memberships` stores `plan_name` and `price` rather than only a plan id.
Repricing a plan must never rewrite what an existing member was charged.

### The member's membership state is cached

`members.current_membership_id` and `members.membership_expires_on` are
denormalised. Attendance validation runs on every scan at the door, and at the
10,000-member scale of SRS §30 it should not pay for a sort over the whole
memberships table at 6pm. `MembershipService::refreshMemberState()` is the only
thing allowed to write those columns.

That method also refuses to overwrite `BLACKLISTED` or `SUSPENDED` — an
administrative decision about a person must survive a nightly job noticing they
renewed.

"Current membership" means **the term that admits them today**, not the
furthest-out one. A member who renews early holds two live memberships: this
month's, paid and running, and next month's, dated ahead and unpaid. Pointing
at the latter would refuse them at the door for "awaiting payment" while they
hold a valid term — punishing exactly the behaviour the gym wants to encourage.

If that derivation is ever changed again, existing rows keep the old answer
until swept: `php artisan members:refresh-state`.

### Auditing hooks model events, not controllers

The `Auditable` trait listens to Eloquent events, so a record changed by an
artisan command, a queued job or the biometric agent is logged exactly as one
changed through the interface. An audit trail with holes in it is worse than
none, because it invites trust it has not earned.

`audit_logs` has no foreign key to `users`: the actor's id, name and role are
denormalised onto the row so the log outlives the account it describes.

### Branch scoping is an explicit scope, not a global one

A Super Administrator legitimately needs to see every branch. A global scope
that must be disabled for the most privileged role is a trap waiting for the
first developer who forgets it exists, so callers ask for the restriction by
name: `->visibleTo($user)`.

### Lists are tables on a desk and cards on a phone

The usual answer for a wide table on a small screen is to let it scroll
sideways. That is wrong here: the columns that fall off the right edge are
*status* and *expiry* — precisely what someone checks a member for. Reception
standing on the gym floor with a phone would see a name and a number and have
to scroll to learn anything useful.

`RecordList` renders a real table from `md` up and a stack of cards below it,
with each page supplying both. Two renderers rather than one layout that
reflows, because a phone row and a desk row want different fields in a
different order, and a compromise between them suits neither.

Three rules keep it honest:

- **Nothing may widen the page.** A grid or flex child will not shrink below
  its content's intrinsic width, so one long email address in a narrow column
  silently pushed the whole page sideways. Fixed with `min-w-0` on the
  containers and `.wrap-anywhere` on free-text values — deliberately *not* with
  `overflow-x: hidden` on the body, which hides the symptom, clips whatever
  caused it, and would stop the audit below from ever catching it again.
- **Touch targets grow only where the pointer is coarse.** A single
  `@media (pointer: coarse)` block raises controls to 40px, so the desktop
  layout keeps its density and the phone gets targets a finger can hit.
- **Tab strips scroll; content does not.** Six tabs will not fit on a phone,
  and wrapping them onto two rows makes the header taller than the content it
  labels.

There is an audit script for this (`scratchpad/mobile-audit.cjs` in the working
notes): it drives every screen at 390px and fails on horizontal overflow or
undersized controls. Worth re-running after adding a screen — the failure mode
is invisible on a developer's monitor.

### Branding is data, not a build-time asset

The gym's name, logo, tagline and sign-in imagery are uploaded and edited
through the application, not configured in a file and redeployed. A gym
rebrands, opens a second site, or simply wants a better photograph, and none of
that should need a developer.

`GET /api/branding` is the one endpoint in the system that is **public**. It has
to be: the sign-in screen renders before anyone has a token, and refusing it
would leave the first screen a visitor sees looking like nobody's software. It
returns exactly four fields — name, tagline, logo URL, image URLs — which is
precisely what is already on display there and nothing more. A test asserts the
key list, so widening it is a deliberate act rather than an accident.

Everything that *changes* branding sits behind `branding.manage`, held by
administrators and not by reception: it is what every member and visitor sees.

Two details worth keeping:

- **The sign-in overlay is two layers, not one.** A single wash heavy enough to
  guarantee readable text also erases the photograph, which defeats the point of
  uploading one. A light brand tint covers the panel; a stronger scrim sits only
  at the bottom where the text is. The management screen previews with the same
  two layers, so what an administrator approves is what a visitor sees.
- **A missing logo is not an error state.** It falls back to the first letter of
  the gym's name, so a fresh installation looks deliberate rather than
  unfinished, and `useBranding()` resolves to neutral defaults if the request
  fails. Branding is decoration; it must never be the reason someone cannot
  sign in.

### Uploaded files: two disks, on purpose

| What | Disk | Served by | Why |
|---|---|---|---|
| Profile photos | `public` | Static URL under `/storage` | Shown 25 at a time in list views. Routing every avatar through an authenticated fetch would mean 25 blob requests per page. The stored filename is a 40-character random hash, so a photo cannot be reached by guessing a member number. |
| Agreements, ID copies, medical clearances | `local` (private) | Streamed through the API, permission-checked on every read | These carry a signature, an address and usually an ID number. They must not exist at a URL that works without a session. |

That is a deliberate asymmetry, not an oversight. If a deployment needs photos
private too, move them to the `local` disk and serve them through a temporary
signed URL — the resource already returns `photo_url` as a single value, so
nothing else changes.

Uploads are constrained by an allow-list of types, not a deny-list, and both
the extension and the MIME type are checked because either alone is forgeable.
Filenames are generated, so a file called `../../.env` achieves nothing.

### Documents are versioned, never overwritten

Replacing an agreement writes a new row pointing at the old one through
`replaces_id` and soft-deletes the old. Removing one is also a soft delete, and
the file stays on disk.

This is the same reasoning as the payment ledger: a gym asked *"what did this
member actually sign in March?"* — in a billing dispute, or an injury claim —
has to be able to answer. Destroying the previous version is the one thing the
interface must not let someone do by accident.

### Seeing everything is its own permission

A trainer holds both `workouts.view` and `workouts.manage` — for their own
members. So "can they manage?" cannot stand in for "may they see everyone's?",
and `workouts.view-all` exists to say the second thing explicitly. Reception
and administrators hold it; trainers do not.

The scoping keys off that permission rather than off whether the user has a
linked trainer record. Keying on the record fails open: a Trainer-role account
that was never linked to one skipped the filter entirely and could read the
whole gym's programmes. It now sees nothing, which is the right answer when the
link is missing.

The list filter and the single-schedule endpoint apply the same rule — member
ids are sequential, so a restriction that only covers the list is not a
restriction at all.

### The super administrator holds no permissions

It is granted everything by a `Gate::before` rule. A feature added next month
is therefore covered without a migration, and the role cannot drift out of date
with the permission catalogue.

### Device credentials are encrypted

A reader's password opens a physical door. It is encrypted at rest, excluded
from the audit log, and never returned by the API in any form.

### The website has no content of its own where the system already has it

The public site is server-rendered Blade on `routes/web.php`, not part of the
React admin app: a crawler indexing "gym in Colombo" gets real HTML, and a
visitor reading the opening hours does not download the admin bundle.

The rule the whole design rests on is that **anything the system already knows
is read live**. Prices come from `membership_plans`, the team from `trainers`,
the address from `branches`, the name and logo from branding. Only the copy the
system cannot know — the headline, the About text, the opening hours — is
editable, and that reuses the existing `settings` table under a `website` group
rather than a table of its own. A website with its own copy of the prices is a
website that eventually quotes a figure reception will not honour.

Sections render only when switched on *and* non-empty, so a gym with no trainers
and no photographs gets a short tidy page rather than three empty headings. The
whole site 404s until it is published, so a fresh installation never puts
placeholder copy on the internet under a real gym's name.

Enquiries become `leads` — their own table, not members with a status, because a
lead carries none of a member's obligations and most never become one.
`converted_member_id` is the join that answers whether the website pays for
itself.

#### Two traps in this area

**`View::share()` binds once, not per render.** It writes into the view factory,
which is a singleton, so site-wide data shared from a controller constructor is
decided the first time that controller is built. It showed up as a Gallery link
that would not appear until the process restarted, because the navigation had
been computed before the first image was uploaded. `SiteController::page()`
merges the shared data into every render instead.

**A `json` setting comes back already decoded.** `Setting::get()` returns an
array for those, so a template casting one to a string — the natural thing for a
template to do — is an "Array to string conversion" that only appears once
somebody fills the field in. Views take plain strings from `SiteContent::copy()`
and never touch the model.

### The session is a cookie the page cannot read

The token used to live in `localStorage`. It now lives in an httpOnly cookie,
and the front end has no token handling left in it at all.

The reasoning is about blast radius rather than about cookies being nicer. With
a readable token, one cross-site scripting hole anywhere in the front end hands
an attacker a credential they can keep using from their own machine. With an
httpOnly cookie the same hole is still bad — script can act as the user while
the page is open — but the credential never leaves the browser.

Underneath it is the same Sanctum personal access token as before, so
per-device sessions, expiry and revocation are unchanged. Only the transport
moved, which is why the change touched three files on the server and two in the
front end.

The cost is that the browser now sends the credential automatically, which is
what CSRF exploits. `SameSite=Lax`, a required custom header on
cookie-authenticated writes, and a credentialed CORS allow-list answer it. The
full reasoning, and what to set before going live, is in
[SECURITY.md](SECURITY.md).

#### Two ordering traps, both of which cost real time

**Middleware order is not registration order.** Laravel sorts the assembled
route stack by its middleware priority list, in which `Authenticate` ranks above
`SubstituteBindings`. A middleware added to the `api` group therefore lands
*after* `auth:sanctum` whichever end of the group it is registered at — so the
guard ran first, found no bearer token, and returned 401 before the cookie had
been promoted into a header. It presented as the cookie silently doing nothing,
with the middleware apparently never running. `AuthenticateFromSessionCookie` is
registered **globally**, where the router's sorting cannot reach it.

**Sanctum stamps `last_used_at` when it resolves a token.** By the time any
middleware runs, that value has already been advanced to now, so an idle check
written as middleware finds every session fresh, always. The check belongs in
Sanctum's `authenticateAccessTokensUsing` hook, which runs before the stamp and
gets the token without a second query.

### Expenses are their own table, not negative payments

The ledger trick that works for refunds does not work for costs. A payment
belongs to a member and settles an invoice; an expense belongs to a supplier and
settles nothing. Folding them together would mean every revenue query had to
remember to exclude expenses, and the first one that forgot would quietly
under-report takings.

Amounts are stored positive. The sign lives in the table the row is in, not in
the number, so a mistyped minus cannot turn a cost into income.

Expenses are also editable, where payments are not. A payment is a record of
something the system did; an expense is a record of something that happened
outside it, so a mistyped supplier invoice is a correction rather than a
reversal. The audit log keeps both versions, so the correction is still
accountable.

Categories are a table rather than an enum: they are the gym's own chart of
accounts, and an accountant reconciling against their books needs them to match
the ones they already use. Deleting one with spending filed under it is refused —
that would leave last year's accounts with a total nobody can explain.

#### An accessor that shadowed its own aggregate

`Expense::getTotalAttribute()` returns `amount + tax_amount`. Any query that
aliased a `SUM(...)` as `total` was therefore shadowed by the accessor, which
recomputed from two attributes the aggregate query had never selected and
returned `0.00`. No error — just a wrong number, in the expense report, the
profit report and the list footer.

Sums are aliased `total_amount` throughout, and
`ExpenseTest::the_expense_report_totals_are_not_zero` fails if that regresses.
Worth knowing generally: an accessor and a select alias share a namespace, and
the accessor wins.

### Every report exports through one registry

`ReportExporter` holds each report's permission, column headings and row
generator in one place, behind a single `GET /api/reports/export/{report}`.

The alternative — an endpoint per report — was six routes differing only in
their headings, and in practice one of them would quietly stop matching the
screen it came from. Registering a report is what makes it exportable, so the
two cannot drift apart.

Rows come from generators, not arrays: a year of attendance at the SRS 30 scale
is hundreds of thousands of rows, and the payment and expense exports use
`cursor()` for the same reason. Each file opens with a UTF-8 byte-order mark,
because the audience is Excel on Windows, which otherwise reads UTF-8 as the
local codepage and mangles any name with an accent in it.

Permission is checked against the report definition rather than by route
middleware, because it varies per report — reception can export attendance but
not revenue, expenses or profit. `GET /api/reports/exports` returns only the
reports the caller may download, which is what the interface uses to decide
which buttons to draw.

### Confirmation is rationed; feedback is not

Every write reports its outcome through a toast (`web/src/components/Toast.tsx`)
— bottom-right, above every dialog, successes fading after five seconds and
failures staying until dismissed, because an error that vanished before it was
read is an error nobody acted on. This replaced per-page notice banners, which
pushed page content down as they appeared, moving the row somebody had just
clicked out from under their cursor.

Only destructive and hard-to-reverse actions ask first
(`web/src/components/Confirm.tsx`). Confirming ordinary saves is tempting and
wrong: it trains people to dismiss dialogs without reading them, which is
precisely what stops the important ones from working. So a plan is created with
a toast, and a refund, a deletion or a reset is confirmed.

The confirmation names what will happen and what it costs rather than asking
"are you sure?", and the few truly irreversible actions require the phrase to be
typed — reading is the point, not clicking. It is promise-based, so the calling
code reads as a straight line:

```tsx
if (!(await confirm({ title: 'Record this refund?', tone: 'danger', ... }))) return
```

Layering matters here and was got wrong first: a confirmation opened from inside
a modal sat at the same `z-50` and rendered *behind* it. Dialogs are `z-50`,
elevated confirmations `z-70`, toasts `z-80`.

### Resetting data takes a backup first, and cannot be done by the gym

`DataResetService` offers four cumulative scopes — attendance, then
transactions, then members, then everything — and staff accounts, roles,
branches, settings and branding survive all four, so the system is still usable
the moment the reset finishes.

Three gates, deliberately of different kinds: the `data.reset` permission, which
**only Super Admin holds** — a Gym Admin runs the business but cannot erase its
history; the caller's own password, re-entered; and the word `RESET`, typed.

A full backup runs first and a failure aborts everything:

```php
if ($backup->status !== 'SUCCESS') {
    throw new \RuntimeException("The pre-reset backup failed, so nothing was deleted: {$backup->message}");
}
```

That guard is not decorative — it fired during verification and stopped a real
reset. The deletes then run inside one transaction using `DELETE` rather than
`TRUNCATE`, because `TRUNCATE` causes an implicit commit in MySQL and would
leave a half-finished reset with no way back.

`BackupService` shells out to `mysqldump` and passes `SystemRoot` to it
explicitly on Windows. Winsock loads its provider DLLs through that variable, and
`php artisan serve` filters the environment it hands its child down to an
allowlist that does not include it — so the identical backup succeeded from the
console and failed from the browser with `Can't create TCP/IP socket (10106)`.
Some FastCGI and scheduled-task configurations scrub the environment the same
way.

---

## Database

28 application tables — the schema in SRS §28, plus `invoice_items`,
`settings`, `backup_logs`, `biometric_event_logs`, `brand_assets`, `expenses`,
`expense_categories`, and the website's `site_posts`, `testimonials`,
`timetable_entries` and `leads`. Laravel's own (`cache`, `jobs`, `sessions`,
`migrations` and the permission tables) are not counted here.

```
branches ──┬── users ──── trainers
           ├── members ──┬── memberships ──┬── invoices ── invoice_items
           │             │                 └── payments
           │             ├── attendance
           │             ├── biometric_users
           │             └── workout_plans ── workout_exercises
           ├── membership_plans
           └── biometric_devices ──┬── biometric_users
                                   ├── biometric_event_logs
                                   └── attendance_sync_logs

settings · notifications · audit_logs · backup_logs
```

Indexes are placed against the queries that actually run at scale: member
search from the desk, today's attendance, the expiry sweep, the "expiring soon"
card, and the open-visit lookup that every check-out performs.

Deletion is soft nearly everywhere. Attendance, payments and audit history all
reference members, and a gym that hard-deletes a member loses the financial
record that goes with them.

---

## Where the vendor lives

```
DeviceDriver (interface)
  └── HikvisionDriver          ISAPI over HTTP digest
  └── FakeDeviceDriver         tests only
```

`config/gms.php` maps a driver name to a class. Everything above the interface
speaks in `DeviceEvent`, `DeviceUser` and `DeviceInfo`, so supporting ZKTeco or
Suprema (SRS §35) is one new class and one line of config.

`fetchEvents()` returns a generator, so a reader holding months of buffered
offline events can be drained without loading them all into memory.

---

## Configuration

`config/gms.php` holds deployment defaults. Anything reception or an
administrator is expected to change at runtime lives in the `settings` table
and resolves **branch override → global setting → config default**, so a chain
can set a house rule once, a single branch can differ, and a fresh install works
before anyone opens the settings screen.

SRS §10 requires that whether an invalid membership blocks entry be
administrator-configurable — which is why it cannot live in `.env`.
