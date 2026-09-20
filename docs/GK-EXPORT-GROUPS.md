# The export, section by section · Lane GK

The owner's words:

> *"in our app you section by section made the import process, why you didn't
> in export. like section by section, Products + categories + brands and
> anything related to products, then customers + orders etc, and so on, please
> group the related stuff and give options to choose any with live export
> progress bar... please plan well and do, do not miss anything."*

Before this lane the screen was all-or-nothing: rows per batch, a trashed-items
tick, Start / Resume / Pause, one bar. It is now eight named groups he ticks,
one bar per group, and — the part that is actually the job — a screen that will
not let him press Start on a selection whose consequences he has not read.

The evidence is `tests/Feature/GeWpExporterTest.php` (32 tests, 600 assertions,
ten of them this lane's), the screenshots are `docs/gk-export-shots/`, and
`docs/GE-WP-EXPORTER.md` remains the account of the plugin itself.

---

## 1. The headline

| | |
|---|---|
| groups | **8**, named the way `Store → Import` names the same rows |
| files | all 17 CSVs, each in **exactly one** group, pinned against the fixture |
| one group at a time | eight separate exports, imported one after another, land **exactly** what the all-at-once export lands — same counts, no null `product_id`, no synthesised customer |
| bytes | every CSV from every partial export is **byte-identical** to the full export's. Ticking changes *which* files, never *what is in them* |
| a dependency-violating selection | **refused until answered** — in the browser *and* in `start()`, with the consequence printed first |
| `manifest.json` | records `groups.selected` / `skipped` / `assumed_already_imported`, and a skipped group's files are **absent from `files`**, which is the contract's existing distinction finally having an example |
| pause / resume | per group, unchanged: `groups` is pinned at `start()` the way `skip_trashed` already was |
| media | untouched. Links still travel in `products.csv` / `posts.csv`; `MediaSideloader` still fetches them |
| mutations | **24 broken, 3 survived first time, all 24 red now** — §6 |

Nothing was added to the Laravel side. The contract already carried everything
this needed; §7 is the one line the integrator may want to add to it, and it is
a documentation change, not a format change.

---

## 2. The eight groups, and why the boundaries fall there

Declared in `wordpress-plugin/kbb-exporter/includes/class-kbb-export-groups.php`,
in the order the runner writes their files.

| key | label | files | needs |
|---|---|---|---|
| `catalogue` | **Catalogue** | `categories.csv` `brands.csv` `tags.csv` `attributes.csv` `products.csv` `variations.csv` | — |
| `seo` | **SEO (Yoast)** | `seo.csv` | catalogue |
| `coupons` | **Coupons** | `coupons.csv` | catalogue |
| `customers` | **Customers** | `customers.csv` | — |
| `sales` | **Orders** | `orders.csv` `order_items.csv` `refunds.csv` `order_notes.csv` | customers, catalogue |
| `reviews` | **Reviews** | `reviews.csv` | catalogue, customers |
| `content` | **Journal articles** | `posts.csv` | — |
| `addresses` | **Addresses and pictures** | `permalinks.csv` `media.csv` | catalogue, content |

**The labels are the import screen's own.** `ImportWorkspace::ENTITIES` calls
them *Customers*, *Reviews*, *SEO (Yoast)*, *Journal articles*, *Order lines*,
and this screen says the same words for the same rows. Two screens describing
one pipe in two vocabularies is how an owner comes to believe they are two
systems.

**Why the catalogue is one group and not six.** Categories, brands, tags,
attributes, products and variations are a single graph with no edge leaving it:
tags and attributes carry their product pivot, a variation names its attribute
terms by slug, a product names its category and brand terms. Splitting them
would put five dependency warnings on a screen for a decision nobody would ever
usefully make — nobody exports variations without products.

**Why orders, lines, refunds and notes are one group.** All four are the same
object. A refund without its order is not a smaller export, it is an
unimportable file: `RefundImporter` attaches to the order and nothing else.

**Why `seo` and `coupons` are their own groups even though both need the
catalogue.** Both are things the owner genuinely re-exports on their own. Yoast
meta changes when he edits a product description; a coupon is created on a
Tuesday afternoon. Folding them into `catalogue` would mean re-exporting 671
products to carry 40 coupons, which is the waste the whole screen exists to
remove.

**Why `permalinks.csv` and `media.csv` are together.** They are the same
question asked twice — *what did the old site publish, and where*. Both are
derived from the catalogue and the blog rather than being data of their own,
both are consumed by a separate command rather than by `kbb:import`, and neither
is any use without the other half of the cutover.

**Why `seo` does not depend on `content`.** `seo.csv` carries Yoast meta for
*every* post type, blog posts included — but `SeoImporter` matches on
`products.wc_id` and refuses anything else by name. The dependency is derived
from the importer, not from the exporter's file. This is the rule everywhere in
the table: an edge exists where the **importer** resolves a foreign key, not
where the exporter happens to write related bytes.

---

## 3. The dependency problem, and what the screen does about it

### 3.1 The two things it must not do

**It must not refuse.** Exporting a group alone is *often correct*: the
catalogue may already be in the new shop from last month's export, and
re-exporting it to get this week's orders is exactly what the owner asked to
stop doing. A screen that refuses a partial selection has removed the feature it
was asked for.

**It must not silently auto-tick.** Adding `Customers` to a selection because
`Orders` is in it takes the decision away from the only person who knows what is
already in the other shop, and quietly produces the 40-minute export he was
trying to avoid.

### 3.2 What it does instead

1. The screen computes every dependency edge **leaving the ticked set**, live,
   as he ticks.
2. Each one prints as a **consequence in words** — not "missing dependency", but
   what will happen to his rows — with one of two severities (§3.3).
3. Each warning offers **two ways forward, both explicit**:
   - a button, *Add Customers to this export*, which ticks it; or
   - a checkbox, *Customers is **already imported** into the new shop*.
4. **Start stays disabled** until every warning has been answered one way or the
   other, and the text beside the button says how many are outstanding.
5. `KBB_Export_Runner::start()` **re-checks the same thing** and refuses with the
   same sentence. A disabled button is a statement about one browser; this is the
   statement about the export.
6. Whatever he confirmed is written into `manifest.json` (§5), so the claim
   travels with the export instead of living in his memory.

The screenshots are the three states:
`docs/gk-export-shots/01-at-rest-groups-unticked.png` (a partial selection with
nothing unmet — no warning, Start live, because this is the case that must not
be obstructed), `02-dependency-warning.png` (Orders alone: two warnings, Start
greyed, *"2 dependencies above are unanswered."*), and
`03-dependency-answered.png` (one confirmed, one resolved by adding the group).

### 3.3 The two severities, and why there is exactly one red

`loses` means one thing and nothing else: **damage the import report of the run
that causes it does not describe.** `reported` means the import names it, row by
row, as it happens.

There is exactly one `loses` edge in the whole graph, and
`it names exactly one dependency whose damage the import report does not
describe` fails if a second appears. That is deliberate. A red banner that shows
up eight times is a banner nobody reads, and the whole value of this screen is
that when it goes red it means something.

**`sales` → `customers` — the one.** `docs/FV-IMPORT-AT-VOLUME.md` §6:
`OrderImporter` links an order to its shopper by billing email, synthesises a
guest customer row with a NULL `wp_user_id`, and the genuine WordPress user is
then refused as an email collision. **14 of 80 customers lost** on the measured
rehearsal.

Worth being precise, because FV says "nothing is silent" and that is also true:
*each refusal is printed*. What is not printed is the connection. The refusals
appear in the **customer** import, which may be weeks later and is a different
button press, and nothing in that report says "because an export taken on the
9th carried orders and not customers". The damage is reported; its cause is not.
That is the distinction the severity draws, and the warning says so in those
words.

Every other edge is `reported`, and the sentence names what the owner will
actually see:

- **`sales` → `catalogue`**: the line imports with its name, SKU and money
  intact, a null `product_id` and a note. Re-importing `order_items.csv` after
  the products repairs every line — `OrderItemImporter` rewrites `product_id` on
  each pass, which is checked rather than assumed.
- **`reviews` → `catalogue`**: refused by name. An orphaned review would publish
  on the homepage wall belonging to nothing, so `ReviewImporter` declines it.
- **`reviews` → `customers`**: imports under the name and email on the comment,
  not linked to the account. Re-importing relinks.
- **`coupons` → `catalogue`**: an unrestricted coupon is fine; a restricted one
  whose whole include list fails to translate is **refused**, deliberately,
  because an empty include list reads as "valid on everything" and a 50% code
  meant for three lines would go live on the shop.
- **`seo` → `catalogue`**: every row refused with *"no product with wc_id 4021 —
  import products before seo"*.
- **`addresses` → `catalogue` / `content`**: `RedirectMap` puts the row in the
  ASK pile with *"nothing in this shop carries product id 4021"*. Nothing wrong
  is written; run it again afterwards and the rows resolve.

### 3.4 The confirmation is per edge, not per selection

`sales:customers` and `sales:catalogue` are answered separately.
Confirming that the customers are already in the new shop does **not** wave
through the catalogue. Mutating `outstanding()` so that one confirmation clears
every edge goes red (M3, §6) — it was worth a mutation of its own because it is
the obvious simplification and it is the one that would let a red warning be
dismissed by answering an amber one.

---

## 4. Resume, pause, and the thing that would have broken them

`KBB_Export_Runner::stages()` is **filtered** by the selection and never
reordered. The stage order is the export's own failure mode — the catalogue is
written first, so an export that dies at 60% has the products and not just the
tags — so a selection is a subset of that sequence. `normalise()` puts the
operator's ticks back into declared order for the same reason, and a mutation
that keeps his order instead goes red.

`state['stage']` is an **index into the filtered list**. That makes one thing
dangerous: the admin screen posts the whole form with every batch, so un-ticking
a group mid-run would shorten the list under a cursor pointing into it. The
export would not stop — it would carry on at the same index, now naming a
different file, finish early with one file half written and another never
opened, and write a manifest describing neither.

`groups` is therefore **pinned at `start()`**, exactly as `skip_trashed` already
was and for exactly the same reason. `--flip_groups_after=N` in the harness does
what clicking the checkbox does, and
`it pins the group selection to the export` asserts the output is byte-identical
to an unflipped run. `batch` remains the one unpinned setting: how many rows fit
in a request is a property of the host, not of the data.

**One bar per group**, drawn with the same arithmetic the whole-export bar uses
and the same two corrections `docs/GE-WP-EXPORTER.md` §8.1 paid for: the
denominator is `max(total, written)` and the percentage is capped at 99 until
the group's last file is done. This matters *more* per group than overall: the
media stage under-estimates its own row count by about five to one, and on the
whole-export bar that is lost in a sum of fifty rows, but the *Addresses and
pictures* bar divides by that stage's own estimate. It is precisely where the
fake 100% `docs/GD-MEDIA-SIDELOADER.md` removed once would come back. The
harness records the peak each group's bar showed while that group was not done,
and `it never shows a finished bar on a group that has not finished` fails on
anything at 100.

Three states per group — `pending`, `running`, `done` — drawn differently,
because a bar that has not moved because its group has not started and a bar
that has not moved because the request died must not look the same.

---

## 5. `manifest.json`, and the contract distinction that finally has an example

`docs/WP-EXPORT-CONTRACT.md` already says:

> A file with no rows is still listed, with `"rows": 0`. **Absent from `files`
> means the plugin did not write it at all**, which is a different statement and
> the importer must be able to tell the two apart — "this shop has no coupons"
> and "this export does not carry coupons" are not the same fact.

Until now nothing produced the second case. Every stage ran on every export, so
every file was listed, and the rule had no example. **A ticked-groups export is
the example**, and that is what makes the distinction load-bearing rather than
decorative.

A skipped group's files are therefore absent from `files`, absent from `counts`,
and absent from the folder — all three, because any one alone would let the
other two drift. The reader is this shop's own
`App\Services\ImportConsole\ImportManifest::lists()`, unchanged, which
`ImportDriver` already feeds to the Import screen as `in_manifest` per entity.
The test asserts through that class rather than through the JSON.

On top of that, and permitted by the contract's *"unknown keys are ignored,
never fatal"*:

```json
"groups": {
  "selected": ["sales"],
  "skipped": ["catalogue", "seo", "coupons", "customers", "reviews", "content", "addresses"],
  "files": ["orders.csv", "order_items.csv", "refunds.csv", "order_notes.csv"],
  "assumed_already_imported": [
    {
      "group": "sales",
      "needs": "customers",
      "severity": "loses",
      "claim": "Customers was already imported into the new shop when this export was taken. The operator stated this; the plugin cannot see the other shop and did not check it."
    }
  ]
}
```

`assumed_already_imported` is the only thing in the export that a file list
cannot carry. It is a claim about the **other** shop, the plugin cannot check it
and does not pretend to, and the wording says so. What it can do is write down
that the claim was made — so months later the file answers "why does this export
have orders and no customers" instead of somebody having to remember.

And the same facts in words, in `notes`, which is what the owner actually reads
on the import screen:

```
This is a PARTIAL export. Groups exported: Orders.
NOT in this export: Catalogue (categories.csv, brands.csv, …). Those files are absent
from `files` in this manifest rather than present with zero rows, which is the
contract's way of saying the export does not carry them rather than that the shop
has none.
Orders was exported without Customers because the operator confirmed Customers is
already imported into the new shop. If that is not true, read this before importing: …
```

A row silently absent from an export is worse than a row the importer refuses.
A whole *group* silently absent is that defect multiplied by four files.

---

## 6. Mutation testing — 24 guards, 3 survived, all 24 red

Each was broken, `vendor/bin/pest tests/Feature/GeWpExporterTest.php` run, and
the guard restored.

| # | mutation | verdict |
|---|---|---|
| M1 | `start()` stops refusing an unmet dependency | red |
| M2 | `outstanding()` waves every unmet dependency through | red |
| M3 | one confirmation clears every dependency | red |
| M4 | the `sales` → `customers` dependency is deleted | red (3 tests) |
| M5 | `sales` → `customers` downgraded to `reported` | red |
| M6 | `groups` taken from the request per batch, not pinned | red |
| M7 | a skipped group's files listed with `"rows": 0` | red |
| M8 | per-group bar back to the clamped percentage | red |
| M9 | the selection stops filtering the stage list | red |
| M10 | `normalise()` keeps the operator's order | red |
| M11 | `media.csv` belongs to no group | red (3 tests) |
| M12 | the manifest stops recording a confirmed dependency | red |
| **M13** | **the screen stops posting the selection** | **GREEN — survived**, then red |
| M14 | Start pressable with a warning unanswered | red |
| M15 | every warning drawn the same colour | red |
| M16 | the per-group bars are not drawn | red |
| M17 | *Add &lt;Group&gt;* stops ticking the group | red |
| M18 | the manifest stops saying a group is missing | red |
| **M19** | **an empty selection is allowed to start** | **GREEN — survived**, then red |
| **M20** | **a request with no `groups` field exports nothing** | **GREEN — survived**, then red |
| M21 | a ticked dependency no longer satisfies its edge | red (6 tests) |
| M22 | every group reports itself done from the first batch | red |
| M23 | the manifest stops listing the skipped groups | red |
| M24 | the harness stops passing the selection | red (5 tests) |

M14 through M17 were only *possible* to write after M13 was closed; before that
they would all have survived too, because nothing in the suite loaded the page.

### 6.1 M13 — the whole screen was untested, and a grep would not have fixed it

Deleting one line —

```js
body.set('groups', ticked().join(','));
```

— so that **every export becomes a whole export whatever is ticked, silently**,
left the entire suite green. Everything this lane added to the screen is
JavaScript and the PHP suite cannot see a line of it. The same hole hid the
dependency warning that never appears, the Start button that stays pressable,
and the per-group bar that draws every group at 100%.

A grep for that one line would have closed that one mutation and nothing else,
which is the shape of fix `docs/GE-WP-EXPORTER.md` §8.3 already warns about:
fixing the one key leaves the next one added just as unprotected. So the fix is
general — the screen is now **rendered by its own code and driven in a real
browser**:

- `wordpress-plugin/harness/screen.php` renders `KBB_Export_Admin::screen()`
  with the same WordPress stubs the export harness uses, plus the half-dozen
  admin-side functions the page calls. Nothing about the markup is
  reconstructed.
- `wordpress-plugin/harness/screen-drive.mjs` drives it in Chromium: ticks and
  un-ticks groups, reads the warnings and their severities, reads whether Start
  is disabled and why, confirms one dependency and adds the other, starts a run,
  reads every bar, and reports every request body the page sent.
- `it draws the groups, the warning and the bars in a real browser, and sends
  what was ticked` asserts all of it, and skips where Playwright or Chromium is
  absent.

`fetch` is intercepted rather than served, for two reasons: there is no
WordPress here to answer `admin-ajax.php`, and it is the only way to hold the
page mid-run — a real run of this fixture finishes in under a second and there
is no moving bar to photograph. The same run takes the four screenshots.

One thing that had to be got right in the stub is worth recording, because it
looked like a hang: a `fetch` replacement that resolves **immediately** starves
the macrotask queue, since the page answers each batch by posting the next one.
The browser stops painting and the driver's own clicks never land. A 90 ms delay
fixes it and is also the honest shape — a shared host takes hundreds of
milliseconds per batch.

### 6.2 M19 and M20 — the same hole at the two ends of an ambiguous request

Both are about what a request *without* a clear selection means, and neither
could be reached by anything that existed: the harness and the browser both
always send the field.

**M20**: defaulting a **missing** `groups` field to nothing instead of
everything. A browser tab left open across the plugin update, or anything else
that predates this screen, would then export an empty folder — and write a
`manifest.json`, which in this format is the proof that the export *finished*.
The correct reading of an ambiguous request is the whole export the plugin has
always produced.

**M19**: allowing an **empty** selection to start. No files, then a manifest: an
export that says it succeeded and carries nothing.

Closing them meant making *missing* and *empty* tellable apart everywhere, which
the harness was quietly conflating (`--groups=` fell back to everything). It now
means an empty selection, `start()` refuses it by name, and
`wordpress-plugin/harness/screen.php --probe=settings` reads
`settings_from_request()` through reflection — three request shapes, and what
the runner then makes of each — so the one line no browser test can reach is
checked directly. Reflection rather than widening the method's visibility: a
private method is a real statement about the plugin's surface and a test is not
a reason to change it.

---

## 7. For files this lane may not edit

### 7.1 `docs/WP-EXPORT-CONTRACT.md` — the integrator's, and **nothing is required**

The `groups` key is already legal under the contract's own rule — *"Unknown keys
are ignored, never fatal. A newer plugin writing a field this shop does not read
must not stop an import."* — and the absent-vs-`rows: 0` distinction it leans on
is already written down and already read by `ImportManifest::lists()`. So this
lane has not diverged from the contract and does not need it changed.

What the integrator **may** want is to document the key, so the next reader of
the contract knows it exists. It is a documentation addition; the format is
unchanged and no code depends on it.

Anchor — the whole line, verified **1 occurrence** in
`docs/WP-EXPORT-CONTRACT.md`:

```
- **Unknown keys are ignored, never fatal.** A newer plugin writing a field this
```

Insert the following **immediately before** that anchor line, leaving the anchor
itself untouched. It **inserts**; no existing line changes:

```markdown
- **`groups` says which sections the export carries.** The plugin's screen lets
  the owner tick named groups — catalogue, coupons, customers, orders, reviews,
  articles, SEO, addresses and pictures — so an export need not be the whole
  shop. `groups.selected`, `groups.skipped` and `groups.files` name what was
  exported; `groups.assumed_already_imported` records a dependency the operator
  stated was already in this shop, which the plugin cannot check. This is what
  makes the rule above load-bearing: a skipped group's files are ABSENT from
  `files`, never listed with `"rows": 0`. `docs/GK-EXPORT-GROUPS.md` is the
  account.
```

### 7.2 `docs/IMPORT-RUNBOOK.md` — how to run a partial export

§9b of that document (added by Lane GE) describes the plugin as all-or-nothing.
**Inserts a new paragraph**; no existing line changes.

Anchor — the whole line, verified **1 occurrence** in `docs/IMPORT-RUNBOOK.md`
once GE's §9b is in place:

```
`manifest.json` is written LAST, so a folder without one is an export that did
```

Insert the following **immediately before** that anchor line, leaving the anchor
itself untouched:

```markdown
The screen offers the export in eight ticked groups rather than all at once, so
a second pass can carry only this month's orders. The order the new shop needs
them in is the order they are listed on the screen, and it matters: importing
orders before the customers they belong to costs customer rows. The screen will
not let you start a selection that does that until you have either added the
missing group or confirmed it is already imported here, and either way it writes
what you chose into `manifest.json`. `docs/GK-EXPORT-GROUPS.md` has the reasoning
and what each choice costs.
```

### 7.3 Nothing needed in `routes/web.php`, `bootstrap/app.php` or the admin app

This lane adds no route, no view and no Laravel class. The Import screen already
reads everything the new manifest carries.

---

## 8. What was confirmed rather than built

**Media is untouched and stays that way.** The owner named two things as already
true and asked for neither to be rebuilt: the image **links** travel inside the
CSVs (`products.image`, `products.images`, and the URLs inside descriptions),
and `App\Services\Import\MediaSideloader` — shipped 2.60.220 — downloads the
files itself in batches with its own progress page.

Grouping introduced one specific hazard there and it is checked:
`media.csv` is in *Addresses and pictures*, so a catalogue-only export does not
write it. If the links lived in `media.csv` rather than in `products.csv`, that
export would carry a catalogue with no pictures and say nothing.
`it still carries the picture links inside the CSVs` exports the catalogue
alone, asserts `media.csv` is absent, asserts `products.csv` still carries
`image` and `images` with real upload URLs in them, imports it, and asserts the
product has an image path for the sideloader to fetch. No new code.

**`wordpress-plugin/` still cannot ship, under both locks.**
`UpdateGuard::ALLOWED_PREFIXES` does not name it, so `checkPath()` answers
*"Path outside the permitted areas"* — measured for every PHP file of the
plugin, including this lane's new `class-kbb-export-groups.php`, which the
iterator picks up automatically. `BuildPackage::NEVER_SHIP` **does** name
`wordpress-plugin/` (GE's §10 anchor has been applied since), so `kbb:package`
will not put it in the zip in the first place. The test now asserts the second
lock through reflection instead of trusting the comment beside it, and names the
three new harness files explicitly — they are the ones somebody looking for
"test tooling" might move into `tests/`.

**No Composer dependency, no build step.** The one new plugin file is plain PHP
7.4; `it stays inside the PHP version its header claims` covers it with the
rest.

---

## 9. What only the owner can settle

1. **Whether *Addresses and pictures* should be two groups.** They are together
   because they answer the same question and have the same dependencies. If he
   wants to re-run the redirect map without re-listing 2,000 images, that is a
   split — cheap to make, and it is his call, not a correctness one.

2. **Whether a confirmed dependency should be sticky.** Today the tick is per
   export: if he exports orders on Monday having confirmed the customers are
   already in, he ticks it again on Tuesday. That is deliberate — the claim is
   about the other shop's state *at that moment* and the other shop can change —
   but if he finds it tiresome the alternative is to remember it and show what
   was remembered, which is a different and slightly worse guarantee.

3. **Whether the new shop should act on `groups`.** It records it; nothing reads
   it yet. The Import screen could print *"this export carries Orders only; the
   operator stated Customers were already imported"* above the run, which would
   put the claim in front of whoever presses Import rather than only in the
   file. That is a Laravel-side change in a lane that owns that screen, and it
   is worth doing.

4. **The real run.** Everything here was measured against WordPress-shaped MySQL
   tables with a `$wpdb` stub — `docs/GE-WP-EXPORTER.md` §9 lists what only the
   live site can answer, and this lane adds nothing to that list. The groups are
   a property of the file set, not of the shop.
