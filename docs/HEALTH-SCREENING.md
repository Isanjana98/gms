# Health screening (PAR-Q)

A member answers the seven standard PAR-Q questions on their own phone, agrees
to the gym's terms, and signs. Anything they answer **yes** to lands in a queue
somebody has to clear.

---

## Why a "yes" has to go somewhere

A gym that asks *"do you have a heart condition?"*, records **yes**, and lets
the person train anyway is in a **worse** position than one that never asked.
It now has documented knowledge of the risk and no evidence of having acted on
it.

So the design rule is: a flag cannot be ignored. One yes and the screening sits
in the review queue until a trainer or an administrator records what was
decided and why. The notes field is required — a clinical decision with no
reasoning is the thing that looks worst a year later.

Entry is never blocked by this. Somebody can join, pay and train while the
review is outstanding; what they cannot do is have the flag quietly disappear.

---

## How it works at the counter

1. Reception opens the member → **Health** → **Issue a health form**.
2. A QR appears. The member scans it with their own phone.
3. They answer seven questions, read the terms, and sign with a finger.
4. The desk refreshes and shows the outcome. A PDF is filed against the member.

```
staff                         member's phone
─────                         ──────────────
Issue a health form
   │
   └── QR  ──────────────▶   /health/{token}
                                 7 questions
                                 terms  ·  signature
                                     │
   Health tab  ◀─────────────────────┘
     all clear      → nothing to do
     1 flagged      → review outstanding
```

The link can also be copied and sent, which is what you want when somebody
fills it in before they come down.

---

## The link

The token in the URL is the entire authorisation — the member has no account
yet. So it is:

| | |
|---|---|
| **Long** | 32 random bytes, 64 hex characters |
| **Hashed at rest** | only `sha256(token)` is stored, so a leaked backup hands out no working links |
| **Single use** | spent the moment it is signed |
| **Expiring** | 48 hours by default (`health.link_hours`) |
| **Superseding** | issuing again cancels the one outstanding, so there is never a question of which screening is current |
| **Throttled** | 20 requests a minute on the route |

A bad, spent or expired token gets a flat **404** — never "this link has
expired for Nimal Perera", which would turn a guessed token into a membership
oracle. The form shows the member's name so they know it is theirs, and nothing
else: not their phone number, not their plan, not what they owe.

---

## What makes it evidence rather than a form

Everything needed to stand the record up later is pinned to it at signing:

- **The answers**, keyed by question id — not just "passed". Reordering the
  questionnaire cannot shift old answers onto different questions.
- **The exact terms**, by version *and* by hash. Editing the gym's waiver text
  afterwards does not rewrite what everybody who already signed is recorded as
  having agreed to.
- **The signature** as drawn, on the private disk, served only through an
  authenticated endpoint.
- **When, from where, on what** — timestamp, IP, user agent.
- **A SHA-256 over all of it.** This does not make the row tamper-proof;
  anything in a database you control can be changed. What it catches is the
  realistic case — one field edited later, by hand or by a careless migration,
  without the hash being recomputed. The admin panel and the PDF both say so
  when it no longer matches.

The PDF is filed as a `MEDICAL` member document, so it appears wherever
agreements already appear.

---

## Who can do what

| Permission | Means |
|---|---|
| `health.view` | See screenings and their answers |
| `health.issue` | Hand somebody a QR |
| `health.review` | Decide what a "yes" means |

Deliberately **not** folded into `documents.*`: whoever may file an ID copy
should not automatically learn that a member has a heart condition.

As seeded:

| Role | view | issue | review |
|---|:--:|:--:|:--:|
| Gym Administrator | ✓ | ✓ | ✓ |
| Receptionist | ✓ | ✓ | |
| Trainer | ✓ | | ✓ |

Reception hands over the QR and can see whether it came back signed. A trainer
is usually the person who actually has the conversation a flag calls for, and
who writes the programme around it, so the review is theirs.

The queue shows **how many** questions were flagged, never which — "three
flags" is what a queue needs, and the detail is a click away rather than
scrolling past on a screen at the front desk.

---

## The questions

The seven standard PAR-Q questions, in the usual wording, in
[`app/Support/ParQ.php`](../api/app/Support/ParQ.php). They are **not** editable
from Settings, on purpose: a gym that reworded question 2 and then relied on the
answer would be relying on something no doctor or insurer recognises.

The gym's own waiver text, which genuinely is theirs, **is** editable —
`health.terms_body` and `health.terms_version` in settings. Bump the version
when you change the wording.

`ParQ::VERSION` changes when the questions do, and is copied onto every
screening, so an answer set can always be read back against the questions it was
actually given.

---

## Settings

| Key | Default | |
|---|---|---|
| `health.link_hours` | 48 | How long a QR stays usable |
| `health.terms_body` | built-in | The gym's waiver wording |
| `health.terms_version` | 1.0 | Bump when the wording changes |

---

## Not built

- **Blocking entry on an unreviewed flag.** The door does not consult the
  screening. That was a deliberate choice — see the top of this page — and
  reversing it is a setting plus a check in `AttendanceService`.
- **Re-screening reminders.** Nothing expires a screening or asks for a fresh
  one annually. The `replaces_id` column is there for when it does.
- **Collecting it before they are a member.** A screening is issued against an
  existing member record, so somebody is registered first. A public "join
  online" form that screens before registration would be a second route into
  the same evidence trail.
