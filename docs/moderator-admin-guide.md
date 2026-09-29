# Moderator admin guide: events, homepage features, and incident answers

For moderators running the TWO site week to week — publishing events,
featuring content on the homepage, and answering members when something
breaks. No engineering needed. If a step below asks you to deploy, edit
code, or touch production, stop: that step is not yours (see §6).

Companion pages: [`docs/troubleshooting-join.md`](troubleshooting-join.md)
(member can't get into Discord), [`docs/runbook.md`](runbook.md) and
[`docs/ci.md`](ci.md) (deploys — not moderator jobs, linked so you know
where your job ends).

## 0. Getting into the admin panel

1. Open the **staging** admin: `<staging>/admin` (ask the COO for the
   staging URL). The panel lives at path `admin` and there is no separate
   login page — signing in happens through Discord.
2. Sign in with Discord. Moderator status is recomputed from your Discord
   role at **every** login, so a newly-granted moderator must sign out and
   back in once before the panel appears.
3. If you sign in and get a **403** (or never see an admin link), you are
   signed in fine but not a moderator — ask the COO to check your role.
   Members get 403, guests are sent to Discord sign-in.

> Staging only. The moderator panel does not operate on production until
> the member-data logging work lands — never publish, cancel, or feature
> anything on the live site.

## 1. Publish an event, end to end (staging walkthrough)

This is the acceptance path for this guide: a moderator who has never
done it follows these steps and ends with a visible event.

1. Go to `<staging>/admin/events` and choose **Create** (or open an
   existing draft and choose **Edit**).
2. Fill in the fields. Everything you create starts as **Draft** —
   invisible to members, visible to moderators — so you cannot break
   anything by saving.

   | Field | Rule of thumb |
   |---|---|
   | Title | Required, 100 characters max. The name members see. |
   | Game | Optional, 100 max. Leave blank for non-game nights. |
   | Description | Optional, 1000 max. Two or three sentences is plenty. |
   | Starts / Ends | Required, local time as shown on the poster. Ends must be after starts. Leave seconds alone. |
   | Timezone | Required, defaults to `Europe/London`. Change it when the event is elsewhere — the time is meaningless without the right zone. |
   | Location | Recommended, 255 max. `Voice: General` is the usual value. |
   | Capacity | Leave **empty** for unlimited. A number caps RSVPs. Minimum 1. |

3. **Save.** Check the row shows the gray **Draft** badge.
4. On the event's row, choose **Publish** and confirm
   ("Publishing announces the event to Discord"). From that moment
   members can RSVP — publish is the announcement, not the save.
5. Verify, signed out, on staging:
   - `/events` lists the event;
   - its share page `/e/<key>` opens with the details and an RSVP button.

Two gotchas: around the clocks-change weekend, a time that never existed
(the 1–2am "gap") is rejected — shift by an hour and mention it to an
engineer. And there is **no delete**: a published event has been
announced, so the record stays. Fix mistakes with Edit, or Cancel (§2).

## 2. Cancel an event

On the event's row (`/admin/events`), choose **Cancel** — available for
drafts and published events — and confirm. Know before you confirm:

- **Cancelling is permanent.** A cancelled event can never be reopened;
  make a new event instead.
- Discord is told, and RSVPs do not come back.
- The event's page stays up saying it was cancelled, so links shared
  before the cancellation land somewhere honest instead of a 404.

**Cancelled is not past.** An event becomes *past* by itself when the
clock passes its end time — you never set that by hand, and past events
quietly leave listings and the calendar feed.

## 3. Feature content on the homepage

The homepage has two parts: a **featured row** you control, and an
**events teaser** that fills itself (the next 3 published events —
nothing to do by hand).

1. Go to `<staging>/admin/featured-contents` and choose **Create**.
2. **Content section:** headline (required), one or two sentences under
   it (optional — leave empty for a headline-only row), a link for the
   headline (optional), an image link (optional — see §5 for sizing).
3. **Visibility section:**
   - **Published off = staged.** Visible to you in the panel, invisible
     on the site. This is how you draft.
   - **Position:** lower numbers appear first. `0` is the front.
   - **Show from / Show until (UTC):** optional shelf life — an event
     announcement should not outlive the event.
4. The **Preview** box at the bottom shows exactly what visitors will
   see (or that they see nothing yet) — trust it; it uses the same
   rules as the homepage.
5. Verify on the staging homepage: your item appears in position order,
   the headline links where you said, and unpublishing (or an expired
   window) removes it.

Unlike events, a featured row *can* be deleted from its row menu when it
is truly dead — but unpublishing is usually enough.

## 4. Missed searches: what guests looked for and missed

The admin dashboard shows **Top searches with no results** — what guests
typed into the `/events` search box and found nothing for, ordered by how
often it was missed. A repeat miss here is a game night nobody posted yet:
if "helldivers" keeps showing up with no results, post a Helldivers night.

What you see is only the search words and how often they missed — never
who searched. The log keeps no names, accounts, or addresses, so there is
nothing here that identifies a guest. Entries older than 90 days are pruned
automatically; the widget shows the window, not all time.

## 5. Cover images: what fits where

The short version: **events have no cover image.** There is no upload
button, no image field, no sizing rule — if you are looking for one,
you are in the wrong place.

The only moderator-controlled image on the site is the **homepage
feature image**, and even that is not an upload: you paste a direct
`https://` link to a photo that already lives somewhere public, with
the rule **real community photos only — never stock, never generated.**

How it displays: full-width at **16:9**, cropped automatically to fill
(`object-cover`), loading lazily below the hero. Size for that box:

- **Shape:** 16:9 — `1600×900` ideal, `1200×675` minimum. Other shapes
  still work (the crop handles them) but keep faces and text centered —
  edges may be cut.
- **Weight:** JPG or WebP, **under ~500 KB**. Nothing enforces this, so
  enforce it yourself: an oversized photo slows the homepage for
  everyone on phones.
- **Link:** a direct public `https://` file URL. Private, expiring, or
  login-walled links break the moment you look away.
- Check the form's Preview box, then the staging homepage, before you
  call it done.

## 6. Incident FAQ: what to tell members

You cannot change any of the messages below from the panel — they are
part of the site, changed only by a deploy (an engineer + reviewer job).
Your job is recognising them and pointing members the right way.
**`/discord` is always the fallback**: it needs no database, no bot, no
sign-in, so it works when everything else is down.

| Member reports | What it means | Tell them |
|---|---|---|
| "We will be right back" page | Site in maintenance, about a minute. | "The Discord server never closes — use the Discord invite and we'll see you there." |
| Join/sign-in shows a recovery page with a retry button | Discord hiccup or expired approval — nothing is broken. | Retry on the page, or use `/discord`. (Exact sentences: see `docs/troubleshooting-join.md`.) |
| "Slow down a little" | Rate limit. | Wait a minute and try again. |
| Event page says it was cancelled | The event is not happening; the page stays so old links land honestly. | It was cancelled — watch `/events` for the replacement. |
| Anything else / page looks wrong | Unknown. | Ask for the **page URL and the exact sentence** it shows, then escalate to the COO with both. Never guess, never promise a fix time. |

Escalation rules: collect URL + exact wording first (engineers need
both); moderators never deploy, restart, or "try something on the box" —
write it up and hand it to the COO/DevOps.

---

*Sources (for engineers checking this guide): panel path and 403 rule
`app/Providers/Filament/AdminPanelProvider.php:30-49`,
`app/Models/User.php:51-54`; event actions and no-delete
`app/Filament/Resources/Events/Tables/EventsTable.php:46-75`; event
fields `app/Filament/Resources/Events/Schemas/EventForm.php:19-66`;
draft-by-default `app/Services/EventService.php:34-47`; statuses
`app/Enums/EventStatus.php:5-37`; featured form and preview
`app/Filament/Resources/FeaturedContents/Schemas/FeaturedContentForm.php:17-129`,
visibility `app/Models/FeaturedContent.php:76-82`, homepage
`app/Http/Controllers/HomeController.php:61-85`, image markup
`resources/views/home.blade.php:85-89`; 503 copy
`lang/en/errors.php:11-12`, recovery
`resources/views/oauth/recovery.blade.php:1-12`; staging deploy and
moderator-role check `docs/ci.md:363-503`.*
