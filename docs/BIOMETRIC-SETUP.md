# Fingerprint device setup

Covers SRS §7, §8, §13, §14, §23, §24 and §35.

---

## Before installation — confirm these

The system is built against **Hikvision ISAPI**, which is what Hikvision
access-control terminals expose over HTTP — no vendor SDK and no native
library, so it deploys on ordinary PHP hosting.

**Known to work:** the MinMoe access-control range, including the
**DS-K1T321MFWX**. Any terminal in that family that serves
`/ISAPI/AccessControl/AcsEvent` needs no code changes at all.

Confirm the following with whoever supplies the readers:

| Question | Why it matters |
|---|---|
| Exact model and firmware version | ISAPI paths are stable across the access-control range, but event field names shifted in older firmware. |
| Does the terminal expose `/ISAPI/AccessControl/AcsEvent`? | This is the endpoint attendance is pulled from. Some camera-only models do not have it. |
| Is it on the same LAN as the server, with a static IP? | The server must be able to reach the device; the device must be able to reach the server if push is used. |
| How many devices, and at which doors? | Decides whether to run automatic mode or separate check-in / check-out readers (SRS §11). |
| Are check-in and check-out separate doors? | See "Operating modes" below. |
| Does the site have a UPS on the reader? | Determines how much offline buffering matters in practice. |

**If the readers turn out not to be Hikvision**, only one class needs writing:
an implementation of `App\Services\Biometric\Contracts\DeviceDriver`,
registered in `config/gms.php` under `biometric.drivers`. Nothing above that
interface knows or cares which vendor is in use. `tests/Support/FakeDeviceDriver.php`
is a worked example of the contract.

---

## Adding a device

1. Give the reader a **static IP** on the gym LAN and confirm you can open its
   web interface from the server.
2. In the application: **Fingerprint devices → Add device**.

   | Field | Notes |
   |---|---|
   | Name | What reception calls it — "Main entrance" |
   | Code | Short, unique; forms part of the push URL |
   | IP address / port | The reader's address; port is normally 80 |
   | Username / password | A device account with access-control rights |
   | Operating mode | See below |
   | Synchronisation | `Pull` unless you have a reason otherwise |

3. Press **Test**. It reports the model, serial number and firmware read back
   from the hardware. If it cannot connect, the message says what to check.

The device password is encrypted at rest and is never returned by the API.

### Port 80, not 8000

The mistake that wastes an afternoon. Hikvision terminals listen on **8000 for
the SDK / private protocol** — that is what iVMS-4200 speaks. **ISAPI is plain
HTTP on port 80**, or 443 with HTTPS. Enter 8000 and the Test button fails with
a confusing timeout on a device that is perfectly reachable.

---

## If you are using iVMS-4200

**You do not need it, and it should not be your attendance route.** This system
talks to the terminal directly over ISAPI. Putting iVMS-4200 in the middle
gives you two systems that both believe they own attendance.

They can coexist for reading — Pull is read-only polling and will not disturb
iVMS — but there are four real conflicts:

| Risk | What happens |
|---|---|
| **Event host slots** | The terminal holds a small, fixed number of HTTP event-receiving hosts. iVMS registers as one when armed. Configure Push here as well and you can run out, or one silently overwrites the other. |
| **It changes the password** | iVMS Remote Configuration can change the device account. Test then fails with *Authentication failed*, and nobody connects the two events. |
| **Clock drift** | A different timezone or NTP server shifts event timestamps. Sync resumes from a high-water mark: a clock that jumps back causes re-reads, one that jumps forward skips events. |
| **Two attendance databases** | iVMS-4200 has its own attendance module writing to its own local database on that PC. Use it and the real attendance lives in a Windows application rather than here — reports, memberships and the door rule stop agreeing. |

**Recommended:** use iVMS-4200 once for initial setup — firmware, network, time,
bulk enrolment on a big screen — then close it and leave it unarmed. Keep this
system on **Pull**. If you want iVMS running alongside for door control or
video, leave Push off here and do not use its attendance module.

---

## Operating modes (SRS §11)

**Automatic** — one reader at the door. The first valid scan of a visit is the
check-in; the next one closes it. Simplest, and right for most single-entrance
gyms.

**Check-in only / Check-out only** — two readers, one at each side of the
turnstile. The door the member walks through decides the direction, so the
system never has to guess.

A visit left open is closed automatically once it exceeds
`ATTENDANCE_MAX_SESSION_HOURS` (default 6), both by the nightly job and on the
member's next scan. Without that, one missed check-out would swallow every
second visit from then on.

---

## Registering a fingerprint (SRS §8)

1. Create the member in the system.
2. Open their profile → **Fingerprint** tab → **Register fingerprint**.
3. Choose the reader. The system allocates a biometric user ID and creates the
   user on the device.
4. **The member enrols their fingerprint at the reader itself**, using the
   device's own enrolment menu.
5. Back in the profile, press **Verify on device**. This asks the reader how
   many fingerprints it actually holds for that user, rather than trusting
   what the screen said at step 3.

The fingerprint template never leaves the reader. This system stores only the
mapping between member, device and biometric user ID — which is what SRS §26
requires for biometric data.

If the device is unreachable at step 3, the mapping is still saved, marked
`FAILED`, and can be retried once the reader is back.

---

## How attendance arrives

### Pull (the default, and the one that must always work)

Every minute the scheduler runs `biometric:sync`, which asks each reader for
events since its high-water mark, minus a ten-minute overlap.

The overlap is deliberate. Readers sometimes report an event a few seconds
after it happened, and a sync that resumed exactly where it left off would
step straight over those. Re-reading costs nothing, because every event carries
a hash of (device, user, time, direction) with a unique index on it — ingesting
the same scan twice is a no-op at the database level.

That one property is what makes the rest safe:

- The manual **Sync attendance now** button can be pressed as often as you like.
- A reader that buffered three days of events through a network outage needs no
  special recovery path. It is just a larger ordinary pull (SRS §14).
- An event that arrives by push and again by pull is stored once.

### Push (optional, faster)

Configure the reader's **HTTP Host / Event notification** to post to:

```
http://<server>/api/biometric/push/<device-code>
```

with header:

```
X-GMS-Token: <the value of BIOMETRIC_PUSH_TOKEN in .env>
```

This is the only endpoint a device talks to. It authenticates with a shared
secret compared in constant time — a reader cannot hold a bearer token.

**Leave pull enabled alongside it.** Push notifications stop silently if the
device loses the server's address, and a notification that never arrives looks
exactly like a quiet gym. A poll that finds nothing is visibly a poll that
found nothing.

---

## Checking it is working

**Fingerprint devices** shows, per reader: connection status, last successful
sync, last event seen, and consecutive failures. A reader that has not
synchronised in five cycles is flagged as overdue.

The **synchronisation log** on the same page records every run: the window
requested, how many events came back, and how they were classified —

| Outcome | Meaning |
|---|---|
| new | Recorded as attendance |
| duplicate | Already stored (normal, and usually the majority) |
| unmatched | A device user with no member mapped to it |
| rejected | Matched a member, but membership validation refused entry |
| failed | Could not be processed; details in the log |

**Unmatched device users** are listed so they can be attached to a member.
They usually mean somebody was enrolled directly on the reader without being
registered in the system first.

### If nothing is arriving

1. Is the scheduler running? `php artisan schedule:work`, or the cron entry.
2. Is `BIOMETRIC_SYNC_ENABLED=true`?
3. Press **Test** on the device — reachable?
4. Read the synchronisation log. `FAILED` rows carry the reason.
5. Run it by hand and watch: `php artisan biometric:sync --device=ENT-01`
6. Are the events arriving but `unmatched`? Nobody has mapped those device
   users to members. Use **Read users** and then match them.

---

## Membership validation at the door (SRS §10)

Every scan is validated before it is accepted:

1. Is the member blacklisted or suspended? — refused outright, whatever their
   membership says.
2. Do they have a membership, is it active, paid, started, and not expired?

Whether a failed check actually blocks entry is a setting, per branch:
**Settings → Attendance → Refuse entry on an invalid membership**.

With it off, the visit is still recorded and flagged, so reception can see who
walked in on a lapsed membership rather than the fact disappearing.

Validation gates entry, never exit. Someone already inside can always scan out,
even if their membership lapsed at midnight while they were on the treadmill.

---

## Synchronising users to the device (SRS §24)

**Read users** downloads the reader's user list and reconciles it: matched
members have their fingerprint counts updated, unknown users are reported as
unmatched.

Uploading pushes members onto the reader as users. It only creates the
identity — fingerprints are still enrolled at the device by the member.

The device user's *validity window* is still set far into the future rather
than to the member's expiry date — but the *enable flag* on that window is kept
in step, which is what actually stops a lapsed member at the door. See
**The reader stops admitting lapsed members** below.

---

## Linking a member to a user the reader already has

`GM-2026-00001` in here, `2` on the terminal. Two ways to join them:

- **Devices → Unmatched device users → Link to member.** Search by name or
  membership number, pick the member, done.
- **Member → Fingerprint → Register fingerprint**, and fill in **ID on the
  device**. Leave that blank and the member gets their own member ID, which is
  what keeps the two sides in step without anybody thinking about it.

Either way, **the scans that device user already made become this member's
attendance**, backdated to when they actually happened.

That matters more than it sounds. Every scan a reader sends for a device user
nobody has linked is stored in full and marked unmatched — which is to say,
recorded and unusable. Without recovering them a member's history silently
begins on the day somebody got round to the paperwork, even though the system
has been watching them come and go for weeks.

Replay is oldest first, because whether a scan is an entry or an exit depends
on whether the member is currently inside; out of order it would pair the
wrong entries with the wrong exits.

Two things worth knowing:

- **Membership is not re-checked for history.** The door opened days ago and
  the person was demonstrably there, so the visit is recorded and flagged
  rather than refused. A member who holds no membership today still gets their
  history back, with `membership_valid` false and the reason stored, so a
  report can still show who was in the building on a lapsed membership. Live
  scans are unaffected — somebody standing at the door is still refused if the
  branch is set to refuse them.
- **Membership state is today's, not that day's.** Reconstructing a membership
  history that has since been renewed or expired would be guesswork.

### `01` and `1` are the same person

The number a terminal prints on its own screen is not always the string it
sends in an event. Type `01` because that is what the screen showed, and a
plain string comparison will never match a scan that arrives as `1` — the
enrolment screen says registered and verified while every scan quietly files
itself as unmatched.

Scans now resolve leniently: an exact match always wins, and failing that an
id made entirely of digits matches the mapping with the same numeric value.
A reader genuinely holding both `1` and `01` as separate users resolves to
neither, so the scan stays visibly unmatched rather than being credited to a
guess.

---

## Making the device ID and the system ID match

The number the reader shows and the member's ID here drift apart, and it is
almost always the same story: somebody enrolled a member at the terminal before
adding them to the system, or the reader arrived with users already on it. From
then on the desk reads 47 off a screen while the record says 112.

New enrolments avoid this — a member is given their own member ID as the device
ID wherever it is free. For the ones that have already drifted, open the
member, **Fingerprint → Change ID**.

**The choice on that screen decides whether the fingerprint survives**, so it is
put in front of you rather than decided for you:

| | What happens | Fingerprint |
|---|---|---|
| **Leave "Change it on the reader too" off** | Only our record moves. Use this when the terminal is already right and we are the ones out of step. | **Kept** |
| **Tick it, reader already has that ID** | We adopt the identity already on the device and set its name to the member. | **Kept** |
| **Tick it, reader does not have that ID** | The user is created under the new number and the old one removed. | **Lost** — the member must enrol again |

That last row is not a limitation we chose. Hikvision stores fingerprint
templates against the employee number and offers no way to move one, so a
change that recreates the user costs the member another trip to the counter.
The system asks the reader which case applies rather than guessing, and the
warning on screen says so before you confirm.

An ID already held by a different member is refused. An ID held by an
*unmatched* device user is not a conflict — that is the identity you are
adopting, and the two records are merged.

> **With a push-only device** — one the server cannot open a connection to —
> only the first row is available. Read the number off the terminal and correct
> the record to match; leave the tick box off.

---

## Opening the door from the system

**Devices → Open door** releases the lock immediately, for the cases a reader
cannot help with: a finger that will not read, a visitor, a delivery.

It asks for a reason first, and the reason goes into the audit log with your
name. The entry is written **before** the attempt, not after it succeeds — a
refused attempt is exactly the one somebody will want to find later.

Reception holds this permission (`devices.open_door`) by default, separately
from `devices.manage`: letting someone in is an operational act that should not
require handing over the device's address and password. The endpoint is
throttled to ten calls a minute.

---

## The reader stops admitting lapsed members

When a membership expires, is frozen or is cancelled, the member is **disabled
on the reader** — their finger stops opening the door. Renewing re-enables them.

**Disabled, never deleted.** Deleting the user would take the fingerprint with
it, so every renewal would become a fresh enrolment and a queue at the desk.
Clearing the reader's `Valid.enable` flag leaves the template exactly where it
is and simply stops it working.

Two things drive it:

- **Immediately** after a membership is created, renewed, activated, suspended
  or cancelled — so a member who has just paid can walk straight in.
- **Nightly at 00:30**, just after the expiry sweep at 00:15, for everything
  nobody touched. Run by hand with `php artisan biometric:sync-access`.

Nothing is sent to the reader unless the decision has actually changed, so the
nightly run over every member does not rewrite every record on the terminal.
An unreachable reader does not stop the sweep or fail a payment — it is logged
and retried on the next run.

Scan-time validation stays regardless, and remains the real control. This only
saves the member a walk to a door that was never going to open, and keeps the
terminal's own user list honest for anyone reading it off the device.

---

## What needs the server to reach the reader

Most of this chapter assumes the server can open a connection **to** the
terminal. Three things depend on it and will not work otherwise:

| Feature | Needs outbound access to the reader |
|---|---|
| Pull sync, Read users, Test connection | yes |
| Opening the door | yes |
| Disabling a lapsed member on the reader | yes |
| Changing the ID on the reader | yes |
| Changing the ID in the system only | **no** |
| Push attendance (device posts to us) | **no** |

That matters for a hosted installation. A reader sitting on the gym's LAN
behind NAT can post events out to a public site perfectly well, but the site
cannot call back in. If you want door control and automatic lockout on a hosted
system, the reader needs to be reachable — which in practice means running the
system on the gym's own network, not that you should expose a fingerprint
terminal to the internet.
