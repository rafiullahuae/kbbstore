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
