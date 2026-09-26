# Concern collection copy — English drafts to edit or reject

Lane S2, round 2, 2026-09-24. The companion to `docs/SEO-CONCERN-MAPPING.md`.

`SEO-GAP.md` §11 sets the working spec for a concern collection: **150–300 words
of real intro copy**, a floor of **8–12 products**, and outbound links to the
matching article and the leading brands. `SEO-BUILD-PLAN.md` Part II item 1 says
the reason: a grid with no prose gives the ranking system nothing to read.

**This file is the prose. The owner edits or rejects it; he does not start from
a blank page.**

---

## Read this before the drafts

**1. The word counts are a convention, not a rule, and they are not in a test.**
`SEO-BUILD-PLAN.md` Part II item 6 is explicit: the figures in `SEO-GAP.md` §12
are practitioner convention, not Google documentation. **Do not put a word count
in a test.** Each draft below says its count so you can see it is in the band;
that is the only reason the number is there.

**2. Three of these sentences name products, and the products are UNVERIFIED.**
This repository does not contain the catalogue — `storage/catalog/products.json`
holds **22 products**, all Beauty Devices and Makeup (OBSERVED; see
`SEO-CONCERN-MAPPING.md` §2). Every product name in the drafts below is in
`[SQUARE BRACKETS]` and is a **placeholder to replace with a real one**. I have
not invented a single product name; I have marked the slot and said what kind of
product goes in it.

The one exception is marked inline: **Medicube's Kojic Acid Turmeric line** is
genuinely in this shop, and its own description says *"designed to target
stubborn hyperpigmentation, uneven skin tone, and dullness"* (OBSERVED,
`storage/catalog/products.json`). That one you can leave.

**3. The ingredient claims are deliberately soft.** No draft says a product
*will* clear acne or fade a spot. The shop sells cosmetics, not medicines, and a
collection page that promises a clinical outcome is a liability before it is an
SEO problem. Every claim below is of the form "this is what the ingredient is
for" rather than "this is what it will do to you".

**4. The voice is the shop's own, not a copywriter's.** Matched against the
storefront's existing English in `app/Services/Translation/InterfaceStrings.php`
— plain, warm, short sentences, honest about limits, UAE-aware. The benchmarks
I wrote against:

> *"Small joys, gently priced."* (`:collection.intro_under_54`)
> *"Steps it stocks nothing for are shown empty rather than filled with a guess."* (`:quiz.js_routine_link_lead`)
> *"Sunscreen every morning — the UAE sun is the whole game."* (`:quiz.js_step_protect_desc`)
> *"Answer five questions and we will build a routine from what we actually stock — with the reasoning behind every pick."* (`:home.quiz_body`)

No hype, no "unlock", no "transform your skin", no exclamation marks. If a draft
below sounds too plain to you, that is on purpose and I would push back once
before changing it.

**5. Where the copy goes.** Each concern needs a `title` and an `intro` pair in
`app/Services/Translation/InterfaceStrings.php` under `store.collection.*`,
mirroring the four that exist (`:473`). The `intro` is the block quoted under
the `<h1>`. **Both locales** — and the Arabic half is a human's job, not this
lane's and not a machine's. See `SEO-CONCERN-MAPPING.md` §7.

**6. No traffic claim is made anywhere in this file.** Nobody here has search
volume for any of these terms in either language.

---

## 1 · Redness & sensitivity — `sensitivity`

**Launch this one first.** It is the only one of the three with articles to link
to today: three of the shop's five journal pieces are about sensitive skin,
heartleaf and barrier repair (OBSERVED — `SEO-CONCERN-MAPPING.md` §6).

**H1:** Korean skincare for sensitive skin
**Title tag:** falls out of the existing `{title} {sep} {sitename}` template. Do not override it.

> **Intro (225 words)**
>
> Reactive skin is not a skin type you grow out of. It is a barrier that has
> been asked to do too much — too many actives at once, too hot a cleanse, or
> simply a summer of moving between 45°C outside and dry air conditioning
> inside, which is most of the year here.
>
> Korean skincare is unusually good at this problem, because calming ingredients
> are the tradition rather than the specialist corner. Centella asiatica —
> **cica** on most labels — is the one you will meet first, along with its
> refined form **madecassoside**. **Heartleaf**, or houttuynia cordata, does
> similar work with a lighter feel. **Panthenol** and **ceramides** are the
> repair side: they put back what a stripped barrier is missing rather than
> soothing the symptom.
>
> What we have collected here are the products from our range that lead on those
> ingredients — [A CENTELLA AMPOULE OR TONER], [A BARRIER CREAM], [A GENTLE
> CREAM CLEANSER] — plus the calming sheet masks that are the cheapest way to
> find out whether your skin agrees with an ingredient before you commit to a
> full-size bottle.
>
> One honest note. If your skin is reacting right now, the useful move is
> usually to take products away rather than add one. Cleanse, moisturise,
> sunscreen, and nothing else for two weeks. Come back for the actives when the
> stinging has stopped.

**Links out of this page — all three already exist:**

- *Heartleaf extract: transforming K-beauty skincare* → `/heartleaf-extract-transforming-k-beauty-skincare/`
  — anchor it on **heartleaf**, in the sentence that names it.
- *10 best Korean moisturizers for sensitive skin* → `/k-beauty-bliss-10-best-korean-moisturizers-for-sensitive-skin/`
  — anchor on **a barrier cream** or **moisturiser**.
- *How to repair a damaged skin barrier with K-beauty* → `/k-beauty-bliss-how-to-repair-a-damaged-skin-barrier-with-k-beauty/`
  — anchor on **a stripped barrier**.

**Brands to name once the tagging is done** (UNVERIFIED — `SEO-CONCERN-MAPPING.md`
§5): SKIN 1004, VT Cosmetics, Celimax. Replace with whichever brands actually
carry the page once it is populated; naming a brand the page does not stock is
worse than naming none.

---

## 2 · Acne & blemishes — `acne`

**H1:** Korean skincare for acne-prone skin

> **Intro (205 words)**
>
> Most acne routines fail for the same reason: they are too harsh, and skin that
> is stripped and raw produces more oil, not less. The Korean approach is
> gentler and slower, and it tends to hold up better over months.
>
> The working ingredient is usually **salicylic acid** — BHA on most labels —
> which is oil-soluble, so it gets into a pore rather than sitting on top of it.
> You will also see **tea tree** and **centella**: one clarifying, one calming,
> and most well-built Korean acne products pair them precisely so the treating
> step does not leave skin red. **Niacinamide** turns up here too, because it
> works on the marks a breakout leaves behind as much as on the breakout.
>
> This collection brings together what we stock for that routine — [A LOW-pH GEL
> CLEANSER], [A BHA TONER OR PAD], [A SPOT TREATMENT OR SERUM] — kept to
> products that treat, not products that cover.
>
> Two things worth saying plainly. Nothing on this page is a medicine, and
> cystic or painful acne is a dermatologist's job, not a skincare shelf's. And
> whatever else you use, use sunscreen — every acid on this page makes skin more
> sun-sensitive, and the UAE sun is the whole game.

**Links out of this page:** **none exist yet.** This is the gap
`SEO-BUILD-PLAN.md` Part II item 6 identifies. The article to commission is
*"BHA, salicylic acid and gentle acne care"*, and this page is what it links
back to.

**Brands** (UNVERIFIED): COSRX, SOME BY MI, Anua.

**Note on the last line:** the sunscreen sentence deliberately echoes
`quiz.js_step_protect_desc`, which already says *"the UAE sun is the whole
game"*. Reusing the shop's own phrase is intentional — it is the same shop
talking. Change it in both places or in neither.

---

## 3 · Dark spots & tone — `dark-spots`

**H1:** Korean skincare for dark spots and uneven tone

> **Intro (221 words)**
>
> Uneven tone is the slowest thing in skincare to shift and the easiest to undo.
> A mark left by a spot, a patch of sun damage, a shadow that was not there last
> year — all of it fades on a timescale of months, and all of it comes back
> faster than it went if the sunscreen lapses.
>
> The ingredients that do the work are well established. **Niacinamide** is the
> everyday one, gentle enough to use morning and night. **Vitamin C** is the
> brightener most routines are built around, and the reason so many Korean
> serums pair it with something calming. **Alpha arbutin**, **tranexamic acid**
> and **kojic acid** are the more targeted options, aimed at specific marks
> rather than at overall dullness — Medicube's Kojic Acid Turmeric line, which
> we stock, is built around exactly that pairing.
>
> Here you will find what we carry across those actives: [A VITAMIN C SERUM], [A
> NIACINAMIDE SERUM OR ESSENCE], [A TONER PAD], alongside the sleeping masks
> that make an overnight step easy to keep up.
>
> The part nobody enjoys hearing: **sunscreen is the treatment**. Every serum on
> this page is working against UV exposure, and without daily SPF it is a
> holding action at best. Give any of them three months before you judge them —
> six weeks is not long enough to tell.

**Links out of this page:** **none exist yet.** The article to commission is
*"Niacinamide and vitamin C for pigmentation"* (`SEO-BUILD-PLAN.md` Part II item
6, topic 3). Once it exists, link it on **niacinamide**.

**Brands** (UNVERIFIED): Beauty of Joseon, Axis-Y, Goodal.

**The Medicube sentence is the one factual product claim in this file and it is
OBSERVED**, from the product's own description in `storage/catalog/products.json`:
*"By combining Medicube's cutting-edge device technology with the brightening
synergy of Kojic Acid, Turmeric, and Niacinamide…"*. If that line is ever
delisted, cut the clause.

---

## What shipped, and what did not — Lane S8, 2026-09-26

**All eight concerns are enabled and all eight have written English copy.** The
owner's instruction was *"finish the missing items in the document. i don't want
to miss or skip anything."* `ConcernCollections::ENABLED` now holds every slug in
`RoutineConcerns`, and `store.concern.title_*` / `store.concern.intro_*` exist for
each of them in `app/Services/Translation/InterfaceStrings.php`.

**Enabling them published nothing.** A concern page needs copy AND at least
`ConcernCollections::MIN_PRODUCTS` live, in-stock products tagged for it. Nothing
is tagged, so all eight addresses answer 404, `/sitemap.xml` carries no
`/concern/` entry at all, and nothing links to one. Measured in
`ConcernCollectionsTest` ("enables all eight concerns and still publishes none of
them") and on `docs/SEO-PREVIEWS.html`. The pages appear one at a time as his
tagging crosses the floor for each concern — which is the staged rollout
"MEASURE ONE BEFORE SHIPPING SIX" was asking for, driven by the catalogue instead
of by a release. **He no longer needs a package to publish his second concern
page. He needs to tag three products.**

### The three drafts above did NOT ship as written, and this is why

The substance is kept — the ingredients, what each is for, and the honest limit
each draft ends on. Two things were dropped, and neither is a matter of taste.

**1. The `[SQUARE BRACKET]` placeholders.** A placeholder in a translation value
is **printed**. There is nothing in this application that resolves a bracket, so
`[A CENTELLA AMPOULE OR TONER]` would be read by a shopper, on a page submitted to
Google. Draft 1 carries three, draft 2 three, draft 3 three. The drafts are
correct as *drafts* — marking the slot rather than inventing a product name was
exactly right, and §2 above says so — but a draft is not a shippable value.

**2. The named brands.** Every brand list above is marked UNVERIFIED, and §1's own
note says *"naming a brand the page does not stock is worse than naming none."*
Which brands land on a concern page is decided by the owner's tagging, months from
now. A brand named in the intro is a promise the page may not keep.

**And one thing was changed: the length.** The drafts are 205–225 words; what
shipped is 90–140. Three reasons, in order of weight:

- **The template renders one paragraph.** `store/collection.blade.php` prints an
  `<h1>` and one intro block, which §"No H2 subheadings" above already
  establishes. A 225-word single paragraph is a wall; the same argument broken
  into three paragraphs needs a template change that would move the four curated
  listings that already work.
- **Every word is a word of commissioned Arabic**, per key, times eight.
- The word band is **practitioner convention, not documentation** — §1 of this
  file says so and says not to put it in a test. It is not in one. The only floor
  asserted is 40 words, which separates prose from a label.

If you want the long form, it is a template change plus a re-commission and it is
its own item. Say so and it gets done properly rather than smuggled into a
translation value.

### What is still yours on this file

**The Arabic.** Unchanged and not worked around: eight intros at roughly 120 words
for a human Arabic writer. The English pages work meanwhile, because the Arabic
layer is off.

**Editing or rejecting any of the eight English intros.** They are in
`InterfaceStrings.php` under `store.concern.*`. Read them on the shop once three
products are tagged, and if one sounds too plain, that is on purpose — §4 above
says the voice pushes back once before changing.

---

## What I did not write, and why

**No Arabic.** `SEO-BUILD-PLAN.md` Part II item 2 concluded that
machine-translated Arabic is worse than no Arabic, and that Gulf product search
skews to dialect over Modern Standard Arabic. That conclusion has not changed in
a day and this lane did not quietly work around it. **These three pages need a
human Arabic writer, and that is a line item with a fee, not a task a lane can
absorb.** Three intros at roughly 200 words each is a very small commission —
which is the argument for doing it properly rather than the argument for
skipping it.

**No meta descriptions.** The shop builds one from the intro already, and a
hand-written one per collection is a separate, smaller decision that should be
made after the intros are signed off rather than at the same time.

**No H2 subheadings or FAQ blocks.** The four existing collections render an
`<h1>` and one intro paragraph and nothing else (OBSERVED —
`CollectionController::seoCtx()` and the collection template). Adding a
richer page shape is a template change that would alter all four existing
collections, and rule 1 says nothing that already works may change. If a richer
shape is wanted, it is its own item with its own pin advanced deliberately.

**No `FAQPage` markup.** Refused in Part I and reaffirmed in Part II: Google
stopped showing FAQ rich results on 7 May 2026. The FAQ *content* may still earn
its place for readers; the markup buys nothing.
