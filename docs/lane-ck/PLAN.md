# Lane CK (22 October 2026) — checkout text sizes, grey labels, asterisks, optional street

The owner's brief, as relayed: "very critical change, super care, without messing up
functionality". Written before any code moved; kept current as the work goes.

## 0. What ships ON, and what ships as "no change"

| Change | Default | Why |
|---|---|---|
| B. Lifted floating labels grey (`--muted` #756C74) | ON | asked for in as many words |
| C. No "*" until a required field is left empty / submit finds it empty | ON | asked |
| D. Area / Street (`billing_address_2`, list mode) not required, no "optional" | ON | asked |
| A. Every text-size role, Overall Text % / Spacing %, small-phone steps, Arabic overrides | **no change** — nothing new printed | he has not picked values; he sets them with the live preview |

## 1. Files touched (minimal)

Storefront (shopper sees):
- `resources/css/kbb/kbb-checkout.css` — lifted label grey; asterisk hidden until `.kbb-req-miss` / `:user-invalid`; role + overall factors (`calc(Npx*var(--ckt,1))`).
- `resources/css/kbb/kbb-cart.css` — cart page role + overall factors.
- `resources/css/kbb/kbb.css` — cart panel `.kc-*` role + overall factors, and the touch-device 16px floor takes the checkout's Overall factor inside its max() (no header/logo rule touched).
- `resources/views/store/cart-squeeze.blade.php` — the squeezed cart's inline px font sizes take the same factor (computed value identical at default).
- `resources/views/partials/checkout/address-fields-list.blade.php` — Area / Street loses `required` / `validate-required`.
- `resources/views/store/checkout.blade.php` — one `{!! !!}` for the small-phone style block (empty at default).
- `resources/views/partials/drawers.blade.php` — same, for the cart panel.
- `resources/js/kbb/checkout.js` — ~10 lines: `kbb-req-miss` on focusout of an empty required field, cleared on input; set on the failing submit. No request.
- `public/build/*` — rebuilt by `npx vite build` (CSS + app bundle hash).

Server:
- `app/Http/Controllers/Store/CheckoutController.php` — `billing_address_2` rule becomes `nullable` (list mode). Nothing else.
- `app/Services/CheckoutPage.php`, `app/Services/CartPage.php`, `app/Services/CartPanel.php` — new SCHEMA keys (appended), tab membership, emission only when not default, Arabic overlay, small-phone CSS, `fl_tone` (checkout).
- `app/Support/TextSizes.php` (new) — the shared Arabic-overlay / small-phone / factor maths, so three services cannot drift.
- `app/Http/Controllers/Admin/{CheckoutPage,CartPage,CartPanel}ApiController.php` — `ar` map in show/save.
- `app/Http/Controllers/Admin/ShopLivePreviewController.php` (new) + `app/Support/ShopLivePreview.php` (new) — renders the real cart / checkout / panel with a sample basket inside a rolled-back transaction.
- `routes/checkout-page-admin.php`, `routes/cart-page-admin.php` — the three `GET …/live` routes (no edit to `routes/web.php`).
- `app/Support/AdminCapabilities.php` — `GET admin-api/checkout-page/live` (checkoutpage.manage), `GET admin-api/cart-panel/live` (content.manage); cart-page/** already covered.
- `database/migrations/…_clear_caches_lane_ck_text_sizes.php` — routes added, so the compiled route/view caches go.
- `resources/views/admin/partials/{checkout-page,cart-page,cart-panel}-screen.blade.php` — EN/AR tabs over the size controls, the live preview frames.

Tests (new): `tests/Feature/LaneCkTextSizesTest.php`, `LaneCkCheckoutFieldsTest.php`, `LaneCkStreetOptionalTest.php`, `LaneCkLivePreviewTest.php`, fixture `tests/Fixtures/lane-ck-sizes-before.json`; edited (deliberate changes only): `AddressRegionsCheckoutTest` (Area / Street was pinned required), `CheckoutPageScreenTest` (the Text sizes tabs gained keys), `CheckoutTotalsCardFloatingLabelsTest` (Area / Street and the pink lifted label were pinned), `CheckoutPageSpacingTest` (new bool keys), `AdminResetConfirmTest` (one more screen asks for itself), `ModuleScreenPayloadTest` / `ModuleSchemaEquivalenceTest` (fixtures spliced: rows inserted, none modified). `NoFocusZoomTest` is NOT edited: the twin-rule approach (3b) leaves every original declaration, the 16px floor included, byte for byte. `StorefrontEnglishUnchangedTest` stays green without a pin move.
Tools: `tools/ck-factor.php` (the mechanical stylesheet conversion), `tools/ck22-*.cjs` (pictures and Chromium checks).

NOT touched: `express-wallets.blade.php`, wallet layout settings, `GatewayCountries`, Tamara date logic, any file under `app/Services/Payments/`, the header/logo files, `SettingsRequestMemo`, `SettingsService`, `Setting`, `InstantNav`, `ImageVariants`, `layouts/store.blade.php`.

## 2. Risk register

| # | Risk — how it could break the shop | Proof it did not | Roll back |
|---|---|---|---|
| 1 | Checkout submit / validation / payment (COD, Tabby, Tamara, Stripe, Apple/Google Pay): the JS change stops Place order, or the street rule lets a bad order through | payment set (Tamara*, Tabby*, Stripe*, Wallet*, Checkout*, Order*, Payment*) green at start AND end; new test: empty street places COD / Tabby / Tamara / Stripe with the suite's gateway fakes; Chromium: empty-street order placed (COD) on the preview | revert `checkout.js` hunk + rebuild; restore `required_without:billing_city` |
| 2 | Payment payloads change for a filled street | test: Tabby / Tamara request body with the street filled is byte-identical to the body built from the pre-change address array (and no gateway file changes at all) | n/a — no gateway file edited |
| 3 | Gateway refuses an empty line 2 | read: Tamara sends `line2` only when non-empty (already), Tabby joins non-empty parts, Stripe sends no address, `line1` (Building) stays required — so no fallback needed | — |
| 4 | Express wallets | file not touched; `Wallet*` set | — |
| 5 | Coupon box (floating label green stays green; apply/remove) | coupon rule keeps its own colour; `CheckoutCoupon*` tests; Chromium apply on preview | CSS revert |
| 6 | Gift wrap / gift note | tick lines only gain a size factor (1 at default); `CheckoutGift*`/`Order*` tests | CSS revert |
| 7 | Account creation (password box inside `.kbb-acct-pw`) | its asterisk logic is the same CSS; `Checkout*Account*` tests | CSS revert |
| 8 | Saved addresses / address book | `AddressController` untouched (line2 already nullable); prefill unchanged; `AddressRegions*` tests | — |
| 9 | Country / emirate change re-draw replaces rows | the asterisk class lives on the row and is re-derived on the next blur; delegated listener on the form (survives re-render) | JS revert |
| 10 | Free gifts box | only a font factor of 1 inside `.fgb` if any; `FreeGifts*` tests | CSS revert |
| 11 | Cart panel add / remove / quantity | no panel markup or JS edited; style attr only gains properties when moved; `CartPanel*`, `CartDrawer*` tests | — |
| 12 | Cart page quantity / remove | no cart markup or JS edited; `CartPage*` tests | — |
| 13 | Arabic / RTL | AR overlay only on `/ar` and only when it differs; tests: AR override reaches only `/ar`, inheriting prints nothing; Chromium AR 390/1280 pictures | AR map is one setting row per page — delete it |
| 14 | 16px iOS zoom floor | `max(16px, …)` kept, factor goes INSIDE the max; small-phone step never below 100%; test pins the floor declaration | CSS revert |
| 15 | Small phones (320–414) | factors computed server-side, `max(1, v·(1−N%))`, emitted only when a size > 100%; Chromium sweep 320/340/360/375/390/414 at +15%: scrollWidth = width, sizes step down | set N/M to 0 |
| 16 | Existing size settings change meaning | no key renamed/removed/re-defaulted; test: every existing size key still prints the same property/value; overall multiplies on top | — |
| 17 | Speed budgets | no query added on any shop page (settings map already memoised; AR map is a key in the same map); before/after ms + queries for product, category, brand, cart, checkout; `StorefrontQueryBudgetTest`, `SettingsRequestMemoTest`, `InstantNavTest` | revert |
| 18 | Header lock | the CSS converter skips every rule whose selector matches HeaderLayoutLockTest's own pattern and `.co-head`; `HeaderLayoutLockTest`, `LogoLockupTest` green | — |
| 19 | Admin console assets / screens | admin screens are Blade partials (no new dependency, no build); `AdminConsole*` tests, `CheckoutPageScreenTest`, `CartPageScreenTest`, `CartPanelScreenTest`, `CartPagePreviewTest` | revert partials |
| 20 | Live preview has side effects (a cart row, a cookie, a session write, a pixel) | render inside `DB::transaction` that is always rolled back; queued cookies removed; session restored; scripts stripped and body inert; iframe `sandbox="allow-same-origin"`; test asserts no cart row, no cart cookie and the session unchanged after a preview | delete the routes |
| 21 | Validation weakened elsewhere | test: every other required field (email, name, phone, building, emirate, country, payment method) still refused server-side, EN and AR | — |
| 22 | Shared resources between lanes | logs in `storage/ck-logs/`, `KBB_WP_DB=kbb_wp_ck`, preview on its own port, never `pkill` by pattern | — |

## 3. Order of work (smallest blast radius first)

1. Baseline: payment set, speed numbers, before pictures (done before any code moved).
2. CSS only: lifted label grey (+ `fl_tone` control, grey default); asterisk hidden by CSS, shown by `.kbb-req-miss` / `:user-invalid` / inline-validation's `woocommerce-invalid`.
3. Labels and asterisks: the checkout.js fallback (focusout / submit).
4. The street rule: view + server rule; gateway check (no fallback needed — see risk 3); tests.
5. Text sizes: schema keys, emission, CSS factors, Arabic overlay, small-phone CSS.
6. Admin: EN/AR tabs, live preview (real pages, sample basket, rolled back).
7. Full suite, payment set again, pictures, numbers, commit.

## 3b. Decisions taken while building (kept current)

- **Spacing % is vertical only** (padding/margin top and bottom, row gaps). Horizontal padding is also what keeps field text clear of its icon and in line with its floating label (`inset-inline-start`), which are positions; scaling one and not the other walks the text off its label. Widths and side offsets never move.
- **Icons are excluded** from Overall Text % (Lane TS's "x" group: `.cth`, `.co-rm`, `.kc-x`, `.kc-rm`, `.kc-th`, `.pay-logo`, `.kc-wt`) — they have their own size controls.
- **clamp()/vw fit-to-width texts are left alone** (the phone coupon strip and coupon ticket): wrapping them in a var() factor is the "newer function next to var()" landmine.
- **Twin rules, not rewrites.** Rewriting every size in place moved 37 existing pins. Instead each original rule stays byte for byte, and a same-selector rule immediately after it repeats only the converted sizes (`tools/ck-factor.php --twin`). At the defaults every factor is 1, so the twin computes to the original; Chromium measured 0 size/spacing differences (same-at-defaults). Cost: kbb-checkout.css +3.3 KB gzip, kbb-cart.css +0.8 KB, kbb.css +0.9 KB.
- **The 16px floor:** the original `max(16px, …)` declarations stay; their twins put the factor INSIDE the max, in kbb-checkout.css and in kbb.css's touch-device floor, so Overall can raise field text and never lower it below 16px.
- **Small phones are computed on the server**, in integer thousandths (`App\Support\TextSizes`), and printed as one `<style>` with `!important` custom properties inside `@media (max-width:374px)` / `(max-width:340px)` — only when a phone size is above today. The admin preview does the same sums in the same integer form, so they cannot round apart.
- **The live preview is the real page, no script:** `ShopLivePreview` renders the shop's controller and views on a 3-product basket inside a rolled-back transaction, strips `<script>`, marks `<body inert>`; the admin shows it as `srcdoc` in `sandbox="allow-same-origin"` frames, fetched once per language. (A `Content-Security-Policy: sandbox` header was the first idea; SecurityCspTest forbids the enforcing header name anywhere in app code, so the HTML itself carries no script instead.)
- **The "*" on submit** is marked from a capture listener on `window`, because the placing overlay's capture listener on `document` stops the press before checkout.js's bubble handler. It only adds/removes a class.
- **No gateway fallback for an empty street:** line 1 (Building) stays required and is what Tamara gets as `line1`; Tamara sends `line2` only when present; Tabby joins non-empty parts; Stripe sends no address. Filled-street payloads are byte-identical (test).

## 4. Open points (to report, not to guess)

- "Delivery notes (optional)" keeps its word: it is a different field and the brief says not to change other fields.
- Lane TS's "Overall" scope is the checkout FORM, the cart page and the panel; the checkout's own secure header (`.co-head`) is outside it (header frozen).

## 5. Results (final)

- Payment set (Tamara*, Tabby*, Stripe*, Wallet*, Checkout*, Order*, Payment*): start 1578 tests, 1566 passed, 12 skipped; end the same, twice (after each commit).
- Full suite: 12691 tests, 12624 passed, 65 skipped, 2 failed -- both fixed and green on rerun: DownloadNavigationGateTest (the 404 guard is now spelled `code === 404`) and CheckoutPlaceOrderOnceTest (query count 38 vs 40 between two Place orders in one test, once; green alone and in both payment-set runs).
- Built CSS/JS, gzip: kbb-checkout.css +3,281 B, kbb-cart.css +773 B, kbb.css +875 B, app.js +160 B.
- HTML at defaults, same DB: product/category/brand/cart identical; checkout -127 B (the hidden-until-needed markers).
- Server ms (median; base code vs this branch, same DB copy, alternated): product 40.0-43.6 vs 42.2-43.9; category 32.8-34.0 vs 33.7-33.8; brand 31.5-35.1 vs 34.0-34.4; cart 27.4-29.6 vs 30.4-30.5; checkout 43.8-45.4 vs 46.8-48.7. Queries identical (16/10/10/9/18). Run-to-run spread on one tree is about 3 ms.

## 6. Lane CK2 (23 October): the same features at zero speed cost

The owner on 2.60.571: "I don't want to compromise on speed at all." Every feature and control stays; the cost goes.

- **Stylesheets back to 7a8c4803.** The twin rules are out of kbb.css, kbb-cart.css, kbb-checkout.css and the cart page's style blocks. kbb.css, kbb-cart.css, app.js and every other built file are byte-identical to 7a8c4803; kbb-checkout.css is +103 B raw / +25 B gzip: `display:none` on the star, one rule that shows it, and the lifted label's `--pink-deep` -> `--muted` (docs/lane-ck2/build-bytes.txt).
- **Text sizes are an on-demand inline block.** `tools/ck2-twins.php` reads Lane CK's twins out of commit 0e27fb0f ONCE, with each twin's roles measured in Chromium (`tools/ck2-roles.cjs`), into `App\Support\TextSizeTwins` (a constant). `App\Support\TextSizes::css()` builds, from that table and constant role/selector specs on the three services, one `<style>` holding only what moved: per-device factors, the containers of moved roles, the twins of those roles (all text twins for Overall · Text %, all spacing twins for Spacing %), Arabic values (on /ar only), small-phone steps (media queries), and re-assertions of later stylesheet rules so the block wins exactly where the twins won in place. Built at Save (`refreshTypeCss()` in every save/saveAr), stored in one settings row per surface, read from the memoised map; at the defaults nothing is stored, nothing printed, nothing computed. Chromium: identical computed sizes to Lane CK's build in 6 scenarios x EN/AR x 330-1280 (docs/lane-ck2/same-as-lane-ck.json).
- **The "*" is CSS only** (`:is(.form-row:has(:user-invalid),.woocommerce-invalid) .required`); the checkout.js addition is gone. Chromium marks a field user-invalid after it is typed in and cleared, or after a real form submission -- NOT after a bare visit, and not after Place order (which calls reportValidity(), not a submit). Those two cases showed the star under Lane CK's script and do not now; the owner decides whether the ~10 lines of script come back (+160 B gzip app.js).
- **The pink lifted label** ("The shop's pink") is printed inline only when chosen; the grey ships in the sheet.
- The admin previews build the same block in JS (`typeCss()` in shop-type-live) from the same spec and table; LaneCkTextSizesTest runs it in node against the PHP and holds them equal, and Chromium found preview = shop byte for byte after Save on all three screens, EN and AR (docs/lane-ck2/admin-facts.json).
