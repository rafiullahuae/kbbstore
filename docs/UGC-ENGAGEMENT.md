# Shoppable UGC video — "the real likes, comments etc from the original source"

Phase 20, Lane V3. **This was researched, designed, built, and then CUT by the owner
mid-round**, and this file exists so nobody researches it a second time.

> **"The video must display the real likes, comments etc from the original source,
> and if uploaded, then count itself and users can like etc."**

and then, hours later:

> **"okay, leave the counts for now, just get the videos from there."**

---

## What is in the shipped code

**Nothing that fetches a count, nothing that stores one, and nothing that prints
one.** The cut was taken by deleting rather than by disabling:

| Deleted | Was |
|---|---|
| `app/Services/UgcEngagement.php` | the cache, the age gate and the column writer |
| `app/Services/Ugc/MetricsProvider.php` | the seam |
| `app/Services/Ugc/MetricsAnswer.php` | the three-valued answer and its seven states |
| `app/Services/Ugc/InstagramMetrics.php` | business_discovery |
| `app/Services/Ugc/TikTokMetrics.php` | an honest refusal |
| `app/Services/Ugc/YouTubeMetrics.php` | `videos.list?part=statistics` |
| `config/kbb.php` → `ugc.*` | `KBB_YOUTUBE_KEY`, `KBB_IG_TOKEN`, `KBB_IG_USER_ID` |
| `POST /admin-api/ugc-videos/{id}/metrics` | the refresh button |
| six `metrics_*` columns on `ugc_videos` | never shipped at all |
| `engagement` and `metrics_age` on the settings schema | never shipped at all |

**The columns were removed rather than shipped nullable-and-unwritten**, deliberately:
a column nothing writes is a column the next reader assumes something writes.

What survives is **our own** like count — `ugc_videos.likes`, a count of clicks on
this shop, one per browser, no personal data, and switched OFF by default because R3
draws no heart. That is a number this shop computes, so 0 genuinely means nobody has
pressed it.

---

## ▲ Everything below is UNVERIFIED

**Egress from the build container is blocked.** Not one line of this was executed
against a live endpoint. It is read off each platform's published documentation as of
this model's knowledge, and it should be re-checked before anybody spends money or
an App Review cycle on it.

---

## Instagram

| Path | Counts? | What it costs |
|---|---|---|
| **Public oEmbed** (`/oembed/`) | no | **gone.** Retired October 2020. |
| **oEmbed Read** (`/instagram_oembed`) | **no — it has never returned a count** | an app access token, plus the oEmbed Read feature through App Review. Returns `author_name`, `html`, `width`, a thumbnail. |
| **Graph API, our own media** (`/{ig-media-id}?fields=like_count,comments_count`) | yes | an Instagram **Business or Creator** account, a Facebook Page linked to it, a Facebook app, `instagram_basic` + `pages_show_list`, a long-lived Page access token, App Review. This is about *our* posts. |
| **Business Discovery** (`/{our-ig-user-id}?fields=business_discovery.username({handle}){media{permalink,like_count,comments_count}}`) | **yes, and this is the only one of use here** | everything in the row above, **plus the creator's account must itself be a public Business or Creator account.** A personal account returns nothing at all, and a great many small creators are personal accounts. |
| **View / play counts for someone else's reel** | **no, at any price** | plays live behind `/insights`, which needs a token for the account that posted it. |

**And business discovery is an ACCOUNT-level read, not a per-post one.** There is no
"give me the counts for this permalink" call: the response is the creator's recent
media, and our `source_url` has to be matched against it. A clip older than that
window comes back as a perfectly successful call containing no row for it — which is
why the deleted design wrote numbers only when the answer carried some, so an old
post could not null out yesterday's real figures.

**Embedding, as distinct from counts, appears still to be free.** The static
`<blockquote class="instagram-media" data-instgrm-permalink="…">` + `embed.js` form
and the `https://www.instagram.com/p/{code}/embed/` iframe do not obviously require a
token. The *API* that would hand us a thumbnail programmatically does. **UNVERIFIED.**

---

## TikTok

| Path | Counts? | What it costs |
|---|---|---|
| **oEmbed** (`https://www.tiktok.com/oembed?url=…`) | **no — it has never carried one** | nothing. No key, no auth. Returns `title`, `author_name`, `author_url`, `thumbnail_url`, embed html. |
| **Display API** (`/v2/video/query/?fields=like_count,comment_count,share_count,view_count`) | yes | an **OAuth authorisation from the account that owns the video**, with the `video.list` scope. Per creator. Re-granted when the refresh token expires. |
| **Research API** | yes | approved academic institutions only. |

**So for TikTok the honest answer is that counts are unobtainable.** Featuring a
creator's TikTok would mean that creator logging in to extrabeauty.ae with TikTok and
granting this shop access — which is not a thing a creator will do to be featured.

**The alternative was considered and rejected**: scraping the count out of the public
page's embedded JSON works today, breaks silently whenever they rename a key, is
against their terms, and would put a number on this shop that nobody can account for.

---

## YouTube — the one easy case, and the plan never considered it

`GET https://www.googleapis.com/youtube/v3/videos?part=statistics&id={id}&key={key}`
returns `statistics.viewCount`, `likeCount` and `commentCount` for **any public
video**, with a plain API key from the Google Cloud console and **no OAuth, no App
Review and no relationship with the creator**. Quota is 1 unit per call against a
default 10,000/day. `dislikeCount` was removed in December 2021.

`likeCount` is **absent** when the uploader hides it, and `commentCount` is absent
when comments are off. An empty `items` array is a 200 and means private, deleted or
region-blocked.

`youtube` is already in `UgcVideo::PLATFORMS`. If the counts question ever comes
back, **this is the platform to start with**, because it is the only one where the
answer costs a key rather than a review cycle. **UNVERIFIED.**

---

## The design rule that must survive, whoever builds this next

**A NUMBER THIS SHOP CANNOT VERIFY IS NOT PRINTED.**

Not zero. Not a dash where a figure should be. The element is not drawn at all —
exactly as the rating bar draws nothing for a product with no reviews, and for the
same reason.

So, concretely, if it is rebuilt:

- **Every metrics column nullable with NO default.** `NULL` is *we do not know* and
  `0` is *the source said zero*. A default of 0 makes those the same value, and the
  shop then prints "0 likes" under a reel with fourteen thousand of them the first
  time a token expires. That is not a cosmetic bug — it is the shop telling a shopper
  something false about somebody else's work.
- **Compare with `=== null`, never with a falsy test.** A reel posted an hour ago
  genuinely has zero comments. Hiding an honest zero is the same defect pointed the
  other way.
- **Record WHY, not just that there is nothing.** "We have no numbers" has at least
  five causes and the operator can act on four of them: no credential configured, the
  platform will not say, the token was refused, the source was unreachable, the post
  is gone. A single `null` flattens them into one shrug. The deleted
  `MetricsAnswer::STATES` had seven and a sentence for each.
- **No provider may throw.** A DNS failure behind an admin button is a sentence, not
  a 500, and an operator cannot act on a 500.
- **Nothing on the read path.** A rail must not make a third-party call per tile per
  render — twelve outbound calls behind a page a shopper is waiting for, any one of
  which can hang. The deleted design had exactly one caller (a button) and cached the
  answer for six hours, including the refusals, so a revoked token did not hammer Meta
  once per press.
- **Age the number out.** This host has **no cron and no queue worker**
  (`docs/LC-SECURITY-MODULE.md` says so for retention; `ProductVisibility` sets the
  same precedent by comparing against `now()`), so there is nowhere to put a nightly
  refresh — it would be a job that never ran. A count therefore ages, and past some
  number of days it must stop being printed rather than be printed stale.
- **A credential belongs in `.env`, not in `settings`.** This module's own precedent
  is `KBB_FFMPEG` (`docs/UGC-DATA-MODEL.md`), Cloudways gives this owner a shell, and
  a token in the settings table travels in every admin payload that reads it. Remember
  `.env` is not read at all while the config cache exists — `php artisan config:clear`
  after editing it.
- **Do not offer a box for the owner to type a count into.** It is unverifiable, it
  is stale within a day, and a figure a shopper reads as "Instagram says" that in
  fact says "somebody typed this in March" is the invented number wearing a hat.

---

## What the owner should be told, in one paragraph

Instagram will give this shop a creator's like and comment counts only if he sets up
an Instagram Business account, a Facebook app and an App Review, *and* the creator's
own account is a public Business or Creator account — and never a view count. TikTok
will not give them at all without the creator personally logging in to the shop and
granting access. YouTube will give views, likes and comments for the cost of an API
key. Until one of those is in place the shop shows no number rather than a zero,
because a zero on a reel with fourteen thousand likes is a lie and a shopper cannot
tell it from a fact.
