# Arabic addresses · the decision, with the numbers

Lane S5, round 5 of the SEO module. `docs/SEO-MODULE-ROUND-4.md` §6.3 left this
waiting on you:

> **Arabic slugs** need the owner's decision — transliterate or translate, new
> pages only or retrofit — before a line is written.

That question asked you to predict consequences you could not see, so this round
did not ask it again. It **built all three answers, measured what each costs, and
made the choice a switch** — and then measured the switch, in both directions.

**Everything in this document is a fetch or a query, not a reading of the code.**
Where a figure comes from a count rather than a measurement it says so.

---

## The recommendation, in one sentence

> **Leave Arabic addresses as they are** — `/ar/product/anua-heartleaf-toner/` —
> because a translated address buys an Arabic word almost no shopper will ever
> see and costs 1,460 permanent redirects, and because the shopper you are doing
> it for is served an 85-character line of `%D8%A7%D9%84…` the moment they copy
> the link.

You can change that with one control, today or in a year. The switch exists, it
is off, and it has been proven to go back.

---

## 0. What the shop does today, exactly

Fetched through the application's own HTTP stack with Arabic switched on.

| shape | English | Arabic | live today? |
|---|---|---|---|
| Product | `/product/anua-heartleaf-toner/` | `/ar/product/anua-heartleaf-toner/` | yes |
| Category | `/product-category/skincare/toners/` | `/ar/product-category/skincare/toners/` | yes |
| Brand page | `/korean-skincare-brands/anua/` | `/ar/korean-skincare-brands/anua/` | yes |
| Article | `/skin-care-in-the-gulf-summer/` | `/ar/skin-care-in-the-gulf-summer/` | yes |

**One slug, two addresses, four characters apart.** Every `hreflang` cluster on
the shop is built from that fact by one function, `Locale::alternatePaths()`,
which takes a path and swaps the prefix. Measured on all four shapes: `en`, `ar`
and `x-default` are reciprocal, and each page names itself.

**And Arabic is not live.** `language_ar_enabled` has no row, so
`Locale::enabledCodes()` is `['en']`, `Locale::segment('ar')` is `''`, no
`hreflang` is emitted at all, and `/ar/…` answers **404**. Measured, not assumed
— `ArabicSlugPolicyTest` pins all five of those.

**That is why this round is worth doing now.** Nothing Arabic is indexed, so
choosing the address shape is free. After you launch it costs the 1,460 redirects
below, for ever.

---

## 1. The three options, and what each one actually costs

### Option 1 — keep one slug *(what the shop does; the recommendation)*

`/ar/product/anua-heartleaf-toner/`

| | |
|---|---|
| What a shopper sees | a Latin address, in an Arabic page |
| What a shared link looks like | 46 characters, readable, unchanged when pasted |
| What Google does | indexes it as the Arabic version of the English page, on the strength of the `hreflang` pair |
| The English URL | untouched |
| Reversible | nothing to reverse |
| Retrofit cost | **0 rows, 0 redirects** |

### Option 2 — transliterate

The premise is "an Arabic-readable Latin slug", and **the premise is false with
the tooling this project has.** `Str::slug()` is the only transliterator in the
dependency set and is what the WooCommerce importer already uses. Measured:

| Arabic | `Str::slug()` |
|---|---|
| واقي الشمس *(sunscreen)* | `oaky-alshms` |
| مرطب الوجه *(face moisturiser)* | `mrtb-alogh` |
| مرطب الوجه بالشاي الأخضر | `mrtb-alogh-balshay-alakhdr` |
| العناية بالبشرة *(skin care)* | `alaanay-balbshr` |

Arabic script omits short vowels, so the romanisation drops them too. The result
is unreadable to an Arabic reader **and** to an English one. `PostImporter`'s own
comment reached this conclusion before this round did: *"a valid address, and one
no human will ever recognise."*

It also **collides**: `Str::slug('مرطب')` and `Str::slug('مُرَطِب')` are the same
string, so two products would fight over one address.

| | |
|---|---|
| What a shopper sees | `oaky-alshms` |
| The English URL | untouched |
| Retrofit cost | **the same 1,460 rows as option 3, for no gain** |

**Not offered as a policy.** A switch whose label promises readability and
delivers `oaky-alshms` is a switch that misleads the person using it. If you want
a transliteration for a particular product you can type it into that product's
Arabic address box — same mechanism, no false promise.

### Option 3 — translate *(built, measured, switchable)*

`/ar/product/مرطب-الوجه/`

| | |
|---|---|
| What a shopper sees in the address bar | Arabic. This is the real benefit and it is genuine. |
| What a shared link looks like | `https://extrabeauty.ae/ar/product/%D8%A7%D9%84%D8%B9%D9%86%D8%A7%D9%8A%D8%A9-%D8%A8%D8%A7%D9%84%D8%A8%D8%B4%D8%B1%D8%A9/` — **120 characters**, of which the slug is **85**. Measured, for a 15-letter Arabic slug. WhatsApp, Instagram and email all send this form. |
| What Google does | indexes it fine. It has handled non-Latin URLs for years and shows the decoded form in results. |
| The English URL | **untouched.** This is the one thing that had to be true and is: the redirect rows carry `locale = 'ar'`, so `/product/anua-heartleaf-toner/` still answers 200. Measured. |
| Reversible | **yes** — see §4. |
| Retrofit cost | **730 addresses change, 1,460 redirect rows.** §3. |

An earlier lane already met the escaped form on this shop and it cost real work:
one unbreakable percent-encoded Arabic permalink overflowed an article page at
390px, and the fix was `overflow-wrap:anywhere` (`docs/U3-ARTICLE-ADDRESSES.md`).
That is what an 85-character token does to a layout.

---

## 2. What a translated address can and cannot reach

Not every page shape can carry one, and this is a fact about the router rather
than a decision. Measured by fetching each, in both the readable and escaped
spellings:

| shape | Arabic slug routes? | why |
|---|---:|---|
| Product | **200** | `/product/{slug}` constrains nothing |
| Category | **200** | `/product-category/{path}` is `.*` |
| Brand page | **404** | `/korean-skincare-brands/{slug}/` is `[A-Za-z0-9\-_]+` |
| Article | **404** | the site-root route is `PageController::slugPattern()`, whose class is `[a-z0-9]` |
| Content page | **404** | seven literal root routes |

**So a "translate everything" answer is not available.** Two of the four shapes
would need their route regex widened, and both regexes are load-bearing: the
site-root catch-all is what `RESERVED_SLUGS` and `RootSlugCollisionTest` protect,
and widening it to arbitrary UTF-8 reopens the soft-404 space round 1 closed.

`LocaleSlugs::ADDRESSABLE` therefore offers **products and categories only**, and
refuses a brand or article slug outright rather than accepting one that would
404. Of the real catalogue:

| | rows | can carry an Arabic address |
|---|---:|---|
| Products | 671 | yes |
| Categories | 59 | yes |
| Brands | 93 | no — route |
| Articles | 9 | no — route |
| Content pages | 7 | no — route |
| **total** | **839** | **730** |

Row counts are the real import figures (`docs/FV-IMPORT-AT-VOLUME.md`:
671 products, 59 categories, 93 brands; `docs/GJ-POSTS-AND-VERDICT.md`: 9
articles in the database).

**Brands should never have been on the list anyway.** A brand slug is an
identifier — `anua`, `cosrx`, `beauty-of-joseon` — and translating one is the
same mistake as translating a SKU, which `HasTranslations` refuses by name.

---

## 3. The retrofit, in rows

**Measured:** switching the policy writes **exactly two redirect rows per address
that moves** — one for each spelling of the trailing slash, because
`getPathInfo()` reports whichever the client sent and the table compares byte for
byte.

> 730 addresses × 2 spellings = **1,460 redirect rows**, permanent.

"Permanent" is not rhetoric. An indexed address that changes needs a 301 that
outlives the decision; the shop is still carrying rows for a WooCommerce site it
left.

### It is 1,460 and not 4,380, and that is this round's other fix

An Arabic address arrives from a real client **escaped**, and in two spellings —
`%D8%A7…` from browsers and Search Console, `%d8%a7…` from the old WordPress
permalinks. Before this round the redirects table compared the raw request line
against the stored source, so a row could match exactly one spelling. Three
spellings × two slash forms = **6 rows per address, 4,380 in total**, and even
then a client encoding differently would miss.

`CheckRedirects::spellings()` now also consults the percent-decoded path, so **one
row answers all three spellings.** Measured before and after:

| row written as | request | before | after |
|---|---|---:|---:|
| `/العناية-بالبشرة/` | `/العناية-بالبشرة/` | 301 | 301 |
| `/العناية-بالبشرة/` | `/%D8%A7%D9%84…/` | **404** | **301** |
| `/العناية-بالبشرة/` | `/%d8%a7%d9%84…/` | **404** | **301** |

### And this was already broken, today, with no Arabic slugs at all

This is not a fix for a feature nobody has switched on. The old WordPress site
published an Arabic-titled article at a percent-encoded permalink. The importer
refuses to publish it at that address and renames it — `/العناية-بالبشرة/` became
`/skin-care-in-the-gulf-summer/` — then hands you a list at **Store → Import** so
you can write a redirect for the old address. **That list shows the address
decoded, on purpose**, because that is the address a person reads and the one
Search Console shows (`docs/GB-MEDIA-AND-REDIRECTS.md`).

So you paste `/العناية-بالبشرة/` into Redirects & 404s, exactly as told, **and it
404s for every real visitor.** That is fixed, and it is fixed whatever you decide
about slugs.

---

## 4. Reversibility — stated plainly, because you will flip this when nobody is watching

**Yes, it reverses, and it has been measured reversing.**

Switching back to `shared` writes the opposite rows, so every Arabic address that
was live 301s to the shared one. Measured on real rows, all three spellings:

```
/ar/product/مرطب-الوجه/          301 → /ar/product/ats-heartleaf-toner/
/ar/product/%D9%85%D8%B1…/       301 → /ar/product/ats-heartleaf-toner/
/ar/product/%d9%85%d8%b1…/       301 → /ar/product/ats-heartleaf-toner/
```

**Three things make that true, and all three are new in this round.**

1. **`redirects.locale`.** Without it the only row that could be written would
   have moved the English page too. Measured with the column removed: the English
   product page starts 301ing to an Arabic address.
2. **The percent-decoding above.** The reverse rows' *sources* are Arabic. Before
   the fix they could only be matched by a client sending raw UTF-8 bytes, which
   none does — so **the switch would have looked reversible and not been**, and
   nobody would have found out until after flipping it back.
3. **The previous generation is deleted first.** Otherwise the two generations
   point at each other, `CheckRedirects` refuses to follow a loop and serves the
   page, and **both** addresses stop forwarding with nothing on any screen saying
   why. Measured with the delete removed: the reverse 301s read 200.

**The table does not grow.** One generation at a time, measured across three
flips: 4 rows for 2 addresses, still 4.

### What is NOT reversible, and it is worth one line

**What Google has already done is not.** Each flip is a change of address, so
each one costs a re-crawl and a re-consolidation, and a 301 sheds a little
authority every time. Reversible in the sense that no address dead-ends — which
is the sense that matters for a shopper's saved link — not in the sense that a
flip is free.

**So the decision is cheap now and not cheap later.** Right now no Arabic address
is indexed: switching costs 1,460 rows and nothing else. After launch it costs the
same rows plus whatever Arabic ranking has accumulated.

---

## 5. What was built, and what it needs to be live

| piece | file | state |
|---|---|---|
| The policy, `shared` / `translated` | `app/Support/LocaleSlugs.php` | ships at `shared` |
| A second address per row | `locale_slugs` table | created **empty** |
| Serving the Arabic address | `app/Http/Middleware/ResolveLocaleSlugs.php` | **needs one line to register** |
| Canonical + `hreflang` in the reader's spelling | `app/Support/Seo.php` | live, identity while `shared` |
| Arabic-only redirects | `redirects.locale` | NULL on every existing row = both languages |
| The retrofit, both directions | `app/Services/Seo/ArabicSlugRetrofit.php` | `plan()` shows, `apply()` does |
| The percent-spelling fix | `app/Http/Middleware/CheckRedirects.php` | **live now, fixes a defect you have today** |

**One line the integrator adds**, in `AppServiceProvider::boot()` beside the
`CheckRedirects` registration already there:

```php
$kernel->pushMiddleware(\App\Http\Middleware\ResolveLocaleSlugs::class);
```

`pushMiddleware`, because it must run **after** `CheckRedirects` — the other way
round, the retrofit's own redirect and the slug rewrite bounce a visitor between
two addresses for ever. The order is asserted in
`ArabicTranslatedSlugsTest`, not just written down here.

### Still open, named rather than hidden

- **Internal links point at the shared address.** `App\Support\Url::to()` is
  another lane's file and does not know about `locale_slugs`, so with the policy
  on a shopper clicking an Arabic product card is sent to the shared address and
  the redirect forwards them. Correct, indexed correctly, and **one hop more than
  it needs to be**. One change, in one file, when somebody owns it.
- **There is no box to type an Arabic address into yet.** The SEO back office is
  another lane's; §6 says exactly what to add.
- **Brands, articles and content pages cannot carry one at all.** §2.

---

## 6. The controls this needs, and what each should say

For whoever builds the SEO screen. Plain words, because the person reading them
does not know what a slug is.

**Store → SEO & Meta → Arabic addresses** — one card, three things on it.

1. **A choice of two, not a switch.**

   > **Arabic web addresses**
   > ○ **Same address as English** — `/ar/product/anua-heartleaf-toner/`
   >   *Recommended. One address per product, with `/ar` in front for Arabic.*
   > ○ **Arabic address** — `/ar/product/مرطب-الوجه/`
   >   *Arabic shoppers see an Arabic address. Changes the address of every
   >   product and category that has an Arabic name filled in.*

2. **What it will do, before it does it.** `ArabicSlugRetrofit::plan()` is
   read-only and exists for this. Under the choice, in words:

   > Changing this will move **730 addresses** and create **1,460 permanent
   > forwarding rules**. Nothing already indexed will break — every old address
   > forwards to the new one. **The English addresses do not change.**
   > *(the numbers come from `plan()`, so they are this shop's, not an example)*

   And the honest warning, which matters more before launch than after:

   > Best decided **before the Arabic shop goes live**. Afterwards each change
   > costs Google a re-crawl.

3. **A per-row box, on the product and category editors**, beside the Arabic name
   that is already there:

   > **Arabic web address** *(optional)*
   > Leave empty to use the English one. Arabic letters, numbers and hyphens.

   It must show the address it would produce — `/ar/product/مرطب-الوجه/` — and say
   when the box is refused, which happens for exactly three reasons:
   *another product already uses it*, *it contains a character an address cannot
   carry* (`/ . % ?`), or *it is a word the shop itself answers to* (`cart`,
   `shop`). `LocaleSlugs::put()` returns false for each; the screen needs to say
   which.

**Not on the SEO screen:** the policy must **not** be posted by that screen's
ordinary Save. Changing it writes 1,460 rows in the same transaction as the
setting, so it needs its own button and its own confirm. `AdminController::
SETTING_RULES` lists the key only so that an existing value survives a Save of
the tab.

---

## 7. Found, not fixed

1. **`CategoryPath::resolve()` answers `notfound` for a percent-encoded path**
   while the route serves it 200 — the router decodes its parameter and
   `resolve()` does not. Nothing on the storefront is affected, because the
   controller is handed the decoded form; a caller passing a raw path-info (an
   admin screen, the import map) gets 404 where the shop gets 200. Reported rather
   than changed: `normalise()` also writes `category_redirects.from_path`, so
   decoding there changes what is stored, and no live defect was found to justify
   that.

2. **A slashless variant of a hand-written redirect still 404s.** Unchanged from
   `docs/SEO-URL-MAP.md` §6.4, and now less likely to bite: every row this round
   generates writes both spellings.

3. **`Locale.php`, `Url.php`, `SeoFilesController` and `layouts/store.blade.php`
   were not changed**, and two of them would need to be for the translated policy
   to be complete: `Url::to()` for internal links (§5), and the sitemap, which
   emits `xhtml:link` alternates through `Locale::alternatePaths()` directly rather
   than through `Seo`. **The sitemap would therefore advertise the shared address
   under `/ar` while the page canonicalises to the Arabic one** — an inconsistency
   that costs the cluster. It is a one-line change in a file this lane does not
   own: `SeoFilesController` should ask `Seo` for the alternates rather than
   `Locale`. **Do not switch the policy on before that is done.**

4. **`Pest`'s `toContain()` and `toHaveKey()` take extra arguments as more
   needles and as an expected value**, not as failure messages, so
   `->not->toContain('slug', 'message')` and `->toHaveKey('k', 'message')` are
   assertions that cannot fail. `docs/SEO-ARABIC-PARITY.md` §5 and §7 record two
   instances; this round wrote two more and found them **by running the mutation
   and watching the suite stay green**. Both are rewritten as
   `expect(array_key_exists(...))`. It is worth a sweep: the shape is invisible
   in review and every instance is a guarantee that is counted and unverified.
