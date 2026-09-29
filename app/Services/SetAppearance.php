<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Color;

/**
 * Appearance → Set — every control the three set surfaces have.      (Lane SA)
 *
 * The owner, verbatim:
 *
 *   "I need the full controls of everything like spacing, fonts, elements turn
 *    on off etc etc. every single details. for mobile and desktop both separate
 *    tabs. Under Appearance → Set → desktop / mobile."
 *
 * and, the same week, the decision that says WHAT is being controlled:
 *
 *   "The fanned stack — chosen 28 September, with a tiny popup for what's
 *    inside is fine, please proceed with that."
 *
 * ── THE THREE SURFACES, AND WHY THEY ARE ONE SCREEN ─────────────────────────
 *
 *   THE BOX     resources/views/partials/set-row.blade.php — the fanned stack
 *               of circular member thumbnails with the "What's inside" popup
 *               beside it. Class prefix `kset`. Included by every surface a set
 *               is bought on: the cart drawer, the cart page, the checkout
 *               summary, the browsed rail, an order's detail page and the
 *               order-received line.
 *
 *   THE LIST    resources/views/partials/set-contents-panel.blade.php and its
 *               row — "What is in this set" in the buy column of a set's
 *               product page. Photo, brand, name, option, quantity; a <details>
 *               fold past six; a footing reading Bought separately · Set price
 *               · You save. Class prefix `ksl`. ONE include site, checked
 *               rather than assumed: resources/views/store/product.blade.php.
 *
 *   THE CART
 *   PAGE ROW    the padding and the gaps of `.kbb-cartpage .ci` itself, which
 *               are hard-coded in resources/css/kbb/kbb-cart.css while the
 *               checkout's equivalent already runs on tokens. That asymmetry is
 *               why the owner asked for this control on the cart page by name:
 *               *"give control for set rows too on backend for cart page."*
 *
 * They are one screen because they are one THING to the owner — "the set" — and
 * because the controls that are genuinely shared (what colour a saving is,
 * whether an option line is drawn) would otherwise be in two places and get
 * different answers.
 *
 * ── DESKTOP AND MOBILE ARE INDEPENDENT, NOT INHERITED. THE ARGUMENT ─────────
 *
 * Every dimensional control has its own value per breakpoint, `x` and `x_m`,
 * and the mobile one NEVER falls back to the desktop one. Inheritance — mobile
 * follows desktop until it is touched — is kinder to explain and is the wrong
 * answer here, for one decisive reason: THE SHIPPED SHEET ALREADY DIFFERS
 * BETWEEN THE TWO. `.kset-save` multiplies its base font by .82 on a laptop and
 * by .85 on a phone, and the base font itself is a different token on each side
 * (`--cp-name` and `--cp-name-m`). Under inheritance that field would have to
 * ship ALREADY TOUCHED to reproduce today's page, so the first thing the owner
 * would meet is a screen claiming to inherit while a field visibly does not.
 * Inheritance would be a lie on the day it shipped.
 *
 * Independent values are also the shape this console already speaks:
 * App\Services\CartPanel emits `--cp-x` and `--cp-x-m` side by side for exactly
 * this reason, and its docblock is the note that got this right.
 *
 * NON-DIMENSIONAL CONTROLS ARE SHARED AND APPEAR ONCE. A colour, a font weight
 * and an on/off switch are not measurements, and a second copy of "show the
 * quantities" per breakpoint is one control with two halves that can disagree
 * without anybody meaning them to. They live on the Desktop tab, which says so,
 * and the Mobile tab says where they are.
 *
 * ── THREE BREAKPOINTS, AND THEY ARE MEANT TO DIFFER ────────────────────────
 *
 * 600 for the cart page's rows, 760 for the set box, 480 for the buy column's
 * list. They are the widths the three stylesheets ALREADY turn over at, and
 * each is about a different thing collapsing: the cart page's two columns, the
 * checkout summary starting to clip its own contents (`.kbb-checkout .panels`
 * is `max-height:148px;overflow:hidden` below 760, which is where the popup has
 * to stop being an absolutely-positioned box inside a clipping ancestor and pin
 * itself to the viewport), and a buy column that is narrow at every width.
 *
 * Collapsing them to one number would change what the shop renders at every
 * width in between, which is the one thing a new setting may not do. All three
 * are settings, all three ship at the number the sheet carries, and they are
 * the first card on the Mobile tab — because "mobile" means nothing until it is
 * a number, and on this screen it means three of them.
 *
 * ── FONTS: SIZES AND WEIGHTS, NEVER A FAMILY ────────────────────────────────
 *
 * Every text element here gets a size and a weight. NONE of them gets a
 * font-family box. This shop has one typographic system — `--sans` is Poppins
 * with a system stack behind it, declared once in resources/css/kbb/kbb.css and
 * inherited by everything — and a free-text family on one screen is a way to
 * put an unloaded face in a cart drawer and nowhere else. A family picker is a
 * SITE-WIDE typography feature: one list of families the shop actually loads,
 * one place that loads them, applied everywhere at once. It is not this screen
 * and this screen must not pretend to be it.
 *
 * THE THREE TYPE SIZES AND THE CIRCLE ARE PERCENTAGES, NOT PIXELS, because that
 * is how the box is sized today: `calc(var(--cp-thumb,42px) * .62)` and
 * `calc(var(--cp-name,12.5px) * .82)`. The multiplier is what makes the fan
 * follow Appearance → Cart panel instead of being left behind the first time
 * the owner moves that thumbnail. Turning them into pixel counts here would
 * have quietly cut that link, which is a change disguised as a control.
 *
 * ── RULE 1: EVERY DEFAULT IS WHAT THE SHEET ALREADY SAYS, AND THE BLOCK IS
 *    NOT EMITTED AT ALL UNTIL ONE OF THEM MOVES ────────────────────────────
 *
 * storefrontCss() answers the EMPTY STRING while every value is at its shipped
 * default, exactly as App\Services\SiteLayout::css() does and for the same
 * reason: restating the defaults would be correct in pixels and wrong in bytes
 * — a new <style> element in the head of every storefront page at once, which
 * is what StorefrontEnglishUnchangedTest exists to notice, for a render that is
 * identical. So a shop that applies this package and touches nothing gains not
 * one byte on any page, and the first slider he moves is what brings the block
 * into existence.
 *
 * Where a value was a theme token (`var(--cp-accent,#c9587f)`) the colour field
 * ships EMPTY and the emitted CSS keeps the token as the fallback. A hex
 * default would have frozen a themeable colour on the day this shipped.
 *
 * ── HOW A VALUE REACHES THE SHOP, WHICH IS THE ONE THING TO GET RIGHT ───────
 *
 * App\Services\ProductStyles is the cautionary tale this project already paid
 * for: twenty of its controls reached no storefront page for releases because
 * cssVariables() was called only from the admin. So, said plainly and in one
 * place:
 *
 *   THE ONE STOREFRONT EMISSION IS resources/views/partials/set-appearance-
 *   css.blade.php, included ONCE by resources/views/layouts/store.blade.php,
 *   in the <head>, after @stack('styles') and the accent and layout blocks.
 *
 * There is no second caller and no admin-only path.
 * tests/Feature/SetAppearanceStorefrontTest.php renders a real cart with a real
 * set in it and reads the properties back off the page, then moves a setting
 * and reads them again.
 *
 * ── IT DECLARES VARIABLES, NOT PROPERTIES, AND THAT IS THE WHOLE TRICK ─────
 *
 * The two set partials were rewritten so that every tunable number is
 * `var(--kset-x, <the literal it has always been>)` and NOTHING declares those
 * properties anywhere. So this class emits ONE declaration block per surface —
 * `.kset.kset{--kset-…}` and `.ksl.ksl{--ksl-…}` — plus a media query with the
 * owner's own breakpoint, and the cascade does the rest. There is no property
 * to re-specify, no !important, and no rule that has to be kept in step with a
 * partial's own.
 *
 * The exceptions are the three things a custom property CANNOT do, and each is
 * a rule rather than a variable: `display:none` for the parts the owner has
 * switched off, `nth-child` for the fan's cap, and the CART PAGE'S OWN ROW
 * PADDING — which lives in resources/css/kbb/kbb-cart.css, a compiled Vite
 * stylesheet this package does not rebuild, so those four numbers are
 * re-declared here at `.kbb-cartpage .items .ci.ci` (0,4,0) against the sheet's
 * `.kbb-cartpage .ci` (0,2,0) and win on specificity at every width.
 *
 * ▲ AND IT IS EMITTED IN THE HEAD, WHICH IS LOAD-BEARING. The partial's phone
 *   block carries `.kbb-checkout .kset-pop.is-open{position:fixed;…}` at
 *   (0,3,0) — the rule that lets the popup escape the checkout summary's
 *   `overflow:hidden` on a phone. The popup-width override below is also
 *   (0,3,0), so the two are decided by ORDER, and the head is before the body.
 *   Emitted after the partial instead, a width cap would have won over
 *   `max-width:none` and sliced the popup back to one line on the one screen
 *   that was already fixed for it once.
 *
 * ── AND IT COSTS NO QUERY ───────────────────────────────────────────────────
 *
 * Every value is a row of `settings`, read through SettingsService::get(),
 * which reads the one App\Models\Setting::map() snapshot the header, the cart
 * panel and the footer have already warmed. all() is called ONCE per page by
 * the partial and never per set or per member, so StorefrontQueryBudgetTest
 * does not move.
 */
class SetAppearance
{
    /** Every key is stored as `settings.key` = PREFIX . <schema key>. */
    public const PREFIX = 'setap_';

    /**
     * key => [type, label, default, help, options]
     *
     * A trailing `_m` is the phone's own value for the same measurement. Every
     * default below was copied off resources/views/partials/set-row.blade.php
     * declaration by declaration, and SetAppearanceDefaultsTest asserts each one
     * against the literal it came from.
     */
    public const SCHEMA = [

        /* ── WHAT IS DRAWN. Shared by both screens. ──────────────────────── */
        'on' => ['bool', 'Show the set box', true,
            'The fanned circles and the “What’s inside” button under a set’s name — in the cart drawer, on the cart page, in the checkout summary, in the browsed rail, on an order’s detail page and in the buy column of a set’s own product page. Off makes a set look like an ordinary product everywhere it is bought.'],
        'fan_on' => ['bool', 'Show the member circles', true,
            'The fanned stack itself. Each circle carries one member’s picture and nothing else — no initials, no quantity badge, no count in the corner, which is what the owner asked for on 28 September.'],
        'fan_max' => ['range', 'Most circles in the fan', 0,
            'Zero draws one circle per member, which is what the box does today. Set a number and the fan stops there. The popup still lists EVERY member whatever this says, because it reads the set’s own contents and never what happens to be on screen.',
            ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => '']],
        'btn_on' => ['bool', 'Show the “What’s inside” button', true,
            'The button and the tiny popup it opens. Off removes both — there is no popup without something to open it.'],
        'head_on' => ['bool', 'Popup: show the member count line', true,
            'The small uppercase “3 products” heading at the top of the popup.'],
        'qty_on' => ['bool', 'Popup: show quantities', true,
            'The bold “2×” before each member’s name.'],
        'save_on' => ['bool', 'Show the saving', true,
            'The green “You save …” at the end of the row. A set that saves nothing has never printed it and still will not.'],

        /* ── SPACING AND SIZE, laptop. ───────────────────────────────────── */
        /*
         * ▲ THE TWO DEFAULTS IN THIS WHOLE SCHEMA THAT ARE NOT WHAT THE PAGE
         *   DREW YESTERDAY. CLAUDE.md rule 1's stated exception — a default the
         *   owner asked for in as many words — plus the measurement that came
         *   with it. The owner, with a screenshot of the cart page and a red
         *   arrow at the set line:
         *
         *     "the only this i need is the row spacing i need little bit up
         *      spacing or give control for set rows too on backend for cart
         *      page."
         *
         *     margin-top     6px -> 10px   what he asked for
         *     margin-bottom  0   ->  6px   measured, not taste
         *
         *   Measured in Chromium on the cart page BEFORE the change: the set
         *   block had 6px above it and NOTHING below it — the fan sat flush
         *   against the quantity stepper, while every other pair of stacked
         *   things in that row had at least 6px. And there is no collision
         *   anywhere: the gap from the divider above to the top of the product
         *   name was 27px at 390 and 28px at 1280 on a SET row and the same two
         *   numbers on the plain rows either side, so what reads as a crossed
         *   name in the screenshot is a block with room above and none below.
         *   Moving only the top would have made that worse.
         *
         *   The same two numbers are in the fallbacks in set-row.blade.php, so
         *   the storefront emits no stylesheet at all until one of them moves.
         */
        'top' => ['range', 'Space above the set box', 10,
            'Between the product name and the circles. It was 6px until this release; the owner asked for “little bit up spacing” and this is it.',
            ['min' => 0, 'max' => 32, 'step' => 1, 'unit' => 'px']],
        'bot' => ['range', 'Space below the set box', 6,
            'Between the circles and whatever is under them — on the cart page and in the cart drawer that is the quantity stepper, which the fan used to sit flush against. It was 0px until this release.',
            ['min' => 0, 'max' => 32, 'step' => 1, 'unit' => 'px']],
        'gap' => ['range', 'Space between the fan, the button and the saving', 8, '',
            ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'circle' => ['range', 'Circle size', 62,
            'A percentage of the cart row’s own thumbnail rather than a pixel count, which is how the box is sized today — so the fan follows Appearance → Cart panel instead of being left behind when that thumbnail moves. The cart thumbnail is 42px, so 62% is a 26px circle.',
            ['min' => 20, 'max' => 160, 'step' => 1, 'unit' => '%']],
        'overlap' => ['range', 'How far each circle sits over the last', 38,
            'A percentage of the circle. Zero lays them in a row with no overlap; 50 hides half of each.',
            ['min' => 0, 'max' => 70, 'step' => 1, 'unit' => '%']],
        'ring' => ['range', 'Ring around each circle', 2,
            'The band that separates one circle from the one beneath it.',
            ['min' => 0, 'max' => 8, 'step' => 1, 'unit' => 'px']],
        'btn_f' => ['range', '“What’s inside” text size', 82,
            'A percentage of the cart row’s own name size, for the same reason the circle is a percentage.',
            ['min' => 50, 'max' => 170, 'step' => 1, 'unit' => '%']],
        'btn_px' => ['range', '“What’s inside” padding, left and right', 9, '',
            ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'btn_py' => ['range', '“What’s inside” padding, top and bottom', 3, '',
            ['min' => 0, 'max' => 20, 'step' => 1, 'unit' => 'px']],
        'btn_h' => ['range', '“What’s inside” minimum height', 24,
            'A tap target has a floor whatever the type does.',
            ['min' => 0, 'max' => 56, 'step' => 1, 'unit' => 'px']],
        'btn_r' => ['range', '“What’s inside” corner radius', 99,
            '99 is a pill at any height. Bring it down for a rounded rectangle.',
            ['min' => 0, 'max' => 99, 'step' => 1, 'unit' => 'px']],
        'pop_w' => ['range', 'Popup width', 230,
            'A CEILING, not a width. The popup is never wider than the screen less its gutters whatever this says, which is what stops it widening a 390px page — and it is only ever as wide as its longest line.',
            ['min' => 140, 'max' => 420, 'step' => 2, 'unit' => 'px']],
        'pop_pad_x' => ['range', 'Popup padding, left and right', 10, '',
            ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'pop_pad_y' => ['range', 'Popup padding, top and bottom', 8, '',
            ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'pop_r' => ['range', 'Popup corner radius', 10, '',
            ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'pop_off' => ['range', 'Popup distance from the button', 6, '',
            ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'pop_sh_y' => ['range', 'Popup shadow drop', 8, '',
            ['min' => 0, 'max' => 32, 'step' => 1, 'unit' => 'px']],
        'pop_sh_blur' => ['range', 'Popup shadow softness', 24,
            'The shadow is pulled in by a third of this figure, so it stays under the box instead of leaking out at the sides however soft it is set.',
            ['min' => 0, 'max' => 64, 'step' => 1, 'unit' => 'px']],
        'pop_sh_a' => ['range', 'Popup shadow strength', 28,
            'Zero is no shadow at all.',
            ['min' => 0, 'max' => 100, 'step' => 1, 'unit' => '%']],
        'head_f' => ['range', 'Popup heading size', 78,
            'A percentage of the cart row’s name size. This one keeps the LAPTOP’s base on both screens, because that is what the sheet does today — the phone block never restated it.',
            ['min' => 50, 'max' => 170, 'step' => 1, 'unit' => '%']],
        'head_gap' => ['range', 'Space under the popup heading', 4, '',
            ['min' => 0, 'max' => 20, 'step' => 1, 'unit' => 'px']],
        'li_f' => ['range', 'Popup line size', 88,
            'A percentage of the cart row’s name size.',
            ['min' => 50, 'max' => 170, 'step' => 1, 'unit' => '%']],
        'li_gap' => ['range', 'Popup space between lines', 2, '',
            ['min' => 0, 'max' => 16, 'step' => 1, 'unit' => 'px']],
        'qty_f' => ['range', 'Popup quantity size', 100,
            'A percentage of the popup line beside it. 100 is what it draws today — the quantity has never had a size of its own and simply took the line’s.',
            ['min' => 50, 'max' => 170, 'step' => 1, 'unit' => '%']],
        'save_f' => ['range', 'Saving size', 82,
            'A percentage of the cart row’s name size.',
            ['min' => 50, 'max' => 170, 'step' => 1, 'unit' => '%']],

        /* ── WEIGHT, LETTER-SPACING AND COLOUR. Shared by both screens. ──── */
        'btn_w' => ['range', '“What’s inside” weight', 650, '',
            ['min' => 300, 'max' => 900, 'step' => 10, 'unit' => '']],
        'head_w' => ['range', 'Popup heading weight', 700, '',
            ['min' => 300, 'max' => 900, 'step' => 10, 'unit' => '']],
        'head_ls' => ['range', 'Popup heading letter-spacing', 3,
            'In hundredths of an em, which is how the sheet writes it: 3 is .03em.',
            ['min' => 0, 'max' => 20, 'step' => 1, 'unit' => '/100 em']],
        'li_w' => ['range', 'Popup line weight', 400, '',
            ['min' => 300, 'max' => 900, 'step' => 10, 'unit' => '']],
        'li_lh' => ['range', 'Popup line height', 145,
            'In hundredths: 145 is a line-height of 1.45.',
            ['min' => 90, 'max' => 220, 'step' => 5, 'unit' => '/100']],
        'qty_w' => ['range', 'Popup quantity weight', 700, '',
            ['min' => 300, 'max' => 900, 'step' => 10, 'unit' => '']],
        'save_w' => ['range', 'Saving weight', 700, '',
            ['min' => 300, 'max' => 900, 'step' => 10, 'unit' => '']],
        'ring_c' => ['colour', 'Ring colour', '#FFFFFF',
            'The band between one circle and the next. It usually wants to match whatever is behind the row, which on every surface the box appears on is white.'],
        'btn_c' => ['colour', '“What’s inside” text colour', '',
            'Leave it empty and the button keeps the cart panel’s own accent, so it changes colour with Appearance → Cart panel instead of drifting away from it.'],
        'btn_bg' => ['colour', '“What’s inside” background', '#FFFFFF', ''],
        'btn_hover_bg' => ['colour', '“What’s inside” background under the pointer', '#FFF4F8',
            'Only ever seen on a device with a pointer; a phone goes straight from untouched to pressed.'],
        'btn_line_c' => ['colour', '“What’s inside” border colour', '',
            'Empty keeps the theme’s soft hairline.'],
        'pop_bg' => ['colour', 'Popup background', '#FFFFFF', ''],
        'pop_line_c' => ['colour', 'Popup border colour', '',
            'Empty keeps the theme’s soft hairline, the same one the button uses.'],
        'head_c' => ['colour', 'Popup heading colour', '',
            'Empty keeps the theme’s soft ink.'],
        'li_c' => ['colour', 'Popup line colour', '',
            'Empty keeps the theme’s secondary ink.'],
        'qty_c' => ['colour', 'Popup quantity colour', '',
            'Empty keeps the theme’s ink.'],
        'save_c' => ['colour', 'Saving colour', '#1C7A4A',
            'The one colour this box writes itself rather than taking from the theme, so it ships as the green the page already draws.'],

        /* ── THE PHONE. ──────────────────────────────────────────────────── */
        'bp' => ['range', 'The set box switches to its phone sizes below', 760,
            'The width the sheet already turns over at, and it is not a round number by accident: below it the checkout’s summary panel clips its own contents, which is where the popup has to pin itself to the screen instead of hanging off the button. Moving this away from 760 leaves a band of widths where the popup escapes that clip at one size and the words change at another.',
            ['min' => 320, 'max' => 1200, 'step' => 10, 'unit' => 'px']],
        'top_m' => ['range', 'Space above the set box', 10,
            'The phone’s own. It moved with the laptop’s — see the note on the Desktop control, which carries the measurement.',
            ['min' => 0, 'max' => 32, 'step' => 1, 'unit' => 'px']],
        'bot_m' => ['range', 'Space below the set box', 6, '',
            ['min' => 0, 'max' => 32, 'step' => 1, 'unit' => 'px']],
        'gap_m' => ['range', 'Space between the fan, the button and the saving', 8, '',
            ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'circle_m' => ['range', 'Circle size', 62,
            'A percentage of the cart row’s thumbnail. ▲ Of the LAPTOP token, on purpose: the sheet’s phone block has never restated the circle, so it has always been sized off `--cp-thumb` at every width, and pointing it at the phone token here would have moved the fan on every phone in the shop.',
            ['min' => 20, 'max' => 160, 'step' => 1, 'unit' => '%']],
        'overlap_m' => ['range', 'How far each circle sits over the last', 38, '',
            ['min' => 0, 'max' => 70, 'step' => 1, 'unit' => '%']],
        'ring_m' => ['range', 'Ring around each circle', 2, '',
            ['min' => 0, 'max' => 8, 'step' => 1, 'unit' => 'px']],
        'btn_f_m' => ['range', '“What’s inside” text size', 82,
            'A percentage of the cart row’s PHONE name size — the sheet switches base here, and this control keeps that.',
            ['min' => 50, 'max' => 170, 'step' => 1, 'unit' => '%']],
        'btn_px_m' => ['range', '“What’s inside” padding, left and right', 9, '',
            ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'btn_py_m' => ['range', '“What’s inside” padding, top and bottom', 3, '',
            ['min' => 0, 'max' => 20, 'step' => 1, 'unit' => 'px']],
        'btn_h_m' => ['range', '“What’s inside” minimum height', 24, '',
            ['min' => 0, 'max' => 56, 'step' => 1, 'unit' => 'px']],
        'btn_r_m' => ['range', '“What’s inside” corner radius', 99, '',
            ['min' => 0, 'max' => 99, 'step' => 1, 'unit' => 'px']],
        'pop_w_m' => ['range', 'Popup width', 230,
            'Still a ceiling, and still below the screen’s own width less its gutters. ▲ On the CHECKOUT at this width the popup ignores it entirely and pins itself to the bottom of the screen — that is the rule that gets it out of the summary panel’s clip, and this control deliberately does not fight it.',
            ['min' => 140, 'max' => 420, 'step' => 2, 'unit' => 'px']],
        'pop_pad_x_m' => ['range', 'Popup padding, left and right', 10, '',
            ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'pop_pad_y_m' => ['range', 'Popup padding, top and bottom', 8, '',
            ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'pop_r_m' => ['range', 'Popup corner radius', 10, '',
            ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'pop_off_m' => ['range', 'Popup distance from the button', 6, '',
            ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'pop_sh_y_m' => ['range', 'Popup shadow drop', 8, '',
            ['min' => 0, 'max' => 32, 'step' => 1, 'unit' => 'px']],
        'pop_sh_blur_m' => ['range', 'Popup shadow softness', 24, '',
            ['min' => 0, 'max' => 64, 'step' => 1, 'unit' => 'px']],
        'pop_sh_a_m' => ['range', 'Popup shadow strength', 28, '',
            ['min' => 0, 'max' => 100, 'step' => 1, 'unit' => '%']],
        'head_f_m' => ['range', 'Popup heading size', 78,
            'A percentage of the cart row’s LAPTOP name size even here — see the laptop control. The sheet’s phone block restates the button, the line and the saving and has never restated the heading.',
            ['min' => 50, 'max' => 170, 'step' => 1, 'unit' => '%']],
        'head_gap_m' => ['range', 'Space under the popup heading', 4, '',
            ['min' => 0, 'max' => 20, 'step' => 1, 'unit' => 'px']],
        'li_f_m' => ['range', 'Popup line size', 88,
            'A percentage of the cart row’s phone name size.',
            ['min' => 50, 'max' => 170, 'step' => 1, 'unit' => '%']],
        'li_gap_m' => ['range', 'Popup space between lines', 2, '',
            ['min' => 0, 'max' => 16, 'step' => 1, 'unit' => 'px']],
        'qty_f_m' => ['range', 'Popup quantity size', 100, '',
            ['min' => 50, 'max' => 170, 'step' => 1, 'unit' => '%']],
        'save_f_m' => ['range', 'Saving size', 85,
            '▲ 85 and not 82. The phone’s saving really is a shade larger than the laptop’s in the sheet this ships from, and copying the laptop’s number across would have been a change nobody asked for. It is also the single clearest reason this screen gives the two screens independent values rather than making the phone inherit.',
            ['min' => 50, 'max' => 170, 'step' => 1, 'unit' => '%']],

        /* ═══════ THE LIST — "What is in this set", on the product page ═════ */

        /* ── what is drawn. Shared by both screens. ──────────────────────── */
        'p_on' => ['bool', 'Show the “What is in this set” list', true,
            'The member list in the buy column of a set’s product page, between the price and the Add to cart button. Off removes the whole block — the list, the fold and the footing — and the page does not even work out what is in the set.'],
        'p_heading_on' => ['bool', 'Show the heading line', true,
            'The “What is in this set” label and the member count beside it, in the slot the quantity-bundle strip used to occupy.'],
        'p_photo_on' => ['bool', 'Show each member’s photograph', true,
            'Off drops the picture column entirely and the words start at the edge of the column. The row’s height follows the photograph, so this shortens every row as well.'],
        'p_brand_on' => ['bool', 'Show each member’s brand', true,
            'The small uppercase line above the product name.'],
        'p_var_on' => ['bool', 'Show each member’s option', true,
            'The shade, size or variant under the name. A member with no option never drew one anyway.'],
        'p_qty_on' => ['bool', 'Show each member’s quantity', true,
            'The “2×” at the end of the row. Off leaves nothing to say a set holds two of something, so think twice.'],
        'p_link_on' => ['bool', 'Link each member to its own page', true,
            'On is what the list does today. It can only ever narrow: an unpublished member is plain text either way, so switching this on can never produce a link to a page that 404s.'],
        'p_underline_on' => ['bool', 'Underline the linked names', true,
            'The hairline under a member’s name that shows it is a link.'],
        'p_rule_on' => ['bool', 'Hairline between rows', false,
            'The thin line separating one member from the next, and above “Show all”. ▲ IT SHIPS OFF, because the box the owner chose on 29 September — “hanging photos” — draws none: the blush panel is what groups the rows, and a rule inside it reads as a second grouping of the same thing. It was a hard-coded `border-block-start:0` in the partial until this release, which is to say the control existed and did nothing. Rule 1: the shipped value is what the page draws.'],
        'p_fold_on' => ['bool', 'Fold a long list behind “Show all”', true,
            'A long list sits between the price and the Add to cart button, so a set with more members than the number below shows the first few and folds the rest into the browser’s own <details>. No JavaScript, and find-in-page still reaches the folded rows.'],
        'p_fold_at' => ['range', 'Show this many before folding', 5,
            'The fold only applies when the set has MORE than one past this number — folding a single row costs a click and saves nothing.',
            ['min' => 2, 'max' => 20, 'step' => 1, 'unit' => ' rows']],
        'p_panel_on' => ['bool', 'Draw the list inside a panel', true,
            'The “hanging photos” box the owner chose on 29 September: a blush fill, a radius, inner padding, and the photographs hanging past its inline-start edge on a white ring. OFF returns the bare list this replaced — no fill, no radius, no padding, no hang — which is still the drawing every OTHER control on this screen was written against, so nothing else here stops working.'],
        'p_count_on' => ['bool', 'Heading: show the item count', true,
            'The “4 items” at the far end of the “What is in this set” line. Off leaves the label alone.'],
        'p_footrule_on' => ['bool', 'Footing: the rule above it', true,
            'The hairline between the last member and the three figures. It has its own colour and its own switch because it is the one rule the panel keeps — it separates the list from the sum of the list, which is a different job from separating one member from the next.'],
        'p_foot_on' => ['bool', 'Show the footing', true,
            'Bought separately · Set price · You save, under the list.'],
        'p_was_on' => ['bool', 'Footing: “Bought separately”', true,
            'The struck-through total of the members bought one at a time — the figure the saving is measured from.'],
        'p_price_on' => ['bool', 'Footing: “Set price”', true, 'What the set itself costs.'],
        'p_save_on' => ['bool', 'Footing: “You save”', true,
            'The difference, in green. A set that saves nothing has never printed this line and still will not.'],

        /* ── the list’s spacing and size, laptop ─────────────────────────── */
        /*
         * THE PANEL'S FOUR PADDINGS ARE FOUR CONTROLS AND NOT A SHORTHAND, and
         * the inline-start one is not interchangeable with the other three.
         * `padding-inline-start` is the words' inset AND the number the
         * photographs and the footing are pulled back against — one value read
         * three times, which is why it is emitted once and never restated.
         */
        'p_panel_r' => ['range', 'Panel corner radius', 18, '',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'p_panel_pt' => ['range', 'Panel padding, top', 12, '',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'p_panel_pe' => ['range', 'Panel padding, trailing edge', 14,
            'The right-hand side in English and the LEFT-hand side on /ar — it is a logical property, so it mirrors with the page.',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'p_panel_pb' => ['range', 'Panel padding, bottom', 11, '',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'p_panel_ps' => ['range', 'Panel padding, leading edge', 20,
            'The words’ inset. The photographs hang past THIS edge and the footing is pulled back to it, so it is read three times and set once — which is what keeps the drawing consistent however it is moved.',
            ['min' => 0, 'max' => 48, 'step' => 1, 'unit' => 'px']],
        'p_over' => ['range', 'How far the photographs hang past the panel', 10,
            '▲ THIS IS THE OVERHANG AND NOT THE PULL, on purpose. The chip’s pull is worked out as “panel padding + this”, so the chip can never end up level with the panel’s edge (a gutter) or inside it (an indent) whatever the padding is set to — which is what the owner would otherwise be one drag away from doing to the design he chose. The floor is 1px for the same reason: zero IS flush.',
            ['min' => 1, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'p_ring' => ['range', 'Ring around each photograph', 30,
            'IN TENTHS OF A PIXEL: 30 is 3px. The phone’s is 2.5px, which is why this is not a whole-pixel slider. It is a spread shadow and not a border — a border would grow the square and push the words along.',
            ['min' => 0, 'max' => 80, 'step' => 5, 'unit' => '/10 px']],
        'p_sh_y' => ['range', 'Photograph shadow drop', 2, '',
            ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'p_sh_blur' => ['range', 'Photograph shadow softness', 6, '',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'p_head_f' => ['range', 'Heading size', 125, 'In tenths of a pixel. 125 is 12.5px.',
            ['min' => 90, 'max' => 220, 'step' => 5, 'unit' => '/10 px']],
        'p_head_gap' => ['range', 'Space under the heading', 7, '',
            ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'p_name_lh' => ['range', 'Name line height', 130,
            'In hundredths: 130 is 1.3. The phone ships at 124 — the leading is what buys height on a two-line name there, and it is the only thing that does once the photograph is already shorter than the words.',
            ['min' => 90, 'max' => 220, 'step' => 2, 'unit' => '/100']],
        'p_brand_lh' => ['range', 'Brand line height', 130, 'In hundredths. The phone ships at 115.',
            ['min' => 90, 'max' => 220, 'step' => 5, 'unit' => '/100']],
        'p_block' => ['range', 'Space under the whole list', 14, '',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'p_rowpad' => ['range', 'Row padding, top and bottom', 3,
            'This and the photograph size are the two numbers that decide how tall the list is.',
            ['min' => 0, 'max' => 20, 'step' => 1, 'unit' => 'px']],
        'p_gap' => ['range', 'Space between photo, words and quantity', 12, '', ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'p_wgap' => ['range', 'Space between brand, name and option', 1, '',
            ['min' => 0, 'max' => 10, 'step' => 1, 'unit' => 'px']],
        'p_photo' => ['range', 'Photograph size', 36,
            'The floor is 30px and it is not a preference: below that the chip stops being a tap target and the picture stops being readable at arm’s length. SetContentsBoxTreatmentsTest pins the same floor on the shipped rule.',
            ['min' => 30, 'max' => 88, 'step' => 1, 'unit' => 'px']],
        'p_radius' => ['range', 'Photograph corner radius', 10, '',
            ['min' => 0, 'max' => 44, 'step' => 1, 'unit' => 'px']],
        'p_brand' => ['range', 'Brand size', 10, '',
            ['min' => 7, 'max' => 20, 'step' => 1, 'unit' => 'px']],
        'p_name' => ['range', 'Name size', 135,
            'IN TENTHS OF A PIXEL, because the shipped size is 13.5px and a slider stores whole numbers: 135 is 13.5px. CartPage’s “4.5 products on the screen” slider set that precedent for exactly this reason. The floor is 125 — 12.5px — which is the legible floor SetContentsBoxTreatmentsTest pins on the shipped rule; a squeeze is exactly the change that walks past a floor one pixel at a time.',
            ['min' => 125, 'max' => 240, 'step' => 5, 'unit' => '/10 px']],
        'p_var' => ['range', 'Option size', 115, 'In tenths of a pixel. 115 is 11.5px.',
            ['min' => 80, 'max' => 200, 'step' => 5, 'unit' => '/10 px']],
        'p_qty' => ['range', 'Quantity size', 12, '',
            ['min' => 8, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'p_more' => ['range', '“Show all” size', 125, 'In tenths of a pixel. 125 is 12.5px.',
            ['min' => 90, 'max' => 220, 'step' => 5, 'unit' => '/10 px']],
        'p_morept' => ['range', 'Space above “Show all”', 8, '',
            ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'p_morepb' => ['range', 'Space below “Show all”', 4, '',
            ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'p_foot' => ['range', 'Footing size', 13,
            'The wording — “Bought separately”, “Set price” — and the base the three figures start from. Each of them has its own size below; this is what they all ship at.',
            ['min' => 9, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        /*
         * THREE SIZES FOR THE THREE FIGURES, and they all ship at 13 because
         * the sheet gives all three one declaration today. The owner asked for
         * them separately — "each of the three figures … with their sizes" —
         * and the reason to have them is that the saving is the one a shopper
         * is meant to read across the aisle while the struck-through total is
         * the one he is meant to read past.
         */
        'p_was_f' => ['range', 'Footing: “Bought separately” size', 13, '',
            ['min' => 9, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'p_price_f' => ['range', 'Footing: “Set price” size', 13, '',
            ['min' => 9, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'p_save_f' => ['range', 'Footing: “You save” size', 13, '',
            ['min' => 9, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'p_footsp' => ['range', 'Space around the footing’s rule', 9,
            'Used twice — above the rule and below it — so the line stays centred in the space however it is set.',
            ['min' => 0, 'max' => 32, 'step' => 1, 'unit' => 'px']],
        'p_footgy' => ['range', 'Footing: space between its lines', 5, '',
            ['min' => 0, 'max' => 20, 'step' => 1, 'unit' => 'px']],
        'p_footgx' => ['range', 'Footing: space between its figures', 16, '',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],

        /* ── the list’s weight, letter-spacing and colour. Shared. ───────── */
        'p_lh' => ['range', 'Option line height', 130,
            'In hundredths: 130 is a line-height of 1.3. ▲ THE OPTION LINE ALONE. The name and the brand had their own leading the day the panel shipped — 1.24 and 1.15 on a phone against the option’s 1.3 — so they have their own controls, on the size tabs where the rest of their measurements are. One slider for three elements would have had to ship already disagreeing with two of them.',
            ['min' => 90, 'max' => 220, 'step' => 5, 'unit' => '/100']],
        'p_brand_w' => ['range', 'Brand weight', 700, '', ['min' => 300, 'max' => 900, 'step' => 10, 'unit' => '']],
        'p_brand_ls' => ['range', 'Brand letter-spacing', 4,
            'In hundredths of an em, which is how the sheet writes it: 4 is .04em. The brand is uppercase at 10px and the tracking is what makes it readable at that size, so bringing it to zero is not free.',
            ['min' => 0, 'max' => 20, 'step' => 1, 'unit' => '/100 em']],
        'p_brand_op' => ['range', 'Brand opacity', 72, '',
            ['min' => 20, 'max' => 100, 'step' => 1, 'unit' => '%']],
        'p_name_w' => ['range', 'Name weight', 640, '', ['min' => 300, 'max' => 900, 'step' => 10, 'unit' => '']],
        'p_qty_w' => ['range', 'Quantity weight', 700, '', ['min' => 300, 'max' => 900, 'step' => 10, 'unit' => '']],
        'p_more_w' => ['range', '“Show all” weight', 700, '', ['min' => 300, 'max' => 900, 'step' => 10, 'unit' => '']],
        'p_foot_w' => ['range', 'Footing figure weight', 700, 'The money, not the words beside it.', ['min' => 300, 'max' => 900, 'step' => 10, 'unit' => '']],
        'p_was_w' => ['range', '“Bought separately” figure weight', 600, '', ['min' => 300, 'max' => 900, 'step' => 10, 'unit' => '']],
        'p_save_w' => ['range', '“You save” weight', 700, '', ['min' => 300, 'max' => 900, 'step' => 10, 'unit' => '']],
        'p_brand_c' => ['colour', 'Brand colour', '',
            'Leave it empty and the brand keeps the theme’s own secondary ink, which is what it uses today and what a later theme change would move with.'],
        'p_name_c' => ['colour', 'Name colour', '', 'Empty keeps the theme’s ink.'],
        'p_var_c' => ['colour', 'Option colour', '', 'Empty keeps the theme’s secondary ink.'],
        'p_qty_c' => ['colour', 'Quantity colour', '', 'Empty keeps the theme’s ink.'],
        'p_more_c' => ['colour', '“Show all” colour', '',
            'Empty keeps the panel’s own deep pink (`--pink-deep`), which is what the disclosure draws today; with the panel off it falls back to the theme’s ink, exactly as the bare list always did.'],
        'p_line_c' => ['colour', 'Hairline colour', '',
            'The line between rows, under a linked name and above the footing. Empty keeps the theme’s own hairline, which is a TRANSLUCENT ink rather than a flat colour — a hex here replaces it with a solid one, which will read heavier than it looks in the picker.'],
        'p_ph_bg' => ['colour', 'Photograph backing colour', '#FFFFFF',
            'Behind a picture while it loads, and under a member with no picture at all. It ships WHITE rather than empty because the panel draws it white — a chip whose backing were the theme’s grey would read as a hole in the blush while the picture loaded. Empty falls back to the theme’s, which is what the bare list (panel off) uses.'],
        'p_foot_c' => ['colour', 'Footing wording colour', '',
            'The words “Bought separately” and “Set price”, and the struck-through figure beside the first of them. Empty keeps the theme’s secondary ink.'],
        'p_foot_b_c' => ['colour', 'Footing figure colour', '', 'The money. Empty keeps the theme’s ink.'],
        'p_save_c' => ['colour', '“You save” colour', '#1C7A4A',
            'The one colour this list writes itself rather than taking from the theme, so it ships as the green the page already draws.'],
        'p_panel_bg' => ['colour', 'Panel fill', '',
            'Empty keeps the theme’s own blush (`--pink-soft`), which is what the panel draws today and what a later theme change would move with. A hex here freezes it.'],
        'p_ring_c' => ['colour', 'Photograph ring colour', '#FFFFFF',
            'The band that lifts each chip off the blush. It wants to match whatever is BEHIND the panel — white on the product page — rather than the panel itself, which is what makes the chip read as sitting on top of the box.'],
        'p_sh_a' => ['range', 'Photograph shadow strength', 22,
            'Zero is no drop shadow at all; the ring stays.',
            ['min' => 0, 'max' => 100, 'step' => 1, 'unit' => '%']],
        'p_head_w' => ['range', 'Heading weight', 700, '', ['min' => 300, 'max' => 900, 'step' => 10, 'unit' => '']],
        'p_head_c' => ['colour', 'Heading colour', '', 'Empty keeps the theme’s ink.'],
        'p_footrule_c' => ['colour', 'Footing rule colour', '',
            'Empty keeps the slightly stronger hairline the panel draws today — a translucent ink rather than a flat colour, so it reads against the blush without a second tone in the box.'],

        /* ── the list, phone ─────────────────────────────────────────────── */
        'p_bp' => ['range', 'The set list switches to its phone sizes below', 480,
            'The buy column is about 346px wide on a phone and about 582px on a laptop, so this list is narrow at every width and its own turnover is low. 480 is the number the sheet already uses.',
            ['min' => 320, 'max' => 1024, 'step' => 10, 'unit' => 'px']],
        'p_panel_r_m' => ['range', 'Panel corner radius', 16, '',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'p_panel_pt_m' => ['range', 'Panel padding, top', 8, '',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'p_panel_pe_m' => ['range', 'Panel padding, trailing edge', 9,
            '▲ 9 AND NOT 14. At 390px the buy column is 346px wide, so every pixel of inner padding is a pixel the NAMES lose and this catalogue’s names are long enough that twenty of them flips a row onto a second line. The first cut of this box used the laptop’s padding on the phone and came out TALLER than the bare list it was squeezing — 408px against 396. Measured, both widths, in docs/SET-BOX-SQUEEZE.md.',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'p_panel_pb_m' => ['range', 'Panel padding, bottom', 8, '',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'p_panel_ps_m' => ['range', 'Panel padding, leading edge', 16, '',
            ['min' => 0, 'max' => 48, 'step' => 1, 'unit' => 'px']],
        'p_over_m' => ['range', 'How far the photographs hang past the panel', 10,
            'The same 1px floor as the laptop’s, and for the same reason.',
            ['min' => 1, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'p_ring_m' => ['range', 'Ring around each photograph', 25,
            'In tenths of a pixel: 25 is 2.5px, which is what the phone draws.',
            ['min' => 0, 'max' => 80, 'step' => 5, 'unit' => '/10 px']],
        'p_sh_y_m' => ['range', 'Photograph shadow drop', 2, '',
            ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'p_sh_blur_m' => ['range', 'Photograph shadow softness', 5, '',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'p_head_f_m' => ['range', 'Heading size', 125, 'In tenths of a pixel.',
            ['min' => 90, 'max' => 220, 'step' => 5, 'unit' => '/10 px']],
        'p_head_gap_m' => ['range', 'Space under the heading', 5, '',
            ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'p_name_lh_m' => ['range', 'Name line height', 124, 'In hundredths: 124 is 1.24.',
            ['min' => 90, 'max' => 220, 'step' => 2, 'unit' => '/100']],
        'p_brand_lh_m' => ['range', 'Brand line height', 115, 'In hundredths: 115 is 1.15.',
            ['min' => 90, 'max' => 220, 'step' => 5, 'unit' => '/100']],
        'p_block_m' => ['range', 'Space under the whole list', 14, '',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'p_rowpad_m' => ['range', 'Row padding, top and bottom', 3, '',
            ['min' => 0, 'max' => 20, 'step' => 1, 'unit' => 'px']],
        'p_gap_m' => ['range', 'Space between photo, words and quantity', 9, '', ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'p_wgap_m' => ['range', 'Space between brand, name and option', 1, '',
            ['min' => 0, 'max' => 10, 'step' => 1, 'unit' => 'px']],
        'p_photo_m' => ['range', 'Photograph size', 32,
            'Same 30px floor as the laptop’s, and the phone is where it bites: the shipped chip is 32.',
            ['min' => 30, 'max' => 88, 'step' => 1, 'unit' => 'px']],
        'p_radius_m' => ['range', 'Photograph corner radius', 9, '',
            ['min' => 0, 'max' => 44, 'step' => 1, 'unit' => 'px']],
        'p_brand_m' => ['range', 'Brand size', 10, '',
            ['min' => 7, 'max' => 20, 'step' => 1, 'unit' => 'px']],
        'p_name_m' => ['range', 'Name size', 130,
            'In tenths of a pixel. 130 is 13px — half a pixel smaller than the laptop’s, which is the squeeze this list was designed with. Same 125 floor as the laptop’s.',
            ['min' => 125, 'max' => 240, 'step' => 5, 'unit' => '/10 px']],
        'p_var_m' => ['range', 'Option size', 115, 'In tenths of a pixel.',
            ['min' => 80, 'max' => 200, 'step' => 5, 'unit' => '/10 px']],
        'p_qty_m' => ['range', 'Quantity size', 12, '',
            ['min' => 8, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'p_more_m' => ['range', '“Show all” size', 125, 'In tenths of a pixel.',
            ['min' => 90, 'max' => 220, 'step' => 5, 'unit' => '/10 px']],
        'p_morept_m' => ['range', 'Space above “Show all”', 8, '',
            ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'p_morepb_m' => ['range', 'Space below “Show all”', 4, '',
            ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'p_foot_m' => ['range', 'Footing size', 13, '',
            ['min' => 9, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'p_was_f_m' => ['range', 'Footing: “Bought separately” size', 13, '',
            ['min' => 9, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'p_price_f_m' => ['range', 'Footing: “Set price” size', 13, '',
            ['min' => 9, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'p_save_f_m' => ['range', 'Footing: “You save” size', 13, '',
            ['min' => 9, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'p_footsp_m' => ['range', 'Space around the footing’s rule', 7, '',
            ['min' => 0, 'max' => 32, 'step' => 1, 'unit' => 'px']],
        'p_footgy_m' => ['range', 'Footing: space between its lines', 4, '',
            ['min' => 0, 'max' => 20, 'step' => 1, 'unit' => 'px']],
        'p_footgx_m' => ['range', 'Footing: space between its figures', 12, '',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],

        /* ═════════ THE CART PAGE'S OWN PRODUCT ROWS ═══════════════════════ */
        /*
         * The owner asked for these by name — *"give control for set rows too
         * on backend for cart page"* — and they are not set-specific: they are
         * `.kbb-cartpage .ci`, the row EVERY product sits in, set or not. A
         * control that only moved a set row would leave the set out of step
         * with the rows either side of it, which is the opposite of what he is
         * looking at.
         *
         * THEY LIVE HERE RATHER THAN ON Appearance → Cart page because that
         * screen governs the SQUEEZED layout (`layout = squeeze`) and emits
         * nothing at all while the shop is on `classic`, which it is. These
         * four numbers are hard-coded in resources/css/kbb/kbb-cart.css and
         * apply to the page the shop actually serves. The checkout's equivalent
         * already runs on tokens (`--cop-rowp-t` and its family); this is the
         * same idea, one page along.
         */
        'ci_pad_t' => ['range', 'Cart row padding, top', 11, '',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'ci_pad_b' => ['range', 'Cart row padding, bottom', 11, '',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'ci_pad_x' => ['range', 'Cart row padding, left and right', 14, '',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'ci_gap' => ['range', 'Cart row: space between the picture and the words', 12, '',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'ci_name_gap' => ['range', 'Cart row: space under the product name', 6,
            'What separates the name from whatever is under it — the option line, the set’s circles, or the quantity stepper.',
            ['min' => 0, 'max' => 32, 'step' => 1, 'unit' => 'px']],
        'ci_bp' => ['range', 'The cart rows switch to their phone sizes below', 600,
            '▲ 600, AND NOT THE 760 THE SET BOX USES. The cart page’s own phone block in the stylesheet is at 600px and the set box’s is at 760px, and they are right to differ — one is about a two-column page collapsing and the other about the checkout summary starting to clip its contents. One number for both would change the shop at every width in between, which is exactly what may not happen.',
            ['min' => 320, 'max' => 1200, 'step' => 10, 'unit' => 'px']],
        'ci_pad_t_m' => ['range', 'Cart row padding, top', 10, '',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'ci_pad_b_m' => ['range', 'Cart row padding, bottom', 10, '',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'ci_pad_x_m' => ['range', 'Cart row padding, left and right', 12, '',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'ci_gap_m' => ['range', 'Cart row: space between the picture and the words', 11, '',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'ci_name_gap_m' => ['range', 'Cart row: space under the product name', 5, '',
            ['min' => 0, 'max' => 32, 'step' => 1, 'unit' => 'px']],
    ];

    /**
     * `tab => [label, description, [keys]]`.
     *
     * TWO TABS, because that is what the owner asked for by name. Inside each
     * one the grouping is by what a control DOES — what is drawn, then size and
     * spacing, then type and colour — because that is the order somebody
     * arranges a thing in.
     */
    /**
     * `tab => [label, description, [keys]]`.
     *
     * TWO TABS, because that is what the owner asked for by name. Inside each
     * one the grouping is by SURFACE and then by what a control does — what is
     * drawn, then size and spacing, then type and colour — because that is the
     * order somebody arranges a thing in.
     */
    public const TABS = [
        'd_cart' => ['Desktop · Cart page rows',
            'The rows every product sits in on the cart page — set or not. These four numbers were hard-coded in the stylesheet until this release, which is why the owner asked for them by name. Laptop values; the phone has its own on the Mobile tab.',
            ['ci_pad_t', 'ci_pad_b', 'ci_pad_x', 'ci_gap', 'ci_name_gap']],
        'd_box_parts' => ['Desktop · Set box — what is drawn',
            'The fanned circles and the “What’s inside” popup under a set’s name in the cart drawer, on the cart page, in the checkout summary, in the browsed rail and on an order. Every switch here applies to BOTH screens — one control with two halves that could disagree is worse than one honest control.',
            ['on', 'fan_on', 'fan_max', 'btn_on', 'head_on', 'qty_on', 'save_on']],
        'd_box_size' => ['Desktop · Set box — size and spacing',
            'Laptop values. The circle and the type sizes are PERCENTAGES of what Appearance → Cart panel already sets, so the box follows the row it sits in rather than drifting away from it.',
            ['top', 'bot', 'gap', 'circle', 'overlap', 'ring', 'btn_f', 'btn_px', 'btn_py', 'btn_h', 'btn_r',
                'pop_w', 'pop_pad_x', 'pop_pad_y', 'pop_r', 'pop_off', 'pop_sh_y', 'pop_sh_blur', 'pop_sh_a',
                'head_f', 'head_gap', 'li_f', 'li_gap', 'qty_f', 'save_f']],
        'd_box_type' => ['Desktop · Set box — weight and colour',
            'Both screens. There is no font family on this screen on purpose: this shop has one typographic system, and a family belongs to a site-wide typography setting rather than to the cart’s set box. Leave a colour empty and that element keeps the theme’s own, so a later theme change still moves it.',
            ['btn_w', 'head_w', 'head_ls', 'li_w', 'li_lh', 'qty_w', 'save_w',
                'ring_c', 'btn_c', 'btn_bg', 'btn_hover_bg', 'btn_line_c', 'pop_bg', 'pop_line_c',
                'head_c', 'li_c', 'qty_c', 'save_c']],
        'd_list_parts' => ['Desktop · Set list — what is drawn',
            '“What is in this set”, in the buy column of a set’s own product page. Both screens.',
            ['p_on', 'p_panel_on', 'p_heading_on', 'p_count_on', 'p_photo_on', 'p_brand_on', 'p_var_on',
                'p_qty_on', 'p_link_on', 'p_underline_on', 'p_rule_on', 'p_fold_on', 'p_fold_at',
                'p_foot_on', 'p_footrule_on', 'p_was_on', 'p_price_on', 'p_save_on']],
        'd_list_panel' => ['Desktop · Set list — the panel and the hang',
            'The blush box the owner chose on 29 September, and the photographs hanging off its leading edge. Laptop values. “How far the photographs hang past the panel” is the OVERHANG and not the chip’s raw pull: the pull is worked out as the panel’s leading padding plus this, so the chips cannot be dragged level with the panel’s edge or inside it whatever the padding is set to.',
            ['p_panel_r', 'p_panel_pt', 'p_panel_pe', 'p_panel_pb', 'p_panel_ps',
                'p_over', 'p_ring', 'p_sh_y', 'p_sh_blur']],
        'd_list_size' => ['Desktop · Set list — size and spacing',
            'Laptop values. The row’s height follows the photograph, so the photograph size and the row padding are the two numbers that decide how tall the list is.',
            ['p_block', 'p_head_f', 'p_head_gap', 'p_rowpad', 'p_gap', 'p_wgap', 'p_photo', 'p_radius',
                'p_brand', 'p_brand_lh', 'p_name', 'p_name_lh', 'p_var',
                'p_qty', 'p_more', 'p_morept', 'p_morepb', 'p_foot', 'p_was_f', 'p_price_f', 'p_save_f',
                'p_footsp', 'p_footgy', 'p_footgx']],
        'd_list_type' => ['Desktop · Set list — weight and colour',
            'Both screens.',
            ['p_head_w', 'p_lh', 'p_brand_w', 'p_brand_ls', 'p_brand_op', 'p_name_w', 'p_qty_w', 'p_more_w',
                'p_foot_w', 'p_was_w', 'p_save_w', 'p_sh_a',
                'p_panel_bg', 'p_head_c', 'p_brand_c', 'p_name_c', 'p_var_c', 'p_qty_c',
                'p_more_c', 'p_line_c', 'p_footrule_c', 'p_ring_c', 'p_ph_bg', 'p_foot_c',
                'p_foot_b_c', 'p_save_c']],

        'm_where' => ['Mobile · Where “mobile” starts',
            'THREE NUMBERS AND NOT ONE, and they are meant to differ. Each of the three surfaces already turns over at its own width in the shop’s stylesheets — 600 for the cart page’s rows, 760 for the set box, 480 for the buy column’s list — because each is about a different thing collapsing. Forcing one number on all three would change what the shop renders at every width in between, which is the one thing a new setting may not do.',
            ['ci_bp', 'bp', 'p_bp']],
        'm_cart' => ['Mobile · Cart page rows',
            'The phone’s own padding and gaps for the cart page’s product rows.',
            ['ci_pad_t_m', 'ci_pad_b_m', 'ci_pad_x_m', 'ci_gap_m', 'ci_name_gap_m']],
        'm_box' => ['Mobile · Set box',
            'The phone’s own sizes. What is DRAWN, every weight and every colour are shared with Desktop and are set on that tab — an element switched off there is off here too.',
            ['top_m', 'bot_m', 'gap_m', 'circle_m', 'overlap_m', 'ring_m', 'btn_f_m', 'btn_px_m', 'btn_py_m',
                'btn_h_m', 'btn_r_m', 'pop_w_m', 'pop_pad_x_m', 'pop_pad_y_m', 'pop_r_m', 'pop_off_m',
                'pop_sh_y_m', 'pop_sh_blur_m', 'pop_sh_a_m', 'head_f_m', 'head_gap_m', 'li_f_m',
                'li_gap_m', 'qty_f_m', 'save_f_m']],
        'm_list_panel' => ['Mobile · Set list — the panel and the hang',
            'The phone’s own. The trailing padding is 9 here against the laptop’s 14 and that is not an oversight: at 390px the buy column is 346px wide, so inner padding is measure the names lose, and the first cut of this box came out TALLER on a phone than the bare list it was squeezing.',
            ['p_panel_r_m', 'p_panel_pt_m', 'p_panel_pe_m', 'p_panel_pb_m', 'p_panel_ps_m',
                'p_over_m', 'p_ring_m', 'p_sh_y_m', 'p_sh_blur_m']],
        'm_list' => ['Mobile · Set list',
            'The phone’s own sizes for the product page’s list. What is drawn, the weights and the colours are shared with Desktop.',
            ['p_block_m', 'p_head_f_m', 'p_head_gap_m', 'p_rowpad_m', 'p_gap_m', 'p_wgap_m', 'p_photo_m',
                'p_radius_m', 'p_brand_m', 'p_brand_lh_m',
                'p_name_m', 'p_name_lh_m', 'p_var_m', 'p_qty_m', 'p_more_m', 'p_morept_m', 'p_morepb_m',
                'p_foot_m', 'p_was_f_m', 'p_price_f_m', 'p_save_f_m',
                'p_footsp_m', 'p_footgy_m', 'p_footgx_m']],
    ];

    /**
     * This screen's point on ModuleSchema's policy axes.
     *
     * `invalid => default` with `clamp => true`: every numeric control here is a
     * slider, a slider cannot emit an out-of-range value, and a POST that does
     * is not worth a 422 — rule 5's "a select stores one of its own options or
     * the default", pointed at numbers.
     *
     * `blank => keep` is what makes the EMPTY COLOUR a real value rather than a
     * missing one: seven colour fields ship empty and mean "keep the theme's",
     * and `blank => default` would be the same answer by accident rather than
     * on purpose. `hex => repair` is the dialect the other colour screens in
     * this console store, so a colour copied from one into another behaves the
     * same in both.
     *
     * `markup => strip` is declared and never exercised: there is no text field
     * on this screen at all, and there must not be — every string a shopper
     * reads on the set box is an interface string that goes through __() and
     * belongs to Translation → Strings, not to a box here.
     */
    public const POLICY = [
        'max' => 120,
        'blank' => 'keep',
        'invalid' => 'default',
        'clamp' => true,
        'hex' => 'repair',
        'bool' => 'cast',
        'markup' => 'strip',
    ];

    public function __construct(private SettingsService $settings) {}

    /**
     * Every value, saved or shipped.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $saved = $this->settings->get(self::PREFIX.$key, null);
            $out[$key] = $saved === null ? $def[2] : $this->cast($key, $saved);
        }

        return $out;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** The shipped value of every key. @return array<string, mixed> */
    public static function defaults(): array
    {
        return array_map(static fn (array $def) => $def[2], self::SCHEMA);
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (isset(self::SCHEMA[$key])) {
                $this->settings->set(self::PREFIX.$key, $this->cast($key, $value));
            }
        }
    }

    /**
     * The normalised schema, memoised inside ModuleSchema rather than in a
     * static here — one piece of process-level state with one registered reset,
     * which is what StaticMemoIsolationTest asks for.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function fields(): array
    {
        return ModuleSchema::normalised(self::class, self::SCHEMA, self::POLICY);
    }

    private function cast(string $key, mixed $value): mixed
    {
        return ModuleSchema::cast(self::fields()[$key], $value);
    }

    /**
     * Cast a whole payload WITHOUT writing it — what the live preview draws
     * from.
     *
     * The preview shows what the owner has typed and not saved, and it must not
     * be a second interpretation of those values: the same cast, the same
     * clamp, the same colour repair, then the same css(). A preview built from
     * raw POST data is a preview that can show something the save would never
     * store.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function preview(array $values): array
    {
        $out = $this->all();

        foreach ($values as $key => $value) {
            if (isset(self::SCHEMA[$key])) {
                $out[$key] = $this->cast($key, $value);
            }
        }

        return $out;
    }

    /* ═══════════════════════ what reaches the storefront ═══════════════════ */

    /**
     * The block the storefront emits — or the EMPTY STRING while nothing has
     * moved.
     *
     * The empty answer is the whole of rule 1 on the markup side. See this
     * class's header: restating the shipped defaults would be correct in pixels
     * and wrong in bytes, on every page of the shop at once.
     */
    public function storefrontCss(): string
    {
        $values = $this->all();

        return $values == self::defaults() ? '' : self::css($values);
    }

    /**
     * A colour that is safe to print into a CSS declaration, or ''.
     *
     * ── WHY Color::isValidHex() AND NOT TRUST IN THE CAST ───────────────────
     *
     * Because the value is printed into a <style> BLOCK, where Blade's escaping
     * is not a defence but a hazard: `{{ }}` turns an apostrophe into `&#39;`,
     * and an HTML entity inside a <style> element is handed to the CSS parser as
     * the five characters `&#39;` rather than being decoded — so escaping
     * neither removes a quote nor keeps one out. The only safe thing to print
     * there is a value that cannot contain anything but hex digits, and that is
     * what this checks, at the boundary the value crosses, on every read.
     *
     * ModuleSchema::cast() already refuses anything else on the way IN. This is
     * the second lock and it is not decoration: `settings` is a table, the owner
     * has a shell on the live box (CLAUDE.md, 24 September 2026), and a row
     * written by hand would otherwise land straight in a stylesheet. Rule 5.
     */
    private static function hex(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '' || ! Color::isValidHex($value)) {
            return '';
        }

        return str_starts_with($value, '#') ? $value : '#'.$value;
    }

    /**
     * `--name:#RRGGBB`, or '' when the field is empty.
     *
     * An empty colour must emit NO PROPERTY AT ALL rather than an empty one:
     * the rules below read `var(--kset-btnc, var(--cp-accent,#c9587f))`, and a
     * declared-but-empty custom property is a VALID value that beats the
     * fallback — the button would render with no colour set and the cart
     * panel's accent unreachable. That is the difference between "ships at
     * today's value" and "ships blank", and it is one `if`.
     */
    private static function colourVar(string $name, mixed $value): string
    {
        $hex = self::hex($value);

        return $hex === '' ? '' : $name.':'.$hex;
    }

    /** A hundredths integer as a unitless CSS number, locale-proof. */
    private static function ratio(int $value, int $of = 100): string
    {
        $out = rtrim(rtrim(number_format($value / $of, 3, '.', ''), '0'), '.');

        return $out === '' || $out === '-' ? '0' : $out;
    }

    /**
     * Every custom property the SET BOX reads, for one breakpoint.
     *
     * `$m` selects the phone's twin of each dimensional key. The SHARED keys —
     * weights, letter-spacing, line height and the colours — are emitted on the
     * desktop block only: a custom property the phone block does not redeclare
     * simply stays what it was, so restating them would be bytes for nothing.
     *
     * @param  array<string, mixed>  $c
     * @return list<string>
     */
    private static function boxVars(array $c, bool $m): array
    {
        // ONE ACCESSOR, so the phone block cannot quietly read a laptop key.
        // Every dimensional field below is named once and the `_m` is added
        // here, which is what makes the two blocks provably the same list.
        $n = static fn (string $k): int => (int) $c[$m ? $k.'_m' : $k];

        $out = [
            /*
             * The base the three type sizes multiply, and the ONLY thing that
             * differs between the two blocks for those fields. It differs
             * because the shipped sheet differs: the phone block restates the
             * button, the popup line and the saving against `--cp-name-m` and
             * leaves the popup's HEADING on `--cp-name`. Both are reproduced
             * exactly — the heading's rule names `--cp-name` itself.
             */
            '--kset-base:'.($m ? 'var(--cp-name-m,13px)' : 'var(--cp-name,12.5px)'),

            '--kset-top:'.$n('top').'px',
            '--kset-bot:'.$n('bot').'px',
            '--kset-gap:'.$n('gap').'px',
            '--kset-cf:'.self::ratio($n('circle')),
            // Negative: it is a margin-inline-start pulling each circle back
            // over the one before it, so the sign belongs to the value.
            '--kset-lap:-'.self::ratio($n('overlap')),
            '--kset-ring:'.$n('ring').'px',
            '--kset-btnf:'.self::ratio($n('btn_f')),
            '--kset-btnpx:'.$n('btn_px').'px',
            '--kset-btnpy:'.$n('btn_py').'px',
            '--kset-btnh:'.$n('btn_h').'px',
            '--kset-btnr:'.$n('btn_r').'px',
            '--kset-popw:'.$n('pop_w').'px',
            '--kset-poppx:'.$n('pop_pad_x').'px',
            '--kset-poppy:'.$n('pop_pad_y').'px',
            '--kset-popr:'.$n('pop_r').'px',
            '--kset-popoff:'.$n('pop_off').'px',
            '--kset-popshy:'.$n('pop_sh_y').'px',
            '--kset-popshb:'.$n('pop_sh_blur').'px',
            '--kset-popsha:'.self::ratio($n('pop_sh_a')),
            '--kset-headf:'.self::ratio($n('head_f')),
            '--kset-headgap:'.$n('head_gap').'px',
            '--kset-lif:'.self::ratio($n('li_f')),
            '--kset-ligap:'.$n('li_gap').'px',
            '--kset-qf:'.self::ratio($n('qty_f')),
            '--kset-savef:'.self::ratio($n('save_f')),
        ];

        if ($m) {
            return $out;
        }

        return array_values(array_filter(array_merge($out, [
            '--kset-btnw:'.(int) $c['btn_w'],
            '--kset-headw:'.(int) $c['head_w'],
            '--kset-headls:'.self::ratio((int) $c['head_ls']).'em',
            '--kset-liw:'.(int) $c['li_w'],
            '--kset-lilh:'.self::ratio((int) $c['li_lh']),
            '--kset-qw:'.(int) $c['qty_w'],
            '--kset-savew:'.(int) $c['save_w'],
            self::colourVar('--kset-ringc', $c['ring_c']),
            self::colourVar('--kset-btnc', $c['btn_c']),
            self::colourVar('--kset-btnbg', $c['btn_bg']),
            self::colourVar('--kset-btnhbg', $c['btn_hover_bg']),
            self::colourVar('--kset-btnline', $c['btn_line_c']),
            self::colourVar('--kset-popbg', $c['pop_bg']),
            self::colourVar('--kset-popline', $c['pop_line_c']),
            self::colourVar('--kset-headc', $c['head_c']),
            self::colourVar('--kset-lic', $c['li_c']),
            self::colourVar('--kset-qc', $c['qty_c']),
            self::colourVar('--kset-savec', $c['save_c']),
        ]), static fn (string $d): bool => $d !== ''));
    }

    /**
     * Every custom property the PRODUCT PAGE'S LIST reads, for one breakpoint.
     *
     * Three of the sizes are stored in TENTHS of a pixel and printed with the
     * decimal put back: the shipped name is 13.5px, the option line 11.5px and
     * "Show all" 12.5px, and a `range` stores whole numbers. Printing them here
     * rather than at the screen is what keeps the slider and the page from
     * disagreeing by a factor of ten.
     *
     * @param  array<string, mixed>  $c
     * @return list<string>
     */
    private static function listVars(array $c, bool $m): array
    {
        $n = static fn (string $k): int => (int) $c[$m ? $k.'_m' : $k];

        $out = [
            /*
             * THE PANEL. Four paddings and never a shorthand: `padding: a b c d`
             * is PHYSICAL, so the fourth value is the left edge in every
             * language — which is how the shipped box came to hang its chips
             * 16px past a panel whose leading padding it thought was 20 on /ar,
             * and to push the footing's rule 6px out through the panel's right
             * edge. Measured in Chromium before the fix; the case in
             * SetContentsBoxTreatmentsTest that goes red without it names both
             * numbers.
             */
            '--ksl-pr:'.$n('p_panel_r').'px',
            '--ksl-ppt:'.$n('p_panel_pt').'px',
            '--ksl-ppe:'.$n('p_panel_pe').'px',
            '--ksl-ppb:'.$n('p_panel_pb').'px',
            '--ksl-pps:'.$n('p_panel_ps').'px',
            /*
             * THE OVERHANG, NOT THE PULL. The partial works the chip's negative
             * margin out as `calc(-1 * (var(--ksl-pps) + var(--ksl-over)))`, so
             * `pull > padding` holds for every value this slider can take — the
             * schema's floor of 1 is the whole of the guarantee, and there is no
             * arithmetic here that could get it wrong. The alternative, clamping
             * a raw pull against the padding in PHP, would have had to be
             * re-derived at both breakpoints and would still have let the owner
             * set a pull that LOOKED wrong in the field while rendering right.
             */
            '--ksl-over:'.$n('p_over').'px',
            '--ksl-ring:'.self::ratio($n('p_ring'), 10).'px',
            '--ksl-shy:'.$n('p_sh_y').'px',
            '--ksl-shb:'.$n('p_sh_blur').'px',
            '--ksl-headf:'.self::ratio($n('p_head_f'), 10).'px',
            '--ksl-headgap:'.$n('p_head_gap').'px',
            '--ksl-nmlh:'.self::ratio($n('p_name_lh')),
            '--ksl-brlh:'.self::ratio($n('p_brand_lh')),

            '--ksl-block:'.$n('p_block').'px',
            '--ksl-rowpad:'.$n('p_rowpad').'px',
            '--ksl-gap:'.$n('p_gap').'px',
            '--ksl-wgap:'.$n('p_wgap').'px',
            '--ksl-ph:'.$n('p_photo').'px',
            '--ksl-phr:'.$n('p_radius').'px',
            '--ksl-br:'.$n('p_brand').'px',
            '--ksl-nm:'.self::ratio($n('p_name'), 10).'px',
            '--ksl-var:'.self::ratio($n('p_var'), 10).'px',
            '--ksl-q:'.$n('p_qty').'px',
            '--ksl-more:'.self::ratio($n('p_more'), 10).'px',
            '--ksl-morept:'.$n('p_morept').'px',
            '--ksl-morepb:'.$n('p_morepb').'px',
            '--ksl-foot:'.$n('p_foot').'px',
            '--ksl-wasf:'.$n('p_was_f').'px',
            '--ksl-pricef:'.$n('p_price_f').'px',
            '--ksl-savef:'.$n('p_save_f').'px',
            '--ksl-footsp:'.$n('p_footsp').'px',
            '--ksl-footgy:'.$n('p_footgy').'px',
            '--ksl-footgx:'.$n('p_footgx').'px',
        ];

        if ($m) {
            return $out;
        }

        return array_values(array_filter(array_merge($out, [
            '--ksl-lh:'.self::ratio((int) $c['p_lh']),
            '--ksl-headw:'.(int) $c['p_head_w'],
            // `rgba(42,34,40,var(--ksl-sha))` in the partial, so this is the
            // alpha alone and never a colour — one number, no parsing.
            '--ksl-sha:'.self::ratio((int) $c['p_sh_a']),
            '--ksl-brw:'.(int) $c['p_brand_w'],
            '--ksl-brls:'.self::ratio((int) $c['p_brand_ls']).'em',
            '--ksl-brop:'.self::ratio((int) $c['p_brand_op']),
            '--ksl-nmw:'.(int) $c['p_name_w'],
            '--ksl-qw:'.(int) $c['p_qty_w'],
            '--ksl-morew:'.(int) $c['p_more_w'],
            '--ksl-footw:'.(int) $c['p_foot_w'],
            '--ksl-wasw:'.(int) $c['p_was_w'],
            '--ksl-savew:'.(int) $c['p_save_w'],
            self::colourVar('--ksl-brc', $c['p_brand_c']),
            self::colourVar('--ksl-nmc', $c['p_name_c']),
            self::colourVar('--ksl-varc', $c['p_var_c']),
            self::colourVar('--ksl-qc', $c['p_qty_c']),
            self::colourVar('--ksl-morec', $c['p_more_c']),
            self::colourVar('--ksl-linec', $c['p_line_c']),
            self::colourVar('--ksl-pbg', $c['p_panel_bg']),
            self::colourVar('--ksl-headc', $c['p_head_c']),
            self::colourVar('--ksl-footrule', $c['p_footrule_c']),
            self::colourVar('--ksl-ringc', $c['p_ring_c']),
            self::colourVar('--ksl-phbg', $c['p_ph_bg']),
            self::colourVar('--ksl-footc', $c['p_foot_c']),
            self::colourVar('--ksl-footbc', $c['p_foot_b_c']),
            self::colourVar('--ksl-savec', $c['p_save_c']),
        ]), static fn (string $d): bool => $d !== ''));
    }

    /**
     * The whole emitted stylesheet, from a set of values.
     *
     * ── WHAT IS A VARIABLE AND WHAT HAS TO BE A RULE ────────────────────────
     *
     * Almost all of it is ONE DECLARATION BLOCK PER SURFACE. The two set
     * partials were rewritten so every tunable number reads
     * `var(--kset-x, <its old literal>)` and NOTHING declares those properties,
     * so there is no property here to re-specify and no !important anywhere.
     * The selectors are `.kset.kset` and `.ksl.ksl` — one class more specific
     * than the partials' own `.kset` and `.ksl`, which is what lets the owner's
     * numbers beat the phone blocks those partials still carry for a shop that
     * has moved nothing.
     *
     * Three things a custom property cannot do, and each is a rule:
     *
     *   `display:none`  for the parts that are switched off. A variable can
     *                   change a number inside a rule; it cannot switch the
     *                   rule off, and it cannot take a track out of a grid.
     *   `nth-child`     for the fan's cap.
     *   THE CART ROW    `.kbb-cartpage .ci`'s padding and gaps live in
     *                   resources/css/kbb/kbb-cart.css, a compiled Vite
     *                   stylesheet this package does not rebuild, so those four
     *                   numbers really are re-declared — at (0,4,0) against the
     *                   sheet's (0,2,0), which wins on specificity at every
     *                   width including inside the sheet's own 600px block.
     *
     * ── THE ONE THING DELIBERATELY LEFT ALONE ───────────────────────────────
     *
     * The popup's `max-width` is not touched here at all: it is `var(--kset-
     * popw, 230px)` in the partial, and the checkout's phone escape hatch
     * `.kbb-checkout .kset-pop.is-open{max-width:none}` still beats it on
     * specificity exactly as it did before. That rule is what gets the popup out
     * of `.panels{overflow:hidden}` on a phone, and a width cap that outranked
     * it would slice the box back to one visible line on the one screen that was
     * already fixed for that once.
     *
     * ── NOTHING HERE IS INTERPOLATED THAT IS NOT AN INTEGER OR A HEX ────────
     *
     * Every value below is an int out of a clamped `range`, or a string this
     * class built out of one, or a colour that has been through
     * Color::isValidHex(). There is no path from a settings row to a selector, a
     * property name or a unit. Rule 5.
     *
     * @param  array<string, mixed>  $c
     */
    public static function css(array $c): string
    {
        $fan = max(0, (int) $c['fan_max']);

        $rules = [
            '.kset.kset{'.implode(';', self::boxVars($c, false)).'}',
            '.ksl.ksl{'.implode(';', self::listVars($c, false)).'}',
            // The cart page's own rows — properties, not variables, because the
            // sheet that sets them is compiled and this package does not
            // rebuild it.
            '.kbb-cartpage .items .ci.ci{padding:'.(int) $c['ci_pad_t'].'px '.(int) $c['ci_pad_x'].'px '
                .(int) $c['ci_pad_b'].'px;gap:'.(int) $c['ci_gap'].'px}',
            '.kbb-cartpage .items .ci.ci .cn{margin-bottom:'.(int) $c['ci_name_gap'].'px}',
        ];

        /*
         * ── WHAT IS DRAWN ──────────────────────────────────────────────────
         *
         * Each selector is one class past the partial's own, which matters most
         * for the popup: `.kset-pop.is-open{display:block}` is (0,2,0), so
         * hiding it needs (0,3,0) and gets it without an !important. The set
         * LIST's switches are classes on `.ksl` instead, written by
         * panelClass() — they change grid tracks as well as visibility, and the
         * rules for them live in the partial beside the tracks they alter.
         */
        if (! $c['on']) {
            $rules[] = '.kset.kset{display:none}';
        }

        if (! $c['fan_on']) {
            $rules[] = '.kset .kset-fan.kset-fan{display:none}';
        }

        if (! $c['btn_on']) {
            $rules[] = '.kset .kset-btn.kset-btn{display:none}';
            $rules[] = '.kset .kset-pop.kset-pop{display:none}';
        }

        if (! $c['head_on']) {
            $rules[] = '.kset .kset-pop.kset-pop h4{display:none}';
        }

        if (! $c['qty_on']) {
            $rules[] = '.kset .kset-pop.kset-pop .kset-q{display:none}';
        }

        if (! $c['save_on']) {
            $rules[] = '.kset .kset-save.kset-save{display:none}';
        }

        /*
         * The fan's cap. `nth-child(n+N+1)` hides everything past the Nth
         * circle; the popup still lists every member, because it reads the
         * set's own contents and never what happens to be on screen — which is
         * what the partial's own docblock argues and what makes a capped fan
         * honest rather than a lie about what is in the box.
         */
        if ($fan > 0) {
            $rules[] = '.kset .kset-fan.kset-fan .kset-c:nth-child(n+'.($fan + 1).'){display:none}';
        }

        /*
         * THREE MEDIA QUERIES, because the three surfaces really do turn over at
         * three widths and always have — 600 for the cart page's rows, 760 for
         * the set box, 480 for the buy column's list. Each breakpoint is
         * interpolated into its QUERY rather than read from a property, because
         * a media query is resolved before custom properties exist:
         * `@media (max-width: var(--x))` is not a thing.
         */
        $rules[] = '@media (max-width:'.(int) $c['ci_bp'].'px){'
            .'.kbb-cartpage .items .ci.ci{padding:'.(int) $c['ci_pad_t_m'].'px '.(int) $c['ci_pad_x_m'].'px '
            .(int) $c['ci_pad_b_m'].'px;gap:'.(int) $c['ci_gap_m'].'px}'
            .'.kbb-cartpage .items .ci.ci .cn{margin-bottom:'.(int) $c['ci_name_gap_m'].'px}}';

        $rules[] = '@media (max-width:'.(int) $c['bp'].'px){.kset.kset{'
            .implode(';', self::boxVars($c, true)).'}}';

        $rules[] = '@media (max-width:'.(int) $c['p_bp'].'px){.ksl.ksl{'
            .implode(';', self::listVars($c, true)).'}}';

        return implode('', $rules);
    }

    /**
     * The set LIST's structural switches, as classes on `.ksl`.
     *
     * A custom property can change a number inside a rule; it cannot switch a
     * rule off and it cannot take a track out of a grid — and the photograph
     * and the quantity are grid TRACKS. So these are classes, exactly as
     * CartPanel::bodyClass() does it, and they are on the element from the
     * first byte so nothing reflows after paint. The rules they select live in
     * partials/set-contents-panel.blade.php beside the tracks they alter.
     *
     * Leading space included, or the empty string.
     *
     * @param  array<string, mixed>  $c
     */
    public static function panelClass(array $c): string
    {
        $classes = array_values(array_filter([
            /*
             * `ksl-panel` IS ADDED WHEN THE PANEL IS ON, and the absence of it
             * is what the bare list is — not a `ksl-nopanel` that has to undo
             * nine declarations. Every rule of the "hanging photos" treatment is
             * scoped under this class, so switching it off does not fight the
             * drawing, it simply does not select it, and the bare list the
             * treatment was layered over comes back exactly as it was written.
             */
            $c['p_panel_on'] ? 'ksl-panel' : '',
            $c['p_count_on'] ? '' : 'ksl-nocount',
            $c['p_footrule_on'] ? '' : 'ksl-nofootrule',
            $c['p_photo_on'] ? '' : 'ksl-noph',
            $c['p_brand_on'] ? '' : 'ksl-nobr',
            $c['p_var_on'] ? '' : 'ksl-novar',
            $c['p_qty_on'] ? '' : 'ksl-noq',
            $c['p_rule_on'] ? '' : 'ksl-norule',
            $c['p_underline_on'] ? '' : 'ksl-noul',
            $c['p_foot_on'] ? '' : 'ksl-nofoot',
            $c['p_was_on'] ? '' : 'ksl-nowas',
            $c['p_price_on'] ? '' : 'ksl-noprice',
            $c['p_save_on'] ? '' : 'ksl-nosave',
        ]));

        return $classes === [] ? '' : ' '.implode(' ', $classes);
    }

    /**
     * How many rows stand before the fold, or 0 for "never fold".
     *
     * The fold is a SERVER decision — the rows past it are inside a <details> in
     * the markup, which is what lets the disclosure work with no script at all
     * and what puts them in the page for find-in-page and for a crawler. So the
     * partial asks this rather than reading two keys and getting the "off" case
     * wrong.
     *
     * @param  array<string, mixed>  $c
     */
    public static function foldAt(array $c): int
    {
        return $c['p_fold_on'] ? max(1, (int) $c['p_fold_at']) : 0;
    }
}
