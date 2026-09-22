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
        'addr_btn_font'   => ['range', 'Address button text size', 100,
                              'The "+ Address" / "Change address" button only. Multiplied into the size "Text in both rows" already produced, so the two sliders stack rather than overrule one another.',
                              ['min' => 80, 'max' => 140, 'step' => 5, 'unit' => '%']],
        'co_label'        => ['text', 'Checkout button wording', 'Proceed to Checkout',
                              'It shares the docked row with the item count and the total, so a longer word here is a narrower tally beside it. The button gives way first and ends in an ellipsis rather than pushing the figures off a 360px screen.'],
        'addr_heading'    => ['text', 'Address row heading', 'Please choose your delivery address', ''],
        'addr_btn_add'    => ['text', 'Address button · nothing chosen', '+ Address', ''],
        'addr_btn_change' => ['text', 'Address button · address chosen', 'Change address', ''],
        'addr_chosen'     => ['text', 'Address row · once chosen', 'Delivering to {tag}',
                              'Use {tag} where Home or Office should appear.'],

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
                      ['row_h', 'row_font', 'row_bold']],
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
                      ['addr_on', 'addr_h', 'co_h', 'bar_font', 'bar_pad', 'addr_btn_font', 'co_label',
                       'addr_heading', 'addr_btn_add', 'addr_btn_change', 'addr_chosen']],
        'popup'   => ['Address popup', 'Its two heights — one for the list, a taller one for the form — its density and every word in it.',
                      ['sheet_max', 'sheet_max_list', 'sheet_max_land', 'sheet_max_list_land', 'sheet_blur', 'sk_on', 'sheet_two_up', 'sheet_dense', 'sheet_font', 'sheet_list_title', 'sheet_form_title',
                       'sheet_add_new', 'sheet_save', 'sheet_area', 'sheet_apt', 'sheet_city',
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
     * Cast and clamp on the way in, so a bad value is refused once at save
     * rather than defended against on every render. Same shape as CartPanel.
     */
    private function cast(string $key, mixed $value): mixed
    {
        $def = self::SCHEMA[$key];

        return match ($def[0]) {
            'bool' => (bool) $value,
            'range' => max((int) $def[4]['min'], min((int) $def[4]['max'], (int) $value)),
            'money' => max(0, (int) $value),
            'select' => isset($def[4][(string) $value]) ? (string) $value : (string) $def[2],
            'ids' => $this->castIds($value),
            default => mb_substr(trim((string) $value), 0, 160),
        };
    }

    /**
     * The rail's product ids, as a comma-separated string.
     *
     * Kept as a string and not JSON because `settings` holds strings and every
     * other text field in this schema round-trips as one; a JSON column here
     * would be the only value in the file needing its own decode on read.
     *
     * Order is the owner's and is preserved. Duplicates are dropped, because a
     * rail that shows one product twice is a mistake nobody makes on purpose,
     * and the list is capped so a paste of the whole catalogue cannot put four
     * hundred cards in a horizontal scroller.
     */
    private function castIds(mixed $value): string
    {
        $raw = is_array($value) ? $value : explode(',', (string) $value);

        $ids = [];

        foreach ($raw as $one) {
            $id = (int) trim((string) $one);

            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return implode(',', array_slice($ids, 0, self::MAX_REC));
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
            '--cpg-addrbtn-f:' . $this->ratio($c['addr_btn_font']),
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
     * IN PHP AND NOT IN THE BLADE, for two reasons. The first is that these are
     * COMPANY NAMES: a translated one is a different company, and
     * StorefrontStringsAreKeyedTest exists to catch English prose sitting in a
     * storefront template — six brand names interleaved with six @ifs is
     * exactly what it is meant to flag, and silencing it with six allowlist
     * entries would spend that guard's credibility on something that should not
     * be in a template at all. The second is that when the schemes' own artwork
     * replaces these text chips, this is the one list that has to change.
     *
     * NBSP between the two words of Apple Pay and Google Pay: the row is a
     * single line of very small type and "Google" on one line with "Pay" on the
     * next is not a payment mark, it is two words.
     *
     * @return list<string> HTML-safe, and deliberately so — the entities are
     *   the point. Nothing user-supplied reaches this list.
     */
    public function paymentMarks(): array
    {
        $c = $this->all();

        $marks = [
            'pay_visa' => 'Visa',
            'pay_mc' => 'Mastercard',
            'pay_apple' => 'Apple&nbsp;Pay',
            'pay_google' => 'Google&nbsp;Pay',
            'pay_tabby' => 'tabby',
            'pay_tamara' => 'tamara',
        ];

        $out = [];

        foreach ($marks as $key => $label) {
            if ($c[$key]) {
                $out[] = $label;
            }
        }

        return $out;
    }

    /** The handful of strings the address sheet's script needs. */
    public function jsConfig(): array
    {
        $c = $this->all();

        return [
            'listTitle' => $c['sheet_list_title'],
            'formTitle' => $c['sheet_form_title'],
            'addNew' => $c['sheet_add_new'],
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
