# The WordPress exporter · Lane GE

The owner's words were *"the same plugin for wordpress, so that plugin will
export everything as per our new site, and then we will import that here in our
new site. must be everything exported from the plugin compatible in our new
site."*

"Compatible" is a claim about **two** systems, so this document leads with the
round trip rather than with the plugin. The plugin is in `wordpress-plugin/`;
the evidence is `tests/Feature/GeWpExporterTest.php`.

---

## 1. The headline

A real export, written by the plugin's own stage classes running over
WordPress-shaped tables in MySQL, goes into `App\Services\Import\ImportRunner` —
the class `kbb:import` runs, with no test double anywhere in the path — and
comes out as:

| | |
|---|---|
| rejections | **0** |
| row counts | every one agrees with `manifest.json`'s `counts` |
| money | exact to the fil, and each order's lines sum to its total |
| second pass | `updated: 0` on products and orders — the importer's own dirty check, not a row comparison |
| both order storages | **byte-identical CSVs** from legacy `wp_posts` and from HPOS `wc_orders` |
| `permalinks.csv` | consumed by `RedirectMap::fromPermalinks()` without it learning a new word — products `discard` (they did not move), `/toners/` → `/product-category/toners/`, brands no proposal at all |
| resume | `--batch=7` and `--batch=500` produce byte-identical CSVs across 200-odd separate runner instances |

20 tests. 19 mutations, 19 red — §8, including **two that survived** and
what closing each one took.

The export it was measured on is checked in at `tests/Fixtures/kbb-export/`, and
one of the tests regenerates it from the plugin and compares sha256 per file, so
the fixture cannot drift away from the generator.

---

## 2. Where it lives, and why it can never ship

`wordpress-plugin/` at the repository root.

`App\Services\Update\UpdateGuard` works by **allow-list**
(`app/`, `config/`, `database/migrations/`, `database/seeders/`, `resources/`,
`routes/`, `public/build/`, plus four named files). `wordpress-plugin/` is not
one of them, so `checkPath()` answers

```
Path outside the permitted areas: wordpress-plugin/kbb-exporter/kbb-exporter.php
```

and `check()` rejects the whole package before a byte is written. **Measured,
not assumed** — `it cannot be shipped in a Core Updates package` asserts that
string for every PHP file of the plugin, and the mutation that adds
`'wordpress-plugin/'` to `ALLOWED_PREFIXES` goes red. "The guard would have
caught it" is the sentence that preceded packages 2.60.102–.106.

`BuildPackage` is a second, independent lock and it is **not** currently one:
`NEVER_SHIP` does not name `wordpress-plugin/`, so `--file=wordpress-plugin/…`
would put it in the zip and the guard would then refuse the zip. That is the
right outcome but it is one lock, not two. The anchor is in §10.

The plugin has **no Composer dependencies and no build step** — asserted — so it
installs by uploading a zip through Plugins → Add New, which is the only door
this owner has.

---

## 3. HPOS: which storage, stated plainly

**Both are supported.** `KBB_Export_Orders_Source` is the whole of the
difference and it is 429 lines of one file.

| | legacy | HPOS |
|---|---|---|
| order header | `wp_posts` (`post_type = shop_order`) | `wp_wc_orders` |
| money, addresses | `wp_postmeta` keys | `wp_wc_order_operational_data`, `wp_wc_order_addresses` |
| everything else | `wp_wc_orders_meta` | |
| line items | `wp_woocommerce_order_items` — **unchanged by HPOS** | same |
| order notes | `wp_comments` (`order_note`) — **unchanged by HPOS** | same |
| refunds | child rows of the order in both | same |

Detection is `woocommerce_custom_orders_table_enabled = 'yes'` **and** the
`wc_orders` table existing. If the option says yes and the table is gone, the
export **refuses** rather than falling back — a fallback there reads the
synchronisation stubs in `wp_posts` and writes **zero for every order total**,
with a row count that looks right. The answer goes into
`manifest.json → source.order_storage` and is printed on the admin screen before
the export starts.

### What measuring both actually found

Seeding the same shop into each storage and diffing the two exports:

**HPOS keeps money in `DECIMAL(26,8)`.** MySQL hands back `358.50000000` and
`0.00000000`; the legacy storage hands back the postmeta *string*, `358.50`.
Same shop, same order, different bytes — which would have made "both storages
are supported" unverifiable, because the two exports could never have been
compared.

`KBB_Export_Stage::money()` now drops trailing **zeros** past the second decimal
and nothing else. `99.12345678` — which an 8-decimal column really can hold, and
a currency-conversion plugin really does write — passes through untouched so
`App\Services\Import\Money` refuses it by name with the value quoted. That
refusal is correct and must not be rounded away. This is not the pre-conversion
the contract forbids: the cell is still the decimal string WooCommerce holds, to
the fil; what comes off is padding MySQL added on the way out.

**HPOS stores every date in GMT and `wp_posts` stores both.** So both storages
emit local in `date_created` and UTC in `date_created_gmt`, converted through
`KBB_Export_Wp::local_from_gmt()`, which prefers `timezone_string` over
`gmt_offset` because only the name knows about daylight saving. Without that,
one `--timezone` could never have been right for both and the shop would have
found out as a four-hour shift across its whole order history.

---

## 4. The column derivation

The contract requires these to be **read out of the importers**, never from
WooCommerce's documentation or from memory. Each row below names the importer
line the column exists for.

### `categories.csv` — `CategoryImporter`

| column | source | why |
|---|---|---|
| `term_id` | `term_taxonomy.term_id` | matched on `source_term_id`; never on the slug — "slugs get edited in wp-admin between the full import and the cutover delta" |
| `name` | `terms.name` | `requireText('name', 'name', 'title')` |
| `slug` | `terms.slug` | |
| `parent` | `term_taxonomy.parent` | the PARENT'S TERM ID, which is what the importer's two-pass `finalise()` resolves. 0 for a root, which `Row::id()` reads as null by design |
| `description` | `term_taxonomy.description` | |
| `image` | termmeta `thumbnail_id` → `wp_get_attachment_url()` | aliases `image`, `thumbnail` |
| `position` | termmeta `order` | aliases `position`, `menu_order`, `order` |

### `brands.csv` — `BrandImporter`

Same shape, `logo` instead of `image`, no `parent` (the importer says "Flat — no
parent, no depth, no path").

The **taxonomy is asked for, not assumed**: `KBB_Export_Wp::brand_taxonomy()`
tries `pa_brands` first — because BrandImporter says production runs "brands
live as the `pa_brands` product attribute, 93 terms of it" — then `pa_brand`,
`product_brand`, `yith_product_brand`, `pwb-brand`, `berocket_brand`, `brand`.
Whichever it finds goes into a manifest note. If it finds none, `brands.csv` is
written with its header and **no rows**, which the contract says means exactly
what it looks like.

### `products.csv` — `ProductImporter`

| column | source | why |
|---|---|---|
| `id` | `posts.ID` | `wc_id` is a URL contract — `?add-to-cart={id}` links are live in the wild |
| `name` | `post_title` | |
| `slug` | `post_name`, **always** | the importer invents one with `Str::slug($name)` otherwise, which romanises Arabic and drops CJK. On a Korean-cosmetics shop with Arabic titles that is a 404 per product |
| `sku` | `_sku` | |
| `status` | `post_status`, verbatim | `publish/draft/private/pending/future` are mapped; `trash` is refused by name |
| `type` | `product_type` **taxonomy** | Woo has never stored this in meta |
| `regular_price`, `sale_price` | `_regular_price`, `_sale_price` | decimal strings, untouched |
| `sale_starts_at`, `sale_ends_at` | `_sale_price_dates_from/_to` | UNIX timestamps → local `Y-m-d H:i:s`, so one `--timezone` covers the file |
| `stock_status` | `_stock_status` | |
| `stock` | `_stock`, present-and-empty preserved | NULL and 0 are different rows |
| `manage_stock` | `_manage_stock` | |
| `featured` | `product_visibility` term `featured` | |
| `is_visible` | **computed** from `product_visibility` | see below |
| `brand_term_id` | first brand-taxonomy term | |
| `category_term_ids` | `product_cat` terms, **primary first** | see below |
| `position` | `menu_order` | |
| `date_created` | `post_date` | "a catalogue whose every product says it was created on cutover day loses the newest sort" |
| `image` | `_thumbnail_id` → URL | |
| `images` | `_product_image_gallery` ids → URLs, **pipe-separated** | see below |
| `short_description` | `post_excerpt` | |
| `description` | `post_content` | |
| `total_sales` | `total_sales` | |

Three of those needed a decision:

**`is_visible` is computed, not copied.** Woo's catalog visibility is a
*taxonomy term*, not a meta value (`_visibility` was the meta key before
WooCommerce 3.0 and returns nothing on any shop updated this decade), and it has
four states. `Row::bool()` reads `'visible'` as true and would read `'catalog'`
— which means *in the shop but not in search* — as **false**, hiding a product
the owner had published. Folded here to the yes/no the column means, with the
raw four-state value carried alongside as `product_visibility`, which nothing
reads and which the import's discard channel therefore names.

**`images` is pipe-separated.** `ProductImporter` chooses its separator by
looking — pipe wins where it appears, comma otherwise — and its own comment
records what the comma cost: a whole gallery imported as one string that is not a
URL, one broken frame instead of four pictures, and "created" in the report
either way. A URL cannot contain a bare `|`; a filename can contain a comma. The
fixture's second gallery image is called `ginseng-serum-3,-detail.jpg` so this is
a measurement and not a preference.

**`category_term_ids` is primary-first.** `ProductImporter` takes
`$categoryIds[0]` as `products.category_id` — the primary category, which decides
the breadcrumb. Ordered by term id, the parent ("Skincare", 15) beats the leaf
("Face Cleansers", 22) purely because it was created first, and **every product
on the shop files itself one level too high**. `_yoast_wpseo_primary_product_cat`
is the shop's own answer where Yoast wrote one.

Also carried, read by nothing, each a real thing the old shop holds and the new
one has no column for: `date_modified`, `product_visibility`, `backorders`,
`low_stock_amount`, `weight`, `length`, `width`, `height`, `tax_status`,
`tax_class`, `shipping_class`, `virtual`, `downloadable`, `purchase_note`,
`upsell_ids`, `cross_sell_ids`, `grouped_ids`, `tag_term_ids`,
`attribute_summary`. The import's discard channel names them with a sample value
in **one** consolidated line per entity, which is the designed way for the owner
to approve a loss rather than discover it (`docs/FV-IMPORT-AT-VOLUME.md` §10).

### `customers.csv` — `CustomerImporter`

`user_id, email, name, first_name, last_name, phone, registered, password_hash,
roles` + `billing_*`/`shipping_*` in `AddressWriter::readType()`'s own most
canonical spelling (`billing_address_1`, not `billing_line1`).

- `password_hash` carries `user_pass` and **nothing is ever called `password`** —
  that column is cast `hashed`, so assigning a hash to it hashes the hash and
  nobody can sign in.
- `registered` is `user_registered`, because Store → Customers sorts on it.
- `<type>_country` goes out exactly as WordPress holds it. `AddressWriter`
  **refuses** a three-letter code rather than truncating it, and this plugin
  does not paper over that.
- **Every** user is exported, not only the `customer` role, with the role
  breakdown in a manifest note. A shopper whose role was changed, or an
  administrator who has ordered, would otherwise be left out and
  `OrderImporter` would synthesise a *second*, guest customer row for the same
  person, splitting their history.

### `orders.csv` — `OrderImporter`

All the obvious columns, plus four that needed work:

- **`subtotal` and `fee_total` are computed.** WooCommerce stores neither on the
  order: the subtotal is the sum of the line items' `_line_subtotal` and fees are
  line items of type `fee`. Left blank, `Row::moneyOrZero()` reads them as zero
  without a word and every order page shows a total that does not agree with the
  lines above it. One query per batch does it, and the same query produces
  `shipping_method` and `coupon_code`, which are also line items.
- The summing is done in **integer minor units**, never with `+=`. Floats would
  hand the importer `298.50000000000006`, which `Money::fils()` refuses —
  "carries more precision than fils can represent" — and the whole order would be
  rejected over arithmetic this plugin did on the way past.
- **`date_created_gmt` is in the file.** It is the only thing in the export that
  can catch a wrong `--timezone`: `checkDeclaredTimezone()` compares the two and
  reports once, with a count. The test asserts it is quiet on the right zone and
  **fires** on the wrong one, because a check that cannot fire is the dead-filter
  shape this repository has already paid for.
- `customer_id` is emitted **empty** for a guest, not `0`.

### `order_items.csv` — `OrderItemImporter`

`item_id, order_id, product_id, variation_id, name, sku, brand, quantity,
subtotal, total, tax_total`.

- `product_id` is the **parent**, not the variation. `products.csv` carries
  parents; naming the variation would leave every variable-product line with a
  null `product_id` and a note, when the parent is right there. The variation id
  is carried in its own column so the link is not lost.
- `sku` and `brand` are looked up from the product in one query for the batch;
  Woo stores neither on the line.
- `unit_price` is deliberately **not** emitted — the importer derives it by
  integer division and reports the truncation, which is the behaviour the shop
  is tested on.
- **Refund lines are excluded.** WooCommerce keeps them in the same table with
  `order_id` pointing at the refund. Exported wholesale they would be refused
  one by one — on this shop, 207 refusals in a report meant for real problems.

### `coupons.csv` — `CouponImporter`

The sharpest single finding in the file set:

**The expiry is emitted as a bare date on purpose.** Woo stores `date_expires`
as a timestamp at midnight site time and treats that day as *inclusive*; this
shop's rule is `now() > expires_at`. `CouponImporter` corrects for it — but only
when it can tell a bare date from a datetime:

```php
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) { return $parsed; }
$inclusive = $parsed->addDay()->subSecond();
```

An exporter that formatted the timestamp as `2027-01-01 00:00:00` defeats that
branch: the correction never fires, no adjustment is reported, and **every live
discount code retires 24 hours early**. Emitted as `Y-m-d` when the time
component is midnight and as a full datetime when it genuinely is one. The
round trip asserts the coupon's last usable second is 23:59:59 Asia/Dubai.

Also: only `publish` coupons go out. `coupons` has no status column, so an
imported draft is a live working discount — the opposite of what the owner did
when they withdrew it. The count of withheld codes is in the manifest.

`used_by` is a **repeated** meta key, so it gets a query of its own; the usual
one-value-per-key pivot would lose all but one redemption, and `CouponImporter`
reports that column by name in its discard channel.

### `reviews.csv` — `ReviewImporter`

**The one value this plugin rewrites, and why.** WooCommerce only started
writing `comment_type = 'review'` in 3.0; before that a product review was a
comment with an **empty** type. `assertIsAReview()` refuses anything whose type
is present and is not `review` — and an empty string is present — so a straight
copy refuses every review a 2019 shop took before 2017, with a message telling
the owner to "export with comment_type = 'review'", which is exactly what is
being done. The rewrite is conditioned on the row being a comment, **on a
product**, carrying a **rating** meta. That is the definition of a product
review; there is nothing else it could be. It is counted in the notes.

**Only comments with a rating are exported.** A product comment with no rating is
a customer *question*, and `ReviewImporter` refuses it rather than defaulting it
to five stars — "a perfect score this shop then averages into the product's
rating and publishes as structured data. Nobody wrote that five." Exporting them
would produce nothing but refusals. The count is named in the manifest because
the shop has nowhere to put a product question and that is a **real loss**.

The shop's reply to a review is a child comment, which the rating filter would
otherwise drop; it is carried on its parent's row as `reply`.

### `seo.csv` — `SeoImporter` / `YoastSeo` / `YoastTiers`

**The columns are discovered, not listed**: one
`SELECT DISTINCT meta_key … LIKE '_yoast_wpseo_%' OR LIKE 'wpseo_%'`.

An exporter that wrote only the keys `YoastSeo` and `YoastTiers` already know
would make `YoastTiers::unrecognised()` impossible to satisfy — an undocumented
key would be *absent*, and absent is indistinguishable from "this shop never had
one", which is the exact distinction that class exists to draw.

Both prefixes, because `wpseo_global_identifier_values` — the GTIN map —
**does not start with an underscore and does not contain `yoast`**
(`docs/FX-YOAST-TIER-CENSUS.md` §2). A LIKE on `_yoast_wpseo_%` alone misses it
entirely, which is how "an import gap, not a data gap" becomes a data gap after
all. The round trip asserts `YoastTiers::gtinFrom()` reads `8809453510003` out
of the exported row. The serialised blob goes out **verbatim**; that class
unpicks both the JSON and the PHP-serialised shape itself and a helpfully decoded
value would be a shape it has no branch for.

A shop with no Yoast writes a header and **no rows**, rather than 671 identical
refusals about a problem nobody has.

### `permalinks.csv` — `kbb:import-redirects`

`type, wc_id, slug, permalink, status, source, note`.
`RedirectMap::fromPermalinks()` reads the first, second and fourth; the rest are
for the owner and cannot confuse it.

`source` is `wp` when `get_permalink()`/`get_term_link()` answered — meaning
every rewrite rule and every filter on the site was applied — and `derived` when
they did not. **§9 is the caveat on that under the harness.**

This is the file that settles the two questions the migration has been guessing
at, and §5 has the answers.

### The seven gap files

| file | what is in it |
|---|---|
| `variations.csv` | `product_variation` posts: parent, sku, own `post_status` (Woo disables a size by setting it to `private` — an importer that ignored that would put a withdrawn size back on sale), prices, sale window, stock, dimensions, image, and the chosen values flattened from the `attribute_*` meta keys into `attribute_pa_size=50ml\|…` |
| `refunds.csv` | one row per refund: parent order, date, `amount` (Woo's positive figure) **and** `total` (the same money as a negative — two conventions, and an importer guessing which it has applies a refund twice or backwards), reason, who, and `refunded_items` as `item_id:qty:total` so a **partial** refund's detail survives |
| `order_notes.csv` | `wp_comments` `order_note` rows with `is_customer_note`, which is what decides whether a note can be shown on the shopper's own order page |
| `tags.csv` | `product_tag` terms **with `product_ids`** — the terms and the pivot, because `docs/FV-IMPORT-AT-VOLUME.md` §11 names both and a tags file without the pivot needs a second file before anything can use it |
| `attributes.csv` | every `pa_*` term that is not a brand, one row per term with the attribute's definition repeated on it (three empty shop tables are fed from this one file) and `attribute_public`, which is whether its archive was ever served |
| `posts.csv` | the blog, the pages, and any other post type that is not another file's job or WordPress's own machinery — a **deny**-list, because a shop that ran a page builder has content in a post type nobody remembers the name of and an allow-list would drop it silently |
| `media.csv` | §6 |

---

## 5. What the plugin answered that nobody knew

`App\Services\Import\RedirectMap` carries a banner saying two of its premises did
not survive being checked, and `docs/GB-MEDIA-AND-REDIRECTS.md` corroborates the
category shape from fifteen paths copied off the live navigation. Those are
inferences drawn from outside the shop. The plugin stands inside it.

**Categories were served flat at the site root.** Not deduced from a menu seed:
`woocommerce_permalinks['category_base']` is the empty string, that setting is
in `manifest.json → source.woocommerce_permalinks`, and `get_term_link()` returns
`https://…/skincare/`. The plugin reports the *setting* and the *result*, so the
next reader does not have to take the file's word for it.

**Brand archives: the plugin will say whether they existed.** `RedirectMap`
refuses to guess a base because "inventing one writes 93 redirects from an
address that may never have existed". `permalinks.csv` carries a row per brand
term whose `permalink` is **empty** and whose `note` says
*"WordPress returned no archive URL for this term — the `pa_brands` taxonomy has
no public archive on this site, so no address of this kind was ever served"*.
That is an answer, not a gap. On the harness shop — where `attribute_public = 0`,
which is WooCommerce's default for a product attribute — the answer is **no
archive**. Whether the live site set that flag is what the one real run settles
(§9).

`manifest.json` also carries `permalink_structure` verbatim and every taxonomy
and post type that has rows, with counts, so "what else does this site publish"
is answered by the export rather than by asking.

---

## 6. `media.csv` — the sizes genuinely referenced

`url, attachment_id, size, path, exists, bytes, referenced_by, referenced_id,
field`.

References come from two places and only one of them is an id:

- `_thumbnail_id` and `_product_image_gallery` are **ids**, and resolve to the
  **full** size, because that is what `wp_get_attachment_url()` returns and
  therefore what `products.csv` carries.
- a description or a blog post contains `<img src="…-300x300.jpg">` written by
  the editor, with no id in the HTML at all. `KBB_Export_Media_Index` strips the
  `-WxH` suffix, finds the attachment whose base path that is, and names **which
  registered size** it is by matching the stored `file` name in the attachment's
  own metadata — not by parsing the numbers, because `medium` is 300x300 in the
  settings and 300x169 on a landscape photograph.

So the harness shop's serum has `thumbnail`, `medium` and `large` registered, and
**only `medium`** is in the file, because only `medium` is referenced. The test
asserts `150x150` and `1024x1024` are absent.

Only URLs under **this site's uploads directory**, matched on host so an http URL
written before the certificate was installed is still the same picture. A
supplier's photograph or a CDN is not this shop's to fetch — the same rule
`MediaRewrite` follows, for the same reason.

`exists` is a `stat()` per row, so a file the media library names and the disk
does not have is found **now**, while the old site is still up, rather than by
`MediaAudit` afterwards.

Rows are **(url, referrer, field) pairs**, not one row per URL. Deduplicating
across the whole export would mean holding every URL seen so far across HTTP
requests; the pair is also more useful, because `url` says what to fetch and
`referenced_by` says which product goes blank if the fetch fails. A consumer
wanting the download list groups by `url`.

---

## 7. Surviving a real shop

- **Keyset, never OFFSET.** Every stage is `WHERE id > :cursor ORDER BY id LIMIT
  :n`. `OFFSET 40000` makes MySQL walk and discard forty thousand rows, so the
  last batch of a 4,000-order export costs four hundred times the first — and an
  offset is *wrong* under resume, because one order placed while the export runs
  shifts every later offset by one and silently skips a row. Nothing anywhere
  does `posts_per_page => -1`.
- **Meta in bulk.** One batch of ids, then one query for all of that batch's
  meta, pivoted in PHP. `get_post_meta()` in a loop over 4,000 orders is 4,000
  queries and is the commonest reason a WordPress export times out.
- **Written first, saved second.** The batch is appended to the file and *then*
  the checkpoint is written. A request killed between them repeats a batch, which
  is visible; the other order loses one, which is not.
- **Three states on the screen.** Idle, Running, Stalled — the distinction
  `docs/GD-MEDIA-SIDELOADER.md` insisted on. A bar that has not moved because the
  export finished and one that has not moved because the request died look
  identical unless somebody makes them different.
- **A real denominator.** Every stage answers `total()` before it starts, so the
  bar is honest rather than the fake 100% that lane removed. The media stage
  over-estimates — one row per referencing object, where most reference several —
  which the stage contract permits and the reverse forbids.
- **The export is customer data in the web root.** A random export id in the
  path, an `index.php` in the folder and its parent, and a deny-all `.htaccess`.
  The admin screen says in words to delete the folder once it is downloaded:
  `customers.csv` holds every shopper's address and password hash and
  `reviews.csv` holds reviewer emails and IPs — the pair CLAUDE.md names as
  having leaked from `/api/*` before.
- **Capability and nonce on both AJAX endpoints**, not only on the screen that
  draws the button.

---

## 8. Mutation testing — 19 of 19 red

Each guard was broken, the suite run, and the guard restored.

| mutation | verdict |
|---|---|
| gallery separator: pipe → comma | red |
| coupon expiry: bare date → full datetime | red |
| order items: stop excluding refund lines | red |
| reviews: stop normalising the pre-3.0 `comment_type` | red |
| reviews: drop the rating filter | red |
| products: stop putting the Yoast primary category first | red |
| money: stop trimming HPOS's 8-decimal padding | red |
| orders: drop `date_created_gmt` | red |
| orders: stop summing line items into `subtotal`/`fee_total` | red |
| `wp_usermeta` keyed on `meta_id` again | red |
| HPOS detection: always answer "legacy" | red |
| csv: stop doubling an embedded quote | red |
| money: emit `'0'` for an empty cell instead of `''` | red |
| products: stop emitting the slug | red |
| permalinks: report a missing archive as a URL anyway | red |
| `UpdateGuard`: let `wordpress-plugin/` into a package | red |
| **runner: stop pinning the settings to the export** | **GREEN — survived**, then red |
| **manifest: count the header row as data** | **GREEN — survived**, then red |

### The one that survived, which is the valuable line

`'rows' => $rows + 1` in the runner left the **whole suite green**.

Every manifest assertion read `tests/Fixtures/kbb-export/manifest.json` — a
snapshot the mutation did not touch — and the regeneration test compared only the
`*.csv` files, deliberately, because `export_id` and `generated_at` differ per
run. So the one arithmetic the progress bar's honesty rests on was the one thing
nothing re-derived. `rows` is the denominator the contract exists to provide; a
manifest one out on every file is a bar that never reaches the end, and the
duplicate-import guard's `sha256` would have been checked while its row count was
not.

The regeneration test now parses the **freshly written** manifest and asserts
`rows`, `bytes` and `sha256` against the files beside it, and that `counts`
agrees with `files` rather than being computed a second way. Re-run: red.

### The second one that survived, which turned into a fix

`pinned_settings()` returning `$this->settings` instead of what `start()` stored
left the suite **green** — because the harness passed the same settings on every
batch, so pinning could not make a difference.

The guard exists because writing its test is what showed the hazard. The admin
screen posts the **whole form with every batch**, since that is how a
browser-driven resumable job works, so an operator who ticks *include trashed
products* halfway through changes what the remaining batches select.
`products.csv` would then hold the first half under one rule and the rest under
another, with nothing in the file to say where the line is and a manifest row
count that matches neither.

`run-export.php --flip_after=N` now does exactly what that tick does, and
`it pins its settings to the export, not to the request that asked for a batch`
asserts the output is byte-identical to an unflipped run. Re-run: red.

`batch` is deliberately **not** pinned: how many rows fit in a request is a
property of the host, not of the data, and an operator who finds 200 too slow
should be able to drop it without starting again.

### Two more that survived, in a narrower sense, and what that showed

`it hands permalinks.csv to the redirect map without it having to learn a new
word` is insensitive to **exporter** changes: mutating the permalinks stage to
label categories `taxonomy` instead of `category`, and to fabricate a brand
archive URL where none exists, both left that test **green**. It reads the
committed fixture, which the mutation does not touch.

That is not a hole, but it is worth knowing which test is load-bearing for which
kind of change, so it was measured in both directions:

- with the **exporter** mutated, `it regenerates the fixture…` is the test that
  goes red, on a per-file sha256 mismatch — confirmed by running the whole file
  with each mutation applied and reading which one failed.
- with the **fixture** mutated instead (`category` → `taxonomy`; an invented
  brand URL written into `permalinks.csv`), the redirect-map test goes red on
  both — so its assertions do bite, and it is not passing vacuously.

The chain is: an exporter change is caught by regeneration, and a wrong *value*
in the file is caught by the consumability test. Neither covers the other and
both are needed.

Two mutations are also worth naming for *how* they went red rather than that they
did. `usermeta keyed on meta_id` dies with a MySQL error — `wp_usermeta` names
its primary key `umeta_id` and every other meta table names it `meta_id`, a
WordPress quirk that breaks exactly one of the four tables, which is the shape of
bug that passes every test that happens not to cover customers. And `csv: stop
doubling an embedded quote` went red only because the fixture's product
description contains an `<img src="…">` with real quotation marks in it; a
fixture of prose would have let it through.

---

## 9. What still needs one real run on the live site

Everything above was measured against WordPress-**shaped** MySQL tables with a
`$wpdb` stub over PDO. Every SQL statement the plugin issues was executed by a
real MySQL against real WordPress table shapes, and the CSVs were fed to the real
importer. **There is no WordPress in this sandbox and every external host is
blocked by the egress proxy, so the plugin has not been installed.** These are
the things only the live site can answer:

1. **`get_permalink()` and `get_term_link()` under the real rewrite rules and
   filters.** The harness implements what *core* WordPress does from
   `permalink_structure` and `woocommerce_permalinks`; a live site runs every
   filter every plugin installed. This is why `permalinks.csv` carries a `source`
   column per row. **Expected on the live run:** every row `wp`. Any row reading
   `derived` is a row to look at.
2. **Whether `pa_brands` has `attribute_public = 1` on kbeautybliss.com.** The
   harness sets 0, which is WooCommerce's default, and the export then says "no
   brand archive was ever served". If the live site set it to 1 the export will
   say so instead, with the addresses. **Either answer settles `RedirectMap`'s
   open question; this lane cannot settle which.**
3. **Which brand taxonomy the live site actually runs.** The detector tries seven
   spellings and reports which it found. `BrandImporter` expects `pa_brands`.
4. **The real `permalink_structure` and `category_base`.** The harness seeds an
   empty `category_base` because three places in this repository say the live
   site served categories flat. The export reports the setting; the live run
   confirms or refutes it in one line of `manifest.json`.
5. **Volume.** Batching, keyset paging and the checkpoint are exercised at
   `--batch=1` over 58 rows, not at 4,000 orders. The *shapes* are right; the
   wall-clock per batch on the owner's host is not something this can know.
   Start at 200 and lower it if a batch times out.
6. **Yoast keys nobody has documented.** The `SELECT DISTINCT` will find whatever
   the live site wrote. `YoastTiers::unrecognised()` will name them in the import
   report — which is the designed outcome, and the first time anyone will see the
   real list.
7. **Whether the shop runs HPOS.** Both are supported and tested; the manifest
   will say which was used.

---

## 10. Changes for files this lane may not edit

### `app/Console/Commands/BuildPackage.php` — a second lock on `wordpress-plugin/`

`UpdateGuard` already refuses it (§2, measured). This makes `kbb:package` refuse
to put it in the zip in the first place, so the refusal is not the last line of
defence. **Appends one entry to an existing array.**

Anchor — verified **1 occurrence** in `app/Console/Commands/BuildPackage.php`:

```php
        'public-web-root/', 'vendor/', 'node_modules/', '.env',
```

Replacement:

```php
        'public-web-root/', 'vendor/', 'node_modules/', '.env',
        // WordPress code, not shop code. App\Services\Update\UpdateGuard
        // already refuses it -- `wordpress-plugin/` is not an allowed prefix,
        // so checkPath() answers "Path outside the permitted areas" and the
        // whole package is rejected -- and tests/Feature/GeWpExporterTest.php
        // measures that rather than assuming it. This is the second lock: the
        // guard refusing a zip that should never have been built is a worse
        // outcome than the zip not containing it.
        'wordpress-plugin/',
```

### `docs/IMPORT-RUNBOOK.md` — how to run the export

§10 of that document is a page of shell commands for a person with no shell. The
export half of it is now a plugin and an admin screen. **Inserts a new section**;
no existing line changes.

Anchor — verified **1 occurrence** in `docs/IMPORT-RUNBOOK.md`:

```
## 10.
```

Insert immediately **before** that anchor:

```markdown
## 9b. Producing the export (WordPress side)

Upload `wordpress-plugin/kbb-exporter` as a zip through Plugins -> Add New on
kbeautybliss.com, then Tools -> KBB Export. It says which order storage the shop
uses before it starts, writes one batch per request so a shared host cannot time
it out, and resumes from the last completed batch if a request dies.

It writes into `wp-content/uploads/kbb-export/<export id>/`. Download the folder
over FTP and **delete it from the server**: `customers.csv` holds every
shopper's address and password hash, and `reviews.csv` holds reviewers' email
addresses and the IPs they posted from.

`manifest.json` is written LAST, so a folder without one is an export that did
not finish. Read `source.timezone` out of it and pass it as `--timezone`;
`App\Services\Import\DateParser` refuses to default it and reading Dubai
timestamps as UTC shifts the whole order history by four hours.

The full column derivation, the round-trip evidence and what still needs one
real run are in `docs/GE-WP-EXPORTER.md`.
```

---

## 11. What was deliberately not exported

| not exported | why |
|---|---|
| trashed products | `ProductImporter` refuses them by name — "empty the trash or filter the export". Counted in `manifest.json`; switchable off on the admin screen |
| unpublished coupons | `coupons` has no status column, so an imported draft is a live working discount. Counted |
| product comments with no rating | not reviews; `ReviewImporter` refuses them rather than inventing five stars. Counted, and named as a **real loss** — the shop has nowhere to put a product question |
| the whole media library | `media.csv` carries what the catalogue **references**. Every other attachment is a file nobody will request, fetched over a connection to a server about to be switched off. The attachment count is in the notes so the difference is stated |
| `unit_price` on order lines | the importer derives it and reports the truncation; emitting it would bypass a report the owner reads |
| variation-level GTINs | `wpseo_variation_global_identifiers_values` comes out in `seo.csv` via the `wpseo_` prefix scan, but `product_variants` has no `gtin` column — `docs/FX-YOAST-TIER-CENSUS.md` has the migration for that and it is not this lane's file |
| blog comments | not product reviews and not in any file the contract names. Named here so the omission is stated |

Every one of those is in `manifest.json`'s `notes` with a count. A row silently
absent from an export is worse than a row the importer refuses: the refusal is in
a report the owner reads, and the absence is in no report at all.
