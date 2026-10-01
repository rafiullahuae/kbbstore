# KBB Store Exporter — what changed, and when

Derived from this repository's commits against `wordpress-plugin/`, not from
memory. The version in `kbb-exporter.php` had stayed at **1.0.0** through every
entry below, so an owner looking at **Plugins** in WordPress could not tell which
build was installed, and `manifest.json` — which records the exporter's version
as `source.plugin_version` — could not say which build produced a set of files.
That is corrected at 1.5.0 and guarded by `GeWpExporterTest`.

**To install or update:** zip the `wordpress-plugin/kbb-exporter` folder and
upload it through **Plugins → Add New → Upload Plugin** on the WordPress site.
Nothing else is needed: the plugin has no Composer dependencies and no build
step, because uploading a zip is the only door that shared host has.

**It is never part of a Core Updates package.** `UpdateGuard` refuses the whole
package if `wordpress-plugin/` appears in one, and `GeWpExporterTest` asserts
that refusal rather than trusting it.

---

## 1.9.0

**Extra product tabs are now exported.** (Lane PI-A)

- The old product page showed tabs such as **Major Ingredients** beside
  **Description**; the imported product showed Description alone. WooCommerce
  has no extra tabs of its own — every plugin and theme that adds them keeps
  them in its own post meta — and this exporter read none of those keys, so the
  tabs were in no file.
- `products.csv` has a new column, `custom_tabs`: a JSON list of
  `{"title", "content"}` in the order the old page showed them, empty tabs left
  out. Read from Custom Product Tabs for WooCommerce (`yikes_woo_products_tabs`),
  Custom Product Tabs Lite (`frs_woo_product_tabs`), WoodMart, Flatsome and
  Porto. The new shop's importer turns each one into a tab on that product.
- A product meta key with "tab" in its name that this build does **not** read
  is named in `manifest.json`'s notes, so a tab plugin nobody listed is
  reported rather than skipped.
- **To get the tabs onto the new shop:** install this build, export
  **Products** again, and import that export. Products already imported are
  updated in place by their WooCommerce id; nothing is duplicated.

## 1.8.0

**Review photographs are now on the list of files to fetch.** (Lane IE2)

- `reviews.csv` already named the pictures shoppers attached to their reviews,
  but `media.csv` — the list the new shop works through to download files off
  the old one before it is switched off — walked products, categories, brands
  and articles and **not reviews**. A customer's own photograph, the one image
  on the shop that cannot be re-created, was named in one file and missing from
  the only file that says "fetch this".
- The rule for "this value is a photograph" now lives in one class,
  `KBB_Export_Review_Photos`, read by both the reviews stage and the media
  stage, so the two files cannot disagree. It recognises a value by a fact
  about this site (an attachment ID it resolves, or an address under its own
  uploads directory), never by a guessed list of other plugins' meta keys.
- Version moved 1.7.1 → 1.8.0 in all three places it lives (header,
  `KBB_EXPORTER_VERSION`, `KBB_Export_Runner::PLUGIN_VERSION`), so **Plugins**
  in WordPress shows which build is installed.

## 1.7.1

**Two things the plugin was doing right and had never said, both of which the
owner meets on the screen he opens before the migration.** (Lane IE)

- **HPOS compatibility is now declared.** WooCommerce 8.2+ lists every plugin
  that has not called `FeaturesUtil::declare_compatibility('custom_order_tables')`
  under **WooCommerce → Settings → Advanced → Features** as *incompatible with
  High-Performance Order Storage*. This plugin has read HPOS's own `wc_orders`
  tables since the orders stage was written — `KBB_Export_Orders_Source` picks
  the storage, and the harness runs the real stages both ways with
  `--storage=posts` and `--storage=hpos` producing **byte-identical CSVs**,
  asserted in `GeWpExporterTest`. So the warning was false, and it was false on
  the one screen an owner checks before trusting a migration tool with his
  orders. Guarded twice (the hook is 7.1+, the class 7.5+), so it is inert on an
  older shop rather than fatal.

- **WooCommerce being absent is now said at the top of the screen instead of
  midway through the export.** There was no check of any kind: on a site whose
  WooCommerce is deactivated — which happens by accident during a migration —
  **Tools → KBB Export** appeared exactly as usual and the run then died on
  `wp_woocommerce_order_items` not existing, *after* the owner had started it.

  Deliberately an admin notice and **not** a `Requires Plugins: woocommerce`
  header. That header (WordPress 6.5+) makes WordPress REFUSE TO ACTIVATE this
  plugin whenever it cannot match the slug — including a shop whose WooCommerce
  sits in a differently named folder, which staging copies and some shared hosts
  do produce. Blocking the migration tool over a folder name is a worse failure
  than the one being fixed.

Nothing about the export's output changed. A 1.7.0 export and a 1.7.1 export of
the same shop are the same files, except for `source.plugin_version` in
`manifest.json`.

---

## 1.7.0

**The navigation menu — the last thing a WordPress shop carries that no export
had ever read.**

1.6.0's own note said it out loud, with a count: *"THE NAVIGATION MENU IS NOT IN
THIS EXPORT AND HAS TO BE RE-ENTERED BY HAND: 3 menu items across 1 menu."* The
new shop's `menus` and `menu_items` tables have carried `source_term_id` and
`source_post_id` since the first schema migration, waiting for it.
`docs/IE-IMPORT-READINESS.md` §7 swept every `source_*` column in that schema
against every writer in `app/` and found this to be the last one nothing filled.

Two new files, because WordPress keeps a menu in two tables and so does this
shop:

- **`menus.csv`** — one row per `nav_menu` TERM: `term_id, name, slug,
  description, locations, count`. `locations` is the theme's own slot
  assignment, read out of every `theme_mods_*` option's `nav_menu_locations`.
  It is exported so the owner can be told which of his menus WAS the header; it
  is deliberately not an instruction, because an imported menu is not mounted.

- **`menu_items.csv`** — one row per `nav_menu_item` POST that belongs to a
  menu: `id, menu_term_id, parent_id, position, label, label_source, type,
  object, object_id, object_slug, url, target, classes, description, status`.

**The label is not always the item's own title, and this was measured rather
than assumed.** WordPress writes `post_title` on a menu item only when the owner
types a label OVER the default; leave the box alone and the column is the empty
string and the theme prints the target's own name. Most items on a real menu are
that shape. An export emitting `post_title` would carry an empty label for most
of the menu, and `menu_items.label` on the new shop is NOT NULL. So the fallback
is resolved HERE, against the object the item points at — which is the only side
of the pipe that still has the object, since the new shop refuses a WordPress
page by name. `label_source` says which of the two a row's label came from.

**An orphan is not exported and is counted.** A `nav_menu_item` whose term
relationship was deleted and whose post row was not belongs to no menu;
WordPress renders it nowhere, so neither does this export carry it, and the note
says how many there were.

**`_menu_item_classes` is a serialised array** and comes out as a comma list, so
nothing downstream has to run `unserialize()` over an untrusted file.

**A new group, `navigation`,** between `content` and `addresses`. Its own group
rather than part of `content` because the dependency is different — an article
needs nothing, a menu item points at a category, a brand, a product or an
article — and because it is the one group an owner may genuinely want to leave
out, having already rebuilt his header by hand.

**And two notes that were about the gap are now about what is carried.**
`nav_menu_item` joins the list of post types posts.csv does not carry *because
another file does*, and the "IS NOT IN THIS EXPORT" note fires only when the
Navigation group was left unticked — which is the one case where it is still
true. `docs/GQ-MIGRATION-COMPLETENESS.md` §5.4: a discard list with false
entries is worse than a shorter one.

The importer is `App\Services\Import\Entities\MenuImporter` and
`::MenuItemImporter`; `docs/MN-NAVIGATION-IMPORT.md` is the account of what each
pointer resolves to and what happens to one that cannot be resolved.

---

## 1.6.0

**Two things a WordPress shop carries that no export had ever read.**

**1. `_wp_old_slug` — every address the shop USED to serve.** WordPress writes
one of these every time a published post's slug changes, and core's
`wp_old_slug_redirect()` then answers the old address with a 301 on every
front-end 404. So a product renamed in 2021 has been quietly redirecting ever
since: Google still holds the old address, shoppers still have it bookmarked,
and nobody has noticed, **because it works**.

It works because WordPress is running. Switch the old shop off without carrying
these rows and every one of those addresses is a hard 404 on day one — and the
list cannot be recovered afterwards, because it only ever existed in the database
that was turned off. The string `_wp_old_slug` appeared nowhere in this project:
not in the plugin, not in the importer, not in a document.

They go into `permalinks.csv` with `status` = `old-slug`, carrying the same
`type` and `wc_id` as the object's current row, so
`App\Services\Import\RedirectMap` already does the right thing with them and
**nothing on the Laravel side had to learn a new word**.

The address is **not assembled from a base**. `_wp_old_slug` stores a slug, and
this plugin does not guess at bases — the brand-archive note in the permalinks
stage is there because guessing one would have written 93 redirects from an
address that may never have existed. So it takes `get_permalink()`'s answer for
the post and swaps the **last path segment**. A child page's previous address
comes out as `/about-us/our-team/`, not `/our-team/`. Where the last segment is
not the post's current slug the address **cannot** be reconstructed, and the row
says so with an empty `permalink` and a note, rather than inventing one.

The count is in `manifest.json`'s notes, because the number itself is the
finding: it is how many addresses go from "301, quietly, for years" to "404" on
cutover day.

**2. Review photographs.** `reviews.images` has been a `json` column on the
Laravel side since the first schema migration — cast on the model, drawn by the
product page with a "+n" chip, filtered on by the review wall, scheme-checked by
`ReviewWall::photos()` on the way out. All of it built and tested, and on an
imported shop it rendered nothing, because the only writer was a shopper
uploading to the **new** site. A photograph is the part of a review a shop cannot
re-create: the owner can retype a review, he cannot retype a customer's picture
of her own face.

`reviews.csv` now has an `images` column. It recognises a photograph by the
**value** and never by the key: an attachment id this site resolves, or an
address under this site's **own uploads directory** with an image extension.
Review photos are not WooCommerce core — every shop that has them has them from
one of a dozen plugins, each with its own meta key — and a list of keys to look
for would be a list of guesses that fails silently on the one shop it is pointed
at. Both the serialised-array and the id-list storage shapes are handled, and an
address on somebody else's server is refused so the new shop cannot hotlink a
stranger's file from a product page.

**And every comment meta key it did not use is named in `manifest.json`, with a
count.** The value rule can still miss — a plugin storing a bare filename, or a
path relative to the uploads root, produces no match. If a shop visibly has
review photographs and the `images` column comes out empty, that note is the
list of keys to look at, so the answer is a key name rather than a discovery
after the cutover.

Both are proved end to end, on this plugin's own output through the shop's real
`ImportRunner` and — for the 301s — through its real HTTP kernel, in
`tests/Feature/IeLegacyAddressesAndPhotosTest.php`.

**Caught by the fixture before it shipped:** the old-slug query first excluded
`KBB_Export_Stage_Posts::NOT_CONTENT` verbatim, which reads like the obviously
right list and is not. That list answers a different question — it is
`posts.csv`'s account of "post types this file does not carry" — and `product` is
in it because `products.csv` carries products, **not** because a product has no
address. Used as-is it dropped every product rename, which is the largest and
most valuable source of old addresses on a six-year-old catalogue.

---

## 1.5.0

**Three fields that were on the owner's product edit page and in no export file
at all.** `sold_individually` (Inventory → "Limit purchases to 1 item per
order"), `reviews_enabled` (Advanced → "Enable reviews", which is WordPress's
`posts.comment_status`) and `default_attributes` (Variations → "Default Form
Values").

The third was the worst of the three and not simply an absence:
`_default_attributes` was **already in the products stage's `META_KEYS`** —
fetched on every batch, for every product, and emitted by no column. `META_KEYS`
is this plugin's own account of what it reads out of WooCommerce, so a reader
checking coverage against it would have concluded the field crossed.

None of the three lands in a column on the Laravel side yet; they are exported
so the import can **name** them as losses instead of losing them in silence.
`ImportProductParityTest` now reads the stage's `META_KEYS` against the row the
stage actually emits, so a key that is fetched and dropped fails by name.

## 1.4.0

**The export can be deleted from the browser, for real.** `customers.csv` holds
addresses and password hashes and `reviews.csv` holds emails and IP addresses,
and the screen used to tell the owner to delete a folder he has no shell or FTP
to reach. A typed confirmation, a real recursive delete, and a report of what
was removed.

Also fixed: the delete could report "gone" about a folder it had not managed to
read.

## 1.3.0

**Each group is its own download.** A single archive of a whole shop is a file
this hosting will not reliably serve; one zip per group is.

Also: **Rows per batch** moved behind a disclosure. It is plumbing, not a
decision the owner should be asked to make before he can start.

## 1.2.0

**The export can be run section by section** — Catalogue, SEO, Coupons,
Customers, Orders, Reviews, Journal articles, Addresses and pictures — and the
manifest says what each skipped section leaves out, so a partial export is a
stated partial rather than a silent one.

## 1.1.0

**The manifest says what else this site has**, and the regeneration checks all of
it: post types and counts, taxonomies and term counts, the permalink settings
that produced every address in `permalinks.csv`, and the WooCommerce permalink
bases.

Also fixed: a white admin page was the way a storage change reported itself, and
the progress bar read 100% while the export was still running.

## 1.0.0

First build. Batched, resumable, keyset-scanned (`WHERE id > ? ORDER BY id LIMIT
?` — never `posts_per_page => -1`, never a deep OFFSET), driven one batch per
AJAX request from the admin screen, with the position written to an option after
every batch so a request killed at 110 seconds resumes on the row after the last
one written.
