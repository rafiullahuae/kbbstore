# Lane GS · one reusable product-grid section, used as many times as wanted

Phase 23. The owner, verbatim:

> "Under the above sections, i need another section as same as attached, the
> product grid 4 by default on desktop and on mobile careousel, and give
> controls to chooose the brand, category, manual products selection etc and
> other controls. on desktop also give control to make it carousel. with bottom
> view all button (manual link) and THEN i need anothe product grid section
> called BEST SELLERS. 5 columns on desktop and in mobile 6 products, and all
> controls i need for it. DO ONE thing. prepare a proper grid section with all
> controls and it can be use anywhere, and can be edit that specific grid
> section. so this case we can re-use this grid section anywhere multiple times
> with different products etc selection."

**The last three sentences are the brief.** He is not asking for two sections.
He is asking for one section TYPE he can add as many times as he likes, each
instance with its own heading, its own product selection and its own layout.
The 4-up bundles row and the 5-up BEST SELLERS row are the first two INSTANCES
of it, and they are created through the mechanism — from two one-click presets —
rather than hand-built.

**Where it sits: `Appearance → Grid sections`.**

---

## 1. The decision that everything else follows from

**An instance is a ROW IN THE EXISTING HOMEPAGE SECTION REGISTRY, not a new
mechanism beside it.**

`HomepageSections` keys ordering, the Desktop/Mobile switches and the section
dividers off `REGISTRY`, which is a `const` and therefore fixed. There is now
one method beside it:

```php
public static function registry(): array
{
    return self::REGISTRY + GridSections::registryRows();
}
```

`registryRows()` is one row per instance in `grid_sections`. Seven `self::REGISTRY`
reads inside that class became `self::registry()`; the const is untouched and
still public.

What that buys, and the reason a second mechanism was not built:

| an instance gets | from |
|---|---|
| a position among the shop's other sections | `Appearance → Homepage`, the ↑↓ it already has |
| Desktop / Mobile switches | `HomepageSections::SECTION_SCHEMA`, cast by the same `ModuleSchema::cast()` |
| a divider above it | `SectionDividers`, unchanged |
| the CSS `order` rules that move it | `HomepageSections::orderStyle()`, unchanged |
| `settle()`'s renumbering, and the preset machinery | unchanged |

A parallel order and a parallel pair of device switches would have surfaced
immediately as two screens disagreeing about where a section sits — which is the
fault `HomepageSections::settle()` and `HomepageLayouts::summaries()` both exist
to stop one level along.

**One line on the console's side.** `HomepageApiController::payloadFor()` read
`HomepageSections::REGISTRY[$row['key']]` to reject unknown sections. Appearance
→ Homepage paints an instance's row, so it posts it back — and against the const
every instance is an "Unknown section", which 422s the whole save. The screen
would show the row, let the owner reorder it, and refuse to store any of it.

**The skin is the one value that deliberately does NOT go through it.** The
registry row says `has_grid = false`, so the Homepage screen draws no card-template
picker for an instance even though an instance is nothing but a grid. The skin is
edited on the instance's own screen beside the rest of its controls, and a value
with two homes is a value that will disagree with itself.

---

## 2. What one instance carries

Nineteen controls, declared once in `GridSections::SCHEMA` and read three times —
by the screen (through `ModuleSchema::tabs()`), by the cast on every write, and
by the write-token sweep. A field added there appears, validates and persists
from one statement.

| group | controls |
|---|---|
| **This grid** | name, published/draft |
| **Heading** | show the heading, heading, sub-heading |
| **Which products** | source (best sellers · newest · on sale · featured · one brand · one category · a list I pick), brand, category, include sub-categories, how many on desktop, how many on mobile |
| **Layout** | desktop layout (grid/carousel), desktop columns 2–6, mobile layout, mobile columns 1–3, card template, eyebrow on every tile, number the tiles #1 #2… |
| **“View all” button** | show it, its text, its link |

Plus the manual picker — search the catalogue, add, reorder, remove — which
appears only when the source is "a list I pick myself".

**The two counts are one query.** "5 columns on desktop and in mobile 6
products" is two numbers over one selection. The row is fetched once at
`max(count, mobile_count)` and the surplus is hidden at the other width by a
class on the cell — `gs-d-only` / `gs-m-only`. A second query for the second
number would double the section's cost for a difference that is presentational,
and a `:nth-child()` rule cannot take the number because a custom property may
not appear in a selector.

---

## 3. What N instances cost

`StorefrontQueryBudgetTest` gives the homepage 5 and measures 3. Measured on this
project's own fixture, with this feature's two cache entries cleared and the rest
of the page warm, against a fully warm page:

| instances | cold | warm |
|---|---|---|
| 0 | 1 | 0 |
| 1 | 4 | 0 |
| 3 | 8 | 0 |
| 6 | 14 | 0 |

The warm column is what a shopper gets and what the budget file sees: the
homepage measures the SAME number with six instances built as with none, because
`forHome()` is one cache entry and `registryRows()` is another.

The cold column is `1 (registry) + 1 (rows) + 2 per DISTINCT selection`. The 2 is
the products and then the `with('brand:id,name,slug')` eager load the card needs
— which is what makes the page flat, so it is bought deliberately and counted
rather than hidden. An empty selection costs 1, because Eloquent skips an eager
load with nothing to hydrate.

**Two instances asking for the same products are one query.** `specKey()` is
what makes that true, and `GridSectionQueryCostTest` asserts it from both sides:
three instances sharing one selection cost 3, three on different brands cost 7.
A `specKey()` that returned a constant would make the first half greener rather
than redder, which is why the second half exists.

**Flat in the catalogue**: six instances cost the same over 12 products and over
60.

**Indexes, checked rather than assumed.** `bestsellers` walks
`products_total_sales_index`; `newest` walks `products_created_at_index`; both
from the 2026-10 storefront-speed migrations. `onsale` has **no index behind it
and this lane did not add one**: the predicate is `sale_price < price`, a
comparison between two columns that no single-column index can serve, and
`HomeController`'s own flash rail has run exactly it unindexed on this page since
it was written.

---

## 4. The carousel is CSS, and there are no arrows

`CLAUDE.md` rule 4 forbids JavaScript that measures layout, and a previous/next
arrow is the most tempting place in this codebase to break it: an arrow has to
know how far to scroll and every way of knowing that is a measurement.

The row is `scroll-snap-type:x mandatory` over one `calc()`:

```
flex: 0 0 calc((100% - (var(--gs-d,4) - 1 + var(--gs-peek,.28)) * var(--kbb-gap,14px))
              / (var(--gs-d,4) + var(--gs-peek,.28)))
```

`--gs-d` whole cards, one gap fewer than that, and 0.28 of the next card
showing so the row reads as scrollable. The partial carries **no `<script>` at
all** and none of the eleven element-measuring APIs by name —
`GridSectionShapeTest` asserts it against the list
`CardsBannerSectionShapeTest` and `InstagramSectionShapeTest` use.

The affordance is the peek, which is what `.kbb-home .rail` on this same page has
used since it was written. `prefers-reduced-motion: reduce` switches off the only
motion the section has.

**The stylesheet is inline and every byte of it is a constant.**
`HomepageSections::orderStyle()` already argues why inline: the storefront serves
BUILT css from a web root that is a different directory, `npx vite build` is
manual, and a rule added to `kbb.css` ships inert until somebody rebuilds. The
per-instance numbers ride in each section's own `style` attribute through
`{{ }}`, as integers clamped twice — so nothing printed unescaped came from a
setting, and **this lane touches no file under `resources/css/`**.

---

## 5. Arabic

Not one `[dir]` selector, and no direction-specific rule to keep in step. Every
inline axis is logical — `padding-inline`, `margin-inline`,
`scroll-padding-inline-start`, `text-align:start` — so a flex row inside
`dir="rtl"` lays out and scrolls the other way because that is what the inline
axis IS.

Measured in Chromium rather than asserted (`tools/gs-shoot.mjs` sets `scrollLeft`
to ±400 and reads back, which is one full card — a smaller nudge is pulled back
by mandatory snapping and reads as "not scrollable" on a row that scrolls
perfectly well):

| page | direction | advances |
|---|---|---|
| `/` 390px | ltr | positive (left → right) |
| `/ar/` 390px | rtl | **negative (right → left)** |

The heading, the sub-heading, the eyebrow and the button text are the OWNER'S and
are printed as typed and escaped, never keyed — a second English source for a
value he types is one of the two silently wrong, which is the rule
`InterfaceStrings` states for the cards banner's own headings. The one string
this section supplies itself is the fallback on the button, and that is
`store.product_grid.view_all` — the key the shop ALREADY has for the "View all"
under a product grid (`components/product-grid.blade.php`,
`store/brands.blade.php`), reused rather than duplicated. A new key was written
first and `ArabicInterfaceDraftsTest` refused it twice in one run: on the draft
count, and on "it never labels two controls on one screen with the same Arabic",
because both would have carried عرض الكل.

---

## 6. Security

| rule | how |
|---|---|
| a select stores one of its own options or the default | `GridSection::SOURCES`, `::LAYOUTS`, `::DESKTOP_COLS`, `::MOBILE_COLS`, `::STATUSES` are the option sets AND the validator, handed to `ModuleSchema::cast()` as overrides. Posted rubbish lands on the default. |
| the "View all" link is scheme-checked | `SafeUrl::href($url, '')` — and `''` rather than `#`, so a refused address draws NO BUTTON. Six schemes pinned, including `jav&#x09;ascript:` and `java\nscript:`, which an HTML escaper passes through byte for byte. |
| a manual list is ids validated against rows that exist | `cleanManualIds()`: de-duplicated, capped at 48 (which is `fetchCount()`'s own ceiling), and filtered through a `whereIn` against `products`. |
| nothing printed unescaped that is not a constant | `GridSections::css()` has no interpolation at all. Asserted against a heading of `</style><script>alert(1)</script>`. |
| every new admin endpoint gets its own capability and fails closed | `gridsections.view` / `gridsections.manage`, writes listed ABOVE reads because `AdminCapabilities::RULES` is first-match-wins. |
| allowlist what a model returns | the manual picker returns five columns, asserted by name and by the absence of `sku`, `wc_id` and `total_sales` against a product row carrying them. |

The picker is not under `/api/*` — it is behind `auth:admin` and its own
capability — and it still names its columns, because CLAUDE.md's list of what
leaked in production is a list of endpoints somebody was sure was private.

---

## 7. Nothing moves until the owner builds one

The table is created **empty**. With no rows `registryRows()` is `[]`,
`HomepageSections::registry()` is the const byte for byte, `forHome()` returns
`[]` before it reads anything, the loop emits no element and pushes no
stylesheet.

`GridSectionShipsOffTest` fills the table the way the live shop will be the
morning after and asserts the same bytes for: a table full of drafts, an instance
switched off on both devices, and an instance whose selection came back empty.
`StorefrontEnglishUnchangedTest` is the instrument for the rest.

**The owner's two rows are presets, not defaults.** Seeding them would put two
new bands on a live front page the moment the package applied.

---

## 8. Reproducing the pictures

```bash
cp -al public/build public-web-root/build      # public_path() is public-web-root
php artisan migrate --force
php tools/gs-seed-preview.php                  # presses the two preset buttons

export KBB_PUBLIC_PATH=$PWD/public-web-root SESSION_DRIVER=file
php -S 127.0.0.1:8971 -t public-web-root tools/gs-router.php &

node tools/gs-shoot.mjs         # the storefront pair, EN and AR, 390 and 1280
node tools/gs-shoot-admin.mjs   # the console, 390 and 1280
```

**`-t public-web-root` is part of the command and not a detail.** Without it
`php -S` resolves both its own static handler and the router's `return false`
against the CWD, so every `/build/assets/*.css` 404s. The first run of this
harness reported no overflow anywhere and `display:block` on the shop's OWN four
rails, because with no stylesheet there is no layout to break. `tools/gs-shoot.mjs`
now re-asserts both sheets at 200 and `.kbb-pgrid` computing `grid` before a
single row is believed — a sweep that cannot fail is not evidence.

Screenshots and `measurements.json` are in `docs/lane-gs-shots/`.

---

## 9. The integrator's five edits

`docs/GS-ADMIN-APP-BLOCKS.md`, with every anchor verified to occur exactly once.
All five are one commit: four of them leaves either a sidebar row that opens the
dashboard or a finished-looking screen whose every endpoint 404s.

The fifth is the one that is easy to leave out — `docs/T1B-ADMIN-APP-BLOCKS.md`
quotes the `LATE_RENDERED` line verbatim and `TranslationConsoleTest` keeps them
in step.
