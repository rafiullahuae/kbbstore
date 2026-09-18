# `wordpress-plugin/` — WordPress code. Not shop code.

**Nothing in this directory may ever be written onto the Laravel shop.**

`App\Services\Update\UpdateGuard` works by allow-list — `app/`, `config/`,
`database/migrations/`, `database/seeders/`, `resources/`, `routes/`,
`public/build/`, plus four named files — and `wordpress-plugin/` is not one of
them. A Core Updates package containing any path under here is refused outright
with

```
Path outside the permitted areas: wordpress-plugin/kbb-exporter/kbb-exporter.php
```

before a single byte is applied. That is asserted, per file, in
`tests/Feature/GeWpExporterTest.php` → *it cannot be shipped in a Core Updates
package*, and the mutation that adds `'wordpress-plugin/'` to the guard's
allow-list turns that test red. **"The guard would have caught it" is the
sentence that preceded packages 2.60.102–.106**, which is why it is measured
rather than trusted.

`docs/GE-WP-EXPORTER.md` §10 carries the anchor that adds a second lock, in
`BuildPackage::NEVER_SHIP`, so a package containing this is never built in the
first place rather than only being rejected on the way in.

---

## `kbb-exporter/` — the plugin

Installs on kbeautybliss.com by uploading a zip through **Plugins → Add New**,
then runs from **Tools → KBB Export**.

No Composer, no build step, no `vendor/` — shared hosting has no shell, so a
plugin that needs either is a plugin that cannot be installed. Asserted.

It writes the file set `docs/WP-EXPORT-CONTRACT.md` names into
`wp-content/uploads/kbb-export/<export id>/`, batched and resumable from the
admin screen, with `manifest.json` written last.

To build the zip:

```bash
cd wordpress-plugin && zip -r kbb-exporter.zip kbb-exporter
```

## `harness/` — how it was tested without WordPress

There is no WordPress in this sandbox and every external host is blocked, so the
plugin cannot be installed and run here. What can be done, and is:

1. `shop.php` builds WordPress-**shaped** tables in MySQL — `wp_posts`,
   `wp_postmeta`, `wp_users`, `wp_usermeta`, `wp_terms`, `wp_term_taxonomy`,
   `wp_term_relationships`, `wp_termmeta`, `wp_comments`, `wp_commentmeta`,
   `wp_woocommerce_order_items`, `wp_woocommerce_order_itemmeta`,
   `wp_woocommerce_attribute_taxonomies`, and the four HPOS tables — and fills
   them with a deliberately nasty shop: a trashed product, a variable product
   with a disabled variation, a guest order, a partial refund, an order note, a
   GTIN in `wpseo_global_identifier_values`, a withdrawn coupon, a pre-3.0
   review with an empty `comment_type`, and an attachment referenced at two
   sizes.
2. `wp-stubs.php` is `$wpdb` over PDO plus the fourteen WordPress functions the
   plugin is allowed to call. If a stage ever calls a fifteenth it fails here
   with an undefined-function error, rather than working under the harness and
   failing on the live site or the reverse.
3. `run-export.php` runs the plugin's **real** stage classes over it, one batch
   per iteration with a **fresh runner each time**, so every batch reloads its
   checkpoint from the options table exactly as a separate HTTP request would.

```bash
php wordpress-plugin/harness/run-export.php --storage=posts --out=/tmp/x --db=kbb_ge_wp --batch=200
php wordpress-plugin/harness/run-export.php --storage=hpos  --out=/tmp/y --db=kbb_ge_wp --batch=200
```

The two produce **byte-identical CSVs**, and the output goes straight into this
repository's real `ImportRunner` in `tests/Feature/GeWpExporterTest.php`. That
round trip is the evidence; `docs/GE-WP-EXPORTER.md` is the account of it,
including §9 — what still needs one real run on the live site.
