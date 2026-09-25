<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;

/**
 * The cart PAGE — the squeezed layout, and every knob on it.
 *
 * Not to be confused with App\Services\CartPanel, which is the slide-out
 * mini-cart drawer. Two surfaces, two services, deliberately: the drawer is
 * 380px of overlay and this is a whole page with two docked bars on it, and a
 * shop that wants the drawer dense has said nothing about wanting the page
 * dense.
 *
 * ── THE ONE SETTING THAT DECIDES WHETHER ANY OF THIS RUNS ───────────────────
 *
 * `layout`, and it ships as `classic`. At `classic` this service emits an empty
 * class list and an empty style attribute, the page's Blade takes none of its
 * new branches, and the stylesheet's new rules are all scoped under a class
 * that is not on the element. Applying the package changes the rendered cart
 * page by ZERO BYTES — which is what tests/Feature/StorefrontEnglishUnchangedTest
 * compares, and what a live shop with real baskets in it requires. The owner
 * turns it on from Appearance → Cart page when he is ready to look at it.
 *
 * ── SIZING IS CSS, NOT JAVASCRIPT ───────────────────────────────────────────
 *
 * Everything on the squeezed page derives from four numbers — the row height,
 * the two bar heights and the sheet's density — through calc() in
 * kbb-cart.css. There is no resize observer and nothing measures anything. The
 * reason is not purity: a JS sizer runs after first paint, so every shopper
 * sees one frame of the wrong layout, and it runs again on every scroll-driven
 * viewport resize on iOS. calc() has neither problem and costs nothing.
 *
 * The derivation lives in the stylesheet rather than here so that a shop that
 * has never opened this screen still gets a static, cacheable sheet.
 *
 * ── WHY `rec_per` IS STORED AS TENTHS ───────────────────────────────────────
 *
 * The owner asked for "4.5 products on the screen". 4.5 is not an integer and
 * `range` casts to one, so the stored value is 45 and the admin screen divides
 * by ten for display and multiplies on the way in. Storing a float here would
 * mean a second cast path in this class for one field; storing tenths means the
 * same clamp as every other range, and the one place that has to know is the
 * screen that draws the slider.
 */
class CartPage
{
    /**
     * key => [type, label, default, help, options]
     *
     * Types: range, bool, text, select, money (stored in fils), ids.
     */
    public const SCHEMA = [
        // ── Layout ──
        'layout' => ['select', 'Cart page layout', 'classic',
                     'Classic is the page as it is today. Squeezed is the docked-bar layout: dense product rows, a full-width recommended rail, the minimal coupon box and the two sticky rows at the foot of the screen.', [
                         'classic' => 'Classic — the page as it is today',
                         'squeeze' => 'Squeezed — docked bars, dense rows',
                     ]],

        /*
         * THE ONE DEFAULT IN THIS SCHEMA THAT DOES NOT REPRODUCE TODAY'S PAGE,
         * and it is off on purpose because the owner asked for it in as many
         * words: "on cart there will be no footer ... by default keep the
         * footer turned off on the cart page completely."
         *
         * CART PAGE ONLY. The footer is included by layouts/store.blade.php,
         * which every page in the shop extends, so the switch must not live
         * there as a condition every page evaluates against a cart setting.
         * Instead store.blade.php asks `View::hasSection('no-footer')` — the
         * same question it already asks about 'bare' — and store/cart.blade.php
         * is the only template in the repo that declares that section. A page
         * that does not declare it cannot lose its footer, whatever this value
         * is, because nothing else reads this key.
         *
         * It is declared OUTSIDE the squeezed branch in cart.blade.php, so it
         * governs the classic page and the squeezed one alike. `layout` ships
         * `classic`, so a switch that only reached `squeeze` would reach almost
         * nobody.
         */
        'footer_on' => ['bool', 'Show the site footer on the cart page', false,
                        'Off, as asked: the cart page ships with no footer at all. THE CART PAGE ONLY — the footer still appears on the homepage, on product pages, on /shop/ and on every other page of the shop, and this switch cannot affect them. Turn it on to bring the footer back to the cart page; it applies to both the classic and the squeezed cart layouts.'],

        // ── Product rows ──
        /*
         * THE DRIVER. Everything inside a basket line — the thumbnail, the
         * brand, the name, the price and the stepper — is a calc() off this
         * number, so dragging it squeezes the row rather than leaving its
         * contents adrift in a shorter box. 96 is what a row measures today.
         */
        'row_h'      => ['range', 'Row height', 96,
                         'The single control for how dense the basket is. The thumbnail, the type sizes and the quantity stepper are all worked out from it, so they shrink with the row instead of overflowing it.',
                         ['min' => 52, 'max' => 132, 'step' => 2, 'unit' => 'px']],
        'row_font'   => ['range', 'Text size in rows', 100,
                         'A nudge multiplied INTO the size the row height already produced, never an override — so this slider and the one above can never fight, and neither can silently win.',
                         ['min' => 80, 'max' => 125, 'step' => 5, 'unit' => '%']],
        'row_bold'   => ['bool', 'Bold text in rows', true,
                         'On is what the rows do today. Off gives every line regular weight.'],
        /*
         * THE STEPPER, SIZED APART FROM THE ROW AND STILL DERIVED FROM IT.
         *
         * "also give option to control the size of the - + quanity icon etc."
         *
         * A MULTIPLIER, NOT A PIXEL SIZE, and that is the whole of the design.
         * The owner asked earlier, in as many words, that "if i adjust the
         * height of rows then inner content must adjust automatically" — so an
         * absolute size here would be a direct contradiction of a requirement
         * already met: the stepper would stay 34px while the row it sits in
         * went from 132 down to 52 and swallowed it.
         *
         * So the stylesheet keeps `calc(var(--cpg-row-h) * .30)` and this is a
         * third term on it. Both of the stepper's numbers take it — the box
         * height AND the glyph size — so the two scale together and the shape
         * of the control is invariant. That is what makes the digit safe at
         * either end of the slider: at any value the stepper is today's
         * stepper, scaled, so the digit cannot outgrow a box that grew with it
         * and cannot rattle around in one that did not shrink.
         *
         * NO WEIGHT CONTROL FOR THE DIGIT, deliberately. The stylesheet already
         * paints `.qty span` at `font-weight:var(--cpg-row-bold)`, so "Bold
         * text in rows" three lines up governs it. A second switch beside this
         * one would be two controls fighting over one declaration, and the
         * loser is a switch that does nothing.
         */
        'qty_size'   => ['range', 'Size of the − / + quantity stepper', 100,
                         'The minus, the plus and the number between them. A multiplier and not a fixed size: the stepper is worked out from the row height like everything else in the line, so it still shrinks when you shorten the row — this nudges that result up or down. The digit scales with the box, so it stays centred at every setting.',
                         ['min' => 60, 'max' => 180, 'step' => 5, 'unit' => '%']],

        // ── Recommended rail ──
        'rec_on'      => ['bool', 'Show the recommended rail', true,
                          'Renders nothing at all until products are chosen below, so this can stay on in a shop that has not picked any.'],
        'rec_heading' => ['text', 'Rail heading', 'Recommended for you', ''],
        'rec_per'     => ['range', 'Products across the screen', 45,
                          'In tenths: 45 is four and a half cards. The half card is the point — a card cut off by the screen edge is what tells a thumb there is more to the right. Card width is a fraction of the screen, so the count holds on every phone.',
                          ['min' => 25, 'max' => 65, 'step' => 5, 'unit' => '/10']],
        /*
         * ONE SWITCH BECAME TWO, AND THE OLD KEY KEPT ITS NAME.
         *
         * `rec_bold` drove the product name AND the price together. The owner
         * asked for them apart. Renaming it to `rec_name_bold` and adding
         * `rec_price_bold` would have been tidier to read and wrong to ship: a
         * shop that has already saved `cartpage_rec_bold` would find the key
         * nobody reads any more, both halves fall back to their defaults, and
         * a rail that was bold this morning is not bold this afternoon.
         *
         * So the old key stays, meaning what its label now says — the NAME —
         * and the price gets a new one that INHERITS IT until it is saved in
         * its own right. See all(). A shop that saved `rec_bold = true` renders
         * exactly what it rendered before this change, both halves bold, and
         * the first time the owner touches the price switch it stops inheriting
         * and starts being a setting of its own.
         */
        'rec_bold'    => ['bool', 'Bold product names in the rail', false,
                          'Off, as asked. The price below the name has its own switch — until you move that one it follows this.'],
        'rec_price_bold' => ['bool', 'Bold prices in the rail', false,
                             'Follows the product-name switch above until you move it. From then on it is its own setting and the two are independent.'],
        /*
         * IN HUNDREDTHS, for the same reason rec_per is in tenths: `range`
         * casts to an integer and 1.25 is not one. 125 is what the card does
         * today, and the two-line clamp below the name is derived from it —
         * height is 2 x line-height — so opening the lines out makes room for
         * them instead of cropping the second one.
         */
        'rec_lh'      => ['range', 'Line height of product names', 125,
                          'How far apart the two lines of a product name sit. The name is clamped to two lines and the box is worked out from this, so a looser setting gives the second line room rather than cutting it off.',
                          ['min' => 100, 'max' => 190, 'step' => 5, 'unit' => '%']],
        'rec_gap'     => ['range', 'Space under the product name', 2,
                          'The gap between the name and the price under it.',
                          ['min' => 0, 'max' => 16, 'step' => 1, 'unit' => 'px']],
        'rec_img_gap' => ['range', 'Space under the picture', 5,
                          'The gap between the product picture and the name under it.',
                          ['min' => 0, 'max' => 18, 'step' => 1, 'unit' => 'px']],
        'rec_add_size' => ['range', 'Size of the + button', 100,
                           'A multiplier on the one-tap add button, not a pixel size: the button is worked out from the screen width like the rest of the card, so it stays in proportion on every phone and this nudges that result.',
                           ['min' => 60, 'max' => 180, 'step' => 5, 'unit' => '%']],
        /*
         * TWO SLIDERS, NOT FOUR. Left and right are one axis and a slider that
         * crosses zero covers both of it; a pair of them would let a shop set
         * left 6 and right 4 and then work out what that means. Zero is where
         * the button sits today.
         *
         * Measured from the corner the button is pinned to, so they mirror with
         * the page rather than against it: positive across is OUT past the
         * corner of the picture, positive up is up.
         */
        'rec_add_x'   => ['range', 'Move the + left or right', 0,
                          'Zero leaves it on the corner of the picture. Positive pushes it further out past that corner, negative tucks it back inside. It is measured from the corner it sits on, so it mirrors in a right-to-left shop.',
                          ['min' => -16, 'max' => 16, 'step' => 1, 'unit' => 'px']],
        'rec_add_y'   => ['range', 'Move the + up or down', 0,
                          'Zero leaves it on the corner of the picture. Positive lifts it, negative drops it.',
                          ['min' => -16, 'max' => 16, 'step' => 1, 'unit' => 'px']],
        'rec_motion'  => ['range', 'Background movement', 1,
                          'The rail sits on a slow wash of colour. 0 holds it still. It is directly above the checkout button, so it is deliberately quiet — and it stops entirely for anyone whose phone asks for reduced motion.',
                          ['min' => 0, 'max' => 3, 'step' => 1, 'unit' => '']],
        'rec_ids'     => ['ids', 'Products in the rail', '',
                          'Chosen by hand on this screen. Order is kept.'],

        // ── Summary ──
        'sum_value_label' => ['text', 'Order value row', 'Order Value', ''],

        /*
         * EVERY CHARGE ROW IS OPTIONAL AND ALL THREE SHIP OFF, and the rule
         * that binds them is the one worth reading:
         *
         *   A SWITCHED-OFF ROW CONTRIBUTES NOTHING TO THE TOTAL EITHER.
         *
         * Not "is hidden but still charged". A charge a shopper cannot see on
         * the line above is a charge they meet for the first time at the
         * payment step, which is the most reliable way there is to lose an
         * order — and this page already carries a note about the last time
         * these two figures disagreed. So the total is the sum of exactly what
         * is printed above it, with one stated exception below.
         */
        'sum_express_on'    => ['bool', 'Express Delivery Charge row', false,
                                'Off, because this shop does not offer express delivery yet. THE EXPRESS FIGURE IS NEVER ADDED TO THE TOTAL even when this is on: the reference shows it priced beside a free Standard and a total that matches Standard, so it is an option a shopper picks at checkout, not a charge applied behind them.'],
        'sum_express'       => ['money', 'Express delivery charge', 1500, 'Quoted, not added. See the switch above.'],
        'sum_express_label' => ['text', 'Express row', 'Express Delivery Charge', ''],
        'sum_express_help'  => ['text', 'Express (i) note', 'Delivered the next working day where available. Chosen at checkout.', ''],

        /*
         * NO VAT NOTE ANYWHERE ON THIS PAGE, and no setting for one. Prices
         * here are inclusive and the checkout already says so; a second place
         * saying it is a second place that has to stay true. The switch that
         * used to control it is gone rather than left behind controlling
         * nothing, which is how this kind of deletion half-happens.
         */
        'sum_delivery_on'   => ['bool', 'Standard Delivery row', false,
                                'Off, so the cart page does not try to answer a question the checkout answers properly. While it is off, the shop\'s own free-delivery bar takes its place in the summary — the green congratulations once an order qualifies, and how much more would qualify it before that.'],
        'sum_std_label'     => ['text', 'Standard row', 'Standard Delivery Charge', ''],
        'sum_std_help'      => ['text', 'Standard (i) note', 'Free on every order. Two to four working days.', ''],
        'sum_std_free'      => ['text', 'Standard row value', 'Free', ''],
        /*
         * NOT SMALL PRINT. It is the answer to "what will this cost me", and a
         * shopper who cannot find that answer goes looking for it instead of
         * checking out — which is the exact behaviour the switch above exists
         * to end. Shown only while delivery and VAT are off, because with those
         * rows on it would be contradicting them.
         */
        /*
         * The fallback to the fallback. With the delivery row off, the summary
         * draws this shop's own free-delivery bar in its place — but a shop
         * that has not set a free-delivery threshold has no bar to draw, and a
         * summary silent about delivery is the thing all of this exists to
         * avoid. Shown only in that case.
         */
        'sum_fallback'      => ['text', 'Line shown when there is no free-delivery threshold',
                                'Delivery is calculated at checkout', ''],

        'sum_service_on'    => ['bool', 'Service Fee row', false,
                                'Off, and while it is off no fee is charged either — the row and the money are one switch, not two.'],
        /*
         * TWO KEYS AND NOT ONE, which is what stops a pricing change being made
         * by a units change. With a single stored amount, flipping the mode
         * turns AED 3.00 into 3% of the order in silence — a fee that has
         * quietly multiplied by ten on a 100-dirham basket and nobody touched
         * the number. Each mode reads its own value, so neither can be read in
         * the other's units.
         */
        'sum_service_mode'  => ['select', 'Service fee is', 'fixed', '', [
                                'fixed' => 'A fixed amount',
                                'percent' => 'A percentage of the order',
                               ]],
        'sum_service'       => ['money', 'Service fee · fixed amount', 300, ''],
        'sum_service_pct'   => ['range', 'Service fee · percentage', 2,
                                'Worked out on the order value AFTER any coupon, so a discount reduces the fee with it.',
                                ['min' => 0, 'max' => 20, 'step' => 1, 'unit' => '%']],
        'sum_service_label' => ['text', 'Service fee row', 'Service Fee', ''],
        'sum_service_help'  => ['text', 'Service fee (i) note', 'Covers card processing and packing.', ''],

        'sum_total_label' => ['text', 'Order total row', 'Order Total', ''],

        // ── Trust row ──
        'trust_on'    => ['bool', 'Secure badge and payment marks', true, ''],
        'trust_text'  => ['text', 'Secure badge wording', 'Secure checkout', ''],
        /*
         * ONE NUMBER FOR THE WHOLE ROW. The tick, its wording, the gaps and the
         * payment chips are each a calc() off this, so the row scales as a row.
         * A slider that grew the marks and left the tick and the text where
         * they were would take a line that reads as one thing and pull it into
         * three.
         */
        'trust_size'  => ['range', 'Size of the trust row', 100,
                          'The tick, the wording and the payment marks together — they are one line, so one number moves all of it.',
                          ['min' => 70, 'max' => 150, 'step' => 5, 'unit' => '%']],
        'pay_visa'    => ['bool', 'Visa', true, ''],
        'pay_mc'      => ['bool', 'Mastercard', true, ''],
        'pay_apple'   => ['bool', 'Apple Pay', true, ''],
        'pay_google'  => ['bool', 'Google Pay', true, ''],
        'pay_tabby'   => ['bool', 'tabby', true, ''],
        'pay_tamara'  => ['bool', 'tamara', true, ''],

        // ── Docked bars ──
        'addr_on'         => ['bool', 'Delivery address row', true, ''],
        'addr_h'          => ['range', 'Address row height', 40, '', ['min' => 32, 'max' => 56, 'step' => 2, 'unit' => 'px']],
        'co_h'            => ['range', 'Checkout row height', 62, '', ['min' => 50, 'max' => 86, 'step' => 2, 'unit' => 'px']],
        'bar_font'        => ['range', 'Text in both rows', 100,
                              'Everything in both docked rows is a multiple of this, so no wording can outgrow the bar it sits in.',
                              ['min' => 85, 'max' => 125, 'step' => 5, 'unit' => '%']],
        /*
         * ZERO IS TODAY'S PAGE, EXACTLY. The rows sit on the bottom edge as
         * they do now until somebody asks for space, which is what "defaults
         * reproduce today's rendering" means for a control that adds room.
         *
         * It is padding on the docked block and NOT a margin under it: the
         * block is white to its bottom edge, so the space it adds is white
         * too. A margin would show the page through underneath and read as the
         * bar failing to reach the bottom of the screen.
         *
         * It is also added into --cpg-bars, so asking for more space moves the
         * end of the page down with the bars instead of sliding them over the
         * last basket line.
         */
        'bar_pad'         => ['range', 'Space under the checkout row', 0,
                              'Extra white space below the docked rows. Zero is where they sit today, on the bottom edge. On a phone with a home indicator this is added to the space the hardware already reserves, not used instead of it.',
                              ['min' => 0, 'max' => 40, 'step' => 2, 'unit' => 'px']],
        /*
         * A MULTIPLIER ON TOP OF bar_font, never an override — the same rule
         * row_font follows against row_h. The two sliders cannot fight, and
         * neither can silently win.
         */
        /*
         * THE DELIVERY ROW ON ITS OWN — "give also option to control the font
         * size, bold etc for delivery sticky row."
         *
         * Until now `bar_font` was the only size on either docked row, and it
         * moved BOTH of them: there was no way to enlarge the delivery line
         * without enlarging the Proceed to Checkout row underneath it.
         *
         * A MULTIPLIER ON TOP OF bar_font, never an override — the rule
         * addr_btn_font states three lines up and row_font states against
         * row_h. That gives the two docked rows three honest levels rather
         * than three sliders arguing:
         *
         *     bar_font       both rows
         *     addr_font      everything in the DELIVERY row, button included
         *     addr_btn_font  the button on its own
         *
         * So the button reads 12px x bar_font x addr_font x addr_btn_font.
         * Leaving addr_font off the button would make "the delivery row" mean
         * "the delivery row except the only thing on the right of it", which
         * is the kind of almost-true control this project keeps paying for.
         *
         * The row is `min-height`, so a larger setting grows the bar rather
         * than clipping the words inside it.
         */
        'addr_font'       => ['range', 'Text in the delivery row', 100,
                              'The delivery row only — its wording, the address under it and the button on the right. Multiplied into the size "Text in both rows" already produced, so this and that slider stack rather than overrule one another, and the checkout row below is left where it is.',
                              ['min' => 80, 'max' => 140, 'step' => 5, 'unit' => '%']],
        /*
         * A SELECT AND NOT A BOOL, AND THE DEFAULT IS WHY.
         *
         * row_bold and rec_bold are bools because the only two weights their
         * text has ever had are the two a bool can carry — 600 on, 400 off.
         * This row is not like that: `.cpg-addrbar .who b` is painted at 500
         * today and `.cpg-addrbtn` at 600, both by hand, and a bool has no
         * third position to put 500 in. Shipping a bool would mean choosing
         * between "default true" (the row silently thickens to 600 on every
         * shop already using this layout) and "default false" (it thins to
         * 400). Either one breaks the promise every other default in this
         * schema keeps, to buy a control the owner asked for — and it would
         * still not reach 700, which is what "bold" means when somebody asks
         * for it.
         *
         * So: the weights themselves, with today's as the default. Storing the
         * CSS value rather than an index means cssVariables() prints what it
         * was given and `cast` refuses anything not on this list.
         */
        'addr_bold'       => ['select', 'Weight of the delivery row text', '500',
                              'The heading — "Please choose your delivery address", and "Delivering to Home" once one is picked. Medium is what the row is today. The address line under it stays regular; the button has its own setting below.', [
                                  '400' => 'Regular',
                                  '500' => 'Medium — as today',
                                  '600' => 'Semi-bold',
                                  '700' => 'Bold',
                              ]],
        'addr_btn_font'   => ['range', 'Address button text size', 100,
                              'The "+ Address" / "Change address" button only. Multiplied into the size "Text in both rows" already produced, so the two sliders stack rather than overrule one another.',
                              ['min' => 80, 'max' => 140, 'step' => 5, 'unit' => '%']],
        /*
         * The button carries its own text node and its own `font-weight:600`
         * declaration — it is a `<button class="cpg-addrbtn">` beside the
         * `.who` block, not a run inside it — so this is a real control and
         * not a second name for the one above. Checked in the markup
         * (store/cart-inner.blade.php) before it was added, because a switch
         * that moves nothing is a bug this repo has shipped before.
         *
         * Same list as addr_bold, defaulting to the 600 the button is today.
         */
        'addr_btn_bold'   => ['select', 'Weight of the address button', '600',
                              'The "+ Address" / "Change address" button only. Semi-bold is what it is today.', [
                                  '400' => 'Regular',
                                  '500' => 'Medium',
                                  '600' => 'Semi-bold — as today',
                                  '700' => 'Bold',
                              ]],
        'co_label'        => ['text', 'Checkout button wording', 'Proceed to Checkout',
                              'It shares the docked row with the item count and the total, so a longer word here is a narrower tally beside it. The button gives way first and ends in an ellipsis rather than pushing the figures off a 360px screen.'],
        'addr_heading'    => ['text', 'Address row heading', 'Please choose your delivery address', ''],
        'addr_btn_add'    => ['text', 'Address button · nothing chosen', '+ Address', ''],
        'addr_btn_change' => ['text', 'Address button · address chosen', 'Change address', ''],
        'addr_chosen'     => ['text', 'Address row · once chosen', 'Delivering to {tag}',
                              'Use {tag} where Home or Office should appear.'],

        /*
         * ── Desktop ──────────────────────────────────────────────────────
         *
         * EVERY ONE OF THESE IS READ ONLY INSIDE `@media (min-width: …)`.
         * That is not tidiness, it is the guarantee: the owner asked four
         * times in one message not to touch the phone, and a rule a phone
         * cannot match is a rule that cannot touch it. Nothing here has any
         * effect below the breakpoint, by construction rather than by care.
         *
         * They are EXTRAS, not a second copy of the schema. The owner chose
         * shared values: row height, type sizes, weights, wording and the rail
         * are the same settings on both, so there is one place to tune them
         * and no way for the two to disagree about what a word says.
         */
        'd_on'         => ['bool', 'Two-column layout on desktop', true,
                           'From the width below, the basket and the Recommended rail take the left column and everything else moves to the right. Off, a desktop gets the phone layout stretched across the screen, which is what it did before this existed.'],
        'd_min'        => ['range', 'Desktop starts at', 1024,
                           'Screens narrower than this keep the phone layout exactly as it is. 1024 leaves an iPad in portrait (768px) on the phone layout, which is the right call: two columns need room.',
                           ['min' => 768, 'max' => 1440, 'step' => 32, 'unit' => 'px']],
        'd_aside'      => ['range', 'Right column width', 380, '',
                           ['min' => 300, 'max' => 520, 'step' => 10, 'unit' => 'px']],
        'd_gap'        => ['range', 'Space between the columns', 28, '',
                           ['min' => 12, 'max' => 64, 'step' => 4, 'unit' => 'px']],
        'd_max'        => ['range', 'Widest the page will go', 1200,
                           'The two columns stop growing here and centre themselves. A basket row stretched across a 27-inch monitor is unreadable.',
                           ['min' => 960, 'max' => 1600, 'step' => 40, 'unit' => 'px']],
        /*
         * NOT the phone's fixed bar, and the difference matters. On a phone the
         * checkout row is pinned to the bottom of the SCREEN. Here the whole
         * right column simply stops when it reaches the top of the viewport and
         * travels with the page after that — which is what the owner asked for
         * when they said the sticky rows "will not be sticky in desktop, it
         * will go in the right column".
         */
        /*
         * THE RAIL'S OWN COUNT ON DESKTOP, and it needs one because the phone's
         * number is a fraction OF THE SCREEN. 4.5 cards across a 390px phone is
         * a readable card; 4.5 across a 748px column is a card with acres of
         * nothing in it. Same setting, different width, wrong answer.
         *
         * In tenths like `rec_per`, and for the same reason the owner gave for
         * that one: the half card is the point. A card cut off by the column
         * edge is what says "there is more to the right" — on a desktop there
         * is no thumb to swipe, so that cue is doing more work here, not less.
         */
        'd_rec_per'    => ['range', 'Products across the rail', 55,
                           'In tenths: 55 is five and a half cards. The half card is deliberate — it is what tells someone the rail carries on past the edge. Desktop only; the phone keeps its own count under Recommended.',
                           ['min' => 30, 'max' => 90, 'step' => 5, 'unit' => '/10']],
        /*
         * ── Desktop spacing ──────────────────────────────────────────────
         *
         * The phone's spacing is the phone's: one column the width of the
         * screen, where a card's padding IS the page's gutter. Desktop has two
         * columns, a gap between them and room around everything, so the same
         * numbers read as cramped there. These four are the ones that matter,
         * and like everything else on this tab they are read only inside the
         * min-width query.
         */
        'd_pad_x'      => ['range', 'Page side padding', 24,
                           'Space between the edge of the page and the columns.',
                           ['min' => 0, 'max' => 80, 'step' => 4, 'unit' => 'px']],
        'd_pad_y'      => ['range', 'Page top and bottom padding', 24, '',
                           ['min' => 0, 'max' => 80, 'step' => 4, 'unit' => 'px']],
        'd_sec_gap'    => ['range', 'Space between sections', 16,
                           'Between the basket and the Recommended rail on the left, and between the summary and the checkout block on the right.',
                           ['min' => 0, 'max' => 48, 'step' => 2, 'unit' => 'px']],
        'd_sec_pad'    => ['range', 'Padding inside a section', 16,
                           'Inside the basket card, the rail and the summary — the space between a section\'s border and what it holds.',
                           ['min' => 4, 'max' => 40, 'step' => 2, 'unit' => 'px']],
        /*
         * THE RAIL'S ARROWS, and they are a desktop thing specifically. A
         * phone swipes; the half card is all the cue a thumb needs. A desktop
         * has no swipe — the rail scrolls with a trackpad or a shift-wheel,
         * neither of which anybody discovers — so the half card says "there is
         * more" without offering any way to get at it. The arrows are that way.
         */
        'd_arrows'     => ['bool', 'Carousel arrows on the rail', true,
                           'A round button at each end of the Recommended rail. Desktop only; a phone swipes instead, and the half card already tells it there is more.'],
        'd_arrow_size' => ['range', 'Arrow size', 34, '',
                           ['min' => 24, 'max' => 56, 'step' => 2, 'unit' => 'px']],
        'd_dock_pad'   => ['range', 'Padding inside the checkout box', 14,
                           'The box holding the delivery address and Proceed to Checkout. It has its own number because it is the only section that is mostly a button.',
                           ['min' => 0, 'max' => 32, 'step' => 2, 'unit' => 'px']],
        /*
         * A MULTIPLIER ON TOP OF `qty_size`, never an override -- the same rule
         * `addr_font` follows against `bar_font`. The phone's stepper is sized
         * against a 96px row read at arm's length; the same control under a
         * mouse pointer on a 27-inch monitor wants a different number, and the
         * two sliders stack rather than one silently winning.
         */
        'd_qty_size'   => ['range', 'Quantity buttons on desktop', 100,
                           'Multiplied into the size "Product rows" already produced, so the phone keeps its own number and this adjusts it for desktop.',
                           ['min' => 60, 'max' => 180, 'step' => 5, 'unit' => '%']],
        'd_sticky'     => ['bool', 'Right column follows the scroll', true,
                           'The summary and Proceed to Checkout stay on screen while a long basket scrolls past. Off, they sit at the top and scroll away with the page.'],
        'd_sticky_top' => ['range', 'Gap above it when it sticks', 20, '',
                           ['min' => 0, 'max' => 80, 'step' => 4, 'unit' => 'px']],
        'd_modal_w'    => ['range', 'Address popup width', 460,
                           'On a phone the address popup rises from the bottom edge. On desktop there is no bottom edge worth rising from, so it is a panel centred over the page.',
                           ['min' => 360, 'max' => 720, 'step' => 20, 'unit' => 'px']],
        'd_modal_blur' => ['range', 'Blur behind the popup', 4, '',
                           ['min' => 0, 'max' => 14, 'step' => 1, 'unit' => 'px']],

        // ── The popup ──
        'sheet_max'        => ['range', 'New-address popup height · upright', 50,
                               'The popup that holds the FORM — the one with the fields in it. It grows from the bottom to fit what is in it and stops here. THE POPUP ITSELF NEVER SCROLLS — if a long address list would not fit, the list scrolls inside its own box and the Home / Office / Deliver here row stays put at the bottom where a thumb can reach it.',
                               ['min' => 35, 'max' => 75, 'step' => 5, 'unit' => '%']],
        /*
         * A SECOND CAP, FOR THE LIST, AND THE REASON IS NOT TIDINESS.
         *
         * Choosing is a smaller job than typing. The list popup holds a few
         * rows and one link; the form popup holds six fields, a country picker
         * and a commit row. Sized off the form's cap the list came up as a
         * half-empty white slab with a lot of nothing under the last address —
         * which reads as "something failed to load", not as "pick one". So the
         * sheet takes a `cpg-pick` class while it is showing the list, and that
         * class selects these two values instead.
         *
         * The list still SIZES TO ITS CONTENTS and only stops here. With two
         * addresses in it the popup is two addresses tall, not 38% of the
         * screen tall. Past the cap, .cpg-list scrolls inside its own box —
         * never the sheet, which is the rule the entry above states and the one
         * thing here that must not regress.
         */
        'sheet_max_list'   => ['range', 'Address-list popup height · upright', 38,
                               'The popup that holds the SAVED LIST — the one that opens first for a signed-in shopper who already has an address. Shorter than the form above it, because choosing needs less room than typing. Past this the list scrolls inside its own box; the popup itself still never does, so + Add New Address stays where a thumb can reach it.',
                               ['min' => 25, 'max' => 60, 'step' => 1, 'unit' => '%']],
        /*
         * A phone on its side has roughly half the height and twice the width,
         * so the same sheet needs a much larger share of the screen to hold the
         * same content. This is a media query and not a measurement: same
         * markup, same classes, only the grid and this cap change, which is why
         * nothing has to be observed and no script runs on rotation.
         */
        'sheet_max_land'   => ['range', 'New-address popup height · on its side', 82,
                               'Used when the phone is held horizontally. The fields go two across and the address list goes two across at the same time.',
                               ['min' => 50, 'max' => 95, 'step' => 5, 'unit' => '%']],
        'sheet_max_list_land' => ['range', 'Address-list popup height · on its side', 76,
                               'The list popup, held sideways. It keeps a cap of its own there too — the landscape rule that widens the form to two columns widens the list to two columns as well, so the list needs LESS height on its side, not the same as the form.',
                               ['min' => 40, 'max' => 95, 'step' => 1, 'unit' => '%']],
        'sheet_blur'       => ['range', 'Blur behind the popup', 3,
                               'The page behind is frozen as well as dimmed while the popup is open, and that is not decoration: without the freeze a finger that misses the sheet scrolls the cart underneath it, and the address you were about to tap has moved by the time you tap again. Zero leaves the dimming and drops the blur.',
                               ['min' => 0, 'max' => 8, 'step' => 1, 'unit' => 'px']],
        'sk_on'            => ['bool', 'Show loading placeholders', true,
                               'Grey blocks in the shape of what is coming, with a shimmer, while the shop is waiting on the server — opening the address popup, applying a coupon, changing a quantity. They appear only when a real request is in flight: a placeholder that flashes for a fortieth of a second reads as a glitch.'],
        'sheet_dense'      => ['range', 'Field height in the popup', 100, '', ['min' => 65, 'max' => 120, 'step' => 5, 'unit' => '%']],
        /*
         * PORTRAIT ONLY, and the help text says so rather than leaving it to be
         * discovered. Held sideways the popup already pairs EVERY field — the
         * landscape rule two entries up — so a second rule doing the same thing
         * there would not add anything; it would fight for the same declaration
         * and, depending on which won, put Area beside Apartment / building,
         * which is not what this switch claims to do.
         */
        'sheet_two_up'     => ['bool', 'City and Country on one row', false,
                               'Applies when the phone is held upright. Held sideways the popup already puts every field two across, so this changes nothing there.'],
        'sheet_font'       => ['range', 'Text in the popup', 100, '', ['min' => 80, 'max' => 120, 'step' => 5, 'unit' => '%']],
        'sheet_list_title' => ['text', 'Popup heading · choosing', 'Choose location', ''],
        'sheet_form_title' => ['text', 'Popup heading · adding', 'Add New Address', ''],
        /*
         * THE WAY BACK, and it needed one. Tapping "Add New Address" replaced
         * the saved list with the form, and the only way back to the list was
         * to close the sheet and open it again — which a shopper reads as
         * having lost the addresses they had.
         *
         * It appears in the FORM view only, and only when there are saved
         * addresses to go back to: on a first-ever address the form IS the
         * sheet, and a link back to an empty list is a link to nothing.
         */
        'sheet_back'       => ['text', 'Back-to-the-list link', 'Back to address',
                               'Top right of the address form, beside the close button. Shown only when there are saved addresses to go back to.'],
        'sheet_add_new'    => ['text', 'Add-another link', '+ Add New Address', ''],
        'sheet_save'       => ['text', 'Save button', 'Deliver here', ''],
        'sheet_area'       => ['text', 'Field · area', 'Area', ''],
        'sheet_area_hint'  => ['text', 'Field · area · example', 'e.g. Jumeirah Village Circle',
                               'Grey placeholder text, not a value. It disappears the moment anybody types.'],
        'sheet_apt'        => ['text', 'Field · apartment', 'Apartment / building', ''],
        'sheet_apt_hint'   => ['text', 'Field · apartment · example', 'e.g. Flat 802, Sunrise Residence', ''],
        'sheet_city'       => ['text', 'Field · city', 'City', ''],
        /*
         * City ships EMPTY with a placeholder, and only the country is filled
         * in from where the shopper is. Guessing a city from a country-level
         * signal is guessing, and a pre-filled wrong city is worse than an
         * empty one: it is a field nobody re-reads.
         */
        'sheet_city_hint'  => ['text', 'Field · city · example', 'e.g. Sharjah', ''],
        'sheet_country'    => ['text', 'Field · country', 'Country', ''],
        'sheet_geo_mark'   => ['text', 'Beside the country we guessed', 'from your location', ''],
        'sheet_geo_note'   => ['text', 'Note under the fields', 'Country set from where you are. Change it if it is wrong.',
                               'Singular, because only the country is filled in. A note that claims to have filled a field it left blank is a note nobody believes twice.'],
        'sheet_mark'       => ['text', 'Field · mark this address', 'Mark this address', ''],
        'sheet_home'       => ['text', 'Tag · home', 'Home', ''],
        'sheet_office'     => ['text', 'Tag · office', 'Office', ''],
        /*
         * Read aloud, never seen. A screen reader gets nothing at all from
         * three grey rectangles, so the placeholder carries this in a
         * visually-hidden role="status" — which is the only thing on the sheet
         * that announces the wait.
         */
        'sheet_loading'    => ['text', 'Read out while the popup is loading', 'Loading your addresses', ''],
        'sheet_failed'     => ['text', 'If the address cannot be saved', 'That could not be saved. Please try again.', ''],
        /*
         * Shown under the list, and ONLY to a shopper who is not signed in and
         * has filled all three session slots.
         *
         * The rule it states is CartAddressState::GUEST_MAX's: the fourth
         * address drops the oldest. Saying so is the whole reason the cap is a
         * drop rather than a refusal — a shopper part-way through a checkout
         * who is refused has been given a chore, and one who is told what
         * happens has been given a fact they can act on. A note that appears
         * before the cap is reached is noise, so it waits until the next save
         * will actually replace something.
         *
         * A signed-in shopper never sees it, because none of it is true for
         * them: their addresses are rows in their address book and there is no
         * cap on those.
         */
        'sheet_guest_note' => ['text', 'Under the list · not signed in', 'We keep your 3 most recent addresses on this device. Adding another replaces the oldest.',
                               'Only shown to a shopper who has not signed in and already has three. Signed-in addresses are saved to the account and are not capped.'],
    ];

    public const TABS = [
        'layout'  => ['Layout', 'Which cart page this shop serves, and whether it carries the site footer.', ['layout', 'footer_on']],
        'rows'    => ['Product rows', 'One height drives the whole line. Everything in it is worked out from that number.',
                      ['row_h', 'row_font', 'row_bold', 'qty_size']],
        'rec'     => ['Recommended', 'Full width, no rounded corners, no padding box around it.',
                      ['rec_on', 'rec_heading', 'rec_per', 'rec_bold', 'rec_price_bold',
                       'rec_lh', 'rec_gap', 'rec_img_gap',
                       'rec_add_size', 'rec_add_x', 'rec_add_y', 'rec_motion']],
        'summary' => ['Summary & trust', 'The figures under the coupon box, and the row of marks below them.',
                      ['sum_value_label',
                       'sum_express_on', 'sum_express', 'sum_express_label', 'sum_express_help',
                       'sum_delivery_on', 'sum_std_label', 'sum_std_free', 'sum_std_help',
                       'sum_fallback',
                       'sum_service_on', 'sum_service_mode', 'sum_service', 'sum_service_pct',
                       'sum_service_label', 'sum_service_help',
                       'sum_total_label',
                       'trust_on', 'trust_text', 'trust_size',
                       'pay_visa', 'pay_mc', 'pay_apple', 'pay_google', 'pay_tabby', 'pay_tamara']],
        'bars'    => ['Docked rows', 'The two rows that stay at the foot of the screen.',
                      ['addr_on', 'addr_h', 'co_h', 'bar_font', 'bar_pad',
                       'addr_font', 'addr_bold', 'addr_btn_font', 'addr_btn_bold', 'co_label',
                       'addr_heading', 'addr_btn_add', 'addr_btn_change', 'addr_chosen']],
        'desktop' => ['Desktop', 'The two-column cart page, from 1024px up. Everything here is read only on desktop — none of it can reach a phone.',
                      ['d_on', 'd_min', 'd_aside', 'd_gap', 'd_max',
                       'd_rec_per', 'd_arrows', 'd_arrow_size', 'd_qty_size',
                       'd_pad_x', 'd_pad_y', 'd_sec_gap', 'd_sec_pad', 'd_dock_pad',
                       'd_sticky', 'd_sticky_top', 'd_modal_w', 'd_modal_blur']],
        'popup'   => ['Address popup', 'Its two heights — one for the list, a taller one for the form — its density and every word in it.',
                      ['sheet_max', 'sheet_max_list', 'sheet_max_land', 'sheet_max_list_land', 'sheet_blur', 'sk_on', 'sheet_two_up', 'sheet_dense', 'sheet_font', 'sheet_list_title', 'sheet_form_title',
                       'sheet_add_new', 'sheet_back', 'sheet_save', 'sheet_area', 'sheet_apt', 'sheet_city',
                       'sheet_area_hint', 'sheet_apt_hint', 'sheet_city_hint',
                       'sheet_country', 'sheet_geo_mark', 'sheet_geo_note', 'sheet_mark', 'sheet_home', 'sheet_office',
                       'sheet_loading', 'sheet_failed', 'sheet_guest_note']],
    ];

    /** How many products the rail will hold. A cap, not a paging window. */
    public const MAX_REC = 24;

    private const PREFIX = 'cartpage_';

    public function __construct(private SettingsService $settings) {}

    /**
     * Every value, saved or default.
     *
     * ── THE ONE KEY THAT DOES NOT SIMPLY FALL BACK TO ITS DEFAULT ───────────
     *
     * `rec_price_bold` falls back to `rec_bold` instead, and only while it has
     * never been saved. The pair used to be one switch; see the note on the
     * schema entry. A static default cannot be right for both of the shops that
     * exist today — one that saved `rec_bold = true` needs a bold price and one
     * that saved false needs a regular one — so the fallback is the old key
     * rather than a constant, and every shop keeps the rail it already has.
     *
     * Done AFTER the loop and not inside it so it does not depend on the two
     * keys' order in the schema.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $saved = $this->settings->get(self::PREFIX . $key, null);
            $out[$key] = $saved === null ? $def[2] : $this->cast($key, $saved);
        }

        if ($this->settings->get(self::PREFIX . 'rec_price_bold', null) === null) {
            $out['rec_price_bold'] = $out['rec_bold'];
        }

        return $out;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (isset(self::SCHEMA[$key])) {
                $this->settings->set(self::PREFIX . $key, $this->cast($key, $value));
            }
        }
    }

    /** True when this shop serves the squeezed page. */
    public function squeezed(): bool
    {
        return $this->get('layout') === 'squeeze';
    }

    /**
     * This screen's point on ModuleSchema's six policy axes.
     *
     * `max` is 160 because that is the cap this file's own text arm has always
     * applied, and the strings under it are a heading, a label and a help line
     * rather than a paragraph. `blank` is `keep`: every text control here is
     * optional wording, and an emptied box means the shop wants nothing there
     * — `sum_express_help` cleared has to stay cleared.
     *
     * `hex` is declared and unobserved: this screen has no colour control. It
     * is written down rather than left to the default so that adding one later
     * is a decision somebody makes rather than one they inherit.
     */
    public const POLICY = [
        'max' => 160,
        'blank' => 'keep',
        'invalid' => 'default',
        'clamp' => true,
        'hex' => 'repair',
        'bool' => 'cast',
    ];

    /**
     * The two things the positional SCHEMA has no slot for.
     *
     * ── THE REAL RULE THIS SCREEN KEEPS ─────────────────────────────────────
     *
     * `money` here CLAMPS AT ZERO; the shared `money` arm REFUSES anything that
     * is not a plain run of digits. That is not a policy point — it is what
     * these two fields have always done, and every figure a shop has saved in
     * them came through it — so it survives as its own validator rather than
     * being flattened into an axis. ModuleSchema::rule() is the channel for
     * exactly that, and moneyFloor() below is the rule.
     *
     * `rec_ids` needs its cap named. ModuleSchema::castIds() defaults to 24 and
     * MAX_REC is 24, so the two agreed by coincidence; saying it here means
     * moving MAX_REC moves the cast with it instead of silently not doing so.
     *
     * The rest of what castIds() did is what ModuleSchema::castIds() does, and
     * the reasoning behind it is kept here rather than deleted with the method:
     * the value is a comma-separated STRING and not JSON because `settings`
     * holds strings and every other text field in this schema round-trips as
     * one — a JSON column here would be the only value in the file needing its
     * own decode on read. The order is the owner's and is preserved. Duplicates
     * are dropped, because a rail that shows one product twice is a mistake
     * nobody makes on purpose. And the list is capped so a paste of the whole
     * catalogue cannot put four hundred cards in a horizontal scroller.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function overrides(): array
    {
        return [
            'sum_express' => ['rule' => [self::class, 'moneyFloor']],
            'sum_service' => ['rule' => [self::class, 'moneyFloor']],
            'rec_ids' => ['options' => ['cap' => self::MAX_REC]],
        ];
    }

    /**
     * A fils figure that cannot go below zero, and is never refused.
     *
     * ── WHY THIS IS NOT `money` ─────────────────────────────────────────────
     *
     * ModuleSchema's money arm refuses "12.50" rather than storing 12, because
     * for PayShipRules' Cash-on-delivery bounds a hundredfold error that reads
     * back as a plausible number is the worse answer. This screen has never
     * done that: both figures are quoted-not-charged amounts typed into a plain
     * number box, the box has always answered `max(0, (int) $value)`, and a
     * refusal where the shop previously stored something is a behaviour change
     * on a control an owner has already used. Preserved exactly, and named.
     */
    public static function moneyFloor(mixed $raw, array $field): int
    {
        return max(0, (int) $raw);
    }

    /**
     * The normalised schema, built once per process.
     *
     * all() casts 106 keys and each cast needs the field, so normalising the
     * whole schema per key would be 11,236 field() calls for one cart render.
     * The memo lives in ModuleSchema rather than in a `static` here, so there
     * is ONE piece of process-level state with ONE registered reset instead of
     * three classes each asking StaticMemoIsolationTest to take an exemption on
     * trust — see ModuleSchema::normalised().
     *
     * @return array<string, array<string, mixed>>
     */
    private static function fields(): array
    {
        return ModuleSchema::normalised(self::class, self::SCHEMA, self::POLICY, self::overrides());
    }

    /**
     * Cast and clamp on the way in, so a bad value is refused once at save
     * rather than defended against on every render. Same shape as CartPanel.
     *
     * ONE LINE, for the reason CartPanel's own copy of this gives: these arms
     * agreed with thirteen other copies of the same arms until they did not.
     * The two that are genuinely this screen's own — the money floor and the
     * rail's cap — are declared in overrides() above rather than lost.
     */
    private function cast(string $key, mixed $value): mixed
    {
        return ModuleSchema::cast(self::fields()[$key], $value);
    }

    /** @return list<int> */
    public function recommendedIds(): array
    {
        $raw = (string) $this->get('rec_ids');

        return $raw === '' ? [] : array_map('intval', explode(',', $raw));
    }

    /**
     * The rail's products, in the order the owner arranged them.
     *
     * whereIn returns them in whatever order the database likes, so they are
     * reordered here against the stored list. A product that has since been
     * unpublished or deleted simply falls out — the rail gets shorter rather
     * than linking to a 404.
     *
     * @return \Illuminate\Support\Collection<int, Product>
     */
    public function recommended()
    {
        $ids = $this->recommendedIds();

        if ($ids === []) {
            return collect();
        }

        $found = Product::query()
            ->whereIn('id', $ids)
            ->where('status', 'publish')
            ->where('is_visible', true)
            ->get()
            ->keyBy('id');

        return collect($ids)
            ->map(fn (int $id) => $found->get($id))
            ->filter()
            ->values();
    }

    /**
     * Inline custom properties for the cart page wrapper.
     *
     * EMPTY while the shop is on the classic layout, and that is the whole of
     * the "applying this changes nothing" guarantee on the markup side: the
     * view emits no style attribute at all for an empty string, so the element
     * is byte-identical to the one that ships today.
     */
    public function cssVariables(): string
    {
        if (! $this->squeezed()) {
            return '';
        }

        $c = $this->all();

        return implode(';', [
            '--cpg-row-h:' . $c['row_h'] . 'px',
            '--cpg-fscale:' . $this->ratio($c['row_font']),
            '--cpg-row-bold:' . ($c['row_bold'] ? 600 : 400),
            // A third term on the stepper's `calc(var(--cpg-row-h) * .30)`,
            // and on its glyph size with it, so the control grows and shrinks
            // as one shape and still follows the row height.
            '--cpg-qty-s:' . $this->ratio($c['qty_size']),
            '--cpg-per:' . $this->ratio($c['rec_per'], 10),
            '--cpg-rec-bold:' . ($c['rec_bold'] ? 600 : 400),
            '--cpg-rec-price-bold:' . ($c['rec_price_bold'] ? 600 : 400),
            // Unitless: the name's box is TWO of these, so the clamp opens out
            // with the lines instead of cropping the second one.
            '--cpg-rec-lh:' . $this->ratio($c['rec_lh']),
            '--cpg-rec-gap:' . $c['rec_gap'] . 'px',
            '--cpg-rec-img-gap:' . $c['rec_img_gap'] . 'px',
            '--cpg-rec-add-s:' . $this->ratio($c['rec_add_size']),
            // Signed, and printed with its unit, because the stylesheet adds
            // them to the corner the button already sits on. A bare integer
            // would need a `* 1px` in every calc() that reads it.
            '--cpg-rec-add-x:' . $c['rec_add_x'] . 'px',
            '--cpg-rec-add-y:' . $c['rec_add_y'] . 'px',
            // 0 would divide by zero in the animation-duration calc(). The
            // keyframes are switched off by the class instead; this keeps the
            // opacity term honest without a second branch in the stylesheet.
            '--cpg-drift:' . ($c['rec_motion'] > 0 ? $c['rec_motion'] : 1),
            '--cpg-addr-h:' . $c['addr_h'] . 'px',
            '--cpg-co-h:' . $c['co_h'] . 'px',
            '--cpg-bar-f:' . $this->ratio($c['bar_font']),
            '--cpg-bar-pad:' . $c['bar_pad'] . 'px',
            // The delivery row's own size, ON TOP of --cpg-bar-f and never in
            // place of it, and its two weights. The weights are printed as
            // given: `cast` only ever stores one of the four values the
            // schema's option list names, so nothing else can reach here.
            '--cpg-addr-f:' . $this->ratio($c['addr_font']),
            '--cpg-addr-bold:' . $c['addr_bold'],
            '--cpg-addrbtn-f:' . $this->ratio($c['addr_btn_font']),
            '--cpg-addrbtn-bold:' . $c['addr_btn_bold'],
            '--cpg-trust-s:' . $this->ratio($c['trust_size']),
        ]);
    }

    /**
     * The popup's own custom properties, and the class that pairs its fields.
     *
     * SEPARATE FROM cssVariables(), and not for tidiness. The sheet is rendered
     * OUTSIDE .kbb-cartpage — see the note in store/cart.blade.php for the
     * defect that placement fixes — so it inherits nothing from that element.
     * Variables emitted there would resolve to their fallbacks here, and every
     * slider on the popup would save, report success and move nothing: the
     * exact failure this project keeps paying for.
     *
     * @return array{0: string, 1: string} [class list, style attribute]
     */
    public function sheetAttrs(): array
    {
        if (! $this->squeezed()) {
            return ['', ''];
        }

        $c = $this->all();

        $vars = implode(';', [
            '--cpg-sheet-max:' . $c['sheet_max'] . '%',
            '--cpg-sheet-max-l:' . $c['sheet_max_land'] . '%',
            // The list's own pair. Read only under .cpg-pick, which the script
            // puts on the sheet while it is showing the saved addresses.
            '--cpg-sheet-max-list:' . $c['sheet_max_list'] . '%',
            '--cpg-sheet-max-list-l:' . $c['sheet_max_list_land'] . '%',
            '--cpg-sheet-blur:' . $c['sheet_blur'] . 'px',
            '--cpg-sheet-d:' . $this->ratio($c['sheet_dense']),
            '--cpg-sheet-f:' . $this->ratio($c['sheet_font']),
            /*
             * Desktop. Every one of these is read ONLY inside the stylesheet's
             * min-width block, so emitting them costs a phone nothing but the
             * bytes — they can never be matched by a rule a phone applies.
             *
             * `d_min` is NOT here, and cannot be: a media query is resolved
             * before custom properties exist, so `@media (min-width: var(--x))`
             * is not a thing. The breakpoint is interpolated into the query
             * itself by cart-squeeze.blade.php, which is a Blade file.
             */
            '--cpg-d-per:' . $this->ratio($c['d_rec_per'], 10),
            '--cpg-d-padx:' . $c['d_pad_x'] . 'px',
            '--cpg-d-pady:' . $c['d_pad_y'] . 'px',
            '--cpg-d-secgap:' . $c['d_sec_gap'] . 'px',
            '--cpg-d-secpad:' . $c['d_sec_pad'] . 'px',
            '--cpg-d-arrow:' . $c['d_arrow_size'] . 'px',
            '--cpg-d-dockpad:' . $c['d_dock_pad'] . 'px',
            '--cpg-d-qty:' . $this->ratio($c['d_qty_size']),
            '--cpg-d-aside:' . $c['d_aside'] . 'px',
            '--cpg-d-gap:' . $c['d_gap'] . 'px',
            '--cpg-d-max:' . $c['d_max'] . 'px',
            '--cpg-d-top:' . $c['d_sticky_top'] . 'px',
            '--cpg-d-modal:' . $c['d_modal_w'] . 'px',
            '--cpg-d-blur:' . $c['d_modal_blur'] . 'px',
        ]);

        return [
            $c['sheet_two_up'] ? ' cpg-twoup' : '',
            ' style="' . e($vars) . '"',
        ];
    }

    /**
     * A percentage as a unitless CSS multiplier, printed at a fixed two
     * decimals so the string does not change with the machine's locale —
     * (string) 1.05 is "1,05" under a comma locale and that is an invalid
     * custom property value.
     */
    private function ratio(int $value, int $of = 100): string
    {
        return number_format($value / $of, 2, '.', '');
    }

    /**
     * Structural switches as classes. Leading space included, or an empty
     * string — the view interpolates it straight after `kbb-cartpage`.
     */
    public function bodyClass(): string
    {
        if (! $this->squeezed()) {
            return '';
        }

        $c = $this->all();

        $classes = array_filter([
            'cpg-squeeze',
            $c['rec_motion'] > 0 ? '' : 'cpg-still',
            $c['row_bold'] ? '' : 'cpg-rowthin',
            $c['rec_bold'] ? 'cpg-recbold' : '',
            $c['sk_on'] ? '' : 'cpg-nosk',
            /*
             * Desktop, as CLASSES rather than as custom properties, because a
             * custom property cannot switch a rule on and off — only change a
             * number inside one. `cpg-d` gates the whole two-column block and
             * `cpg-dstick` the column that follows the scroll.
             *
             * Both are harmless on a phone whatever they say: every rule that
             * reads them sits inside the stylesheet's min-width query, which a
             * phone never matches. The class is on the element from the first
             * byte either way, so nothing flashes at the breakpoint.
             */
            $c['d_on'] ? 'cpg-d' : '',
            ($c['d_on'] && $c['d_sticky']) ? 'cpg-dstick' : '',
            ($c['d_on'] && $c['d_arrows']) ? 'cpg-darr' : '',
        ]);

        return ' ' . implode(' ', $classes);
    }

    /** `style="..."`, or nothing at all. */
    public function styleAttr(): string
    {
        $vars = $this->cssVariables();

        return $vars === '' ? '' : ' style="' . e($vars) . '"';
    }

    /**
     * The service fee this basket carries, in fils.
     *
     * ONE PLACE, because the summary and the docked bar both print a total and
     * two roundings of the same number is how they end up a fil apart on the
     * same screen. The rounding happens here, once, and both read the result.
     *
     * Zero while the row is switched off. That is the rule the schema states:
     * a charge the shopper cannot see on the line above is not charged.
     *
     * @param  int  $afterDiscount  The order value once any coupon is applied —
     *   which is what the percentage is taken of, so a discount reduces the fee
     *   along with everything else rather than being quietly clawed back.
     */
    public function serviceFee(int $afterDiscount): int
    {
        $c = $this->all();

        if (! $c['sum_service_on']) {
            return 0;
        }

        if ($c['sum_service_mode'] === 'percent') {
            return (int) round($afterDiscount * (int) $c['sum_service_pct'] / 100);
        }

        return (int) $c['sum_service'];
    }

    /**
     * The payment marks the trust row prints, in the order the reference shows
     * them, filtered to the ones switched on.
     *
     * IN PHP AND NOT IN THE BLADE, for two reasons. The first is that these
     * carry COMPANY NAMES: a translated one is a different company, and
     * StorefrontStringsAreKeyedTest exists to catch English prose sitting in a
     * storefront template — six brand names interleaved with six @ifs is
     * exactly what it is meant to flag, and silencing it with six allowlist
     * entries would spend that guard's credibility on something that should not
     * be in a template at all. The second is that this is the one list that has
     * to change when what a mark looks like changes.
     *
     * WHICH IT NOW HAS. These were text chips reading "Visa", "Mastercard" and
     * so on; they are the schemes' drawn marks, and the drawings themselves
     * live in App\Support\PaymentMarkArt, whose header explains why they are
     * inline SVG on this host rather than files under public/. This method's
     * job is unchanged and deliberately so: take the six `pay_*` booleans and
     * return, in the reference's order, exactly the marks that are switched on.
     *
     * @return list<string> HTML-safe, and deliberately so — the markup is the
     *   point, and the trust row prints it with {!! !!}. Nothing user-supplied
     *   reaches this list: PaymentMarkArt::marks() is a hardcoded constant with
     *   no setting, no database read and no interpolation in it, which is what
     *   keeps an unescaped print on the cart page from being an XSS sink.
     */
    public function paymentMarks(): array
    {
        $c = $this->all();

        $out = [];

        foreach (\App\Support\PaymentMarkArt::marks() as $key => $art) {
            if ($c[$key]) {
                $out[] = $art;
            }
        }

        return $out;
    }

    /**
     * Whether anything on this shop can open the delivery-address sheet.
     *
     * TWO PAGES CAN NOW, which is the whole reason this is a method rather
     * than a reading of `squeezed()` at each call site. The squeezed cart page
     * opens it, and so does the checkout's Shipping address section — and the
     * checkout does not care which layout the cart page is set to.
     *
     * CartAddressController gates every one of its endpoints on this. Before
     * the checkout had the sheet that gate was `squeezed()`, and a shop on the
     * classic cart layout would have had a checkout picker whose every call
     * answered 404.
     *
     * The checkout's section is not switchable yet, so this is true whenever
     * the shop has a checkout — which is always. When Appearance → Checkout
     * page gains its own switch, it belongs in the `||` here and nowhere else.
     */
    public function addressPickerOn(): bool
    {
        return true;
    }

    /** The handful of strings the address sheet's script needs. */
    public function jsConfig(): array
    {
        $c = $this->all();

        return [
            'listTitle' => $c['sheet_list_title'],
            'formTitle' => $c['sheet_form_title'],
            'addNew' => $c['sheet_add_new'],
            'back' => $c['sheet_back'],
            /*
             * The breakpoint, and whether the desktop layout is on at all.
             * The close button is placed against the panel's own box, which
             * only the script can know -- the panel is centred and its height
             * depends on what is in it. A media query cannot be asked from
             * CSS here, so the number travels to the script instead.
             */
            'bp' => (int) $c['d_min'],
            'desktop' => (bool) $c['d_on'],
            'save' => $c['sheet_save'],
            'area' => $c['sheet_area'],
            'apt' => $c['sheet_apt'],
            'city' => $c['sheet_city'],
            'country' => $c['sheet_country'],
            'areaHint' => $c['sheet_area_hint'],
            'aptHint' => $c['sheet_apt_hint'],
            'cityHint' => $c['sheet_city_hint'],
            'geoMark' => $c['sheet_geo_mark'],
            'geoNote' => $c['sheet_geo_note'],
            'mark' => $c['sheet_mark'],
            'home' => $c['sheet_home'],
            'office' => $c['sheet_office'],
            'loading' => $c['sheet_loading'],
            'saveFailed' => $c['sheet_failed'],
            'guestNote' => $c['sheet_guest_note'],
            'chosen' => $c['addr_chosen'],
            'heading' => $c['addr_heading'],
            'btnAdd' => $c['addr_btn_add'],
            'btnChange' => $c['addr_btn_change'],
        ];
    }
}
