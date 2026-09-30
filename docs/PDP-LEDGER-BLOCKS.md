# Lane PDP2 · what the integrator has to apply: nothing, and the two things that are not nothing

This is the shape `docs/BG-ADMIN-APP-BLOCKS.md` established — an exact ANCHOR and
an exact REPLACEMENT, each occurring **exactly once** in its target. This lane
has **no route to mount and no admin screen to include**, so the two usual files
are untouched; what follows are the two edits that *are* needed and the one
block the owner's answer on the mobile type will need.

| # | Where | What | Needed |
|---|---|---|---|
| 0 | `routes/web.php` | — | **no edit** |
| 0 | `resources/views/admin/app.blade.php` | — | **no edit** |
| 1 | the package's `update.json` | declare the `clear_caches_*` migration | **yes** |
| 2 | the package's file list | `public/build/manifest.json` **and** the new bundles — **CSS *and* JS this round** | **yes** |
| 3 | `resources/css/kbb/kbb-product.css` | the mobile type, once he picks a letter | only if he picks A or C |

---

## Block 1 · the package must declare its migration

A `clear_caches_*` migration is needed because `resources/views/store/product.blade.php`
is **compiled** on the server. `update.json` needs six keys and `UpdateRunner`
keys off `migrations`, which is the **only** thing that decides whether
migrations run at all — `hasMigrations()` never looks at the files. That is
CLAUDE.md's first landmine: five packages shipped eight migrations that were
copied to the live server and never ran.

Build it with `php artisan kbb:package <version> --since=<ref>`, never by hand.

**No route was added by this lane, so the route cache is not the reason.** The
view cache is.

---

## Block 2 · the manifest travels with the bundle, or the change is invisible

`@vite('resources/css/kbb/kbb-product.css')` resolves the hashed filename out of
`public/build/manifest.json`. Both of these are in the diff and **both** must be
in the package:

```
public/build/manifest.json                      (modified)
public/build/assets/kbb-product-<new hash>.css  (new)
public/build/assets/kbb-product-CJbrmkEW.css    (deleted — may be left behind)

ROUND 3 ADDS THE JAVASCRIPT BUNDLE, which earlier rounds did not touch:
public/build/assets/app-BLfAa6WG.js             (new)
public/build/assets/app-jOqRtoT7.js             (deleted — may be left behind)

The hashed names are in `git status`; read them from there rather than from this
document, which cannot be right about a content hash after the next edit.
```

**The JS bundle is not optional this round.** The bundle price block's fix is
half template and half `pdp.js`: ship the template without the new `app-*.js` and
every variant row carries `data-was`/`data-off` that nothing reads, so the block
goes on printing the stale strike and the **wrong discount badge** — the exact
defect, with the fix apparently applied. `BuiltAssetNamesAreStableTest` is what
says the name moved because the content moved.

Ship the new bundle without the manifest and the shop goes on asking for
`kbb-product-CJbrmkEW.css`, which is still on the server: **no 404, no error, no
sign of anything wrong, and the product page renders exactly as it did
yesterday.** Diff the package's contents against the repo before shipping it —
never trust a file list.

---

## Block 3 · the mobile type — **A or B**, and C is withdrawn

`docs/PDP-LEDGER-HANDOVER.md` §4 has the measurement. The short of it: drawn in
Outfit, the state he complained about (21px / weight 600) is **two line-boxes on
his everyday name, not three** — the size half of "big bold" went with the
typeface. The weight half did not. **B ships and needs no edit.** A is one
declaration. C is withdrawn: at 23px/700 its price box is 75px against B's 73px,
which is not "the price leads", it is a rounding.

**Only if he answers A.** In `resources/css/kbb/kbb-product.css`.

**Anchor** (occurs once):

```
.pdp .bb-title{font-size:19px;font-weight:500;line-height:1.36;letter-spacing:-.005em}
```

**Replacement:**

```
.pdp .bb-title{font-size:21px;font-weight:500;line-height:1.34;letter-spacing:-.005em}
```

Then, in the same commit and not afterwards:

1. `npx vite build` and commit `public/build` — the source alone changes nothing
   on the shop, and `BuiltCssSelectorsAreCurrentTest` is what says so;
2. move the literal in `tests/Feature/ProductPageLedgerTest.php` →
   *it ships the quiet mobile title and the Ledger desktop title*. It pins the
   SHIPPED value by name on purpose, so this stays a one-line change with a test
   that notices when the build half is forgotten.

**The desktop is untouched either way.** `.pdp .bb-title` inside
`@media (min-width:881px)` stays 30px/500; he said the desktop is fine.

---

## The merge is done

Merged at `9de9044`. The integrator resolved the three-way as scoped:
`kbb-product.css` and `EnglishRenderWalk.php` had no overlap, and `public/build`
was rebuilt from the merged sources rather than taking a side — checked here by
running `npx vite build` on the rebased tree and getting **no diff**, so the
bundle on the branch is the one the merged sources produce.

The typeface caveat that was in this section is discharged: §3 and
`docs/PDP-LEDGER-HANDOVER.md` §4 are re-measured in Outfit and both sheets are
re-shot.

---

## And one thing to take OUT when it is time

`docs/PDP-PRODUCT-PAGE-DESIGNS.md` lists the nine files the five previews are
made of. Removing them is an integrator change because one of them is the
`require __DIR__.'/pdp-preview-admin.php';` at `routes/web.php:706` — and
`EverythingIsMountedOnceTest` is what says the require and the file must leave
together. **Not now**, though: the owner is still choosing a type from §4 and may
want to look at the other four while he does.
