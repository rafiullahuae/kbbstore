# The export contract — what the WordPress plugin writes and this shop reads

**The integrator owns this file.** It exists because two lanes are building the
two ends of one pipe, and a format invented twice is a format that does not
meet in the middle. Lane GE (the WordPress plugin) writes to it; Lane GF (the
importer, the progress bar and the duplicate guard) reads from it. Neither may
change it unilaterally — a change goes through the integrator so the other end
moves with it.

## The shape, and why it is not a new one

The plugin emits **the file set this importer already reads**, with the column
names the existing importers already parse:

| File | Read by | Status |
| --- | --- | --- |
| `brands.csv` | `BrandImporter` | exists |
| `categories.csv` | `CategoryImporter` | exists |
| `products.csv` | `ProductImporter` | exists |
| `customers.csv` | `CustomerImporter` | exists |
| `orders.csv` | `OrderImporter` | exists |
| `order_items.csv` | `OrderItemImporter` | exists |
| `coupons.csv` | `CouponImporter` | exists |
| `reviews.csv` | `ReviewImporter` | exists — carries `images` from plugin 1.6.0 |
| `seo.csv` | `SeoImporter` | exists |
| `permalinks.csv` | `kbb:import-redirects` | exists — `status=old-slug` rows from plugin 1.6.0 |
| `variations.csv` | `VariationImporter` | exists — Lane GH |
| `refunds.csv` | `RefundImporter` | exists — Lane GI |
| `order_notes.csv` | `OrderNoteImporter` | exists — Lane GI |
| `tags.csv` | `TagImporter` | exists — Lane GH |
| `attributes.csv` | `AttributeImporter` | exists — Lane GH |
| `posts.csv` | `PostImporter` | exists — Lane GJ |
| `menus.csv` | `MenuImporter` | exists — Lane MN, plugin 1.7.0 |
| `menu_items.csv` | `MenuItemImporter` | exists — Lane MN, plugin 1.7.0 |
| `media.csv` | — | **gap** — `kbb:import-media` re-derives its download list from the imported product URLs instead, so this file is opened by nothing and the unread-file channel names it every run. Its `exists` column is a `stat()` taken on the OLD server and cannot be re-taken after the cutover |

That is deliberate and it is the whole risk-management strategy here. Ten of
those files already have an importer with tests behind it, and 4,480 tests pass
against that shape today. A plugin that emits a *new* format would strand all
of it. So the plugin's job on those ten is to emit **exactly what the importer
already parses**, and the columns are to be derived by READING each importer,
not from WooCommerce's documentation and not from memory.

The seven marked **gap** are what `ImportRunner` itself already names as the
files a real export carries that nothing here opens, plus the two this
migration has been guessing at (`media.csv`, `permalinks.csv` for products).
The plugin writes them; importers for them come after.

## `manifest.json` — required, and the reason the rest of this works

Every export carries one at its root:

```json
{
  "format": "kbb-export/1",
  "export_id": "8f14e45f-ceea-467a-9c31-1a2b3c4d5e6f",
  "generated_at": "2026-09-18T09:30:00+04:00",
  "source": {
    "site_url": "https://kbeautybliss.com",
    "wp_version": "6.5.2",
    "woo_version": "8.7.0",
    "plugin_version": "1.0.0"
  },
  "files": {
    "products.csv": { "rows": 671, "bytes": 812344, "sha256": "…" },
    "orders.csv":   { "rows": 4159, "bytes": 2210044, "sha256": "…" }
  },
  "counts": { "products": 671, "orders": 4159, "customers": 3712 },
  "notes": []
}
```

Three properties depend on it, and each is a thing the owner asked for:

**1. A progress bar with a real denominator.** Lane GD's live page records, in
its own words, that the catalogue stage *cannot* draw a bar: `import_checkpoints`
records how many source rows were consumed and nothing records how many a CSV
holds until it has been read to the end. `rows` settles that before the first
row is read. A bar without a denominator is the fake 100% that lane already
caught and removed; this is what replaces it honestly.

**2. Duplicate imports refused rather than merged.** `export_id` identifies one
export; `sha256` identifies one file's contents. Importing the same export twice
is the case the owner asked to be protected from. Note that the importer is
*already* idempotent by `wc_id` and proved so at volume — so this is not a
correctness backstop, it is the shop **saying so out loud** instead of silently
doing nothing for a minute.

**3. A truthful record of what was imported.** `source` and `generated_at` are
what let the shop answer "which export is this data from, and when was it
taken" months later.

## Rules

- **`rows` excludes the header.** A file with a header and no data rows is
  `"rows": 0`, not 1.
- **`sha256` is of the file's bytes as written**, and the manifest is written
  last, after every file it describes is closed.
- **A file with no rows is still listed**, with `"rows": 0`. Absent from `files`
  means the plugin did not write it at all, which is a different statement and
  the importer must be able to tell the two apart — "this shop has no coupons"
  and "this export does not carry coupons" are not the same fact.
- **`groups` says which sections the export carries.** The plugin's screen lets
  the owner tick named groups — catalogue, coupons, customers, orders, reviews,
  articles, SEO, addresses and pictures — so an export need not be the whole
  shop. `groups.selected`, `groups.skipped` and `groups.files` name what was
  exported; `groups.assumed_already_imported` records a dependency the operator
  stated was already in this shop, which the plugin cannot check. This is what
  makes the rule above load-bearing: a skipped group's files are ABSENT from
  `files`, never listed with `"rows": 0`. `docs/GK-EXPORT-GROUPS.md` is the
  account.
- **An export may also arrive as one zip per group.** The plugin packs each
  exported group into `kbb-export-<group>-<short id>.zip` beside the CSVs. Each
  archive holds that group's CSV files AT THE ARCHIVE ROOT — no wrapping
  directory — plus a `manifest.json` of its own whose `files` and `counts` are
  narrowed to the files in that archive, so the absent-versus-`"rows": 0` rule
  above holds inside an archive exactly as it holds in a folder. Every archive of
  one export carries the SAME `export_id`, which is how this shop tells parts of
  one export from several exports. A `zip` key records `group`, `part`, `parts`
  and `of_export`; nothing reads it yet and, per the rule below, it is ignorable.
  `docs/GL-GROUP-DOWNLOADS.md` is the account.
- **Unknown keys are ignored, never fatal.** A newer plugin writing a field this
  shop does not read must not stop an import.
- **`format` is checked.** Anything other than `kbb-export/1` is refused with a
  sentence naming what was found, not a stack trace.
- **Money stays as the decimal string WooCommerce holds.** The importers already
  convert to integer fils and are tested on it; a plugin that pre-converts would
  double it.
## Two deltas the 1.6.0 plugin introduced

Both are ADDITIVE and both obey the ignore-unknown rule above, so a 1.5.0
export still imports — it simply carries neither of these facts.

- **`reviews.csv` gains a final column, `images`.** `…,"ip","reply","images"`.
  A JSON array of the review's photograph paths. `reviews.images` has been a
  `json` column since the first schema migration — cast on the model, drawn by
  the product page with its "+n" chip, filtered on by the review wall — and the
  only thing that had ever written to it was a shopper uploading to the NEW
  site. On an imported shop the whole feature rendered nothing, which is the
  quietest kind of gap: every piece built, tested and reachable, and no data.
  The importer scheme-checks each address (`App\Support\SafeUrl::src()`) rather
  than trusting the file.

- **`permalinks.csv`'s `status` gains the value `old-slug`**, beside `publish`
  and `empty`. A row so marked is a PREVIOUS address of the thing it names.

  ▲ THIS ONE IS TIME-LIMITED AND THE DATA CANNOT BE RECOVERED LATER.
  WordPress writes a `_wp_old_slug` row every time a published post's slug
  changes, and core's `wp_old_slug_redirect()` has been answering those
  addresses with a 301 on every front-end 404 for as long as the site has run.
  A product renamed in 2021 still resolves today; Google holds the address and
  shoppers have it bookmarked — and nobody notices, because WordPress is doing
  it silently. Switch WordPress off without carrying these and every one becomes
  a hard 404 on day one, and the list only ever existed in the database that was
  turned off. `_wp_old_slug` appeared NOWHERE in this repository before 1.6.0:
  not in the plugin, not in the importer, not in a document.

  `kbb:import-redirects` therefore requires `--permalinks=` on every run; see
  `docs/IMPORT-RUNBOOK.md` §10. Store → SEO & Meta does not need the flag —
  `UrlsMediaApiController` passes the uploaded file itself.

## The 1.7.0 delta: the navigation

ADDITIVE, and it obeys the ignore-unknown rule like the two before it: a 1.6.0
export still imports and simply carries no menu.

- **`menus.csv` and `menu_items.csv` are new**, and they are a new GROUP,
  `navigation`, so an export that leaves the box unticked carries neither file
  at all — ABSENT from `files`, never `"rows": 0`, which is the distinction this
  document's rules already turn on.

- **A menu item is a POINTER and the export never resolves it.** `type`,
  `object` and `object_id` are WordPress's own three columns, verbatim. The new
  shop resolves `object_id` against what IT imported — `categories.source_term_id`,
  `brands.source_term_id`, `products.wc_id`, `posts.source_post_id` — because a
  WordPress id is unique across every post type and every taxonomy, so the NAME
  of the type never has to be trusted. `object_slug` travels with it for the
  report only.

- **`label` MAY NOT BE the item's own `post_title`**, and this is the one place
  the export resolves something rather than carrying it. WordPress writes
  `post_title` only when the owner types a label over the default; otherwise the
  theme prints the target's name. `label_source` is `item`, `object` or `none`
  and says which happened. The export has to do it because the importer cannot:
  the target of the interesting case is a WordPress page, which this shop
  refuses by name.

- **`classes` is a comma list**, not the serialised array WordPress stores.
  Nothing downstream runs `unserialize()` over a file this shop did not write.

- **`locations` on a menu is a FACT, not an instruction.** It is the theme's
  `nav_menu_locations` map. An imported menu is never mounted by the import; see
  `docs/MN-NAVIGATION-IMPORT.md`.

- **The plugin never invents an id.** `wc_id`, `wc_order_id`, `wc_item_id` and
  the WordPress user id are what every importer upserts on; they come from
  WordPress unchanged.
