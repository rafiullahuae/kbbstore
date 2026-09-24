# `/skincare-sets/` — the duplicate, answered

Lane S2, round 2, 2026-09-24. Closes `docs/SEO-BUILD-PLAN.md` Part II item 8,
which said *"one person, one look"* and correctly refused to guess.

## The question

`SEO-COMPETITIVE.md` §14 observed, on the **live WooCommerce site**, that both

- `https://kbeautybliss.com/skincare-sets/` and
- `https://kbeautybliss.com/product-category/skincare-sets/`

are indexed, with near-identical titles differing only by year ("…for Women in
2024" / "…in 2025"). Item 8 asked three things: does the Laravel port serve both
paths, does the redirects table already cover it, or is it live?

## The answer, in one line

**The port does not have the duplicate — it serves one of the two addresses and
**404s** the other. But it does not redirect the other either, so the indexed
legacy URL becomes a 404 at cutover instead of consolidating. The
duplicate-content bug is fixed by construction; the link-equity half is not, and
nothing in the repo fixes it.**

## How that was established

Not by reading. By fetching, against a migrated database with a `skincare-sets`
category present, through the application's own HTTP kernel.

| Address | Status | `Location` |
|---|---|---|
| `/skincare-sets/` | **404** | — |
| `/skincare-sets` (no trailing slash) | **404** | — |
| `/product-category/skincare-sets/` | **200** | — |
| `/shop/?cat=skincare-sets` | **200** | — |

And on the two that answer:

```
/product-category/skincare-sets/
    <link rel="canonical" href="http://localhost/product-category/skincare-sets/">
    <title>Skincare Sets · K-Beauty Bliss | KBB</title>

/shop/?cat=skincare-sets
    <link rel="canonical" href="http://localhost/shop/">
    <meta name="robots" content="index, follow">
    <title>Shop all · K-Beauty Bliss | KBB</title>
```

**All OBSERVED**, from a scratch test run in this worktree on the current tip.
The test was deliberately **not** kept: every assertion in it is about a defect
that does not exist in this codebase, and a test asserting "this 404s" would
pin the very behaviour §"What would fix it" below says should change.

### The three findings inside that table

**1. There is no second address. The port serves one.** No route anywhere
matches `skincare-sets` — searched the whole registered route collection, zero
hits. `/skincare-sets/` is a single path segment, so it falls through to the
root-level article route, which looks for a post slugged `skincare-sets`, finds
none, and 404s. This is the documented behaviour of that route and it is exactly
what `App\Support\LegacyCategoryUrls`' header describes happening to all fifteen
legacy flat category paths (OBSERVED — `app/Support/LegacyCategoryUrls.php:23-29`).

**2. `/shop/?cat=…` is a third address for the same products and it is
correctly handled.** It answers 200 and canonicalises to `/shop/` — not to
itself — so the facet parameter does not create a second indexable document.
It is linked from the homepage (OBSERVED — `resources/views/store/home.blade.php:390`).
Nothing to do here; noted so nobody "fixes" it.

**3. The redirects table ships 10 rows and none of them is a category.** Read
directly off a migrated database: **all ten are journal rows**, two spellings
(with and without trailing slash) of the five real articles, written by
`2026_09_14_160000_seed_phase9_post_url_redirects`.

```
/blog/heartleaf-extract-transforming-k-beauty-skincare/  -> /heartleaf-extract-…/
/blog/heartleaf-extract-transforming-k-beauty-skincare   -> /heartleaf-extract-…/
…and four more articles, same pair shape
```

**There is not one row for `/skincare-sets/` or for any of the other fourteen
legacy flat category paths.** Grepping every migration for a write to the
`redirects` table confirms it: the Phase 9 post seed is the only one.

## So is it live?

**Neither "already answered" nor "live", and the distinction matters.**

- **The duplicate-content defect: answered.** The port cannot serve the same
  catalogue at two indexable addresses, because it only serves one. Part I's
  claim that this Laravel port does not have the duplicate-content class of bug
  is **confirmed** for this case.
- **The legacy URL: live, and it degrades at cutover.** `/skincare-sets/` is in
  Google's index today because the WooCommerce site serves it. On the day the
  port becomes the live site, that indexed URL starts returning **404** rather
  than **301**. Whatever ranking and links the old address accumulated are
  dropped instead of being passed to `/product-category/skincare-sets/`. The
  same is true of the other fourteen.

That is a smaller problem than a duplicate and a bigger one than nothing.

## What would fix it, and whose file that is

The mechanism already exists and is already correct. `App\Services\Import\RedirectMap`
proposes exactly the needed row — rule `legacy-root-category`, both slash
spellings, target `/product-category/{slug}/` — and its reason string
distinguishes the fifteen addresses **corroborated** from `LegacyCategoryUrls::PATHS`
from ones merely inferred (OBSERVED — `app/Services/Import/RedirectMap.php:230-270`).
`docs/GP-ADDRESSES-LAND.md` §3.3 records all fifteen measured end to end, going
from 404 to `301 → /product-category/{slug}/`.

**The rows are produced by running the WordPress import and applying its
`migrate` bucket. They are not seeded by any migration, so a server that has not
run the import has none of them.** That is the whole gap.

Three ways to close it, in the order I would rank them:

1. **Run the import and apply the redirect bucket.** Zero new code. The rows are
   already derived, already corroborated against `permalinks.csv`, and already
   measured in `GP-ADDRESSES-LAND.md` §3.3. **Recommended.** It is also the only
   option that fixes the other fourteen at the same time.
2. **Type fifteen rows** in **Store → SEO & Meta → Redirects & 404s**. No code,
   no import, but fifteen chances to mistype a path, and it is the same work the
   import does for free.
3. **A seed migration**, on the model of `2026_09_14_160000_seed_phase9_post_url_redirects`,
   writing both spellings of each of `LegacyCategoryUrls::PATHS`. Defensible —
   the fifteen are a fixed, owner-confirmed list — but it duplicates logic that
   `RedirectMap` already carries correctly, which is how two sources of truth
   start.

**Whose file:** option 1 is the owner's, at the admin screen. Option 3 would be
a new file in `database/migrations/` — no lane in this round's ownership list
holds that directory, but `RedirectMap` itself is `app/Services/Import/**`,
which is **Lane A's**. Anyone doing option 3 must not edit `RedirectMap`.

**This lane wrote no code for any of the three.** Item 8 asked for an
investigation and said *"no code until that question is answered"*. It is
answered; the code is somebody's scheduling decision, not this lane's to make.

## One thing that would have made this a 20-minute job into a permanent guard

There is no test asserting that the addresses the old site published still
resolve on the new one. `GP-ADDRESSES-LAND.md` §3.3 measured it **once**, by
hand, with `curl`, against a server that had the rows. Nothing re-checks it, so
the fifteen can silently go back to 404 — and on the current tip, without the
import, they are at 404 right now.

That test belongs with whoever owns the redirect map, and it is worth more than
it looks: the failure mode is fifteen indexed URLs quietly dying at cutover,
which nothing on the storefront looks wrong about.
