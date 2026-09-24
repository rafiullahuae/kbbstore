# The SEO module · round 4 — what I took, and what I refused

Lane SEO, round 4. `docs/SEO-BUILD-PLAN.md` treated as a ranking to argue with.

---

## 1. The decision table

| Plan item | Verdict | Why |
|---|---|---|
| **Part II item 9** — the four collection-key lists cannot drift | **TAKEN, and done better than asked** | Its failure mode is a page that works and never enters the sitemap. §2 |
| **Part I item 5** — sitemap index + sub-sitemaps | **Refused** | ~1,400 URLs against a 50,000 limit. The plan ranks it low, the matrix agrees, the coordinator agrees |
| **Part I item 6** — chunked sitemap generation | **Refused** | Same threshold, and the plan says the two should be done together. 20 queries against a budget of 24, unchanged |
| **Part I item 7** — news/blog sitemap | **Refused** | The plan's own words: "cargo cult unless the shop is accepted into Google News", which a retail journal will not be |
| **Part I item 8** — hreflang: verify, do not rebuild | **Already done — round 3** | Verified by fetching twelve page/language combinations, not by reading the tests |
| **Part II item 7** — Arabic slugs | **Refused this round** | The plan itself says "decide before building": transliterate or translate, new pages only or retrofit. That is an owner decision, not a lane's |
| **Part II item 10** — merchant shipping/returns | **Refused** | Already built and off. Turning it on needs written confirmation of live terms; a wrong shipping figure is a promise Google shows a shopper |
| **Part II item 11** — image `alt` coverage | **Refused** | Needs a schema change and touches the product editor, which is not this lane's |
| **My own §7.2** — `CollectionPage` on the Journal index | **Refused — wrong lane** | It needs `PageController::blog()`, which I do not own. Exact edit handed over in §5 |

### And one item the matrix can now retire

`SEO-FEATURE-MATRIX.md` §2 "Where we genuinely lose" lists five. Item 4 is
**"Fifteen old addresses that will 404 the day we go live… it is fifteen rows."**
That is no longer true: since round 1 the shop derives those redirects itself,
so it is **zero rows** and needs no import run and no typing. The other four —
concern content, the article programme, editorial links, Arabic copy — are
content and outreach, exactly as the matrix says.

**So after four rounds there is no remaining CODE item in "where we genuinely
lose".** That is worth the owner knowing, because it means further SEO-module
work has a low ceiling and the ranking now moves on writing.

---

## 2. What I took: the sitemap stops keeping its own lists

Plan item 9, in its own words:

> Four hardcoded lists that must agree, with nothing enforcing it. Miss the last
> one and the page works perfectly and never enters the sitemap — a silent
> failure that no screenshot catches.

**It asked for a test that the lists agree. I removed the sitemap's copies
instead, so there is nothing left to drift from.** `SeoFilesController` now asks
the router:

- the curated listings (`/new-in/`, `/best-sellers/`, `/super-sale/`,
  `/everything-under-54-aed/`) come from the routes bound to
  `CollectionController@show`;
- the content pages come from the routes bound to `PageController@show`, keyed
  by the `slug` default each one carries — which is the same string the
  controller reads.

That is the move the concern block beside them already made and said so in
capitals: *"the same question the router asks, asked of the same class."* These
two blocks were the last on the page still doing it the other way.

**Why "the key sets are equal" would have been the wrong test.** The route path
and the collection key are different strings: the internal key `under-54` is
served at `/everything-under-54-aed/`. A test asserting the two sets equal would
have failed on a correct shop, and the obvious way to make it pass — renaming
one to match — would have changed a live, indexed URL.

**What stays a test, and why.** `CollectionController::show()` opens with
`abort_unless(isset(self::COLLECTIONS[$key]), 404)`, so a route whose `key`
default is not in that map 404s — and a sitemap built from the router alone
would advertise it. `COLLECTIONS` is `private`, so this half cannot become a
lookup; it is asserted instead, before the bad route ships. That is the same
arrangement `RootSlugCollisionTest` already uses for the reserved-segment half,
which is why that half is not re-tested here.

### The second bug this turned up

The line that built a content page's URL did `'/' . $page->slug . '/'` — so the
sitemap assumed a page's slug and its address are spelled the same. They need
not be, and **this application already does the opposite one block away**:
`under-54` is served at `/everything-under-54-aed/`. Register `/about-us` with
`->defaults('slug', 'about')` and the shop serves the page while the sitemap
advertises `/about/`, which 404s.

The slug now identifies the **row** and the router's URI identifies the
**address**, which is what each of them actually is. Found by chasing a mutation
that survived (§4).

### Rule 1, proved rather than claimed

`/sitemap.xml` fetched from a running server with the old implementation and the
new one, on the same database:

```
before   10,586 bytes    67 <url>
after    10,586 bytes    67 <url>
diff     IDENTICAL — zero bytes changed
```

`robots.txt` and `llms.txt` unchanged by checksum. Registration order is the
order the literals used, which is why.

---

## 3. Found, not fixed: a developer page inviting Google in

**`/_design-check` answers 200 to anybody and asks to be indexed.**

```
GET /_design-check            200
  <title>Design check · KBB | K-Beauty Bliss</title>
  <h1>Phase 1 — design check</h1>
  <meta name="robots" content="index, follow">
  <link rel="canonical" href="…/_design-check/">
robots.txt                    does not mention it
sitemap.xml                   does not contain it
```

`routes/web.php:821` calls it *"Temporary: the Phase 1 design check. Delete when
Phase 2 lands."* The shop is in Phase 20. The controller's own docblock says
*"Delete once Phase 2 lands."* It renders eight real products.

**Why it matters more than it looks.** `SEO-FEATURE-MATRIX.md` makes crawl
hygiene one of the things this shop *wins* on — the competitor has `.atom` feeds
and search-parameter URLs in Google's index and we "publish no second
machine-readable address per listing", noted *"so nobody 'fixes' a problem we do
not have"*. We do have one: a page whose `<title>` carries the brand, that
self-canonicalises, and that explicitly invites indexing.

**The fix is to delete the route, not to hide it** — a page marked for deletion
eighteen phases ago should not be given a `noindex` and kept. That is one line
in `routes/web.php` plus the controller and its view, and it is the
integrator's, not mine.

If it is kept deliberately, the SEO-correct alternative is two lines that must
move together, because `SeoBilingualTest` pins the two lists equal:
`'/\_design-check'` into `Indexability::PRIVATE_PREFIXES` **and** into
`SeoFilesController::ROBOTS_PRIVATE`. Neither file is fully mine, so both are
reported rather than edited.

---

## 4. The mutations, including the two that survived

Four written and run. **Two survived the first pass and both were unreached
guards in code I had just written** — the third round running that the surviving
mutation was a guard the default shop cannot reach.

| # | mutation | first pass | after |
|---|---|---|---|
| Q1 | restore the hardcoded curated-listing literal | **RED** | — |
| Q2 | restore the seven-slug literal | **RED** | — |
| Q3 | drop the parameterised-route guard | **survived** | **RED** |
| Q4 | build the page URL from the slug again | **survived** | **RED** |

**Q3** survived because the only parameterised collection route is
`@concern`, which the action-name filter already excludes — so the guard was
correct and unreachable. It is worth keeping rather than deleting: a `@show`
route taking a parameter is one line away, and without it the sitemap would
publish the literal `/{listing}/` as a URL. The test now registers exactly that
route.

**Q4** survived because the slug and the path are the same string for all seven
content pages. Chasing it produced §2's second bug rather than just a test.

**And a third matcher trap, from the same family as last round's.** Four
assertions written as `expect($haystack)->toContain($needle, $message)` failed
immediately: Pest's `toContain()` is **variadic over needles** for a string
subject, so the message became a second thing the document had to contain. Unlike
`toHaveKey()`, this one fails loudly rather than passing vacuously — so it cannot
be hiding in a green suite anywhere, and there is nothing to sweep. Worth knowing
that the rule is *"only `expect(bool)->toBeTrue($message)` reliably takes a
message"* rather than *"toHaveKey is odd"*.

---

## 5. The edit I could not make: `CollectionPage` on the Journal index

`/skincare-guide/` is a listing of articles and says nothing about itself in the
graph, in either language. It needs `PageController::blog()`, which this lane
does not own.

**ANCHOR** (`app/Http/Controllers/Store/PageController.php`, in `blog()`):

```php
        $seo = Seo::render([
            'type' => 'website',
            'title' => 'The Glow Journal',
```

**REPLACEMENT:**

```php
        $seo = Seo::render([
            'type' => 'collection',
            'collection' => ['name' => 'The Glow Journal'],
            'title' => 'The Glow Journal',
```

`Seo::jsonLd()`'s `collection` branch then emits `CollectionPage` with this
page's own `url` and `inLanguage`, exactly as a category archive does. The
branch passes `collection.items` only when the canonical is self-referencing,
which it is here, so a list of articles could follow later.

**Judged honestly, this is the lowest-value thing in this document.** The matrix
establishes that this shop is already ahead on markup and loses on content, so
a richer graph is worth less than the two reachability fixes in §2. It is one
node and it is correct; it is not worth a package of its own.

---

## 6. What is left, and who has to do it

1. **Delete `/_design-check`** (§3), or disallow it in the two paired lists.
2. **The Journal `CollectionPage` node** (§5), if the integrator wants it.
3. **Arabic slugs** need the owner's decision — transliterate or translate, new
   pages only or retrofit — before a line is written.
4. **Merchant shipping/returns** needs written confirmation of live terms.
5. **Everything else on the plan that matters is content**: concern copy, the
   article programme, Arabic catalogue text, editorial links. Four rounds of
   code have not changed that and were never going to.
