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
| 2 | the package's file list | `public/build/manifest.json` **and** the new bundle | **yes** |
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
public/build/manifest.json                    (modified)
public/build/assets/kbb-product-<new hash>.css  (new)
public/build/assets/kbb-product-CJbrmkEW.css  (deleted — may be left behind)

The two hashed names are in `git status`; read them from there rather than from
this document, which cannot be right about a content hash after the next edit.
```

Ship the new bundle without the manifest and the shop goes on asking for
`kbb-product-CJbrmkEW.css`, which is still on the server: **no 404, no error, no
sign of anything wrong, and the product page renders exactly as it did
yesterday.** Diff the package's contents against the repo before shipping it —
never trust a file list.

---

## Block 3 · the mobile type, once he has picked a letter

`docs/lane-pdp-shots/sheet-real-type-390.png` is the picture. **B ships**; this
block is only if he answers **A** or **C**. See §4 of
`docs/PDP-LEDGER-HANDOVER.md` for the measured numbers behind each.

In **`resources/css/kbb/kbb-product.css`**.

**Anchor** (occurs once):

```
.pdp .bb-title{font-size:19px;font-weight:500;line-height:1.36;letter-spacing:-.005em}
```

**Replacement for A · weight only (21px / 500):**

```
.pdp .bb-title{font-size:21px;font-weight:500;line-height:1.34;letter-spacing:-.005em}
```

**Replacement for C · price leads (17px / 500):**

```
.pdp .bb-title{font-size:17px;font-weight:500;line-height:1.42;letter-spacing:-.005em;color:var(--ink-2)}
```

**C only — a second anchor** (occurs once):

```
.pdp .bb-head .bb-price .now{display:block;font-size:22px;font-weight:800;letter-spacing:-.02em}
```

**Replacement:**

```
.pdp .bb-head .bb-price .now{display:block;font-size:23px;font-weight:700;letter-spacing:-.02em}
```

Then, in the same commit and not afterwards:

1. `npx vite build` and commit `public/build` — the source alone changes nothing
   on the shop, and `BuiltCssSelectorsAreCurrentTest` is what says so;
2. move the two literals in `tests/Feature/ProductPageLedgerTest.php` →
   *it ships the quiet mobile title and the Ledger desktop title*. They pin the
   SHIPPED values by name on purpose, so that this stays a two-line change with
   a test that notices when only one of the two lines is made.

**The desktop is untouched by all three letters.** `.pdp .bb-title` inside
`@media (min-width:881px)` stays 30px/500; he said the desktop is fine.

---

## What is red before these are applied, and what is green after

**Nothing is red.** This lane's branch is green as it stands — blocks 1 and 2 are
properties of the **package**, which no test in this repository can see, and
block 3 is a choice the owner has not made yet.

That is the reason this document exists at all: the two things that can go wrong
here are both invisible to the suite, and one of them has already cost this
project a round.

**Both anchors in block 3 were counted, not eyeballed:** each occurs exactly once
in `resources/css/kbb/kbb-product.css` at this commit.

---

## The merge, and the one thing in it that is not mechanical

This branch was cut at `768a6e8`; eight commits landed upstream while it was being
written. Three files are touched on both sides, and all three merge mechanically:

| file | mine | upstream | |
|---|---|---|---|
| `resources/css/kbb/kbb-product.css` | a block at the FOOT | one line at the TOP (`--sans`) | no overlap |
| `tests/Support/EnglishRenderWalk.php` | two rules appended to two methods | rules edited elsewhere | no overlap |
| `public/build/manifest.json` | one entry | many entries (the font rework) | **resolve by rebuilding** |

**The manifest is the one to be careful with.** It is a build product, so do not
hand-merge it: take either side, then run `npx vite build` and commit what it
writes. Anything else risks a manifest that names a bundle that is not on disk —
which is a silent 404 on a stylesheet, not an error.

**And one thing that is not mechanical at all.** `a29a9dc` changes the shop's
typeface from Poppins to Outfit, in this stylesheet's own `--sans`. Every line
count and block height this lane measured was read with Poppins on the page. The
sizes and weights are unaffected; the line counts may not be. See the banner at
the head of `docs/PDP-LEDGER-HANDOVER.md` §4 — **re-shoot
`sheet-real-type-390.png` after the merge** before putting the three options in
front of the owner. `sh storage/pdp-logs/round3.sh` does it in about four minutes.

---

## And one thing to take OUT when it is time

`docs/PDP-PRODUCT-PAGE-DESIGNS.md` lists the nine files the five previews are
made of. Removing them is an integrator change because one of them is the
`require __DIR__.'/pdp-preview-admin.php';` at `routes/web.php:706` — and
`EverythingIsMountedOnceTest` is what says the require and the file must leave
together. **Not now**, though: the owner is still choosing a type from §4 and may
want to look at the other four while he does.
