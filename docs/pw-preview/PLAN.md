# Lane PW: "Add to Home Screen" for the shop: phase 2 plan

Phase 1 is the preview (`docs/pw-preview/index.html`). Nothing visible ships
until the owner picks:

| Decision | Choices | Recommended |
|---|---|---|
| How it is offered | A bottom bar · B floating icon · C menu entry + one sheet after an order · D top banner (letters combine) | **C** (add B if he wants it on every page) |
| Button icon | i expand arrows · ii phone + arrow · iii plus-in-square · iv the app icon | **ii** |
| App icon | 1 KB monogram · 2 wordmark · 3 flower | **1** |
| Name under the icon | K-Beauty Bliss · KB Bliss · K-Beauty | confirm on a real iPhone (14 letters may be cut with "…") |
| "Not now" remembered for | 30 days (setting) | 30 |

Whatever he picks becomes the shop's state at apply time (CLAUDE.md, 30 September
reversal) and also gets a control in the admin so it can be undone. Everything
he did not pick stays byte-identical.

## What phase 1 measured (not assumed)

1. **Full screen ends on every page change.** On this shop, in Chromium,
   `document.fullscreenElement` was `true` on `/` and `false` after one
   navigation to `/shop/`. The owner app keeps full screen because it is a
   single page. The shop loads a new document per tap, so the shop's "expand"
   icon cannot be a full-screen switch. It opens the install, and the
   installed app (standalone) is what stays full screen. `tools/pwa-shop-preview.sh`
   and `tools/pwa-fs-nav.cjs` reproduce it.
2. **WhatsApp float:** 60×60, 48px above the viewport bottom, 44px from the
   right, at 390 and at 820 (`tools/pwa-backdrops.cjs` prints it). The shop
   already lifts it with `body:has(...) .kbw{margin-bottom:72px}`
   (`WhatsAppButton::LIFT_CSS`). A reuses that convention from its own CSS, and
   B sits on the opposite side, centred on the same line.
3. **The storefront viewport has no `viewport-fit=cover`**
   (`layouts/store.blade.php:144`). The owner app and the admin-only `/app`
   preview do. In Safari nothing about the notch changes either way. It only
   matters in the installed app (see iOS below).
4. **No theme-color, manifest, apple-touch-icon or service worker** exists on
   the storefront today. `public-web-root/` holds only `favicon.ico`.
5. **The bottom tab bar** (Store & content → Floating bottom menu (mobile),
   module `mobile_tabbar`) is off by default. The product page's sticky Add to
   Bag bar and the cart's docked bar also live at the bottom.
6. **CSP is report-only.** `default-src 'self'` covers `worker-src` and
   `manifest-src`. `script-src` has no `'unsafe-inline'`, so new code goes in
   the existing bundle (`resources/js/kbb/app.js`, loaded as a module through
   `@vite`), not in an inline script.
7. **Arabic** is `/ar/...`, rewritten by the global `SetLocaleFromPath` before
   routing. One `/manifest.webmanifest` route therefore answers
   `/ar/manifest.webmanifest` in Arabic too. Strings live in
   `App\Services\Translation\InterfaceStrings` and `ArabicInterfaceDrafts`.
8. **The owner app's SW is served by Laravel at `/{app}/sw.js`** and works on
   the live host, so a Laravel-served `/sw.js` reaches PHP there too. It is
   still checked with `curl -I https://extrabeauty.ae/sw.js` after apply,
   because a root path can be configured differently from a sub-path.

## Files (all lane-owned; wiring by the integrator)

- `routes/pwa.php`: `GET /manifest.webmanifest`, `GET /sw.js`,
  `GET /offline`. Wired by `tools/pw-wire.php` and `docs/pw-wiring.json` (one
  `require`, pinned `substr_count(...) === 1`, never "absent"). The package
  ships a `clear_caches_*` migration.
- `app/Http/Controllers/Storefront/PwaController.php`: manifest, worker and
  offline page.
- `resources/pwa/sw.js`: the worker template (placeholders filled by the
  controller, the same pattern as `AppController::worker`).
- `resources/views/partials/pwa-head.blade.php`: **the one head block**,
  between `{{-- PW:BEGIN --}}` and `{{-- PW:END --}}`, included once from
  `layouts/store.blade.php`. Lane PS may also be editing the head, so the
  block is a single `@include` line and nothing else moves.
- `resources/js/kbb/pwa.js`: a tiny bootstrap imported by `app.js`, plus a
  lazily `import()`ed `pwa-install.js` for the UI.
- `resources/views/partials/pwa-offer.blade.php`: the chosen letter's markup
  (menu row / thank-you sheet / bar / button).
- `tools/pw-icons.php`: draws `resources/pwa/icons/{icon-192,icon-512,maskable-512,apple-180}.png`
  with GD, the same way `tools/mac-icons.php` does. Opaque PNGs, because iOS
  paints transparency black. The mark stays inside the 80% maskable safe zone.
- Admin: **Appearance → Home Screen app**, with on/off, letter, days, menu
  label and short name. Its capability is `appearance.manage`, and it fails
  closed. The screen partial is wired via `pw-wiring.json` (Lane AP owns
  `admin/app.blade.php`).

## Manifest

`id: "/"`, `start_url: "/?utm_source=homescreen&utm_medium=app"` (`/ar/...`
for Arabic), `scope: "/"`, `display: "standalone"` (not `fullscreen`, which
hides the clock and battery and suits games, not shops), `theme_color:
"#FFFFFF"` (the header is white), `background_color: "#FFF8F5"` (`--cream`),
`lang`/`dir` from `Locale`, and icons 192/512 `any` plus 512 `maskable`. Every
URL is built from `$request->getBasePath()`, as `AppController::base()` does,
so staging under `/kbb-upgrade` gets `/kbb-upgrade/` throughout. Served as
`application/manifest+json`. The query-string marker is checked against the
shop's own UTM/attribution capture before choosing the final names.

## Service worker: safe by construction

**Rule 0. The worker never contains a secret.** `admin_path` and the owner-app
address are secret (CLAUDE.md, `OwnerAppPath`), and `/sw.js` is public. So the
worker cannot name them, and it does not need to, because it never stores any
HTML.

| Request | What the SW does |
|---|---|
| Any non-GET, any cross-origin (Tabby, Tamara, card/3-D Secure, Google, Meta, WhatsApp) | **Nothing.** No `respondWith`; the browser behaves exactly as without a SW |
| Navigation to `/cart`, `/checkout`, `/my-account`, `/order-pay`, `/api/`, `/admin-api/`, payment return paths | **Nothing** (public prefixes only, see Rule 0) |
| Any other navigation (including the secret admin and owner-app paths, which it cannot name) | `respondWith(preloadResponse \|\| fetch)`. **Network only, never stored**, with the precached offline page only if the network throws. Redirects pass through untouched |
| `/build/assets/*` (hashed names) | Cache-first, `kbb-assets-<version>`, cap 150 entries |
| Same-origin images (`destination === 'image'`) under the upload/variant paths | Stale-while-revalidate, `kbb-img`, cap 80 entries, only `200` and non-opaque, trimmed oldest-first |
| Everything else | Nothing |

Why HTML is never cached: every storefront page carries a cart count, a
signed-in name and a CSRF token (`CacheHeaders` says so). A stored page would
show the wrong bag and fail its next POST with 419. Network-only with an
offline fallback gives the offline experience with none of that risk. The
owner app's own worker has the narrower scope `/{app}/`, so it wins there.

- **Install:** precache only `/offline` (static, no CSRF, no name, no cart)
  and icon-192, about 10 KB in total. **Activate:** delete `kbb-*` caches
  except the current and previous asset versions (an open tab may still lazily
  load the previous build's chunk), enable navigation preload, then
  `clients.claim()`.
- **Updates:** the version is a hash of the Vite manifest plus the template.
  `/sw.js` is sent with `Cache-Control: no-cache` and registered with
  `updateViaCache: 'none'`. `skipWaiting()` is safe here, which is the
  "controlled update", because no cached HTML can disagree with new assets.
- **Kill switch:** with the setting off, `/sw.js` answers a worker that deletes
  every `kbb-*` cache and unregisters itself, and the head block stops
  registering. Tested.
- **Registration:** after `load`, in idle time, on storefront pages only
  (it is never in the admin layout).
- **Headers:** `application/javascript; charset=utf-8`, `nosniff`,
  `no-cache`. Both routes are `GET` only and read settings through the
  existing cached `SettingsService`, adding no new queries per page.

## iOS and iPad

- Head: `apple-touch-icon` 180, `apple-mobile-web-app-capable`,
  `mobile-web-app-capable`, `apple-mobile-web-app-status-bar-style: default`
  (a white bar *above* the page, so nothing sits under the notch), and
  `apple-mobile-web-app-title`.
- **Safe areas.** Step 1 keeps the viewport tag byte-identical and checks on a
  real iPhone whether, in the installed app, any fixed element (tab bar,
  WhatsApp, drawers, address sheet, sticky Add to Bag) sits under the home
  indicator. Only if it does, step 2 adds `viewport-fit=cover` **in the
  installed app only** (one line in `pwa.js`, run when `display-mode:
  standalone` matches), plus padding by `env(safe-area-inset-*)` in rules
  inside `@media (display-mode: standalone)`. Safari browsing is untouched
  either way.
- **Back button.** An installed iOS app has no browser Back button. A back
  chevron appears in the header only in standalone and only on inner pages.
  It uses `history.back()` and falls back to home. The edge-swipe gesture is
  checked on a device first.
- **Separate storage.** iOS gives a Home Screen app its own cookies and
  storage, so a Safari bag and login do not carry over. That is why C asks
  after an order. Confirmed on a device in phase 2.
- **Startup images.** Each one is a media-queried `<link>` sent on every page
  (about 12 sizes, about 2.4 KB of HTML per page) for a splash shown less than
  a second. Default: none, with the cream background. Added only if the device
  check shows a jarring flash, and then measured.
- **Instructions** do not depend on the iOS version: "Share (on iOS 26: ••• first)
  → Add to Home Screen → Add". On iPad the card sits under Share, top right.
  Chrome and Edge on iOS (16.4+) point to their address-bar Share button.

## The install UI (whichever letter)

- **Eligibility** is decided client-side with no layout measuring. It requires
  a touch device (`pointer: coarse`, plus iPadOS's desktop-UA case
  `Macintosh && maxTouchPoints > 1`), not standalone (`display-mode:
  standalone` or `navigator.standalone`), and not an in-app browser
  (Instagram, FBAN/FBAV, TikTok/musical_ly, Snapchat UA). It also requires no
  dismissal within N days and no recorded `appinstalled`. `localStorage` is
  always inside try/catch, and a refusal means "show nothing".
- **Never on** cart, checkout, order-pay or payment pages. The server simply
  does not render the hook there.
- **Android:** `beforeinstallprompt` is caught in the bootstrap
  (`preventDefault`, kept) and `prompt()` runs as the first statement of the
  tap. Without the event (Firefox), a two-line "⋮ → Install" sheet shows.
- **Lazy:** the bootstrap is roughly 1 KB in the existing bundle.
  `pwa-install.js` and the sheet markup load only when the UI is about to
  show. There is no timer that keeps running, no polling and no per-keystroke
  work.
- **Accessibility and RTL:** `role="dialog"`, `aria-modal`, focus moves in
  and back, Esc closes, logical properties throughout, and
  `prefers-reduced-motion` turns off the arrow bob.

## Security checklist

No secret in `/sw.js` (a test greps the body for `admin_path` and the
owner-app path). There is no new `/api` endpoint. The manifest is constants
plus escaped settings: the short name is plain text clamped to 30 characters,
and colours are validated hex. The scope cannot widen beyond the base path.
Cross-origin and non-GET requests are never intercepted. HTML is never stored.
There is a kill switch. The admin screen has its own capability and fails
closed. Headers carry the correct types plus `nosniff`.

## Speed

Measured, not asserted. The harness runs Playwright with CDP 4G throttling,
4× CPU and real mobile UAs. It records FCP, LCP, CLS, request count and
transferred bytes for a first visit and a repeat visit, before and after,
English and Arabic. The budgets are:

- 0 new render-blocking requests.
- The head block at 1.2 KB or less.
- First-visit FCP/LCP within run-to-run noise.
- CLS added by the offer = 0, because every offer is an overlay and none
  pushes content.
- `StorefrontQueryBudgetTest` unchanged.
- Flat cost against catalogue size (3 vs 40 products, the same number of
  queries).

The repeat visit should improve, with assets and images served from the SW.
Lighthouse runs too if it is installable here without a new project
dependency.

## Tests (each with its mutation note)

- `PwaManifestTest`: type, id/scope/start_url under the base path, Arabic
  `lang`/`dir`, icons exist and are hashed. *Drop `dir` and the Arabic case
  goes red.*
- `PwaWorkerTest`: content type and `no-cache`, no `admin_path` or owner-app
  path in the body (with both set to known values), kill switch body when off.
  *Interpolate the admin path and this goes red.*
- `PwaWorkerRulesTest` (Playwright, real SW): offline navigation shows
  `/offline`, cart/checkout are fetched from network and never stored,
  `caches` holds no `text/html` after browsing, a POST is untouched, an update
  activates and drops old caches, the kill switch unregisters.
- `PwaHeadBlockTest`: the head block is included exactly once,
  `StorefrontEnglishUnchangedTest` is advanced for exactly that block, and the
  admin layout has no head block.
- `PwaOfferPlacementTest`: no offer markup on cart/checkout/order-pay, the
  chosen letter's markup on its pages, and the after-order sheet only on the
  confirmation page.
- `PwaWiringTest`: `require .../pwa.php` count is 1, and the screen partial
  is included once.
- Screenshots at 390/430/820/1024 (plus SE 375×667, Pro Max 430×932, iPad
  1024×1366), English and Arabic, browser and standalone. Standalone is shown
  via CDP `display-mode` emulation where Chromium accepts it, and safe areas
  via the custom properties the CSS reads ahead of `env()`. Every shot records
  `scrollWidth` against `clientWidth`.

## What only a real phone can prove (about 10 minutes for the owner, after apply)

Chromium cannot emulate iOS standalone, Apple's separate storage or the
home-indicator area. The owner checks each of these on a real device:

- **iPhone (Safari):** add the shop to the Home Screen, open it, confirm the
  name under the icon is not cut off, check the header and bottom against the
  notch and home bar, and use Back. Then place one test order each with Tabby,
  Tamara and card inside the installed app, and confirm each returns to the
  thank-you page.
- **Android (Chrome):** install it and repeat the same checks.
- **iPad:** add it once.

Findings feed one follow-up package if needed.

## Scope as of 5 October (owner): build the app core only

The owner: "skip the app addition icon etc. we are deciding on it for now ...
just build the app for the site ... give me just one icon to test it". Phase 2
therefore ships only the installable app: manifest, service worker (all the
rules above), offline page, iOS/iPad head tags, ONE icon (1, white KB on the
pink gradient: apple-touch 180, 192, 512, maskable 512), name "K-Beauty
Bliss", and a minimal admin screen at **App → Site App** (on/off, default ON;
the app name; a read-only icon preview; capability `siteapp.manage`, failing
closed). He installs it from the browser's own menu.

## Decided later (keep in the master plan)

Nothing below is built; the code path leaves room for it without rework.

- **How it is offered:** A bottom bar · B floating icon · C menu row +
  My account row + one sheet after an order (recommended) · D top banner.
  Letters combine.
- **Button icon:** i expand arrows · ii phone + down arrow (recommended) ·
  iii plus-in-square · iv the app icon.
- **App icon:** 1 KB monogram (shipping now, for testing) · 2 wordmark ·
  3 flower.
- **"Not now" remembered for:** 30 days (proposed).
- **Name under the icon:** "K-Beauty Bliss", with "KB Bliss" as the fallback
  if a real iPhone cuts it.
- **Back chevron in the installed iPhone app** on inner pages, if the device
  check shows edge-swipe back is not enough.

## Phase 2 as built (app core only)

What the plan above said, and what changed after reading the code:

- **The manifest is localised by query, not by path.** `Locale::localisable()`
  refuses any path whose last segment has a dot, so `/ar/manifest.webmanifest`
  can't exist. An Arabic page links `/manifest.webmanifest?lang=ar`. The
  language comes from the enabled-locale allowlist and never from the
  request. Both languages are the same app (`id: "/"`).
- **The registration script is served by the shop, not by the Vite bundle.**
  `/site-app.js?v=<hash>` is deferred, 1.2 KB, and immutable under its hash.
  This means no shared `public/build` rebuild, and no inline script for the
  report-only CSP to flag.
- **Icons are served the same way.** `/site-app/icons/{name}.png?v=<hash>`
  serves allowlisted names only. They are drawn by `tools/pwa-icons.cjs` in
  the shop's own Outfit 800.
- **No `theme-color` meta on the page.** It would tint the browser bar for
  every ordinary visitor. The colour lives in the manifest only.
- **No viewport change.** The storefront viewport stays without
  `viewport-fit=cover`, so iOS keeps the installed app's page out of the
  notch and home-indicator areas. All fixed elements were measured inside
  the screen at five installed-app sizes. The owner's device test confirms
  it.
- **Standalone documents included.** The head block is also in the four
  documents that do not use the layout (blog, article, review wall, skin
  quiz), so every storefront page can be installed from. That makes 37
  pages, pinned by `StorefrontEnglishUnchangedTest`. Nothing else on any
  page moved.
- **Admin placement.** App → Site App is a static NAV row in the new App
  group. A row registered by a partial must anchor on a NAV id
  (AdminNavAndIdsTest), and an empty group has none. Owner App can register
  `after:['siteapp']`.
