# The member mobile app

A phone app for **members** — not for staff. Reception uses the admin panel,
which is already responsive and works from a phone browser; building a second
app for them would be duplicating a screen that already exists.

```
mobile/     Expo · React Native · TypeScript
```

---

## Why Expo and React Native

Chosen for reasons specific to this system rather than general ones:

| | |
|---|---|
| **One language** | The admin panel is React 19 + TypeScript. Same components model, same TanStack Query, same habits. No second ecosystem to staff. |
| **Auth was already there** | `POST /api/auth/login` has taken `issue_token: true` since the session work, and bearer tokens deliberately bypass the CSRF header check. The transport needed no changes at all. |
| **Windows** | EAS Build compiles iOS in the cloud. You need the Apple developer account, not the hardware. |
| **A web target** | Expo renders the same code to the browser, which is how this app is reviewed and tested here without an emulator. |

Flutter was the serious alternative — better rendering consistency, fine
tooling — and loses on one point: Dart is a second language for a team whose
entire front end is TypeScript. For an app of forms, lists and a PDF, its
performance edge buys nothing anyone would notice.

---

## Signing in: members have no password

This was the real work. Before it, `members` was a table of records with no way
to authenticate — only `User` (staff) could log in.

A member types **anything already on their record** — membership number,
telephone or email — and receives a six-digit code.

```
POST /api/member/auth/request-code   { identifier }
POST /api/member/auth/verify         { identifier, code }  → bearer token
```

No password, for three reasons in order of weight: there is nothing to forget,
so nobody queues at reception to reset it; it needs no enrolment step, so every
member already on file can sign in the day the app ships; and a stolen database
yields nothing but hashes of codes that expired minutes later.

**Six digits is only safe because of three limits, and all three are enforced
in `MemberAuthService` rather than left to callers:**

1. It expires (10 minutes by default).
2. It survives a handful of wrong guesses, then destroys itself.
3. It is single-use, and requesting a new one kills the old one.

Remove any of them and a six-digit code is guessable. Codes go out through the
ordinary notification outbox, so they follow whatever SMS provider the gym has
configured and appear in the same delivery log as everything else.

### Before an SMS provider exists

`MEMBER_CODE_EXPOSE=true` returns the code in the API response so the app can be
run and demonstrated today. It is ignored outright when `APP_ENV=production`, so
setting it by accident on a live server does nothing.

### What it deliberately does not reveal

An unknown identifier gets **the same answer as a real one**. Otherwise this
endpoint answers "is this person a member here?" for anyone who asks — a
question about somebody's gym attendance that strangers should not be able to
put to the system. The destination is masked in the reply (`******4567`).

---

## The wall between members and staff

Sanctum's token table is polymorphic, so the moment `Member` became
authenticatable, **a member's token became a perfectly valid token as far as
`auth:sanctum` is concerned**.

Most staff routes would have refused a member anyway for want of a permission.
But `auth/me` and the session endpoints have no permission gate at all, and
"most" is not a security boundary.

So two middleware check the **type of the thing holding the token**, not what it
can do:

| | |
|---|---|
| `staff.only` | Wraps the entire staff API. A member token gets 403. |
| `member.only` | Wraps `/api/member/*`. A staff token gets 403, and a member blacklisted since sign-in is cut off and their tokens deleted. |

A member is not a small staff account, and must not be able to become one by
accumulating permissions. `MemberAppTest::a_member_token_cannot_reach_the_staff_api`
is the most important test in the suite.

Every member endpoint is scoped to `$request->user()` and **takes no member id
from the request**. That is the whole security model of `MemberPortalController`
and worth preserving: the moment one endpoint accepts "which member?", every
endpoint has to be re-audited for whether it checks the answer.

---

## What the app does

Four tabs, deliberately no more — a member opens this to answer one of four
questions:

| Tab | Answers |
|---|---|
| **Membership** | When does it run out? Plus visits, balance, gym address and hours. |
| **Visits** | Have I been coming? Month, all time, average session, full history. |
| **Payments** | What have I paid? Every receipt, tappable to open the PDF. |
| **Classes** | What is on? The published timetable, with today highlighted. |

Anything else belongs at reception.

### Receipts

Downloaded **with the Authorization header**, then handed to the share sheet
(or a blob URL on web). Never `?access_token=…` in the URL: that ends up in the
web server's access log, the browser's history and the `Referer` header of
anything the document links to — and a member token is valid for six months.

### Sessions

Member tokens last 180 days on purpose. A member opens this perhaps
fortnightly; signing them out each time means an SMS round trip per visit, and
somebody who gives up is somebody who turns up to a lapsed membership. The
exposure is small — a member token reads only that member's own record, and
reception can end it by blacklisting.

The token is kept in the iOS keychain / Android keystore via `expo-secure-store`.

---

## Running it

```bash
# 1. The API (any port; the app is told where to look)
cd api && php artisan serve --port=8010

# 2. The app
cd mobile
npm install
npx expo start            # then press a (Android), i (iOS), w (web)
```

Point the app at your API in `app.json`:

```json
"extra": { "apiUrl": "http://localhost:8010/api" }
```

One setting, corrected per platform in `src/lib/api.ts`: Android emulators
cannot reach the host on `localhost` (that address is the emulated device), so
`10.0.2.2` is substituted automatically. A **real phone** needs your machine's
LAN address or a public URL.

The web target needs its origin allowed by `config/cors.php`, which outside
production matches **any loopback port** — Expo does not keep a fixed port, and
a hard-coded list breaks the moment it moves. Production allows no loopback
origin at all. A native build needs none of this either way: CORS is a browser
rule, and a phone app is not a browser.

### Shipping

```bash
npx eas build --platform android     # or ios, built on EAS's macOS workers
```

---

## Two traps worth knowing about

**`expires_at` must not be a `TIMESTAMP` column.** MariaDB gives the first
TIMESTAMP column in a table an implicit `ON UPDATE current_timestamp()`. Counting
a wrong attempt updates the row — which silently reset the expiry to *now*, so
one mistyped digit expired the code the member was still looking at. The column
is `DATETIME`, and
`MemberAppTest::a_wrong_guess_does_not_expire_the_code` fails if that changes.
The same trap hit `attendance.started_at` earlier in this project.

**`Alert.alert` is not implemented by React Native Web.** The sign-out
confirmation silently did nothing on the web target — which is how this app is
reviewed. `ConfirmSheet` is a real component for that reason: same behaviour on
every platform, and a test can drive it.

---

## Not built

- **Push notifications.** The plumbing is not there: `notifications.channel` has
  no `PUSH` value and no device token is stored. It is the obvious next step —
  an expiry reminder that arrives on the phone is worth more than one in an
  inbox — and needs a device-registration endpoint plus an Expo push driver.
- **Renewing or paying in the app.** No payment gateway is integrated; the app
  shows the balance and tells the member to see reception.
- **Class booking.** The timetable is display-only, like the website's. Booking
  with capacity and a waitlist is a separate feature that does not exist yet.
- **QR check-in.** Entry is by fingerprint at the door.
