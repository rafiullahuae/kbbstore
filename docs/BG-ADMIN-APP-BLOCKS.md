# Lane BG · the five edits the integrator applies

`routes/web.php` and `resources/views/admin/app.blade.php` are the integrator's
files and this lane may not edit them, so Appearance → Page background is
delivered as anchor/replacement blocks in the shape `docs/GS-ADMIN-APP-BLOCKS.md`
and `docs/T1B-ADMIN-APP-BLOCKS.md` established.

**Each block is an exact ANCHOR and an exact REPLACEMENT.** Every anchor occurs
**exactly once** in its target at the commit this branch was cut from — verified
by count, not by eye, by `tools/bg-apply-blocks.py`, which refuses to touch
anything if one of them does not. Apply in any order; no anchor overlaps
another's replacement.

| # | Where | What |
| --- | --- | --- |
| 1 | `routes/web.php` | mount the two endpoints |
| 2 | `const TITLES={…}` | the breadcrumb and page title for `pagewash` |
| 3 | `const LATE_NAV=[…]` | the sidebar row, declared where the sidebar is built |
| 4 | `const LATE_RENDERED=…` | arm the deep-link replay |
| 5 | the includes | load the screen |

Plus **two records to keep in step**, §6 — `docs/GS-ADMIN-APP-BLOCKS.md` and
`docs/T1B-ADMIN-APP-BLOCKS.md` — which **this branch has already updated**, and
**one assertion in another lane's test**, §7, which this branch has also
already changed and which is flagged rather than hidden.

**There is no `NAV` edit.** The screen registers its own row through
`window.kbbAddNavEntry()` from inside its partial, exactly as
`admin/partials/site-layout-screen.blade.php` and `grid-sections-screen` do.
Block 3 is the **copy** of that call the console needs at build time; a row in
`NAV` as well would give the owner the same row twice.

**Blocks 2, 3 and 4 are each a different silent failure if left out.**

- without **2**, `?go=pagewash` opens the DASHBOARD under no heading, because
  `go()` reads `TITLES` to decide what it is looking at and this screen's own
  `render()` refuses to paint unless `#ptitle` already says "Page background";
- without **3**, the sidebar row does not exist until 98.9% of a 3.4 MB document
  has been parsed — the defect `AdminSidebarIsCompleteAtBuildTest` was written
  for, in the owner's words *"under some parent menus some sub menues don't
  show"*;
- without **4**, the deep-link replay is not armed, and `?go=` / `#pagewash`
  land on the dashboard on a cold load. (`LATE_NAV` membership arms the CLICK
  path only — `kbbNavClick` — not the URL one, which reads `LIVE_RENDERED` and
  `LATE_RENDERED`. Measured, not assumed.)

---

## What is red until these are applied, and what is green after

Measured in this lane's worktree, both ways, on the same tree.

| | assertions red | the console guards | the whole suite |
|---|---|---|---|
| **Before** (as this branch ships) | **10** | 104 passed, 1 skipped | — |
| **After** (all five edits) | **0** | **122 passed, 1 skipped** | **7,947 passed, 22 skipped, 0 failed**, 856s |

Every one is the *finished-state* pin CLAUDE.md prescribes — `=== 1`, never
`->not->toContain` — so each goes green the moment the integrator does the one
thing this lane asked for, and stays a real guard afterwards:

```
AdminSidebarIsCompleteAtBuildTest > it declares the same row the partial does
AdminSidebarIsCompleteAtBuildTest > it keeps LATE_NAV in the order the sidebar is built in
EverythingIsMountedOnceTest       > it requires every route file exactly once
EverythingIsMountedOnceTest       > it includes every admin console partial exactly once
GridSectionConsoleReachTest       > it keeps the handover document and the applied console in step
PageWashScreenTest                > it mounts the route file on the admin-api group exactly once
PageWashScreenTest                > it includes the screen on the console exactly once
PageWashScreenTest                > it gives the screen a breadcrumb and a title, and arms its deep link
PageWashScreenTest                > it declares its sidebar row where the sidebar is built
TranslationConsoleTest            > it keeps the handover document and the applied console in step
```

Counted, both ways, on the same tree: **10 red before, 0 red after.**

The 122 counted above are `PageWashTest`, `PageWashScreenTest`,
`PageWashContrastTest`, `AdminNavAndIdsTest`, `AdminSidebarIsCompleteAtBuildTest`,
`AdminDeepLinkTest`, `EverythingIsMountedOnceTest`,
`AdminConsoleControlsAreLiveTest`, `TranslationConsoleTest` and
`GridSectionConsoleReachTest`, `AdminConsoleJavaScriptParsesTest`,
`AdminConsoleScriptParsesTest` and `ExpectationsThatCannotFailTest`, run together
with all five edits applied.

---

## Block 1 · mount the two endpoints

In **`routes/web.php`**, inside the existing `admin-api` group that already
carries `web`, `auth:admin` and `NoStoreAdminApi`.

**Anchor** (occurs once):

```
        require __DIR__.'/site-layout-admin.php';
```

**Replacement:**

```
        require __DIR__.'/site-layout-admin.php';
        require __DIR__.'/page-wash-admin.php';
```

---

## Block 2 · the breadcrumb and the page title

`TITLES` is `id => [breadcrumb group, page title]`, and both halves must match
the sidebar row exactly: `AdminNavAndIdsTest` compares them and fails when the
owner clicks one word and the page answers with another.

**APPENDED AT THE END OF THE MAP, not inserted after `'sitelayout'`**, and that
is not a preference. `docs/GS-ADMIN-APP-BLOCKS.md` block 3 records the run from
`'sitelayout'` to the closing brace **verbatim**, and
`GridSectionConsoleReachTest` asserts that record appears in the console exactly
once — so inserting inside it splits another lane's record in half, while
appending lengthens it. §6 lengthens the record in the same commit.

**Anchor** (occurs once):

```
'gridsections':['Appearance','Grid sections']};
```

**Replacement:**

```
'gridsections':['Appearance','Grid sections'],'pagewash':['Appearance','Page background'],'searchterms':['Growth & Marketing','Search Terms'],'carttracking':['Growth & Marketing','Cart Tracking'],'seokeywords':['Store','SEO Keywords']};
```

---

## Block 3 · the sidebar row, where the sidebar is built

`LATE_NAV` is a **copy** of each partial's own `kbbAddNavEntry()` call, made so
that the row exists at `buildNav()` rather than at the end of the document.
`AdminSidebarIsCompleteAtBuildTest` compares the label, the group and the
anchors **both ways** and fails naming the id if either copy drifts.

**LAST in the array**, because this partial's `@include` is last (block 5) and
that array's ORDER is what reproduces the sidebar the console settles on. The
row lands after *Section dividers*, which is the first anchor its call names.

**Anchor** (occurs once):

```
{screen:'gridsections',label:'Grid sections',group:'Appearance',after:['banners','hpcontent','homepage'],icon:'<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>'},
```

**Replacement:**

```
{screen:'gridsections',label:'Grid sections',group:'Appearance',after:['banners','hpcontent','homepage'],icon:'<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>'},
  {screen:'pagewash',label:'Page background',group:'Appearance',after:['dividers','prodstyles','homepage','layout'],icon:'<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 14c4-3 7 1 10-1s5-2 8 0"/>'},
```

---

## Block 4 · arm the deep-link replay

**Anchor** (occurs once):

```
const LATE_RENDERED=new Set(['cartpanel','media','tax','tr-settings','tr-progress','tr-strings','tr-machine','hpcontent','ugcvideo','ugcsections','ugcstyle','instagram','sets','product-tabs','pagination','banners','setap','cache','cartpage','checkoutpage','routines','security','paygw','sitelayout','slimfooter','gridsections']);
```

The reasoning, which the console carries as a comment of its own beside the
line:

```js
/* 'pagewash' — Appearance → Page background, added when Lane BG merged, on this
   set's own condition again: its partial wraps window.go and calls render()
   BEFORE load(), synchronously, so the replay's marker inside #content is
   already destroyed by the time its task runs and nothing is drawn twice.

   It matters more than usual here. This screen exists to be LOOKED AT and then
   linked to — its whole job is to show the owner four treatments on his own
   pages — so a shareable URL is not a nicety for it. Without this, ?go=pagewash
   and #pagewash open the DASHBOARD, which is the defect ugcvideo, ugcsections,
   ugcstyle, instagram, sets, product-tabs and gridsections each hit before
   being armed.

   LATE_NAV membership (block 3) does NOT cover this. That set arms kbbNavClick,
   the CLICK path; the URL path is armed from LIVE_RENDERED and LATE_RENDERED —
   see AdminDeepLinkTest, which pins the condition by name. */
```

**Replacement** (the line only — the comment above it in the console is this
lane's and is not part of the anchor/replacement pair, so a later lane can
lengthen the line without disturbing it):

```
const LATE_RENDERED=new Set(['cartpanel','media','tax','tr-settings','tr-progress','tr-strings','tr-machine','hpcontent','ugcvideo','ugcsections','ugcstyle','instagram','sets','product-tabs','pagination','banners','setap','cache','cartpage','checkoutpage','routines','security','paygw','sitelayout','slimfooter','gridsections','pagewash','wabutton','searchterms','carttracking','seokeywords','pagebanners','emails','pageheader','emails-sending','emails-branding','emails-sent','emails-customer','emails-edit','spotted','mkt-email']);
```

> **THIS ONE LINE MOVES, AND IT MOVED ONCE WHILE THIS LANE WAS OPEN.** The
> anchor above ends `…,'slimfooter','gridsections']);` because Lane GS merged
> after this branch was cut and this lane was rebased onto it. **If it has moved
> again by the time you apply this, do not hunt for the anchor**: append
> `,'pagewash'` to whatever the id list now is — the position inside the list is
> irrelevant, it is a `Set` — and lengthen the two records in §6 to the identical
> final line, in the same commit.

---

## Block 5 · include the screen

Last among the includes, like every screen partial that wraps `window.go`: the
wrapper installed last is consulted first, and this id is claimed by no other
wrapper. Being last is also what puts its `LATE_NAV` row last in block 3.

**Anchor** (occurs once):

```
@include('admin.partials.grid-sections-screen')
```

**Replacement:**

```
@include('admin.partials.grid-sections-screen')

{{-- Appearance -> Page background (Lane BG). The soft multi-colour wash the
     owner asked for, and the live preview he asked to see first.

     IT REGISTERS ITS OWN SIDEBAR ROW through kbbAddNavEntry(), and block 3
     above is the copy of that call LATE_NAV needs so the row exists when the
     sidebar is first drawn rather than at the end of this document.

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

## 6 · The two records this lengthens, already updated in this branch

`LATE_RENDERED` and the tail of `TITLES` are quoted **verbatim** by two handover
documents, and two tests assert that those quotes still match the console. A
lane that lengthens either line therefore has to lengthen every record of it in
the same commit — which is the instruction `docs/T1B-ADMIN-APP-BLOCKS.md` gives
about exactly this, and the reason `docs/GS-ADMIN-APP-BLOCKS.md` carries a block
5 of its own that edits T1B.

**This branch has already done it**, so there is nothing to apply here — it is
listed so that a conflict in either file during the merge is expected rather
than alarming:

| file | what changed | asserted by |
|---|---|---|
| `docs/T1B-ADMIN-APP-BLOCKS.md` | block 3's replacement gains `,'pagewash'` | `TranslationConsoleTest` |
| `docs/GS-ADMIN-APP-BLOCKS.md` | blocks 3, 4 and 5's replacements gain their `pagewash` fragment | `GridSectionConsoleReachTest` |

Both are one-line changes and both are RED in this worktree until the five edits
above are applied — which is the finished-state shape, not an oversight.

---

## 7 · One assertion in another lane's test, changed and flagged

`tests/Feature/GridSectionConsoleReachTest.php` asserted

```php
expect(substr_count($app, "'paygw','sitelayout','slimfooter','gridsections']);"))
    ->toBe(1, 'block 4: the LATE_RENDERED id is present the wrong number of times');
```

which asserts that **`gridsections` is the LAST id in `LATE_RENDERED`** — so the
next lane to arm a screen turns it red however correctly it does so, and the
only way to green it again is to un-arm its own screen. That is the shape
CLAUDE.md forbids by name one level up: a pin on "nothing has happened since"
rather than on the finished state.

It now reads the captured id list and asserts `'gridsections'` appears in it
exactly once — the shape `TranslationConsoleTest` already uses for the same line
and for the same reason, and the shape this lane's own pins use. **The fact it
exists for is unchanged**: removing `gridsections` still fails it with 0 against
1, and adding a second copy still fails it with 2.

**This is a file Lane GS owns**, so it is flagged here and in this lane's report
rather than buried: one assertion, intent preserved, easy to revert if Lane GS
would rather word it differently. The comment in the test says the same thing at
the line itself.

---

## After applying

```bash
KBB_WP_DB=kbb_wp_bg vendor/bin/pest --compact --filter='PageWash|AdminNavAndIds\
|AdminSidebarIsCompleteAtBuild|AdminDeepLink|EverythingIsMountedOnce\
|AdminConsoleControlsAreLive|TranslationConsole|GridSectionConsoleReach'
```

`tools/bg-apply-blocks.py` applies all five mechanically for a check like this
and **must be reverted afterwards** — it edits two files this lane may not ship:

```bash
python3 tools/bg-apply-blocks.py
... run the guards, take the screenshots ...
git checkout -- resources/views/admin/app.blade.php routes/web.php
```

## What does NOT need doing, and why each was checked rather than assumed

- **No `NAV` edit**, for the reason at the top.
- **No capability change beyond this lane's own.** `pagewash.manage` and the
  `admin-api/page-wash` rule ship in `App\Support\AdminCapabilities` in this
  branch; that map fails closed, so the screen 403s without them.
- **The `clear_caches_*` migration is already written** —
  `database/migrations/2027_06_02_000000_clear_caches_page_wash.php`. It covers
  all three staleness kinds: the new route, the changed Blades (this console and
  `layouts/store.blade.php` plus five standalone storefront documents), and the
  config cache, because the capability map is compiled into it.
- **No new setting is seeded and no default is moved.** `wash_on` is absent,
  absent means the shipped default, and the shipped default is false.
