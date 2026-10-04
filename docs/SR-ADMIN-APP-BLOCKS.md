# Lane SR · the one edit the integrator applies

**The sidebar search** — a box at the top of the admin's left menu that finds
any page, tab, section or setting and opens it, lit up in brand pink for a
moment. `resources/views/admin/app.blade.php` is the integrator's file, so the
box ships as one partial and this one block.

## Block 1 · put the search box at the top of the sidebar

**Anchor** (occurs exactly once — verified by count at `99b6c1c` / 2.60.376, line 2826):

```
    <nav class="nav" id="nav"></nav>
```

**Replacement:**

```
@endverbatim
@include('admin.partials.admin-search')
@verbatim
    <nav class="nav" id="nav"></nav>
```

The four lines start in column 0 except the `<nav>`, exactly as written. The
sidebar is inside the console's first raw region (line 1's verbatim block), so
the include has to close it and reopen it; without those two lines the
`@include` is printed into the sidebar as text. Nothing between the brand and
`<nav>` moves: the box takes its own 46px at the top of the column.

**Nothing else.** No NAV row, no TITLES entry, no LATE_RENDERED entry, no
route, no migration that adds a table. The package does need a
`clear_caches_*` migration (the convention for a new Blade partial: a stale
compiled `admin.app` would render the sidebar without the box).

## What it costs, measured (Chromium, preview, 2.60.376)

| | |
|---|---|
| Server, per console load | 0 queries (pinned: the cache is the FILE store by name, one key holding [signature, json]); cold build 13 ms without opcache, then one file read |
| Inline index | 49,440 bytes UTF-8, 1,941 entries (88 screens + tabs + settings) |
| Browser before first focus | two listeners; the JSON is not parsed |
| First focus (parse + build) | 10–17 ms, once |
| Per keystroke (60 ms debounce) | 3.9–5 ms match, 6.8–8.2 ms match + draw |
| `scrollWidth` | 1280 at 1280, 390 at 390 (results panel 440 / 366 px wide) |

Every one of the 1,941 entries was opened in Chromium (`kbbAdminSearch.open`):
1,814+ land on the exact row or heading; 30 light their tab because the row
is drawn only after another choice (Site layout → Category header's per-style
colours, Cart page's service-fee percentage, Set → Desktop's conditional rows);
the rest landed on target when re-opened one at a time (the crawler's
back-to-back load had timed out the 8 s screen wait).

## What is red until it is applied, and green after

Both are the finished-state pin CLAUDE.md prescribes (`=== 1`, never "not
wired"), so each goes green the moment the include lands and stays a real
guard afterwards:

```
AdminSidebarSearchTest > it includes the search partial exactly once, inside the sidebar above the menu
EverythingIsMountedOnceTest > it includes every admin console partial exactly once
    admin-search    is included 0 times
```

`AdminSidebarSearchTest > it puts the box in the sidebar of the console as
served` is **skipped** until then and runs after (it renders `/admin` as an
owner and checks the box sits between `<aside class="side">` and `<nav>`).

## Where it sits in the admin

The very top of the left menu, above **Overview → Dashboard**, on every
screen. `Ctrl K` (`⌘K` on a Mac) or `/` focuses it from anywhere; on a phone
the same shortcut opens the menu drawer first. There is no setting for it.

## Keeping it complete

* A screen drawn from a `SCHEMA` + `TABS` pair is read live: add the class to
  `AdminSearchIndex::SCHEMA_SCREENS` once and every setting a later lane adds
  to that schema is searchable with nobody touching the index.
* Every other screen is in `AdminSearchIndex::CURATED`. `node
  tools/sr-search-crawl.cjs` against a preview prints, as PHP, every label the
  console draws that the index does not have yet.
* `AdminSidebarSearchTest` fails when a screen id in NAV / LATE_NAV / TITLES
  has no index entry, and when a curated label no longer occurs in the admin
  source (a renamed heading).
