# Will anything be lost? — the migration census · Lane GQ

The owner's words: *"make sure everything is there in export and competible to
our app… i want everything super same, nothing should be disturbed."*

He is about to move a five-year-old live shop — **671 products, 4,159 orders,
3,712 customers, 2,514 reviews** — onto this application and switch the old one
off. He is not asking for a feature. He is asking whether anything will be lost.

This document is the answer. §1 is the verdict, in his terms. §§2–6 are the four
lists he asked for: what crosses, what changes, what is dropped, and what would
have been lost in silence. §7 is what only he can decide. §§8–11 are the
working, for whoever has to check it.

The instrument is `tests/Feature/GqMigrationCensusTest.php` — **12 tests, 12
mutations, one of which survived** and is the most useful thing in the file
(§10). It drives the WordPress plugin's own export into
`App\Services\Import\ImportRunner`, the class `kbb:import` runs, with no test
double anywhere in the path.

---

## 1. The verdict

**Nothing that a WooCommerce shop holds is lost in silence any more.** Every one
of the **282 columns** the export writes now ends in one of seven places, and the
census asserts which:

| | columns | |
|---|---:|---|
| **land** in a named table and column | **208** | asserted at the VALUE, not just at the read |
| **named in the discard list** as one line per entity | 44 | the list Phase 13 says the owner *approves* |
| **named individually**, with the value being dropped | 6 | `used_by`, `refunded_items`, a note's author email, two Yoast scores, the primary-category hint |
| read as a **guard** and never stored | 6 | the wrong-timezone check, the refund's currency and sign, the coupon's status, the review's type |
| **carried by a sibling column** that does land | 2 | the two GMT dates |
| read by a **different command** (`kbb:import-redirects`) | 7 | `permalinks.csv` |
| **in a file nothing opens** | 9 | `media.csv`, named whole with its row count |

Three things that *were* being lost in silence were found by building this
census, and all three are fixed in this lane: **the barcodes**, **which size of
a variable product each order line sold**, and **the OpenGraph image and the
noindex flag, which a hyphen had been eating**. §5.

One thing is still in neither the export nor any channel, and it is named here
so it becomes a decision rather than a discovery: **`_order_key`**, the token in
every order-view link WooCommerce has ever emailed. §6.

And six entries of the old "what a clean import leaves empty" list are now
**closed** (§9) — refunds, order notes, variants, attributes, tags and the
Journal all land. What is left empty is shipping, tax, the navigation menu and the pictures,
and each of those is re-entered by hand rather than imported. §4.

---

## 2. What crosses intact

Counts marked **at volume** are from the full-volume rehearsal on MySQL
(`docs/FV-IMPORT-AT-VOLUME.md`), whose products, orders, customers and reviews
are the shop's real figures and whose other files are of the density a real
export has. The mapping itself is proved on the plugin's own export, row by row
and value by value, by `tests/Feature/GqMigrationCensusTest.php`.

| | crosses | where it lands |
|---|---|---|
| **Products** | 671 | `products`, matched on `wc_id` — the id `?add-to-cart=` links in the wild use |
| **Product slugs** | every one, verbatim | Arabic and Korean titles keep their address instead of being romanised into a 404 |
| **Prices** | exact to the fil | integer minor units, never a float |
| **Sale windows** | start and end | in the shop's own timezone |
| **Stock** | count *and* status, with NULL and 0 kept apart | |
| **Categories** | 59, with their tree | and the **leaf** as the primary category, from Yoast's own answer, not the lowest term id |
| **Brands** | 93 | promoted out of the `pa_brands` attribute into their own table |
| **Tags** | 74 at volume, with the product pivot | `tags` + `product_tag` |
| **Attributes** | every `pa_*` term that is not a brand | `attributes`, `attribute_values`, `product_attribute_value` |
| **Variations** | 335 at volume — every size and shade, with its own price, SKU and stock | `product_variants` — and a size the owner had disabled arrives **out of stock**, not on sale |
| **Orders** | 4,159 | every money column, and each order's lines sum to its total |
| **Order line items** | 10,571 at volume | and now the **variant** each one sold |
| **Refunds** | 207 at volume; the money, exactly | `refunds` — the order screen's "still refundable" ceiling is now right |
| **Order notes** | 1,386 at volume | `order_notes`, including whether the shopper could see each one |
| **Customers** | 3,712, **every WordPress user**, not only the `customer` role | so nobody's history is split in two |
| **Passwords** | the WordPress hash | into `legacy_password`, so shoppers sign in with the password they have |
| **Addresses** | billing and shipping, per customer and per order | |
| **Coupons** | 40 published codes, with every restriction and both counters | |
| **Reviews** | 2,514, with rating, author, verified flag and the shop's reply | including the pre-WooCommerce-3.0 ones a straight copy would have refused |
| **SEO** | title, meta description, **OpenGraph image** | `products.seo` |
| **Barcodes** | GTIN-8/12/13/14 and ISBN | `products.gtin`, and published to Google in the Product schema |
| **The Journal** | every article | `posts` — `/skincare-guide/` serves real articles |
| **URLs** | every old address | a separate command, `kbb:import-redirects` |

Run twice, the second pass reports **`created 0, updated 0`** on products and
orders — Eloquent's own dirty check, not the importer's opinion of itself.

---

## 3. What changes on the way, and why

Nothing here is a loss. Each one is a value that arrives in a different shape,
and each is reported as an **ADJUSTMENT** — imported, and not what the export
said — so the owner reads it rather than discovers it.

| what changes | into what | why |
|---|---|---|
| **Money** | a decimal string → **integer fils** | 358.50 becomes 35850. Floats cannot hold a price; the importer refuses a value carrying more precision than fils can represent rather than rounding it |
| **A price carrying fils** | stored exactly, **printed rounded** | this shop prints whole dirhams. 127 of 669 products at volume. `kbb:whole-dirhams --fix` settles them |
| **Dates** | WordPress local or GMT → **Asia/Dubai** | one `--timezone`, taken from the manifest. `date_created_gmt` is in the file for one reason: to catch a wrong one, with a count |
| **A coupon's bare expiry** | → **23:59:59 that day** | WooCommerce treats the expiry day as inclusive; this shop's rule is `now() > expires_at`. Without the correction every live code would retire 24 hours early. 20 codes at volume |
| **A coupon code** | lower-cased | 40 at volume |
| **Order status** | Woo's `wc-*` → this shop's names | `wc-completed` → `completed`. A status this schema has never heard of is refused by name, not guessed |
| **Product status** | `publish/draft/private/pending/future` mapped; **`trash` refused** | importing a trashed product would put it back on sale |
| **Catalogue visibility** | Woo's **four** states → one yes/no | `catalog` means *in the shop but not in search*; read naively it would have **hidden a product the owner had published**. The raw four-state value rides along and is named in the discard list |
| **Slugs** | normalised | and never invented: the export always carries `post_name` |
| **A unit price** | derived by integer division from the line total | Woo exports the line, not the unit. 494 lines at volume do not divide evenly, and each is reported |
| **A refund's negative quantity** | clamped to 0, money untouched | `order_items.quantity` is unsigned. 152 at volume, each reported, because the quantity and the money then disagree |
| **A review with no author** | → "Anonymous" | 15 at volume |
| **`comment_approved = 'trash'`** | → `spam` | 378 at volume |
| **A pre-3.0 review's empty `comment_type`** | → `review` | done in the EXPORT, and counted in the manifest. Without it every review a 2019 shop took before 2017 is refused |
| **A `<script>` in a description** | removed | 7 at volume |

---

## 4. What is deliberately dropped, and what it costs him

Each of these is named in the import report. None of them is silent. The column
on the right is what he actually loses.

### 4.1 Named one line per entity — the discard list he approves (44 columns)

| dropped | what it costs |
|---|---|
| **weight, length, width, height, shipping class** | nothing today: shipping here is a flat rate per zone, not calculated from a parcel. It costs him the *option* of weight-based shipping later without re-entering 671 products |
| **tax status and tax class, per product** | tax here is one shop-wide rate. If some products were zero-rated on the old shop, that distinction is gone |
| **backorders, low stock amount** | the backorder policy and the low-stock threshold become shop-wide settings |
| **upsells, cross-sells, grouped products** | "you may also like" is computed from the category here. Hand-picked recommendations are lost |
| **purchase note** | the per-product message on the order-received page |
| **virtual / downloadable** | nothing: every product this shop sells is a physical good |
| **custom (non-taxonomy) attributes** — `attribute_summary` | e.g. `Scent=Unscented`. The `pa_*` attributes cross in `attributes.csv`; a **free-text** attribute typed into one product has no table here |
| **`roles`** on a customer | this shop has `customers` and `admin_users` and nothing in between. An administrator arrives as a customer |
| **a post's author id and email, comment status, parent, position** | the Journal keeps the author's **name**, takes no comments and is flat |
| **a tag's or attribute value's description, and WordPress's cached counts** | no prose on a tag archive; counts are recomputed here |
| **`attribute_public`** | whether a `pa_*` archive was ever served on WordPress — an answer, kept in `permalinks.csv` rather than in the database |

### 4.2 Named individually, with the value beside the name (6)

| dropped | what it costs |
|---|---|
| **`used_by`** — who has already used a coupon | ▲ **Every imported code starts every shopper again from zero uses.** A one-per-customer code can be used a second time by everyone who already used it. There is no column that could hold this and no way to add one cheaply: it is a fact about the two data models |
| **`refunded_items`** — which lines a refund covered | the money is exact; the per-line breakdown is not. A partial refund shows as an amount against the order, not against the bottle that came back |
| **a note's `author_email`** | `order_notes` keeps the author as a name |
| **`_yoast_wpseo_focuskw` / `_yoast_wpseo_linkdex`** | Yoast's keyphrase and its 0–100 score. Nothing here scores a page. 669 of each at volume |
| **`_yoast_wpseo_primary_product_cat`** | already spent — the exporter used it to put the right category first in `products.csv` |

### 4.3 Held back by the export itself, and counted in the manifest

| held back | what it costs |
|---|---|
| **products in the WordPress trash** | nothing — importing one would put it back on sale. Empty the trash and re-export to change this |
| **unpublished coupons** (draft, pending, trashed) | `coupons` has no status column, so an imported draft is a **live working discount**. Withheld, and the count is in the manifest |
| **product comments with no star rating** | ▲ **customer questions.** This shop has nowhere to put one. Naming them as five stars would publish a score nobody wrote, into the product's average and its structured data. A real loss, named in the manifest |
| **the rest of the media library** | `media.csv` carries the sizes the catalogue actually references. Everything else in `wp-content/uploads` is counted, not listed |

### 4.4 Whole areas nothing carries — re-entered by hand

`kbb:import` leaves these tables **at exactly the count they had before it ran**,
and the census asserts it:

| table | what he re-enters |
|---|---|
| `shipping_zones`, `shipping_methods`, `shipping_zone_locations` | every shipping zone and rate |
| `tax_rates` | every tax rate |
| `delivery_countries` | the countries he ships to and their charges |
| `payment_providers` | gateway configuration — which is secrets, and should be re-entered |
| `menus`, `menu_items` | the navigation menu. WordPress keeps it as a `nav_menu_item` post type, which is on the exporter's denylist and **counted in the manifest notes** with its row count |
| `pages` | WordPress pages are **refused by name**: this shop ships its own `/about/`, `/delivery/`, `/faqs/`, `/privacy-policy/` and `/terms-and-conditions/`, and which of the two he wants is a content decision |
| `media` | `kbb:import-media`, a separate command |
| `redirects` | `kbb:import-redirects`, a separate command |

None of that is in any CSV, so none of it can be in the discard list either. It
is in this document and in the census instead, which is the only channel it can
be in.

---

## 5. What would have been lost in silence — three found, three fixed

These are the reason the census was built rather than described. Every one had
the export column, the destination column and, in two cases, the parsing code
already written. Nothing joined them, and nothing said so.

### 5.1 The barcodes

`wpseo_global_identifier_values` is the Yoast WooCommerce SEO add-on's identifier
map. It was in the export (Lane GE made the `SELECT` match `wpseo_%` as well as
`_yoast_wpseo_%` precisely so it would be), `YoastTiers::gtinFrom()` unpicked
both the JSON and the PHP-serialised shape and validated the check digit,
`products.gtin` existed, and `App\Support\Seo` published it into the Product
schema the moment it was not null. **Nothing wrote the column.** Yoast's own
report line said so out loud — *"NOT imported, AND THERE IS SOMEWHERE FOR IT TO
GO"* — and it had been saying so since Lane FX.

**Fixed.** `SeoImporter` writes it, never over a barcode the owner typed in this
shop, and an MPN or a failed check digit leaves the column alone — the failure
being avoided is not "no barcode", it is *somebody else's barcode*, because
Google matches listings on this field.

**What it was costing:** 671 products publishing to Google as anonymous offers.

### 5.2 Which size of a variable product each order sold

`order_items.csv` carries `variation_id` beside `product_id` because Lane GE
refused to throw the link away. On this side it was read by nothing — correctly,
while there were no variants — and it went on being read by nothing after Lane
GH imported them, with both the row it points at and the column it belongs in
(`order_items.product_variant_id`) sitting there.

**Fixed.** The line now carries the variant *and* `variant_attributes`, which is
what `InvoiceDocument`, `OrderEmailPresenter` and the shopper's own order page
print underneath the product name.

**What it was costing:** every invoice, order email and order page for a variable
product read "Rice Cleanser" with nothing to say whether the customer was sent
the 50ml or the 100ml. The money was right; the record of what was in the parcel
was not.

### 5.3 ▲ The OpenGraph image and the noindex flag, eaten by a hyphen

Two of the five Yoast keys this shop maps carry a **hyphen** —
`_yoast_wpseo_opengraph-image` and `_yoast_wpseo_meta-robots-noindex`. The CSV
reader rewrites every run of non-alphanumerics in a header to a single
underscore, so the column handed to `YoastSeo::pick()` was
`yoast_wpseo_opengraph_image`, and all three spellings it compared against still
had the hyphen in them. **Neither key has ever matched a column produced from a
WordPress meta key of that name** — which is every export this pipe can be
handed.

It was invisible from both ends: the SEO fragment simply came back without them,
and the runner's ignored-column line named them among a dozen others.

**Fixed**, and measured: `products.seo` for the fixture's serum now carries its
`og_image`, and did not before.

**What it was costing:** the social preview image on every shared product link —
and, the expensive half, **a product the owner had deliberately set to noindex in
Yoast would have been published to Google by this shop, with nothing saying so.**

### 5.4 And a fourth: the discard list was overstating the loss

`SeoImporter` reads its row wholesale, because the Yoast keys are discovered from
the file rather than listed in advance. `Row` only counts a column as read when
something asks for it by name — so **every Yoast column looked unread**, and the
runner's consolidated line told the owner, in the one list Phase 13 says he
*approves*, that his meta descriptions, his SEO titles and his barcodes were "in
the file and will not be in the database" while all three were being written.

Seven columns named as lost; four of them imported — `metadesc` and `title` all
along, and `opengraph_image` and the barcodes since §5.1 and §5.3. A discard list with false
entries is worse than a shorter one — he cannot tell which of the seven matters,
so he stops reading all seven. `SeoImporter` now declares the columns a known key
resolved to; a `wpseo`-looking column nothing has ever heard of is genuinely
unread and stays in the consolidated line, which is the only place it is named.

**Two smaller cases of the same shape are left, deliberately, and named here
instead of fixed:** `posts.date_created` and `reviews.comment_date_gmt` are both
reported as dropped, and in both cases the *same instant* crosses under a sibling
column the importer preferred (`date_created_gmt` and `comment_date`). The date
arrives. The report overstates it by two lines out of 44, and the census records
that it does.

---

## 6. ▲ The one thing still in no channel at all: `_order_key`

WooCommerce writes `_order_key` on every order, and every *order-received* and
*view order* link it has ever emailed carries it:

```
https://kbeautybliss.com/checkout/order-received/10233/?key=wc_order_ccc
```

`orders.csv` has no column for it. `orders` has no column for it. It is in
neither the export nor any report channel, so nothing would ever have mentioned
it — the exact shape of the failure this project has already paid for twice.

**What it costs:** those links cannot be served after the cutover, whatever the
redirect map does. How much that matters depends on how often shoppers still open
old order emails; the shop's own account area does not need the key, so a
signed-in customer is unaffected.

**And the value is already read.**
`KBB_Export_Orders_Source` puts `'order_key' => $get( '_order_key' )` on every
row it builds, under both storages — the orders **stage** simply does not list
the column, so the value is fetched and dropped on the floor. The change is one
word in the plugin (not this lane's file) plus a column on `orders`.

Anchor — `wordpress-plugin/kbb-exporter/includes/stages/class-kbb-export-stage-orders.php`
(verified: one occurrence):

```php
				'coupon_code', 'customer_note', 'origin', 'invoice_number',
```

Replacement:

```php
				'coupon_code', 'customer_note', 'origin', 'invoice_number', 'order_key',
```

That alone would put it in the file and therefore in the ignored-column line,
which turns a silent loss into a named one for the price of one word. Landing it
needs `orders.order_key` as well, and whether the old order-view links are worth
a column is the owner's call — §7.

**It is now named** either way, in `gqMetaKeyCensus()` in the census test and
here.

---

## 7. What the owner has to decide

1. **A variable product's headline price is AED 0** on the shop tile, and
   `ORDER BY price ASC` puts it **first**, ahead of a product that really costs
   AED 25. WooCommerce keeps no price on a variable product's parent, so there is
   nothing to import. Fixing it needs two halves in
   `app/Services/Import/Entities/` (`docs/GH-VARIATIONS-AND-ATTRIBUTES.md` §6 has
   both), and the decision is his: should the tile read the cheapest option's
   price, or "from AED 60"?
2. **Per-customer coupon limits restart from zero.** Every shopper who has
   already used a one-per-customer code can use it again. Accept it, or retire
   those codes at the cutover and issue new ones.
3. **The demo catalogue survives the import.** 24 products, 8 brands and 6
   categories ship on every install with no external id, so the shop reads **703
   products for 679 imported**. Delete them at the cutover, or keep them.
4. **`_order_key`** (§6) — are the old order-view links worth a column?
5. **WordPress pages are refused.** This shop ships its own `/about/`,
   `/delivery/`, `/faqs/`, `/privacy-policy/` and `/terms-and-conditions/`. Which
   text does he want on them — his WordPress copy, or the ones here?
6. **An article at a reserved first segment is refused**, by name, in three
   places. `/cart`, `/checkout`, `/wishlist`, `/about`, `/feed` and forty more
   belong to the shop, and an article slugged `about` is an indexed URL this
   application can never serve. He decides which one keeps the address.
7. **Importing attributes does not turn on a storefront size filter.** The
   Attributes screen says `is_filterable` "puts it in the storefront filter
   panel"; `App\Support\Facets` knows `cat`, `brand`, `price`, `sale` and
   `instock` and nothing else. The data is there; the sidebar is not.
8. **Shipping, tax and delivery countries are re-entered by hand** (§4.4). Worth
   doing before the cutover, not after, because checkout needs them.

---

## 8. The census, and how to read it

`tests/Feature/GqMigrationCensusTest.php`. Three columns wide:

1. **what a real WooCommerce shop holds** — read out of
   `wordpress-plugin/harness/`, and out of `manifest.json`'s own census of the
   post types and taxonomies the export found, never from memory;
2. **what the export carries** — the 17 files and their 282 columns;
3. **what the import lands** — a table and a column, or the name of the channel
   that says it did not.

| test | what it fails on |
|---|---|
| `has a census entry for every file the export writes` | a file nobody classified — the shape `coupons.csv` and `reviews.csv` were in |
| `accounts for every column of every file, by name` | a column added to, or removed from, the export |
| `names a real table and a real column for everything it says lands` | a destination that has been renamed, so the value is going somewhere else or nowhere |
| `lands every column the census says lands, and drops exactly the ones it says are dropped` | a column that has moved between the two sides — compared against the report the OWNER reads, not against a second copy of the importer's logic |
| **`finds a real value in every column the census says things land in`** | a column that is still *read* and has stopped *arriving* (§10) |
| `names the one file in the export that nothing opens` | `media.csv` stops being named, or a second file joins it |
| `gives every WordPress post type and taxonomy a destination` | a **new kind of thing** on the shop — the dangerous case |
| `classifies every meta key the WordPress shop actually holds` | a WordPress meta key nobody has classified. MySQL-gated; queries the harness shop directly |
| `fills the tables the census says it fills, and leaves the rest visibly empty` | an entity that has stopped landing, or a gap in §4.4 that a lane has quietly closed |
| three value tests | the barcode, the variant link and the OpenGraph image, pinned by value |

Everything but the meta-key test runs on file-based SQLite and therefore in CI.
The meta-key test needs MySQL and skips where it is absent, exactly as Lane GE's
regeneration test does:

```bash
vendor/bin/pest tests/Feature/GqMigrationCensusTest.php
KBB_TEST_DB=kbb_gq KBB_WP_DB=kbb_wp_gq vendor/bin/pest -c phpunit-mysql.xml tests/Feature/GqMigrationCensusTest.php
```

---

## 9. What `docs/FV-IMPORT-AT-VOLUME.md` §11 said, and what is true now

That section is the list of tables a clean, complete, full-volume import leaves
at zero. **Six of its seven lines are closed.** The list is stale and this is
the correction.

| §11's line | now |
|---|---|
| `refunds` | **CLOSED** — Lane GI. `refunds.csv` lands; the order screen's "still refundable" ceiling was offering the whole of an order that had already been part-refunded |
| `order_notes` | **CLOSED** — Lane GI |
| `product_variants`, `product_variant_attribute_value` | **CLOSED** — Lane GH. Named there as "the largest missing entity by revenue" |
| `attributes`, `attribute_values`, `product_attribute_value` | **CLOSED** — Lane GH |
| `tags`, `product_tag` | **CLOSED** — Lane GH |
| `posts` | **CLOSED** — Lane GJ. `/skincare-guide/` serves real articles |
| `pages` | **STILL 0 from WordPress**, and now by an explicit refusal with a reason, not by nobody having written an importer |

And the three FV named as "not files at all": **media** and **URLs** still need
their own commands, and **a coupon's per-customer usage limit** still cannot
cross at all — that one is unchanged and is a fact about the two data models.

Two more things FV §11 could not have said, because the files did not exist yet:

* **`refunds` has no currency column** — true, and it is now a *guard* rather
  than a gap: `refunds.csv` carries `currency`, `RefundImporter` reads it, and a
  refund in a currency that is not the order's is **reported** instead of being
  silently counted as dirhams.
* **`media.csv` is opened by nothing.** `kbb:import-media` re-derives its
  download list from the imported product URLs. That is defensible — but the
  file carries an `exists` column, a `stat()` taken on the **old server**, which
  answers *"which pictures does the media library name that the disk no longer
  has?"*. That question is answerable now, while the old site is up, and never
  again. It is an unread file, named as one, and it is worth someone's time.

---

## 10. Mutation testing — 12 guards, **one survived**, and that one is the finding

Each guard was broken, the suite run, and the guard restored.

| mutation | verdict |
|---|---|
| `ProductImporter` stops **writing** `sku` | **GREEN — survived**, then red |
| order line loses `product_variant_id` | red (two tests) |
| `OrderItemImporter` stops **reading** `variation_id` | red — the column moves into the discard list, by name |
| `SeoImporter` stops writing the gtin | red (two tests) |
| `YoastSeo::spellings()` loses the underscored candidates | red |
| the runner claims `media.csv` as read | red |
| `RefundImporter` unregistered | red |
| the census loses a column entry | red |
| the census loses the `_order_key` entry | red |
| the census names a column that does not exist | red |
| the census loses the `page` post type | red |
| `SeoImporter` stops declaring the columns it read | red |

### The one that survived, which is why there are two column checks and not one

Deleting `'sku' => $row->text('sku'),` from `ProductImporter`'s `apply()` left the
census comparison **green**.

`ProductImporter` asks for `sku` in a **second** place — the duplicate-SKU check
— so the column was still *read*, never appeared in the ignored-column line, and
the comparison had nothing to notice. The SKU simply stopped arriving in
`products.sku`, on all 671 products, and the census said everything was fine.

**"Read" and "landed" are different claims, and only the second one is what the
owner asked about.** So `finds a real value in every column the census says
things land in` runs the import and requires every `table.column` the census
names to hold a value on at least one **imported** row — counted on the external
id, never on the whole table, because the demo catalogue would otherwise hide the
loss behind its own 24 products. Re-run with the same mutation: **red, naming
`products.sku`**.

The 15 destinations this fixture genuinely cannot exercise are listed in
`gqEmptyInThisFixture()`, each with the row of `wordpress-plugin/harness/shop.php`
that would have to change for it to carry something. Three of them are facts
about WooCommerce rather than about the fixture, and the first is worth reading:

> **A WooCommerce review has no title.** `reviews.title` exists because this
> shop's own review form has one. Every review imported from WordPress will have
> it empty — all 2,514 — and that is a property of the source.

Two more from the same list: `orders.invoice_number` comes from the WooCommerce
PDF Invoices plugin, so a shop that never ran it has none; and `coupons.starts_at`
comes from Smart Coupons' `_wc_sc_start_date`, which core WooCommerce does not
write.

Also worth recording: `expect(...)->toContain($needle, $message)` is **variadic**,
so the message is read as a second needle. It failed one assertion here for the
wrong reason before it was noticed — the same family as
`expect(...)->not->toContain()` passing vacuously, which this repository has paid
for before. Every comparison in the census file is `array_diff`, `str_contains`
or `array_key_exists`.

---

## 11. What changed, file by file

| file | change |
|---|---|
| `app/Services/Import/Entities/SeoImporter.php` | writes `products.gtin` from the identifier map, never over one already there; declares the columns a known Yoast key resolved to, so the discard list stops naming four imported columns as lost; the entity's outcome is `updated` when only the barcode changed |
| `app/Services/Import/Entities/OrderItemImporter.php` | `variation_id` → `order_items.product_variant_id` and `variant_attributes`. A variant not in the import is a **note**, never a rejection — the line is real money. The attribute names are memoised **per variant and not per line**: at 10,571 line items a lookup per line would cost a query and an eager load for every line of every variable product |
| `app/Services/Import/ImportContext.php` | `localId('variations', …)` resolves from the database as well as from the run's map, so the Sales group can land after the Catalogue group |
| `app/Support/YoastSeo.php` | `spellings()` — the underscored candidates the CSV reader actually produces; `recognisedColumns()` so an importer that reads a row wholesale can say which columns it read |
| `app/Support/YoastTiers.php` | the same hyphen fix in its own census; `wpseo_global_identifier_values` is now `imported` rather than "somewhere to go and nothing reads it", and the verdict says which of the two identifier maps is imported and which is not |
| `tests/Feature/YoastTierCensusTest.php` | the third disposition is now asserted on the **per-variation** map, which is where it is true |
| `tests/Feature/GqMigrationCensusTest.php` | the census |

### 11.1 Changes for files this lane may not edit

**`docs/WP-EXPORT-CONTRACT.md`** — the integrator owns it, and its file table is
stale: six of the seven files marked **gap** now have an importer.

Anchor (verified: one occurrence):

```markdown
| `variations.csv` | — | **gap** |
| `refunds.csv` | — | **gap** |
| `order_notes.csv` | — | **gap** |
| `tags.csv` | — | **gap** |
| `attributes.csv` | — | **gap** |
| `posts.csv` | — | **gap** |
| `media.csv` | — | **gap** |
```

Replacement:

```markdown
| `variations.csv` | `VariationImporter` | exists — Lane GH |
| `refunds.csv` | `RefundImporter` | exists — Lane GI |
| `order_notes.csv` | `OrderNoteImporter` | exists — Lane GI |
| `tags.csv` | `TagImporter` | exists — Lane GH |
| `attributes.csv` | `AttributeImporter` | exists — Lane GH |
| `posts.csv` | `PostImporter` | exists — Lane GJ |
| `media.csv` | — | **gap** — `kbb:import-media` re-derives its download list from the imported product URLs instead, so this file is opened by nothing and the unread-file channel names it every run. Its `exists` column is a `stat()` taken on the OLD server and cannot be re-taken after the cutover |
```

**`KBB-Master-Plan.md`** — the integrator owns it. Anchor (verified: one
occurrence, in *Lanes in flight*):

```markdown
- **Lane GQ — is everything there?** *"make sure everything is there in export and
  competible to our app."* Not a feature: a census three columns wide — what a
  real WooCommerce shop holds, what the export carries, what the import lands —
  because the dangerous case is the column that appears in neither the export nor
  any report channel. `coupons.csv` and `reviews.csv` were ignored in silence
  until something finally listed the files nobody opened
```

Replacement:

```markdown
- **Lane GQ — is everything there? ANSWERED.** A census three columns wide, in
  `tests/Feature/GqMigrationCensusTest.php`: **282 columns across 17 files — 208
  land in a named table and column, 44 are named in the discard list, 9 are in a
  file nothing opens, and not one is unaccounted for.** Asserted against the
  plugin's own export and the real `ImportRunner`, so a column that stops
  crossing fails by name rather than arriving empty. Three things that were being
  lost in silence were found by building it and all three are fixed: the
  **barcodes** (`products.gtin`, published to Google), **which size of a variable
  product each order line sold**, and the **OpenGraph image and noindex flag** —
  two Yoast keys with a hyphen in them that the CSV reader turns into an
  underscore, so neither had ever matched on any import ever run. One thing is
  still in no channel and is now named: `_order_key`, the token in every
  order-view link WooCommerce emailed. `docs/FV-IMPORT-AT-VOLUME.md` §11 is
  **stale** — six of its seven lines are closed by Lanes GH, GI and GJ. See
  `docs/GQ-MIGRATION-COMPLETENESS.md`
```

---

## 12. Method

`vendor/` hard-linked, never symlinked. `df -h /` checked before every run —
12 GB free throughout, so nothing here is the full-disk failure that reads as a
transaction bug. No `pkill` by pattern. `php -l` across `app`, `database` and
`routes`.

### ▲ Run `GeWpExporterTest` with `KBB_WP_DB` set, or it fails for reasons that are not yours

`geWpDb()`'s own comment warns about this and it is worth repeating with a
figure. The harness **drops and rebuilds** every table it uses, so two worktrees
running that file at once tear the schema down under each other. Measured in this
lane: the same file, unchanged, three runs against the shared default —

| run | result |
|---|---|
| inside the full suite, no `KBB_WP_DB` | 2 failed |
| alone, no `KBB_WP_DB` | **8 failed, and a different 8** |
| alone, `KBB_WP_DB=kbb_wp_gq` | **38 passed** |

A different set of failures each time is the tell. Both databases were created
for this lane (`kbb_gq`, `kbb_wp_gq`) and both commands below carry them:

```bash
KBB_WP_DB=kbb_wp_gq vendor/bin/pest
KBB_TEST_DB=kbb_gq KBB_WP_DB=kbb_wp_gq vendor/bin/pest -c phpunit-mysql.xml
```

Green on both: **4,692 passed, 24 skipped** on file-based SQLite (never
`:memory:` — the migration set does not survive it), and the same on MySQL.
