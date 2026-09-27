# Instagram Profile — what Meta actually permits for **our own** account

Phase 21, Lane IG. The owner's request, verbatim:

> "i want another function called Instagram Profile. our instagram is
> https://www.instagram.com/kbeauty.bliss/ i want a nice grid type instagram section
> where all our recent posts/videos display and playable on our site directly from
> instagram, like embed type, all likes etc should be show. and also our profile box
> should also show. please prepare multiple layouts for the instagram section, so i
> can choose from. The system should capable by configure auto by clicking the
> configure now button. it should reach to instagram, take permission and configure.
> and display the profile log, and choose the style etc. and can be used shortcode to
> display this section anywhere."

`docs/UGC-ENGAGEMENT.md` is the prior research and it saved this lane hours. **Read it
first.** What follows re-states the two of its findings that turn out to be the wrong
shape for this task, and adds the one it only gestured at — because *this* task is
about **our own** account, which is a completely different permission problem from
*a creator's* account, which is what that round was costing.

---

## 0. Egress is blocked in this container, and here is the proof

```
$ curl -sS -o /dev/null -w '%{http_code}' https://graph.instagram.com/v23.0/me
curl: (56) CONNECT tunnel failed, response 403
$ curl -sS -o /dev/null -w '%{http_code}' https://graph.facebook.com/v23.0/
curl: (56) CONNECT tunnel failed, response 403
$ curl -sS -o /dev/null -w '%{http_code}' https://api.instagram.com/oauth/authorize
curl: (56) CONNECT tunnel failed, response 403
$ curl -sS -o /dev/null -w '%{http_code}' https://www.instagram.com/kbeauty.bliss/
curl: (56) CONNECT tunnel failed, response 403
```

All four refused at the egress proxy. **Not one line below was executed against a
live endpoint.** Everything in this file is read off Meta's published developer
documentation as of this model's knowledge, and every claim is marked with how it
was established. The whole flow is built and tested against `Http::fake()`, and §7
lists exactly which steps remain unverified and what the owner must do to finish
them.

The same honesty rule the previous round set applies: **a number this shop cannot
verify is not printed.** It is carried through into `InstagramPost` as *nullable with
no default* — `null` is "we do not know", `0` is "Instagram said zero" — and the
template draws no element at all for a null.

---

## 1. The surface, as of now — and Basic Display is GONE

`docs/UGC-ENGAGEMENT.md` lists four Instagram paths. Two of its four rows are still
right, one is the wrong question for this task, and one path it does not mention is
the one we want.

| Path | Own media? | `like_count` / `comments_count`? | Cost |
|---|---|---|---|
| Public oEmbed (`/oembed/`) | — | no | **gone.** Retired October 2020. Confirmed unchanged. |
| oEmbed Read (`/instagram_oembed`) | any public post | **never** | app token + oEmbed Read via App Review. Gives `author_name`, `html`, a thumbnail. Still no counts. |
| **Instagram Basic Display API** | own media | **no — it never carried counts** | **DEPRECATED, shut down 4 December 2024.** Do not build on it. |
| Business Discovery | *someone else's* media | yes, if they are a public Business/Creator | the row above plus their account type. **This is the row UGC-ENGAGEMENT costed, and it is not our problem.** |
| **Instagram API with Instagram Login** | **our own media** | **YES** | an Instagram **professional** (Business or Creator) account, a Meta app with the *Instagram* product added, `instagram_business_basic`. **No Facebook Page required.** |
| Instagram API with Facebook Login | our own media | yes | the above **plus** a Facebook Page linked to the IG account, `instagram_basic` + `pages_show_list` + `pages_read_engagement`. |
| `/insights` (plays, reach, saves) | own media | yes, but a *different* field set | `instagram_business_manage_insights`. **Not used here** — see §4. |

**The finding that matters, and it is the opposite of the previous round's
conclusion:** UGC-ENGAGEMENT concluded that counts cost "an App Review cycle". That
is true for *business discovery on a creator's account*. For **our own** account it
is not: `like_count` and `comments_count` are ordinary fields on
`GET /me/media` under `instagram_business_basic`, and that scope is available at
**Standard Access without App Review** to any person who has a role on the app.
The owner IS the person with a role on his own app. So the expensive half of that
document's conclusion does not apply to this feature at all.

**▲ UNVERIFIED, and it is the single most load-bearing claim in this file.** If Meta
has since moved `instagram_business_basic` to Advanced-Access-only, the owner needs
an App Review and §7 says so. The flow degrades honestly either way: an unauthorised
token produces a refusal with a sentence, not a 500, and the section renders nothing.

### The exact calls this lane implements

```
1  authorise   GET  https://www.instagram.com/oauth/authorize
                    ?client_id=<IG app id>&redirect_uri=<ours>&response_type=code
                    &scope=instagram_business_basic&state=<40 random chars>

2  short token POST https://api.instagram.com/oauth/access_token
                    client_id, client_secret, grant_type=authorization_code,
                    redirect_uri, code            -> access_token (1 hour), user_id

3  long token  GET  https://graph.instagram.com/access_token
                    ?grant_type=ig_exchange_token&client_secret=..&access_token=..
                                                  -> access_token (60 days), expires_in

4  refresh     GET  https://graph.instagram.com/refresh_access_token
                    ?grant_type=ig_refresh_token&access_token=..
                                                  -> a fresh 60 days

5  profile     GET  https://graph.instagram.com/v23.0/me
                    ?fields=id,username,name,account_type,profile_picture_url,
                            followers_count,follows_count,media_count

6  media       GET  https://graph.instagram.com/v23.0/me/media
                    ?fields=id,caption,media_type,media_url,permalink,thumbnail_url,
                            timestamp,like_count,comments_count,children{media_url,
                            media_type,thumbnail_url}
                    &limit=<n>
```

Step 4 is the one with no home on this host — see §5.

---

## 2. `media_url` is a signed, expiring CDN URL. This decides the whole design.

Every `media_url` and `thumbnail_url` Meta returns is a `*.cdninstagram.com` /
`*.fbcdn.net` address carrying a signature and an expiry in its query string.
Documented lifetime is short; Meta's own guidance is not to store them.

So "playable on our site" has exactly three honest readings, and this lane chose
deliberately:

| Reading | What it costs | Verdict |
|---|---|---|
| **(a) play `media_url` in our own `<video>`** | The tile plays natively and looks perfect **until the URL expires, at which point every video on the homepage is a black box.** Also needs CSP `media-src` widened to the CDN, and re-fetching on every expiry means a scheduled job this host does not have. | **REJECTED.** A homepage that breaks on somebody else's clock. |
| **(b) Instagram's own embed iframe**, `https://www.instagram.com/reel/{code}/embed/` | Plays in place, always current, **no token and no expiry** — Instagram resolves the media itself. Costs an iframe, and CSP `frame-src`. | **CHOSEN, on demand only.** |
| **(c) link out to `permalink`** | Always works, no CSP, no iframe. Leaves the shop. | **CHOSEN as the shipped default and the permanent floor.** |

**And the grid tiles themselves never point at Instagram at all.** At fetch time, in
the admin, `InstagramSync` **downloads each post's thumbnail into this shop's own
`storage/app/public/uploads/instagram/` and stores the local path**. That one decision
answers four problems at once:

* the poster cannot expire, so the grid cannot go blank;
* the storefront makes **zero** third-party requests per render, which is rule 4's
  "a rail that blocks on a third party is a shop that goes down when they do";
* CSP `img-src 'self'` already covers it — no directive widening for the grid;
* we know the file's real pixel dimensions, so `width`/`height` are honest and
  layout shift is zero.

A post whose thumbnail could not be downloaded is **stored with `local_path = null`
and is not rendered** — the same rule `UgcRail` applies to a clip with no poster, for
the same reason: a tile with no poster is a hole in the page at first paint.

---

## 3. Content-Security-Policy — what this feature needs, named rather than widened

`App\Services\Security\ContentSecurityPolicy` is in its **report-only** phase
(`HEADER = 'Content-Security-Policy-Report-Only'`), so nothing this feature draws is
blocked today; violations feed the round that enforces. Rather than widen a directive
silently, here is the exact and complete list:

| Directive | Today | Needs | Why |
|---|---|---|---|
| `img-src` | `'self' data: https:` | **nothing** | thumbnails are local files. `'self'` covers them, and would still cover them after `https:` is narrowed. |
| `media-src` | `'self'` | **nothing** | this feature never emits a `<video>` pointing off-origin. That is decision (a) being rejected in §2. |
| `frame-src` | `https://js.stripe.com https://hooks.stripe.com` | **`https://www.instagram.com`** — *only if the owner turns the in-page player on* | Instagram's embed is an iframe on that host. The tap setting ships at `permalink`, which needs nothing. |

**The one line the enforcing round owes this feature, and only if the setting is
moved off its default:**

```php
'frame-src' => ['https://js.stripe.com', 'https://hooks.stripe.com', 'https://www.instagram.com'],
```

This lane did **not** make that edit. The admin screen says the sentence instead, on
the control that would need it, so the owner cannot pick the in-page player without
being told what else it wants.

---

## 4. What we deliberately do NOT ask for

* **`instagram_business_manage_insights`** — plays, reach, saves, profile views. It is
  a second permission, it is the one most likely to want an App Review, and the owner
  asked for "all likes etc", not for analytics. Reels **play counts** live only here;
  UGC-ENGAGEMENT is right that they are not on the media edge. **So this shop prints
  no play count at all** rather than printing something else labelled as one.
* **`instagram_business_content_publish`** — posting *to* Instagram from the shop.
  Not asked for, and a write scope on a read feature is a scope to be sorry about.
* **`business_discovery`** — other people's accounts. Not this feature.
* **Comment text.** `comments_count` is a number; the comments themselves are other
  people's words, would need moderation, and are a data-protection question nobody
  asked. The count is shown; the thread is not.

---

## 5. Token lifetime, and the fact that this host has no cron

The long-lived token lasts **60 days** and is refreshed by calling
`refresh_access_token` **while it is still valid**. Let it lapse and the only repair
is the full authorisation again.

`docs/UGC-ENGAGEMENT.md` records that this host has **no cron and no queue worker**,
so a nightly refresh would be a job that never ran. Three things instead, and they are
belt, braces and a written warning:

1. **Opportunistic refresh.** Every admin call that touches Instagram — opening the
   screen, pressing Refresh posts — refreshes the token first if it is inside
   `InstagramCredentials::REFRESH_WINDOW_DAYS` (7) of expiry. An owner who opens the
   admin once a month never sees this happen.
2. **The screen says the date.** `Content → Instagram` prints "Connection valid until
   3 December" and turns that line amber inside the window and red past it. A number
   nobody can see is a number nobody renews.
3. **The storefront is never affected by it.** An expired token stops *new* posts
   arriving. The posts already fetched are rows in our table with local thumbnails, so
   the section keeps rendering exactly what it rendered yesterday. **An expired token
   is a stale section, never an empty one and never a broken page.**

Cloudways now gives this owner a shell (CLAUDE.md, 24 September 2026), so a real cron
entry calling an artisan command is available if he wants one. This lane did not add a
command that nothing on the server is configured to call; §7 says what to run.

---

## 6. Security decisions

* **The app secret and the long-lived token never reach the browser.** Both live in
  `settings`, encrypted with `Crypt::encryptString` — the `TranslationCredentials`
  precedent, chosen for its stated reason: `.env` cannot be written by an update
  package (`UpdateGuard::FORBIDDEN_PREFIXES`). `InstagramCredentials` exposes
  `hasSecret()` / `hasToken()` / `expiresAt()` and **no getter reachable from a
  payload** — the `SecretStore` contract's own argument, applied by hand because this
  is not a `ModuleSchema` field.
* **Neither key is on `SettingController::PUBLIC_KEYS`.** `/api/*` is unauthenticated;
  `InstagramSecurityTest` asserts by name that the public settings endpoint does not
  return either, in the shape `BilingualFoundationTest` already uses for the
  translation key.
* **OAuth `state` is 40 random characters, server-side, single-use**, held in the admin
  session and `pull()`ed *first* in the callback so every early return has already
  spent it — the `StripeConnect` shape, including the empty-string guard, because
  `hash_equals('', '')` is `true` and a callback carrying no state arriving in a
  session holding none would otherwise pass. TTL 15 minutes.
* **The redirect URI is built by the server**, never accepted from a request, and is a
  fixed path (`/admin-api/instagram/callback`) so the owner's Meta app configuration
  never has to change — and so it does not embed the secret admin path.
* **Every admin endpoint has its own capability and fails closed** —
  `instagram.view` / `instagram.manage`, writes mapped above reads in
  `AdminCapabilities::RULES` because that array is first-match-wins.
* **Everything from Instagram is remote user input.** Caption, username, name and
  media type are printed through `{{ }}`. `permalink` and `profile_picture_url` go
  through `UgcPath::link()` — decode, strip, then read the scheme — before they can
  become an `href`, and the shortcode's embed URL is **rebuilt from the post's own
  shortcode characters** (`[A-Za-z0-9_-]`) rather than from the permalink string, so
  no remote byte reaches an `iframe src`.
* **A remote id is never a filename.** The downloaded thumbnail is named from
  `sha1(remote_id)`, not from anything Meta sent.

---

## 7. What the owner must still do himself — the honest list

The **Configure now** button cannot invent a Meta app, and this lane will not pretend
it can. What the button does, in order, is in `docs/IG-PROFILE.md §8`; what it cannot
do is this:

1. **Make `kbeauty.bliss` a professional account.** Instagram app → Settings →
   Account type and tools → Switch to professional account → **Business** or
   **Creator**. A personal account returns nothing from any of these endpoints at any
   price. *Two minutes, in the phone app.*
2. **Create a Meta app.** developers.facebook.com → My Apps → Create app → use case
   **"Other"** → type **Business** → add the **Instagram** product → *API setup with
   Instagram login*. *Five minutes.*
3. **Paste the Instagram app ID and Instagram app secret into
   `Content → Instagram → Connection`.** They are on that same *API setup with
   Instagram login* panel. The secret is stored encrypted and never shown again.
4. **Register the redirect URI, exactly.** Same panel → *Business login settings* →
   **OAuth redirect URIs**. The screen prints the exact string to paste — on the live
   shop it is `https://extrabeauty.ae/admin-api/instagram/callback`. A single
   character of difference is the error Meta reports as
   `redirect_uri` mismatch, and it is the most common way this flow fails.
5. **Add himself as a tester if the app is in Live mode.** App roles → Instagram
   testers → add `kbeauty.bliss`, then accept the invitation in the Instagram app
   under Settings → Apps and websites → Tester invites. In **Development** mode an app
   admin needs no invitation.
6. **Then press Configure now.** Everything after this point is automated.

**App Review:** on the claim in §1, *none is needed* for reading our own account's
media with `instagram_business_basic` at Standard Access. **If Meta refuses the
scope**, the refusal arrives in the callback's query string, the screen prints it
verbatim, and the remedy is App Review for `instagram_business_basic` with a screencast
of this shop's own Instagram section — which is the ordinary submission, not a special
one. Nothing else in the shop is affected while that is pending.

**Optional, now that Cloudways gives a shell:**
`php artisan kbb:instagram-sync` does not exist and was not invented; the refresh is
opportunistic per §5. If the owner wants a cron, the thing to schedule is a hit on the
admin refresh endpoint from a logged-in session, which a cron cannot do — so the
honest advice is **open the screen once a month**, and the screen says so.

---

## 8. What "Configure now" actually does

The button is a **three-step wizard that says what it is about to do before it does
it**, because the owner asked for one click and one click is not honestly enough:

| Step | What the screen does | Automated? |
|---|---|---|
| 1 | Prints the six items in §7 as a checklist, with the exact redirect URI to copy and a Copy button. Nothing is sent. | reading only |
| 2 | Takes the Instagram app ID and app secret. Stores the secret encrypted. Validates the ID is digits and the secret is 32 hex characters **before** anything leaves this server. | yes |
| 3 | **Configure now.** Mints a single-use `state`, redirects to Instagram's own authorisation screen, and on the way back: verifies the state with `hash_equals`, exchanges the code for a short token, exchanges that for a 60-day token, stores it encrypted, fetches the profile, fetches the most recent media, downloads every thumbnail locally, and writes the rows. Then it lands the owner back on the screen with his own profile picture, follower count and grid showing. | **yes, all of it** |

Step 3 is the whole of "it should reach to instagram, take permission and configure,
and display the profile". The button is labelled **Configure now** on a fresh shop and
**Reconnect** once a token is stored, and the note beneath it says which of the six
manual items are still outstanding — checked from what is actually saved, not from a
box the owner ticked.

---

## 9. The layouts

Five, all of them CSS-only, all sized with `calc()` / `clamp()` / `min()`, **no
JavaScript that measures anything** (rule 4 — `CheckoutFloatingBarGateTest` and
`CartPageSqueezeTest` forbid the element-measuring APIs by name).

| Key | Name | Shape |
|---|---|---|
| `grid` | **Square grid** | Instagram's own 3-across (2 on a phone) square grid. The default. |
| `rail` | **Peeking rail** | One row, scrolls sideways, the next tile peeking — the geometry `UgcSettings` already measured for R3. |
| `mosaic` | **Mosaic** | First tile double-width and double-height, the rest square around it. |
| `masonry` | **Tall cards** | 4:5 portrait cards, the shape a reel is actually shot in. |
| `strip` | **Slim strip** | One short row of small tiles, for a footer or a sidebar. |

`profile_style` is separate, because the owner asked for the profile box as its own
thing: `card` (avatar, name, counts, Follow button), `bar` (one line above the grid),
`inline` (the avatar becomes the first grid cell) or `off`.

Every layout is one class on the section element and a `calc()` tile width; the tile
markup is identical across all five, so a layout cannot be the one that drops the
escaping.

---

## 10. Cost

`InstagramFeed` is **two queries, flat** — the profile blob is a module setting (no
query; it rides the `module_settings` snapshot the module already reads) and the posts
are one `SELECT ... LIMIT n`, cached as plain arrays for `FEED_TTL` (600s) under this
class's own index, exactly as `UgcRail` does. Measured as a slope at 1, 2, 5, 10 and
20 posts; see `InstagramSlopeTest`.

**With the module off the storefront does nothing at all**: `enabled()` reads the
`module_toggles` snapshot the homepage has already warmed, returns false, and the
section renders no bytes — no query, no cache read, no outbound request.
