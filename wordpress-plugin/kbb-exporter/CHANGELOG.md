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
