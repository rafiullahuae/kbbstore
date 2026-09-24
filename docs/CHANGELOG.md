# Changelog

Versions are the numbers used by the Core Updates screen. Each entry lists the
files it touched, so a diff can be checked against it.

## 2.60.271
The routine search, actually fixed — and the previous diagnosis was wrong.

▲ 2.60.270's note implied the search fix was the step-scope change. IT WAS NOT.
Reproduced against the shipped 2.60.268 screen in a browser rather than reasoned
about: typing "centella" fired NO REQUEST AT ALL and left the previous 25 rows
on screen. The box was bound to `onchange`, which on a text input fires on blur
or Enter and nothing else. The scope narrowing fixed in 2.60.270 did not exist
on that screen, so it cannot have been the cause. Enter also fired the request
TWICE, because two handlers both answered it.

AND A SECOND, INDEPENDENT CAUSE, WHICH THIS PROJECT SHIPPED. 2.60.269 added
`ingredients` to the search's WHERE clause. That column is created by a
migration guarded with Schema::hasColumn — so on a server where the migration
never ran, EVERY SEARCH IS A 500 while browsing without a term works perfectly.
That is the exact shape of the report. It now probes for the column and falls
back to the name-and-SKU search, saying so on screen rather than narrowing
silently.

QUICK AND REAL-TIME, measured on a 681-product catalogue: debounce 250ms, chosen
because a fast typist's inter-keystroke gap is 120-180ms and a control stops
feeling responsive past ~300ms. "centella" (8 keystrokes) is one request and
65ms; "niacinamide" (11 keystrokes) is one request and 38ms. The stale-response
race is proved in a browser, not argued: holding the reply to `q=cer` so it
lands after `q=ceramide`, the guards paint 166 results; with both removed, 339
stale results appear under a label reading "339 products match ceramide".

DEMO DATA FOR ROUTINES at Catalog -> Build my routine -> Settings -> Demo data,
and also at Store -> Demo Content. Five products, one per step, removable from
either screen. THE DEMO ROWS CARRY NO CONCERNS AT ALL, and that one decision is
what makes them safe: an empty concern list already means "suits any routine",
and ConcernCollections selects explicit tags only — so a demo row can never
reach the threshold that publishes /concern/acne/, never enters the sitemap and
is never offered by the quiz, BY CONSTRUCTION rather than by an exclusion rule
somebody has to remember in six places. Every row is named "Demo — …" and a
banner appears on every tab while any exist, saying whether shoppers can see
them.

THE ON/OFF SWITCH is on the same screen, writing the same setting as Store ->
Modules -> Build my routine. It deliberately does NOT post to the modules
endpoint: that rebuilds `module_devices` from only the keys it is handed, so a
one-module post from here would silently reset every other module's device
setting. It carries `store.settings` rather than a catalogue capability, because
everything else on the screen decides which product fills which step and this
one puts two pages on the internet.

WHAT IT DOES NOT TURN OFF is now on the card in the owner's words:
/concern/{slug}/ is deliberately outside the switch and keeps answering either
way, because those are ordinary shop pages people find in Google and a switch
here should not take them down.

▲ AND /_design-check IS DELETED — a URL that answered 200 now answers 404.
Nothing linked to it. Its own comment read "Temporary: the Phase 1 design check.
Delete when Phase 2 lands"; this shop is at Phase 20. Measured before removing
it: 200 to anybody, `<meta name="robots" content="index, follow">`, a
self-canonical, the theme directory named in its markup, eight real products
rendered — and absent from both robots.txt and the sitemap. A developer page
actively asking to be indexed, on a shop whose own research lists crawl hygiene
as something it BEATS the competitor on.

THE SITEMAP STOPPED KEEPING ITS OWN COPIES of the curated listings and the
content pages; both now come from the router, the way the concern block beside
them already did. A second bug fell out of it: the content-page URL was built as
'/' . slug . '/', assuming the slug IS the address. It need not be — a page
routed at /about-us with ->defaults('slug','about') would have been served while
the sitemap advertised /about/, a 404. Proved inert: same database, old
implementation against new, 10,586 bytes and 67 URLs both ways, diff identical.

Five migrations, all cache clears.

## 2.60.270
Catalog -> Build my routine is now eight tabs, and the import stopped asking
eleven questions whose answer was always the same.

THE ROUTINE SCREEN WAS ONE 693-LINE PAGE and the owner had to scroll past all of
it to reach the only job that matters. It is now one flat strip:
Cleanser · Toner · Treatment · Moisturiser · SPF · Concern pages · Wording ·
Settings, copied from Appearance -> Checkout page because that is the pattern he
already uses daily. He chose the two things that would otherwise have been
guesses: LOCKED MEANS DONE, NEVER DISABLED — nothing is gated on anything else
and he works in any order — and every section gets a tab rather than staying
stacked below. From a cold shop he is tagging in two clicks: the strip names the
next step and the empty body carries the button that fills it.

THE TAB LABEL CARRIES TWO FIGURES BECAUSE THEY ANSWER TWO QUESTIONS. The COUNT
is products a shopper can actually be shown — published, visible, in stock — so
four tagged products that are all out of stock read 0, which is what the
storefront can draw on. The TICK is "no routine I am showing still draws this
step empty": four toners all tagged for acne leave seven routines short, and a
count-based tick would have called that finished. A routine he has hidden is not
counted, because a step it cannot fill is not a gap a shopper can reach.

AND THE SEARCH ON A STEP TAB LOOKS AT THE WHOLE CATALOGUE, NOT THE STEP.
Narrowed to the open step it returns nothing on an empty step — which is every
step on this shop today — and the owner would have concluded the search was
broken on the first word off the worksheet. Measured: "centella" on the empty
SPF tab returned 0 rows before and 14 after.

ONE CARD WAS CONSOLIDATED AND IT IS SAID HERE RATHER THAN LEFT TO BE NOTICED:
"What each step can draw from" is gone, because its five role chips ARE the five
step tabs now — same numbers, one card earlier, where he is about to click. Its
other two options, "Untagged only" and "Every product", survive as a scope
select on every step tab. Nothing else was removed, and a test names all
fourteen behaviour hooks the old page had and fails if one goes missing.

THE IMPORT STOPPED ASKING ABOUT THINGS IT HAD ALREADY SOLVED. 2.60.269 taught
the shop to derive the fifteen legacy category redirects; the importer had not
learned, so it still proposed writing all of them and asked eleven identical
questions, each ending "worth leaving alone if it does not" — where the answer
is always leave it alone. On a fully imported tree: rows to write 38 -> 8,
questions 11 -> 0.

AND A WRITTEN ROW IS WORSE THAN REDUNDANT, which is why this is a defect and not
tidying. The shop's answer is derived, so it follows the category when it is
renamed or re-parented. A stored row does not — and the row WINS, because the
redirects table is read before the router. Measured: write the row, rename the
parent, and a one-hop redirect becomes a two-hop chain; rename the leaf too and
it becomes a 301 onto a 404. The same reasoning that said 2.60.269 must not SEED
those rows says the import must not WRITE them.

Idempotency proved the strict way: first run writes 8, second writes ZERO — not
"writes the same 8", which would pass on a build that recomputed every
destination. Byte-identical table, no doubled path segments, and no article body
edited.

TWO SENTENCES ON THE IMPORT SCREEN STOPPED BEING FALSE. A product tag or
attribute term used to read "nothing in this shop carries {type} id {id} —
either it was never imported…", which is untrue: the term WAS imported; what
this shop has no equivalent for is a tag ARCHIVE, by design. And the screen's
headline note still described proposals as "questions rather than discards",
which is exactly what this round reversed, above numbers that had changed
underneath it.

IN-CONTENT LINKS ARE STILL NOT REWRITTEN, and the case against got stronger
rather than weaker: a body linking to /toners/ already lands in one hop with no
row and no rewrite, a derived redirect follows the category while a rewritten
body freezes today's nesting into the owner's prose, and a body is the one
artefact here that cannot be recomputed.

No new route, no new endpoint, no new capability, no migration.

## 2.60.269
FIFTEEN ADDRESSES GOOGLE HAS INDEXED STOPPED BEING 404s.

The old shop served its category archives FLAT at the site root — /toners/,
/sunscreens/, /skincare-sets/ — because that WooCommerce install had
`category_base` set to the empty string. That is the old site's own exported
setting, not an inference. This shop serves them nested, at
/product-category/skincare/toners/. The cutover forwards the old domain
PRESERVING the path, so every one of those fifteen has been landing on a 404 at
the end of a 301 — its ranking dropped rather than inherited.

Measured before: all fifteen 404. The `redirects` table ships ten rows and
every one is a journal article; there was never a category row.

Measured after, against a running server: all fifteen answer 301 to the correct
NESTED path, `curl -L` reports num_redirects=1 and a final 200, and the landing
page's own <link rel="canonical"> matches the Location byte for byte. The
slashless spelling lands too.

ARABIC WORKS, ON ALL FIFTEEN. /ar/toners/ 301s to
/ar/product-category/skincare/toners/ and answers 200 with <html lang="ar"> and
an Arabic canonical — one hop, the prefix kept exactly once. The middleware
order was MEASURED on the live global stack rather than reasoned about:
CanonicalHost, then SetLocaleFromPath, then CheckRedirects, so /ar is stripped
BEFORE the redirect check and one list of paths serves both languages. That
order is now pinned by reflecting on the real stack, so a future prepend that
inverted it fails loudly instead of silently 404ing every Arabic old address.

THE ROWS ARE DERIVED PER REQUEST, NOT SEEDED, and that is the design point
rather than an implementation detail. On a fresh database the only categories
are six seeded placeholders, so a migration resolving /cleansing-oils/ at apply
time would bake in a destination the real import then contradicts —
permanently, invisibly, and on a shop whose owner cannot easily inspect the
table. Deriving is null until the category arrives and correct the moment it
does, with nothing to apply. A stored row still wins if one exists.

AND THE AUDIT CARD WAS ADVERTISING THE WRONG FIX. Shipped yesterday in
2.60.266, it named the FLAT form as the destination — which is a second 301 for
a nested category and a 404 for a missing one. Corrected: addresses the shop
now lands are no longer reported at all, and the card reads 15 before this
package and 0 after.

Ruled out with evidence rather than left undone: /category/ and /tag/ prefixes
(that install never used them — the same empty `category_base`), brand archives
(the exporter records an empty permalink per brand, WordPress served none),
products, articles and pages (same addresses on both sites), and feeds
(301ing a feed reader onto an HTML page is worse than the 404). Attribute
archives, product tags, paginated archives and attachment pages are deferred to
the next round with the reason stated. `?p=123` is structurally impossible for
any redirect row — the query string is not part of the path the router matches
— and needs an .htaccess rule the owner has to add.

No new route, no new setting, no new admin control, no migration. Zero queries
added to a warm storefront page: the check is an in-memory list of fifteen
literals and answers before the table is consulted.

## 2.60.268
The quiz stops being a dead end, and the tagging job becomes visible.

THE QUIZ ANSWERED EVERY SHOPPER WITH THE SAME ONE LINK. Measured on a default
shop before this: /skin-quiz answers 200 in 46,136 bytes, `window.KBB_ROUTINES`
is ABSENT from that document, `QuizRoutineLink::map()` returns null, /routines
is 404, and the results screen offers exactly ONE distinct URL — `/shop/`. A
shopper names up to three concerns and gets three chips, three routine shapes
holding no products, and four buttons to the whole catalogue. The concern
reached a chip and a row in `quiz_leads` and went nowhere else.

The hand-off itself had been built some time ago and the master plan still
carried it as outstanding — but ticking that box alone would have recorded the
opposite of the truth, because every URL it can produce is a `/routines/`
address and those sit behind `build_my_routine`, which ships OFF. So the
hand-off existed and could never fire on a shipped shop.

THE RUNG THAT WAS MISSING is `/concern/{slug}/`, the other page per concern and
the one NOT behind that switch: it publishes itself the day a concern has copy
and three live tagged products. The quiz now emits that table separately and
falls back to it, and the plan email gets the same middle rung instead of
dropping from a routine straight to `/shop/`. Separate rather than merged,
because "Build my acne routine" over a collection URL describes a page the
shopper is not about to see.

THE TAGGING JOB IS SMALLER. Catalog -> Build my routine searched `name` and
`sku` only, while `products.ingredients` has existed since October — so most of
the sensitivity signal (`fragrance-free`, `centella`) was unreachable from the
one screen the owner is meant to tag from. It now searches ingredients too and
shows a snippet when that is what matched, at the same 2 queries.

AND THE JOB IS NOW VISIBLE. Catalog -> Build my routine -> "Concern landing
pages — N of 8 live": per concern, live tagged products against the floor, how
many more are needed, whether copy exists, and the address once live. It ships
reading 0 of 8. It counts what the PAGE counts and not what a ROUTINE counts —
an untagged product suits every routine and no concern page, so the routine
tally would have reported 400 products for acne over an address that still
404s.

AN N+1 FOUND WHILE MEASURING: `BuildMyRoutine::coverage()` ran
`Routine::overrides()` inside its per-concern loop — eight identical SELECTs
over one table that cannot change between them, invisible to any budget because
it scales with the CONCERN list rather than the catalogue. 10 queries to 3.

NOTHING MOVES ON APPLYING THIS. `ENABLED` is still `['acne']`, the floor is
still 3, nothing in the repo is tagged, so the concern list is empty, the quiz
emits no second table, and the shipped page contains neither
`window.KBB_CONCERN_PAGES` nor the string `/concern/`. The English pin moved
for the quiz page and the diff was read rather than waved through: every changed
byte is inside the inline <script>, and with script blocks stripped the two
documents are identical at 15,349 bytes on both sides.

THE REMAINING BLOCKER, stated on the screen rather than left to be wondered
about: only `acne` has page copy. The other seven need a string in the code, so
a concern can sit at 5 tagged products against a floor of 3 and still be dark.
The countdown row now says so. Both SEO lanes refused to generate that copy and
were right to.

No new route, no new endpoint, no new capability, no migration: the countdown
rides GET /admin-api/routines and the search rides GET /admin-api/routine-
products, both already behind `catalog.view`.

## 2.60.267
Ed25519 package signing, ENFORCING NOTHING — and a fix for the page that was
supposed to save you when the admin console breaks.

▲ /admin/updates?fallback=1 HAS ANSWERED 500 SINCE THE UPDATER WAS BUILT.
routes/web.php called `UpdateController::index()` with no argument on a method
typed `index(Request $request)` — an ArgumentCountError present since the
baseline commit, 2.60.41. That page is the standalone fallback that exists
PRECISELY for the case where the admin bundle will not load, so the one day
anybody would ever open it is the one day it could not help them. Nobody found
it because nobody loads a fallback page until they need it, and on that day the
conclusion is that the server is dead rather than the page. Found by a lane
photographing a banner on it. One line.

SIGNING SHIPS PERMISSIVE, WITH AN EMPTY TRUSTED-KEY LIST. A signature that is
present is verified; a package with no signature installs exactly as it does
today; the Core Updates header still reads "unsigned packages accepted", byte
for byte. Every package this project has ever built carries `"signature": ""`,
so the same packages install before and after. The one behaviour change for any
package that exists today is that one carrying a signature this server CANNOT
check is refused rather than ignored, and no real package carries one.

ON HOLD AT THE OWNER'S REQUEST. He asked for unsigned patches for now, so step
6 of the rollout — the step that makes a signature mandatory — is not to be
taken. `KBB-Master-Plan.md` Phase 14 records the hold; the lane is freed.

WHY THE ROLLOUT IS SIX STEPS AND WHY THEY MAY NOT BE MERGED
(docs/PACKAGE-SIGNING.md §1). The package that teaches a shop to REQUIRE a
signature is applied by the verifier that came BEFORE it. So a shop must be
able to check a signature before it can be told to demand one, and must have
been SEEN to check one before that demand is safe. That is the identical
new-code/old-data/no-way-in shape that bricked this shop's updater earlier the
same day; the sequence exists so it cannot recur.

AND SIGNING WOULD NOT HAVE CAUGHT THAT OUTAGE. Said plainly because the
opposite is easy to assume: a signature proves ORIGIN, never CORRECTNESS. All
five packages behind it would have been signed by this key, verified, and
applied — they were built by the wrong script, not by the wrong person.
`checkMigrationsAreDeclared()` and `ClassDependencyScan`, both shipped in
2.60.266, are what stand between the shop and that fault. What signing adds is
that a package which did not come from the build machine cannot be applied at
all: the difference between a mistake and an attack.

THE KEY NEVER TOUCHES THIS REPOSITORY OR A SHOP. Private half lives at
~/.config/kbb/package-signing.key on the build machine, 0600; the code REFUSES
a key inside the repository, a group- or world-readable key, and a symlink. A
`.key` file is not an allowed package extension and a home directory is not an
allowed prefix, so it cannot reach a shop even if it were committed. The public
half is a LIST in config/kbb.php, so a key rotates by trusting both for one
release.

THE EMERGENCY HATCH IS A FILE, storage/app/kbb-accept-unsigned, not a setting —
because `.env` is not read at all while the config cache exists (the trap that
made KBB_NOINDEX read false for its whole life), because a database setting
needs the app to boot, and because `storage/` is on UpdateGuard's forbidden
list, so no package can create it to weaken a shop or delete it to strand one.
It relaxes "must be signed" and never "if signed, must be genuine": a forged
package is still refused with the hatch on.

One migration, a cache clear: config/kbb.php gains two keys and is
config-cached, and the /updates route closure changed.

## 2.60.266
Three lanes, and a security item that this package CANNOT fix.

▲ DELETE public_html/kbb-doctor.php OVER SSH. TODAY. It is reachable right now
with a token that is committed to this repository. Unlike kbb-recover.php, which
refuses to run while its token is the placeholder, the doctor has no such check
-- it simply compares the request's token against the constant, so THE
PLACEHOLDER IS THE PASSWORD, and the file's own header prints the complete URL
with it in plain text. Anyone who has seen this repository can read the error
log and its stack traces, enumerate every table, list the web root and clear the
caches. No package can repair this: BuildPackage excludes public-web-root/ and
UpdateGuard forbids those files outright, both deliberately, so that a bad
update cannot damage its own escape route. It has to be `rm`.

THE HEALTH CHECK NOW LOOKS AT THE SHOP, and this is the one deliberate rule-1
departure in the package. `/_kbb-health` ran `SELECT 1` and returned JSON; it
never rendered a page. That is why 2.60.260 -- which removed a class every
product tile resolves out of the container -- passed its own post-update check
and was KEPT, with the home page, /shop, every category, every brand and every
product page answering 500 behind it. It now renders the home page and the first
visible product page and asserts four things: status exactly 200; a body ending
in `</html>` (the case a status code cannot reach, because a fatal mid-render
flushes the buffer with 200 already sent); a length floor; and that the product
page names its own slug -- which is DATA read from the database a moment
earlier, never copy, so a lane stays free to rewrite every visible string but
cannot ship a page that no longer knows which product it is.

It changes which updates are KEPT: a package that leaves either page unable to
render is now rolled back automatically. `KBB_HEALTH_DEEP=false` in .env is the
escape hatch for the one case where that is wrong, a shop already broken for an
unrelated reason that cannot otherwise install its own repair. An empty
catalogue still passes, and one unrenderable product row cannot brick updates
for ever -- it tries the first three visible products and passes if any renders.

AND A PACKAGE CAN NO LONGER INSTALL CODE WHOSE CLASSES IT DOES NOT CARRY. Every
PHP and Blade file in a package is tokenised, and each `App\` class it names
must be in the package or already on the server. That is exactly what 2.60.260
did: three templates resolving App\Services\VariantPricing, with the class
itself in a package that had not been applied. It uses PHP's own tokeniser, so a
name in a comment or a string is not a reference, and it never calls
class_exists(), because a verifier must not execute what it is verifying.

A SAMPLE ORDER, at Safety -> Demo Content -> Sample order. The owner has had no
orders on this shop all round and has twice been asked to open a document he
could not reach. Choose English or Arabic, press the button, and get an order
worth looking at -- three lines, one a variable product with an option, an
address, delivery, payment, AED 542 -- with direct links to the invoice, packing
slip, delivery note and dispatch label, and a button to delete it again. An
Arabic order shows the split this round built: invoice and delivery note in
Arabic, packing slip and dispatch label in the operator's English.

It is marked three ways, each failing safe for a different question: a
demo_seed_log row written INSIDE the order's own transaction, so there is no
instant where the order exists and the thing excluding it does not; `origin =
'sample'` for the places that may not issue a query, such as the mailer and the
document banner; and the order number SAMPLE-0001, which every screen, document
and export prints by construction. No email, through six separate guards and a
backstop. No stock. No invoice number. Its own owner-only capability.

TWO FIGURES WERE ALREADY WRONG and are fixed here rather than buried: Store ->
Orders' revenue tiles had no demo exclusion at all, so a demo order made the
Dashboard and the very next screen disagree; and the Orders CSV export computed
`is_demo` and discarded it, now the last column so no existing column moves.

FOUND AND NOT FIXED: the COD drawer in Payments -> Reconciliation has no demo
exclusion, so Demo Content's own eight seeded COD orders already inflate it.
Pre-existing. The sample order avoids it by using a card gateway and a test pins
that, so a later edit cannot break it silently.

A NEW SEO AUDIT FINDING, Store -> SEO & Meta -> SEO Audit, last card, advisory.
Fifteen legacy category addresses are indexed today, the cutover forwards the
old domain preserving the path, and this app 404s those flat paths by
construction -- so each one lands on a 404 at the end of a 301 and drops its
ranking instead of passing it on. Nothing could see it, because a scan of the
indexable surface cannot reach addresses the shop refuses to serve.

## 2.60.265
2.60.264 plus the guard that stops its root cause recurring, rebuilt as one
package. Apply this instead of .264.

THE SERVER NOW REFUSES A PACKAGE THAT CARRIES MIGRATIONS WITHOUT DECLARING
THEM. `UpdateRunner` runs migrations only when `update.json` says
`"migrations": true` -- `hasMigrations()` reads that key and never looks at the
files. 2.60.259 through .263 were built by a hand-written script that omitted
it, so eight migration files were copied to the live server and none ran, while
every package reported "applied". That is what left the server holding an
UpdateRunner which writes `update_releases.manifest` and no such column, which
is what bricked the updater.

The same shape had already happened once, with `orders.is_gift` in 2.60.85, and
`PackageMigrationFlagTest` has warned about it since. A test in the repository
cannot stop a builder that never runs it, so the check now lives in
`UpdatePackage::verify()`, on the server, where every package passes however it
was built. It refuses rather than inferring the flag: a silent correction would
let a broken builder keep shipping and nobody would learn the builder is wrong.

CONTENTS: everything from 2.60.259, .260, .261, .262 and .264, written whole
against 2.60.258, so the order the earlier ones were applied in no longer
matters.

## 2.60.264
THE UPDATER COULD NOT APPLY ANYTHING AT ALL, and the cause was three
defects stacked on each other. Every package, down to an 18 KB one carrying
two files, answered a bare "Server Error".

1 · THE PACKAGES WERE BUILT WRONG, and this one is the root cause. 2.60.259
through .263 were built by a hand-written script instead of
`php artisan kbb:package`, and it left out the `migrations` key. UpdateRunner
reads that key and nothing else -- `hasMigrations()` never looks at the files
-- so all eight migration files were copied to the server and NONE of them
ran, while every package still reported "applied". `PackageMigrationFlagTest`
has warned about exactly this since the is_gift incident; the warning was in
the repository and the builder that ignored it was not.

2 · SO THE SERVER HELD THE CODE AND NOT THE COLUMN. 2.60.260 installed an
UpdateRunner that writes `update_releases.manifest` and, because of 1, did not
add the column. Every apply after that hit it.

3 · AND ONE SWALLOWED EXCEPTION SPREAD TO EVERY WRITE AFTER IT.
`recordManifest()` wrote with `$release->update()` inside a try/catch whose
docblock claimed that made it incapable of failing an update. Eloquent's
`update()` is `fill()` then `save()`: `fill()` puts `manifest` on the model
FIRST, and only then does the save throw. The catch swallowed the throw and
left the attribute on the model, dirty -- so every later `save()` re-sent it:
the `backup_id` write, the `status` write, then `rollback()`'s status write,
and finally the `['status' => 'failed']` inside `rollback()`'s own catch, which
is the third throw and the one nothing catches. It escaped `apply()`, escaped
the controller, and became the 500. The guard did not contain the failure, it
seeded it.

WHAT CHANGED. Every write to `update_releases` now goes through one private
writer that cannot dirty the model (query builder, no model state) and cannot
throw (logged, never raised) -- because by the time a rollback writes its
status the files are already restored, and losing the row AND showing a bare
500 is far worse than losing the row. `recordManifest()` also checks the column
exists before writing, so the ordinary window between a package's files landing
and its migrations running costs nothing. And the exit code of
`Artisan::call('migrate')` is read: a migration that fails now fails the
update, rolls the files back and puts the migrator's own output on the screen,
instead of reporting success over a broken schema.

NOTE ON APPLYING THIS ONE. The updater cannot repair itself -- `recordManifest`
runs at the top of `apply()`, before any file is copied -- so the column has to
exist before this package will go on. One statement, in the host's database
tool:

    ALTER TABLE `update_releases` ADD COLUMN `manifest` LONGTEXT NULL;

After that this package applies normally, its eight migrations run for the
first time, and the `add_manifest_to_update_releases` migration finds the
column already there and returns without touching it.

CONTENTS. Everything from 2.60.259, .260, .261 and .262 as well, written whole
against 2.60.258, so the order the earlier packages were applied in no longer
matters. Built with `php artisan kbb:package --since=`, which is the only
builder this project should ever use again.

## 2.60.263
NO CODE CHANGE. Two files out of 2.60.262, shipped alone because the host
would not apply the whole thing.

WHY IT IS SPLIT. 2.60.260 (26 files) applied. 2.60.259 (56 files) left its row
at `running` three times, and 2.60.262 (84 files) answered a plain "Server
Error" from a screen whose endpoint returns JSON on every outcome including a
rollback -- so the response was not the application's at all, and the PHP worker
was killed by the host before it could write one. Applying copies every file,
snapshots each one first, dumps the database when migrations are present and
then makes an HTTP request to itself; somewhere between 26 and 56 files that
exceeds this host's request limit.

WHAT THESE TWO FILES ARE. The whole of the live breakage and nothing else:

  - `app/Services/VariantPricing.php`, which does not exist on a server that
    skipped .259, and which `product-card.blade.php`, `product-grid.blade.php`
    and `store/product.blade.php` all resolve out of the container.
  - `app/Services/Invoices/InvoiceDocument.php`, which is where the
    `nameForCustomer` key the invoice and delivery-note sheets read is
    produced.

NEITHER NEEDS A MIGRATION OR A NEW DEPENDENCY. Every class the two import
exists on the server at 2.60.258, checked one by one, and InvoiceDocument reads
`name_localised` as `?? ''`, so it is correct before
`order_lines_snapshot_the_customers_language` has run and correct after.

## 2.60.262
NO CODE CHANGE. This is 2.60.259, .260 and .261 rebuilt as ONE cumulative
package against 2.60.258, because the three were applied out of order on the
live host and a file ships whole.

WHY IT WAS NEEDED. .260 was applied before .259, and .259 never recorded a
completion. Three files that landed then referenced things only .259 ships:

  - `App\Services\VariantPricing` is NEW in .259 and does not exist on a server
    that skipped it. `product-card.blade.php`, `product-grid.blade.php` and
    `store/product.blade.php` -- all three shipped by .260 -- resolve it out of
    the container to print a variable product's price range. Every page that
    draws a product tile, and every product page, throws
    `Class "App\Services\VariantPricing" not found`.
  - `nameForCustomer` is the array key `InvoiceDocument::items()` gained in
    .259. `sheet-invoice.blade.php` and `sheet-delivery-note.blade.php`, both
    shipped by .261, read it. Against .258's InvoiceDocument the key is absent,
    so every invoice and delivery note throws.

THE HEALTH CHECK DID NOT CATCH EITHER, and that is the defect underneath the
mistake rather than the mistake itself. `/_kbb-health` runs `SELECT 1` and
returns JSON. It never renders a storefront page, so a package that breaks the
home page, the shop, every category, every brand and every product page answers
`{"ok":true}` and is kept. An update that takes the shop down is exactly what
that check exists to refuse, and it cannot see it.

WHAT THIS PACKAGE DOES. Every file changed between 2.60.258 and 2.60.261,
written whole. It does not matter which of the three landed, which landed
partly, or in what order: applying this produces the 2.60.261 tree exactly.
Laravel's migrator skips the migrations that already ran, so the eight
migrations here are safe whatever state the table is in.

## 2.60.261
Two changes, both visible on the shop, both fixing something rather than
preferring something.

▲ THE NAV BAR DRAGGED THE WHOLE SHOP SIDEWAYS, and this is a RULE 1 EXCEPTION
that will change at least five pages the day it is applied. `.mbar .wrap` was
`flex-wrap:nowrap` with `overflow-x:visible`: flex items shrink only to their
min-content width, and past that floor the bar stopped shrinking and pushed the
PAGE wider, because nothing clipped it, scrolled it or wrapped it. Measured on
a sixteen-entry menu, identically on /, /shop/, /new-in/, /best-sellers/ and
/super-sale/: `document.documentElement.scrollWidth` 1459 against a 1280
viewport, +339 at 1120, +435 at 1024. The mobile nav does not take over until
~1000px, so there was a band where the desktop bar took the storefront with it.

NEITHER OBVIOUS ANSWER WORKED, and both were built and measured before one was
picked. `overflow-x:auto` contains the page and leaves the bar one row -- but a
scroll container cannot keep `overflow-y:visible`, the other axis computes to
auto with it, and the mega panels are `position:absolute` children INSIDE the
bar, 319.2px tall under a 45.5px bar: 1px of 319.2 visible, 0.3%, at every
width. Hovering "Brands" drew the underline and no panel. `flex-wrap:wrap`
alone put the SHIPPED twelve-entry menu on THREE rows, 93px against 45.5px, at
every width from 1024 to 1920.

The reason was a two-part defect in nav-fit.js that had been cancelling itself
out. It asked `scrollWidth > clientWidth`; `scrollWidth` is CLAMPED to
`clientWidth`, so that can never be true, and `clientWidth` includes the bar's
padding while flex wraps against the CONTENT box. On the shipped menu at 1280,
with wrapping suspended for the reading: scrollWidth 1280 vs clientWidth 1280 --
"it fits" -- while items and gaps came to 1242.3 against a content box of 1236.
Six pixels over, invisible because they bled into the 22px gutter, and under
wrapping those six pixels cost a whole row.

ON A MENU THAT ALREADY FITS, NOTHING MOVES: bar height 45.5px before and after
at every width, one row before and after, no vertical shift. The only change is
`--nav-scale` 0.936 to 0.919 at 1280 -- 12.17px to 11.95px, a fifth of a pixel,
and it is the correct size. At 390 the bar is hidden and the before and after
screenshots are byte-identical.

HOW MANY TOP-LEVEL ENTRIES, and how long their labels, is still the owner's
question at Appearance -> Header. This only decides what happens when the menu
is too wide: a nav bar that handles its own overflow, rather than a shop that
scrolls sideways.

THE DELIVERY NOTE now prints in the customer's language. Before this, an Arabic
customer's handover sheet was byte-for-byte the English customer's --
`lang="en"`, "Delivery Note", "Rice Daily Moisturizing Toner 150ml". It now
reads `lang="ar"`, "إشعار التسليم" and the Arabic product names. THE PACKING
SLIP IS UNCHANGED and stays in the operator's language, deliberately: the
distinction is not whether a document leaves the building -- the dispatch label
leaves too, on the outside of the box, and a courier reads it -- but who reads
it. A packing slip is a picking list read at the bench. The delivery note goes
IN THE PARCEL and is opened by the person who ordered.

A third file the same argument required: `BulkDocumentController::sheets()`
decided whether to enter the order's language by asking
`allocatesInvoiceNumbers()`, which was right only while the invoice was the one
customer-facing document. Left alone, a batch of twenty would have printed
Arabic product names inside English headings, and nothing would have failed.

No migration: no route and no new setting.

## 2.60.260
Two lanes: the variable-product basket, and the content-security policy in
report-only.

THE DEFECT THIS EXISTS FOR: a shopper could put a variable product in the
basket for AED 0. Measured on a running server before the fix --
`POST /api/cart/add {"product_id":27}` answered 200 with
`cart_items.unit_price = 0` and no variant. `CartService::add()` now refuses
it, and there were FOUR doors into it, not the two the tile suggested:

  - Store\CartController::add()          the basket
  - Store\CheckoutController::browsedAdd()  ON THE CHECKOUT PAGE. `browsed()`
      lists the viewed-products cookie with no filter on type and `browsedAdd()`
      passes no variant by construction, so a free line was one press away on
      the page where people pay.
  - Services\ManualOrderBuilder            an operator's draft order, which
      would have been a 500. It now names the product that needs an option.
  - anything written next month             the service throws.

▲ RULE 1 EXCEPTION, SAID OUT LOUD. Tiles in the SKINNED grid that carry an
"Add to cart" button today will lose it in two cases, and both are defects
rather than preferences:

  - a VARIABLE product, which is the AED 0 above. It now reads "View product".
  - a SOLD-OUT product. The skinned grid offered Add to cart on out-of-stock
    items and the server answered "That product is sold out." Found by the byte
    pin, not by anybody looking.

`components/product-card.blade.php` -- the tile everywhere else on the shop --
has always shown "View product" for both. This makes the two agree. The whole
tile is already a link to the product page, so nothing became unreachable, and
the button box measures the same before and after at 390 and 1280: the label
swap moves no layout. No new string, no new CSS.

THE PRODUCT PAGE headline printed AED 0 for a variable product, and this was
not a flicker: pdp.js writes the real figure from a CLICK listener only, so the
page stood at AED 0 -- with an option already highlighted reading AED 35
directly beneath it -- until the shopper tapped something. It now draws the
same price range the tiles print.

NEW, AND IT SHIPS OFF: `Content-Security-Policy-Report-Only`, with violations
on Store -> Security -> Content security policy. The enforcing header does not
occur anywhere under app/ and a test proves it by stripping comments and
searching every PHP file. Measured in Chromium, every violation the shop
produces today is INLINE SCRIPT OR INLINE STYLE -- not one external host was
refused, so the allowlist is already right. The home page posts 158 reports in
one view. Enforcing today would take the shop apart (24 inline `<script>`, 124
`on*` handlers, 29 `<style>`, 210 `style=""` across 95 views), which is why
this round buys the measurement rather than the enforcement.

The policy deliberately does NOT cover the admin console: that page is one
1.1 MB document of inline script and style, and a storefront policy on it would
post thousands of violations from the screen the owner reads violations on.

The violation endpoint is public by necessity and is bounded five ways:
60/minute, a 16 KB body cap, four allowlisted fields, shape checks that cut the
URI to scheme/host/path so a per-request query string cannot defeat the
collapse, and a row ceiling that is scoped to policy rows -- so a flood cannot
push the owner's own security trail out of the table. It answers 204 to
everything and echoes nothing.

Also: Store -> Security's verdict used to read "687 requests refused as too
many" the first time the policy was switched on -- every one of them the
owner's own visitors being shed by the throttle and recorded as a rate-limit
trip. A shed report is now its own event: still counted, because reports really
were lost and an absent violation must not read as "does not happen", but out
of the trips list and out of the verdict.

Three migrations, two of them cache clears.

## 2.60.259
Six lanes, merged with no conflicts, and ONE package for the reason 2.60.258
gives: `routes/web.php` carries both route changes in this round and a file
ships whole, so splitting by lane would mean hand-editing a partial version of
it -- which is how 2.60.102-.106 were withdrawn.

NOTHING ON THE SHOP MOVES ON APPLYING THIS, with two named exceptions below.
Every new setting ships at the value the page already had, and the one new
storefront address answers 404 until the owner fills it.

THE TWO BEHAVIOUR CHANGES TO WATCH:

1. `/sitemap.xml`, `/robots.txt` and `/llms.txt` now leave with
   `Cache-Control: public, max-age=3600, s-maxage=3600` and NO `Set-Cookie`.
   Measured before: `no-cache, private` plus two cookies on each, which made
   them uncacheable by anything in front of this host. They keep
   `X-Robots-Tag: noindex` on staging -- the five classes dropped are the
   cookie and session ones, deliberately not the whole `web` group.
2. `/product-category/<leaf>/` now 301s to the canonical NESTED path. It always
   301'd; it named a different address as canonical than the one the page
   itself declares, which is a self-contradiction a crawler reads.

FIXES THAT WERE LIVE DEFECTS: brand, category and article pages published
`<title>KBB</title>` -- Yoast's shipped template is `%%title%% %%sep%%
%%sitename%%` and the renderer deleted the token rather than resolving it, so
the page name was simply absent (the product half of this shipped in .258, the
other three callers are here); a redirect row could point at itself, at all
three writers; the Arabic cart badge sat stretched across the bag icon because
an inline `style` attribute was setting `right` while the stylesheet set
`inset-inline-end`, and an inline declaration beats an author rule of any
specificity; a variable product's tile printed AED 0 instead of its price
range; article `<a href>` links to the old host's uploads were never migrated,
so a thumbnail's full-size image broke on the day that host went dark; the
share image and organisation logo held in `settings` were invisible to the
media audit entirely, so "remote: 0" could be reached with the shop's own
WhatsApp preview still hot-linked.

NEW CONTROLS, each shipping at today's value:
  Appearance -> Checkout page -> Mobile . Header       Header padding -- sides
  Appearance -> Footer -> On a phone                   Padding inside the top / bottom
  Appearance -> Footer -> Shape & size                 Padding inside the top / bottom

NEW SCREENS AND CARDS:
  Store -> Import -> Addresses & pictures -> Old addresses
      "What this needs you to decide" -- the ask bucket became answerable.
      An answer is to a QUESTION, not an address: approving /x/ -> /a/ is not
      approval of /x/ -> /b/, and a re-parented category makes the answer stale
      and asks again naming both. A destination that 404s wins the question
      whatever the source's verdict, so a bulk yes cannot write a 301 to a 404.
  Store -> Business Details -> Business -> "Where the shop is"
      Address and opening hours for the Organization node. Half an address is
      never published -- street AND city AND a recognised country, or nothing.
      `postalCode` is NOT required: the UAE does not use one for street
      addresses.
  Store -> SEO & Meta -> SEO Audit -> "Product image with no alt text"
      Advisory, so it cannot take the headline from a finding that breaks a
      page. A product with no photograph is not reported -- there is no alt to
      write for a shot that does not exist.

NEW ADDRESS: `/concern/{slug}/`. It 404s, and stays 404, until the owner has
written the page's copy AND tagged at least three live products for that
concern under Catalog -> Build my routine. The sitemap asks the same class the
router asks, so it can never advertise an address the site refuses. `concern`
is reserved against the root-level article slug for the same reason `routines`
is: otherwise an article published there would be reachable today and would
silently stop being reachable on the day the third product was tagged.

ORDER LINES now snapshot the customer's language beside the operator's.
`order_items.name` keeps its exact meaning and value; a new nullable
`name_localised` is read by the invoice, both invoice emails and /my-account,
while the packing slip and delivery note keep the operator's. The hook returns
before touching anything while one language is live, so this ships inert.

Five migrations, four of them cache clears -- `route:cache` compiled the route
table as it was, so a route change that ships without one lands and is never
read.

RTL: the Arabic homepage hero now travels the way Arabic is read. The sign
comes from `document.documentElement.dir` in the slider itself; both CSS rules
that had tried to do it are deleted with the fix, because keeping them would
cancel it and restore the blank hero. Measured at 390px: slide 2 used to queue
at 378..744 -- the RIGHT, exactly as in English -- and now queues at -354..12.

Also: the built bundle stopped renaming itself on every unrelated edit.
`app.js` imported `kbb.css` while kbb.css was also its own Vite entry, and
Rollup folds a dependency into the chunk hash, so a stylesheet edit renamed the
JavaScript. `app-DSE-434-.js` and `app-iyd4z9xL.js` are byte-identical -- same
45,971 bytes, same md5 -- which is what a renamed-for-nothing bundle looks
like. Every stale name is a file the server keeps forever.

## 2.60.258
Seven lanes, merged. ONE package and not three, for a reason worth stating:
`routes/web.php` mounts all three new route files and `AppServiceProvider.php`
carries both the security listener and the redirect middleware registration. A
file ships whole, so splitting by lane would mean hand-editing partial versions
of those two -- which is exactly how 2.60.102-.106 were withdrawn.

THE ONE BEHAVIOUR CHANGE TO WATCH: the redirects table now fires BEFORE the
router. `CheckRedirects` was written as middleware and never registered, so a
row for an address the shop already answered could never fire -- five years of
old URLs silently serving the wrong page. Measured: /shop/ and /product/tx-serum/
went 200 to 301. Existing rows were fetched before and after and diffed
byte-identical. A row pointing a live page at itself is refused, not looped.

FIXES THAT WERE LIVE DEFECTS: a stored-XSS hole on Content -> Translations
(Arabic rich text published with no allowlist -- four script dialogs fired on
/ar/product, now zero); every imported product would have published the SAME
page title, because Yoast's default template is `%%title%% %%sep%% %%sitename%%`
and the renderer deleted the token instead of resolving it; the homepage slider
dot rail sat half off the screen at 390px, in ENGLISH; the discount badge printed
on top of the wishlist heart in Arabic; the Arabic hero went blank after one
click; a redirect destination was never scheme-checked though it goes straight
into a Location header.

NEW: Store -> Security (an administrative audit trail and a report that blocks
nothing), Store -> SEO & Meta -> SEO Audit (which found 24 products sharing one
meta description on its first run), an "Articles at addresses the shop owns"
report, and a real delete for the export folder holding shopper password hashes.

The Journal was uncounted by the media audit entirely -- a migration could have
reported zero remote references with every article picture still hot-linked.

The WordPress exporter changes are NOT in this zip: UpdateGuard refuses that
prefix. The plugin ships through Plugins -> Add New as it always has.

## 2.60.257
The third and last cause of the notch beside the phone logo, found by the owner
in DevTools: `kbb.css:1612` carries a bare `.logo{flex:1;text-align:center}`
inside its own 900px media query -- the site header's mobile centring, which the
checkout's logo inherited. It is also why two rounds of measuring said "fixed":
`flex:1` stretches the ELEMENT and `text-align:center` moves the GLYPHS inside
it, so the box's left edge stayed correct. Measured at 390: box left 20, first
letter 47.2. After: letters at 20, and at 140 at 1280 -- both equal to the
page's own edge.

The back-to-top arrow was knocked off centre by a rule shipped in 2.60.255: the
ruled-rows shape treated it as a row. Button centre x 355 y 2312.6 against glyph
centre x 348.5 y 2316.1; now the same point. It also waits until the page has
been scrolled, via a sentinel and an observer rather than a scroll handler.

And both presets now move only the tab in front of you, on the checkout screen
and on the footer screen -- a preset that reaches past the screen changes numbers
nobody can see.
`css/kbb/kbb-checkout.css`, `partials/slim-footer.blade.php`,
`admin/partials/checkout-page-screen.blade.php`,
`admin/partials/slim-footer-screen.blade.php`, `Services/CheckoutPage.php`,
`2026_12_11_000000_clear_caches_logo_arrow_and_per_tab_presets.php`

## 2.60.256
The floating Place order bar now waits for an order that can actually be placed:
it asks `form.querySelector(':invalid')` -- the same test the button itself uses
-- plus the address, delivery and payment, which constraint validation cannot
see because the chosen address lands in hidden inputs and a hidden input is
never `:invalid`. Driven at 390px: hidden on an empty form, hidden with the
fields filled but no address, shown once the address is chosen, hidden again the
moment the in-page button scrolls back or a field is cleared.

The notch beside the phone logo had a SECOND cause, found by sweeping 36
combinations rather than reasoning about it: `--cop-headpadx` is a control
separate from the page's `--cop-padx` and defaults to the same 20 only by
coincidence. In "lined up with the page" mode the header now takes the page's
padding -- 0 of 36 combinations misaligned, against several before.

And the footer lines up with the page: `width_mode` defaults to `page` and takes
the checkout's own inherited `--cop-d-max`, so the two cannot drift. The two
policy links move under the wordmark, in the markup rather than with `order` --
flexbox cannot put one sibling inside another's column.
`Services/SlimFooter.php`, `css/kbb/kbb-checkout.css`,
`store/checkout.blade.php`, `partials/slim-footer.blade.php`,
`admin/partials/checkout-page-screen.blade.php`,
`2026_12_10_000000_clear_caches_float_gate_and_footer_align.php`

## 2.60.255
The checkout footer wears the site header's own wordmark. It was drawing
`brand`, a flat text box shipping "K-BEAUTY BLISS" in capitals; it now reads
Appearance -> Header's Wordmark, Accent word and two colours, live, on every
render -- so "K-Beauty" in the header's ink and "Bliss" in the header's accent,
and renaming the shop stays one edit in one place. Not a second colour box: two
copies drift, and the one that drifts is whichever nobody looks at. The two
colours are compared against the HEADER's defaults, so a shop that has touched
neither screen still renders a footer with no style attribute, and they are safe
in a declaration because HeaderSettings::cast() answers a `colour` row with a
six-digit hex or the shipped default. `The wordmark` -> `The typed brand name`
restores the old box.
It also gains the spacing controls it was missing: space above the bar (a margin
and not padding, so the page's own ground shows through it rather than the bar's
tone), padding inside each ruled row and a floor under every row -- the rows used
to derive their padding from the block gap, so the only way to open them was to
open every gap in the bar at once -- plus "Squeeze the bar" and "Back to
defaults". Every one ships at what the bar was already doing; measured, 55px on
a desktop and 187.8px on a phone before and after.
`Services/SlimFooter.php`, `partials/slim-footer.blade.php`,
`Admin/SlimFooterApiController.php`, `admin/partials/slim-footer-screen.blade.php`,
`2026_12_09_000000_clear_caches_footer_wordmark_and_spacing.php`

## 2.60.254
Three selects that were rendering as sliders, the notch beside the phone logo,
the reviews line's wording, and the footer's own shape on a phone.

`checkout-page-screen.blade.php` handled `bool` and returned an
`<input type="range">` for everything else, so `ph_tone` and `ph_weight`
(2.60.252) and `m_float` (2.60.253) each drew as a slider with no scale showing
a value it could not represent, and saved as `NaN`. The screen now draws
`select` and `text`, and the handler branches on the schema's type rather than
on the DOM element's.

`.co-head .in` centres a band narrower than the window. Measured at 390px with
the mobile header width at 280: the band ran 55…335, the logo started at 75 and
the page's own "Back to shop" started at 20, while the badge overflowed to 370
— the right edge flush, the left notched. The phone now takes the page's own
edges by default; the class restores the centring.

"Go back to cart" gets its own tab, because the controls shipped under ten
spacing sliders and could not be found. The reviews line gets its wording back
without getting its figures back: `{rating}` and `{count}` are substituted from
approved reviews and a template carrying any other digit is refused. And the
footer gets a phone shape of its own — ruled rows on a phone, spread-to-both-
edges on a desktop — with the WhatsApp mark in WhatsApp green.
`Services/CheckoutPage.php`, `Services/SlimFooter.php`,
`css/kbb/kbb-checkout.css`, `partials/checkout/reassurance.blade.php`,
`partials/slim-footer.blade.php`, `admin/partials/checkout-page-screen.blade.php`,
`2026_12_08_000000_clear_caches_checkout_selects_and_footer_phone.php`

## 2.60.253
The band above the checkout header, and two controls under it. `kbb.css` carries
a bare `section{padding:52px 0}` and `.kbb-checkout` IS a `<section>`, so every
checkout inherited 52px of page background above the secure-checkout bar and
52px of nothing below the last block — measured in Chromium, `.co-head`'s own
top read 52 at 1280 AND at 390. The section now declares its own padding, from
two controls that ship at 0. "Go back to cart" gains a size, an arrow size and a
corner radius per surface, plus a tap height on mobile that starts at the 44px
Lane BM measured and raised it to. And the phone-only Place order bar now waits
for the in-page button to leave the viewport and goes the moment it returns —
IntersectionObserver, not a scroll handler, because the button moves as an
address is chosen. Two defaults change the page on purpose, both asked for: the
band is removed, and the floating bar is drawn where it was not.
`Services/CheckoutPage.php`, `css/kbb/kbb-checkout.css`,
`store/checkout.blade.php`, `2026_12_07_000000_clear_caches_checkout_shell_and_float.php`

## 2.60.107
Corrects 2.60.102–.106. Three files had been edited against a stale base — my
working copy of the server was the 2.60.71 snapshot with the 2.60.98 package
overlaid, and files changed in 2.60.72–.74 are not in that package. Applying
.102 or later would have reverted the 2.60.74 seoCtx closure fix (500 on every
product page), the Quick view button and its module gate, and the quick-view
modal shell. All three rebuilt on the correct base with the .102/.103 changes
reapplied.
`Store/ProductController.php`, `components/product-card.blade.php`,
`layouts/store.blade.php`

## 2.60.106
Reviews. The admin Reviews screen read product_slug, author, body and likes —
none of which is a column on `reviews`; the real ones are product_id,
author_name, content and helpful, so every review rendered blank. Both API
review endpoints keyed on the same phantom column and had never worked: the
read raised SQLSTATE 42S22, the write failed at the insert. GET /api/reviews
returned whole models with no status filter, exposing author_email and ip plus
unmoderated rows; now approved-only, named columns, capped, with author_email
and ip added to the model's $hidden. Posts endpoints given named columns.
`AdminController.php`, `Api/ProductController.php`, `Api/ReviewController.php`,
`Api/PostController.php`, `Models/Review.php`

## 2.60.105
POST /api/checkout/session — the one public endpoint that creates an order.
No visibility filter, so hidden products could be bought; no stock check, so
out-of-stock items were sold; `sale_price ?? price` ignored sale_starts_at and
sale_ends_at, so expired sales kept charging the sale price and scheduled ones
sold early; and 'brand' stored the belongsTo relation instead of its name.
`Api/CheckoutController.php`

## 2.60.104
Security headers were absent from every /api response — SecurityHeaders is
appended to the web group, and api routes do not inherit it. robots.txt let
crawlers into /cart, /my-account, /my-wishlist, /wishlist, /track-my-order. The
web-root deletion migration now uses public_path(), which bootstrap/app.php
sets to the real directory.
`routes/api.php`, `SeoFilesController.php`, `2026_09_13_150000_*.php`

## 2.60.103
og:image, twitter:image and the schema image were relative paths — Url::media()
returns root-relative, which Facebook, WhatsApp and X drop silently and Google
reports as invalid. Absolutised. priceValidUntil added to every Offer.
`Support/Seo.php`, `Store/ProductController.php`

## 2.60.102
AddToCart was missing from every pixel, so the funnel jumped ViewContent ->
InitiateCheckout. Added for Meta, GA4 and TikTok as one delegated listener,
with price from effectivePrice() on the button.
`MarketingPixels.php`, `layouts/store.blade.php`, `product-card.blade.php`,
`product-grid.blade.php`, `partials/home/grid.blade.php`, `quick-view.blade.php`

## 2.60.101
Deletes the four unauthenticated web-root scripts (kbb-patch-file, kbb-fix-now,
kbb-unstick, kbb-check-schema) via a migration, since the web root is outside
the paths the update system writes to. doctor and recover are kept — both are
token-checked.
`2026_09_13_150000_remove_ungated_webroot_scripts.php`

## 2.60.100
Eighteen further unescaped sites across every admin screen, found by sweeping
rather than by looking where the last bug was: review title and body, the reply
modal, Quiz Leads, Subscribers and their three modals, Customers, order line-item
brands, dashboard top-product brands, the redirects code pill. Two flagged sites
left alone after reading them — a search filter comparing text, and an HTTP
status in an Error string. Also folds in 2.60.99.
`admin/app.blade.php`

## 2.60.99
Core Updates reported "Version 1.0.0" on a server at 2.60.98. Three call sites
read config('kbb.version') -> env('KBB_VERSION'), never set here. New
InstalledVersion reads the newest 'applied' row from update_releases. Not
cosmetic: UpdatePackage compares requires_version against the same value, so any
package declaring a prerequisite above 1.0.0 would be refused by a server that
already exceeded it — which is why no package here has ever set one.
`InstalledVersion.php`, `UpdatePackage.php`, `UpdateRunner.php`,
`UpdateController.php`, `UpdateApiController.php`

## 2.60.98
Stored XSS in the admin console. Seven places concatenated customer-supplied
strings into innerHTML unescaped: the customer name on the dashboard feed, the
orders table, the order detail address block and line items, the review author
in the reviews table and reply modal, and top-product names. An order name or a
review author is whatever an anonymous visitor typed, and it executed with the
admin's session on the origin where /admin-api answers. All now pass through
sesc(), which the file already defined and used in ~90 other places.
`admin/app.blade.php`

## 2.60.97
SVG uploads could carry script. MediaUploadController allowed svg, which is XML
and can hold <script>, on* handlers, javascript: URLs, foreignObject and entity
declarations. Written to the public web root, that is stored XSS on this origin.
Eight markers now checked and rejected by name; content is entity-decoded first
and read as text, never parsed. Bitmaps unaffected.
`MediaUploadController.php`

## 2.60.96
The review captcha was bypassable. /reviews/submit had a honeypot, a captcha and
a 5/hour limit; POST /api/products/{slug}/reviews reached the same table with
none of them, and routes/api.php had no throttling at all. Controller-level
limit and honeypot added, sharing one key with the storefront form, plus
throttles on all four public POSTs.
`routes/api.php`, `Api/ProductController.php`

## 2.60.95
Public API was leaking. GET /api/settings returned the entire settings table
unauthenticated, including admin_path and indexnow_key; now allowlisted to
eleven presentational keys. /api/products/{slug} and /api/posts/{slug} had no
visibility filter, serving drafts and hidden products by slug. /api/products
filtered on status 'active', which products never use, so it returned nothing —
the bug that hid the missing access rules.
`Api/SettingController.php`, `Api/ProductController.php`, `Api/PostController.php`

## 2.60.94
The sitemap contained zero products. It filtered status 'active'; products use
'publish'. is_visible and deleted_at were unchecked too. Categories were listed
under /category/{slug}, which has never been a route. Product URLs lacked the
trailing slash and so pointed at redirects.
`SeoFilesController.php`

## 2.60.93
The journal was published at one URL and served at another. Routed at /blog and
/post/{slug}; four places published /skincare-guide/ and nothing matched it.
Canonical tags, breadcrumb schema and IndexNow all submitted /post/{slug}, which
the site never linked. /skincare-guide/ and /skincare-guide/{slug}/ are now the
real routes with 301s from the old pair.
`routes/web.php`, `PageController.php`, `AppServiceProvider.php`,
`blog.blade.php`, `post.blade.php`, `admin/app.blade.php`, clear_caches migration

## 2.60.92
Every brand URL was a 404. The homepage linked /brands/ twice, MenuDemo built
/korean-skincare-brands/ and /brand/{slug}/, and none had a route. /brands/ is
now a real A-Z index; the other two 301 to it and to the filtered listing.
`BrandController.php`, `brands.blade.php`, `routes/web.php`, clear_caches migration

## 2.60.91
Module statuses corrected. `product_sorting` and `seo_engine` were marked
`todo` and are both built; verified before changing.
`app/Services/ModuleRegistry.php`

## 2.60.90
Autocomplete now expands synonyms like the results page has since 2.60.77.
Per-order `gift_fee` column records what wrapping actually cost, rather than
recomputing it from the current setting. `AdminPathService` cache leak closed.
`SearchController.php`, `CheckoutController.php`, `AdminOrderController.php`,
`Order.php`, `AdminPathService.php`, `admin/app.blade.php`,
`2026_09_13_120000_add_gift_fee_to_orders.php`

## 2.60.89
Gift settings never saved: `gift_enabled` and `gift_fee` were absent from the
settings endpoint's allowlist, which skips unknown keys and returns ok anyway.
Separately, that endpoint wrote with `Setting::updateOrCreate()` and cleared
neither settings cache, so every value saved from Business Details reached the
database and was then ignored by the storefront.
`AdminController.php`, `admin/app.blade.php`

## 2.60.88
Gift setting given one source of truth: a migration seeds the row, and nothing
guesses a default in two places any more.
`2026_09_13_110000_seed_gift_settings.php`, `admin/app.blade.php`,
`CheckoutController.php`, `checkout.blade.php`, `order-block.blade.php`

## 2.60.87
Gift tick was hidden (default off), the checkbox sat on the text baseline
(`.form-row label{display:block}` outranked the rule meant to override it), and
the summary row showed at 0 (`.sumrow{display:flex}` outranks `[hidden]`).
`checkout.blade.php`, `order-block.blade.php`, `CheckoutController.php`,
`admin/app.blade.php`

## 2.60.86
Checkout 500. `...gift@if (...)` — Blade's directive pattern starts `\B@`, so a
directive whose `@` follows a word character is never compiled, while its
partner is. The compiled view held `endif;` with no `if:`.
`checkout.blade.php`

## 2.60.85
Cache-clear migration for the new route (stale `bootstrap/cache/routes-*.php`
overrides `routes/web.php`), and the Gift wrapping tab made scope-independent —
it read `SETTINGS` from another script scope, which killed the whole Delivery &
Shipping screen.
`2026_09_13_100000_clear_caches_2_60_85.php`, `admin/app.blade.php`

## 2.60.84
Gift settings moved to Store → Delivery & Shipping as a third tab; the card
added to Business Details in 2.60.82 removed.
`admin/app.blade.php`

## 2.60.83
Admin sidebar clipped: Store has 20 entries, `.nav-sub` opened to 560px with
`overflow:hidden`, so Business Details, Customers, Quiz Leads and Content &
Pages were unreachable.
`admin/app.blade.php`

## 2.60.82
Gift wrapping as a priced, merchant-controlled option. Fee read from settings,
never from the request; choice held in the session so every totals path agrees.
`CheckoutController.php`, `checkout.blade.php`, `order-block.blade.php`,
`routes/web.php`, `admin/app.blade.php`

## 2.60.79–.81
Checkout layout. The account, notes and gift blocks had been inserted inside
`<div class="row2">`, a two-column grid, so they became grid children and split
Email from Phone. Moved below the grid; notes and gift then moved into the
Delivery section; checkbox metrics matched to the existing WhatsApp row.
`checkout.blade.php`

## 2.60.78
Gift notes end to end, and `customer_note` — on the orders table since the
original schema, returned by the admin API, never captured and never rendered.
`2026_09_13_090000_add_gift_and_order_notes.php`, `Order.php`,
`AdminOrderController.php`, `CheckoutController.php`, `checkout.blade.php`,
`admin/app.blade.php`

## 2.60.76–.77
Guest checkout → account creation. The password is only ever written into a
blank, never over an existing one, and `legacy_password` counts as set, so the
3,712 imported customers cannot be trampled. Search synonyms via
`App\Support\SearchTerms`, with over-broad entries removed and a four-character
floor on expansion.
`SearchTerms.php`, `ShopController.php`, `CheckoutController.php`,
`checkout.blade.php`

## 2.60.75
Admin Catalog 500'd selecting a `category` column that has never existed on
`products` — categories and brands are foreign keys. Also removed an N+1 of
roughly 1,300 queries per screen load.
`AdminController.php`

## 2.60.74
Every product page 500'd. `ProductController::show()` builds `$summary` and the
`seoCtx` closure imported only `$product`.
`ProductController.php`

## 2.60.73
Module switches for quick view and the address book, registered in
`ModuleRegistry` with per-device visibility. Off means gone: no button, no
shell, endpoints 404.

## 2.60.72
Account address book (list, add, edit, delete, default per type) and product
quick view. No schema change — the `addresses` table and its model already
existed; the page was a 12-line stub that printed "No addresses saved yet"
without ever querying anything.
