# Lane BG · the three edits the integrator applies

`resources/views/admin/app.blade.php` is the integrator's file and this lane may
not edit it, so Appearance → Page background is delivered as anchor/replacement
blocks in the shape `docs/OD-ADMIN-APP-BLOCKS.md` and `docs/T1B-ADMIN-APP-BLOCKS.md`
established.

**Each block is an exact ANCHOR and an exact REPLACEMENT.** Every anchor occurs
**exactly once** in that file at the commit this branch was cut from — verified
by count, not by eye. Apply in any order; no anchor overlaps another's
replacement.

**THREE BLOCKS, NOT FOUR, AND THE MISSING ONE IS THE SIDEBAR.** This screen
registers its own row through `window.kbbAddNavEntry()` from inside its partial,
exactly as `admin/partials/site-layout-screen.blade.php` does — so there is no
edit to the `NAV` const. The other three are all required:

| # | Where | What |
| --- | --- | --- |
| 1 | `const TITLES={…}` | the breadcrumb and page title for `pagewash` |
| 2 | `const LATE_RENDERED=…` | arms the deep-link replay for it |
| 3 | the includes | loads the screen |

**Block 3 without block 1 is the silent half-wired failure this repo keeps
finding**: the sidebar row appears (the partial adds it), the screen draws, and
`?go=pagewash` opens the DASHBOARD under no heading at all, because `go()` reads
`TITLES` to decide what it is looking at and this screen's own `render()` refuses
to paint unless `#ptitle` already says "Page background". `AdminNavAndIdsTest`
fails by name on that combination.

**There is a FOURTH edit, and it is to this repository rather than to the
console**: `routes/web.php` must gain the `require` for
`routes/page-wash-admin.php`. That file's own header carries the line and where
it goes. `EverythingIsMountedOnceTest` reports it as *"page-wash-admin.php is
required 0 times"* until it lands.

---

## What is red until these are applied, and what is green after

Measured in this lane's worktree, both ways, on the same tree.

| | assertions red | the guards |
|---|---|---|
| **Before** (as this branch ships) | **3** — see below | — |
| **After** (all three blocks + the route require) | **0** | green |

All three are the *finished-state* pin CLAUDE.md prescribes — `=== 1`, never
`->not->toContain` — so each goes green the moment the integrator does the one
thing this lane asked for, and stays a real guard afterwards:

```
EverythingIsMountedOnceTest > it requires every route file exactly once
    page-wash-admin.php                is required 0 times

EverythingIsMountedOnceTest > it includes every admin console partial exactly once
    page-wash-screen                   is included 0 times

PageWashScreenTest > it mounts the page background screen on the console exactly once
    the screen is not mounted on the console exactly once
    Failed asserting that 0 is identical to 1.
```

---

## Block 1 · the breadcrumb and the page title

`TITLES` is `id => [breadcrumb group, page title]`, and both halves must match
the sidebar row exactly: `AdminNavAndIdsTest` compares them and fails when the
owner clicks one word and the page answers with another. The group is
`'Appearance'`, which is the `group` the partial's `kbbAddNavEntry()` call joins,
and the title is the row's own label.

**Anchor** (occurs once):

```
'sitelayout':['Appearance','Site layout'],
```

**Replacement:**

```
'sitelayout':['Appearance','Site layout'],'pagewash':['Appearance','Page background'],
```

---

## Block 2 · arm the deep-link replay

**Anchor** (occurs once):

```
const LATE_RENDERED=new Set(['cartpanel','media','tax','tr-settings','tr-progress','tr-strings','tr-machine','hpcontent','ugcvideo','ugcsections','ugcstyle','instagram','sets','product-tabs','banners','setap','cache','cartpage','checkoutpage','routines','security','paygw','sitelayout','slimfooter','gridsections']);
```

**Replacement:**

```
/* 'pagewash' — Appearance → Page background, added when Lane BG merged, on this
   set's own condition a seventh time: its partial wraps window.go and calls
   render() BEFORE load(), synchronously, so the replay's marker inside #content
   is already destroyed by the time its task runs and nothing is drawn twice.

   Without it ?go=pagewash and #pagewash open the DASHBOARD, which is the defect
   ugcvideo, ugcsections, ugcstyle, instagram, sets and product-tabs each hit
   before being armed. It matters more than usual here: this screen exists to be
   LOOKED AT and then linked to — its whole job is to show the owner four
   treatments on his own pages — so a shareable URL is not a nicety for it. */
const LATE_RENDERED=new Set(['cartpanel','media','tax','tr-settings','tr-progress','tr-strings','tr-machine','hpcontent','ugcvideo','ugcsections','ugcstyle','instagram','sets','product-tabs','banners','setap','cache','cartpage','checkoutpage','routines','security','paygw','sitelayout','slimfooter','gridsections','pagewash']);
```

> **THIS ONE LINE MOVES, AND IT MOVED WHILE THIS LANE WAS OPEN.** The anchor
> above ends `…,'slimfooter','gridsections']);` because Lane GS merged into
> `claude/kind-mayer-rpqesv` after this branch was cut, and this lane was
> rebased onto it; `docs/T1B-ADMIN-APP-BLOCKS.md` records the same line and its
> copy was updated in the same rebase, which is where the merge conflict landed
> and was resolved to carry **both** ids.
>
> **If it has moved again by the time you apply this**, do not hunt for the
> anchor: append `,'pagewash'` to whatever the id list now is, and make
> `docs/T1B-ADMIN-APP-BLOCKS.md`'s block-3 replacement carry the identical final
> line **in the same commit**. `TranslationConsoleTest > it keeps the handover
> document and the applied console in step` asserts that document's replacement
> appears in `app.blade.php` exactly once, so a console that has legitimately
> moved on makes the record false rather than making the console wrong — and
> `PageWashScreenTest` asserts only that the final list contains `pagewash`
> exactly once, so it does not care where in the line it ends up.

---

## Block 3 · include the screen

Last among the includes, like every screen partial that wraps `window.go`: the
wrapper installed last is consulted first, and this id is claimed by no other
wrapper, so the order is a convention rather than a dependency.

**Anchor** (occurs once):

```
@include('admin.partials.set-appearance-screen')
```

**Replacement:**

```
@include('admin.partials.set-appearance-screen')

{{-- Appearance -> Page background (Lane BG). The soft multi-colour wash the
     owner asked for, and the live preview he asked to see first.

     IT REGISTERS ITS OWN SIDEBAR ROW through kbbAddNavEntry(), the way
     site-layout-screen does, so this include and the two const edits above are
     the whole of the change to this file.

     IT CHANGES NOTHING ON THE SHOP BY BEING APPLIED. App\Services\PageWash
     ships `on` FALSE and css() returns the empty string while it is, so every
     storefront page is byte-identical until somebody switches the wash on --
     and the screen opens on its PREVIEW tab, which writes nothing at all.

     The preview frames five real storefront addresses with ?kbbwash= on them.
     That parameter is honoured only for a request carrying an admin session, so
     a shopper who is handed one of those URLs gets the shop exactly as it is
     today. --}}
@include('admin.partials.page-wash-screen')
```

---

## After applying

Run, from the repo root:

```bash
KBB_WP_DB=kbb_wp_bg vendor/bin/pest --filter='PageWash|AdminNavAndIds|EverythingIsMountedOnce|AdminConsoleControlsAreLive|TranslationConsole'
```

`AdminNavAndIdsTest` reads `app.blade.php` and the partials it includes, so it is
the check that all three blocks landed and agree with each other.

## What does NOT need doing, and why each was checked rather than assumed

- **No `NAV` edit.** The partial calls `kbbAddNavEntry({group:'Appearance', …})`.
  Adding a row here as well would give the owner the same row twice.
- **No `docs/T1B-ADMIN-APP-BLOCKS.md` edit.** That document records the
  `LATE_RENDERED` replacement for the *Translation* lane, and
  `TranslationConsoleTest > it keeps the handover document and the applied
  console in step` asserts that document's replacement appears in
  `app.blade.php` **exactly once**. Appending `'pagewash'` to the live line
  therefore makes that document's copy stale — **so T1B's block 3 replacement
  must be updated in the same commit**, exactly as it was for `'setap'` on 29
  September. This is the fourth edit three of four lanes forget; block 2 above
  and this paragraph are the same instruction said twice on purpose.
- **The `clear_caches_*` migration is already written** —
  `database/migrations/2027_06_02_000000_clear_caches_page_wash.php`, and it
  ships in this lane. It covers all three staleness kinds: the new route, the
  two changed Blades (this console and `layouts/store.blade.php`), and the
  config cache, because `AdminCapabilities` gains `pagewash.manage` and a rule
  for `admin-api/page-wash` and that map fails closed.
- **No new setting is seeded and no default is moved.** `wash_on` is absent,
  absent means the shipped default, and the shipped default is false.
