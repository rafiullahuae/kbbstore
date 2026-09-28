# Lane briefs — the current round

Six lanes, three at a time, batched into two or three packages per round the way
`CLAUDE.md` sets out. Every brief below assumes `CLAUDE.md` in full and
**`## What every lane owes, every time`** in particular: nothing that already
works may change, every patch arrives with a picture at 390 and 1280, every
control names where it sits in the admin, no N+1s and no JavaScript that
measures layout, secure by construction, and every fix ships with the test that
goes red without it.

**Lane B was un-parked on 24 September and its work is merged** — Ed25519
signing ships in `permissive` mode in 2.60.267, enforcing nothing. The
paragraph below is the reasoning for parking it and is kept as written; what
changed is the last sentence of it. It said signing "becomes urgent the day a
second install exists, and not before". Hours later five hand-built packages
bricked this shop's updater, and while signing would not have caught that
fault — it proves origin, never correctness — it made the argument for doing
the key work with ONE install rather than fifty concrete rather than
hypothetical. The six-step rollout is `docs/PACKAGE-SIGNING.md` §1 and the
steps may not be merged.

**Lane B is not assigned this round.** The Ed25519 signing work
(`KBB-Master-Plan.md`, Phase 19 — `BuildPackage.php:199` writes
`'signature' => ''` unconditionally) is parked at the owner's instruction to
save a package slot. Parking it changes nothing about today's risk: `CLAUDE.md`
already records *"Unsigned update packages are accepted (`KBB_UPDATE_SECRET`
unset) — a deliberate choice while the site is a test deployment."* It stays
that way. It becomes urgent the day a second install exists, and not before.

## Reading a brief

| Heading | What it means |
|---|---|
| **The work** | The open master-plan item, quoted or cited by line |
| **Where it sits** | The exact admin path a human clicks to see it |
| **Owns** | The only files this lane may write |
| **Must not touch** | Files another lane owns, or that this project has already been burned by |
| **Done when** | The finish line, in checkable terms |

Two rules apply to every lane without exception, because both have already cost
this project a round:

- **`routes/web.php` is the integrator's file.** Declare routes in your own
  file; say in the PR body what needs wiring. Every package that adds a route
  also ships a `clear_caches_*` migration.
- **`KBB-Master-Plan.md` and `KBB-Progress-Dashboard.html` are the integrator's
  files.** Write what you did in the PR body. The integrator merges the plan.

---

## Lane A — the four migration defects that are still open

**The work.** Phase 13 has exactly four unticked ▲ items left, and they are the
last things standing between the owner and switching the old shop off:

1. **Fetching does not re-point the rows** (plan line 1912). Two steps, and
   between them the picture is on disk while the product still names the old
   host — so *the old site must not be switched off between them*, which is a
   procedure, not a fix. Make the apply step re-point the rows it fetched.
2. **Journal images are still hot-linked after an import** (line 1948).
   `MediaRewrite` does not know about `posts`. `posts.cover` is a one-line
   addition; the `<img>` tags inside `posts.body` are not, because that rewriter
   changes *cells* and not *documents*. A document rewriter is the real task —
   and it must be idempotent, because the import is.
3. **An article at a reserved address cannot be served** (line 1952). Articles
   live at the site root and `PageController::RESERVED_SLUGS` owns the first
   segment, so a live article slugged `about`, `wishlist` or `feed` is an
   indexed URL this app can never answer. The import already refuses these and
   names them in the discard list. What is missing is the owner's side of it:
   run a preview — it writes nothing — and put the list somewhere he can read
   and act on, because each one is rename-and-redirect in WordPress.
4. **The export screen tells the owner to delete a folder he cannot reach, and
   it holds every shopper's password hash** (line 2118). `customers.csv` carries
   addresses and password hashes; `reviews.csv` carries emails and IPs. The
   instruction is correct and the owner has no shell and no FTP. **Read the
   trap before writing anything**: `wp_ajax_kbb_export_reset` is registered and
   reachable, and `KBB_Export_Runner::reset()` only `delete_option()`s the
   state — wiring the existing endpoint to a button reports success while the
   hashes stay on disk, unreferenced and un-downloadable. This wants a real
   delete: a new destructive path, with a typed confirmation, a capability of
   its own, and a test that asserts the files are gone from disk and not merely
   that the endpoint answered 200.

**Where it sits.** Store → Import → Addresses & pictures → apply (1, 2); the
discard list on Store → Import → preview (3); the WordPress plugin's own export
screen (4).

**Owns.** `app/Services/Import/**`, `app/Http/Controllers/Admin/Import*`,
the exporter plugin under `wordpress-plugin/` (item 4 only),
`resources/views/admin/**/import*`, `tests/Feature/*Import*`,
`docs/GB-MEDIA-AND-REDIRECTS.md`, `docs/GN-EXPORT-SCREEN.md`.

**Must not touch.** `app/Http/Middleware/CheckRedirects.php` and
`app/Http/Middleware/CanonicalHost.php` — Lane D owns those this round.
`PageController::RESERVED_SLUGS` is read-only here: item 3 is a *report*, and
changing the routing to serve reserved-slug articles is a separate decision the
owner has not made.

**Done when.** A fetch-then-apply cycle leaves zero rows naming the old host,
asserted by a query and not by eye. An imported post body has no absolute
old-host `<img>` src, and re-running the import does not double-rewrite. The
preview produces a list with each article's title and the URL it wanted. The
export folder can be genuinely emptied from the browser, proved by asserting
the files are absent afterwards.

**Watch for.** `ImportAtVolumeTest` blames a different row each run when the
disk is full — `df -h /` before debugging any intermittent database error.

---

## Lane C — the security module, part one and only part one

**The work.** Phase 18, items 6 and 7, in that order and no further:

- **An admin audit trail.** There is no model for it today. Who changed which
  setting, who installed which package, who signed in from where. The shop keeps
  `NotFoundLog` and `PaymentEvent` and nothing about administrative action —
  which is the record an incident is actually reconstructed from.
- **The report screen the owner asked for.** Store → Security, with a plain
  verdict line at the top rather than a dashboard that has to be interpreted:
  failed sign-ins, rate-limit trips (which vanish silently today), and the audit
  rows, each with its evidence.

**"Report before enforce" is the whole sequencing decision and it is already
made.** A module that starts blocking on day one blocks the owner, the payment
provider's webhooks and Google's crawler, gets switched off, and leaves the shop
worse off than before because everyone now believes it is protected. **This lane
blocks nothing.** No request gate, no CSP, no integrity enforcement — those are
later rounds, and a lane that ships one of them early has broken the sequencing
the plan is built on.

**Where it sits.** Store → Security (new). Its switches and thresholds come from
a settings schema in the module's own service, the way `CartPage` and
`MobileHeader` already do, so a new option is a schema row and not a new screen.

**Owns.** `app/Services/SecurityModule.php` (new),
`app/Models/AuditEvent.php` (new), its migration,
`app/Http/Controllers/Admin/SecurityController.php` (new),
`resources/views/admin/partials/security-screen.blade.php` (new),
`tests/Feature/SecurityModuleTest.php` (new).

**Must not touch.** Anything under `app/Http/Middleware/` — recording an
administrative write is a listener or an explicit call at the write, not a
global middleware, and a global middleware here is exactly the "started blocking
on day one" failure. `Setting::map()` memoises in a process-level static as well
as the cache; within one long-lived process it will not see writes made after
the first call, which is a trap in tests and queue workers.

**Done when.** Every administrative write lands an audit row carrying actor, IP,
what changed and the before/after, and Store → Security shows them with a
verdict line. The screen's own capability fails closed. Not one request on the
shop is refused that was not refused before — assert it: the storefront walk is
byte-identical.

---

## Lane D — the redirect table that can only fire from the 404 handler

**The work.** Verified in the code, not assumed:
`app/Providers/AppServiceProvider.php:242–258` calls
`CheckRedirects::findMatch()` and `::recordHit()` from the 404 handler, and
`app/Http/Middleware/CheckRedirects.php` is written as middleware that is never
registered as one. The consequence Lane GB measured and left on the board: **a
redirect row for an address the shop already answers can never fire.** The old
shop has five years of URLs; every one that collides with a slug this app serves
silently keeps serving the wrong page instead of redirecting.

Read `app/Services/Import/RedirectMap.php` first — lines 36, 84, 239, 277, 425,
495 and 536 each record a decision made *because* the table is 404-only, and
registering the middleware changes the premise under several of them. In
particular `findMatch()` compares `source` against `getPathInfo()`, and
`CanonicalHost` (line 77) consults the same table on the same comparison. Both
have to stay consistent, or a redirect fires on one host and not the other.

**Where it sits.** Store → Import → Addresses & pictures (the redirect list the
import writes); no new screen.

**Owns.** `app/Http/Middleware/CheckRedirects.php`,
`app/Http/Middleware/CanonicalHost.php`, `bootstrap/app.php` (registration line
only), `tests/Feature/*Redirect*`, `docs/GP-ADDRESSES-LAND.md`.

**Must not touch.** `bootstrap/app.php`'s closing
`usePublicPath('/home/.../public_html/kbb-upgrade')` — the web root is a
different directory from the application root and that line is load-bearing.
`app/Services/Import/**` is Lane A's this round: if the map needs a change, say
so in the PR body.

**Done when.** A redirect row for a path the shop *does* answer fires, proved by
a test that would 200 on the old code. No storefront URL that worked before
redirects now — the walk is byte-identical, and the redirect precedence against
the router is asserted rather than described. Registration ships with a
`clear_caches_*` migration, because the route cache holds the middleware stack.

**Watch for.** A redirect loop is the failure mode here: a row pointing a page
at itself, which `RedirectMap` already refuses at write time (line 425) and
which the middleware must refuse at read time too, because rows written by the
old code are in the owner's database now.

---

## Lane E — the four SEO halves that are still `[~]`

**The work.** Phase 12 has four partly-done items, and each is a named half:

1. **Per-product SEO storage** (line 1782) — the major find that opened it:
   `products` carries no SEO columns at all, so a per-product title/description
   has nowhere to live.
2. **The Yoast importer** (line 1806) — the field map is named and built
   (`_yoast_wpseo_title` and siblings); it needs the storage above to land in.
3. **Structured data** (line 1812) — sitewide, product, BreadcrumbList and
   article are real; what remains is named in the item.
4. **Image pipeline · cache strategy** (line 1824) — measured, and the homepage
   was the offender. The cache-header half is in
   `docs/cache-headers.htaccess` and `docs/FQ-CACHE-HEADERS.md`.

Take them in that order: 2 cannot land before 1, and 4 is independent.

**Where it sits.** Store → SEO for the sitewide settings; the per-product fields
belong on the product editor's own SEO section, which is where an operator
looks for them.

**Owns.** `app/Services/Seo.php`, `app/Services/Import/Entities/SeoImporter.php`,
the `products` SEO migration, the product editor's SEO panel,
`tests/Feature/*Seo*`, `docs/FX-SEO-LEFTOVERS.md`,
`docs/IMAGE-PIPELINE-AND-CACHE.md`.

**Must not touch.** `app/Services/Import/**` beyond `SeoImporter` — Lane A owns
the importer. `Product::toApi()` is an allowlist and the reason
`tests/Feature/ApiSecurityTest.php` exists: a new column on `products` does
**not** join the API response unless the owner asked for it, and a lane that
adds one without touching the allowlist has done the right thing.

**Done when.** A product carries its own title and description, a Yoast export
fills them, and the rendered `<head>` uses them — asserted at the rendered
markup, not at the model. `StorefrontQueryBudgetTest` is unchanged or raised
deliberately with the reason in the commit. The structured data validates.

**Watch for.** A variable product's `products.price` is genuinely NULL and
`Seo` used to publish `{"@type":"Offer","price":"0.00"}` — it now publishes a
real AggregateOffer. The tile still reads AED 0, and **that is the owner's call,
not this lane's**: four readers take `products.price` directly and backfilling
it breaks idempotency unless `ProductImporter` stops writing null over it in the
same change. Report it; do not decide it.

---

## Lane F — the Arabic catalogue that no page reads

**The work.** T4, and the finding that defines it: **nothing on the storefront
reads a content translation.** `t()` is called by no Blade file, no view
composer and no storefront controller — measured with a sentinel, not grepped.
With the shop fully translated and Arabic on, `/ar/shop` renders "Hydrating
Serum No. 360" and zero occurrences of that product's Arabic name. Every Arabic
word on an Arabic page today is an interface string. The owner's ~55 hours of
catalogue typing would currently land in a table no page reads.

The call sites are named: `product-card.blade.php`, `store/product.blade.php`,
quick-view, `product-tabs`, and `Store\ProductController::tabs()`.

Ship the **map split in the same cycle**, not before it: keep the cached map for
short text (0.17 MB, 2,446 entries) and take `description`, `ingredients`,
`how_to_use`, `pages.content` and `posts.body` out of it — a 19× reduction, one
file. The measurement is already done and is in `docs/fn-translation-at-scale.md`:
Arabic costs +6 MB of peak memory and +11–43 ms of wall clock per page, **none
of it SQL**, and 97% of the map is product long-prose read on one page at a time.

**Where it sits.** Content → Translations (the progress figures and editor boxes
already there); the render has no screen of its own.

**Owns.** `app/Services/Translation/**`, `app/Support/Locale.php`,
the five Blade call sites above, `Store\ProductController::tabs()`,
`tests/Feature/*Translation*`, `docs/fn-translation-at-scale.md`,
`docs/fp-storefront-reads-translations.md`.

**Must not touch.** `resources/css/kbb/**` — Lane G owns CSS this round.
`ProductEditorApiController`'s sanitiser: the T4b gap was not a two-line fix and
the third line was a stored-XSS hole. Whatever you render, render it escaped;
the rich fields are the ones that bite.

**Done when.** `/ar/shop` renders the Arabic name for a translated product and
`/shop` is byte-identical to before. No N+1: `/ar/shop` runs the same seven
statements as `/shop` and `/ar/cart` the same eight as `/cart`, pinned at 400
rows as well as at 24 — that is the existing measurement and it must still hold.
Peak memory on an Arabic page drops by the amount the split predicts, measured.

---

## Lane G — the 57 declarations RTL left standing

**The work.** T6's mechanical half landed in 2.60.201: 678 physical direction
declarations counted with a real CSS declaration reader (not grep, because
`margin-left` occurs both as a declaration and inside comments explaining why a
rule keeps `margin-left`), 368 converted to logical properties, **57 left
physical on purpose and each marked `RTL-PHYSICAL:` with its reason.** Those 57
are the work.

Two findings account for 29 of them and should be taken as units:

- **16 are one finding.** Every slide-in panel anchors with an inset and opens
  with `translateX`, which has no logical form. Convert the inset alone and in
  RTL **the drawer sits on screen in its closed state.** Inset and transform
  move together or not at all.
- **13 are the `left:50%` + `translateX(-50%)` centring idiom**, which is not a
  direction at all. Confirm that reading declaration by declaration and mark
  them settled rather than converting them.

**Where it sits.** No screen. This is `resources/css/kbb/**` and the RTL
stylesheet, visible as Arabic pages rendering with the same geometry mirrored.

**Owns.** `resources/css/kbb/**`, `public/build/**` (rebuild with
`npx vite build` — `package.json` defines no `build` script, so the asset build
is manual and `BuiltCssIsCurrentTest` will catch you if you forget),
`docs/rtl-audit.md`, `docs/rtl-shots/`, `tests/Feature/*Rtl*`.

**Must not touch.** `resources/css/kbb/kbb-checkout.css` and the cart CSS —
those are live under the checkout/cart work and a conflict there costs a package.
Coordinate through the integrator if a `RTL-PHYSICAL:` marker sits in one.

**Done when.** The 57 are each either converted or re-marked with a reason that
survives review, the count is stated, and the proof is the one this lane already
established: 22 of 22 page pairs byte-identical in LTR, a control run of the
same tree against itself to establish the flake rate, computed geometry for
~10,000 nodes identical everywhere, and every drawer shown closed in RTL at
390px.

**Watch for.** An inline `style` attribute beats every stylesheet rule including
one inside a media query. If a panel's inset is set inline anywhere, converting
the stylesheet does nothing.

---

## What comes back to the owner, per lane

One PR body each, carrying: the picture at 390 and 1280, the measured numbers,
the admin path in words, the test that goes red without the fix and the mutation
that proves it asserts something, and one plain line on anything the lane found
and did **not** fix. That last line is the one the owner reads first.

---

# Round 2 — what landed, and what it left the owner

Six lanes merged into `claude/kind-mayer-rpqesv` with zero conflicts. Recorded
here rather than in `KBB-Master-Plan.md` because the plan is merged separately;
this is the integrator's working note.

| Lane | Landed | The admin path |
|---|---|---|
| **A** | Address decisions the owner can actually answer; article `<a href>` files migrated like `<img src>`; the two settings-held images that were still hot-linked to the old host | `Store → Import → Addresses & pictures → Old addresses` |
| **D** | The category archive's own 301 now names the canonical nested path; a redirect row may no longer point at itself, refused at all three writers | `Store → SEO & Meta → Redirects & 404s` |
| **E** | Brand, category and article SEO titles stopped deleting `%%title%%`; the three crawl files became publicly cacheable; a variable product's tile prints its price range instead of AED 0 | `Catalog → Brands → SEO`, `Store → Catalog → Categories → SEO`, `Content → Journal → (article) → SEO` |
| **F** | The RTL cart badge (an inline `style` attribute was beating the stylesheet in both directions); order lines snapshot the customer's language beside the operator's | `Orders → (an order) → Invoice` / `→ Packing slip` |
| **S** | `LocalBusiness` address and opening hours merged into the existing Organization node; an audit card for products whose photographs carry no alt text; concern-led collections | `Store → Business Details → Business`, `Store → SEO & Meta → SEO Audit` |
| **S²** | Research only — and it corrected three claims from its own round 1 | — |

## Two route changes, which are the integrator's and were made in one commit

1. The three crawl files moved into
   `Route::withoutMiddleware(SeoFilesController::STATELESS)`. Measured before:
   `Cache-Control: no-cache, private` and two `Set-Cookie` headers on each.
   Measured after: `public, max-age=3600, s-maxage=3600`, no cookie,
   `X-Content-Type-Options` still arriving. Deliberately not
   `withoutMiddleware(['web'])`, which would take `NoIndexStaging` with it.
2. `routes/concern-collections.php` mounted, with its `EnglishRenderWalk`
   entry in the same commit — that walk checks the route table in **both**
   directions, so a require with no entry and an entry with no require are
   equally red.

## What round 2 handed back that only the owner can answer

Ranked by what it blocks.

1. **A shopper can add a variable product to the basket for AED 0.** Found by
   Lane E, being fixed by Lane E in round 3. The half that is the owner's:
   fixing the tile **removes an Add-to-cart button that is on the shop today**,
   which rule 1 says must be called out rather than buried.
2. **Tag roughly 30–45 products** under `Catalog → Build my routine`, using the
   search terms in `docs/SEO-CONCERN-MAPPING.md` §3. Not 671, and not a
   spreadsheet — Lane S² withdrew that estimate after finding the taxonomy
   already exists. `/concern/{slug}/` 404s until a concern has copy and three
   live tagged products.
3. **A human Arabic writer**, for three intros of about 200 words
   (`docs/SEO-CONCERN-COPY.md`). Both SEO lanes refused to generate them.
4. **The postal address, opening hours and phone** for the `LocalBusiness`
   node. The boxes are built and empty; half an address is never published.
5. **Operator-authored HTML**: allowlist it, leave it, or clean only the two
   things an admin can actually write. Three options with costs in
   `docs/f2-operator-authored-html.md` §6–7. Measured cost of the middle one on
   today's data: eight HTML entities become the characters they already
   rendered as, and nothing else changes.
6. **Egress is still blocked.** Both SEO lanes re-tested it as their first act
   and got `CONNECT tunnel failed, response 403`. The 15-question competitor
   checklist stays a checklist until that opens.

## Found and not fixed, carried into round 3

- The product page headline renders **AED 0** server-side for a variable parent
  until pdp.js overwrites it (`store/product.blade.php:47`). Lane E, round 3.
- `Product::effectivePrice()` still answers 0 for those rows, so the price sort
  and the price facet file them cheapest-first. Reserved: the fix is a backfill
  or a change to `ProductImporter`.
- The delivery note goes in the parcel but renders in the operator's language.
  Moving it needs `OrderLocale::render()` in `Admin\InvoiceController` **and**
  `sheet-delivery-note.blade.php` pointed at `nameForCustomer`, both halves
  together.
- The site header overflows to `scrollWidth 1347` at 1280 on five pages —
  eleven seeded nav entries that do not fit. Pre-existing, measured by Lane S
  on four pages it did not touch.
- `srcset` is not parsed by the media rewrite. A comma-separated list of
  address plus descriptor is a different edit from replacing an attribute's
  whole value, and needs its own idempotency argument.

---

# Lane CP — Cart panel: Desktop / Mobile control sets, with a live preview

Added after the owner's mobile screenshot of the slide-out cart, with an arrow
at the quantity stepper and the note:

> "on mobile cart panel, i need to squeez the rows, spacing, font sizes,
> quantity button size, cross icon size, padding, and cart checkout buttons
> style etc. i need all those controls on backend on Appearance > Cart Panel >
> Desktop / Mobile, the same way you did for checkout page, along with live
> previews. don't touch the checkout page at all."

**The work.** `Appearance → Cart panel` already has sliders, and they are
grouped by CATEGORY — Size, Density, Content, Behaviour, Wording, Colour. The
owner wants them grouped by DEVICE, the way `Appearance → Checkout page` is,
with a phone preview beside the mobile controls, and he wants a set of values
that does not exist yet: today only four keys have a phone variant
(`panel_width_m`, `thumb_size_m`) and everything else — row padding, name size,
stepper size, list padding — is ONE number shared by a 380px desktop panel and
a 77vw phone panel. That shared number is the whole complaint: squeezing the
phone currently squeezes the desktop too.

**Where it sits.** `Appearance → Cart panel`, which becomes two tabs: **Desktop**
and **Mobile**. Content, Behaviour and Wording are device-independent and stay
as they are — put them behind a third tab or leave them below both; they are not
what was asked for and must not change value.

**Owns.**
- `app/Services/CartPanel.php`
- `app/Http/Controllers/Admin/CartPanelApiController.php`
- `resources/views/admin/partials/cart-panel-screen.blade.php` — **new file, see
  below**
- `resources/views/partials/drawers.blade.php` and
  `resources/views/partials/cart-drawer.blade.php`
- the cart-drawer rules in the storefront stylesheet
- `tests/Feature/CartPanel*Test.php` — new files

**Must not touch.**
- **`resources/views/admin/partials/checkout-page-screen.blade.php`,
  `app/Services/CheckoutPage.php`, `CheckoutPageApiController.php`, and
  `resources/views/store/checkout.blade.php`.** "don't touch the checkout page
  at all" is the owner's sentence and it is not a preference — the checkout
  screen is another lane's finished work and `StorefrontEnglishUnchangedTest`
  pins the page. Read the checkout screen as the PATTERN; copy from it; change
  nothing in it.
- `resources/views/admin/app.blade.php` — see the extraction note below.
- `app/Services/CartPage.php` and `cart-page-screen.blade.php`. The cart PAGE
  and the cart PANEL are two different screens and the owner named the panel.
- `routes/web.php`, `KBB-Master-Plan.md`, `KBB-Progress-Dashboard.html`.

---

## The five things that will go wrong if they are not read first

### 1. An inline `style` attribute beats a media query — this is the landmine

`drawers.blade.php:13` renders the panel as

```blade
<aside class="drawer {{ $cp->bodyClass() }}" id="cart" style="{{ $cp->cssVariables() }}">
```

so every custom property arrives in a **style attribute**, which outranks every
media query in the stylesheet. A mobile tab that writes `--cp-rowpad` expecting
`@media (max-width:640px)` to override it **will save and move nothing**, and it
will look like a broken save rather than a specificity problem.

`CartPanel::cssVariables()` already does this correctly for the two keys that
have phone variants: it emits **both** `--cp-w` and `--cp-w-m`, and the
STYLESHEET picks between them inside its media query. Every new mobile value
must follow that shape — emit `--cp-<name>` and `--cp-<name>-m` side by side,
and choose in the stylesheet. `checkout-page-screen.blade.php` line 26 onward
carries the same argument in its own words; read it.

### 2. The screen has to be extracted from `app.blade.php` first

`renderCartPanel()` currently lives **inside** `resources/views/admin/app.blade.php`
(`renderCartPanel()` at app.blade.php:6022), and its preview CSS is in that
file too (the `cpp-` block from app.blade.php:1079). CLAUDE.md forbids a lane editing `app.blade.php`, because
three lanes edit it at once — and the checkout screen was moved into its own
partial for exactly this reason.

So the first commit of this lane is a **pure move**: `renderCartPanel` and its
`cpp-` styles out of `app.blade.php` and into
`resources/views/admin/partials/cart-panel-screen.blade.php`, appending its own
sidebar entry via `window.kbbAddNavEntry` and wrapping `window.go` the way
`checkout-page-screen.blade.php` does at lines 408 and 418.
Prove it is a pure move — the screen renders identically before and after, no
setting changes — and ship that commit on its own so the diff is reviewable.

**The integrator does the `@include`**, the same as every other partial. Do not
edit `app.blade.php` to add it.

### 3. Pin the FINISHED state, never the absence

CLAUDE.md has this three times over. Do **not** write
`expect($app)->not->toContain('cart-panel-screen')` to prove you did not wire
yourself up: it is green in your worktree and goes red the moment the integrator
does the one thing you asked for. Assert
`substr_count($app, "@include('admin.partials.cart-panel-screen')") === 1`
instead — zero is "built, never wired", two registers the sidebar entry twice
and wraps `window.go` around its own wrapper, and both are real failures.

### 4. Every new setting ships at the value the panel already has

Rule 1. A shop that applies this package and opens nothing must render the cart
panel **byte for byte** as it does today. So each new mobile key's default is
the CURRENT shared value, not a nicer number:

| new key | default | because |
|---|---|---|
| `row_pad_m` | `9` | today's `row_pad` |
| `name_size_m` | `13` | today's `name_size` |
| `stepper_size_m` | `22` | today's `stepper_size` |
| `list_pad_m` | `16` | today's `list_pad` |

The owner will then squeeze the phone himself. If you believe a default should
move, say so in the PR body and leave it — do not bury it.

### 5. The controls he actually named, and the two that do not exist yet

From the message, mapped to keys. The first four exist and need a `_m` twin;
the last three are new on both devices:

- rows / spacing → `row_pad` (kbb.css:371, `.kc-item` already reads
  `var(--cp-rowpad,9px)`)
- font sizes → `name_size`, and add a **price size** (`price_size`) — the
  `AED309` in his screenshot is a hard-coded size today
- quantity button size → `stepper_size` (kbb.css:376–377, `.kc-qty button`
  already reads `var(--cp-step,22px)`; note line 377 sizes the NUMBER between
  the − and + off the same variable, so a change moves three boxes)
- padding → `list_pad`
- **cross icon size** → new, and there are **two** crosses, both hard-coded in
  `resources/css/kbb/kbb.css`:
  - `.kc-rm` — the ✕ on each product line (`cart-drawer.blade.php:101`).
    `font-size:13px` at kbb.css:380, and **44×44 with font-size 15px** inside
    `@media (max-width:900px)` at kbb.css:3354.
  - `.kc-x` — the panel's own close button (`cart-drawer.blade.php:60`).
    `28×28` at kbb.css:388, **44×44** on mobile at kbb.css:3325.
- **cart / checkout button style** → new. `.cobtn` at kbb.css:610 carries
  `border-radius:99px; padding:14px; font-size:14px`, `.btn-ghost` at
  kbb.css:417, and both get `min-height:44px` on mobile at kbb.css:3357.
  `--cp-cta-bg` / `--cp-cta-fg` already drive their COLOUR (kbb.css:709);
  height, radius and label size do not exist yet.

### The 44px rule, and how to handle the owner asking to break it

Those three `44px` mobile values are not arbitrary and they are not spacing —
they are **touch targets**. 44px is the minimum a finger hits reliably, and the
`@media (max-width:900px)` block exists solely to raise `.kc-rm`, `.kc-x` and
the two footer buttons up to it on a phone.

The owner has asked to squeeze exactly these. So:

- **The mobile default stays 44** for all four, because that is what the shop
  renders today and rule 1 is not negotiable.
- **The slider may go below it**, because he asked and it is his shop.
- **Say so at the point of the decision**: below 44 the control's help text
  turns warm and reads that taps get less reliable on a phone. One sentence
  under the slider, not a blocking dialog and not a refusal.

Do not silently clamp at 44 — a slider that stops where the owner did not ask
it to stop reads as a bug, and he will report it as one.

Anything else you find hard-coded in the panel: add it, default it to what is
there now, and list it in the PR body. Do not guess at what he meant beyond
this list — name it and ask.

---

## The preview

Follow `checkout-page-screen.blade.php`, which settled this after two rounds of
the owner pushing back:

- **Mobile tab → preview on the RIGHT of the controls**, sticky, folding to one
  column below 1180px. His words on the checkout screen were "in all mobile
  tabs ... i want the preview on the right side, only in the mobile tabs."
- **Desktop tab → preview BELOW the controls, full width**, headed "Preview".
  A desktop panel drawn in a 372px rail shows nothing worth judging.
- It is a **drawing**, not an iframe of the real panel: the real one needs a
  basket to render and would cost an authenticated fetch per keystroke. The
  `cpp-` mock already in `app.blade.php` is the starting point — it already
  reads `--pad` and `--rowpad`.
- It must move **on input, not on save**. That is what "live" means here.

---

## Rules 4 and 5 for this lane specifically

- **No JavaScript that measures layout.** Two tests forbid the element-measuring
  APIs by name. The panel sizes with `calc()` and custom properties; keep it
  that way.
- **A colour from a setting is a hex or it is the default.** `POLICY`'s
  `hex => repair` already covers the three existing colour keys; any new one
  goes through the same path. Never interpolate a raw setting into a `style`
  attribute without it.
- **A select stores one of its own options.** If you add a button-style select
  (pill / square / full-width), the saved value is one of the literal options or
  the default — never the string that arrived.
- **`/api/*` is unauthenticated.** Nothing here should reach it, but if you
  return panel settings anywhere public, allowlist the keys; `cartpanel_*` is
  read through `SettingsService` and `SettingController::PUBLIC_KEYS` governs
  what a shopper may see.

## Done when

1. `Appearance → Cart panel` has **Desktop** and **Mobile** tabs, each with its
   own stored values, and the device-independent tabs are unchanged.
2. Every control the owner named exists on both tabs, including the cross icon
   and the two footer buttons.
3. Both previews move on input, and the mobile preview sits beside its controls.
4. `php artisan tinker` on a fresh database shows every `cartpanel_*` setting
   absent, and the storefront panel renders identically to today —
   `StorefrontEnglishUnchangedTest` does not move.
5. A test asserts the screen partial is included **exactly once**.
6. A test drives `cssVariables()` and proves the mobile value is emitted as its
   OWN property rather than overwriting the desktop one — the landmine in §1,
   with a mutation note showing it red.
7. Screenshots at **390px and 1280px** of the admin screen AND of the storefront
   panel, before and after a squeeze, with the measured numbers: row height,
   stepper box, cross box, button height, and
   `document.documentElement.scrollWidth`.
8. The PR body names the exact admin path and lists every new key with its
   default and the hard-coded value it replaced.

---

# Lane PX — the WooCommerce product round-trip, field by field

The owner uploaded his live WooCommerce product edit page (`Medicube – Kojic
Acid Glow Full Routine Set`) and said:

> "check everything on edit page and match with ours. because we will export the
> products from wordpress site and import into ours. also check and update our
> import/export module if needed. don't assume anything. everything must be
> compatible without anything skipping or losing. Also check everything which is
> related to products, i want super strong, smooth and bugs free functionality.
> with live progress / counts etc."

**This is a migration correctness lane, not a features lane.** The shop is being
moved. A field that silently does not arrive is a field nobody discovers until a
customer asks why a parcel's weight is wrong.

**Where it sits.** `Store → Store Import / Export`, and the product screens
behind `Catalog → Product editor`.

---

## What the audit already found — start here, do not redo it

I diffed the exporter's product row against what `ProductImporter` reads. Facts,
each verified in the code, not inferred:

**The exporter emits 40 fields** per product
(`wordpress-plugin/kbb-exporter/includes/stages/class-kbb-export-stage-products.php:159-213`).
**`ProductImporter` reads 24 of them.**

### Correctly handled — do NOT "fix" these

Check them, then leave them alone. Each already works and a change is a
regression risk:

| Concern | How it is already handled |
|---|---|
| **Multiple categories** | `category_term_ids` is a comma list; `ProductImporter:212-232` resolves each, writes the full set to the `category_product` pivot and the first to `products.category_id` |
| **Primary category** | The exporter sorts on `_yoast_wpseo_primary_product_cat` so the primary lands first, which is what `category_id` then takes. The Yoast key is in `YoastSeo::UNMAPPED` because it is consumed on the **product** row, not the SEO row — this looks like a gap and is not one |
| **Tags** | `TagImporter:221-243` writes `product_tag` membership from the **tag** side. `tag_term_ids` on the product row is therefore redundant, not lost |
| **Attributes** | `AttributeImporter:505-533` writes `product_attribute_value` from the **attribute** side |
| **Variations** | `VariationImporter` — its own entity and file |
| **Yoast SEO** | The SEO stage exports **every** `_yoast_wpseo_*` and `wpseo_*` key by `SELECT DISTINCT meta_key`, not a fixed list. `YoastSeo::MAPPED` imports five (title, metadesc, canonical, og-image, noindex) and `UNMAPPED` names nineteen it deliberately skips **and reports as skipped** |

### ▲ Exported, and then genuinely dropped — this is the lane's work

These have **no column in `products`** and **no sibling importer**. They are
read out of WooCommerce, written into the CSV, and thrown away on arrival:

| Field | What it is on his page |
|---|---|
| `weight` | Product data → Shipping |
| `length`, `width`, `height` | Product data → Shipping, dimensions |
| `shipping_class` | Product data → Shipping |
| `tax_status`, `tax_class` | Product data → General |
| `virtual`, `downloadable` | The two checkboxes beside "Simple product" |
| `backorders`, `low_stock_amount` | Product data → Inventory |
| `upsell_ids`, `cross_sell_ids` | Product data → Linked Products |
| `grouped_ids` | Linked Products, for grouped products |
| `purchase_note` | Product data → Advanced |
| `product_visibility` | The richer four-state value; only the derived `is_visible` yes/no survives |
| `date_modified` | Overwritten by Eloquent's `updated_at` on insert |

Confirm each against `Schema::getColumnListing('products')` before you start —
the current list is:
`brand_id category_id created_at custom_tabs deleted_at description featured
gtin how_to_use id image image_alts images ingredients is_visible manage_stock
meta_feed name position price published_at rating review_count routine_concerns
routine_role sale_ends_at sale_price sale_starts_at seo seo_json short_description
sku slug status stock stock_status total_sales type updated_at wc_id`.

**The decision this lane must put to the owner, not make alone:** which of those
he actually needs. Three groups, and they are not equal:

1. **Shipping (`weight`, dimensions, `shipping_class`)** — he ships real
   parcels across the UAE. Losing weight is losing the input to any weight-based
   rate. Almost certainly must be carried.
2. **Tax (`tax_status`, `tax_class`)** — checked, and the answer is *half*. A
   `tax_rates` table exists with `country, state, rate, priority, is_inclusive,
   applies_to_shipping` — WooCommerce's own shape, **and it currently holds zero
   rows**. What does not exist is any per-PRODUCT tax class, which is what
   `tax_class` selects into. So carrying `tax_class` is only worth anything
   once the rate table is populated and something reads it. Ask the owner
   whether UAE VAT is one flat rate on everything — if it is, per-product tax
   class is data he will never use, and saying so is better than adding two
   columns nobody reads.
3. **Linked products (`upsell_ids`, `cross_sell_ids`, `grouped_ids`)** — these
   are *product-to-product* references and need a pivot plus a **second pass**,
   because a product can point at one imported after it. Do not try to resolve
   them inline; `ImportContext::localId()` will legitimately answer null on the
   first pass.

Bring the three groups to the owner with a one-line cost each. Do not add
fourteen columns because they exist.

---

## The rules this lane is most likely to break

### 1. A dropped field must be REPORTED, never silent

This is the heart of "without anything skipping or losing". `ImportReport`
already has `->for($entity)->note(...)`, and `ProductImporter:216` already uses
it for a category that is not in the import. **Every field the importer
knowingly does not carry gets the same treatment**, the way `YoastSeo::UNMAPPED`
does it for SEO: seen, named, skipped, counted. A field that is dropped in
silence is indistinguishable from a field that was never there.

That alone — before a single new column — turns this from "we hope nothing was
lost" into a list the owner can read.

### 2. Re-running an import must not duplicate anything

`wc_id` is the identity. Every write is an upsert on it, and the pivots
(`category_product`, `product_tag`, `product_attribute_value`) are
diffed — read the existing set, insert the additions, delete the removals — not
blindly re-inserted. `ProductImporter:345-362` is the pattern; follow it exactly
for anything new. A test must import the **same file twice** and assert the row
count and every pivot count are identical after the second run.

### 3. `ImportAtVolumeTest` and the savepoint trap

There is a documented landmine in CLAUDE.md: an intermittent
`no such savepoint: trans3` in the resume test is **a full disk**, not a
transaction bug — SQLite aborts the transaction when it cannot write, which
destroys every savepoint inside it. The tell is that the row it blames moves
between runs. **Check `df -h /` before debugging any intermittent database error
in this lane**, and remove finished worktrees.

### 4. Money is integer fils, and a price is not a float

`regular_price` arrives as WooCommerce's decimal string. It is stored as an
integer. Do not introduce a float anywhere on the path; the existing
`$row->money()` is the only converter.

### 5. HTML from WordPress is not trusted

`ProductImporter:265-266` runs `description` and `short_description` through
`cleanHtmlReported()`, which **reports** what it changed. His description is
full WYSIWYG HTML with lists and bold. Any new text field (`purchase_note`) goes
through the same path, and rule 5 applies: nothing from an import is ever
printed unescaped.

### 6. `/api/*` is unauthenticated

`products` already carries `wc_id`, `sku` and `total_sales`, and
`Product::toApi()` is the allowlist that keeps them off the public endpoint.
**Every column this lane adds must be considered for that allowlist before it
is added** — `tax_class` and supplier-ish fields are not shopper business.
`tests/Feature/ApiSecurityTest.php` pins it and exists because each case leaked
in production.

---

## Live progress and counts

Largely built — verify and extend, do not rewrite:

- `ImportBackgroundController::progress()` → `ImportChain::progress()`
- `ImportDriver::denominators()` already reasons about what the denominator of a
  progress bar should be
- `ImportLedger` keeps per-file `rows_counted` and merges counters rather than
  summing them blindly (`ImportLedger:162`)
- `ImportLedger:366` returns `counts => [imported, changed, new, files]`

What to check, with the owner's words in mind ("live progress / counts"):

1. Does the progress page show **per-entity** counts — products, variations,
   categories, tags, attributes, reviews — or only a total? Per-entity is what
   makes "nothing was lost" checkable.
2. Does a **skipped field** counter reach that page? After rule 1 above it
   should.
3. Does the bar move during the products stage on a real-sized catalogue, or
   jump at the end? Drive it with a seeded export, not a 3-row fixture.
4. Is there a **final reconciliation**: "WooCommerce said 671 products, 671
   arrived, 0 refused, 14 fields skipped"? If not, build it. That single
   sentence is what the owner actually wants and it is the cheapest possible
   proof of the whole migration.

---

## Done when

1. A written field-by-field table — **every** column of the exporter's product
   row against what arrives — committed as `docs/PRODUCT-FIELD-PARITY.md`, with
   each field marked carried / deliberately skipped / newly carried by this lane.
2. Every deliberately-skipped field is **reported by the importer at run time**,
   not only in that document.
3. The owner has been asked, in the PR body, about the three groups above, with
   a cost per group.
4. Whatever he approves is carried, with a migration, the `Product::toApi()`
   allowlist reviewed, and `ApiSecurityTest` extended.
5. An import of the same file **twice** leaves identical row and pivot counts —
   with a test.
6. The progress page shows per-entity counts and ends with a reconciliation
   sentence.
7. Screenshots at 390px and 1280px of the progress page mid-run and at the end.
8. `StorefrontEnglishUnchangedTest` and `StorefrontQueryBudgetTest` unmoved; any
   new column defaults to what the page renders today.

## Owns

`app/Services/Import/**`, `app/Services/ImportConsole/**`,
`app/Http/Controllers/Admin/Import*.php`, `wordpress-plugin/kbb-exporter/**`,
new migrations, `docs/PRODUCT-FIELD-PARITY.md`, `tests/Feature/Import*Test.php`.

## Must not touch

`routes/web.php`, `resources/views/admin/app.blade.php`, `KBB-Master-Plan.md`,
`KBB-Progress-Dashboard.html`, and anything owned by Lane CP
(`CartPanel`, the cart-panel screen) or the checkout page.

**`wordpress-plugin/` never ships in a package** — `UpdateGuard` refuses it and
`BuildPackage::NEVER_SHIP` blocks it twice over. Exporter changes reach the old
site by installing the plugin there, not through Core Updates. Say so in the PR
body so the owner knows there are two deliverables, not one.

---

# Lane IG — the Instagram preview, and Configure in a popup

> "i want the instagram section preview. and also i need auto connector by
> pressing configure button, the system should open popup, request instagram,
> fetch the api etc after login, and save the information. must be super smooth,
> secure and reliable and light weight"

**Most of this is already built. Read before you write.** `app/Services/
Instagram/` already contains `InstagramAuth`, `InstagramClient`,
`InstagramCredentials`, `InstagramSync` and `IgPath`; `InstagramController`
serves the screen; `InstagramSettings` and `InstagramFeed` are the storefront
half. The OAuth handshake, the token exchange and the post fetch all exist and
work. **Do not rewrite any of it.** There are exactly two gaps.

**Where it sits.** `Content → Instagram`.

---

## Gap 1 — there is no preview at all

`grep -c preview resources/views/admin/partials/instagram-screen.blade.php`
answers **0**. Every other Appearance screen in this console previews what it
controls; this one asks the owner to save and go look at the shop.

Build the same kind of drawing the other screens use — a mock of the Instagram
rail as the storefront renders it, at the settings currently on screen, moving
on input rather than on save. `resources/views/admin/partials/
checkout-page-screen.blade.php` is the pattern for a preview beside/below
controls, and `ugc-library-screen.blade.php`'s loop panel is the pattern for
previewing a media rail specifically.

It is a **drawing, not an iframe**: rendering the real storefront here would
cost an authenticated fetch per keystroke. Where there are real stored posts,
draw those (the screen already knows `content.posts`); where there are none,
draw placeholders and say so, rather than an empty box.

## Gap 2 — Configure is a full-page redirect, not a popup

`instagram-screen.blade.php:376` is an `<a href=".../instagram/start">`, and its
docblock defends that choice:

> A REAL LINK AND NOT A FETCH, because an OAuth handshake is a top-level
> navigation to a third party and an XHR cannot log anybody in to one.

**That reasoning is correct and it is not an argument against a popup.** A popup
window IS a top-level navigation — in its own window — which is the standard
OAuth pattern precisely because it keeps the opener's page alive. What the
docblock rules out is an XHR, and a popup is not one.

So: open `/instagram/start` with `window.open`, let the callback page post a
message back to the opener and close itself, and have the screen refresh its
connection state in place. **Keep the anchor as the fallback** — its second
sentence is also right, an owner whose popup is blocked must still be able to
finish by clicking, and a `window.open` that returns null is exactly that case.
Update the docblock rather than deleting it; the reasoning in it is sound and
the next reader needs to know why the anchor survives.

### The security rules this gap lives under

- **`postMessage` must check `event.origin`** against this shop's own origin and
  ignore anything else. A callback page that accepts a message from any origin
  is a way for another tab to tell your admin it is connected.
- **Nothing sensitive crosses in the message.** The token is exchanged and
  stored server-side by `InstagramAuth`; the popup should post back *"done"* and
  nothing more, and the screen should then ASK the server what the state is.
- **The `state` parameter must be verified** on the callback — check whether
  `InstagramAuth` already does this before assuming. If it does not, that is a
  CSRF hole in the existing code and fixing it is part of this lane.
- **The app secret never reaches the browser.** Check what the screen's payload
  currently returns; `InstagramCredentials` is the only thing that should hold
  it.

---

## Rules

- **"Light weight"** was asked for explicitly: no new front-end dependency, no
  polling loop left running after the popup closes, and the preview draws from
  data the screen already has rather than a new endpoint if one can be avoided.
- Every new setting ships at the value the page renders today (rule 1).
- The preview must not use any element-measuring API — two tests forbid them by
  name. Size with `calc()` and CSS.
- 390px and 1280px both work, no horizontal overflow.

## Owns

`app/Services/Instagram/**`, `app/Services/InstagramFeed.php`,
`InstagramSettings.php`, `app/Http/Controllers/Admin/InstagramController.php`,
`resources/views/admin/partials/instagram-screen.blade.php`, the Instagram
storefront partials, `tests/Feature/Instagram*Test.php`.

## Must not touch

`routes/web.php`, `resources/views/admin/app.blade.php`, `KBB-Master-Plan.md`,
`KBB-Progress-Dashboard.html`, anything owned by Lane CP (`CartPanel`, the
cart-panel screen), Lane PX (`app/Services/Import/**`, the exporter), or the
checkout page.

## Done when

1. `Content → Instagram` previews the rail, and the preview moves on input.
2. Configure opens a popup, the connection completes without leaving the screen,
   and the screen shows the new state without a manual reload.
3. A blocked popup still connects via the anchor, and that path is tested.
4. `event.origin` is checked, `state` is verified, and the secret is proven not
   to reach the browser — each with a test.
5. Screenshots at 390px and 1280px of the screen before and after connecting.
6. Mutation notes for every new test.

---

# Round 4 — three lanes, every claim below verified first-hand

**Read this before taking any of these.** The master plan's remaining `[ ]`
items were checked before this round was written and three of the obvious
candidates are NOT available:

- **Ed25519 signing** (plan line 2662) reads like the next thing to do and the
  lines directly above it say `⏸ ON HOLD AT THE OWNER'S INSTRUCTION, 24
  September 2026 … step 6 is not to be taken. The lane is freed.` Do not take
  it. Only the owner can unblock it.
- **"Multiple products per video, with the tagging screen"** (line 3507) is
  already built: `UgcVideo::products()` is a `belongsToMany` over
  `ugc_video_product` with `position` and `at_ms`, and step 4 of the clip
  editor is the tagging screen.
- **"Live editing of homepage sections"** (line 2717) —
  `resources/views/admin/partials/homepage-content-screen.blade.php` exists and
  `hpcontent` is in `LATE_RENDERED`.

A stale plan entry is not a task. If an item you are given turns out to be
built, say so and stop rather than building a second copy — that is a mistake
this repo has made and documented more than once.

---

## Lane MB — the Media Library after the WooCommerce import

**The work.** The owner is about to import his live catalogue. When he does,
**none of the images that arrive will ever appear in his Media Library**, and
no amount of pressing Rescan will change it.

Verified, not inferred:

- The importer writes fetched media to `public_path($path)` where `$path`
  contains `wp-content/uploads` — so files land under
  `public/wp-content/uploads/…`.
- `MediaBackfill::…` walks exactly one root: `$root = public_path('uploads')`.
  Its own comment at the rewrite step distinguishes "an `uploads/` prefix as
  this app's own web root" from "an imported `/wp-content/` path", so the two
  shapes are already known to be different — nothing walks the second.
- `MediaRegistrar` is called from `UgcMedia`, `UgcDerivedFiles`,
  `UgcTranscoder`, `MediaUploadController`, `MediaLibraryApiController` and
  `UgcSectionController`. It is **not** called from the review-photo path or
  from `InstagramSync`, so those register only if a walk finds them.
- Instagram images land in `public/uploads/instagram`, which **is** under the
  walk — so those are reachable by Rescan today, just not on write.

**So there are two separate defects and they need different fixes.** Do not
conflate them:

1. **Imported media is outside the walk entirely.** Decide with evidence
   whether the right answer is to widen the walk, to register on fetch, or to
   move where the importer writes. Each has a cost; state it. Widening the walk
   is the smallest change and the one most likely to be right, but
   `MAX_FILES = 20000` exists for a reason and his catalogue is large — measure
   before choosing.
2. **Review photos and Instagram images register only at Rescan.** These
   already sit under the walk, so this is a freshness problem, not an absence.
   Registering on write is the fix; the pattern is already in `UgcMedia`.

**Done when:** an import of the fixture export leaves every fetched image in
the `media` table with the right `mime`, `bytes` and dimensions; a review photo
and an Instagram image appear without anybody pressing Rescan; and there is a
test that fails without each half. Report the measured cost of the walk on a
realistic file count.

**Owns.** `app/Support/MediaBackfill.php`, `app/Support/MediaRegistrar.php`, the
review-photo write path, `app/Services/Instagram/InstagramSync.php`, and the
importer's media fetch. **Must not touch** `routes/web.php`,
`resources/views/admin/app.blade.php`, the plan, the dashboard, the checkout
page, `CartPanel`, or the Instagram ADMIN SCREEN (another round's work; the sync
service is yours, the screen is not).

---

## Lane MY — MySQL is what the shop runs, and eight tests fail on it

**The work.** The default lane is SQLite. **Production is MySQL 8.0.** Lane PX2
reported eight failures under `-c phpunit-mysql.xml` and established each as
pre-existing by re-running it on an unmodified base:

```
CartLineEagerLoadTest (×2) · CartRecommendedRailSlopeTest · ColumnWidthGuardTest
ContentPageEditorTest · SeoBackOfficePayloadTest · MediaLibraryTest (order-dependent)
```

**Verify that list yourself before working from it** — it is another lane's
report, and this round was written because a report was wrong once already.
`ColumnWidthGuardTest` was specifically flagged as *looking* like an import
problem and not being one (its diff names `instagram_posts` and `locale_slugs`).

Two engine-divergence findings to check while you are in there, both named by
PX2 and neither fixed:

- **`ReviewImporter:511` uses `whereRaw('LOWER(email) = ?')`.** SQLite's
  `LOWER()` is ASCII-only; MySQL's under `utf8mb4` is not. A non-ASCII email
  links on one engine and not the other.
- **A full suite run rewrites `docs/SEO-PREVIEWS.html`**, a tracked file — a
  preview generator re-emitting JSON with different key ordering. The next lane
  will commit it by accident. (I fixed a version of this before by pinning
  fixture dates and removing a timestamp; this is a different cause.)

**Done when:** the MySQL suite is green, each fix ships with the test that goes
red without it, and anything you decline to fix is named with why. If a failure
is genuinely another lane's file, say so and leave it — do not edit across.

**Watch the disk.** CLAUDE.md records that a full disk here produces
`no such savepoint: trans3`, which reads exactly like a transaction bug.
`df -h /` before debugging any intermittent database error.

---

## Lane OD — two money defects the owner has been asked about twice

**The work.** Both are real, both cost money, and both have been sitting in the
handover list unanswered. Build the fix and the test; the DECISION stays the
owner's and goes in your report, phrased so he can answer it in one line.

1. **`PaymentRefunder::capturedFils()` counts a released authorisation as
   refundable.** Read it: the ceiling is built from `captured_total`,
   `captured_at`, `paid_at` and a closure summing `paid` payment rows. An
   authorisation that was RELEASED (voided) rather than captured is money that
   never left the shopper — refunding against it is refunding money the shop
   never took. The fix touches two customer-facing emails, which is why it has
   not been made casually.

2. **Tamara auto-capture.** If an order ships and is never captured, the
   merchant is never paid; Tamara voids the authorisation after roughly 180
   days. `forceCaptureTamaraOrder` is the relevant path. The question for the
   owner is whether capture should follow fulfilment automatically, and that is
   a commercial decision, not a technical one — but the code that would do it,
   and the test proving it cannot double-capture, are yours.

**Money crosses payment interfaces as integer fils.** No floats anywhere on
this path. Every new endpoint gets its own capability and fails closed.
`/api/*` is unauthenticated and payment rows carry provider references — check
`ApiSecurityTest` before returning anything new.

**Done when:** each defect has a test that goes red without the fix and a
mutation note; the two questions are in the report in one line each; and no
customer-facing email changes wording without it being called out.

---

## Lane TC — Tamara capture, and three small things the media round surfaced

**1. Tamara auto-capture — the money item that is still open.**

If an order ships and is never captured, the merchant is **never paid**; Tamara
voids the authorisation after roughly 180 days. `forceCaptureTamaraOrder` is the
relevant path.

Whether capture should follow fulfilment automatically is a **commercial
decision and stays the owner's** — put it to him in one line in your report.
What is yours is the code that would do it and the test proving it cannot
double-capture. Build it behind whatever switch makes the default "no change",
so applying the package moves nothing until he chooses.

Its sibling was fixed by the integrator this round and is worth reading first:
`PaymentRefunder::capturedFils()` was offering a released authorisation as
refundable money. Two call sites, and the second one's query named its columns
explicitly — a column left out of an explicit `select()` comes back NULL rather
than raising, so the rule would have been silently inert there. Expect that
shape again.

**Money crosses payment interfaces as integer fils. No floats on this path.**

**2. `image/avif` is in `SAFE_TYPES` but not `EXT_MIME`.** An imported AVIF is
fetched, served, and invisible to the Media Library. The one-line fix is
obvious and it is **not yours to take**: `EXT_MIME` is also what the shared
media picker offers for every image field in the console, so adding a type
widens what a brand logo may be on every screen at once. **Put it to the owner**
with that cost stated. A test already pins the two lists so they cannot drift
further unnoticed.

**3. The Media Library screen's blurb is now incomplete.** It reads *"Every file
uploaded through the admin…"*; imported, review and Instagram files land there
too now. `resources/views/admin/partials/media-library-screen.blade.php`.

**4. Verify the AVIF and blurb claims yourself** — they come from another lane's
report, and this round exists partly because a report was wrong once already:
one told the integrator to delete a function while leaving a live reference to
it, which would have thrown `ReferenceError` and blacked out the whole console.

## Owns

`app/Services/Payments/**` (except `PaymentRefunder::ceilingFrom`, just
changed — read it, do not re-litigate it), the Tamara gateway,
`media-library-screen.blade.php`, `tests/Feature/*Tamara*`, `*Capture*`.

## Must not touch

`routes/web.php`, `resources/views/admin/app.blade.php`, `KBB-Master-Plan.md`,
`KBB-Progress-Dashboard.html`, `app/Support/MediaBackfill.php`,
`app/Support/MediaRegistrar.php`, `app/Services/Instagram/**`, the checkout
page, `CartPanel`, or anything Lane MY is in (the seven MySQL-only failures).

## Done when

Each change has the test that goes red without it and a mutation note; the two
owner questions are in your report in one line each; no customer-facing email
changes wording without it being called out; and `df -h /` was checked before
debugging any intermittent database error.

---

# Round 5 — three lanes, briefed 28 September 2026

Every fact below was checked in the tree at `dee937c` before it was written.
Where a line of `KBB-Master-Plan.md` is quoted and the code says otherwise, the
brief says so — **do not take the plan's word for anything; it has gone stale
in at least one place this round already.** If you find a brief is wrong, say
so in your report and fix the real thing, not the thing the brief describes.

---

## Lane PC — a partial capture inflates the refund ceiling

**The work.** `PaymentCapturer::capture()` writes

```php
'captured_total' => $amountFils,      // PaymentCapturer.php:235
```

where `$amountFils = (int) $order->total` (line 168). It writes that figure on
**every** success, including the successes where the gateway did not capture
that amount — because `SettlementResult` has no field in which a gateway could
say what was actually taken. Read `app/Services/Payments/SettlementResult.php`:
`ok(string $code, ?string $reference, array $summary, ?string $message)` and
nothing else.

Three of the four gateways return an `already_captured` ok() on a state the
provider reached without us, and each can be a **partial**:

- `TamaraGateway::capture()` (line 1002) treats `partially_captured` as
  `already_captured` — and its own docblock at line 960 says so in as many
  words: *"a partial capture on the account came from elsewhere and silently
  topping it up would be the wrong guess to make with money."* It is right not
  to top it up. It is wrong that `PaymentCapturer` then records the **whole
  order total** as captured.
- `TabbyGateway::capture()` (line 817) returns `already_captured` on `CLOSED`
  with a non-empty `captures[]`. It reads the last capture's **id** and never
  its amount.
- `StripeGateway::capture()` (line 987) returns `already_captured` on a
  `succeeded` intent without reading `amount_received`, which is what a
  partially-captured intent reports.
- `CashOnDelivery::capture()` (line 144) has no provider and no figure. It must
  keep behaving exactly as it does now.

**Why it costs money.** `captured_total` is the ceiling
`PaymentRefunder::capturedFils()` measures a refund against — the same ceiling
whose two other defects are already written up in `KBB-Master-Plan.md` lines
1387 and 2191. An order captured at 120.00 out of 300.00 records 300.00 and
accepts a 300.00 refund: **180.00 of the shop's own money returned to a buyer
who never paid it.** That is the same shape as the Tabby void defect the
gateway's own comment at line 815 describes, one step further along.

**Shape of the fix, and the part that is yours to decide.** `SettlementResult`
wants an optional captured amount — a nullable integer in fils, `null` meaning
"the provider named no figure, use what was requested". Four gateways implement
the interface; three can fill it from the provider's own response and one
cannot. `PaymentCapturer` writes the gateway's figure when there is one and the
requested amount otherwise, so **COD and every ordinary full capture keep
writing exactly what they write today** — prove that, do not assert it.

Do not invent the provider field names. Read the code that already parses each
response (`TabbyGateway::lastId()` and `hasCapture()`, `TamaraGateway::
existingCaptureId()`, `StripeGateway::stripeAttempt()`) and take the figure from
the same body those read. If a provider's response genuinely does not carry an
amount on the path in question, return `null` and say so in your report rather
than guessing a key.

**Integer fils everywhere. No floats on this path, at any point, including in a
test fixture.** `Money::toAed()` is for display and nothing else.

**Where it sits.** `Store → Orders → <an order> → Payment`, the captured figure
and the refundable ceiling beneath it. Screenshot the panel on an order whose
provider reported a partial, at 390 and 1280.

**Owns.** `app/Services/Payments/**`, `tests/Feature/*Capture*`,
`tests/Feature/*Refund*`, `tests/Feature/*Tabby*`, `*Tamara*`, `*Stripe*`.

**Must not touch.** `PaymentRefunder::ceilingFrom()` — changed by the integrator
in 2.60.301 for the released-authorisation defect. Read it, build on it, do not
re-litigate it. Not `routes/web.php`, not `KBB-Master-Plan.md`.

**Done when.** A test fails without the fix for each of the three providers that
can report a partial; a fourth test proves COD and a plain full capture write
the same `captured_total` they write today; every test carries the mutation note
(*"change X back and this is red"*); and the report names, per provider, the
exact response key the figure came from.

---

## Lane MC — four settings modules the framework guard cannot see

**Read this first: the master-plan line for this item is STALE.**
`KBB-Master-Plan.md:430` says *"Eight modules still carry hand-written
`cast()`s — CartPage, CheckoutPage, SecurityModule, HomepageContent,
MailSettings, ReviewSettings, CacheSettings, ReviewBadgeSettings."* Checked at
`dee937c`:

- `cart_page`, `checkout_page`, `security` and `homepage_content` are **already
  enrolled** in `ModuleFrameworkGuardTest::MODULES` (lines 530, 531, 532, 541
  of that file) with their `SCHEMA` and `TABS`.
- Only three of the eight still define a `cast()` at all: `CartPage.php:889`,
  `CheckoutPage.php:1181`, `SecurityModule.php:482` — and all three are inside
  the guard, which is the point of the guard.
- The four that are genuinely outside it are **`MailSettings`,
  `ReviewSettings`, `CacheSettings`, `ReviewBadgeSettings`**. Every one has a
  `SCHEMA`; **none has a `TABS`**, and `TABS` is what enrolment needs.

So the work is not eight `cast()`s. Verify all of that yourself before you
write a line — if my reading is wrong, your report says so and the plan gets
corrected.

**The work.** Give those four modules a `TABS` constant lifted out of whatever
draws their screen today, and enrol them in `ModuleFrameworkGuardTest`. The
precedent is in the file itself, at the comment above line 452: `MobileMenu`
was excluded for having no `TABS`, its groups came out of its controller into
`MobileMenu::TABS`, and it was enrolled. Follow that, not a new pattern.

**The value, and what you should expect to find.** The plan records that this
guard *"caught a module storing a setting with no control to write it"* and that
`AdminConsoleWriteTokenTest` was written after a third settings screen was found
with a box no writer stood behind. Four un-enrolled modules is four screens
nobody has swept. **Expect to find at least one real defect** — a key in
`SCHEMA` with no control, or a control writing a key not in `SCHEMA`. When you
find one, it is a bug with a fix and a test, not a line in the report.

**The rule that decides your scope.** A value stored by these screens must be
one of its own options or the default, and anything printed unescaped must be a
constant. If enrolling a module turns a setting red, fix the setting — do not
widen the guard to accept it.

**Nothing that already works may change**, and that is sharper here than usual:
a `TABS` constant is a description of a screen that already exists, so the
screen must render byte-identically after your change. `Storefront-
EnglishUnchangedTest` and `AdminNavAndIdsTest` are the instruments.

**Where it sits.** Four admin screens — name each one's exact path in your
report (`Settings → …` / `Store → …`), and screenshot any screen you changed at
390 and 1280.

**Owns.** `app/Support/ReviewSettings.php`, `app/Support/CacheSettings.php`,
`app/Support/ReviewBadgeSettings.php`, `app/Services/Mail/MailSettings.php`,
their four controllers and four screen partials, and
`tests/Feature/ModuleFrameworkGuardTest.php`.

**Must not touch.** `CartPage`, `CheckoutPage`, `SecurityModule`,
`HomepageContent` — all four are enrolled and Lane CP has just been through the
cart panel. Not `routes/web.php`, not `KBB-Master-Plan.md`.

**Done when.** The four modules are in `ModuleFrameworkGuardTest::MODULES` and
the guard is green without any exemption being widened; each defect the
enrolment exposed has its own test and mutation note; and the report states,
module by module, what the guard found — including "nothing" where it found
nothing.

---

## Lane HL — editing a homepage section from the preview

**The work.** `KBB-Master-Plan.md:2717`, the last unticked line of Phase 15's
original scope: *"Live editing of homepage sections, reusing the settings
schemas."* The two halves it needs both exist already:

- The preview: `POST /admin-api/homepage/preview`, declared in
  `routes/homepage-preview-admin.php` and mounted at `routes/web.php:842`. Read
  the whole header comment of that file before you start — it is the previous
  lane's handover and it names the cache trap.
- The schemas: the module framework's `SCHEMA` / `TABS` / `POLICY` triple, which
  is how every other settings screen in this console is rendered. **Reuse it.**
  A second, parallel way of describing a control is the thing this brief most
  wants to avoid.

So the lane is the join: a section in the preview is selectable, and selecting
it opens that section's own schema-rendered controls, and a change is reflected
without a full page reload.

**Ships OFF, or ships inert.** Rule 1 of `CLAUDE.md` is not negotiable and this
is the most visible screen in the console: **every new setting ships at the
value the page already has**, so applying the package moves nothing until the
owner moves something. The homepage itself must render byte-identically before
anybody touches a control — `StorefrontEnglishUnchangedTest` is the instrument,
and if it goes red you read the diff rather than advancing the pin.

**Two traps already paid for on this exact screen**, both in the plan at lines
2700–2712 — read them:

- Hero visibility used to set `display:none` on the band containing the delivery
  strip and the ticker, silently overriding two other switches. The band's
  visibility is now the OR of what it contains. Do not undo that.
- `site_title` had a box on the screen and no writer behind it in either console
  block. `AdminConsoleWriteTokenTest` now sweeps for that shape. Your new
  controls will be swept by it; make sure each one has its writer.

**No JavaScript that measures layout.** Two tests forbid the element-measuring
APIs by name, and this project sizes with `calc()` for a reason. A selectable
section is a CSS answer with a class, not a `getBoundingClientRect()` loop.

**Routes are the integrator's.** Declare anything new in your own file beside
`routes/homepage-preview-admin.php` and say in the report what to wire. Pin the
**finished** state, never the absence of the require — `substr_count($web,
"require __DIR__.'/my-file.php';") === 1`. Three lanes have lost a round to the
other assertion. Your package ships a `clear_caches_*` migration if it adds a
route.

**Security.** Every new admin endpoint gets its own capability and fails closed.
`/api/*` is unauthenticated — nothing you add goes there. A URL that arrives
from a setting is scheme-checked before it becomes an `href`.

**Where it sits.** `Appearance → Homepage` (confirm the exact path yourself and
give it in full in the report). Screenshots at 390 and 1280 of the preview
before selection, with a section selected, and with its controls open; plus
`document.documentElement.scrollWidth` at both widths.

**Owns.** `app/Http/Controllers/Admin/HomepageApiController.php`,
`resources/views/admin/partials/homepage-content-screen.blade.php`, a new routes
file of your own, `tests/Feature/HomepagePreview*`, `tests/Support/Homepage*`.

**Must not touch.** `routes/web.php`, `resources/views/admin/app.blade.php`,
`app/Services/HomepageContent.php`'s `SCHEMA` shape without saying why in the
report, `KBB-Master-Plan.md`, `KBB-Progress-Dashboard.html`.

**Done when.** A section can be selected in the preview and edited through its
own schema; the homepage renders identically until a control is moved; every new
control has a writer and a capability; the query budget is unchanged or raised
deliberately; and the report names the admin path and carries the four
screenshots.

---

## Lane SET — the Set: a product type, not a folder of products

**Briefed 28 September 2026.** The owner's words:

> "I have a new product type, which called Set. currently we just combine the
> prices and images etc as 1 product, and call it as set. but in our new
> website, i want this thing very different. Under Catalog, there will be Sets,
> and upon creating new set, the system will ask to choose the products, and
> will ask for set price, category, description etc, the same as in product
> edit page. and it will be published same like other products and display.
> When user add to cart this set product, it will display as set box on the
> cart, on the checkout summary and everywhere on the users side, like email,
> invoice, dashboard etc."

### The shape, decided rather than left to you — and the evidence for it

**A Set IS a row in `products`, with `products.type = 'set'`, plus one pivot
table for its members.** Not its own table. Checked at `c602d3f` before this
was written:

- The owner requires a set to be *"published same like other products"* — so it
  needs `slug`, `status`, `is_visible`, `category_id`, `description`,
  `short_description`, `image`, `images`, `price`, `sale_price`, `position`,
  `created_at`, plus its SEO row and its category pivot. Every one of those is a
  `products` column today. A separate table duplicates all of them **and** every
  screen, sitemap entry, search index and API allowlist that reads `products`.
- `cart_items.product_id` and `order_items.product_id` are foreign keys to
  `products` (`0001_01_01_000000_create_kbb_schema.php:266` and `:437`). A set
  that is a product needs **no schema change on either table** to be added to a
  basket or sold.
- `products.type` already stores an unknown value verbatim — that is written
  down in `docs/PRODUCT-FIELD-PARITY.md` row 6, for the import. And only
  **three** files in the whole application branch on the value: `Product.php`,
  `VariantPricing.php`, `RedirectMap.php`. Nothing does an exclusive
  `type === 'simple'` test that a third value would fall out of. **Verify that
  sweep yourself before you rely on it** — it is the single assumption this
  brief makes that would be expensive if it were wrong.

The pivot: **`product_set_items`** — `set_product_id`, `member_product_id`,
`member_variant_id` (nullable, so a set can name the 50ml), `quantity`,
`position`. Foreign keys to `products` and `product_variants`.

### The one thing that is harder than it looks: what an order remembers

A set's contents WILL change. An order sold last month must still print what was
actually in the box, so **the member list has to be snapshotted onto the order,
not read back through the pivot.** `order_items` already snapshots for exactly
this reason — read the comment at `0001_01_01_000000_create_kbb_schema.php:439`,
*"Snapshots, so an order still reads correctly after a product is renamed or
deleted"* — and it already carries one JSON snapshot column,
`variant_attributes`.

**Do it the same way: one `set_contents` JSON column on `order_items`**, holding
name, brand, sku, quantity and the member's own unit price at the time of sale.
Not child rows: child `order_items` would be counted by every total, every
report, every refund ceiling and every invoice line in this shop, and each of
those is a place to get it wrong. A JSON snapshot is read by the things that
print a set and invisible to the things that add money up.

The same snapshot is what the **invoice**, the **order-confirmation email**, the
**customer's order page** and the **admin order screen** print. One writer, four
readers — do not let a second description of a set's contents exist.

### The seven surfaces, and the design the owner chose

The set row is drawn in seven places. The owner has been shown four options and
picks one; **the answer is in the integrator's message to you — build the
option he names and nothing else.**

1. Cart panel (`resources/views/partials/cart-drawer.blade.php`, the `.kc-item`
   row at line 91)
2. Cart page (`resources/views/store/cart-inner.blade.php`)
3. Checkout summary (`resources/views/store/checkout.blade.php`)
4. The browsed rail (the second tab of the cart panel)
5. Order confirmation email (`app/Mail/`)
6. Invoice (`app/Services/Invoices/InvoiceDocument.php`)
7. The customer's own order page (`resources/views/store/account/`)

Read `resources/css/kbb/kbb.css` for `.kc-item`, `.kc-th`, `.kc-mid`, `.kc-nm`,
`.kc-pr` before you draw anything: the row you are extending has a fixed
geometry and a Mobile control set behind it (`--cp-*`), and a set row that
ignores those is a set row the owner cannot resize.

### What is NOT yours, and goes in the report as a question

- **Stock.** Does selling a set decrement each member's stock? There is a real
  argument either way and it is a commercial decision. Build whichever leaves
  today's behaviour unchanged, put the question in one line.
- **The saving.** The "you save AED 63" figure is the sum of the members' own
  prices minus the set price. Compute it, but only SHOW it where the owner's
  chosen design has it.

### Rules that apply without exception

- **Money is integer fils.** The set price, every member price and the saving.
  No floats anywhere on this path, including in a fixture.
- **`/api/*` is unauthenticated.** A set returned there goes through an explicit
  allowlist like `Product::toApi()`, and the member list is a second allowlist —
  a member is a product and carries `wc_id`, `sku` and `total_sales`. Read
  `tests/Feature/ApiSecurityTest.php` first.
- **Nothing that already works may change.** A shop with no sets in it must
  render byte-identically. `StorefrontEnglishUnchangedTest` is the instrument.
- **`StorefrontQueryBudgetTest` is a budget.** A cart holding one set must not
  cost one query per member — the members are eager-loaded or they are in the
  snapshot. Measure it; do not assert it.
- **Every new admin endpoint gets its own capability and fails closed.**
- **`routes/web.php` is the integrator's.** Declare routes in your own file, say
  what to wire, ship a `clear_caches_*` migration, and pin the FINISHED state
  (`substr_count(...) === 1`) — never the absence of the require.
- **`resources/views/admin/app.blade.php` is the integrator's.** If the Sets
  screen needs a sidebar entry, say so; do not add it.

### Owns

`app/Models/ProductSet*.php` and the set pivot model, a `SetImporter` if you add
one, `app/Http/Controllers/Admin/SetApiController.php`,
`resources/views/admin/partials/sets-screen.blade.php`, your own routes file,
`database/migrations/*_sets_*`, `tests/Feature/*Set*`, `tools/set-*`.

You will also have to touch `cart-drawer.blade.php`, `cart-inner.blade.php`,
`checkout.blade.php`, `InvoiceDocument.php`, the order email and the account
order page. **Those are shared.** Touch only the lines a set needs, say in the
report exactly which lines, and leave the non-set path byte-identical.

### Done when

Catalog → Sets creates a set from chosen products with its own price, category,
description and images; it publishes and displays like any other product; adding
it to the basket draws the chosen set row in all seven places; an order sold and
then edited still prints what was in the box; every fix ships with the test that
goes red without it and a mutation note that was actually run; the pictures are
at 390 and 1280; and the report names the admin path in full.

---

## Lane BN — the homepage cards banner

**Briefed 28 September 2026.** The owner's words:

> "I need multiple cards type with auto scroll smooth scroll, each card will have
> image banner and downside 1-2 lines text with right side small beautiful
> button. need full backend controls under Appearance > Banners > cards banner,
> we can turn on off card banners, and inside each banner section we can create
> multiple cards and the whole section will have full control options to choose
> which banner will show on homepage, how many cards, scroll speed, animation
> etc. please i need it super beautiful and super light optimized and without
> bugs and responsive across devices with auto adjustment from large screens to
> small."

### What it is, in one sentence

A NEW homepage section — **not** the existing `hero` slider, which stays exactly
as it is — holding a horizontally auto-scrolling row of cards. Each card is a
banner image with one or two lines of text beneath it and a small button to the
right of that text. The owner builds any number of named banner sets, each with
its own cards and its own controls, and chooses which set the homepage shows.

### The data shape, decided, with the reasoning

**Two tables plus two module settings. Not one and not four.**

- **`banner_sets`** — `id`, `name`, `slug`, `status` (`publish`/`draft`),
  `position`, and **its own control columns**, because the owner asked for the
  controls to be *"inside each banner section"*: `autoplay` (bool),
  `speed_ms`, `animation` (an enum of your own named options), `per_view`
  (how many cards are visible at once), `gap`, `card_radius`, `show_arrows`,
  `show_dots`, `pause_on_hover`. Every one ships at the value that makes a
  freshly created set look like the shop already looks.
- **`banner_cards`** — `banner_set_id`, `image`, `alt`, `heading`, `body`,
  `button_label`, `button_url`, `position`, `status`.
- **Two module settings only**, in the module framework so they get the guard,
  the screen renderer and the policy allowlist: the section's on/off, and
  **which set id the homepage shows**.

Why not put the controls in the module framework too: they are **per set**, and
`SCHEMA` holds one value per key for the whole shop. Why not put the cards in a
JSON column: they carry images, ordering and their own publish state, and the
Media Library has to be able to see the images (`MediaRegistrar` — read what
Lane MB did for imported files, and register on write).

### The carousel: a CSS answer, not a JavaScript one

**This is the part that decides whether it is "super light" or not, and it is
where a carousel normally goes wrong.**

`CLAUDE.md` rule 4: *"No JavaScript that measures layout — this project sizes
with `calc()` for a reason, and two tests forbid the element-measuring APIs by
name."* A carousel that advances by reading `offsetWidth` is exactly the shape
that rule exists to stop.

**Build the auto-scroll as a CSS animation over a doubled track.** The row holds
the cards twice and translates by `-50%` over `speed_ms`, so it loops seamlessly
with **no JavaScript at all** and the browser can run it on the compositor. The
speed is a CSS custom property the server writes; the animation name is the
owner's `animation` choice. The shop already sizes its rails this way —
`kbb.css:947`, `flex:0 0 clamp(178px,17vw,220px)` — so **the card width is a
`clamp()`, never a measurement**, and "auto adjustment from large screens to
small" falls out of that one declaration rather than out of a resize listener.

Required, and each has already cost this project or is about to:

- **`prefers-reduced-motion: reduce` stops it dead.** Not slower — stopped, with
  the cards laid out as a static row the shopper can still scroll by hand. The
  UGC rail does this at `resources/views/ugc/assets.blade.php:273`; follow it.
- **Pause on hover and on focus-within**, so a keyboard user can reach the
  buttons.
- **Every card's button is a real `<a href>`**, and the URL comes from a
  setting, so it is **scheme-checked before it becomes an `href`** — rule 5.
- **The first card's image is the LCP element on the homepage.** It gets
  explicit `width`/`height` (no layout shift), `fetchpriority="high"` and no
  `loading="lazy"`; every later card gets `loading="lazy"` and
  `decoding="async"`. A carousel that lazy-loads its first image is a carousel
  that made the homepage slower.
- **Arrows and dots are optional and off unless the set asks for them.**

### Where it sits

**`Appearance → Banners → Cards banner`** — a new screen. The Appearance sidebar
group is in `resources/views/admin/app.blade.php`, which is the integrator's
file: **build the screen as your own partial, and say in the report what entry
to add.** Do not edit that file.

The screen wants: a list of banner sets; create/rename/duplicate/delete a set;
inside a set, the cards with drag-free ordering (a position number is fine) and
the set's own controls; and a **live preview of the row as the homepage will
draw it**, because every other Appearance screen in this console has one and the
owner uses them daily.

### Ships OFF, and nothing on the homepage moves

Rule 1, and on the most visible page in the shop. The module ships **off**, no
set is chosen, and the homepage renders byte-identically until the owner turns
it on. `StorefrontEnglishUnchangedTest` is the instrument: if it goes red you
read the diff and revert the accident — you never advance a pin to make it
green.

### The rules that apply without exception

- **`StorefrontQueryBudgetTest` is a budget.** The homepage must cost the SAME
  number of queries with the section off, and at most one more with it on — one
  query for the chosen set and its cards, eager-loaded. Not one per card.
  Measure it; do not assert it.
- **`/api/*` is unauthenticated.** If a banner reaches it, an explicit allowlist
  — never the model.
- **Every new admin endpoint gets its own capability and fails closed.**
- **Anything printed unescaped is a constant, never a setting.** The heading and
  body are settings: escape them.
- **`routes/web.php` is the integrator's.** Your own routes file, a
  `clear_caches_*` migration, and pin the FINISHED state
  (`substr_count(...) === 1`) — never the absence of the require.
- **`resources/views/admin/app.blade.php` and `KBB-Master-Plan.md` are the
  integrator's.**

### Owns

`app/Models/BannerSet.php`, `app/Models/BannerCard.php`,
`app/Http/Controllers/Admin/BannerApiController.php`,
`app/Services/Banners.php`, `resources/views/admin/partials/banners-screen.blade.php`,
`resources/views/partials/home/cards-banner.blade.php`, your own routes file,
`database/migrations/*_banner_*`, `tests/Feature/*Banner*`, `tools/bn-*`,
`docs/lane-bn-shots/`.

`resources/views/store/home.blade.php` and `app/Services/HomepageSections.php`
are shared: add the section and nothing else, name the lines you touched, and
leave every other section byte-identical.

### Done when

The owner can build a set, add cards, choose it, and see it on the homepage;
the row scrolls smoothly and stops entirely under reduced motion; it is
responsive from 390px to 1920px with no horizontal page scroll at any width;
the homepage is byte-identical with the module off; the query budget is
unchanged or +1 deliberately; every fix ships with the test that goes red
without it and a mutation note that was actually run; and the report carries
screenshots at 390, 768 and 1280 plus `document.documentElement.scrollWidth` at
each.

---

# Round 7 — three lanes, briefed 28 September 2026

Round 6 (SET, BN) is merged and shipped as 2.60.305. These three finish its
edges and close one thing that is about to matter more than it did.

---

## Lane SP — the Set's remaining edges

**1. The set's own product page says nothing about what is in it.**
Lane SET's report, §8: *"It was not one of the seven surfaces and you did not
name it. The set publishes with its own description, gallery and price; what is
in the box is not repeated there."* That is a real gap — a shopper who lands on
`/product/{set-slug}/` from Google sees a price and no contents.

`resources/views/partials/set-row.blade.php` already draws the fanned stack and
the popup from `SetContents::fromProduct()`. The product page wants something
FULLER than a basket row, not the same row again: the members named, with their
own pictures and their own links, because on this page a member is a product the
shopper may want to open. Reuse `SetContents` — it is the one description of a
set's contents and a second one is the thing this round most wants to avoid.

**2. A set is indistinguishable in `Catalog → Products`.**
Same report: `CatalogProductsApiController`'s `type` filter is a pass-through, so
a set is listed among the ordinary products with nothing to mark it. The lane
left it deliberately ("a set *is* a product") and offered a chip. Add the chip
and a filter, so the owner can find his sets and can tell what he is looking at.

**3. Build the stock rule, switched to today's behaviour.**
The owner has been asked whether selling a set should take one of each member
off the shelf; he has not answered. **Build it behind a setting whose default is
exactly what happens today** (the set carries its own stock), so applying the
package moves nothing, and the answer is one switch away whichever way he goes.
Say in the report where the switch is.

**Owns.** `resources/views/partials/set-*.blade.php`, a product-page partial of
your own, `app/Support/SetContents.php`, `app/Http/Controllers/Admin/
CatalogProductsApiController.php` (the type filter and its chip only),
`app/Services/Stock*`, `tests/Feature/*Set*`, `tools/sp-*`.

**Must not touch.** `routes/web.php`, `resources/views/admin/app.blade.php`,
`resources/views/partials/set-row.blade.php`'s design switch (Lane SET's `@if`
and `SetDesign::CURRENT` — read them, build beside them), the master plan.

**Done when.** A set's product page names its contents; `Catalog → Products`
marks and filters sets; the stock rule exists and is off; the homepage and every
existing page render byte-identically; each fix has the test that goes red
without it and a mutation note that was run; pictures at 390 and 1280.

---

## Lane BP — the banner's remaining edges, and the guard it is not in

**1. `cards_banner` is not enrolled in `ModuleFrameworkGuardTest`.**
Lane BN's report, §7: *"The integrator may enrol … it passes as written."*
Enrol it. That guard is what catches a module storing a setting with no control
to write it — Lane MC found a screen drawing one control twice by doing exactly
this, and a module that "passes as written" is a module nobody has checked.
If enrolling it turns something red, **fix the module, never widen the guard.**

**2. The dots do not say which card you are on.**
Report §7: *":target can style the card but not its dot without :has()
gymnastics. They navigate; they do not indicate."* `:has()` is available in every
browser this shop supports — check that yourself before relying on it — so the
gymnastics may simply be the answer. If it genuinely cannot be done without
JavaScript, say so with what you tried, and do not add JavaScript: the whole
section has none and that is the feature.

**3. The arrows are Chrome-only and shipped off.**
`::scroll-button()` is the only way to move a scroll container from CSS, and
Safari and Firefox draw nothing. The screen says so. **Decide whether that is
honest enough** — a control that silently does not exist for half the traffic is
a setting the owner can switch on and never see. Either make the switch say
which browsers it reaches, or hide it where it cannot work. Your call, argued.

**4. Each dot adds a history entry**, because they are in-page anchors. Measure
what that actually does to the back button on a phone and report it; fix it only
if it is as bad as it sounds.

**Owns.** `app/Services/Banners.php`, `app/Models/Banner*.php`,
`resources/views/partials/home/cards-banner.blade.php`,
`resources/views/admin/partials/banners-screen.blade.php`,
`tests/Feature/CardsBanner*`, `tests/Feature/ModuleFrameworkGuardTest.php`,
`tools/bp-*`.

**Done when.** The module is in the guard with nothing widened; the dots
indicate or the report says precisely why they cannot; the arrows' reach is
honest on the screen; the homepage still costs +1 query and no more; reduced
motion still stops it dead; `scrollWidth` still equals the viewport at 390, 768
and 1280 with pictures to prove it.

---

## Lane SX — a product image address becomes CSS, in fifteen places

**The work, and why it is worth a lane now rather than later.**

Fifteen sites across twelve storefront Blade files build a CSS declaration by
interpolating an image address:

```php
$thumb = $img ? "background-image:url('" . e($img) . "')" : …
```

`e()` is the HTML escaper. It turns `'` into `&#39;`, and the HTML parser decodes
that back to `'` **before CSS ever sees the attribute** — so a single quote in
the address closes the `url(` and everything after it is CSS the shop did not
write.

**State the size of it honestly, because overstating it is how a real finding
gets ignored.** `e()` also escapes `"`, `<` and `>`, so the attribute cannot be
closed and no tag can be opened: this is **CSS-context injection, not script
injection.** What it buys an attacker is a rule of their choosing on that
element — a `background:url(https://theirs/…)` that fires a request from your
shopper's browser carrying your page as the referrer, or a restyle that covers
something. Not an XSS. Worth fixing, not worth a panic.

**And it is about to matter more.** Every one of these addresses is a product,
review, Instagram or set image, and **the WordPress import is about to write
thousands of them** from a database this shop did not author. Today the values
are ones the owner typed.

**What the fix is.** One helper that makes a value safe for a CSS `url()` — not
a second copy of `e()`. It has to answer the CSS question (quotes, parentheses,
backslashes, newlines, and control characters) and it should refuse an address
whose scheme is not one this shop serves, the way `Banners::safeUrl()` already
does for the banner cards. Then every one of the fifteen sites goes through it.

**Find them yourself rather than trusting this list** — it was built with one
grep and greps miss things. The twelve files it found: `product-reviews`,
`set-row`, `cart-drawer`, `checkout/received-line`, `checkout/browsed-item`,
`checkout/thumbs`, `checkout/summary-items`, `address-sheet`,
`store/cart-inner`, `store/product`, `store/account/order-detail`,
`instagram/assets`.

**Ship a guard that makes a sixteenth impossible**: a test that sweeps the
storefront Blade files for a CSS `url(` built by interpolation and fails on any
that does not go through the helper. Mind the two traps this repo has hit twice
this week — **strip comments before scanning**, and never pass a message as a
second argument to `toContain()`, which is variadic.

**Owns.** A new helper under `app/Support/`, the twelve Blade files listed,
`tests/Feature/*Css*`, `tests/Feature/*Url*`, `tools/sx-*`.

**Must not touch.** `resources/views/admin/**` (a different lane's screens and a
different threat model), `routes/web.php`, the master plan.

**Done when.** Every storefront CSS `url()` goes through one helper; a guard
fails on a new one; the rendered HTML is byte-identical for every address that
was already safe — `StorefrontEnglishUnchangedTest` is the instrument and it
must stay green without its pin being advanced; and the report names what the
helper refuses and what it merely escapes.

---

## Lane JS — the storefront's JavaScript has never had a security sweep

**Briefed 28 September 2026, straight off a finding.**

`resources/js/kbb/search.js` was building its suggestion rows out of `/api/search`
and setting `innerHTML` with **four values escaped by nothing at all** — the
product link, the product image, the group heading and the view-all link. A
double quote in any of them closed the attribute it sat in and the next token
was read as a new attribute: an event handler, on a panel every shopper opens.
**Script injection, on the storefront.** It is fixed, in 2.60.306, with
`schemeIsServed()` / `safeHref()` / `cssUrl()` — read them, they are the
JavaScript twins of `App\Support\CssUrl` and they are the pattern for this lane.

**Nothing about that was special to search.js.** It was found by accident, by a
lane sent to fix a smaller problem in Blade. There are **eighteen files** under
`resources/js/kbb/` and **38 `innerHTML` sites** among them, and nobody has ever
looked at the rest.

### The work

**Sweep every file under `resources/js/`.** For each site where a value that did
not originate in this page's own source reaches the DOM, decide what it is and
fix it:

- **`innerHTML` / `outerHTML` / `insertAdjacentHTML`** built by template string —
  every `${…}` must be escaped for the context it lands in. An attribute, an
  HTML body and a CSS `url()` are three different contexts and `escapeHtml()`
  is only correct for two of them; the third is what `cssUrl()` exists for.
- **`href` / `src` / `action`** — escaped AND scheme-checked, so `javascript:`
  cannot land in one. A refused href is `'#'`, never `''`: an empty href is the
  current page, so a refused link would silently reload the shop.
- **`style` attributes and `el.style.x =`** — the CSS context.
- **`eval`, `new Function`, `setTimeout('string')`, `document.write`** — if any
  exist, they are their own finding.
- **`location` assignments** built from a response.

`reviews.js:185` uses `URL.createObjectURL()`, which is browser-generated and
safe — that one has been checked. Everything else has not.

### Where the values come from, which is what decides the severity

Most of these are fed by `/api/*`, which reads the `products`, `reviews` and
`ugc_videos` tables. **Today those values are ones the owner typed.** The
WordPress import is about to write thousands of them from a database this shop
did not author, and an imported product name, image path or slug is exactly the
kind of value nobody inspects. That is the whole reason this is a lane now and
not a note.

### What NOT to do

- **Do not add a sanitiser library.** One small helper per context, in one
  place, imported by the files that need it — the three in `search.js` are the
  shape; lift them somewhere shared rather than copying them eighteen times.
- **Do not rewrite a file's structure to fix an escape.** The smallest change
  that closes the hole, so the diff is readable and `StorefrontEnglish-
  UnchangedTest` stays green.
- **Do not convert `innerHTML` to DOM building wholesale.** It is the right
  answer in the abstract and a rewrite of the whole storefront in practice.
  Where one site is small and obviously better as `textContent`, take it; say
  so; otherwise escape.

### The thing that makes a JS fix real

**A fix that lives only in `resources/` is a fix the shop does not have** — the
storefront loads the compiled bundle from `public/build`. Run `npx vite build`,
ship the built asset, and **pin it**: `SearchPanelEscapingTest`'s fourth case is
the pattern, and read its comment first — its own first cut looked for the
function NAMES in the bundle and went red against a bundle that *did* carry the
fix, because esbuild renames every local function. Fingerprint string and regex
literals instead.

### Rules

- **Nothing that already works may change.** A normal address must come through
  byte-identical. Prove it, the way the search fix did: exercise the helpers in
  node against real attack strings AND a plain path, and put the measured table
  in the test's comment.
- Every fix ships with the test that goes red without it and a **mutation note
  you actually ran**.
- `CLAUDE.md` rule 4 forbids shipped JavaScript that measures layout. You are
  editing shipped JavaScript: do not add a measuring API while you are in there.
- **Do not touch** `routes/web.php`, `resources/views/admin/**`,
  `KBB-Master-Plan.md`. Admin JavaScript is a different threat model and a
  different lane.
- **Two traps this repo hit four times this week.** `toContain()` is variadic —
  a message as its second argument becomes a second needle. And a source scan
  finds prose: strip comments before scanning, or the comment explaining your
  fix satisfies the check the fix was meant to pass.

### Owns

`resources/js/**`, a shared helper module of your own under `resources/js/`,
`public/build/**` (rebuilt, not hand-edited), `tests/Feature/*Escap*`,
`tests/Feature/*Js*`, `tools/js-*`, `docs/lane-js-shots/`.

### Done when

Every site in `resources/js/` is either escaped for its context or recorded as
safe with the reason; the helpers live in one place; the built bundle carries
the fix and a test proves it; every normal value renders byte-identically; and
the report lists what you found site by site, what you fixed, and what you
judged safe and why.
