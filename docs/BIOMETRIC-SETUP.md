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

The device user's validity window is set far into the future rather than to the
member's expiry date. Expiry is enforced here, at scan time, where it can be
changed by taking a payment. Keeping the reader's own validity window in step
would mean touching hardware on every renewal, and a network failure mid-renewal
would lock out a member who had just paid.
