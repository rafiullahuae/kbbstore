# Concern mapping — a proposal to correct, not a spreadsheet to author

Lane S2, round 2, 2026-09-24. Reads on from `docs/SEO-BUILD-PLAN.md` Part II
item 1, which named *"a product-to-concern mapping"* as **the single biggest
blocker in the entire plan**.

Every claim below is marked **OBSERVED** (with the file and line it was read
from, or the command that produced it) or **UNVERIFIED**. Nothing here is a
traffic estimate and nothing here is a search-volume figure; this project has
neither a keyword tool nor Search Console for any domain.

---

## 0. The headline, and it is a correction to my own round-1 report

**Round 1 said the owner must author a product-to-concern mapping and called it
the biggest blocker in the plan. The "who decides" half of that was right. The
"what shape is the work" half was wrong, and wrong in the owner's favour.**

This shop already has a concern vocabulary, a per-product column to store it in,
an admin screen with one-click chips to set it, and a test pinning the
vocabulary to the skin quiz's. None of it had to be built and none of it is
mentioned anywhere in `SEO-COMPETITIVE.md`, `SEO-GAP.md` or `SEO-BUILD-PLAN.md`,
because round 1 was research against the competitor and never read this part of
our own codebase.

| | | Source |
|---|---|---|
| The vocabulary | **eight concern slugs** | OBSERVED — `app/Support/RoutineConcerns.php:55` |
| Where a product's concerns live | `products.routine_concerns`, JSON text | OBSERVED — `database/migrations/2026_11_18_000000_add_routine_role_to_products.php:67` |
| The screen that sets it | **Catalog → Build my routine** | OBSERVED — `resources/views/admin/partials/routines-screen.blade.php`, included at `resources/views/admin/app.blade.php:21037` |
| The endpoint behind the chips | `POST /admin-api/routine-products/{id}` | OBSERVED — `routes/build-my-routine-admin.php:68` |
| Allowlisted against the vocabulary | `'concerns.*' => ['string', 'in:'…slugs()]` | OBSERVED — `app/Http/Controllers/Admin/RoutinesApiController.php:325` |
| Kept in step with the quiz by a test | `QuizAndRoutinesShareOneConcernListTest` | OBSERVED — named at `app/Support/RoutineConcerns.php:26` |

The eight, exactly as the code spells them (slug on the left is what a URL and
the column carry; the English on the right is the quiz's own, character for
character, American "aging" included):

```
hydration    Hydration
dark-spots   Dark spots & tone
acne         Acne & blemishes
ageing       Fine lines & aging
sensitivity  Redness & sensitivity
pores        Pores & oil
dullness     Dullness & glow
sun          Sun protection
```

### What this changes about item 1

**Round 1 proposed the keys `acne`, `hyperpigmentation` and
`sensitivity-redness`. Two of those three are wrong and should not be used.**
The shop's slugs for the same three concepts are **`acne`**, **`dark-spots`**
and **`sensitivity`**. Shipping new spellings beside the existing ones would
give this shop two concern vocabularies — which is the precise failure
`RoutineConcerns`' own header spends four paragraphs warning about:

> *"a shop with nine concerns in one place and eight in another has two
> vocabularies, and the day somebody wires 'the quiz said Acne, show me that
> routine' the join fails silently on the spellings that do not match."*
> — OBSERVED, `app/Support/RoutineConcerns.php:21`

So the three collection pages this round writes copy for are, in the shop's own
vocabulary:

| Round-1 name | **Use this slug** | Shopper-facing label |
|---|---|---|
| acne | **`acne`** | Acne & blemishes |
| hyperpigmentation | **`dark-spots`** | Dark spots & tone |
| sensitivity-redness | **`sensitivity`** | Redness & sensitivity |

**Owner decision #1, and it is thirty seconds:** confirm we reuse the eight, or
say which of them you want the collections to differ on. Reusing them is
strongly recommended and costs nothing.

---

## 1. What the mapping job actually is now

It is **not** a spreadsheet. It is a screen with a search box and chips.

**Admin path: Catalog → Build my routine.** OBSERVED — `resources/views/admin/app.blade.php:21029-21037`.

What is on it, OBSERVED from the screen and its controller:

- A **count of untagged products** as the headline figure, with a one-click
  filter down to exactly those rows (`routines-screen.blade.php:20`).
- A product table, **25 rows a page**, searchable (`RoutinesApiController::PER_PAGE`, `:39`).
- **One chip per concern per product.** Clicking a chip saves immediately and
  reads the row back (`routines-screen.blade.php:337-340`, `:540-550`).
- A **`live` flag per row** — published, visible, not scheduled, in stock — so a
  product tagged but invisible to shoppers is shown as such rather than left to
  be guessed at (`RoutinesApiController.php:301-307`).

Three things about that screen change the size of the ask:

**a) You do not have to tag the whole catalogue.** A concern collection needs
enough products to clear the thin-page floor `SEO-GAP.md` §11 sets at **8–12**.
Three pages therefore need roughly **30–45 products tagged**, not 671. The rest
of the catalogue can stay untagged indefinitely; an empty concern list already
means "suits any routine" to the engine and is the state every row starts in
(OBSERVED — `RoutineConcerns::clean()`'s docblock, `:89`, and
`RoutinesApiController.php:336`).

**b) The search box matches `name` and `sku` — and nothing else.** OBSERVED —
`RoutinesApiController.php:264-266`: `name LIKE ?` OR `sku LIKE ?`. There is no
description search and no ingredients search. That sounds like a limitation and
in this category it is close to a feature: K-beauty product names routinely
carry the hero ingredient. It is true of this repo's own catalogue snapshot —
*"Medicube - Kojic Acid Turmeric Booster Pro Set"* has both actives in the
product name (OBSERVED — `storage/catalog/products.json`).

**c) So the job is: type a word, tick the chips, next word.** That is what §3
below is: a list of words to type.

**There is no bulk-tagging endpoint.** OBSERVED — `routes/build-my-routine-admin.php`
registers five routes and `tag` takes a single `{id}`. Tagging is per product,
which is why §3 is organised to make each search return a group that all gets
the same chip.

---

## 2. The limit on this document, stated plainly

**This repository does not contain the catalogue.** I could not draft a
per-product mapping over 671 products because 671 products are not here to read.

OBSERVED:

- `storage/catalog/products.json` holds **22 products**, and it is the only real
  product data in the tree. `docs/REPO-STATE.md` lists what is tracked;
  a catalogue dump is not among it, and `tests/bootstrap.php:375` describes this
  file as *"real source data"* used for packaging.
- All 22 are in **Beauty Devices (18)** and **Makeup (4)**. There is not one
  cleanser, toner, serum, moisturiser or sunscreen in the file — which are
  exactly the categories a concern collection is made of.
- `tests/Fixtures/kbb-export/products.csv` holds **4 rows**;
  `tests/Fixtures/woo/products.csv` holds **10**. Both are import fixtures with
  invented names, not catalogue.
- Every one of the 22 has `"concerns": []`. **Nothing in this repo is tagged for
  any concern today.**

So this document does what the evidence actually supports, in three tiers, and
labels which tier every row is in:

- **§3 — the search-term worksheet.** Rules, not rows. A proposal to correct.
- **§4 — the 22 products placed.** Real rows, real quoted reasons. OBSERVED.
- **§5 — the brand lean.** UNVERIFIED and marked so on every line.

---

## 3. The worksheet — type these words, tick these chips

This is the deliverable. Each block is one search in **Catalog → Build my
routine**, and the chip to tick on what comes back.

**These are proposed search terms, not observed matches.** I have not verified
which live products match them, for the reason in §2. UNVERIFIED throughout —
what is observed is only that the search box matches product names (§1b) and
that the ingredient-to-concern reasoning below is conventional dermatological
grouping, not a Google document and not a ranking factor.

A term that returns nothing costs five seconds. A term that returns a group
costs one click per row.

### → chip **Acne & blemishes** (`acne`)

| Search for | Why | Confidence |
|---|---|---|
| `salicylic` | BHA, the canonical acne active | high |
| `BHA` | how Korean labels usually spell it | high |
| `AHA-BHA-PHA` | a named product line rather than an ingredient | high |
| `tea tree` | anti-microbial, standard in acne lines | high |
| `centella` / `cica` | **also `sensitivity` — tick both** | high |
| `mugwort` | calming + clarifying; overlaps `sensitivity` | medium |
| `acne` / `blemish` / `spot` | some names say it outright | high |
| `pore` / `blackhead` | **also `pores` — tick both** | high |
| `clay` / `charcoal` | wash-off masks, oil control | medium |
| `azelaic` | less common in K-beauty; may return nothing | low |

**Question for the owner, not an answer:** do **sunscreens** belong on the acne
page? A non-comedogenic SPF is genuinely part of an acne routine, and this shop
has a whole `Sunscreens` category (OBSERVED — the live menu tree,
`database/migrations/2026_09_09_070000_fix_kbeautybliss_menu_structure.php:100`).
My instinct is **no** — it makes the page about everything — but it is a
merchandising call and it is yours.

### → chip **Dark spots & tone** (`dark-spots`)

| Search for | Why | Confidence |
|---|---|---|
| `niacinamide` | the most-stocked tone ingredient in K-beauty | high |
| `vitamin c` / `vita c` / `ascorb` | the other canonical one | high |
| `arbutin` / `alpha arbutin` | targeted pigment ingredient | high |
| `tranexamic` | targeted pigment ingredient | high |
| `kojic` | **OBSERVED in this shop** — see §4 | high |
| `turmeric` | **OBSERVED in this shop** — see §4 | high |
| `glutathione` | sold hard in the Gulf market | medium |
| `dark spot` / `brightening` / `tone` | some names say it outright | high |
| `glow` | **careful** — often means hydration, not pigment | low |
| `propolis` | usually paired with niacinamide in this category | medium |

**Question, not an answer:** `dark-spots` and `dullness` are two of the eight
and they will fight over the same products. My proposal: **`dark-spots` is for
uneven colour in patches** (post-acne marks, sun spots, melasma) and
**`dullness` is for the whole face looking flat**. Tick `dark-spots` only when
the product names a pigment ingredient. You may disagree, and if you do, say so
once and I will write it down rather than have it decided differently on each of
671 rows.

### → chip **Redness & sensitivity** (`sensitivity`)

| Search for | Why | Confidence |
|---|---|---|
| `centella` / `cica` / `madecassoside` | the sensitivity cluster in this category | high |
| `heartleaf` / `houttuynia` | **this shop has an article about it** — see §6 | high |
| `panthenol` | barrier repair | high |
| `ceramide` | barrier repair | high |
| `mugwort` / `artemisia` | calming | high |
| `noni` | Celimax's line is built on it | medium |
| `azulene` / `allantoin` | redness | medium |
| `barrier` / `soothing` / `calming` / `relief` | named in the product name | high |
| `fragrance-free` | **will almost certainly return nothing** — see below | low |

**A real gap, and it is the one thing on this page I would fix in code.**
Fragrance-free is arguably *the* sensitivity signal, and it is not searchable
here: it lives in an ingredient list, and `routine_concerns`' search matches
`name` and `sku` only (§1b). This shop *has* an `ingredients` column —
OBSERVED, `database/migrations/2026_10_05_000000_add_product_editor_columns.php:84`
— it simply is not searched from this screen. **That is a small, well-scoped
code item** and I have not built it, because this lane's ownership is
`tests/Feature/*` and `docs/`, and `RoutinesApiController` is not mine to edit.
It is written up as item A in §7.

---

## 4. The 22 products this repo does contain, placed

**OBSERVED.** Every row's reason is quoted from the product's own description in
`storage/catalog/products.json`. Category as the file records it.

These are devices and makeup, so several of them are honestly **not** concern
collection material — a collection of face masks and LED devices is not what
"korean skincare for acne" means. Rows I would **not** put on a collection page
are marked so, and that is a recommendation, not a refusal to place them: the
chips are also what the **routine builder** and the **quiz** read, so tagging
them is still worth doing.

| Product | Chips I propose | Quoted reason | On a collection page? |
|---|---|---|---|
| Medicube – Kojic Acid Turmeric Booster Pro Set | **dark-spots**, dullness, (acne?) | *"designed to target stubborn hyperpigmentation, uneven skin tone, and dullness"*; *"kojic acid and turmeric root extract as star ingredients … fading dark spots"*; also *"AHA (glycolic acid) and BHA (salicylic acid)"* | **Yes** — the single clearest row in the file |
| Medicube – PDRN Glow Booster Set (Pink Edition) | **dark-spots**, ageing, dullness | *"fades blemishes and reduces melanin pigmentation"*; *"Niacinamide and turmeric root extract provide brightening"* | Yes |
| Medicube – PDRN Glow Booster Set – Mini | same | same text | Yes |
| Medicube – PDRN Glow Booster Set (Black Edition) | same | same text | Yes |
| Shark CryoGlow LED Mask | **acne**, ageing | *"Blemish Repair Mode is proven to improve blemishes and skin roughness in 4 weeks"* | Device — see note |
| STYLPRO Wavelength LED Face Mask | **acne**, ageing | *"blue LED to promote a clear complexion … near-infrared light to help reduce inflammation"* | Device |
| medicube – AGE-R Booster Pro Yellow Edition | acne, **dark-spots** | *"LED Light Therapy … brighten skin, even tone"*; *"Air Shot Mode (Blue Light)"* | Device |
| ilso – Deep Clean Master | **pores**, acne | *"scrape away skin debris and purify pores without causing irritation"*; *"clogged pores"* | Device |
| StylPro Facial Steamer | pores | *"pore"* only | Device |
| Medicube – Collagen Booster Set | ageing, hydration | *"Niacinamide and blueberry extract … brighter complexion, while hyaluronic acid and squalane strengthen the skin's moisture barrier"*; *"suitable for sensitive skin"* | Device |
| Medicube – Collagen Booster Set – Pink Edition | same | same text | Device |
| Medicube – AGE-R Booster Pro (+ Pink, Mini Pink, X2 Pink) | ageing, pores | *"Air Shot mode for pore care effects"*; the Mini adds *"brighten"*, *"soothe"* | Device |
| STYLPRO Fabulous Firmer Neck & Face Smoother | ageing | *"firm"*, *"red light"*, *"collagen"* | Device |
| StylPro Smooth Finish | pores | *"pore"*, *"tone"* | Device |
| NuFACE Mini Starter Kit | ageing | microcurrent; gel primer's ingredient tab is the only real ingredient list in the file | Device |
| TIRTIR – Mask Fit Red Cushion Foundation | *(none)* | *"coverage to cover blemishes and redness"* — **this is coverage, not treatment** | **No** |
| MISSHA – M Perfect Cover BB Cream SPF 42 | *(sun?)* | SPF 42 PA+++ is real sun protection, but it is makeup | **Question** |
| heimish – Artless Perfect Cushion Set | *(none)* | makeup | No |
| House of Hur – Moist Ampoule Blusher | *(none)* | makeup | No |

**The TIRTIR row is the one I want you to look at.** Its text says *"blur skin
imperfections and redness"*. That is a foundation covering redness, not a
product treating it. Tagging it `sensitivity` would put a cushion foundation on
a page about calming reactive skin, and a shopper who came for a soothing toner
would read that page as untrustworthy. **I have proposed no chip for it and I
think that is right, but it is exactly the kind of call you should overrule me
on if you disagree.**

**Note on the devices.** Nine of these are LED or microcurrent devices with
genuine acne or ageing claims. They belong in the routine builder. Whether they
belong on `/…acne/` is a merchandising decision: a device at AED 900 on a page
someone reached searching "korean skincare for acne" may convert well or may
read as a bait-and-switch. **My proposal: put devices at the bottom of the
collection, not the top, and only once the page has 8+ non-device products.**

### Coverage — the honest count

| | |
|---|---|
| Products in this repository | **22** |
| Placed with a quoted reason | **18** |
| Deliberately not placed (makeup; coverage, not treatment) | **3** |
| Placed but flagged as a question (MISSHA BB, `sun`) | **1** |
| **Products in the live catalogue I could not read at all** | **~649, if the 671 figure holds — UNVERIFIED, it is not sourced in this repo** |
| Products that would clear the 8–12 floor for `acne` from this file alone | **0 non-device** |
| …for `dark-spots` | **0 non-device** (4 device sets) |
| …for `sensitivity` | **0** |

**That last block is the finding.** Not one of the three pages can be built from
what this repository holds. §3's worksheet is how the gap gets closed, and it
needs someone in front of the live admin.

---

## 5. Brand lean — UNVERIFIED, and marked so on every line

The shop's live navigation names **17 brands**. That list is OBSERVED —
`database/migrations/2026_09_09_070000_fix_kbeautybliss_menu_structure.php:78-83`,
a migration whose header records the owner confirming the menu structure.

What each brand's range *leans* toward is **UNVERIFIED**. It is my prior about
these brands from general knowledge of the category, not a reading of this
shop's catalogue, and I could not check a single one of them because the
catalogue is not in this repo (§2) and egress is blocked (`curl` to
`kbeautybliss.com` returns `CONNECT tunnel failed, response 403`, re-tested at
the start of this round).

**Use this only to decide which brand to search first. Do not tag from it.**

| Brand | Leans | Confidence |
|---|---|---|
| Anua | sensitivity (heartleaf), pores | medium |
| Axis-Y | dark-spots | medium |
| Beauty of Joseon | dark-spots, sun | medium |
| BIODANCE | hydration | low |
| Celimax | sensitivity | medium |
| COSRX | acne, sensitivity | medium |
| Dr.Althea | sensitivity | low |
| EQQUALBERRY | — | none |
| Goodal | dark-spots | medium |
| I'm from | hydration, sensitivity | low |
| LANEIGE | hydration | medium |
| MEDICUBE | dark-spots, acne, pores — **partly OBSERVED**, §4 | high for the rows in §4 only |
| numbuzin | dullness, dark-spots | low |
| Shiseido | sun, ageing | medium |
| SKIN 1004 | sensitivity (centella) | medium |
| SOME BY MI | acne | medium |
| VT Cosmetics | sensitivity (cica) | medium |

Three brands I would search first for each page, on this UNVERIFIED basis:
**acne** → COSRX, SOME BY MI, Anua. **dark-spots** → Beauty of Joseon, Axis-Y,
Goodal. **sensitivity** → SKIN 1004, VT Cosmetics, Celimax.

---

## 6. What the journal already gives each page, and what it does not

`SEO-GAP.md` §12 wants each concern collection linked to a matching article.
This shop has **five** real articles. OBSERVED — their slugs are in the shipped
redirect rows, `database/migrations/2026_09_14_160000_seed_phase9_post_url_redirects.php`,
confirmed by reading the `redirects` table on a migrated test database (10 rows,
two spellings each of five articles).

| Article | Serves |
|---|---|
| `heartleaf-extract-transforming-k-beauty-skincare` | **`sensitivity`** — directly |
| `k-beauty-bliss-10-best-korean-moisturizers-for-sensitive-skin` | **`sensitivity`** — directly |
| `k-beauty-bliss-how-to-repair-a-damaged-skin-barrier-with-k-beauty` | **`sensitivity`** — directly |
| `k-beauty-face-masks-the-ultimate-guide-…` | nothing in particular |
| `k-beauty-bliss-a-beginners-guide-to-korean-skincare` | the pillar |

**So: `sensitivity` can ship with three real outbound article links on day one.
`acne` and `dark-spots` can ship with none.**

That is a reason to **launch `sensitivity` first**, not to delay the other two —
and it is concrete evidence for `SEO-BUILD-PLAN.md` Part II item 6 (the article
cluster), whose first two proposed topics were Cica/Centella/Heartleaf and
niacinamide/vitamin C for pigmentation. The first already exists. The second is
the gap.

---

## 7. What is still the owner's, stated as narrowly as I can make it

Round 1 gave a ten-row table of things the owner must supply. Here is what is
actually left for **these three pages**, with everything I could remove removed.

### Decisions — five of them, none needing research

1. **Reuse the existing eight concern slugs?** (§0.) Recommended: yes. *Thirty
   seconds.*
2. **`dark-spots` vs `dullness` — where is the line?** (§3.) My proposal is in
   §3; say yes or correct it. *One minute.*
3. **Do sunscreens go on the acne page?** (§3.) My proposal: no. *One minute.*
4. **Do devices go on a concern page, and where?** (§4.) My proposal: bottom
   only, and only once 8+ non-device products are on it. *One minute.*
5. **Launch order.** My proposal: `sensitivity` first, because it is the only
   one of the three with articles to link to today (§6). *One minute.*

### The one piece of real work, and it is bounded

6. **Tag roughly 30–45 products** in **Catalog → Build my routine**, using §3's
   search terms. Not 671. Not a spreadsheet. A search box and chips.
   - You can stop the moment each of the three has **8–12 live products** — the
     screen shows you the `live` flag per row, so you will not overcount
     (§1, OBSERVED `RoutinesApiController.php:301`).
   - **If a term returns nothing, tell me the term.** That is data about the
     catalogue I cannot get any other way from here, and it changes §3.

### Not needed any more

- ~~A product-to-concern spreadsheet~~ — the screen replaces it (§0, §1).
- ~~A concern vocabulary~~ — eight already exist and are test-pinned (§0).
- ~~150–300 words of English copy per concern~~ — **drafted this round**, in
  `docs/SEO-CONCERN-COPY.md`. Edit or reject it; do not start from blank.

### Still blocked, and still genuinely the owner's

- **Arabic copy.** Unchanged and unchanged on purpose. `SEO-BUILD-PLAN.md` Part
  II item 2's finding stands: machine-translated Arabic is worse than no Arabic,
  and Gulf product search skews to dialect over Modern Standard Arabic. **This
  lane deliberately wrote no Arabic.** Budget a human.
- **The postal address** for `LocalBusiness` (Part II item 4). Untouched by this
  round. Still one short list of facts only you have.

---

## 8. Code items this document produced, for whoever schedules them

Written down rather than built: this lane's ownership this round is
`tests/Feature/*` and `docs/`.

**A. Let the tagging screen search ingredients.** (§3.) `RoutinesApiController::products()`
matches `name` and `sku` only (`:264`). The `ingredients` column exists
(`2026_10_05_000000_add_product_editor_columns.php:84`) and is not searched.
Adding it makes `fragrance-free`, `centella` and `niacinamide` findable on
products whose names do not carry them, which is most of the sensitivity signal.
Small. Owner of the file: whoever holds `app/Http/Controllers/Admin/`.
**Guard:** it is an admin endpoint behind an existing capability, and the term
is already LIKE-escaped with a declared ESCAPE character (`:257`) — reuse that
exact escaping, do not write a second one.

**B. A `concern` selection mode on `CollectionController`.** Item 1's code half
is smaller than Part II estimated: the products are already selectable, by
`routine_concerns`. It is a `match` arm beside `newest` / `popular` / `on_sale`
/ `budget` (`CollectionController.php:48`), not a subsystem.
**Guard:** whoever builds it adds the key in all five places §9 of this file
lists — and `tests/Feature/CollectionKeyDriftTest.php`, added this round, now
fails if they miss three of them.

**C. Nothing else.** In particular: no new table, no new taxonomy, no import.

---

## 9. For whoever adds the keys — the five places, and what now catches you

OBSERVED, and this is what `tests/Feature/CollectionKeyDriftTest.php` (added
this round) exists for.

| # | Place | Caught by |
|---|---|---|
| 1 | `routes/web.php` — `Route::get(…)->defaults('key', …)` | **CollectionKeyDriftTest** |
| 2 | `CollectionController::COLLECTIONS` (`:48`) | CollectionPhpLabelsAreKeyedTest, **and** CollectionKeyDriftTest |
| 3 | `CollectionController::wordingFor()` (`:200`) | **CollectionKeyDriftTest** |
| 4 | `SeoFilesController::sitemap()`'s path list (`~:188`) | **CollectionKeyDriftTest** — this is the silent one |
| 5 | `PageController::RESERVED_SLUGS` (`:62`) | RootSlugCollisionTest, **and** CollectionKeyDriftTest |

Plus, for the wording: a `store.collection.*` pair in
`app/Services/Translation/InterfaceStrings.php` (`:473`), in **both** locales.

**Round-1 correction:** Part II item 9 called these "four hardcoded lists with
nothing enforcing agreement". There are **five**, counting `routes/web.php`,
and **two of the five already had a guard** — `CollectionPhpLabelsAreKeyedTest`
pins `array_keys(COLLECTIONS)` and `RootSlugCollisionTest` walks the router
against `slugPattern()`. The three that had nothing were the route, the match
arm and the sitemap. They do now.

### The URL is a decision, and it is yours

The four existing collections all sit at the **site root** — `/new-in/`,
`/best-sellers/`, `/super-sale/`, `/everything-under-54-aed/` (OBSERVED,
`routes/web.php:59-66`). The competitor uses `/collections/acne` (OBSERVED,
`SEO-COMPETITIVE.md` §9.2).

Two options, and I am not deciding this for you:

- **Short:** `/acne/`, `/dark-spots/`, `/sensitive-skin/`. Clean; matches the
  existing four; shortest.
- **Query-shaped:** `/korean-skincare-for-acne/`, `/korean-skincare-for-dark-spots/`,
  `/korean-skincare-for-sensitive-skin/`. Longer, and closer to the words
  someone types. This shop already does long slugs where they read better —
  `/korean-skincare-brands/` and `/everything-under-54-aed/` are both live.

**I lean query-shaped**, on the grounds that the whole point of the page is to
match an intent-carrying query, and this shop has already chosen that trade once
for `/korean-skincare-brands/`. **UNVERIFIED that it ranks better** — nobody
here has the data to claim that, and anyone who tells you exact-match slugs are
worth a fixed number of positions is guessing.

Either way the first segment must go into `RESERVED_SLUGS`, or the root-level
article route serves it and `/acne/` becomes a lookup for an article called
"acne". The drift test now fails if it is missed.
