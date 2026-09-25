# Lane Q11 — the Reply-To that saved nothing, the order email that was already flat, and where the category walk really is

Three tasks, and two of the three turned out to be a different shape from the
brief. Both corrections were established by driving the code before anything was
changed, and both are recorded here with the measurement rather than the
conclusion.

---

## 1 · A mistyped Reply-To saved "successfully" and kept the old value

### What it actually did, driven first

Through the real endpoint, with a real owner session, against the parent
revision:

```
POST /admin-api/mail {"settings":{"mail_reply_to":"not an address"}}
  → HTTP 200   {"ok":true,"configured":true,"missing":[]}
  → settings.mail_reply_to still 'good@example.com'
```

And in a real browser, at both widths: the owner types the new address, presses
**Save changes**, and the screen answers **"Mail settings saved"** — while
`renderMail()` repaints the box with the address that was already there. The
only evidence anything went wrong is the box quietly reverting, and a box that
reverts after a success toast reads as a redraw, not as a refusal.

`MailSettings::addressOrDrop()` returns `null` to refuse;
`ModuleSchema::write()` reports the refusal in its `rejected` map;
`MailSettings::save()` threw that map away.

### The brief's proposed fix is not the fix, and that was measured

Round 4 §11 named it as "one line plus an error on the screen": give
`mail_reply_to` an `email` entry in `MailApiController::$checks`, the way
`mail_merchant_address` already has one.

**`$checks` is Laravel's `email` rule. The writer is
`filter_var(FILTER_VALIDATE_EMAIL)`. They disagree**, over a 22-address corpus
driven through both:

| spelling | Laravel `email` | the writer | before this lane |
|---|---|---|---|
| `a@b` | accepts | **refuses** | 200 `{"ok":true}`, old address kept |
| `a@example` | accepts | **refuses** | 200, old address kept |
| `a@127.0.0.1` | accepts | **refuses** | 200, old address kept |
| `"quoted local"@example.com` | accepts | **refuses** | 200, old address kept |
| `ünïcode@example.com` | accepts | **refuses** | 200, old address kept |
| `a@exämple.com` | accepts | **refuses** | 200, old address kept |

So **`mail_merchant_address` had the identical silent drop for all six**, despite
being the field round 4 treated as covered *because* it is in `$checks`. The
proposed line would have moved the silence from one address box to the other.

**And the obvious second argument against it is wrong**, which is recorded
because it was believed for an hour. The disagreement runs the other way too —
`'a@b.co '`, `' a@b.co'` and `"a@b.co\n"` are refused by `email` and
trimmed-and-stored by the writer — so the rule looks as though it would *also*
have broken a save that works. It would not: **`TrimStrings` runs before the
controller** and all three arrive already trimmed. Driven through the endpoint
they answer 200 and store `a@b.co` with the rule and without it.

### What was done

`MailSettings::save()` returns the `rejected` map — the hand-off
`PayShipRules::save()` and `BuildMyRoutine::save()` already make, and which this
class was **the only one of the four `ModuleSchema::write()` callers in the
application** not to make. `MailApiController::save()` answers **422** with
`ok: false` and a sentence naming the box:

> “Reply-To address” is not a valid value and was NOT saved — what was stored
> before is unchanged. Everything else on this screen was saved.

`ok: false` + `error` is the shape `PayShipRulesApiController` already answers
with and the shape the console's own save handler already renders
(`toast('Could not save: ' + d.error)`). **No console change**, and
`resources/views/admin/app.blade.php` was not touched.

**`$checks` is left exactly as it was.** It refuses before anything is written,
which is a better answer where it fires, and every rule in it still fires first.
**Nothing stored moved for any value that is accepted today** — the refusal is
reported, not made atomic, because making it atomic would stop the siblings of a
refused key being written, which is what happens today.

### Where it sits in the admin

**Store → Mail → Other settings → Reply-To address**, and the same refusal now
reaches **Store → Mail → Who the message comes from → New-order alerts to** for
the six spellings `$checks` waves through. **No control was added, removed,
renamed or moved, and no setting changed the value it ships at.**

### Measured in Chromium, 390px and 1280px

`tests/browser/lane-q11-mail-reply-to.mjs`, run against both revisions.

| | 390px before → after | 1280px before → after |
|---|---|---|
| `scrollWidth` / `innerWidth`, idle | 390 / 390 → 390 / 390 | 1280 / 1280 → 1280 / 1280 |
| `scrollWidth` / `innerWidth`, on the refusal | 390 / 390 → 390 / 390 | 1280 / 1280 → 1280 / 1280 |
| overflow, any capture | false → false | false → false |
| what Save answers to `replies at kbeautybliss.com` | **“Mail settings saved”** → **“Could not save: “Reply-To address” is not a valid value and was NOT saved — what was stored before is unchanged. Everything else on this screen was saved.”** | same |
| the stored address afterwards | `replies@kbeautybliss.com` → `replies@kbeautybliss.com` | same |

**The idle screens are byte-identical between the two revisions**, at both
widths, in both the before and after states — same md5, same size:

```
5b2c96eeb4e24ae2026f22cbda4a6345   q11-{before,after}-{1-before,3-after}-390.png
60fa5eca68071731caeb189783d5a2ab   q11-{before,after}-{1-before,3-after}-1280.png
```

Four captures on each side collapsing to two digests is rule 1 proved: nothing on
this screen moved except the sentence a refused save produces. Only
`*-2-refused-*` differs, which is the change.

Shots in `docs/q11-shots/`.

### The test, and the mutations

`tests/Feature/MailRefusalIsReportedTest.php`, nine cases. **Fourteen mutations
run**, listed in the file with what each printed. The three that mattered:

- **8** — round 4's proposed fix applied *instead* of this one: **4 failed**,
  printing `mail_reply_to answered success for 'a@b'`, and the same for
  `mail_merchant_address`. The measurement that the one-liner is not sufficient.
- **11 / 11b** — the class scan pointed at a method that does not exist, with the
  floors removed (**9 passed, GREEN**, asserting nothing over zero callers) and
  with them kept (**1 failed**). The pair that proves the floor and not the loop
  is what makes the scan an assertion.
- **13** — the blank box posted as `null` instead of `''`: **9 passed, GREEN**,
  and not a defect. Recorded because a refusal report that turned an empty box
  into an error would be the worst possible regression on this screen.

---

## 2 · The order email does **not** load its lines one at a time

### The brief, and the measurement that contradicts it

> `OrderEmailPresenter:460` reading `$order->items` lazily, and
> `OrderItem::$product` — 14 and 6 lazy reads in the suite's own census.

**A lazy `hasMany` read is ONE query returning every row. It is not one query per
row.** The census counts relation *reads*, so 14 reads is 14 orders each asking
once. Driven at four sizes, on the parent revision, before anything was changed:

| lines on the order | 1 | 2 | 5 | 10 | slope |
|---|---|---|---|---|---|
| lines the presenter produced | 1 | 2 | 5 | 10 | — |
| `present()` statements | **3** | **3** | **3** | **3** | **0.00** |
| whole-send statements | **6** | **6** | **6** | **6** | **0.00** |

And `OrderItem::$product` is not read by an order email at all. No email template
touches the catalogue — every word and number on the receipt is a snapshot on
`order_items`. Asserted separately: a send makes **zero** `products` queries.

The six statements a send makes: the order, its lines, the settings row, the mail
credential, and the two writes to `mail_deliveries`.

### What changed, and it is a tidying rather than a repair

`OrderEmailPresenter::present()` and `::ledgerWidth()` now say
`$order->loadMissing('items')` out loud. Identical cost — `loadMissing()` is a
no-op when the caller already loaded them (`OrderMailer` does, on all five of its
send paths) and is the same single query when it did not. What it buys is that
the presenter no longer depends on its callers having remembered, and that it
survives a `preventLazyLoading()` run, which removes one of the six obstacles
`docs/q10-component-load-contract.md` priced and declined.

The lazy-read recorder over a build-and-send printed `App\Models\Order::$items
=> 2` before and **`(none)`** after.

### The guard: slope **and** total, because one of them has a hole

`tests/Feature/OrderEmailQuerySlopeTest.php`, four cases. Both slope and total
are asserted, and the reason is a mutation that came back green: replacing
`$order->items` with `$order->items()->get()` adds one statement to *every*
render, so the slope stays 0.00 and a slope-only guard sees nothing. Slope
catches what grows with the basket; the total catches what is simply added.

All three measurement traps are handled and each is demonstrated by a mutation:

| trap | mutation | what it printed |
|---|---|---|
| `Setting::map()`'s process static | warm-up removed | `presenting 2 lines cost 3 statements against 5 for one` — the first render of a process is 2 dearer for `present()` and 3 dearer for a send |
| `scoped()` bindings surviving requests | `forgetScopedInstances()`/`forgetMemo()` removed | totals read 2 and 4 instead of 3 and 6 — a budget the application does not meet |
| `DB::listen()` never removed | the counter rewritten to use it | **GREEN**, and the reason is written down: a listener registered *inside* the window and read at the end counts its own window correctly; what misreports is a listener registered once with a running total |

The one that catches the real future defect: **a product thumbnail added to
`items()`** — the plausible request — goes red four ways, at slope 1.00 per
line, naming the query.

Nine mutations, in the file. **No screenshot for this task, and that is the
honest answer rather than a gap: nothing a customer or an operator sees moved.
The deliverable is the numbers above.**

---

## 3 · `Category::buildPath()` — investigated, not migrated

> 1,272 of the 1,325 lazy reads in the suite-wide census … fixing it needs a
> recursive CTE or a materialised path column, which is a migration.

### It needs neither, because the materialised column already exists

`categories.path` is shipped, is recomputed on every structural write by
`Admin\CategoriesApiController` (four call sites) and by
`Import\CategoryImporter`, and both `Category::url()` and
`CategoryPath::canonicalPath()` read it **first**. `buildPath()` is the fallback
for a row whose `path` is null. **No migration is owed; the fix the census called
for was built before the census ran.**

### Where the walk actually runs — a full instrumented suite run

`buildPath()` made to log its caller, full suite, **5,970 passed**, **2,024
entries**:

| caller | entries | share |
|---|---|---|
| `store/home.blade.php:391` — the category tile loop | **1,821** | **90%** |
| `ShopController` ×3 + `CategoryPath::resolve()`, per archive page | 96 | 5% |
| admin category screens (structural writes) | 12 | — |
| `ProductController::breadcrumbTrail` | 6 | — |
| `SearchController` | 5 | — |

**So the brief's two alternatives are both true at once**, and they cost
completely different things.

### The per-page walk: depth − 1 queries, once, and only on a null path

The real category archive, five depths, both states:

| depth | 1 | 2 | 3 | 4 | 5 | slope |
|---|---|---|---|---|---|---|
| `categories` queries, `path` **set** | 4 | 4 | 4 | 4 | 4 | **0.00** |
| `categories` queries, `path` **NULL** | 4 | 5 | 6 | 7 | 8 | **1.00** |

Once per page and **not three times**, although three call sites ask —
`CategoryPath::resolve()`, `ShopController::breadcrumbTrail()` and
`::absoluteListingUrl()`. The first walk loads the chain onto the instance the
other two are handed. **Production nests four deep, so the worst real case is
three extra single-row primary-key reads on one page, and only on a row whose
`path` the admin screen has not yet recomputed.** That is not worth a migration,
and saying so is the deliverable.

### The loop: 1,821 walks, **zero queries**, and ten wrong URLs

`HomeController:133` selects `'id', 'name', 'slug'` for the tiles.

- `path` is not among them → `url()` falls through to `buildPath()` on all ten
  tiles;
- `parent_id` is not among them either → `$this->parent` is a `belongsTo` on an
  absent key, answers `null` **without a query**, and the walk stops at the leaf.

**The same omission causes the speed and the wrongness.** Measured on a real
`GET /`: the tile for a category nested two deep links to
`/product-category/q11h-mists-…/`, and `CategoryPath::resolve()` answers
`redirect` → **301** → `/product-category/q11h-skincare-…/q11h-mists-…/`. The
`path` column was correct the whole time; the homepage just does not select it.

Nothing on the shop as shipped is affected — every seeded category is a root. It
bites a shop whose tree is nested, which is what the WooCommerce import produces
("Nested to four levels", `PagesApiController`).

### The fix, which is one line in a file this lane does not own

```php
// app/Http/Controllers/Store/HomeController.php:133
->select('id', 'name', 'slug')   →   ->select('id', 'name', 'slug', 'path')
```

**Applied as a mutation and measured**: the tile href becomes the full nested
path, the 301 becomes a 200, and the depth-slope assertion stays green — **no
extra query**. Ten 301s become ten 200s for free.

`app/Http/Controllers/Store/**` is not this lane's ground, so the defect is
**pinned as it is** in `tests/Feature/CategoryPathWalkCostTest.php`, with the
fix named and the advance instruction in the failure message — the way
`RedirectMiddlewareTest` pinned the trailing-slash 301 before the lane that owned
that controller closed it. The day somebody adds `path`, the pin reddens and
prints:

> the homepage category tile now links at … which resolves straight through —
> HomeController's select has gained `path` and this defect is closed. Advance
> this pin: expect 'ok', and quote the old assertion in the comment above.

Seven mutations, in the file.

---

## Suite

```
mysql -u root -e "CREATE DATABASE IF NOT EXISTS kbb_wp_q11;"
KBB_WP_DB=kbb_wp_q11 vendor/bin/pest --compact
```

`KBB_TEST_DB` left unset, which is what CLAUDE.md prescribes for the default
suite. Counts in the lane report.

No route was added, so **no `clear_caches_*` migration is owed** for this round.

---

## Found and NOT fixed

- **`HomeController:133` omits `path` from the category-tile select**, so every
  homepage tile for a nested category links at an address that 301s. One line,
  zero queries, measured above. Another lane's file.
- **A 422 from `MailApiController::$checks` reaches the owner as "Could not
  save: 422".** The console reads `d.error || r.status`, and Laravel's validation
  body carries `message`/`errors` and no `error` key — so the rules that already
  work report a bare status code where the new path reports a sentence. Not
  touched: changing the shape of a response that already refuses is a change to
  something that works, and `MailScreenDesignTest` and the recorded payload
  fixture sit on that screen.
- **Nine of the sixteen Mail fields are `UNCAPPED` in `MailSettings`** and are
  bounded only by `$checks`; the seven with a `max` truncate silently rather than
  refusing, so a 600-character signature is cut to 500 and the screen says Saved.
  That is a different shape from this round's defect — truncation, not refusal —
  and closing it is a behaviour change on live fields.
