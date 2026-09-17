# T1b · the blocks for `resources/views/admin/app.blade.php`

Lane FC may not edit that file. Everything else in T1b is implemented; these
**four** blocks are the remainder, written so they can be applied mechanically.

**Each block is an exact ANCHOR and an exact REPLACEMENT.** Every anchor occurs
**exactly once** in `resources/views/admin/app.blade.php` at the tip this branch
was cut from — verified by count, not by eye. Apply in any order; no anchor
overlaps another's replacement.

All four were applied to a scratch copy of the file, the console was driven in
real Chromium, and the result is the screenshots in `docs/translation-shots/`.
The scratch copy was then reverted; nothing in this branch touches
`app.blade.php`.

## What they do, in one line each

| # | Where | What |
| --- | --- | --- |
| 1 | `const NAV=[…]` | the **Translation** parent menu, with its four rows |
| 2 | `const TITLES={…}` | the breadcrumb and page title for those four ids |
| 3 | `const LATE_RENDERED=…` | arms the deep-link replay for them |
| 4 | the includes | loads the screens, `admin/partials/translation-screens.blade.php` |

**All four are required, and three of them fail loudly rather than quietly.**
Block 1 without block 4 puts four rows in the sidebar that open the dashboard
under their own headings — the exact silent failure `LATE_RENDERED` was written
for. Block 4 without block 1 loads a screen with no way to reach it except a
deep link. Block 3 without block 1 is inert. `AdminNavAndIdsTest` fails on the
first two combinations by name, which is why they are listed as one set rather
than as four independent edits.

**No new route, and no fifth block.** All nine `/admin-api/translations/*`
endpoints already exist and `routes/web.php` already requires
`routes/translations-admin.php` inside the `admin-api` group. This lane adds a
screen that calls them and adds not one route, which is why there is nothing to
wire up outside this file. The package does ship
`2026_11_13_000000_clear_caches_translation_console.php`, because it adds a
Blade partial that `app.blade.php` includes and a stale compiled view would
render the menu without the screens behind it.

---

## Block 1 · the Translation parent menu

Placed **immediately before `Appearance`**, so it sits directly under `Content`
and beside `Store` — where the owner asked for it, and where the breadcrumb
every one of these screens sets already says it is.

**Anchor** (occurs once):

```
  {sec:'Appearance',items:[['homepage','Homepage','<path d="M3 11 12 3l9 8"/>
```

**Replacement:**

```
  /* Translation (T1b, Lane FC). A parent menu of its own, beside Store and
     Content, which is the owner's own requirement: the switches that publish a
     second language are not settings to be scattered across other screens.

     Four rows and not one screen with four tabs. They are four different
     questions asked at four different times — is Arabic on, how far along is
     it, what does this one string say, and what would the machine cost — and
     the one the owner opens most is the strings list, which he will sit in for
     hours. A tab strip would make him pass through the switch that publishes
     the language to reach it.

     Every one of these rows is drawn by admin/partials/translation-screens
     .blade.php, which is included at the foot of this file (block 4) and wraps
     window.go. They are in LATE_RENDERED (block 3) as well, without which a
     deep link to any of them lands on the dashboard under its own heading.

     NO `group:true` is needed: the section carries four items, so buildNav
     renders it as a real .nav-group without being asked. Nothing injects rows
     into this group, so it will never be a one-item section. */
  {sec:'Translation',items:[['tr-settings','Language settings','<path d="M4 5h16"/><path d="M4 12h16"/><path d="M4 19h16"/><circle cx="9" cy="5" r="2.2"/><circle cx="15" cy="12" r="2.2"/><circle cx="8" cy="19" r="2.2"/>'],['tr-progress','Progress','<path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6" rx="1"/><rect x="12" y="8" width="3" height="10" rx="1"/><rect x="17" y="5" width="3" height="13" rx="1"/>'],['tr-strings','Strings','<path d="M4 7V5h16v2"/><path d="M9 19h6"/><path d="M12 5v14"/>'],['tr-machine','Machine translation','<path d="m5 8 6 6"/><path d="m4 14 6-6 2-3"/><path d="M2 5h12"/><path d="M7 2h1"/><path d="m22 22-5-10-5 10"/><path d="M14 18h6"/>']]},
  {sec:'Appearance',items:[['homepage','Homepage','<path d="M3 11 12 3l9 8"/>
```

---

## Block 2 · the breadcrumbs and page titles

`TITLES` is `id => [breadcrumb group, page title]`, and both halves must match
the sidebar row exactly: `AdminNavAndIdsTest` compares them and fails when the
owner clicks one word and the page answers with another. The group is
`'Translation'`, which is the `sec` block 1 creates, and each title is the row's
own label.

**Anchor** (occurs once):

```
shopfilters:['Storefront','Shop Filters'],
```

**Replacement:**

```
shopfilters:['Storefront','Shop Filters'],'tr-settings':['Translation','Language settings'],'tr-progress':['Translation','Progress'],'tr-strings':['Translation','Strings'],'tr-machine':['Translation','Machine translation'],
```

---

## Block 3 · arm the deep-link replay for the four screens

**Anchor** (occurs once):

```
const LATE_RENDERED=new Set(['media','tax']);
```

**Replacement:**

```
/* The four Translation screens join this set for exactly the reason the note
   above gives. They are in TITLES, they have sidebar rows, and go()'s dispatch
   object has no entry for any of them — their renderer is installed LATER in
   the document, by admin/partials/translation-screens.blade.php, which does not
   exist yet a few lines after buildNav(). Without them here, ?go=tr-settings
   opens the DASHBOARD under the heading "Translation · Settings" with no error
   anywhere on the page: the owner follows a link to the screen he turns Arabic
   on from and is shown the dashboard instead, with nothing to report.

   They are safe to arm, which is the condition this set carries. Each of the
   four paints synchronously — window.go in that partial calls render() before
   it awaits anything — so the replay's marker inside #content is already
   destroyed by the time its task runs and nothing is drawn twice. That is the
   rule 'rev-all' failed, and the reason it is in neither armed set. */
const LATE_RENDERED=new Set(['media','tax','tr-settings','tr-progress','tr-strings','tr-machine']);
```

---

## Block 4 · include the Translation screens

Last among the includes, like every screen partial that wraps `window.go`: the
wrapper installed last is consulted first, and these four ids are claimed by no
other wrapper, so the order is a convention rather than a dependency here.
Placed after the review queue badge so that badge's own `window.go` wrapper —
which re-reads the pending review count after every navigation — still sees a
move into and out of the Translation screens.

**Anchor** (occurs once):

```
@include('admin.partials.review-queue-badge')
```

**Replacement:**

```
@include('admin.partials.review-queue-badge')

{{-- Translation -> Language settings, Progress, Strings and Machine
     translation (T1b, Lane FC). Same arrangement as the screens above: its own file, its
     own wrapper around window.go.

     It appends NO sidebar entry, like review-settings, media-library and
     html-blocks and unlike Coupons and New Order. All four ids are in the NAV
     const and in TITLES above (blocks 1 and 2); adding entries here would give
     the owner each row twice. A parent menu could not be created from a partial
     in any case — kbbAddNavEntry() joins groups and never invents one, because
     a group invented there would be a second place deciding this sidebar's
     shape while TITLES, the breadcrumbs and buildNav all still used the first.

     Paired with the four additions to LATE_RENDERED above (block 3), without
     which a deep link to any of them opens the dashboard under its own heading.

     WHAT IT IS FOR. Nine endpoints have existed under /admin-api/translations/*
     since the bilingual foundation landed and nothing in this console called any
     of them: the Arabic module had a database, an API, Arabic boxes in six
     editors, a translated storefront and bilingual SEO, and no screen. This is
     the screen, and it is the one the owner turns Arabic on from.

     It changes nothing on the live shop by being applied. Both language
     switches are absent-means-off and nothing here seeds a row. --}}
@include('admin.partials.translation-screens')
```

---

## After applying

Run, from the repo root:

```bash
vendor/bin/pest --filter='AdminNavAndIdsTest|AdminNavWalkTest|TranslationConsole'
```

`AdminNavAndIdsTest` reads `app.blade.php` and the partials it includes, so it
is the check that all four blocks landed and agree with each other. It fails
with the id named if block 1, 2 or 3 is missing or misspelt, and if block 4 is
applied without the others.
