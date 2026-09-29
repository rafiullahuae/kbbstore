# The navigation is imported — Lane MN

`docs/IE-IMPORT-READINESS.md` §5.1 was the owner's last standing loss on the
import, and it was written as a question somebody could close:

> **5.1 The navigation menu is retyped.** Nothing imports it. The `menus` and
> `menu_items` tables have `source_term_id` and `source_post_id` columns
> waiting, so this is a gap a later lane can close — but it is not closed, and
> on cutover night the header and the mobile drawer are entered by hand.

It is closed. The exporter is at **1.7.0**, two new files carry the menus, two
new entities read them, and the header in
`docs/lane-mn-shots/storefront-header-1280.png` is a menu nobody typed on this
shop.

Everything below is measured on a real export, written by the plugin's own stage
classes over WordPress-shaped MySQL tables, fed to `App\Services\Import\
ImportRunner` — the class `kbb:import` runs. No test double anywhere in the path.

---

## 1. Rows in, rows out

| file | rows in | table | rows out |
|---|---:|---|---:|
| `menus.csv` | 1 | `menus` | **+1** |
| `menu_items.csv` | 7 | `menu_items` | **+7** |

`kbb:import`'s own count verification, which is the number nobody can talk
themselves out of:

```
| menus       | 1 read | 1 accounted for | 0 refused | 1 in the database | VERIFIED |
| menu-items  | 7 read | 7 accounted for | 0 refused | 7 in the database | VERIFIED |
```

An **eighth** `nav_menu_item` exists in the fixture and is deliberately not in
the export: its term relationship was deleted and its post row was not, so it
belongs to no menu and WordPress renders it nowhere either. The manifest note
says so with its count, because a row silently absent from an export is the one
thing this whole pipe is arranged against.

## 2. Every item, what it pointed at, and where it landed

| WP id | label | `label_source` | pointed at | lands at | `target_type` |
|---:|---|---|---|---|---|
| 7501 | Skincare | item | `taxonomy` `product_cat` **15** | `/collections/skincare/` | `category` |
| 7504 | Face Cleansers | **object** | `taxonomy` `product_cat` **22** | `/collections/skincare/face-cleansers/` | `category` |
| 7505 | Beauty of Joseon | **object** | `taxonomy` `pa_brands` **502** | `/brands/beauty-of-joseon/` | `brand` |
| 7506 | Our hero serum | item | `post_type` `product` **4021** | `/product/serum-4021/` | `product` |
| 7502 | About us | item | `post_type` `page` **7002** | **nowhere — parked** | `unresolved` |
| 7507 | How to layer a K-beauty routine | **object** | `post_type` `post` **7001** | `/blog/how-to-layer-a-k-beauty-routine/` | `article` |
| 7503 | Sale | item | `custom` | `https://kbeautybliss.com/super-sale/` | `custom` |

7501 is the parent of 7504, 7505 and 7506; `position` is WordPress's own
`menu_order` on every row. `label_source` = **object** means the owner never
typed that label — WordPress was printing the target's own name. See §5.

## 3. The resolution rule

The export resolves **nothing**. It carries WordPress's own three columns
verbatim — `type`, `object`, `object_id` — and the shop resolves them:

| `type` | resolved against | address from |
|---|---|---|
| `taxonomy` | `categories.source_term_id`, then `brands.source_term_id` | `UrlScheme::collection($category->path)` / `UrlScheme::brand($brand->slug)` |
| `post_type` | `products.wc_id`, then `posts.source_post_id` | `UrlScheme::product($product->slug)` / `UrlScheme::article($post->slug)` |
| `custom` | the row's own `url`, through `SafeUrl::href()` | itself |
| anything else | — | **parked**, §4 |

**On the ID, never on the name of the type.** `object` is used for the report and
for nothing else, and that is deliberately the opposite of the obvious design.
`BrandImporter`'s own header records that this shop's brands are not a brands
taxonomy at all — they are terms of the `pa_brands` product **attribute**, 93 of
them — and the taxonomy a given shop keeps its brands in is a setting the export
*reads* rather than a constant. An importer that branched on the string
`pa_brands` would place every brand item on this fixture and none on a shop
running `product_brand`, `berocket_brand` or `yith_product_brand`, **silently**,
because "no brand of that taxonomy" and "no brand" look identical from the
importer. A WordPress id is unique across every post type and every taxonomy, so
the id is exact on every shop. `it resolves a brand on the WordPress id and not
on the name of its taxonomy` proves it by re-importing the same row with
`object` rewritten to a taxonomy nothing has ever heard of.

**And the address comes from `UrlScheme`, never from a literal.** That class's
own header names "two menu builders" among the ten writers of
`/product-category/` it exists to replace, and the scheme moved in the last round
— categories are `/collections/{path}/` now. A menu row that spelled a path would
be the eleventh writer and the one nobody remembered to move. A nested category
resolves through `categories.path`, not its slug: `/collections/skincare/face-
cleansers/`, which is why `it resolves every kind of pointer` mutation-checks
exactly that substitution.

## 4. An item pointing at something that was not imported

**The case, precisely.** "About us" points at WordPress page 7002.
`PostImporter` refuses every WordPress page **by name**, because this shop ships
its own `/about/`, `/delivery/`, `/faqs/`, `/privacy-policy/` and
`/terms-and-conditions/`, and §5.2 of IE's readiness document says which of two
`/about/` pages the shop serves is the owner's decision. The pointer is
perfectly valid. The target is deliberately absent and always will be.

The two obvious answers are both wrong, in opposite directions:

- **Dropping it silently** loses a row the owner authored — he typed that label
  and put it in that position — and after the cutover the old `wp_posts` is gone,
  so nothing can ever say what was lost.
- **Keeping it as a dead link** puts a 404 in the header of **every page of the
  shop**. Not one broken page: the navigation is on all of them, and the shopper
  finds it by clicking. That is damage to pages that were working.

### The third answer: imported, parked, and named

The row is written — label, position, parent, its whole place in the tree — with
`url` NULL and `target_type` = `unresolved`, and `NavigationService::tree()` does
not render an item in that state.

- **Nothing is lost.** The item is in the database and on **Store → Modules →
  Mega Menu**, in its right position, with the label the owner typed. Giving it a
  destination is one field on a screen that already exists.
  `docs/lane-mn-shots/mega-menu-1280.png` is "About us", third row, no address.
- **Nothing 404s.** It never reaches the header, the drawer or the footer.
  `docs/lane-mn-shots/storefront-header-1280.png` is the same menu with three
  top-level items instead of four.
- **It heals itself.** The gate is `target_type = 'unresolved'` **and** no url.
  `MegaMenuApiController::update()` writes `url` and never touches
  `target_type`, so the moment the owner types an address the item appears —
  no second step, no re-import, nothing for him to know about this mechanism.
- **And it cannot catch anything else.** `target_type` is a column no screen in
  this application has ever written, so a mega-panel column **heading** — a real
  menu item with children and no URL, which `MegaMenuApiController::store()`
  accepts — is untouched. CLAUDE.md's first rule holds for a shop that never runs
  an import.
- **He is told which ones, by name.** Every parked item is an **adjustment** in
  the import report, not a discard: `discarded()` means "in the export and not in
  the database", and this row *is* in the database. The sample names what it
  pointed at — `About us → page 7002 (about-us)` — which is the only form of that
  fact that survives the old site being switched off.

**Parking is a state with a stated repair**, not a verdict. Import the thing it
points at and run the file again and the row resolves in place — the same repair
`order_items.csv` already offers for a line whose product arrived late, and
exactly what the Navigation group's own dependency warning tells the owner to do.

The same mechanism carries one more case: **a menu item WordPress had not
published**. `menu_items` has no draft state, so importing it live would publish
unfinished work on the owner's header and refusing it would lose something
half-typed. It is parked, and named.

## 5. The label, which was measured rather than assumed

WordPress writes `post_title` on a `nav_menu_item` **only when the owner types a
label over the default**. Leave the box alone and the column is the empty string,
and the theme prints the target's own name. Three of the seven fixture items are
that shape, and on a real menu it is most of them.

`menu_items.label` on this shop is **NOT NULL**, so an export emitting
`post_title` would have produced a file most of whose rows this importer refuses
— found on the owner's server, mid-import, rather than here.

It is resolved in the **export**, and it has to be: the only side of the pipe
that still has the object. The importer could not look up the title of page 7002,
because this shop refuses page 7002 by name. `label_source` (`item` | `object` |
`none`) travels with the row so the report can say *"WordPress's own name for it,
not a label you typed"* — which changes what the owner does about a parked item.

## 6. Idempotence, and the menu he has been retyping for weeks

**The rule, in one sentence: the import is additive, matched on the WordPress
id, and it never mounts and never deletes.**

- **Matched on `menus.source_term_id` and `menu_items.source_post_id`**, both
  unique and both NULL on every row this shop's own screens create. So no query
  in either importer can *select* a menu the owner typed, and nothing in either
  one deletes. `it leaves a menu the owner typed by hand completely alone`
  asserts his rows are byte-identical after an import, `updated_at` included.
- **A second pass writes nothing.** `menus` 0 created / 0 updated / 1 unchanged;
  `menu-items` 0 / 0 / 7. "Unchanged" comes from Eloquent's own dirty check
  against the database, not from the importer deciding it did nothing.
- **An imported menu arrives switched OFF.** `show_desktop`, `show_mobile` and
  `show_footer` are written `false` **on create and never again**. Never on
  create, because replacing the header of every page as a side effect of a
  rehearsal is not a safe thing to do. Never again, because once he HAS switched
  it on, a delta pass the week after the cutover must not take it down.
- **The parent link is kept as a WordPress id on the row**
  (`menu_items.source_parent_post_id`, new column) so it can be repaired across
  requests — §10. That also means a re-import re-applies WordPress's nesting,
  which is the same semantics `label` and `url` already have for rows WordPress
  owns.
- **It does not write over his decoration.** `badge`, `icon`,
  `highlight_color`, `columns` and `visibility` are absent from the attribute
  list — WordPress has no such concepts, and writing NULL would wipe what he set
  on the Mega Menu screen on every pass.

**What this deliberately does NOT do:** an item **deleted in WordPress** between
two exports is not deleted here. Deleting the rows of a menu that are not in the
file cannot tell a deleted item from an item the owner added on this shop, and
would throw away his work on the one screen he has been doing it on. Stated as a
decision, not left as an omission.

## 7. The cache

`NavigationService::menu()` caches `kbb.nav.primary`, `kbb.nav.mobile` and
`kbb.nav.footer` for **five minutes**, shared across every visitor. Both
importers call `flush()` in `finalise()`, which is what every admin write in
`MegaMenuApiController` already does.

It cannot matter on a first import — nothing imported is mounted. It matters on
the second, onto a menu the owner has since switched on: without it, five minutes
of every visitor's header is the menu as it was before the import, and he
refreshes, sees no change, and imports again.

`tree()`'s two existing gates apply to an imported row like any other:
`SafeUrl::href()` on the url and `Color::isValidHex()` on `highlight_color`. The
importer refuses an executable address as well, so the owner is told on the
screen where he can act rather than finding a menu item that silently goes to the
home page — and `it refuses a menu address the storefront would not follow`
exercises that on a file the plugin did not write, which is the ordinary case
because **Store → Import** takes an upload.

## 8. Where it is in the admin

| what | where |
|---|---|
| The imported menu, its items, and switching it on | **Store → Modules → Mega Menu** — the menu picker gains a tab per imported menu; **⚙ Menu settings** is where Desktop / Mobile / Footer are ticked |
| Running the import | **Store → Store Import / Export → Import**, two new steps: **Navigation menus** and **Navigation items** |
| Choosing to export it in the first place | **WordPress → Tools → KBB Export**, a new group: **Navigation** |

A menu that is not assigned anywhere draws the screen's own banner — *"'Main
menu' isn't assigned anywhere yet. It exists, but nothing on the site is showing
it."* — which is the sentence an imported menu is meant to arrive under.

## 9. The pictures, with the numbers

`docs/lane-mn-shots/`, Chromium, `newContext({ viewport })`:

| shot | width | measured |
|---|---:|---|
| `storefront-header-390.png` | 390 | `scrollWidth` **390**, header **127px** tall, body font 14px |
| `storefront-header-1280.png` | 1280 | `scrollWidth` **1280**, header **94px** tall |
| `storefront-panel-1280.png` | 1280 | the panel open: **216 × 134px**, three links — the category, the brand and the product |
| `mobile-drawer-390.png` | 390 | sheet **390 × 675px**, `scrollWidth` **390**, rows: Skincare (3), How to layer a K-beauty routine, Sale |
| `mega-menu-390.png` / `-1280.png` | 390 / 1280 | `scrollWidth` **390** / **1280**, 4 top-level items, 7 rows in all, both menus in the picker |

No horizontal overflow at either width, on any of the five pages. `About us` is
absent from the storefront at both widths and from the drawer, and present on the
Mega Menu screen at both widths — which is the whole claim of §4, in two
pictures.

## 10. The instrument

`tests/Feature/MnNavigationImportTest.php` — **22 tests, 21 mutation notes,
every one of them RUN.** Twenty were applied by a driver that patches the
source, runs the single test the note names, and reverts. The twenty-first is on
the EXPORT side and was run through the plugin itself: the stage's label
fallback removed, `run-export.php` re-run over MySQL, and the resulting
`menu_items.csv` imported — three empty labels, three rejections, exactly as the
note says. Four did not go as written and were corrected rather than left as
claims.

**The four that survived their first mutation, and what each one taught:**

| note | first attempt | what it showed |
|---|---|---|
| `it leaves no file in the export that nothing opens` | unregistering `MenuImporter` left it GREEN | the test read `before` off the discard sample, and the file name is in `field` — `before` holds "1 data row, read by nothing", so it was comparing file names against a row count and could never fail. **A test bug, found by its own mutation.** |
| `it imports the same export twice` | matching on `label` left it GREEN | an unchanged export has unchanged labels, so the match key never showed. It now re-imports with an item RENAMED — the commonest edit in WordPress — and the mutation is red with 8 rows in a table that should hold 7. |
| `it flushes the five-minute navigation cache` | deleting `MenuItemImporter`'s flush left it GREEN | `MenuImporter::finalise()` runs first and has already forgotten the key. Both calls are real; the note now names the mutation that is red, which is deleting both. |
| `it imports the same rows at --batch=1, --batch=500 and one row per request` | three separate mutations all left it GREEN | `ImportContext`'s id map is per RUN, not per batch, and in this fixture every parent precedes its children — so batch size cannot reach the deferred path at all. The test asserts an AGREEMENT, the defence lives in the next test (which reorders the file so a child comes first), and the note says so instead of claiming cover it does not have. |

**Two defects were found by an assertion rather than by design, and both are the
kind only a measurement produces.**

**A slice is a request, and the parent link did not survive one.**
`_menu_item_menu_item_parent` is a nav_menu_item POST id and a child can arrive
before its parent, so the link has to be deferred. `CategoryImporter` defers in
an instance array that `finalise()` drains — and that is exactly what cannot
work here: **Store → Import steps an entity a slice at a time, and
`ImportRunner::runEntity()` calls `finalise()` on EVERY call, exhausted or
not.** A slice holding the child and not the parent resolved nothing, cleared
its array, and lost the link; the later slice that imported the parent had no
idea anything was waiting on it. It was green in every unsliced test in this
file and red in `AdminImportScreenTest > it reaches the same database whether it
is stepped in twos or done in one go`, with one menu item updated — and on the
owner's server **every** import is sliced, so the sliced answer is the one he
would have got. `menu_items.source_parent_post_id` keeps the WordPress id on the
row, and `finalise()` now repairs with two queries over the whole table:
idempotent, and indifferent to which request wrote which row.

The other:
**`'new_tab' => false` made every menu item dirty on every pass.**
`menu_items.new_tab` has no cast on the model, so the value read back from either
engine is the integer `0`; Laravel's dirty check compares with `!==` for anything
non-numeric, and `is_numeric(false)` is false. Measured: **7 updated, 0
unchanged** on a second pass over a byte-identical export. Nothing was corrupted
— the value written was identical to the value already there — but the report
that says an import is idempotent said "updated" for seven rows that had not
changed, and a report that never says "unchanged" is evidence of nothing. It is
written as an int now, with the measurement in the comment.

## 10a. The schema change

One column, and it is the only one: **`menu_items.source_parent_post_id`**,
`unsignedBigInteger`, nullable, indexed —
`2027_04_20_000000_add_menu_item_source_parent.php`, guarded with
`Schema::hasColumn` like every other migration in this repository. No route is
added, so no `clear_caches_*` migration is needed. Nothing else in the schema
moved: `menus.source_term_id` and `menu_items.source_post_id` have been there
since the first schema migration, waiting.

## 11. What is not fixed

1. **A `post_type_archive` item is parked.** WordPress's "Shop" item points at
   the product archive, and this shop serves `/shop/`. Mapping the two is exact
   rather than a guess, but `UrlScheme` has no `SHOP_BASE` and this lane would
   not spell a path beside it — §3's whole argument is that a menu row must go
   through that class. One constant and three lines, in a file three other lanes
   read.
2. **`_menu_item_classes` is exported and not imported.** `menu_items` has no
   column for a CSS class. It is in the discard list with its value, which is
   the honest state, and it is the one WordPress menu field this shop has
   nowhere to put.
3. **The Mega Menu screen's own "Live preview" strip shows a parked item.** It
   renders the raw tree rather than `NavigationService::tree()`, so it draws
   "About us" where the real header does not. Arguably right — the owner should
   see the item on the screen where he fixes it — but the strip says *Live
   preview* and is not, for that one row. The fix is in
   `resources/views/admin/app.blade.php`, which this lane may not edit.
4. **An item deleted in WordPress is not deleted here.** §6, decided rather than
   missed.
5. **`docs/IE-IMPORT-READINESS.md` §5.1 and `docs/GQ-MIGRATION-COMPLETENESS.md`
   §4.4 now describe a gap that is closed.** Each carries a one-line pointer to
   this document rather than a rewrite: they are other lanes' accounts of their
   own runs, and a document says what was true when it was measured.
