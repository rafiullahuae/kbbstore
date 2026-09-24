# Lane S — evidence

Taken in Chromium (Playwright, `deviceScaleFactor: 2`) against the running shop
on a migrated + seeded demo database (24 products, 6 categories, 8 brands,
7 routed pages), signed in as an `owner` admin.

## Screenshots

| File | Width | `clientWidth` | `scrollWidth` | Overflow |
|---|---|---|---|---|
| `seo-audit-1280.png` | 1280 | 1280 | 1280 | none |
| `seo-audit-390.png` | 390 | 390 | 390 | none |
| `sitemap-control-1280.png` | 1280 | 1280 | 1280 | none |
| `sitemap-control-390.png` | 390 | 390 | 390 | none |

`document.documentElement.scrollWidth === clientWidth` at both widths on both
screens, so neither addition introduces a horizontal scrollbar.

## What the audit screen reported, on real rows

**Store → SEO & Meta → SEO Audit**

```
45 indexable URLs scanned. Biggest issue: No image (38).
Products 24 · Categories 6 · Brands 8 · Articles 0 · Pages 7
11 cards rendered (1 verdict + 10 findings)
```

It found a genuine defect in the seeded catalogue on its first run:
**24 products share one meta description** — *"demo product for layout testing.
replaced by the wordpress migration."* That is exactly the class of problem
`Catalogue Audit` cannot see, because it is a property of the set rather than of
any one row, and it is reported here as 24 pages with a problem rather than as
one repeated string.

`Duplicate titles` reported 0 and printed *"None — nothing on the shop has this
problem"*, which is the other half of a report being trustworthy: a clean check
says so rather than rendering nothing.

## The sitemap control

**Store → SEO & Meta → Settings · Sitemap & robots · "Product images in sitemap"**

The select is present and reads **`0` ("Not included")** on a shop that has never
been told otherwise — rule 1: a new setting ships at the value the page already
has.

## The sitemap, before and after

`sitemap-images-off.xml` and `sitemap-images-on.xml` are the two documents
`/sitemap.xml` served from the same database, differing only in the setting.

| | bytes | `<image:image>` | `xmlns:image` |
|---|---|---|---|
| setting absent (ships like this) | 8107 | 0 | not declared |
| setting `1` | 8506 | 3 | declared |

The **off** capture was taken twice — once before any product had an image, and
once after a product had been given three — and the two are **byte-identical**
(`cmp` reports no difference). So the switch, not the data, is what moves the
file: applying this package changes nothing until an operator ticks the box.

With it on, one product's entry reads:

```xml
<url><loc>http://localhost/product/relief-sun-rice-probiotics-spf50/</loc>
  <lastmod>2026-09-24</lastmod><changefreq>weekly</changefreq><priority>0.8</priority>
  <image:image><image:loc>http://localhost/media/relief-sun-rice-probiotics-spf50-featured.jpg</image:loc></image:image>
  <image:image><image:loc>http://localhost/media/relief-sun-rice-probiotics-spf50-2.jpg</image:loc></image:image>
  <image:image><image:loc>https://cdn.example/relief-sun-rice-probiotics-spf50-3.jpg</image:loc></image:image>
</url>
```

Three things to read off that: the featured image is **first**; it appears
**once** although it is also the first entry of the gallery (de-duplicated); and
the absolute CDN URL is passed through untouched while the relative ones are
rooted on the site URL.

## How these were produced

A local front controller under `/tmp` served the app, because
`bootstrap/app.php` pins `usePublicPath()` to the production host's web root and
`php artisan serve` therefore cannot start here. That file is deliberately left
alone (CLAUDE.md), and nothing in this repo was changed to take these shots:
the `routes/web.php` require, the `.env` session driver and the two Playwright
scripts were all reverted or deleted afterwards, and `git status` shows
`routes/web.php` unmodified.
