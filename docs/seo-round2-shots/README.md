# Lane S, round 2 — evidence

Taken in Chromium (Playwright, `deviceScaleFactor: 2`) against the running shop
on a migrated + seeded demo database (24 products, 6 categories, 8 brands,
7 routed pages), signed in as an `owner` admin.

## Screenshots

| File | Width | `clientWidth` | `scrollWidth` | Overflow |
|---|---|---|---|---|
| `business-details-address-1280.png` | 1280 | 1280 | 1280 | none |
| `business-details-address-390.png` | 390 | 390 | 390 | none |
| `seo-audit-alt-1280.png` | 1280 | 1280 | 1280 | none |
| `seo-audit-alt-390.png` | 390 | 390 | 390 | none |
| `concern-acne-1280.png` | 1280 | 1280 | 1347 | **see note** |
| `concern-acne-390.png` | 390 | 390 | 390 | none |

### The 1347 on the concern page at 1280 is the header, and it is not this lane's

Measured on five pages at 1280 with the same rig, in the same session:

```
/concern/acne/   clientWidth 1280  scrollWidth 1347
/new-in/         clientWidth 1280  scrollWidth 1347
/best-sellers/   clientWidth 1280  scrollWidth 1347
/super-sale/     clientWidth 1280  scrollWidth 1347
/shop/           clientWidth 1280  scrollWidth 1345
```

The offenders are `DIV.navitem` / `A.navlink` in the site header — the demo
catalogue seeds eleven top-level nav entries and they do not fit 1280. It is
identical on four pages this lane did not touch, and `/concern/acne/` renders
through the same `store/collection.blade.php` the other three already use, so
this lane neither introduces nor widens it. Reported rather than fixed: the
header is not this lane's, and "nothing that already works may change."

At 390 every page measures 390/390.

## A measurement bug in the rig, recorded because it nearly became a bug report

The first run measured `scrollWidth 408` at 390 on all four collection pages and
not on `/shop/`, with a `width="400"` `<img>` overflowing a 374px
`.kbb-card-thumb`. That looked exactly like a real layout defect in a shared
template.

It was the screenshot rig. The temporary front controller used to serve the app
(this repo has no `public/index.php`, because the production web root is a
different directory — see CLAUDE.md) was routing static files through Laravel,
which returned the stylesheet as `Content-Type: text/html` with
`X-Content-Type-Options: nosniff`. The browser discarded it, so the global
`img{max-width:100%}` in `kbb.css` never applied. Returning `false` from the
router for real files fixed it and all five pages measured 390/390.

Recorded because the failure mode is convincing: an unstyled page measures like
a broken one, and the tell was that `/shop/` — which happened to get its width
from a stylesheet that did load — was clean.

## Store → Business Details → Business · "Where the shop is"

Eight boxes, all shipping blank. The screenshots show them filled in, with the
JSON-LD that produces:

```json
{
  "@context": "https://schema.org",
  "@type": "Store",
  "name": "K-Beauty Bliss",
  "url": "http://127.0.0.1:8777",
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "Shop 4, Al Wasl Road",
    "addressLocality": "Dubai",
    "addressRegion": "Dubai",
    "addressCountry": "AE"
  },
  "telephone": "+971 58 505 2611",
  "geo": { "@type": "GeoCoordinates", "latitude": "25.2048", "longitude": "55.2708" },
  "openingHoursSpecification": [
    { "@type": "OpeningHoursSpecification",
      "dayOfWeek": ["Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"],
      "opens": "10:00", "closes": "22:00" },
    { "@type": "OpeningHoursSpecification",
      "dayOfWeek": "Sunday", "opens": "12:00", "closes": "20:00" }
  ]
}
```

**One node, not two.** That is the whole Organization node — the address is
merged into it rather than emitted as a second `LocalBusiness` node beside it.
The page carries exactly one node of Organization type, asserted by
`LocalBusinessSchemaTest`.

The two hours lines collapsed into two specifications with the days in week
order, the country was upper-cased from the typed `ae`, the blank postal code
was omitted, and the coordinates came out as strings — character-for-character
what was typed.

## Store → SEO & Meta → SEO Audit · "Product image with no alt text"

On the seeded catalogue **as it ships**, this finding reports **0** — and that
is correct rather than broken: not one demo product carries an image at all, so
there is no photograph to describe. `No image` reports 38. That is the
"no photographs, no alt finding" rule working on real rows.

The screenshots were taken after giving twelve products three shots each, two of
them partly described, which is what a real catalogue looks like:

```
45 indexable URLs scanned. Biggest issue: No image (26).
Product image with no alt text .... 12
  Relief Sun Rice + Probiotics SPF50+ .......... 2 of 3 shots
  Glow Deep Serum Rice + Alpha Arbutin ......... 1 of 3 shots
  Advanced Snail 96 Mucin Power Essence ........ 3 of 3 shots
```

**The verdict still names "No image (26)", not the alt finding's 12.** That is
`SeoAudit::ADVISORY` doing its job — a finding where no page is broken does not
take the headline away from one where something is.

### The sample row wraps now

Six of the ten findings carry a `detail` ("3 of 3 shots", "62 chars"). At 390
four inline-flex children on one unwrapped line left the NAME column at nothing
— the row read as a pill, a detail and a URL running off the card, with the one
thing identifying the row squeezed to an ellipsis. The row now wraps, so the
address drops to its own line on a phone. **1280 is unchanged**: all four still
fit on one line, which the two audit screenshots show side by side.

## /concern/acne/

**404 on the day the package lands.** The screenshot was taken after tagging
four products for "Acne & blemishes" under Catalog → Build my routine, which is
exactly what the owner has to do to make the page exist.

- `<h1>` is the sentence a search result wants — "Korean skincare for
  acne-prone skin" — not the back-office label.
- The card eyebrow is the concern's own short label, "ACNE & BLEMISHES", read
  from `RoutineConcerns::labelKey()` rather than a third wording. Before that,
  the page title was used and wrapped to two lines on every card.
- `/new-in/` still prints "NEW IN" on its cards: the short label is optional and
  nothing but the concern page passes one.
- `rel="canonical"` is self-referencing, and the page carries `CollectionPage` +
  `ItemList` + `BreadcrumbList`.
- `/sitemap.xml` gained `<loc>…/concern/acne/</loc>` only once the page existed,
  and had no entry for it before.

The flat pink blocks in the product cards are a 1×1 placeholder PNG stretched by
the card's aspect ratio — the seeded demo catalogue has no photographs, so one
had to be invented to show the cards at their real size.
