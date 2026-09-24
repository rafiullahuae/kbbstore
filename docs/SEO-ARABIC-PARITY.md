# Arabic parity across the SEO surface · round 3

Lane SEO, round 3. Everything a crawler reads, in both languages, fetched.

---

## 1. The hreflang table — every page shape, both languages, what each declares

Fetched from a running server with Arabic switched on. `canonical`, the
`hreflang` cluster and `<html lang>` are read off the rendered document.

| shape | address | `<html lang>` | canonical | cluster |
|---|---|---|---|---|
| home | `/` | `en` | `/` | en, ar, x-default→en |
| home | `/ar/` | **`ar`** | **`/ar/`** | en, ar, x-default→en |
| shop | `/shop/` | `en` | `/shop/` | en, ar, x-default→en |
| shop | `/ar/shop/` | **`ar`** | **`/ar/shop/`** | en, ar, x-default→en |
| category archive | `/product-category/skincare/toners/` | `en` | self | en, ar, x-default→en |
| category archive | `/ar/product-category/skincare/toners/` | **`ar`** | **self, Arabic** | en, ar, x-default→en |
| product | `/product/{slug}/` | `en` | self | en, ar, x-default→en |
| product | `/ar/product/{slug}/` | **`ar`** | **self, Arabic** | en, ar, x-default→en |
| concern | `/concern/acne/` | `en` | self | en, ar, x-default→en |
| concern | `/ar/concern/acne/` | **`ar`** | **self, Arabic** | en, ar, x-default→en |
| journal index | `/skincare-guide/` | `en` | self | en, ar, x-default→en |
| journal index | `/ar/skincare-guide/` | **`ar`** | **self, Arabic** | en, ar, x-default→en |
| content page | `/privacy-policy` | `en` | `/privacy-policy/` | en, ar, x-default→en |
| content page | `/ar/privacy-policy` | **`ar`** | **`/ar/privacy-policy/`** | en, ar, x-default→en |

**Twelve of twelve correct, and none of it is new.** `SeoBilingualTest` already
pins twenty properties of this surface and `BilingualFoundationTest` pins more.
I re-measured all of it rather than reading the tests, and it holds. This round
did not need to change one line of the hreflang or canonical layer.

### The two states that matter as much as the table

**A page that exists in only one language declares only that one.** On this shop
that is the whole site at once, because Arabic is a switch. With it off:

```
sitemap:   67 <url>   0 with /ar/   0 xhtml:link   0 hreflang="ar"
page:      no <link rel="alternate"> at all
/ar/shop/: 404
```

The shop advertises exactly what it serves, in both directions.

**A document the owner marked noindex retracts its cluster; a per-visitor
private page does not.** Measured: a category with `seo.noindex` emits **0**
alternates in both languages, while `/cart/` and `/ar/cart/` emit **3** each —
deliberately, so an Arabic shopper's cart links to the Arabic one. That
distinction is `noindex_editorial` versus `Indexability::isPrivate()` and it was
already built, correctly, with the reasoning written down. I went looking for it
as a defect and found an answer.

---

## 2. What was actually wrong: structured data

### `inLanguage` was absent from the entire graph

Measured on `/ar/product/…` before this round: **zero occurrences** of the
string anywhere in the document, in either language. The graph carried
Organization, WebSite, Product, Offer, Brand, BreadcrumbList, SearchAction —
and nothing that said what language any of it was in.

**Where it now goes, and where it deliberately does not:**

| node | `inLanguage` | why |
|---|---|---|
| `WebSite` | `"en"`, or `["en","ar"]` when both are live | describes the SITE, which is one site in two languages |
| `CollectionPage` | this document's language | it is a CreativeWork and its `url` is this page's own address |
| `Article` | this document's language | same |
| `Product` | **none** | `Product` is not a CreativeWork; schema.org does not define it |
| `Organization`, `Offer`, `Brand`, `BreadcrumbList` | **none** | same reason |

The refusal is the considered half. A property asserted where the vocabulary
does not define it makes a document invalid rather than richer, and a Product's
language is the language of the page — which `<html lang>` and the
CollectionPage/Article node already state. It is the same rule this lane follows
about redirect destinations: do not assert what you have not got.

**`WebSite` says the site's languages, not the page's,** and that is the one
judgement in this round worth stating plainly. The node carries the same `url`
on every page. A node claiming `"ar"` on an Arabic page and `"en"` on an English
one would be two contradictory statements about one thing, both published, with
no way for a consumer to tell which is current.

### The sitelinks searchbox sent Arabic readers to the English shop

```
before   /shop/     urlTemplate: …/shop/?s={search_term_string}
before   /ar/shop/  urlTemplate: …/shop/?s={search_term_string}   ← the English shop
after    /ar/shop/  urlTemplate: …/ar/shop/?s={search_term_string}
```

Google renders that box under the result for the page it crawled. A reader who
found the Arabic page and typed into it left Arabic without touching a language
switch. A search is something a **person does from this page**, which is why it
is localised while the node's own `url` is not.

### What the Organization node does in Arabic

`Store`/`LocalBusiness` is merged **into** the Organization node rather than
emitted beside it, and the address is gated on being genuinely filled in. On a
shop with no address the node is byte-identical in both languages, which is
correct: a business has one address and one telephone number, in every language.
Nothing about Lane S's work needed changing.

---

## 3. The sitemap

| | Arabic on | Arabic off |
|---|---:|---:|
| `<url>` entries | **134** | **67** |
| with `/ar/` | **67** | **0** |
| `xhtml:link` alternates | **134** | **0** |

Every entry carries the whole cluster, so the sitemap says the same thing the
page says. And it stays in step with the router **in both directions**: a
concern page below `MIN_PRODUCTS` is a 404 and absent from the sitemap; tag
enough products and both the English and Arabic entries appear. That is the
property round 1 established for `/concern/{slug}/` and this round pins for
Arabic — the sitemap asks the same class the route asks rather than keeping its
own list.

---

## 4. The interaction with what shipped today

**The cached crawl files cannot be the wrong language, and the reason is
structural.** 2.60.259 made `/sitemap.xml`, `/robots.txt` and `/llms.txt` leave
with `Cache-Control: public, max-age=3600, s-maxage=3600`, so a shared cache may
hold them for an hour. If any could be served under a locale prefix, a crawler
fetching the Arabic spelling would prime a cache with a document built in the
wrong language — and unlike a page, these three carry no `<html lang>` for
anyone to notice it by.

They cannot:

```
/sitemap.xml     200   Cache-Control: max-age=3600, public, s-maxage=3600
/ar/sitemap.xml  404   Cache-Control: no-cache, private
/robots.txt      200   public …        /ar/robots.txt  404
/llms.txt        200   public …        /ar/llms.txt    404
```

`Locale::localisable()` refuses any first segment containing a dot, so
`SetLocaleFromPath` never strips `/ar` off these paths and the router has no
route for the prefixed form. There is no request that reaches the controller
with a non-default locale bound. The 404 alone would not be enough — a document
that varied would still be a hazard — so the unprefixed files are also asserted
to be byte-identical whatever language is live.

**Round 1's fifteen landings, end to end in Arabic.** Neither round pinned this
on its own: round 1 proved `/ar/toners/` 301s into Arabic, but not what the
landing page then says about itself. A redirect that preserved the language and
landed on a page canonicalising to the English address would have moved the
defect one hop rather than fixing it. Measured: `301 → /ar/product-category/
skincare/toners/ → 200`, `<html lang="ar">`, canonical the Arabic address.

**The concern pages** had no Arabic pin at all — `SeoBilingualTest` predates the
route. They now have one.

---

## 5. The prefix/locale blind spot, hunted deliberately

Two rounds running, the mutation that survived the first pass was a guard that
differs only by a prefix — invisible because the test environment has no base
path and English has no locale segment. So this round went looking rather than
waiting.

**Measured with `KBB_BASE_PATH=/kbb-upgrade` set:**

```
sitemap locs        /kbb-upgrade/ and /kbb-upgrade/ar/…    doubled prefix: 0   missing: 0
sitemap alternates  /kbb-upgrade/ar/shop/
robots.txt          Disallow: /kbb-upgrade/cart  and  /kbb-upgrade/ar/cart
                    Sitemap: …/kbb-upgrade/sitemap.xml
llms.txt            /kbb-upgrade/shop/ and /kbb-upgrade/ar/shop/
```

**A negative result, reported as one:** the base path and the locale segment
compose correctly everywhere on this surface, in the only order that can be
right (deployment prefix outermost, language inside it). `SeoBilingualTest`
already pins that composition, which is why. The blind spot is real but it is
not here.

**It was here, though, in this lane's own test.** The block asserting that
`Product` carries no `inLanguage` survived its own mutation. It was written

```php
expect($node)->not->toHaveKey('inLanguage', $message);
```

and Pest's second argument to `toHaveKey()` is an expected **value**, not a
failure message — so it asserted "does not have `inLanguage` set to this long
sentence", which is true whatever the node carries. A test that cannot fail is
worse than none, because it is counted. Rewritten as
`expect(array_key_exists(...))->toBeFalse($message)`, and the mutation is red.

**One other instance of the same shape exists in the repository** — §7.

---

## 6. What this changes for a shop with Arabic off

Nothing except one accurate field.

```
WebSite.inLanguage   "en"        (a bare string, not an array)
SearchAction target  /shop/?s={search_term_string}   (byte-identical)
hreflang             none emitted, as before
sitemap              67 URLs, no xhtml namespace, as before
```

`Locale::withSegment()` adds nothing in English and `Locale::enabledCodes()`
returns `['en']`, so applying this package moves nothing on the shop as it
stands.

---

## 7. Found, not fixed

1. **`tests/Feature/MobileHeaderControlsTest.php:96`** carries the same vacuous
   assertion §5 describes:
   ```php
   expect($vars)->not->toHaveKey($var, "{$var} is emitted at the defaults, so an untouched shop's header moves");
   ```
   That cannot fail, so the guarantee it names — that an untouched shop's header
   does not move — is currently unverified. The fix is the same one this lane
   made: `expect(array_key_exists($var, $vars))->toBeFalse("…")`. Another lane's
   file, so it is reported rather than edited.

2. **The Journal index emits no `CollectionPage` node.** `/skincare-guide/` is a
   listing of articles and says nothing about itself in the graph, in either
   language. That is a structured-data gap rather than an Arabic one, so it is
   out of this round's scope; it would be a one-node addition wherever the
   Journal index sets its context.

3. **`<html dir>` is `ltr` on Arabic pages** until `language_rtl_enabled` is
   switched on. That is deliberate and is Lane FS's surface (Arabic typography),
   not SEO — noted only because it is visible in every measurement above and a
   reader of this document will wonder.
