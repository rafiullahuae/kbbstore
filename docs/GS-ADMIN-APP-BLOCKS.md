# Lane GS · the five edits the integrator applies

`routes/web.php`, `resources/views/admin/app.blade.php` and
`docs/T1B-ADMIN-APP-BLOCKS.md` are the integrator's files and this lane may not
edit them, so **Appearance → Grid sections** is delivered as anchor/replacement
blocks in the shape `docs/TM-ADMIN-APP-BLOCKS.md` and `docs/T1B-ADMIN-APP-BLOCKS.md`
established. Every anchor below was verified to occur **exactly once** on
`lane/gs` at `0af665b`, which is `claude/kind-mayer-rpqesv` at `4e1d9af` plus
this lane's own commits.

**All five are one commit.** Applying four of them leaves either a sidebar row
that opens the dashboard under its own heading, or a finished-looking screen
whose every endpoint 404s — both of them the silent half-wired failure
`AdminNavAndIdsTest` and `EverythingIsMountedOnceTest` exist to catch, and the
second is the shape the owner reports as "the feature is broken" rather than as
"the feature is unapplied".

| | what it does |
|---|---|
| 1 | requires the routes file, so the ten endpoints exist |
| 2 | draws the screen |
| 3 | gives the screen a breadcrumb and a heading, so `?go=` and `#` reach it |
| 4 | arms the deep-link replay for it |
| 5 | keeps `docs/T1B-ADMIN-APP-BLOCKS.md` in step with block 4 |

---

## What is red until they are applied, and what is green after

Measured in this lane's worktree, both ways, on the same tree.

| | cases red | full suite |
|---|---|---|
| **Before** (as this branch ships) | **5** — listed below | not run; all five are static file checks |
| **After** (all five applied) | **0** | **7,805 passed, 26 skipped, 0 failed** — 7,831 tests, 69,577 assertions |

The five red before are all the *finished-state* pins CLAUDE.md prescribes —
`=== 1`, never `->not->toContain` — so each goes green the moment its edit lands
and stays a real guard afterwards. They go red again if an edit is applied
TWICE, which is the other half of what they are for: a sidebar entry registered
twice wraps `window.go` around its own wrapper and draws the screen into
`#content` twice.

    EverythingIsMountedOnceTest > it requires every route file exactly once
        grid-sections-admin    is required 0 times by routes/web.php + routes/api.php

    EverythingIsMountedOnceTest > it includes every admin console partial exactly once
        grid-sections-screen   is included 0 times

    GridSectionConsoleReachTest > it wires the Grid sections screen exactly once
        block 1: the routes file is required the wrong number of times

    GridSectionConsoleReachTest > it keeps the handover document and the applied console in step
        block 1 is not applied to web.php exactly once

    GridSectionConsoleReachTest > it reaches the screen through the router once it is wired
        the ten grid-section endpoints are not in the router

`StorefrontEnglishUnchangedTest` and `StorefrontQueryBudgetTest` are green
**both ways**, which is the other half of the promise: applying these five moves
no byte on the shop and no query on the homepage.

---

## Block 1 · require the routes file

Without this every one of the ten `/admin-api/grid-sections…` endpoints 404s and
the screen cannot load its own list.

It goes inside the existing `admin-api` group — the one that already carries
`web`, `auth:admin` and `NoStoreAdminApi` — beside the other Appearance route
files. `/api/*` is unauthenticated (CLAUDE.md) and not one of these endpoints
could live there: every one of them writes what the front page of the shop
shows, the picker reads the catalogue, and the preview renders a storefront
template from a draft the owner has not saved.

Anchor (occurs once):

```
        require __DIR__.'/banners-admin.php';
```

Replacement:

```
        require __DIR__.'/banners-admin.php';

        /*
         * Appearance → Grid sections (Lane GS). The owner's ONE reusable
         * product-grid section, used as many times as he likes. Writes what the
         * front page shows, reads the catalogue for the manual picker, and
         * renders a storefront partial from an unsaved draft — so it belongs
         * inside this guarded group like every other Appearance writer. Its own
         * capabilities are `gridsections.view` and `gridsections.manage`; its
         * package ships 2027_05_11_000100_clear_caches_grid_sections.
         */
        require __DIR__.'/grid-sections-admin.php';
```

## Block 2 · draw the screen

Anchor (occurs once):

```
@include('admin.partials.set-appearance-screen')
```

Replacement:

```
@include('admin.partials.set-appearance-screen')

{{-- Appearance → Grid sections (Lane GS). The owner: "prepare a proper grid
     section with all controls and it can be use anywhere, and can be edit that
     specific grid section. so this case we can re-use this grid section
     anywhere multiple times with different products etc selection."

     ONE section type, added as many times as he likes. Each instance is a row
     in HomepageSections::registry() as well, so its position on the page and
     its Desktop/Mobile switches are the ones Appearance → Homepage has always
     shown — there is no second ordering mechanism beside that one.

     It registers its own sidebar entry inside the Appearance group and wraps
     window.go, exactly as the screens above it do.

     Applying it changes nothing on the live shop: the table is created empty,
     so the homepage renders the bytes it rendered before. The two rows he named
     are one-click presets on this screen, not defaults this ships. --}}
@include('admin.partials.grid-sections-screen')
```

## Block 3 · give the screen a breadcrumb and a heading

Without this, `?go=gridsections` and `#gridsections` open the **dashboard** and
the screen has no shareable URL at all — an id that is not in `TITLES` does not
route. `ugcvideo`, `ugcsections`, `ugcstyle`, `instagram`, `sets`,
`product-tabs` and `banners` each hit this before being added.

The strings are copied from what the partial's own `go()` writes into `#crumb`
and `#ptitle`, because two answers for one screen is how a heading ends up
disagreeing with the page under it.

Anchor (occurs once):

```
'sitelayout':['Appearance','Site layout'],'slimfooter':['Appearance','Footer']};
```

Replacement:

```
'sitelayout':['Appearance','Site layout'],'slimfooter':['Appearance','Footer'],'gridsections':['Appearance','Grid sections'],'pagewash':['Appearance','Page background'],'searchterms':['Growth & Marketing','Search Terms'],'carttracking':['Growth & Marketing','Cart Tracking'],'seokeywords':['Store','SEO Keywords']};
```

## Block 4 · arm the deep-link replay

`LATE_RENDERED` is the set of ids `go()` would otherwise answer with the
dashboard, because the partial that owns them is appended after the console's
own script has run. The condition the four originals each state — *the partial
wraps `window.go` and calls `render()` before it awaits anything* — is met here:
`grid-sections-screen.blade.php` calls `render()` synchronously and then
`load()`.

Anchor (occurs once):

```
'paygw','sitelayout','slimfooter']);
```

Replacement:

```
'paygw','sitelayout','slimfooter','gridsections','pagewash','wabutton','searchterms','carttracking','seokeywords','pagebanners','emails','emails-sending','emails-branding','emails-sent','emails-customer','emails-edit','spotted','mkt-email']);
```

> **Block 3's replacement above also carries `,'pagewash':['Appearance','Page
> background']` for the same reason as blocks 4 and 5**: that run reaches the
> closing brace of `TITLES`, so a row appended to the map lengthens this record
> too. Lane BG appends rather than inserting after `'sitelayout'` precisely so
> that this record is LENGTHENED rather than SPLIT IN HALF.

> **Both replacements below carry `,'pagewash'` as well, and that is Lane BG's
> insertion rather than this lane's.** `LATE_RENDERED` is the one line in this
> console that every handover document quotes verbatim, so the lane that
> lengthens it has to lengthen every record of it in the same commit — which is
> the instruction `docs/T1B-ADMIN-APP-BLOCKS.md` gives and the reason block 5
> below exists at all. Applying this document's blocks 4 and 5 therefore lands
> the console and both records on the same line, whichever order the two lanes
> merge in.

## Block 5 · keep the other handover document in step

**In `docs/T1B-ADMIN-APP-BLOCKS.md`, not in the console.** That document's
"Block 3 · arm the deep-link replay" quotes the `LATE_RENDERED` line verbatim
and `TranslationConsoleTest > it keeps the handover document and the applied
console in step` asserts the quote matches the console. The same anchor and the
same replacement as block 4 above; it occurs once in that file.

Anchor (occurs once, in `docs/T1B-ADMIN-APP-BLOCKS.md`):

```
'paygw','sitelayout','slimfooter']);
```

Replacement:

```
'paygw','sitelayout','slimfooter','gridsections','pagewash','wabutton','searchterms','carttracking','seokeywords','pagebanners','emails','emails-sending','emails-branding','emails-sent','emails-customer','emails-edit','spotted','mkt-email']);
```

---

## What no longer needs doing

- **No capability change.** `App\Support\AdminCapabilities` already carries
  `gridsections.view` and `gridsections.manage` and the five RULES rows for
  them, written in this lane's own commit — that file is additive and is not
  the integrator's. The writes are listed **above** the reads because RULES is
  first-match-wins; listed the other way round a read capability would be enough
  to delete an instance, which `GridSectionApiSurfaceTest` asserts by name.
- **No migration to write.** `2027_05_11_000000_create_grid_sections_table` and
  `2027_05_11_000100_clear_caches_grid_sections` ship with this branch. The
  second is not optional: a route added to `routes/web.php` does not exist until
  the compiled route table is rebuilt, and three Blade templates change.
- **No asset build.** This lane touches no file under `resources/css/`. The
  section's stylesheet is emitted inline by `GridSections::css()`, for the
  reason `HomepageSections::orderStyle()` already argues: the storefront serves
  BUILT css from a web root that is a different directory, `npx vite build` is a
  manual step nobody runs during an update, and a rule added to `kbb.css`
  therefore ships inert. `BuiltCssSelectorsAreCurrentTest` has nothing to
  compare.

## And one thing that IS a shared file, named

`app/Services/HomepageSections.php` gains one method, `registry()`, and seven
`self::REGISTRY` reads inside that class become `self::registry()`. The const is
untouched and still public. `app/Http/Controllers/Admin/HomepageApiController.php`
gains one token on line 87 for the same reason — without it, Appearance →
Homepage paints a grid instance's row, lets the owner reorder it, and then 422s
the whole save with "Unknown section". Both edits carry their argument in place
and both are pinned by a test that names the mutation.
