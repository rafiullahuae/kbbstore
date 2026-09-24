# The real URL map · what moved, what did not, and where every old address lands

Lane SEO, round 1. Written for the owner, and it starts by correcting the
sentence this round was asked for.

---

## 0. "Moving from /category/ to /collection/" — that is not what this shop does

That description does not match the code, and building to it would have broken
working pages. Established against the router itself (`routes/web.php`,
`routes/kbb-brands-blog.php`, `routes/concern-collections.php`), not from
memory:

- **There is no `/category/` anywhere.** The old WooCommerce site served its
  category pages **flat at the site root** — `kbeautybliss.com/toners/`,
  `/sunscreens/`, `/skincare-sets/`. It never used a `/category/` prefix,
  because `woocommerce_permalinks['category_base']` on that install was the
  **empty string**. The exporter reads that setting straight out of the live
  database and writes it into `manifest.json`, so this is the old site saying
  it rather than anybody deducing it.
- **This shop serves categories at `/product-category/…`, nested.**
  `/product-category/skincare/toners/` — the full parent path, not the bare
  leaf.
- **"Collections" are a different thing here, and they must not be touched.**
  `/new-in`, `/best-sellers`, `/super-sale`, `/everything-under-54-aed` and
  `/concern/{slug}/` are curated listings with their own routes
  (`CollectionController`). They answer 200 today. Sending a category to one of
  them would point a shopper at the wrong products and would break a live page.

So the move is **flat root → nested `/product-category/`**, not category →
collection. One more correction while we are here: **the Journal does not live
at `/skincare-guide/{slug}/`.** Articles live at the **site root**,
`/{slug}/`, exactly as the old site served them; `/skincare-guide/{slug}/` is a
retired address that 301s onto it.

---

## 1. The map, in full

| What | Old address (kbeautybliss.com) | This shop | Who does it |
|---|---|---|---|
| Category archive | `/toners/` (flat, at the root) | `/product-category/skincare/toners/` | **new this round** — derived 301 |
| Product | `/product/{slug}/` | `/product/{slug}/` — **unchanged** | nothing needed |
| Journal article | `/{slug}/` (at the root) | `/{slug}/` — **unchanged** | nothing needed |
| Journal index | `/blog/` | `/skincare-guide/` | route |
| Retired article prefixes | `/blog/{slug}/`, `/post/{slug}/`, `/skincare-guide/{slug}/` | `/{slug}/` | 10 shipped rows + 2 routes |
| Content pages | `/about/`, `/contact-us/`, `/faqs/` … | same addresses — **unchanged** | nothing needed |
| Brand archive | *the old site served none* | brands are `/shop/?filter_brands=…` | nothing to land |
| Shop pagination | `/shop/page/2/` | `/shop/?paged=2` | route |

**The headline: most of the old site did not move.** Products, articles and
pages are at the addresses they always were. The category archives are the
whole of the breakage, and they were **all fifteen of them**.

---

## 2. The fifteen, before and after — fetched, not asserted

Both columns are real requests through this application's HTTP stack, with the
catalogue imported. `Location` is the raw header.

**Before this round — every one of them:**

| old address | status | Location |
|---|---|---|
| all fifteen | **404** | — |

**After:**

| old address | status | Location |
|---|---|---|
| `/skincare/` | **301** | `/product-category/skincare/` |
| `/face-cleansers/` | **301** | `/product-category/skincare/face-cleansers/` |
| `/cleansing-oils/` | **301** | `/product-category/skincare/face-cleansers/cleansing-oils/` |
| `/face-washes/` | **301** | `/product-category/skincare/face-cleansers/face-washes/` |
| `/exfoliators/` | **301** | `/product-category/skincare/exfoliators/` |
| `/toners/` | **301** | `/product-category/skincare/toners/` |
| `/face-serums/` | **301** | `/product-category/skincare/face-serums/` |
| `/eye-care/` | **301** | `/product-category/skincare/eye-care/` |
| `/face-masks/` | **301** | `/product-category/skincare/face-masks/` |
| `/moisturizers/` | **301** | `/product-category/skincare/moisturizers/` |
| `/lip-care/` | **301** | `/product-category/skincare/lip-care/` |
| `/sunscreens/` | **301** | `/product-category/skincare/sunscreens/` |
| `/hair-care/` | **301** | `/product-category/hair-care/` |
| `/skincare-sets/` | **301** | `/product-category/skincare-sets/` |
| `/beauty-devices/` | **301** | `/product-category/beauty-devices/` |

**And every one of those fifteen destinations was then fetched: 15 of 15
answer 200.** One hop, no chain, nothing landing on a not-found page.

### Why this matters more than it looks

`docs/CUTOVER-EXTRABEAUTY.md` §4.1 forwards `kbeautybliss.com` to
`extrabeauty.ae` **keeping the path**. So before this round, every one of those
fifteen was a 301 that ended on a 404: the ranking was not inherited, it was
dropped, and nothing on any screen said so. Two of the fifteen are confirmed
indexed today with their live titles — `/skincare/` and `/skincare-sets/`.

---

## 3. Arabic

Same fifteen, with Arabic switched on. Fetched the same way:

| Arabic address | status | Location |
|---|---|---|
| `/ar/toners/` | **301** | `/ar/product-category/skincare/toners/` |
| `/ar/skincare-sets/` | **301** | `/ar/product-category/skincare-sets/` |
| … all fifteen … | **301** | `/ar/product-category/…/` |

**The `/ar` is kept, exactly once, on all fifteen.** An Arabic reader following
an old link lands on the Arabic page, not the English one.

**The rule, and it was measured rather than reasoned about.** Three middlewares
run before the router, in this order:

```
CanonicalHost  →  SetLocaleFromPath  →  CheckRedirects
```

`SetLocaleFromPath` strips `/ar` **before** the redirect check sees the path, so
the fifteen need no Arabic spelling — one list serves both languages.
`Url::redirect()` then puts `/ar` back on the way out. The ordering is asserted
against the live middleware stack in the test suite, so a future change that
inverted it fails rather than silently 404ing every Arabic old address.

---

## 4. How it works, and why there is no new screen

There is **no new setting, no new control and no new admin screen** in this
round, so there is nothing for you to switch on. The redirect is worked out
from your own catalogue on the fly:

> If the address is one of the fifteen, **and** no article of yours is published
> at that name, **and** a category of yours answers to it — then 301 to that
> category's real address. Otherwise, leave it alone.

Three consequences worth knowing:

1. **It cannot send anybody to a made-up page.** The destination is a category
   that exists. A name nothing in your shop answers to still returns 404 —
   deliberately. A confident redirect to the wrong page would hand your ranking
   to the wrong product listing and you would never find out; a 404 you can see.
2. **It fixes itself when you import.** Right now the demo catalogue only has
   `toners` and `sunscreens`, so only those two forward. Import the real
   catalogue and all fifteen start working the same day, with nothing to apply.
3. **Your own decisions still win.** A row you have written at
   **Store → SEO & Meta → Redirects & 404s** overrides this for that address,
   and switching that row off falls back to it.

**One thing to expect on that screen:** these fifteen redirects have **no hit
counter**, because they are not rows — there is nothing to count against. If
you want them counted, or want to change where one of them goes, write a row
for it and yours takes over.

---

## 5. The other address shapes — what was ruled out, and on what evidence

Round 1 was asked to cover every content type the old site had. Here is each
one, and why it is or is not in this package.

| Shape | Verdict | Evidence |
|---|---|---|
| **Category archives** (flat root) | **FIXED this round** | `LegacyCategoryUrls::PATHS`, corroborated by `category_base` being empty in the exporter's `manifest.json` |
| **Products** `/product/{slug}/` | **Nothing to do.** WooCommerce's default product base and this shop's are the same string, and the importer never rewrites a slug — it adopts it or refuses it | `RedirectMap` class comment; `SlugGuard` |
| **Journal articles** `/{slug}/` | **Nothing to do.** Same shape on both sites | `2026_09_14_160000_seed_phase9_post_url_redirects`, which records you confirming it |
| **Retired article prefixes** | **Already working**, measured this round and unchanged | 10 shipped rows + `/skincare-guide/{slug}/` and `/post/{slug}/` routes |
| **Content pages** | **Nothing to do.** Seven literal root routes at the old addresses | `routes/web.php` |
| **`/category/…` and `/tag/…` prefixes** | **RULED OUT — that install never used them.** `category_base` was empty, which is the same setting that put the categories at the root in the first place | exporter `manifest.json → source.woocommerce_permalinks` |
| **Brand archives** | **RULED OUT — no archive was ever served.** The exporter writes a row per brand with an **empty** permalink and a note saying WordPress returned no archive URL for that taxonomy | `class-kbb-export-stage-permalinks.php`; `docs/GE-WP-EXPORTER.md` §5 |
| **Attribute archives** (`/pa_size/50ml/`) | **NOT GUESSED — the export answers it.** An attribute has indexed URLs only if `attribute_public` is set, which is a fact about your old database that cannot be known from here. It is a column in `attributes.csv` | `class-kbb-export-stage-attributes.php` |
| **Product tags** | **Same — the export answers it.** This shop has no tag archive route at all, so if the old site published them they need a decision, not a guess | `permalinks.csv` carries a row per `product_tag` term |
| **Paginated archives** `/toners/page/2/` | **Not built, and not guessed.** The exporter writes one row per object, not per page of an archive. `/shop/page/2/` is already handled by a route | `routes/web.php`; permalinks stage |
| **Feeds** `/feed/`, `/comments/feed/` | **Deliberately not redirected.** A feed is not an HTML page, and 301ing a feed reader onto a shop page is worse than the 404 it gets today | — |
| **`?p=123`, `/?page_id=…`** | **Structurally impossible here, and this is the one you may need to act on.** The redirect check compares against the request path, which **excludes the query string entirely**. A row for `/?p=123` could never match. If those addresses carry real traffic the answer is a rewrite rule in the host's `.htaccess`, outside this application | `CheckRedirects::findMatch()` |
| **Attachment pages** | **Not built.** WordPress serves a page per uploaded image; whether that install had them public is in the export, not in this repository | — |

**The honest summary: three shapes are ruled out on hard evidence** (brand
archives, `/category/`, `/tag/`), **one is impossible from inside the
application** (`?p=123`), and **the rest are answered by the export file you
produce**, which is round 2's job — the importer sensing and fixing addresses
by itself.

---

## 6. What is left, and who has to do it

1. **`?p=123` needs an `.htaccess` rule** if it carries traffic. Only you can
   decide that, and it cannot be done from inside this application. §5.
2. **Import the catalogue** and the other thirteen of the fifteen start
   forwarding the same day. Nothing to apply, nothing to switch on.
3. **`APP_URL` must be right on the server before this package is applied.**
   Every redirect is built from it — as password resets and the payment
   webhooks already are — so a wrong value sends the fifteen to the wrong host.
   `docs/CUTOVER-EXTRABEAUTY.md` §2 is the authority.
4. **A slashless variant of a row you wrote yourself still 404s.** If you add a
   redirect for `/foo/` by hand, a visitor arriving at `/foo` does not get it.
   The shipped rows all carry both spellings for this reason; a hand-written one
   does not. Reported here rather than changed in the same patch, because
   widening what the table matches touches every redirect on the shop.
