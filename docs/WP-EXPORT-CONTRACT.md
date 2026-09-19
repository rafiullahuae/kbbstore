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
| `reviews.csv` | `ReviewImporter` | exists |
| `seo.csv` | `SeoImporter` | exists |
| `permalinks.csv` | `kbb:import-redirects` | exists |
| `variations.csv` | `VariationImporter` | exists — Lane GH |
| `refunds.csv` | `RefundImporter` | exists — Lane GI |
| `order_notes.csv` | `OrderNoteImporter` | exists — Lane GI |
| `tags.csv` | `TagImporter` | exists — Lane GH |
| `attributes.csv` | `AttributeImporter` | exists — Lane GH |
| `posts.csv` | `PostImporter` | exists — Lane GJ |
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
- **The plugin never invents an id.** `wc_id`, `wc_order_id`, `wc_item_id` and
  the WordPress user id are what every importer upserts on; they come from
  WordPress unchanged.
