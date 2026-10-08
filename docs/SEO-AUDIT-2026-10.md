# SEO audit — October 2026 (Lane SEO)

The owner's pasted SEO checklist, checked against the code before the move onto
kbeautybliss.com. **Done** = already in the shop (evidence), **Fixed** = built in
this lane, **Skipped** = deliberately not done (why). Line numbers are at the
lane's commit.

Totals: **29 Done · 4 Fixed · 8 Skipped · 1 needs a server query** (orphan count).

## Technical

| Item | Status | Evidence |
|---|---|---|
| Clean URLs | Done | `/product/{slug}/` routes/web.php:151 (same as WooCommerce); `/collections/{path}/` routes/kbb-brands-blog.php:97 |
| HTTPS | Done | `URL::forceScheme('https')` app/Providers/AppServiceProvider.php:665; http→https in the web root `.htaccess` (docs/CUTOVER-EXTRABEAUTY.md §7) |
| Canonicals | Done | `<link rel="canonical">` app/Support/Seo.php:231 (absolute, from site_url/APP_URL, never the Host header) |
| XML sitemap | Done | routes/web.php:41 → SeoFilesController::sitemap() :334 (visible, non-noindex products, categories, brands, pages, hreflang) |
| robots.txt | Done | SeoFilesController::robots() :1164 — Allow /, Disallow admin/api/cart/checkout/account, `Sitemap:` line |
| 301s for old WooCommerce URLs | Done | product URLs unchanged; `/product-category/…` → CategoryArchiveController routes/web.php:147; imported redirect map + `redirects` table read by CheckRedirects and the 404 handler AppServiceProvider.php:616 |
| Correct 404 | Done | real 404 status with the shop's 404 page (NotFoundPage), logged by NotFoundLogger |
| No accidental noindex | Done | header only when `KBB_NOINDEX` is set (app/Http/Middleware/NoIndexStaging.php:24) or the host is private/unlisted (SiteHost::isPrivate); per-product noindex also leaves the sitemap and the feed |
| Mobile-friendly | Done | every page type at 390 px: scrollWidth 390, CLS 0, no console error (docs/lane-seo-shots/report.json) |
| Fast server response | Done | product 25 ms / 12 queries, category 20 ms / 7, brand 36 ms / 5 on the lane's shop; unchanged by this lane (numbers in the report) |
| 410 for deleted products | Skipped | Google treats a lasting 404 like a 410; adding a "gone" register is work with no ranking benefit |

## Product pages

| Item | Status | Evidence |
|---|---|---|
| Product JSON-LD: name, brand, description | Done | Seo.php:1437-1442 |
| …every product image URL | Done | Seo.php:1444-1462 (main + gallery, absolute, de-duplicated) |
| …SKU, GTIN (valid checksums only) | Done | Seo.php:1465, 1496-1500 (Gtin::isValid) |
| …offers: AED price, currency, availability, per-option AggregateOffer | Done | Seo.php:1628-1630, aggregateOffer() :2141, availability() :2431 |
| …shipping + return policy | Done | Seo.php:1702-1760, behind Store → SEO & Meta → Settings "Merchant listing" |
| …aggregateRating only from real approved reviews | Done | Seo.php:1786-1803 requires rating AND count; fed from approved, non-demo rows (ProductController.php:397-398) |
| …individual `review` nodes | Skipped | recommended, not required; aggregateRating already earns the stars, and five review bodies would add ~2 KB to every product page |
| BreadcrumbList | Done | Seo.php:2070 |
| Unique title + meta description | Done | ProductSeo::metaTitle() :213, metaDescription() :176 (+ owner overrides, SEO Audit flags duplicates) |
| One H1 | Done | store/product.blade.php:524 (verified: exactly one per product page) |
| Open Graph / Twitter cards | Done | Seo.php:258 (og:*), :272 (og:image), :307 (twitter:card), product:price/availability :280-300 |
| JSON-LD validated | Done | parsed from the rendered page; required name/image/offers and price/priceCurrency/availability present — docs/lane-seo-samples/product-jsonld.*.json |

## Images

| Item | Status | Evidence |
|---|---|---|
| Descriptive alt with fallback | Done | Product::altFor() app/Models/Product.php:472 — the owner's stored alt first, else ProductTitle::alt() :104 ("Brand Name", gallery "…, view 2 of 3"); gallery partials/product-gallery.blade.php:69,104; cards components/product-card.blade.php:419. Measured: 0 empty alts on a product page. Bulk alt editing belongs to Lane IR |
| WebP | Done | Media → WebP (Services/Media/WebpBulk, WebpConverter); uploads always end as WebP |
| AVIF | Skipped | GD on Cloudways has no reliable AVIF encoder; WebP is already the win |
| Explicit width/height | Done | product-gallery.blade.php:74 (1000×1000), product-card.blade.php:419 |
| LCP eager, rest lazy, decoding async | Done | product-gallery.blade.php:75 (`eager … fetchpriority="high"`), product-card.blade.php:421-422 |
| Image URLs in schema | Done | Seo.php:1444-1462 |
| Image sitemap | **Fixed** | the code existed (SeoFilesController.php:379) but shipped **off**. Now on: default '1' + migration 2027_10_08_100000 writes '1'. Every gallery image under its product's `<url>` |
| Old `/wp-content/uploads/…` image URLs | **Fixed** | the importer KEPT the paths (MediaRewrite, MediaSideloader::targetPath), so originals keep answering. What 404'd: WordPress resized copies (`-600x600.jpg`, the URLs Google Images mostly holds), `-scaled` originals, and JPEGs converted to WebP then removed. New App\Support\LegacyImageRedirect: one 301 to the file that exists now (follows webp_conversions; Lane IR's rename ledger plugs into `movedTo()`). Only runs inside the 404 handler (AppServiceProvider.php:648), only for upload paths. No WordPress backup upload needed for these |
| Long cache headers for images | Skipped (server) | static files are answered by Cloudways' nginx/Apache, never PHP. Check once: `curl -sI https://kbeautybliss.com/wp-content/uploads/<file>.jpg \| grep -i cache-control`; if absent, Cloudways → Application → Varnish/Browser cache settings |
| EXIF stripped | Done (partly) | every derived file is re-encoded by GD, which writes no EXIF: WebP conversions (WebpConverter) and img-cache sizes (ImageVariants.php:2183-2256) — those are what pages serve via srcset. Originals keep their EXIF; recompressing them is the owner's call |
| File size | Skipped (report) | not mass-recompressed without the owner. To see the distribution on the server: `find public_html/wp-content/uploads -type f -name '*.jpg' -size +300k \| wc -l` (and `-size +100k`) |
| Multi-aspect-ratio crops in schema | Skipped | Google's Product snippet / merchant listing docs ask for product photos, not 16:9/4:3 crops (that advice is for Article/Recipe-style results); cropped product shots would be worse pictures |
| Hotlink protection | Skipped | blocks Google Images, Merchant Center and social previews from fetching the pictures |

## Internal linking

| Item | Status | Evidence |
|---|---|---|
| Categories + breadcrumbs | Done | nested `/collections/` paths, visible breadcrumb + BreadcrumbList |
| Related products | Done | "You may also like" (Services/AlsoLikeRail, ProductController.php:176) and "Buy together" |
| Orphan products | Not measurable offline | on the server: `php artisan tinker --execute="echo App\Models\Product::visible()->whereNull('category_id')->whereDoesntHave('categories')->count();"` |

## Google Merchant Center

| Item | Status | Evidence |
|---|---|---|
| Content API for Shopping (google/apiclient) | Skipped | superseded by the Merchant API, sunset announced for 2026; needs a service account, a dependency and a worker this host does not run |
| Product feed | **Fixed** | new `/feeds/google-merchant.xml` (App\Services\Seo\MerchantFeed): RSS 2.0 + g:, allowlisted attributes only, one item per variant with item_group_id, cached and rebuilt when the catalogue changes; Growth & Marketing → Google Shopping feed. Meta Commerce Manager reads the same URL |
| Feed validated | **Fixed** | xmllint well-formed; every item has id/title/description/link/image_link/availability/price/condition + brand + gtin or identifier_exists=no — docs/lane-seo-samples/google-merchant-feed*.xml |

## The owner's clicks (after the move onto kbeautybliss.com)

1. **Merchant Center**: merchants.google.com → Products → Add products → *Add products from a file* → *Enter a link to your file* → `https://kbeautybliss.com/feeds/google-merchant.xml`, fetch daily, UAE / English / AED. The exact address with a Copy button is on Growth & Marketing → Google Shopping feed.
2. **Meta** (optional): Commerce Manager → Catalogue → Data sources → Data feed → Scheduled feed → the same address.
3. **Search Console**: add the `kbeautybliss.com` property → Sitemaps → submit `https://kbeautybliss.com/sitemap.xml`.

## Speed (lane's measurement shop, same database, base vs lane code interleaved, median of 4×9 runs)

| Page | base ms | lane ms | queries | settings-map reads |
|---|---|---|---|---|
| home | 35.8 | 34.4 | 1 → 1 | 4 → 4 |
| product | 28.4 | 25.4 | 12 → 12 | 6 → 6 |
| category | 22.5 | 20.0 | 7 → 7 | 5 → 5 |
| brand | 39.0 | 35.6 | 5 → 5 | 6 → 6 |

Rendered HTML of all four is byte-identical to the base (only the CSRF token differs). Tools: tools/seo-env.sh, tools/seo-speed.php, tools/seo-dump.php.
