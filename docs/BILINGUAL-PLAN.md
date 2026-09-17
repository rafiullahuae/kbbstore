# A second language for K-Beauty Bliss

**Status:** the foundation is built and shipped OFF. Nothing on the live shop
changes when this package is applied. Arabic and the right-to-left layout are
two switches in the admin, both off, and the shop is English-only until they
are turned on.

**Written for:** the owner first, the lanes that follow second. The first half
is in plain English. The second half is the engineering record.

---

# Part one — for the owner

## What you asked for

> "I would like to have a dual language Arabic, for the whole website, i don't
> want to rely on google translation etc. i want a dedicated own translated
> version of our web app. with custom front-end url /ar and by default it will
> have /en. please plan super well and advise. all the translation we will enter
> ourselves, or on all pages, we will option to Translate from Google to enter
> in all required texts instead of manual enter, and if we found anything
> incorrect, we can correct it manually."

You will get exactly that. Here is how, and what it will cost you in time.

## What your shop will look like

Your English shop **does not move**. `kbeautybliss.com/shop/` stays
`kbeautybliss.com/shop/`. Every link you have ever shared on WhatsApp or
Instagram, every address Google has indexed, keeps working.

Arabic sits alongside it with `/ar/` in front:

| English | Arabic |
| --- | --- |
| `kbeautybliss.com/shop/` | `kbeautybliss.com/ar/shop/` |
| `kbeautybliss.com/product/anua-heartleaf-toner/` | `kbeautybliss.com/ar/product/anua-heartleaf-toner/` |
| `kbeautybliss.com/cart/` | `kbeautybliss.com/ar/cart/` |

`kbeautybliss.com/en/shop/` also works — it sends the visitor to the plain
English address. So the `/en` you asked for exists, and nothing had to move to
give it to you.

**Why not `/en/` for real?** Because that would move every address on your shop
for the second time this year. Everything Google knows about your shop would
have to be re-learned, every saved link would bounce through a redirect, and
your search ranking would take the hit twice. You already paid that price moving
off WordPress. It is not worth paying again for tidiness.

## How you will translate it

There will be a new **Translation** menu in your admin, next to Store and
Content. Inside it:

- **Settings** — the two switches, and a progress bar for each part of the shop.
- **Interface text** — the buttons and headings: "Add to bag", "Nothing saved
  yet", "View your order". A few hundred short phrases, English on the left, a
  box for Arabic on the right.

And — this is the important part — **every editor you already use gets an
Arabic box beside the English one.** When you add a product, the Arabic name and
Arabic description are fields on the same form, saved by the same Save button.
Same for categories, brands, pages and Journal posts. You never have to remember
to go somewhere else afterwards, which is how shops end up half translated.

**A blank Arabic box means "not translated yet."** It never means "same as the
English". If you want the Arabic to read the same as the English — a brand name
like Anua, a size like 50ml — type it in. That way the progress bar is telling
you the truth instead of guessing.

**If a word has no Arabic yet, the shopper sees the English.** Your shop keeps
selling while you translate. There is also a switch called *Show untranslated
text in brackets* — turn it on, walk your own shop in Arabic, and everything you
have not done yet appears as ⟪like this⟫. Turn it off when you are done. It is
for you, not for customers.

**You can also correct the English** through the same screens, without waiting
for a code update.

## The Translate-from-Google button

You asked for it and you should have it, but you should know exactly what it is
before you press it.

- It is **not free and not built in.** You open a Google Cloud account, create
  an API key, and paste it into Translation → Settings. The bill is yours.
- **It sends your product text to Google.** That is what it does. It is a
  reasonable thing to agree to; it is not a reasonable thing to do without
  knowing.
- **Google charges about USD 20 per million characters**, and **the first
  500,000 characters each month are free.**
- **Nothing is ever sent until you have seen a number.** Press Estimate and the
  screen tells you how many characters are outstanding and what it would cost.
  Then, and only then, is there a button that spends anything.
- **Nothing a machine writes goes live on its own.** Machine translations arrive
  as **drafts**. Your shoppers cannot see a draft. You read it, fix it, press
  Approve. That is what makes "if we found anything incorrect, we can correct it
  manually" actually true.
- There is also a small **Translate** button beside each Arabic box in the
  product editor. It fills the box and nothing more — you read it and edit it,
  and it is saved by the ordinary Save.

**Everything except that button is free and needs no account at all.** Typing
Arabic in by hand works with nothing configured.

### What we do *not* send to a machine, and why

Your long product descriptions carry formatting — bold text, bullet lists, line
breaks. Every translation service mangles one of two things when given
formatted text: either it breaks the formatting, or it translates the
formatting as though it were words. Neither is acceptable on a product page,
and you would be paying for the damage.

So the machine does **names, short descriptions and interface text**. Long
descriptions are typed. The progress bar counts them as outstanding, honestly,
rather than pretending they are done.

## How much work is this, really

**The honest answer, with the part we cannot see marked as such.**

Measured directly from this repository:

| What | Amount |
| --- | --- |
| Buttons, headings, labels across 99 storefront and email templates | **~30,000 characters** |
| Menu labels (39 items, the real live count) | 366 characters |
| Your content pages (About, Delivery, FAQs, and so on) | ~11,500 characters |
| Messages in the shop's JavaScript | ~1,100 characters |

Estimated for the live shop, which this development copy does not contain:

| What | Amount | How confident |
| --- | --- | --- |
| 671 product names | ~30,000 characters | good |
| 671 short descriptions | ~170,000 characters | fair |
| 671 long descriptions | **~800,000–1,000,000 characters** | **a guess** |
| ~93 brands, ~30 categories | ~5,000 characters | good |
| Journal posts | ~60,000 characters | a guess |

**So the figure you were given — around 700,000 characters — is the right order
of magnitude but it is probably low for the whole shop, and it is not the number
that matters.** Here is the number that matters:

- **The part a machine can translate is roughly 230,000 characters.** That is
  inside Google's free 500,000 a month. **One month, no bill.**
- **The part a machine must not translate — the long product descriptions — is
  the bulk of the volume**, and no amount of free allowance helps, because it
  should be typed by a person who reads Arabic.

The tool tells you the real figure the moment it runs against your live
database. Press Estimate before you plan anything around these numbers.

**Your time.** Translating 671 long product descriptions properly is the job.
At a realistic five minutes each that is about 55 hours. That is the honest
scale, and it is why the plan below lets you go live in Arabic *before* the
product descriptions are done — the shop falls back to English for anything you
have not reached, so you can publish with the interface and the product names
translated and work through the descriptions afterwards.

## Right-to-left

Arabic reads right to left. Doing that properly means mirroring the layout — the
menu, the product grid, the cart, the buttons — not just changing the text
direction.

Three things deliberately **do not** mirror, and that is correct:

- **Prices stay `AED 199`.** Never `199 AED` reversed, never Arabic-Indic
  digits. Arabic shops write prices this way.
- **Product photographs keep their orientation.** A mirrored bottle is a
  different bottle.
- **Your logo is unchanged.**

Right-to-left is its **own switch**, separate from Arabic. That is on purpose:
if the mirrored layout needs more work after you have gone live, you can turn
the layout back without taking Arabic down. The admin will warn you when Arabic
is on and right-to-left is off — that combination is fine while the work is
being finished and wrong once it is.

We also have to add an Arabic typeface. The font your shop uses, Poppins, has no
Arabic letters in it at all — not "renders them badly", it does not contain
them. Without a proper Arabic face, Arabic text falls back to whatever the
visitor's phone happens to have, which looks like a broken website. Cairo, from
Google Fonts, is free and loads only on Arabic pages, so your English pages are
unaffected.

## What happens next, in order

Each step below is a separate piece of work. You can stop after any of them and
have a shop that works.

| # | Step | Size | Needs |
| --- | --- | --- | --- |
| **1** | **The foundation** — done, shipped off | — | — |
| 2 | The Translation menu and the Arabic boxes in your editors | Medium | Step 1 |
| 3 | Translate the interface: ~30,000 characters of buttons and headings | Medium — mostly your time | Step 2 |
| 4 | The right-to-left layout | Large | Step 1 |
| 5 | Search-engine work: sitemap, hreflang, per-language canonicals | Small | Steps 1 and 3 |
| 6 | Emails and invoices in the customer's language | Medium | Step 1 |
| 7 | Translate the catalogue: names, then short descriptions, then long ones | Large — mostly your time | Steps 2 and 3 |
| 8 | Switch Arabic on | — | Steps 3–6 |

**You can go live at step 8 with step 7 unfinished.** Anything untranslated shows
in English.

---

# Part two — the engineering record

## Decision 1 — URL shape: English unprefixed, Arabic at `/ar/`, `/en/` a 301

**Chosen:** (b). English keeps every address it has. Arabic is served under
`/ar/…`. `/en/…` answers a single 301 to the unprefixed form, so the address the
owner asked for resolves without creating a second copy of every page.

**What (a) — both prefixed — would have cost, concretely:**

- Every indexed URL moves a second time, months after the WooCommerce migration
  moved it once. The URL Contract (U-01 … U-07) exists to stop exactly this.
- Every row in the `redirects` table becomes a 301 → 301 chain. Chains shed
  ranking and Google follows a limited number of hops.
- `routes/kbb-brands-blog.php`'s root-level post route, `PageController::
  slugPattern()` and `RESERVED_SLUGS` all move under a prefix.
- The sitemap, `Indexability::isPrivate()`'s prefix list and every
  `Url::to('/…')` literal change meaning.
- Every link in every email the shop has ever sent points at a redirect.

The only thing (a) buys is symmetry. It is not worth it.

**How it composes with what is already here:**

- **`KBB_BASE_PATH`** — base path OUTSIDE, locale INSIDE:
  `/kbb-upgrade/ar/shop/`. The base path is where the application is *mounted*;
  the locale is a fact about the *page*. Reversing them means the front
  controller is never reached. Pinned by test.
- **`RESERVED_SLUGS`** — `ar` and `en` are now reserved, but **not for routing**.
  The middleware strips `/ar` before the router runs, so the router never sees
  it. They are reserved because a post whose slug was literally `ar` would be
  published at an address the middleware eats, unreachable with nothing saying
  why.
- **The root catch-all** — untouched. It never sees a locale segment.
- **`Url::to()`** — adds the prefix, in one place, for all 194 call sites, plus
  the self-referencing canonical, which is built from the request path and fed
  back through it.
- **`Url::media()`** — deliberately does **not**. `/wp-content/uploads/…` is
  served off disk by the web server; PHP never sees the request, so a prefixed
  image URL is a 404 on every Arabic page. Same for `/storage`, `/build`,
  `/fonts`, `/assets`.
- **The admin** — never prefixed. One address for the back office.
- **`sitemap.xml`, `robots.txt`, `llms.txt`** — never prefixed. One canonical
  address each.

## Decision 2 — translations live in the database

**Forced by the host, twice over, not chosen on taste.**

1. There is no shell. A file under `lang/` can only change by building a signed
   zip and applying it through Store → Core Updates. A correction that costs a
   release is a correction that does not happen.
2. **A package could not carry the file anyway.**
   `App\Services\Update\UpdateGuard::ALLOWED_PREFIXES` is `app/`, `config/`,
   `database/migrations/`, `database/seeders/`, `resources/`, `routes/` and
   `public/build/`. **`lang/` is on none of them** — an update package
   containing `lang/ar.json` is rejected before a file is written. Laravel's
   conventional home for translations is, on this host, a directory that cannot
   be shipped to.

**So:** English defaults live in `App\Services\Translation\InterfaceStrings`
(under `app/`, shippable). Everything typed lives in the `translations` table.

**The fallback chain**, for a UI key in Arabic:

1. published Arabic row → use it
2. published English row → use it (owner corrected the English without a release)
3. English default in `InterfaceStrings` → use it
4. nothing → the key itself

A **draft is never step 1**.

**An untranslated string renders as English.** Not a placeholder. The shop is
live while it is being translated and a half-Arabic page still sells something;
a page full of ⟪brackets⟫ does not. The bracket behaviour exists behind
`translation_highlight_missing`, off by default, as a review tool.

**Cache and memo.** `TranslationStore` holds a per-locale map in a cache entry
*and* a process-level memo — and `flush()` clears **three** layers, because
`Illuminate\Translation\Translator` keeps its own `$loaded` cache of every group
it has seen and never asks the loader again. Missing that third layer meant
"press Approve, reload, the draft is still hidden". Evicted from the model's
`saved`/`deleted` hooks, so every writer evicts. Registered in
`Tests\Support\StaticMemos`.

## Decision 3 — a polymorphic table, not `name_ar` columns

**Chosen:** one `translations` table keyed by `(locale, group, item_id, field)`.

Against extra columns:

1. Every new translatable field would be a migration, i.e. a release.
2. "What is still untranslated" would be a COALESCE per column per table,
   rewritten whenever a column is added. Here it is one grouped query.
3. A third language doubles the column count on six tables.

**The read cost, measured rather than asserted.** The naive failure is an N+1 on
a 24-product grid. It does not happen: one locale's whole published set is a
single cached map, so a translation is an array lookup.
`BilingualFoundationTest` drives 24 products with 24 translations and asserts
**zero queries**. `scopeWithTranslations()` is a real eager load (one extra
query per page), written and tested and currently unused, so swapping to it when
the map stops being cheap is a one-line change.

**Collation.** `config/database.php` sets `utf8mb4_unicode_ci` and the table is
created with it. Measured on the MySQL 8.0 the suite runs against:

```
'كتاب' = 'كِتاب'  (kasra)   → TRUE
'محمد' = 'محمّد'  (shadda)  → TRUE
'Name' = 'name'             → TRUE
'name' = 'name  '           → TRUE   (PAD SPACE)
under utf8mb4_bin: all FALSE
```

Two Arabic strings a reader sees as different are, to a unique index or a
`WHERE`, the same string. So **`value` is never indexed, never unique and never
compared with `=`** — and the key columns are normalised to lowercase in PHP
instead, where one rule holds on both engines. Pinned by a MySQL-only test.

`item_id` defaults to **0, not NULL**: `NULL != NULL`, so a nullable column in a
unique index leaves the interface strings — the half with the strongest reason
to be unique — with no uniqueness at all.

## Decision 4 — what is never translated

SKU, coupon code, order number, currency code, price. And **slugs**: one slug
per row, language carried by the prefix only.

Translated slugs would cost a second slug column on six tables, a second
uniqueness space, a second set of redirect rows, a doubled sitemap, and a
product whose address changes when someone edits its Arabic name. Arabic URLs
percent-encode into unreadability when copied anyway. `hreflang` is what tells
Google the two addresses are one product.

Enforced by an allowlist per model (`protected array $translatable`), pinned by
a test that fails if `sku`, `slug`, `code`, `order_number`, `currency`, `price`
or `url` ever appears on one.

## Decision 5 — `orders.locale`

Added, default `'en'`, `varchar(5)`. Recorded by an `Order::creating` hook in
`App\Support\OrderLocale`, **not** by a line in the checkout: orders are created
in five places in this application and a rule applied in four is not a rule. An
explicitly-set locale is never overwritten, so an admin can key in a phone order
taken in Arabic.

The half that matters is reading it back. `OrderLocale::render($order, $fn)`
restores the order's language for one closure and puts the previous one back in
a `finally`, so a failed email cannot leave a queue worker set to Arabic for
every job after it. Without this, an Arabic shopper gets an Arabic checkout and
an English invoice, an English confirmation and an English "your order has
shipped" weeks later.

**What the admin sees:** an `EN`/`AR` badge beside the order number on the list
and the detail screen; a control on the detail screen only, for the phone-order
case.

## Decision 6 — RTL: the audit

**Audited, not built.** Another lane holds the storefront CSS this round.

Across `resources/css/kbb/*.css` — 5,727 lines, 3,385 rules, 8,230 declarations
(excluding `admin-skin-preview.css`):

| Pattern | Count |
| --- | --- |
| `margin-left` / `margin-right` / `padding-left` / `padding-right` | **106** |
| `left:` / `right:` offsets | **216** |
| `border-left` / `border-right` (incl. `-width`/`-color`/`-style`) | 26 |
| `text-align: left\|right` | 16 |
| `border-radius` with four values | 19 |
| `border-*-*-radius` corners | 2 |
| `translateX(` / `translate(` | 63 |
| `flex-direction: row-reverse` | 0 |
| already logical (`margin-inline`, …) | 3 |

Plus **70 inline `style=` attributes** in Blade carrying a physical direction,
and **14 storefront Blade files with inline `<style>` blocks**.

Concentration: `kbb.css` carries 53 margin/padding, 87 offsets, 33 transforms;
`kbb-checkout.css` 19/28/6; `kbb-shop.css` 14/17/10; `kbb-product.css` 11/15/5.

**Recommended approach: CSS logical properties, one stylesheet for both.**
`margin-inline-start` instead of `margin-left`, `inset-inline-start` instead of
`left`, `text-align: start`. Browser support is universal in every browser this
shop's analytics see. One sheet serving both directions beats a mirrored
`rtl.css` because a mirrored sheet is a second file that silently drifts out of
step with the first, and this shop has already shipped that class of defect.

The 63 transforms need hand review: `translateX(-50%)` for centring is
direction-neutral and must be left alone, while a slide-in drawer must flip.
`transform` has no logical equivalent, so these are the manual half.

**Explicitly does not mirror:** prices stay `AED 199` (Latin digits, currency
first); product photographs keep orientation; the logo is unchanged.

**The typeface.** Poppins contains no Arabic glyphs. Cairo (Google Fonts, SIL
OFL, weights 400/600/700) is loaded **only on Arabic pages** — already wired in
`layouts/store.blade.php` and pinned by a test that English pages do not fetch
it.

**Estimate:** roughly 420 declarations to convert plus ~70 inline styles and 63
transforms to review. Mechanical for the first two-thirds; the rest is eyes on a
screen.

## Decision 7 — machine translation, honestly

- **Pluggable.** `TranslationProvider` interface; `NullProvider` is the default
  binding, `GoogleProvider` bound only once a key is saved. DeepL or Azure is a
  new class.
- **His own key**, stored encrypted in the database (`Crypt::encryptString`,
  the `MailCredential` precedent) because `.env` is neither shippable nor
  editable on this host. Never returned by any endpoint — only `has_api_key`.
  Pinned out of `SettingController::PUBLIC_KEYS` by a test.
- **Cost shown first.** `TranslationEstimate` counts source characters with
  `mb_strlen`, excludes anything already translated, and reports USD at
  20/million with the 500,000/month free tier subtracted. The run endpoint
  requires `confirm_characters` to match within 2%, so a stale tab cannot
  authorise a much larger run than the one that was shown.
- **Batched**, 100 per request (Google's own limit), order preserved with
  `null` placeholders — a shifted batch writes every product's copy onto the
  next product.
- **Drafts only.** `status = 'draft'`, `source = 'machine'`.
  `TranslationStore::map()` reads published rows only, so a shopper cannot see a
  draft. Approving is a separate, explicit call.
- **Markup is never sent.** `isMachineSafe()` rejects anything containing a tag
  and anything with no letters.
- **No test touches the network.** The provider is `NullProvider` or an inline
  fake.

## Decision 8 — sequencing

| Phase | What | Size | Depends on |
| --- | --- | --- | --- |
| **1** | Foundation (this) | done | — |
| 2 | Translation admin screen + Arabic boxes in the six editors | ~2–3 days | 1 |
| 3 | Convert ~95 Blade files + 20 JS strings to `__()` | ~3–4 days | 2 |
| 4 | RTL: logical properties across 11 stylesheets | ~3–4 days | 1 |
| 5 | SEO: sitemap per language, hreflang in the sitemap, canonical | ~1 day | 1, 3 |
| 6 | Emails and invoices honour `orders.locale` | ~1–2 days | 1 |
| 7 | Content translation (owner's time) | ~55 hours | 2, 3 |
| 8 | Switch on | — | 3–6 |

---

## What an editor must send, exactly

For the lane building phase 2.

**Payload**, on the existing create/update endpoint for the model:

```
translations[ar][name]              string|null
translations[ar][short_description] string|null
translations[ar][description]       string|null
```

**Controller**, one line after the English row is saved:

```php
$product->fill($data)->save();
$product->saveTranslations($request->input('translations', []));
```

Works for create and update alike, because it runs after `save()` when the row
has an id. Ordinary Eloquent writes, so a transaction the caller opened covers
them.

**Validation:** `['translations' => ['sometimes','array'], 'translations.*' =>
['array'], 'translations.*.*' => ['nullable','string','max:65000']]`. Nothing
stricter is needed — an unknown locale or a field outside the model's
`$translatable` allowlist is dropped silently, because this is fed by a form and
a 422 in the middle of saving a product somebody spent ten minutes on is worse
than a dropped field. The allowlist itself is the guard that matters.

**Blank means "not translated yet" and deletes the row.** Never "same as
English". To say "deliberately identical", type the English in.

**Manual entry is published immediately** (`status = 'published'`,
`source = 'manual'`). Only machine output drafts.

**Prefilling the boxes:** `$product->translationsForEditor()` returns
`['ar' => ['name' => ['value','status','source','stale'], …]]`, drafts included —
a draft must appear in the box, or "approve" means approving something invisible.

**Per-field Translate button:** `POST /admin-api/translations/machine/field`
`{text, locale}` → `{translation, characters}`. Stores nothing; the text lands
in the box and is saved by the ordinary Save.

## Admin endpoints

All inside the `admin-api` group (`web`, `auth:admin`, `NoStoreAdminApi`).

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/admin-api/translations/settings` | switches, provider state, warning |
| POST | `/admin-api/translations/settings` | `{arabic_enabled?, rtl_enabled?, highlight_missing?, api_key?}` |
| GET | `/admin-api/translations/progress?locale=ar` | per-area counts |
| GET | `/admin-api/translations/estimate?locale=ar` | characters + USD, spends nothing |
| GET | `/admin-api/translations?locale&group&missing&q&limit` | the editing list |
| POST | `/admin-api/translations` | `{locale, group, item_id, field, value}` |
| POST | `/admin-api/translations/publish` | `{locale, group?, item_id?, field?}` |
| POST | `/admin-api/translations/machine/field` | per-field button (throttle 60/min) |
| POST | `/admin-api/translations/machine/run` | the only endpoint that spends money (throttle 12/min) |

**Settings keys the screen reads and writes:**

| Key | Values | Default |
| --- | --- | --- |
| `language_ar_enabled` | `'1'` / `'0'` | **absent = off** |
| `language_rtl_enabled` | `'1'` / `'0'` | **absent = off** |
| `translation_highlight_missing` | `'1'` / `'0'` | **absent = off** |
| `translate_api_key` | encrypted, `autoload = false` | absent |

Nothing is seeded. `SettingsService::get()` returns its default only when the
row is absent, which is why no `'0'` is written anywhere.

## Wiring the integrator must do

1. **`routes/web.php`**, inside the existing `admin-api` group:
   `require __DIR__.'/translations-admin.php';`
2. **`bootstrap/app.php`** — already edited in this branch, and the one change
   that must be applied to the server **by hand** (`bootstrap/` is on
   `BuildPackage::NEVER_SHIP` and `UpdateGuard` forbids it):
   `$middleware->prepend(\App\Http\Middleware\SetLocaleFromPath::class);`
   **Forgetting it is safe** — `/ar/…` simply 404s and the shop is English-only.
3. The **Translation** parent menu and its screen, in
   `resources/views/admin/app.blade.php`.
4. The **Arabic boxes** in the six editors.
