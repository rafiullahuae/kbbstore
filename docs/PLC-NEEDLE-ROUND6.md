# Round 6 of the false-green survey: thirty-three sites closed

**Lane PLC.** The survey finds assertions that are **true for the wrong reason** —
a needle present because something unrelated on the same page renders it. This
records what round 6 measured, what it repaired, and what it deliberately left.

The instrument is `tools/plc-needle-scan.sh` (records, at run time, how many
times every `toContain` / `toMatch` / `assertSee` needle occurs in the haystack
it ran against, and **where each copy sits**), read by
`tools/plc-needle-holes.php`. New this round: **`tools/plc-needle-mutate.sh`**,
which blanks a named element across the shop, runs the tests that claim to watch
it, and restores the file on any exit.

---

## 1 · The real number

The brief this round named "41 sites". The measured figure on the merged tree,
from a full instrumented run — **8,188 passed, 22 skipped, 0 failed**, 1,026s,
8,034 recorded rows:

| | |
|---|---|
| `toContain` assertions recorded over a string haystack | 7,035 |
| `toMatch` | 835 |
| `assertSee` | 164 |
| assertion **sites** whose needle is prose occurring 2+ times | **498** |
| of those, reading like a sentence a shopper is shown | **121** |
| copies all in the SAME element (a repeat, not a hole) | 24 |
| **copies in DIFFERENT elements — candidate holes** | **94** |

So the parked figure was low: **94 candidates, not 41.** The count is a screen
and not a verdict, which is why every site below was settled by mutation.

---

## 2 · Six mutations, each blanking one element across the whole shop

Every number was run, in both directions — first with the bare needles, then
with the repairs.

| the element blanked | the tests that claim to watch it | bare | repaired |
|---|---|---|---|
| the article `<h1>` (`store/post.blade.php`) | `PostUrlTest` | **16 passed, 0 failed** | 2 red |
| the journal eyebrow `.ey` (`store/blog.blade.php`) | `PostUrlTest` | **16 passed, 0 failed** | 1 red |
| both brand `<h1 class="brw-h1">` (`store/brands.blade.php`) | `BrandUrlTest`, `UrlSchemeTest` | **25 passed, 0 failed** | 3 red |
| the collection `<h1>` (`store/collection.blade.php`) | `ConcernCollectionsTest` | **15 passed, 0 failed** | 2 red |
| three account `<h1>`s (order-detail, reset, track) | `AccountAreaTest`, `CustomerPasswordResetTest`, `OrderReceivedPageTest` | **59 passed, 0 failed** | 3 red |
| the PDP `<h1 class="bb-title">` and the shop `<h1 class="ptitle">` | `StorefrontReadsTranslationsTest`, `ShopPhpLabelsAreKeyedTest`, `CategoryStorefrontSyncTest` | 1 red | 5 red |
| the card name, brand-directory name, brand standfirst, review caption and order-line brand, together | seven files | 4 red | **13 red** |
| the article `<h1>`, the PDP `.bb-desc` and the collection `.sh` `<h1>`+`<p>` | `JournalArticleEditorTest`, `StorefrontReadsTranslationsTest`, `CollectionPhpLabelsAreKeyedTest` | 1 red | 4 red |

**The first row is the one to read.** Blank the `<h1>` of every article on the
shop — a journal whose every piece renders headless — and the file whose entire
subject is that page is *sixteen passed, nothing failed*. Its assertion was
being answered by the `<title>`, the `og:title`, the `<meta description>` and
the JSON-LD `headline`, all four of which carry the same words in the same
document.

---

## 3 · What was satisfying the needles

In order of how often, across the 33:

1. **the `<title>` and the `og:title` of the same page** — by a distance the
   commonest. Any page heading is also that page's title;
2. **`<meta name="description">`** — a standfirst or blurb is also the
   description;
3. **the JSON-LD on the same document** — `Article.headline`,
   `Product.name`, `Brand.name`, the `ItemList` entries, `BreadcrumbList`;
4. **an attribute of the same component** — the add-to-basket link's
   `data-name`, an `<img alt>`, a `button aria-label`;
5. **a navigation label** repeated in the header's own JSON on every page.

---

## 4 · The thirty-three, by the element each needle now names

| element | sites | files |
|---|---|---|
| `<h1>…</h1>` (page heading) | 13 | `PostUrlTest` ×2, `AccountAreaTest`, `CustomerPasswordResetTest`, `OrderReceivedPageTest`, `ConcernCollectionsTest` ×2, `JournalArticleEditorTest`, `StorefrontReadsTranslationsTest` ×2, `CollectionPhpLabelsAreKeyedTest`, `BrandUrlTest`, `UrlSchemeTest` |
| `<h1 class="brw-h1">` | 3 | `BrandUrlTest` ×2, `UrlSchemeTest` |
| `<h1 class="ptitle">` | 2 | `ShopPhpLabelsAreKeyedTest`, `CategoryStorefrontSyncTest` |
| `<h1 class="bb-title" id="bbTitle">` | 2 | `StorefrontReadsTranslationsTest` ×2 |
| `<span class="kbb-card-nm">` | 7 | `ConcernCollectionsTest` ×3, `BrandUrlTest`, `CategoryPathWalkCostTest`, `DeadCategoryViewTest`, `StorefrontReadsTranslationsTest` |
| `<span class="brw-name">` | 2 | `BrandLogoDisplayTest` ×2 |
| `<div class="ey">` | 3 | `PostUrlTest` ×3 |
| `<span class="sr-cap-count">` | 2 | `ReviewBadgeScreenTest` ×2 |
| `<div class="kbbod-brand">` | 1 | `AccountAreaTest` |
| `<p class="brw-sub">` | 1 | `BrandUrlTest` |
| `<p>…</p>` (collection standfirst) | 1 | `CollectionPhpLabelsAreKeyedTest` |
| `bb-desc">` (PDP blurb) | 1 | `StorefrontReadsTranslationsTest` |

Each is recorded in `KBB_ELEMENT_ANCHORED_R6` in
`tests/Feature/NeedlesNameOneThingTest.php`, so a lane shortening one back to a
bare string goes red naming the file and the fragment. **Mutation, run:**
shorten `OrderReceivedPageTest`'s `'<h1>Track my order</h1>'` back to
`'Track my order'` → *"names '&lt;h1&gt;Track my order&lt;/h1&gt;' 0 times, was
repaired to 1"*.

**In every case the needle changed and the thing asserted did not** — which is
the rule the brief sets. No case had its subject rewritten.

---

## 5 · Examined and NOT repaired, with the reason

| site | why it is not a hole, or not this lane's to change |
|---|---|
| `SitewideDeliveryClaimsTest:492, :501` | **Not a hole.** `czTicker()` already extracts the ticker element and makes it the haystack — the stronger repair shape, the one `slsPageNotice()` uses. The two copies are both *inside* that element, because the ticker is duplicated for a seamless scroll. Blanking the ticker takes both. |
| `CheckoutScreenFieldTypesTest:211, :223` | The needle is a line of the tab script, present once per tab strip (`button.chp-tab` and `button.sfs-tab`). Two strips is the page doing its job. |
| `SetScreenIsSimplerTest:194` ×3 | Same shape — a control title present once in the desktop list and once in the mobile list, which is the screen's own structure. |
| `GridSectionShapeTest`, `CardsBannerSectionShapeTest`, `CheckoutPageSpacingTest`, `CartPageSqueezeTest:781` | CSS-fragment needles. A declaration appearing in a base rule and again in a media query is not ambiguity. |
| `AdminImportScreenTest:425` ×3 | The needle is a CSV error sentence and the copies are rows of the same CSV. Settling it means changing what is asserted (one row rather than the file), which the brief reserves. **Named, not rewritten.** |
| `SeoRenderTest:289, :295` | The case's subject is `Setting::map()`'s process-level memo, not any element — the site name reaching *any* surface is the thing it is about. Narrowing to `og:site_name` would be a different test. **Named, left.** |
| `InvoiceDocumentTest:305`, `InvoiceEmailTest:227`, `OrderEmailsTest:243` | Both copies come from one `Order::paymentLabel()` call rendered twice in the same document; the `->not->toContain('>cod<')` beside each is the half that carries the claim. Low value, not settled by mutation this round. |
| the remaining candidates | Not reached. The full list, grouped by the pair of elements the copies sit in, is in `storage/plc-logs/plc-holes-r6.txt`. |

---

## 6 · Two traps, and what was done about them

`CLAUDE.md` names both by name and both were checked at every site touched:

- **`->not->toContain($needle, $message)` cannot fail**, because Pest's
  `toContain` is variadic and the message becomes a second needle. Every
  negative assertion at a repaired site was read; all are single-argument.
  `ExpectationsThatCannotFailTest` sweeps for the malformed shape.
- **a fixed-width `substr()` window plus a negative needle is a silent false
  green.** Nothing added here uses a window. The same class of mistake *was*
  found in this round's other job, in CSS form — a copy region delimited by line
  position rather than by content, wrong by about forty rules. It is written up
  in `docs/PLC-GRID-SKIN-DRIFT.md` §1.

---

## 7 · Where it stands

**94 candidates measured. 33 settled by mutation and repaired. 21 more read and
recorded above with the reason — six of those are CSS-fragment needles the
sentence filter also picks up, so they are not all inside the 94. The rest were
not reached.**

That is the honest arithmetic rather than a round number: the 94 is the tool's
count under its own "reads like a sentence a shopper is shown" heuristic, and
the 21 were adjudicated by reading, so the two sets overlap without one being a
subset of the other.

The remaining candidates are in `storage/plc-logs/plc-holes-r6.txt`, grouped by
the pair of elements the copies sit in, which is the unit to adjudicate: the same
two elements account for dozens of sites and one repair shape closes all of them.
