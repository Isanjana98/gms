# The public website

A marketing website for the gym, served by the same application and edited
entirely from the admin panel.

---

## The idea it is built on

**Anything the system already knows is read live. Nothing is retyped.**

| On the website | Comes from |
|---|---|
| Gym name, tagline, logo | Settings → Branding |
| Membership prices | Membership plans |
| Trainers | Trainers |
| Address, telephone, email | Branches |
| Class timetable | Website → Timetable |
| Everything else | Website → Content |

Change a price under Membership plans and the website is already correct. There
is no second copy to forget, and the website can never quote a figure reception
will not honour.

Only what the system genuinely cannot know is stored as editable content — the
headline, the About text, which photographs to show, the opening hours — and
that lives in the existing `settings` table under a `website` group rather than
a table of its own.

---

## The design

Near-black, high contrast, one red accent. That is not a style preference —
the gym's own photographs are almost entirely black, and on a light page every
one of them reads as a hole punched in the layout. The dark ground lets the
pictures sit in the page instead of fighting it, and the red picks up the
signage in the photographs themselves.

Type is heavy, uppercase and tightly tracked for headings, with the last word
of the front-page headline carried in the accent colour. Everything is set in
the system font stack: no web font is loaded, so there is no third-party
request, no flash of unstyled text, and nothing to go stale.

### Motion

| Where | What |
|---|---|
| Hero | Photographs crossfade every 6.5s with a slow drift behind the headline |
| Everywhere | Sections fade and rise as they scroll into view, staggered per row |
| Hero figures | Counters run up from zero the first time they appear |
| Header | Transparent over the photograph, solid once you scroll |
| Marquee | A scrolling band of gym-wall slogans, paused on hover |
| Gallery | Tiles lift and zoom; clicking opens a lightbox, closed with Escape |
| Buttons and cards | A colour sweep and a lift on hover |

**All of it is a progressive enhancement.** Three rules hold it together, and
they are the part worth keeping if the design is ever replaced:

1. **Reveals are scoped to a `.js` class** that an inline script sets in
   `<head>` before first paint. If `site.js` fails to load, nothing is hidden —
   the page simply renders. The alternative, hiding by default and revealing
   with script, turns one failed request into a blank website.
2. **`prefers-reduced-motion` removes decorative motion entirely**, not merely
   reduces it. The drifting, zooming and scrolling here is exactly what
   triggers vestibular disorders, and a marquee that cannot be stopped is the
   worst offender on the page. Counters jump to their final figure; reveals
   start visible.
3. **Nothing is required for the content to work.** With JavaScript off the
   hero shows its first photograph, the menu still opens (it is a checkbox, not
   a script), and every page is complete.

All three are covered by the browser verification, which runs the site a third
and fourth time with reduced motion and with JavaScript disabled.

---

## Where it lives

Server-rendered Blade on ordinary web routes, not part of the React admin app.

```
yourgym.lk/           → the website        (Blade, routes/web.php)
yourgym.lk/pricing    → live from membership_plans
yourgym.lk/blog/…     → news
yourgym.lk/api/…      → the API
        (the admin panel is a separate static build — see DEPLOYMENT.md)
```

Two reasons, both of which matter to a gym:

- **Search.** Somebody typing "gym in Colombo" gets real HTML with a heading,
  an address and opening hours. A client-rendered page gives a crawler an empty
  document and hopes it runs the JavaScript.
- **Weight.** A visitor reading the opening hours should not download a 420KB
  admin bundle to do it.

There is no build step. The stylesheet is one hand-written cacheable file at
`public/css/site.css` and the behaviour is one deferred file at
`public/js/site.js` — no framework, no bundler, no CDN. Adding a build pipeline
to the API application to produce ~20KB of CSS and ~6KB of JavaScript would
cost more to maintain than the files do, and a play-CDN script is not something
to put in front of paying customers.

---

## Pages

`/` `/about` `/pricing` `/trainers` `/gallery` `/timetable` `/blog`
`/blog/{slug}` `/contact`

Each section is shown only when it is **switched on** *and* **has something in
it**. A gym with no trainers on file and no photographs gets a short tidy page
rather than three empty headings.

---

## Publishing

**The website is off until somebody switches it on** under Website → Content.
Until then every page returns 404 with a short "not published" notice carrying
`noindex`, so a fresh installation never puts placeholder copy on the open
internet under a real gym's name — and a crawler that arrives mid-setup does
not remember the page.

Taking it offline again is the same switch.

---

## Editing it

**Website → Content** — publish switch, front-page headline and button, About
text, facilities, opening hours, social links, which pages to show, and the
search-engine title and description.

**Website → Images** — the front-page banner and the gallery. Uploading nothing
is fine: the banner falls back to the sign-in screen images, so a gym that has
already uploaded photographs does not do it twice.

**Website → News** — posts, with drafts and scheduling. An unpublished or
future-dated post is unreachable even by its address, not merely hidden from
the list.

**Website → Testimonials** — member quotes, with an optional star rating.
Publish a member's words only with their permission.

**Website → Timetable** — the published class timetable. **Display only.** It
says "Spin, Tuesday 6pm, with Priya" the way a poster on the wall does; it holds
no bookings, capacity or attendance, because class booking is a separate feature
that is not built.

---

## Enquiries

The contact form creates a **lead**, which appears under **Enquiries** in the
admin panel with the name, telephone, what they asked about, and when.

Reception marks it contacted, adds notes, and — the point of keeping leads in
the system at all — **converts it to a member in one click**, carrying the name
and telephone across and linking the two records so the gym can see how many
enquiries actually became members.

If a visitor arrives from a pricing card, the plan is pre-selected on the form
and carried through, so reception knows what the conversation is about before
they pick up the phone.

Protections, because this is the one public endpoint that writes to the
database:

- Rate limited to 10 submissions per 10 minutes per address.
- A honeypot field, absorbed silently — the bot is answered as though it worked,
  so it learns nothing and does not return with the field left blank.
- An enquiry with neither a telephone number nor an email address is refused;
  there would be no way to reply to it.
- Submissions are refused entirely while the website is unpublished.

---

## Who can edit what

| Permission | Covers |
|---|---|
| `website.manage` | All website content, images, posts, testimonials, timetable |
| `leads.view` / `leads.manage` | Enquiries, and converting them to members |
| `branding.manage` | Gym name, logo, sign-in imagery — **not** the website |

`website.manage` is deliberately separate. Writing the marketing copy is the job
a gym is most likely to hand to somebody outside the management team, and doing
it should not carry the ability to change an attendance rule, read a member's
telephone number, or replace the logo printed on every receipt. Reception holds
the lead permissions, because chasing an enquiry and signing somebody up is
already their job.

---

## Security notes

- **Content is escaped, never rendered as raw HTML.** The post body and the
  About text are edited in the admin panel and rendered with paragraph breaks
  preserved but every character escaped. Allowing HTML would make the content
  editor a way to put script on the gym's own origin — the same origin the staff
  session cookie belongs to.
- **The one exception is the headline**, which is emitted as HTML so its last
  word can carry the accent colour. It is escaped in `SiteContent::headline()`
  word by word after splitting on whitespace, and
  `WebsiteTest::the_headline_is_escaped_despite_being_rendered_as_html` fails if
  that ever stops being true. Nothing else may be handed to that view unescaped.
- **The map field accepts only a URL**, so it cannot be used to inject an
  arbitrary iframe.
- **JSON-LD is escaped with the HEX flags**, so a gym name containing
  `</script>` cannot close the tag and have the rest parsed as markup.
- **Trainer telephone numbers and email addresses are never published.** The
  team page shows the name and specialisation only; those contact details are
  held to run the gym, not to put on the internet.
- Uploaded images get generated filenames on the public disk; the original name
  is kept for display only.

---

## Two things worth knowing if you extend it

**Site-wide data is merged at render, not shared from the controller
constructor.** `View::share()` writes into the view factory, which is a
singleton, so values bound there are decided once rather than per render. That
showed up as a Gallery link that refused to appear until the process restarted,
because the navigation had been computed before the first image was uploaded.
`SiteController::page()` merges `SiteContent::shared()` into each render
instead.

**Views do not reach into the `Setting` model.** A `json` setting comes back
already decoded, so a template casting one to a string — which is what a
template naturally does — is an "Array to string conversion" that only appears
once somebody fills that field in. The copy is resolved in
`SiteContent::copy()` and handed to the view as plain strings.
