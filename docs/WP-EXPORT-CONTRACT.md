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
| `variations.csv` | — | **gap** |
| `refunds.csv` | — | **gap** |
| `order_notes.csv` | — | **gap** |
| `tags.csv` | — | **gap** |
| `attributes.csv` | — | **gap** |
| `posts.csv` | — | **gap** |
| `media.csv` | — | **gap** |

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
