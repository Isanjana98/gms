# Security

How a session works, what protects it, and what to set before going live.

---

## The session

**The session token lives in an httpOnly cookie. Nothing else.**

It used to live in `localStorage`, which meant any script running on the page
could read it. That is the difference between a cross-site scripting hole being
bad and being catastrophic: with a readable token, one XSS anywhere in the front
end hands an attacker a working session they can keep using from their own
machine, long after the page is closed. With an httpOnly cookie, script cannot
see the credential at all — the worst it can do is make requests while the
victim has the page open.

Underneath it is still an ordinary Sanctum personal access token, so per-device
sessions, expiry and revocation all work as before. Only the transport changed.

| | |
|---|---|
| Name | `gms_session`, or `__Host-gms_session` over HTTPS |
| `HttpOnly` | yes — script cannot read it |
| `SameSite` | `Lax` |
| `Secure` | over HTTPS |
| `Path` | `/`, with no `Domain` attribute |
| Absolute life | `SESSION_LIFETIME_DAYS`, default 30 |
| Idle life | `SESSION_IDLE_TIMEOUT_MINUTES`, default 720 (12 hours) |

### Why `__Host-`

The prefix is enforced by the browser, not by us: it refuses to accept such a
cookie unless it is `Secure`, has `Path=/` and carries no `Domain`. That means
no other host — in particular no sibling subdomain — can set it. Without it, a
compromised `blog.yourgym.lk` can write a cookie for `yourgym.lk` and fixate a
session on a staff member.

It requires HTTPS, so plain-HTTP development uses the bare name.

### Why `SameSite=Lax` and not `Strict`

`Strict` would sign people out whenever they arrive by following a link from
anywhere else — including the link in a membership expiry email. In practice
that gets solved by staff writing their password on a sticky note, which is a
worse outcome than the attack it prevents.

`Lax` already withholds the cookie from every cross-site POST and every
cross-site subresource, which is what CSRF needs. The one thing it still sends
on is a top-level GET navigation, where the attacker cannot read the response.

### The companion cookie

`gms_session_active=1` is readable by script and deliberately so. It holds no
secret — only the fact that a session exists — and lets the front end skip
asking the server "is anyone signed in?" on every anonymous page load. Forging
it achieves nothing: the next request is simply an unauthenticated one.

---

## Cross-site request forgery

Cookies are attached by the browser automatically, which is the whole basis of
CSRF. Three independent things answer it:

1. **`SameSite=Lax`** — the browser does not send the session cookie on a
   cross-site write at all.
2. **A required custom header.** Cookie-authenticated writes must carry
   `X-GMS-Request` or `X-Requested-With`. A cross-site HTML form cannot set a
   header; a cross-site `fetch` that sets one must first pass a CORS preflight,
   which we answer only for our own origins. A request without one is refused
   with **419**.
3. **CORS.** `supports_credentials` is on, so `config/cors.php` is now the list
   of origins permitted to act as a signed-in user. A wildcard is impossible
   there — browsers reject `*` with credentials — and the allowed headers are
   named individually rather than `*`, so a cross-origin caller cannot set the
   very header the check relies on.

   Outside production, that list also matches any loopback origin, because the
   phone bundlers do not keep a fixed port. It is a real loosening and it is
   confined to a developer's own machine: under `APP_ENV=production` the
   pattern list is empty, and a test asserts it. The pattern is anchored at
   both ends, so `localhost.attacker.com` and `localhost:8082.attacker.com` do
   not match it.

Bearer tokens skip all of this, correctly: nothing sends them automatically, so
forgery does not apply. Integrations are unaffected.

---

## Idle timeout

The absolute 30-day expiry suits a manager's phone and is badly wrong for the
reception terminal, which sits in a public room, signed in, and gets walked away
from. After `SESSION_IDLE_TIMEOUT_MINUTES` without use, the session is deleted —
not merely refused, or the next keystroke would revive it.

This is implemented in `AppServiceProvider::enforceSessionIdleTimeout()` via
Sanctum's own `authenticateAccessTokensUsing` hook, for a reason worth recording:
**Sanctum stamps `last_used_at` the moment it resolves a token**, so by the time
any middleware runs, the value has already been advanced to now and every session
looks fresh. The hook runs before that stamp.

---

## Response headers

Set on every response by `SecurityHeaders`, including ones produced by middleware
that refuses a request before it reaches a controller.

| Header | Why |
|---|---|
| `X-Content-Type-Options: nosniff` | Member documents are uploaded by staff and streamed back by the API. Without this, a file claiming to be a PDF but containing markup can be sniffed as HTML and executed on our origin — which, with the session in a cookie, is precisely the position to avoid. |
| `X-Frame-Options: DENY` | Clickjacking. Nothing here should ever be framed. |
| `Content-Security-Policy: default-src 'none'; …` | API responses are JSON and files, never pages, so the policy can be as tight as it goes. |
| `Referrer-Policy: strict-origin-when-cross-origin` | A receipt URL contains a payment id; it has no business appearing in a third party's logs. |
| `Permissions-Policy` | Nothing in a gym admin system needs a camera, microphone or location. |
| `Strict-Transport-Security` | HTTPS only. Sending it over plain HTTP is meaningless, and from a dev machine it would pin `localhost` to HTTPS in the developer's own browser. |

---

## Signing in

- Rate limited per **email + IP**: 5 attempts, then a 60-second lockout. Without
  it the login endpoint is an offline password cracker with a network
  connection.
- A wrong password and an unknown address return the **same message**.
  Distinguishing them tells an attacker which addresses are worth attacking.
- A deactivated account stops working immediately, and its tokens are deleted
  rather than merely rejected.
- Changing a password signs out every other session.

## Your sessions

**Your account** (the name at the bottom of the sidebar) shows every session on
the account — device, address, when it was signed in and last used — and lets
anyone end one, or sign out everywhere else. No permission is needed: someone
who suspects their login has been used should not have to raise a ticket and
wait. Sessions are scoped to the caller, so an id belonging to another account
is simply not found.

---

## Before going live

1. `APP_DEBUG=false` and `APP_ENV=production`. A stack trace names your file
   paths, your packages and sometimes your queries.
2. **Serve over HTTPS.** Most of the above is either weakened or inert without
   it: no `Secure` cookie, no `__Host-` prefix, no HSTS.
3. Set `FRONTEND_URL` if the front end is on another origin, and confirm
   `config/cors.php` lists nothing you do not control. If it is served from the
   same origin behind one reverse proxy — the usual arrangement — CORS does not
   come into it at all.
4. Change the seeded `admin@gms.local` password, and the demo staff accounts if
   the demo seeder was ever run.
5. Consider lowering `SESSION_IDLE_TIMEOUT_MINUTES` for a shared terminal. 720
   covers a full shift; a machine on the gym floor might want 60.
6. Check `TrustProxies` if you are behind a load balancer, or `$request->isSecure()`
   reports HTTP and the cookie loses its `Secure` flag.
7. Restrict who holds `data.reset` — see [the reset section in the README](../README.md).

## What is not built

- Two-factor authentication.
- Password complexity rules beyond a minimum length of 8.
- Account lockout that survives a restart (the limiter is cache-backed).
- Automated dependency scanning in CI.
