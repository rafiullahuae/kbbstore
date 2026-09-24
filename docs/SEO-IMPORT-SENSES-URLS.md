# The import senses the URLs and fixes them · round 2

Lane SEO, round 2. Companion to `docs/SEO-URL-MAP.md`, which is the map itself.

The owner's words: *"we have the proper import export function, that should also
work fine and sense the urls auto and fix."*

---

## 1. Which URL shapes the old install actually served

Settled from the export's own data, the way `category_base` was settled — not
from a habit about what WooCommerce sites usually do.

| Shape | Did the old site serve it? | How that is known | What this shop does |
|---|---|---|---|
| Category archives, flat at the root | **Yes** | `woocommerce_permalinks['category_base']` is the empty string, carried verbatim in `manifest.json` | Forwarded by the shop itself since round 1 — no rows needed |
| Products `/product/{slug}/` | **Yes, and unchanged** | same permalink settings; the importer never rewrites a slug | Nothing to do |
| Articles at the root `/{slug}/` | **Yes, and unchanged** | the Phase 9 seed records the owner confirming it | Nothing to do |
| Pages | **Yes, and unchanged** | seven literal root routes | Nothing to do |
| Brand archives | **No — none was ever served** | the exporter writes a row per brand term with an **empty** `permalink` and a note that WordPress returned no archive URL for that taxonomy | Nothing to land. No rows proposed at all |
| Product tags `/product-tag/{slug}/` | **The export says, per term** | a taxonomy with no public archive gets an empty `permalink`; one with an archive gets a real URL from `get_term_link()` | This shop has **no tag archive**. Now a question with a true reason |
| Attribute archives `/pa_size/50ml/` | **The export says, per attribute** | `attribute_public` in `attributes.csv`; the permalinks stage reads the same flag | This shop has **no attribute archive**. Same question |
| Paginated archives `/toners/page/2/` | **No rows exist** | the exporter writes one row per OBJECT, never one per page of an archive — `post_rows()` and `term_rows()` both walk rows, not pages | Nothing built. Not guessed |
| Attachment pages | **No rows exist** | `attachment` is in `KBB_Export_Stage_Posts::NOT_CONTENT`, so the exporter never emits one | Nothing built. Not guessed |
| `?p=123` | Yes, WordPress always serves it | — | **Structurally impossible here.** The redirect check compares against the request path, which excludes the query string. Needs an `.htaccess` rule |

**The point of the table is the middle column.** Three shapes are ruled out
because the export contains no row for them, and two more are answered per term
by the export rather than by anybody's judgement. Nothing here was built for a
shape that might not have existed.

---

## 2. What changed, and the number that says why

`SourceReachability` decides what this shop does at an address, and it asks the
router and the tables. Round 1 taught `CheckRedirects` to **derive** a 301 for
the fifteen old flat category addresses, from the global pipeline, before the
router — so the router's answer stopped being the shop's answer.

Measured on a tree with all fifteen categories imported:

```
verdict('/toners/')  →  notfound
   "PageController::post() throws a 404 for a slug with no published post,
    so the redirect is reached"
```

Every word of that was true when it was written. The address answers 301.

**The consequence, in buckets:**

| | before | after |
|---|---:|---:|
| `migrate` — rows the import would write | **38** | **8** |
| `ask` — questions for the owner | **11** | **0** |
| `discard` | 8 | 49 |

Thirty of those thirty-eight were rows restating a redirect the shop already
makes for itself.

### Why a restated row is not merely untidy

This is the part that makes it a defect. **The shop's answer is derived, so it
follows the category when the owner renames or re-parents it. A written row does
not** — and the row is the one that wins, because the table is read before the
router.

Measured, in `SeoImportSensesUrlsTest`:

1. Import writes `/toners/ → /product-category/skincare/toners/`.
2. The owner renames the parent `skincare` to `skin-care`. Ordinary edit.
3. The shop's derived answer moves with it, on its own, with nothing applied.
4. The row does not. It now points at a path that is itself a 301 — **a
   one-hop redirect has become a two-hop chain**, on all fifteen.
5. Rename the leaf as well and the row is a **301 onto a 404**.

**So the same reasoning that said round 1 must not seed fifteen rows in a
migration says round 2 must not let the importer write them either.** A
migration resolving `/cleansing-oils/` at apply time bakes in a destination the
real import then contradicts, permanently and invisibly; an import writing the
same row bakes in a destination the owner's next tidy-up contradicts, the same
way. The derived answer is the one that cannot go stale, and it is free.

---

## 3. The decision `GP-ADDRESSES-LAND.md` §13.7 handed this lane

That document left it open: *"some of those discards are still right, and some
are now wrong. This is the one that needs a decision, not just an edit."*

Neither blanket answer is right, because **"the shop already moves this address"
is two different situations wearing one word.** The destination tells them
apart:

- **The shop already sends it exactly here.** Nothing to decide, and a row
  would be the only one of the two that can rot → **discard**, with that said
  on the row.
- **The shop sends it somewhere else.** A real decision with a real cost, and
  the owner's to make → **ask**, as before.

The comparison is made on **raw paths**. `Url::to()` adds the base path and the
locale segment and `redirects.target` never carries either, so on the staging
mount the prefixed form would have made every agreement look like a
disagreement — all fifteen back into the ask bucket, on the one deployment shape
where nobody would re-check. That guard survived the first mutation pass and has
a test of its own now (§6).

---

## 4. The ask bucket stopped lying

`currentPathFor()` answers for products, categories, posts, pages and brands,
and returned null for everything else — and everything else got:

> nothing in this shop carries product_tag id 47 — either it was never imported,
> or it is in the discard bucket and this address should 404 on purpose

For a product that is true and useful. For a `product_tag` or a `pa_*` term it
is **false**: the term very probably was imported. What this shop lacks is a tag
*archive*, by design, exactly as U-05 says of brands — and no amount of
importing produces one.

**It is a volume problem, which is why it earns a question of its own rather
than a better sentence.** The exporter writes one permalinks row per term of
every `pa_*` taxonomy and every `product_tag`; on a six-year-old shop that is
hundreds of rows, each carrying a false reason, on the one screen the owner is
asked to make decisions from.

New question, last in the list so no card on the screen moves:

> **The old site published a kind of address this shop does not have.**
> the old site served a product_tag archive at this address, and this shop has
> no product_tag archive at all — there is no route for one, the same way U-05
> says there is no brand archive. This is not a row that failed to import: it is
> an address with no equivalent here. Point it somewhere by hand on Store → SEO
> & Meta → Redirects & 404s, or let it 404

Not decidable — there is no destination to accept, and accepting would write
`target = ''`.

---

## 5. Export then re-import, twice

Driven through `POST /admin-api/urls-media/redirects`, which is what the screen
posts to.

```
first run    written 8   conflict 0
second run   written 0   conflict 0
redirects table:  byte-for-byte identical
no target contains "/product-category/product-category/"
```

**`written 0` and not "written the same 8" is the assertion that matters.** An
equal count would have passed on a build that rewrote all eight rows to the
values they already held — idempotent in its result, not in its behaviour, and
it would hide a destination being recomputed differently.

Three further stabilities are pinned:

- **The proposal itself** is byte-identical across two runs over identical data.
  A map whose reasons move between runs is one nobody can review.
- **Writing the map does not change what the map proposes.** This is why
  `SourceReachability` consults the derived rule and **not**
  `CheckRedirects::lookup()`: lookup() reads the `redirects` table first, which
  is the table the map decides what to write into, so the second run would see
  the first run's output and answer differently.
- **The redirect pass never edits an article body.** Asserted byte-for-byte,
  because a body is the one artefact of this migration that cannot be
  re-derived from anything.

The body-rewriting half is idempotent by construction and already measured —
`DocumentMediaRewrite`'s header argues it and `GbUrlsAndMediaTest` ("A second
pass has nothing left to do") measures it. It is cited here rather than
re-proved.

---

## 6. In-content links: the decision holds, and round 1 strengthened it

`DocumentMediaRewrite` deliberately leaves an `<a href>` naming a **page** alone:

> it belongs to `RedirectMap`, whose answer is a row in `redirects` the owner
> approves, and rewriting it here would silently make that decision for him with
> no row to show for it and no way to take it back.

The brief asked whether that still holds now the rows derive themselves. **It
holds, and the case for rewriting got weaker rather than stronger** — which is
the only kind of argument worth changing a decision on, and this one argues for
leaving it alone:

1. **The link already works, permanently, with no row and no rewrite.** An
   article body linking to `/toners/` lands on the real archive in one hop.
   Measured, with the redirects table empty.
2. **A derived redirect follows the category. A rewritten body does not.**
   Rewriting `/toners/` to `/product-category/skincare/toners/` freezes today's
   nesting into the owner's own prose. Re-parent the category tomorrow and the
   body points at a stale address — and unlike a row, a body cannot be
   recomputed from anything.
3. **The cost did not move.** It is still an irreversible edit to the owner's
   content, for the saving of one 301 that the shop makes for free.

So: no change, and the reason is not "we could not" but "it would make the shop
worse". Pinned as a measurement rather than as a claim.

---

## 7. Pins advanced in other lanes' tests, and why

Five, all the same deliberate change, each with the reason written on it:

| test | was | now |
|---|---|---|
| `ImportUrlAndMediaTest` · nested address | ASK | DISCARD — the shop already sends it exactly there |
| `ImportUrlAndMediaTest` · flat root `/face-cleansers/` | MIGRATE | DISCARD — round 1 derives it |
| `ImportUrlAndMediaTest` · two rules claiming one address | `[ask, migrate]` | `[ask, ask]` — the winner now **disagrees with the shop**, which is a new and real question |
| `ImportUrlAndMediaTest` · rollback | turned on `/face-cleansers/` | turns on `/makeup-removers/`, which is not one of the fifteen and is still written |
| `GbUrlsAndMediaTest` · category-nesting | ASK | DISCARD — and the test's own **name** has said "discards" all along |
| `ImportAddressQuestionsTest` · every question has a code | fixture produced `already-redirects` from a self-agreeing nesting row | fixture now produces a genuine **merge** disagreement |

Two of those have now moved **twice** — DISCARD → ASK → DISCARD — and both moves
are kept in the comment, because the pair is the clearest record in this
repository of a correct piece of reasoning outliving its premise.

---

## 8. What is left, and who has to do it

1. **`?p=123` needs an `.htaccess` rule** if it carries traffic. Unchanged from
   round 1, and still the only thing here that cannot be done from inside the
   application.
2. **Upload the addresses group LAST**, after the catalogue. The map resolves
   each old address against rows this shop carries.
3. **Re-run the map after any package that adds a root address.** A published
   article at a category's slug takes that address, correctly, and the map
   reports it rather than fixing it.
4. **`SourceReachability` is `final`**, so `RedirectMap`'s constructor note
   about being "injectable only so a test can pin the reachability verdicts"
   cannot be used — a test reaching for it gets `cannot extend final class`.
   Left final on purpose and the note corrected; the disagreement cases are
   built from a real `category_redirects` merge instead, which is better
   evidence than a stub.
5. **Round 1's open finding stands, unchanged and still held:** a slashless
   variant of a hand-written row still 404s. The owner said he will decide when
   that ships.

---

## 9. One edit for the integrator, in a file this lane may not touch

`app/Http/Controllers/Admin/UrlsMediaApiController.php` belongs to another lane.
Its headline sentence on **Store → Import → Addresses & pictures → Old
addresses** is now false, and it is visible in the 1280 screenshot: it says the
nesting proposals are *"questions rather than discards"*, which is exactly what
round 2 reversed. A screen whose own words contradict the numbers under them is
the shape this repository has paid for before.

**ANCHOR** — occurs exactly once, verified by `grep -cF`:

```php
                'note' => 'The redirects table is read before the router now, so a row here fires even on an '
                    .'address this shop already answers — which is why those are questions rather than '
                    .'discards. Every question below says what writing it would do; answer them in bulk or '
                    .'one at a time, and Undo puts any of them back.',
```

**REPLACEMENT:**

```php
                'note' => 'The redirects table is read before the router now, so a row here fires even on an '
                    .'address this shop already answers. Where this shop already sends an old address to '
                    .'exactly the place the map would, there is nothing to decide and nothing is written — '
                    .'the shop follows the category if you rename it and a stored row would not. A question '
                    .'means the two disagree. Every question below says what writing it would do; answer them '
                    .'in bulk or one at a time, and Undo puts any of them back.',
```

Nothing depends on this: the buckets, the rows and the tests are all correct
without it. It is the sentence above them that is out of date.
