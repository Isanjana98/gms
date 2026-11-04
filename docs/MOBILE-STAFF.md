# The staff mobile app

A phone app for **staff** — the check-in desk, and the whole admin panel behind
it.

```
mobile-staff/    Expo · React Native · TypeScript
```

---

## What it does

Five tabs, and a menu holding everything else.

| Tab | For |
|---|---|
| **Check-in** | Search → see the verdict → check in or out. The default tab. |
| **Members** | Look somebody up, register one, correct their details. |
| **Today** | In the gym now, visits today, active members, expiring soon, takings. |
| **Money** | Today's takings and what is owed, with take-payment one tap away. |
| **More** | Every other module, grouped and permission-gated. |

Under **More**:

| Group | Modules |
|---|---|
| People | Memberships, Enquiries, Trainers |
| Money | Payments, Invoices, Expenses, Plans, Reports |
| Training | Workout plans |
| The gym | Attendance, Devices, Website |
| Administration | Staff users, Branches, Settings, Audit log, Backups, Your account |

That is the admin panel's modules, reachable from a phone.

### Why the tab bar stops at five

A tab bar stops being navigation somewhere around six items and starts being a
puzzle. What earns a tab is what gets used *during a shift* — the door, a
member lookup, today's numbers, taking money. Reports did not: it is read once
a week, usually sitting down, and it is one tap away under **More**.

---

## What it deliberately does not do

Not everything belongs on a 400px screen, and the admin panel is one browser
tab away on the same phone. Left out on purpose:

- **Writing a workout programme.** Exercises, sets and reps across seven days
  is a table. The app shows programmes so a trainer on the floor can check what
  somebody should be doing; writing one happens at a desk.
- **Adding a fingerprint reader.** Needs an IP address, a port and a password
  typed correctly. Testing one and pulling its scans *are* here, because those
  are done standing next to the device.
- **Restoring a backup.** Taking one is a button and a wait, so it is here.
  Putting a database back is done with the server in front of you.
- **Website photography and section order.** Choosing a hero image on a phone
  is choosing it blind. The words are editable; the pictures are not.
- **Editing an existing branch, or moving the default.** Both change what every
  other screen can see.
- **Offline anything.** The app needs the server to decide entry, so a dead
  connection means no check-in. Queueing scans locally would mean the phone
  deciding who gets in, which is the one thing it must not do.

---

## The verdict comes from the server

Before anything is recorded, the app asks
`GET /api/attendance/validate/{member}` — *would this person be let in right
now?* — which **writes nothing**. A lapsed member starts a renewal conversation
instead of leaving a refused entry in the log.

The sentence and the pass/fail both come from the API. The app never decides
this for itself: a phone that disagrees with the door is worse than no phone.

### A repeat scan is ignored, on purpose

Scanning the same person twice within a couple of minutes does not record a
second visit. The app says *"Duplicate check-in ignored: already recorded at
06:32."* and leaves the log alone.

That is the turnstile rule, not an app limitation: at the door people scan
twice, hand the phone back, or get scanned by two members of staff at once, and
none of that should show up as two visits. The window is
`attendance.duplicate_window_minutes` — two minutes by default, per branch,
changeable under **Settings → Attendance**. Set it to `0` to switch it off.

---

## Permissions

Every module declares its permission once, in
[`src/lib/modules.ts`](../mobile-staff/src/lib/modules.ts). The **More** menu
and the guard on each screen both read from that list, so they cannot disagree
— the failure mode otherwise is a tile that dead-ends in a 403.

Three things are true at once, and all three are needed:

1. **The menu hides what you cannot use.** A courtesy, not a control.
2. **The screen refuses if reached directly.** Deep links and browser URLs on
   the web target do not go through the menu.
3. **The API refuses regardless.** This is the actual control. The first two
   exist so the app is honest, not so it is safe.

What the seeded roles actually see, verified by driving the app:

| Role | Tabs | Modules |
|---|---|---|
| Super Administrator | all five | all 18 |
| Receptionist | all five | memberships, enquiries, trainers, payments, invoices, plans, reports, workouts, attendance, devices, account |
| Accountant | all five | memberships, payments, invoices, expenses, plans, reports, audit, account |
| Trainer | no Money tab | trainers, workouts, attendance, account |

Note that a receptionist sees **Devices** but gets no sync button: that role
holds `devices.view` and not `devices.sync`, so front desk can tell whether the
reader is online without being able to trigger anything. Seeing a module and
being able to change it are separate questions, and the app asks both.

---

## Signing in

Email and password — the same credentials as the admin panel. Unlike the member
app, there is no one-time code: staff sign in to a tool they use every shift and
already have credentials for, and an SMS round trip to open the check-in desk
would be an obstacle rather than a protection.

The app asks for `issue_token: true` on `POST /api/auth/login`, which returns a
bearer token instead of the httpOnly cookie the admin panel uses. A phone app is
not a browser: it has no cookie jar worth relying on, and bearer tokens are
deliberately exempt from the CSRF header check for exactly this case.

The token goes in the iOS keychain / Android keystore. That matters more here
than in the member app: **a staff token can read every member's telephone number
and the gym's takings**, so losing the phone must not mean losing the database.
Sign-out asks for confirmation and says so.

**Your account** under More lists every session the token belongs to, with a
"sign out everywhere else" that spares the device in your hand — the one thing
you need when a phone has gone missing and it is not the one you are holding.

A staff token is refused by the member API (`403`), the mirror of the rule that
keeps member tokens out of the staff API. See
[MOBILE.md](MOBILE.md#the-wall-between-members-and-staff).

---

## How it is built

Twenty modules hand-rolling their own list query, search debounce and
save-then-invalidate would be twenty chances to get the cache key subtly wrong.
They all talk to the same REST shape, so the plumbing is shared and each screen
is left with only what makes it different:

| File | Does |
|---|---|
| [`src/lib/resource.ts`](../mobile-staff/src/lib/resource.ts) | Paginated search, one record, save, delete, and turning a 422 into a sentence |
| [`src/components/ResourceList.tsx`](../mobile-staff/src/components/ResourceList.tsx) | Search box, rows, paging, and the four states a list can be in |
| [`src/components/form.tsx`](../mobile-staff/src/components/form.tsx) | Fields, money, dates, pickers, switches, outcomes |
| [`src/components/MemberPicker.tsx`](../mobile-staff/src/components/MemberPicker.tsx) | Choosing a member by the same search the door uses |
| [`src/lib/modules.ts`](../mobile-staff/src/lib/modules.ts) | The module registry, and who may open each |
| [`src/lib/documents.ts`](../mobile-staff/src/lib/documents.ts) | Receipts, invoices, backups and CSV exports |

Nothing here uses `Alert` or a native picker: React Native Web implements
neither, and the app is driven through Expo's web target during verification. A
control that silently does nothing in a browser is a control that cannot be
tested.

A document is fetched with the Authorization header and then handed to the
platform — never by putting the token in the URL. A link like `?access_token=…`
ends up in the access log, in browser history, and in the `Referer` of anything
it links to.

### Types were written from real responses

Several resources rename on the way out — an expense is `expense_date` and
`description`, not `spent_on` and `notes`; profit-and-loss returns `income`, not
`revenue`. A type that describes the database instead of the payload compiles
perfectly and renders a confident zero. Every shape in
[`src/lib/types.ts`](../mobile-staff/src/lib/types.ts) came from an actual
response.

---

## Running it

```powershell
.\dev.ps1          # starts the API, admin panel, member app and this
```

Or on its own:

```bash
cd mobile-staff
npm install
npx expo start            # then press a (Android), i (iOS), w (web)
```

Point it at your API in `app.json`:

```json
"extra": { "apiUrl": "http://localhost:8000/api" }
```

Android emulators get `10.0.2.2` substituted automatically; a real phone needs
your machine's LAN address.

> **Changing that URL needs `npx expo start --clear`.** Expo inlines
> `extra.apiUrl` into the bundle at build time, so editing `app.json` alone
> leaves Metro serving a cached bundle pointed at the old address. `dev.ps1`
> does this for you when the port actually changes.

The web target needs its origin allowed by `config/cors.php`, which outside
production matches **any loopback port**. That is deliberate: Expo moves to
another port whenever the one it asked for is taken, so a fixed list breaks with
a CORS error that looks nothing like "your bundler moved". Production allows no
loopback origin at all.

### Shipping

This is an internal tool, so it does not need a public app store listing:

```bash
npx eas build --platform android --profile preview   # an APK to sideload
```

For iOS, TestFlight is the practical route for a handful of staff devices.

---

## Shared code with the member app

`src/lib/theme.ts`, `format.ts`, `api.ts` and the `components/` primitives are
**copies** of the member app's, not a shared package.

That is a deliberate trade. The two apps have different jobs — one is a card a
member glances at, the other a tool used at speed behind a desk — and they have
already drifted on density and spacing. A shared package would force every
change to be safe for both, which is the wrong constraint for files this small.
If they stop drifting, extract them.

The cost is real: a bug fixed in one is not fixed in the other. Each copied file
says where it came from.

---

## The bigger duplication, stated plainly

This app reimplements the admin panel's modules in React Native. Every future
change to a module now has to be made twice — once in `web/`, once here — and
the two will drift.

That was a deliberate choice, made with the cost known. The alternatives were a
smaller app covering only what a phone is good at, or an embedded web view of
the admin panel; full native parity was chosen over both.

If the drift becomes the problem it might, the honest fix is to move shared
logic into the API. A screen that renders what the server describes does not
drift — [`app/settings/index.tsx`](../mobile-staff/app/settings/index.tsx)
already works that way, building its whole form from the labels, types and
descriptions the settings endpoint returns.
