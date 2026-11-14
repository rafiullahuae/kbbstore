# Arabic description spacing — audit and permanent fix (Lane ARDX, 14 Nov 2026)

The owner, 14 Nov 2026, with screenshots of `/ar/product/medicube-vanilla-deodrant-and-body-mist-duo/`:
*"in arabic version, the spacing and line breaking spacing has issues. please check first the most recent
50 products added and then go to all arabic versions in all products and fix this permanently."*

## How it was measured

- **Every product in the sitemap** (874), English and Arabic page each, fetched from kbeautybliss.com
  sequentially (1 s apart, a bot-check page retried after a pause; 1,748 pages, 0 errors). The 50 newest
  are the first 50 of `/shop/?orderby=date` (by date added — the shop's own "newest" order).
- From each page: every product tab body (Description, Ingredients, How to use, custom tabs) and the short
  description. Each pair read as **lines** by `App\Support\BlockStructure::lines()` — the same code the
  fix uses: `P` a line of words or a picture, `B` a blank line (every stored form: `<div><br></div>`,
  `<p>&nbsp;</p>`, `<p></p>`, `<div class="kbb-bl">`, a `<br>` on an empty line), `L5` a list of five,
  `X` a table. Edge blanks ignored (the page trims them).
- **2.60.665 went live during the fetch (~10:13).** It puts a blank line after *every* Arabic list at render
  time. Pages fetched after it (210 products) were compared with that insertion taken back out (the same
  strip on both languages), so the counts below are the STORED copy. What 2.60.665 itself did is measured
  separately below.
- Tooling: the fetch / extract / audit scripts are in the lane's scratch dir; `tools/ardx-shots.cjs` takes
  the pictures; `php artisan kbb:ar-structure --dry-run` answers the same question on the live database.

## Results

**874 products; 2,527 bodies compared; 1,708 actually in Arabic** (819 show the English on /ar — mostly
the INCI Ingredients tab, kept in English on purpose).

| | Bodies | Products |
|---|---:|---:|
| Arabic lines match the English exactly | 1,688 | 857 |
| **Differ** | **20** | **18** |
| — the command repairs them (blank lines / list split only) | 4 | 4 |
| — need a person: the English was rewritten or extended after the Arabic was written | 16 | 15 |
| **50 newest products** | 50 compared | 1 differs (`medicube-egf-nad-firming-glow-set`, content drift) |

By defect class (one body can be in several):

| Class | Bodies | Products |
|---|---:|---:|
| fewer blank lines in AR than EN | 13 | 13 |
| fewer text lines in AR (heading glued to text / paragraphs merged / `<br>` lost) | 13 | 13 |
| fewer lists in AR (merged list, or a list the English gained later) | 9 | 9 |
| picture count differs (same lines; the English gained or lost a picture) | 4 | 4 |
| more text lines in AR | 1 | 1 |
| more lists in AR | 1 | 1 |
| differs only in list sizes (bullets added/removed in English later; spacing identical) | 1 | 1 |
| stray `&nbsp;` (more in AR than EN) | 0 | 0 |
| tab missing on /ar | 1 | 1 |

**What 2.60.665 did on the live shop:** of the 210 products whose /ar page was fetched after it went live,
**202 had a blank line drawn after a list where the English has none** (e.g. `medicube-collagen-glow-sunscreen`,
`medicube-pdrn-hydrating-uv-serum`, `medicube-kojic-acid-turmeric-body-peel-shot`). It fixed the medicube duo's
two missing blanks and moved ~200 other Arabic pages away from their English. This lane replaces it (below).

### Root causes

1. **The Arabic was written from an older English.** Lane AL (2027_11_08 migrations, "Source: each item's live
   English page, 16 October 2026") and the later new-product packages wrote the Arabic tag-for-tag against the
   English *of that day*. The owner then re-spaced / extended the English (the duo's list split in two with
   blank lines; Dr.Melaxin and nine Medicube pages gained whole sections; short descriptions gained "limited
   offer" / "shipping time" blocks). Nothing re-lines the Arabic up when the English changes. Every one of
   the 20 differing bodies is this shape.
2. **No writer checked blank lines.** Every Arabic write goes through `TranslationStore::put()`, which only
   sanitised. `HtmlStructure` (Claude drafts) compares tags but not text, so `<div>&nbsp;</div>` answered
   as `<div></div>` passes and loses a line; the editor, the review screen's inline edit and Translation →
   Strings had no check at all; the Arabic migrations write `translations` directly.
3. **2.60.665's render-time rule was blind to the English** — see above.

### Worst 20

| # | Product | Score | Body | English lines | Arabic lines | Command |
|---|---|---:|---|---|---|---|
| 1 | [dr-melaxin-calcium-dark-spot-cover-eye-cream](https://kbeautybliss.com/ar/product/dr-melaxin-calcium-dark-spot-cover-eye-cream/) | 17 | Description | `P L6 P B P P B P P B P P B P B P P` | `P L3 P P L2 P P P` | leaves it: the English has 12 blocks (11 lines of text, 1 list groups) and the translation 8 (6 lines of text, 2 list groups) |
| 2 | [medicube-kojic-acid-turmeric-vita-jelly-mist-serum](https://kbeautybliss.com/ar/product/medicube-kojic-acid-turmeric-vita-jelly-mist-serum/) | 9 | Description | `P L5 P B P L2 B P L4 B P B P B P` | `P L3 P P L1 P P P` | leaves it: the English has 10 blocks (7 lines of text, 3 list groups) and the translation 8 (6 lines of text, 2 list groups) |
| 3 | [medicube-vanilla-hair-body-fragrance-mist](https://kbeautybliss.com/ar/product/medicube-vanilla-hair-body-fragrance-mist/) | 9 | Description | `P L5 P B P L2 B P L4 B P B P B P` | `P L3 P P L1 P P P` | leaves it: the English has 10 blocks (7 lines of text, 3 list groups) and the translation 8 (6 lines of text, 2 list groups) |
| 4 | [dr-melaxin-peel-shot-exfoliating-white-rice-ampoule](https://kbeautybliss.com/ar/product/dr-melaxin-peel-shot-exfoliating-white-rice-ampoule/) | 8 | Description | `P L5 P B P L2 B P L2 B P B P P` | `P L5 P P L2 P P P` | leaves it: the English has 10 blocks (7 lines of text, 3 list groups) and the translation 8 (6 lines of text, 2 list groups) |
| 5 | [medicube-vanilla-deodrant-and-body-mist-duo](https://kbeautybliss.com/ar/product/medicube-vanilla-deodrant-and-body-mist-duo/) | 8 | Description | `P B L1 B L1 B P B P L3 B P L4` | `P L2 P B P L5 P L5` | repairs it: split a list of 2 into 1 + 1; put back 1 blank line(s) before line 2; put back 1 blank line(s) between the split lists; put back 1 blank line(s) before line 4; put back 1 blank line(s) before line 7 |
| 6 | [medicube-vanilla-deodrant-and-body-mist-duo](https://kbeautybliss.com/ar/product/medicube-vanilla-deodrant-and-body-mist-duo/) | 8 | Short description | `P` | `P P` | leaves it: the English has 1 blocks (1 lines of text, 0 list groups) and the translation 2 (2 lines of text, 0 list groups) |
| 7 | [medicube-pdrn-collagen-gua-sha-neck-wrinkle-cream](https://kbeautybliss.com/ar/product/medicube-pdrn-collagen-gua-sha-neck-wrinkle-cream/) | 7 | Description | `P L5 P B P L2 P L4 P B P B P` | `P L3 P P L1 P P P` | leaves it: the English has 10 blocks (7 lines of text, 3 list groups) and the translation 8 (6 lines of text, 2 list groups) |
| 8 | [medicube-vanilla-pistachio-deodorant](https://kbeautybliss.com/ar/product/medicube-vanilla-pistachio-deodorant/) | 7 | Description | `P L5 P B P L2 P L3 P B P B P` | `P L3 P P L1 P P P` | leaves it: the English has 10 blocks (7 lines of text, 3 list groups) and the translation 8 (6 lines of text, 2 list groups) |
| 9 | [medicube-egf-nad-firming-gel-mask](https://kbeautybliss.com/ar/product/medicube-egf-nad-firming-gel-mask/) | 7 | Description | `P L5 P B P L2 P L4 P B P B P` | `P L6 P P L4 P P P` | leaves it: the English has 10 blocks (7 lines of text, 3 list groups) and the translation 8 (6 lines of text, 2 list groups) |
| 10 | [medicube-age-r-booster-pro](https://kbeautybliss.com/ar/product/medicube-age-r-booster-pro/) | 6 | Short description | `P P P P` | `P` | leaves it: the English has 4 blocks (4 lines of text, 0 list groups) and the translation 1 (1 lines of text, 0 list groups) |
| 11 | [k-beauty-dark-spots-care-bundle](https://kbeautybliss.com/ar/product/k-beauty-dark-spots-care-bundle/) | 6 | Description | `P P B P B P B P B P B P P P P P P P P` | `P P P P P P B P P P P P P P P` | leaves it: list sizes only |
| 12 | [k-beauty-dark-spots-care-bundle](https://kbeautybliss.com/ar/product/k-beauty-dark-spots-care-bundle/) | 6 | Short description | `P L1 P` | `P P` | leaves it: the English has 3 blocks (2 lines of text, 1 list groups) and the translation 2 (2 lines of text, 0 list groups) |
| 13 | [medicube-egf-nad-firming-glow-set](https://kbeautybliss.com/ar/product/medicube-egf-nad-firming-glow-set/) | 5 | Description | `P L3 B P L8 B P B P L3` | `P L8 B P B P L3` | leaves it: the English has 7 blocks (4 lines of text, 3 list groups) and the translation 5 (3 lines of text, 2 list groups) |
| 14 | [medicube-collagen-booster-set](https://kbeautybliss.com/ar/product/medicube-collagen-booster-set/) | 4 | Short description | `P P P` | `P` | leaves it: the English has 3 blocks (3 lines of text, 0 list groups) and the translation 1 (1 lines of text, 0 list groups) |
| 15 | [medicube-age-r-booster-pro-pink-edition](https://kbeautybliss.com/ar/product/medicube-age-r-booster-pro-pink-edition/) | 4 | Short description | `P P P` | `P` | leaves it: the English has 3 blocks (3 lines of text, 0 list groups) and the translation 1 (1 lines of text, 0 list groups) |
| 16 | [medicube-collagen-booster-set-pink-edition](https://kbeautybliss.com/ar/product/medicube-collagen-booster-set-pink-edition/) | 4 | Short description | `P P P` | `P` | leaves it: the English has 3 blocks (3 lines of text, 0 list groups) and the translation 1 (1 lines of text, 0 list groups) |
| 17 | [medicube-rosemary-pdrn-hair-recovery-set](https://kbeautybliss.com/ar/product/medicube-rosemary-pdrn-hair-recovery-set/) | 4 | Short description | `P P P L3` | `P L3` | leaves it: the English has 4 blocks (3 lines of text, 1 list groups) and the translation 2 (1 lines of text, 1 list groups) |
| 18 | [sebum-sweeper-pore-cleansing-silicone-brush](https://kbeautybliss.com/ar/product/sebum-sweeper-pore-cleansing-silicone-brush/) | 4 | Short description | `P L5 P B P L2 B P L5 B P B P` | `P L5 P P L2 P L5 P P` | repairs it: put back 1 blank line(s) before line 4; put back 1 blank line(s) before line 6; put back 1 blank line(s) before line 8; put back 1 blank line(s) before line 9 |
| 19 | [medicube-egf-nad-firming-serum](https://kbeautybliss.com/ar/product/medicube-egf-nad-firming-serum/) | 1 | Description | `P L5 P L4 P B P L4 P L2 B P B P` | `P L5 P L4 P B P L4 P L2 P B P` | repairs it: put back 1 blank line(s) before line 10 |
| 20 | [lilyeve-grow-turn-brush-steam-booster](https://kbeautybliss.com/ar/product/lilyeve-grow-turn-brush-steam-booster/) | 1 | Short description | `P B P` | `P P` | repairs it: put back 1 blank line(s) before line 2 |

### The 50 newest products

50 of the 50 compared; 1 with a body whose lines differ from the English:

- medicube-egf-nad-firming-glow-set (Description): EN `P L3 B P L8 B P B P L3` / AR `P L8 B P B P L3` -> left: the English has 7 blocks (4 lines of text, 3 list groups) and the translation 5 (3 lines of text, 2 list groups)

(Shapes for pages fetched after 2.60.665 are shown with its insertion stripped from both languages.)

## The fix

1. **`App\Support\BlockStructure`** reads both bodies as lines and, when the Arabic has the same order of
   text lines, lists, tables and rules as its English (a list group may be one list in Arabic where the
   English has several with the same number of items), makes the Arabic's blank lines match the English gap
   for gap, takes extra ones out and splits a merged list. Otherwise it changes nothing and says why. **No
   word moves**: every edit is a constant (`<div class="kbb-bl"></div>`, `<br>` inside a paragraph, the
   list's own close/open tags) or the removal of an element with no words, and the result's text (all
   whitespace removed) must equal the input's or the input comes back untouched.
2. **Display (every page, now):** `ProductTabs` (built-in and authored tabs) and the product blurb draw the
   Arabic lined up with its English. When that is not certain (the 16 content-drift bodies) it falls back to
   2.60.665's "blank after a list", but only if the English itself spaces its lists that way. English pages
   never reach it (byte-identical; `StorefrontEnglishUnchangedTest`). No query, no setting read
   (`ArabicStructureRepairTest` counts the queries); 20 µs median per translated body when the two already
   match (184 of 253 live pairs take this regex fast path), ~0.1–0.3 ms when lining up, 0.8 ms worst
   (4.8 KB body, CLI without opcache). `RichText.php` and `ProductController.php` are back to their
   pre-2.60.665 bytes.
3. **Writers (permanent):** `TranslationStore::put()` lines every rich Arabic write up with the English it
   was made from — the product editor, Translate & categorise (Claude drafts and inline edits),
   Translation → Strings, product tab bodies. Copy that already lines up is stored as given.
4. **Stored rows:** `php artisan kbb:ar-structure` and **Catalog → Translate & categorise → Arabic spacing**
   (tab), capability `catalog.ar_structure` (owner, manager; fails closed):
   - `--dry-run` (default) lists `FIX` rows with the change and `LEAVE` rows with the reason; writes nothing.
   - `--apply` backs every changed row up to `ar_structure_backups` first (one batch id), then writes;
     status, source and source hash untouched. `--recent=50`, `--product=ID` narrow it.
   - `--undo=<batch>` puts back rows still holding what the repair wrote; `--batches` lists them.

   On the live database it is expected to repair the 4 bodies above (and anything the render-time fetch
   could not see, e.g. custom tabs hidden on a product); the 16 drift bodies need re-translating from the
   current English (they are listed by the dry run as "need a person").

## Pictures

Before = live /ar (kbeautybliss.com, 14 Nov, before 2.60.665 for the duo); after = this branch rendering the
same live English + Arabic (English stored with its blank lines as `<div><br></div>`, as the editor saves
them) in a local preview; the English column is the reference. Chromium, details tab opened in full.

| Product | Class | 390 | 1280 |
|---|---|---|---|
| medicube vanilla deodorant & mist duo | merged list, 4 blanks lost — repaired | [390](shots/compare-medicube-vanilla-deodrant-and-body-mist-duo-390.png) | [1280](shots/compare-medicube-vanilla-deodrant-and-body-mist-duo-1280.png) |
| medicube EGF NAD firming glow set | English gained a section — left (fallback: nothing to add) | [390](shots/compare-medicube-egf-nad-firming-glow-set-390.png) | [1280](shots/compare-medicube-egf-nad-firming-glow-set-1280.png) |
| medicube AGE-R booster pro | short description drift — tab unchanged | [390](shots/compare-medicube-age-r-booster-pro-390.png) | [1280](shots/compare-medicube-age-r-booster-pro-1280.png) |
| medicube collagen booster set | short description drift — tab unchanged | [390](shots/compare-medicube-collagen-booster-set-390.png) | [1280](shots/compare-medicube-collagen-booster-set-1280.png) |
| numbuzin No.9 NAD sun essence | already matching — unchanged | [390](shots/compare-numbuzin-no-9-nad-peptides-dewy-sun-essence-390.png) | [1280](shots/compare-numbuzin-no-9-nad-peptides-dewy-sun-essence-1280.png) |
| medicube rosemary PDRN hair oil | already matching — unchanged | [390](shots/compare-medicube-rosemary-pdrn-silk-hair-oil-390.png) | [1280](shots/compare-medicube-rosemary-pdrn-silk-hair-oil-1280.png) |

Measured (one blank line = one `.kbb-bl`): duo /ar 1 → 5 blank lines (English 4 + its `<br>` blank = 5);
the other five unchanged. `scrollWidth` = viewport width in every shot; no console errors. Line heights
differ between the live and preview columns only because the preview has no Arabic webfont.
