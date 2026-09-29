# Can this shop take the kbeautybliss.com catalogue? — Lane IE

The owner has asked twice whether we are ready. This is the answer, and it is
backed by a run rather than by reading: every number below comes from the
WordPress plugin's own export, over WordPress-shaped MySQL tables, fed to
`App\Services\Import\ImportRunner` — the class `kbb:import` runs — and, for the
redirects, through this application's real HTTP kernel. No test doubles anywhere
in the path.

`docs/GQ-MIGRATION-COMPLETENESS.md` is the standing census and remains correct.
This document is what changed after it, what it was wrong about, and the verdict
in the owner's terms.

---

## 1. The verdict

**The catalogue, the orders, the customers, the reviews and the SEO import
cleanly, and they did before this lane.** That part of the answer is "yes", and
`GqMigrationCensusTest` has been asserting it column by column.

**Three things were not ready, and two of them are now.**

| | was | is |
|---|---|---|
| **Old URLs that Google holds for renamed products** | not carried by anything; every one a hard 404 on cutover day | carried, and answering **301** |
| **Customer photographs on reviews** | the column existed and nothing had ever filled it | imported, scheme-checked |
| **The navigation menu** | counted as "WordPress's own machinery" | still **not imported** — but named as itself, with the count of how much retyping |

**What the owner still has to decide is in §5. Nothing in §5 is a bug; each one
is a question only he can answer.**

---

## 2. What was not ready, in detail

### 2.1 ▲ Every address the shop used to serve — and nothing had ever read it

WordPress writes a `_wp_old_slug` row every time a **published** post's slug
changes, and core's `wp_old_slug_redirect()` then answers the old address with a
**301** on every front-end 404.

So a product renamed in 2021 has been quietly redirecting ever since. Google
still holds the old address, shoppers still have it bookmarked, other people's
blogs still link to it — **and nobody has noticed, because it works.**

It works because WordPress is running. Switch the old shop off without carrying
those rows and every one of those addresses is a hard 404 on day one. And the
list **cannot be recovered afterwards**: it only ever existed in the database
that was turned off.

The string `_wp_old_slug` appeared **nowhere** in this repository — not in the
plugin, not in the importer, not in a document.

They now go into `permalinks.csv` with `status` = `old-slug`, carrying the same
`type` and `wc_id` as the object's current row, so `RedirectMap::fromPermalinks()`
already resolves them to wherever the object lives in this shop now. **Nothing on
the Laravel side had to change.**

The address is **not assembled from a base**. `_wp_old_slug` stores a slug, and
this plugin does not guess at bases — the brand-archive note in the permalinks
stage exists because guessing one would have written 93 redirects from an address
that may never have existed. It takes `get_permalink()`'s answer for the post and
swaps the **last path segment**, so a child page's previous address comes out
`/about-us/our-team/` and not `/our-team/`. Where the last segment is not the
post's current slug the address **cannot** be reconstructed, and the row says so
with an empty `permalink` and a note rather than inventing one.

### 2.2 ▲ The photographs on the reviews

`reviews.images` has been a `json` column since the first schema migration: cast
on the model, drawn by the product page with a "+n" chip, filtered on by the
review wall, scheme-checked by `ReviewWall::photos()` on the way out. All of it
built, tested and reachable — and on an imported shop it rendered **nothing**,
because the only writer was a shopper uploading to the **new** site.

A photograph is the part of a review a shop cannot re-create. The owner can
retype a review; he cannot retype a customer's picture of her own face.

`reviews.csv` now carries an `images` column. It recognises a photograph by the
**value** and never by the key — an attachment id this site resolves, or an
address under the site's **own** uploads directory — because review photos are
not WooCommerce core and a list of plugin keys to look for would be a list of
guesses that fails silently on the one shop it is pointed at. Both storage shapes
are handled (attachment ids, and a PHP-serialised array of URLs), and an address
on somebody else's server is refused so the new shop cannot hotlink a stranger's
file from a product page.

**And every comment meta key it did not use is named in `manifest.json` with a
count.** If the live shop shows photographs and `images` comes out empty, that
note is the list of keys to look at — the answer is a key name rather than a
discovery after the cutover.

### 2.3 The navigation menu — still not imported, and now said plainly

§4.4 of the census lists `menus` and `menu_items` among the tables a clean import
leaves untouched, and says the loss is *"counted in the manifest notes with its
row count"*.

**That sentence had never been true of anything.** The note only fires on a shop
that HAS a menu, and the fixture had none — no `nav_menu` term, no
`nav_menu_item` post. Given one, it produced:

> posts.csv does not carry these WordPress post types, **which are either another
> file's job or WordPress's own machinery**: 6 attachment, 3 nav_menu_item.

Both halves of that are false about `nav_menu_item`. No file carries it, and it
is not machinery: it is the header and the mobile drawer — which categories, in
which order, under which names. The owner reading the one discard list Phase 13
says he **approves** was being told his navigation is a cache. §5.4 of the census
already states the rule that breaks: *a discard list with false entries is worse
than a shorter one.*

It is now named as itself, with the counts, and it says the `menus` and
`menu_items` tables carry `source_term_id` and `source_post_id` columns waiting
for exactly this — so it reads as a gap somebody can close rather than a decision
already taken. **It is still retyped by hand.** §5.

---

## 3. The round trip, measured

One export, one import, counted at both ends. The fixture is small; what it
proves is that every path is exercised, not that the volume is survivable —
`docs/FV-IMPORT-AT-VOLUME.md` is the volume rehearsal.

### 3.1 Rows in, rows out

| file | rows in | table | rows out |
|---|---:|---|---:|
| `categories.csv` | 3 | `categories` | **+2** (one adopted an existing slug) |
| `brands.csv` | 2 | `brands` | +0 (both adopted existing slugs) |
| `tags.csv` | 1 | `tags` / `product_tag` | **+1** / **+1** |
| `attributes.csv` | 2 | `attributes` / `attribute_values` | **+1** / **+2** |
| `products.csv` | 4 | `products` | **+4** |
| `variations.csv` | 2 | `product_variants` | **+2** |
| — | | `product_attribute_value` | **+2** |
| `seo.csv` | 4 | `products.seo`, `products.gtin` | see §3.2 |
| `coupons.csv` | 1 | `coupons` | **+1** |
| `customers.csv` | 3 | `customers` / `addresses` | **+4** / **+8** |
| `orders.csv` | 3 | `orders` | **+3** |
| `order_items.csv` | 4 | `order_items` | **+4** |
| `refunds.csv` | 1 | `refunds` | **+1** |
| `order_notes.csv` | 1 | `order_notes` | **+1** |
| `reviews.csv` | 2 | `reviews` | **+2** |
| `posts.csv` | 3 | `posts` | **+1** — two are WordPress **pages**, refused by name |
| `permalinks.csv` | 20 | `redirects` | **11 written**, see §4 |
| `media.csv` | 8 | — | **+0** — nothing opens this file; `kbb:import-media` re-derives its own list |
| — | — | `menus` / `menu_items` | **+0 / +0** — asserted, not assumed |

### 3.2 Field by field, on the things this lane was asked to check

| field | in the export | in the database | survived |
|---|---|---|---|
| **GTIN / barcode** | `wpseo_global_identifier_values` | `products.gtin` = `8809453510003` | **yes** |
| **Brand relation** | `brands.csv` term 502 | `products.brand_id` → `beauty-of-joseon`, `source_term_id` 502 | **yes** |
| **Tags** | `tags.csv` term 601 | `tags.slug` = `k-beauty`, `source_term_id` 601, pivot row present | **yes** |
| **Category nesting** | `parent` = 15 | `face-cleansers.parent_id` = skincare, `path` = `skincare/face-cleansers` | **yes** |
| **Category image** | term meta `thumbnail_id` | `categories.image` = the uploads URL | **yes** |
| **Category position** | term meta `order` | `categories.position` = 1 | **yes** |
| **Variable product** | `products.csv` type | `products.type` handled; 2 variants attached | **yes** |
| **Variation axis** | `attribute_pa_size=50ml` | `product_variant_attribute_value` → `size=50ml`, `size=100ml` | **yes** |
| **Variation price** | `"60.00"`, `"100.00"` | `6000`, `10000` **integer fils, exact** | **yes** |
| **A disabled variation** | `status` = `private` | arrives `stock_status` = **`outofstock`**, not on sale | **yes** |
| **Which variant an order sold** | `order_items.variation_id` | `order_items.product_variant_id` + `variant_attributes` on 1 of 4 lines | **yes** |
| **Product price** | `"99.50"` / `"89.00"` | `9950` / `8900` integer fils | **yes** |
| **SEO title / description** | Yoast meta | `products.seo.title`, `.desc` | **yes** |
| **OpenGraph image** | `_yoast_wpseo_opengraph-image` | `products.seo.og_image` | **yes** |
| **Review photographs** | `images` (new) | `reviews.images` — 2 on one review, 1 on the other | **yes — new in this lane** |
| **Previous addresses** | `permalinks.csv` `old-slug` (new) | `redirects` → **301** | **yes — new in this lane** |
| **Navigation menu** | *not in any file* | `menus` / `menu_items` untouched | **NO — retyped by hand** |
| **An offsite review photo** | refused at export | not in the database | **refused, by design** |
| **A WordPress page** | `posts.csv` | refused by name, named in the discard list | **refused, by design** |

Money is exact to the fil at every boundary measured: `99.50 → 9950`,
`89.00 → 8900`, `60.00 → 6000`, `100.00 → 10000`.

---

## 4. Old URLs — the numbers

The owner's requirement, verbatim: on import the system should convert to the new
slugs, **keep the old record**, and a visitor arriving from Google on an old URL
must be **redirected to the correct new URL instead of a not-found page**.

| | |
|---|---:|
| rows in `permalinks.csv` | **20** |
| …carrying an address the old site really served | **16** |
| …that are **previous** addresses (`_wp_old_slug`) | **4** |
| redirects written by the map, all buckets | **11** |
| **previous addresses answering `301`** | **3 of 4** |

The three that resolve, measured through the real kernel:

```
/product/vitamin-c-serum/   301 ->  /product/serum-4021/
/product/ginseng-elixir/    301 ->  /product/serum-4021/     (a second rename)
/k-beauty-layering/         301 ->  /how-to-layer-a-k-beauty-routine/
```

The fourth is `/about-us/our-team/`, and **it 404s on purpose**. It is a
previous address of a WordPress **page**, and `PostImporter` refuses a page by
name because this shop ships its own `/about/`, `/delivery/`, `/faqs/`,
`/privacy-policy/` and `/terms-and-conditions/`. There is no row for the map to
point at and it will not invent one, so it is filed as a question
(`not-imported`) **with the address named**, rather than lost. §5.3.

The four rows in `permalinks.csv` that carry no address at all are the brand and
attribute terms, whose taxonomies have no public archive on the old site —
WordPress saying, in its own voice, that no address of that kind was ever served.

---

## 5. What only the owner can decide

**5.1 The navigation menu is retyped.** ▲ **CLOSED by Lane MN, plugin 1.7.0** — the navigation is exported as `menus.csv` and `menu_items.csv` and imported into these very columns; `docs/MN-NAVIGATION-IMPORT.md` is the account, including what happens to an item pointing at a page §5.2 refuses. The paragraph below is left as it was written, because it is this document's record of what was true when it was measured.

 3 items across 1 menu in the fixture;
the real figure is in the manifest note on his own export. Nothing imports it.
The `menus` and `menu_items` tables have `source_term_id` and `source_post_id`
columns waiting, so this is a gap a later lane can close — but it is not closed,
and on cutover night the header and the mobile drawer are entered by hand.

**5.2 WordPress pages.** This shop ships its own `/about/`, `/delivery/`,
`/faqs/`, `/privacy-policy/` and `/terms-and-conditions/`. Every WordPress page
is refused by name and named in the discard list with its title and size. **He
has to say, per page, whether he wants his WordPress text or this shop's.** Until
he does, the old page's address — and any previous address of it — 404s.

**5.3 Previous addresses of things this shop declines to import.** Directly
downstream of 5.2. `/about-us/our-team/` is the shape: WordPress was 301ing it,
this shop 404s it, and the map asks about it by name. He either points it
somewhere by hand on **Store → SEO & Meta → Redirects & 404s**, or lets it 404.
The map will not guess.

**5.4 Review photographs the export did not recognise.** The rule is about the
value, and it can miss a plugin that stores a bare filename or a path relative to
the uploads root. The reviews stage names every comment meta key it did not use,
with a count, in `manifest.json`. **If the live shop shows photographs and the
`images` column is empty, that note names the key to look at** — and that is a
five-minute change to the plugin, not a re-architecture. It has to be checked on
the real export before the cutover, because after it the old `wp_commentmeta` is
gone.

**5.5 Everything §§4.1–4.4 of the census already lists** — weights, tax classes,
upsells, `used_by` on a coupon, shipping, tax rates, payment secrets — is
unchanged by this lane and still his to approve.

---

## 6. Where it is in the admin

Nothing in this lane adds or moves a control. The two screens it produces output
on are the ones that already exist:

- **WordPress → Tools → KBB Export** — the notes at the bottom, under *"What
  this export wants you to know"*, now carry the previous-address count, the
  review-photograph keys taken and left, and the navigation count.
  `docs/lane-ie-shots/export-screen-390.png` and `-1280.png` are that screen at
  both widths, with this export's real notes drawn by the page's own
  `renderNotes()`. Measured: `document.documentElement.scrollWidth` is **390** at
  390 and **1280** at 1280 — no horizontal overflow at either; the notes block is
  2238px tall at 390 and 699px at 1280; 15 notes; body font 13px.
- **Store → Import** — unchanged. The photographs arrive through the ordinary
  reviews entity and a refused address is reported as an ordinary discard.

---

## 7. How the remaining gaps were looked for — and the two that are not gaps

The two holes this lane closed were both the same shape: **a column built to
receive imported data that nothing ever wrote to.** `reviews.images` was one;
`menus.source_term_id` and `menu_items.source_post_id` are the other, and they
are still empty.

That shape is searchable, so it was searched rather than reasoned about. Every
`source_*`, `wc_*` and `legacy_*` column in the schema, against every writer in
`app/`:

| slot | written by | verdict |
|---|---|---|
| `wc_id`, `wc_order_id`, `wc_item_id`, `wc_refund_id` | the entity importers | filled |
| `source_term_id` (categories, brands, tags, attribute values) | the entity importers | filled |
| `source_post_id` (posts) | `PostImporter` | filled |
| `source_attribute_id` | `AttributeImporter` | filled |
| `source_comment_id` | `OrderNoteImporter` | filled |
| `source_key` (addresses) | `AddressWriter` | filled |
| `legacy_password` | `CustomerImporter` | filled |
| `source_id` (reviews) | `ReviewImporter` | filled |
| **`menus.source_term_id`, `menu_items.source_post_id`** | **nothing** | **§2.3 — the navigation** |
| `media.source_attachment_id` | nothing | **not a new gap** — `media.csv` is the file nothing opens, by decision; `kbb:import-media` re-derives its download list from the imported product URLs. Named in the contract and in census §4.4 |
| `shipping_zones.source_zone_id` | nothing | **not a new gap** — shipping is re-entered by hand, census §4.4 |

So: **after the navigation, there is no third column of this kind.** The two
that remain empty are both decisions already written down and approved, not
discoveries.

---

## 8. The instrument

`tests/Feature/IeLegacyAddressesAndPhotosTest.php` — **14 tests, 10 mutation
notes, all 10 run.**

One of them **survived** on the first attempt and is the most useful thing in the
file: deleting the importer's scheme check left the whole suite green, because
the export refuses an offsite address at source, so on the plugin's own output
no bad value ever reached the importer. Two defences, one asserted, and no way to
tell which. The file now also feeds `ImportRunner` a `reviews.csv` this plugin
did not write — an executable address, a protocol-relative one and a real picture
in the same cell — which is the ordinary case for a shop that also takes uploads
through **Store → Import** and **Store → Reviews → Import**.

Two other things were found by an assertion going red on correct behaviour rather
than by design:

- `json_encode()` escapes `/` as `\/`, so a `str_contains()` for a **path**
  silently never matches. Twice in this file, both times reading "the shop
  offers nothing" about a shop that offers everything.
- the photograph-key note was tallied across batches, and **a batch is a separate
  HTTP request**. It would have named only the last request's keys on the live
  site and stayed green here forever, because two reviews fit in one batch. It is
  recomputed now, and the test asserts `--batch=1` and `--batch=500` agree.

`GqMigrationCensusTest` learns the new column, the two new post types, the new
taxonomy and the ten new meta keys; both halves of it went red on this lane's
work before they were classified, which is the census doing its job.
