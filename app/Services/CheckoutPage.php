<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The checkout PAGE — its spacing, and nothing else.
 *
 * Appearance → Checkout page, two screens: Desktop and Mobile. The brief was
 * "the same options as we built for cart page, like squeezing, spacings,
 * paddings, control for every section of the checkout page ... please don't
 * disturb any number of sections on desktop checkout and mobile checkout
 * page." So this service moves numbers and moves nothing else: no section is
 * added, removed, reordered or renamed by anything in here, and there is no
 * layout switch. The four numbered sections are the four numbered sections at
 * every value of every control below.
 *
 * ── WHY THE DEFAULTS CHANGE ZERO BYTES ──────────────────────────────────────
 *
 * cssVariables() emits a property ONLY where the saved value differs from the
 * schema default, and styleAttr() emits no attribute at all when that list is
 * empty. A shop that has never opened this screen renders the checkout page
 * byte for byte as before — which is what tests/Feature/StorefrontEnglish-
 * UnchangedTest compares, and what a live shop mid-order requires. The
 * stylesheet carries every default a second time as the var() fallback, so the
 * page is correct with no attribute on it at all.
 *
 * ── DESKTOP AND MOBILE ARE SEPARATE VALUES, NOT ONE VALUE AND A BREAKPOINT ──
 *
 * The owner asked for two screens, and the two are genuinely independent: a
 * 16px section on a 594px column and a 16px section on a 350px one are not the
 * same design decision. So every spacing that exists on both surfaces is
 * stored twice, `d_*` and `m_*`.
 *
 * They reach the page as two SETS of custom properties, and the stylesheet —
 * not this class — picks between them:
 *
 *     .kbb-checkout        { --cop-secpad: var(--cop-d-secpad, 16px) }
 *     @media(max-width:900px){
 *       .kbb-checkout      { --cop-secpad: var(--cop-m-secpad, 16px) }
 *     }
 *
 * and every rule reads `--cop-secpad`. It has to be that way round. An inline
 * `style` attribute beats every stylesheet rule including one inside a media
 * query, so if this class emitted `--cop-secpad` directly, the mobile
 * reassignment could never win and the Mobile screen would save, report
 * success and move nothing. The inline attribute carries only the `d-` and
 * `m-` sources; the stylesheet does the choosing.
 *
 * ── THE ONE RULE THAT IS NOT A NUMBER ───────────────────────────────────────
 *
 * `d_sticky` switches `position:sticky` on the summary column off. A custom
 * property cannot do that — it can change a number inside a declaration, never
 * whether the declaration applies — so it ships as a class, `cop-nostick`, the
 * same way CartPage handles its structural switches.
 */
class CheckoutPage
{
    /**
     * key => [type, label, default, help, options]
     *
     * Types: range, bool. Nothing here is text: this screen sets spacing, and
     * the checkout's wording is translated copy that belongs in the language
     * files, not in a settings row.
     */
    public const SCHEMA = [
        // ── Desktop ──
        'd_max'       => ['range', 'Page width', 1040,
                          'How wide the whole checkout may grow on a large screen. Both columns and the gap between them live inside this. 1040 is the width the page has today.',
                          ['min' => 880, 'max' => 1440, 'step' => 20, 'unit' => 'px']],
        'd_aside'     => ['range', 'Summary column width', 380,
                          'The right-hand order summary. The form column takes whatever is left, so widening this narrows the form rather than widening the page.',
                          ['min' => 280, 'max' => 520, 'step' => 10, 'unit' => 'px']],
        'd_gap'       => ['range', 'Space between the two columns', 26,
                          'The gutter between the form and the summary.',
                          ['min' => 0, 'max' => 64, 'step' => 2, 'unit' => 'px']],
        'd_pad_x'     => ['range', 'Page padding — sides', 20,
                          'Breathing room between the page edge and the columns. On a screen wider than the page width above, this sits inside the centred block and does not move it.',
                          ['min' => 0, 'max' => 64, 'step' => 2, 'unit' => 'px']],
        'd_pad_y'     => ['range', 'Page padding — top', 22,
                          'The gap under the secure-checkout header.',
                          ['min' => 0, 'max' => 64, 'step' => 2, 'unit' => 'px']],
        'd_block_gap' => ['range', 'Space between blocks', 16,
                          'Between the heading and the coupon box, and between the coupon box and the card that holds the four numbered sections.',
                          ['min' => 0, 'max' => 48, 'step' => 2, 'unit' => 'px']],
        'd_sec_pad'   => ['range', 'Padding inside each section', 16,
                          'Applies to all four numbered sections at once — Contact, Shipping address, Delivery and Payment. Their heading bars are worked out from this number, so the bars keep meeting the card edge at every value instead of drifting away from it.',
                          ['min' => 6, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'd_aside_pad' => ['range', 'Padding inside the summary', 17,
                          'The right-hand column only.',
                          ['min' => 6, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'd_sticky'    => ['bool', 'Summary follows the scroll', true,
                          'On is what the page does today: the summary stays in view while the form scrolls past it. Off leaves it at the top of the column.'],
        'd_sticky_top' => ['range', 'Summary stops at', 18,
                           'How far below the top of the window the summary parks itself once it has caught up. Only read while the switch above is on.',
                           ['min' => 0, 'max' => 96, 'step' => 2, 'unit' => 'px']],

        /*
         * ── THE SPACE ABOVE THE HEADER, AND WHERE IT CAME FROM ───────────
         *
         * `kbb.css:240` carries a bare `section{padding:52px 0}`. The checkout
         * IS a <section>, so it inherited 52px of padding at the top and 52px
         * at the bottom — measured in Chromium at 1280 and at 390, the
         * .co-head's own top was 52 on both, with the page background showing
         * through above a header that is supposed to be the first thing on the
         * page. That is the band the owner photographed, and no slider on this
         * screen could reach it, because it was never this screen's number.
         *
         * Exactly the shape of the `footer{padding:52px 0 26px}` landmine the
         * slim footer hit, and found the same way: by measuring the rendered
         * element rather than reading the stylesheet that was supposed to own
         * it.
         *
         * THESE TWO DEFAULT TO 0 AND THAT IS A DELIBERATE CHANGE TO THE PAGE,
         * not the usual "ships at the value the page already has". The owner
         * asked for the space removed in as many words, so the fix is the
         * default and the control is how it comes back.
         */
        'd_shell_pt'  => ['range', 'Space above the header', 0,
                          'Between the top of the window and the secure-checkout bar. It was 52px and nobody chose it — a site-wide `section` rule reached in. Zero is the page with the band gone.',
                          ['min' => 0, 'max' => 80, 'step' => 2, 'unit' => 'px']],
        'd_shell_pb'  => ['range', 'Space below the page', 0,
                          'The other half of the same inherited rule, under the last block on the page. The page already carries its own bottom padding, so this was 52px of nothing.',
                          ['min' => 0, 'max' => 80, 'step' => 2, 'unit' => 'px']],

        /*
         * ── "GO BACK TO CART" ─────────────────────────────────────
         *
         * ONE SIZE SLIDER AND NOT FOUR. The link's five looks each carry their
         * own padding pair, chosen against their own border and fill — a ghost
         * rect is 9/14, a solid pill 10/17. Four separate sliders would let the
         * owner set a padding the look was never drawn for and would have to be
         * re-set every time the look changed. A single share multiplies
         * whichever pair the chosen look uses, so 80% is that look, smaller.
         *
         * The icon is its own control because it is the one part a share of the
         * text does not size correctly: the glyph reads as too big long before
         * the text does.
         */
        'd_tocart_size' => ['range', 'Back-to-cart button size', 100,
                            'Scales the text and the padding of the "Go back to cart" link together, whichever of the five looks is chosen on Store → Ecommerce. 100% is the size it is today.',
                            ['min' => 60, 'max' => 140, 'step' => 5, 'unit' => '%']],
        'd_tocart_icon' => ['range', 'Back-to-cart arrow size', 100,
                            'The chevron alone. Separate from the slider above because a smaller button usually wants a proportionally smaller glyph, not the same one.',
                            ['min' => 50, 'max' => 160, 'step' => 5, 'unit' => '%']],
        'd_tocart_r'    => ['range', 'Back-to-cart corner radius', 10,
                            'Read only by the rounded-rectangle look. The pill looks are round by definition and the icon-only look is a circle.',
                            ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],

        /*
         * ── WHERE THE HEADER BAND SITS ───────────────────────────────
         *
         * `.co-head .in` is `max-width:<the width control>; margin:0 auto`, so
         * a band narrower than the window is CENTRED. On a desktop that is
         * right and is what everyone means by a content width.
         *
         * On a phone it is the bug the owner photographed. Measured at 390px
         * with the mobile header width at 280: the band ran 55…335, the logo
         * started at 75, and the page's own "Back to shop" started at 20 — a
         * 55px notch on the left of the logo and nothing matching it anywhere
         * else on the page. Worse, the badge inside it overflowed to 370, so
         * the right-hand side looked flush while the left did not, which is
         * exactly what the photograph shows.
         *
         * So the phone's default is now "line it up with the page" and
         * `center` is the option — see the CSS, where the class REMOVES the
         * centring on a desktop and RESTORES it on a phone. That way the
         * default of both keys emits no class at all.
         */
        'd_head_align' => ['select', 'Where the header band sits', 'center',
                           'Only matters once the width above is narrower than the window.', [
                               'center' => 'Centred — what it does today',
                               'page'   => 'Lined up with the page below it',
                           ]],

        // ── Mobile ──
        'm_pad_x'     => ['range', 'Page padding — sides', 20,
                          'The gap between the screen edge and every block on the page. The checkout is one column on a phone, so this is the page margin.',
                          ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'm_pad_y'     => ['range', 'Page padding — top', 22,
                          'The gap under the secure-checkout header.',
                          ['min' => 0, 'max' => 48, 'step' => 1, 'unit' => 'px']],
        'm_gap'       => ['range', 'Space between stacked blocks', 14,
                          'The one column is a grid, and this is the gap between the summary at the top and the form below it.',
                          ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'm_block_gap' => ['range', 'Space between blocks', 16,
                          'Between the heading and the coupon box, between the coupon box and the card of sections, and above the Place order box at the foot.',
                          ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'm_sec_pad'   => ['range', 'Padding inside each section', 16,
                          'All four numbered sections, and the Place order box below them. Their heading bars follow it.',
                          ['min' => 6, 'max' => 32, 'step' => 1, 'unit' => 'px']],
        'm_aside_pad' => ['range', 'Padding inside the summary', 14,
                          'The order-summary card at the top of the phone page.',
                          ['min' => 6, 'max' => 32, 'step' => 1, 'unit' => 'px']],
        'm_shell_pt'  => ['range', 'Space above the header', 0,
                          'The phone half of the inherited `section{padding:52px 0}` — measured at 390px, the header sat 52px down the page for the same reason it did on a desktop. Zero is the band gone.',
                          ['min' => 0, 'max' => 80, 'step' => 2, 'unit' => 'px']],
        'm_shell_pb'  => ['range', 'Space below the page', 0,
                          'And the bottom half of it, under the Place order box.',
                          ['min' => 0, 'max' => 80, 'step' => 2, 'unit' => 'px']],
        'm_tocart_size' => ['range', 'Back-to-cart button size', 100,
                            'The same link on a phone, its own value. 100% is today.',
                            ['min' => 60, 'max' => 140, 'step' => 5, 'unit' => '%']],
        'm_tocart_icon' => ['range', 'Back-to-cart arrow size', 100,
                            'The chevron alone, on a phone.',
                            ['min' => 50, 'max' => 160, 'step' => 5, 'unit' => '%']],
        'm_tocart_r'    => ['range', 'Back-to-cart corner radius', 10,
                            'The rounded-rectangle look only.',
                            ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        /*
         * 44 IS A FLOOR SOMEBODY PUT THERE ON PURPOSE, which is why it is a
         * slider that starts there rather than a number in the stylesheet.
         * Lane BM measured "Go back to cart" at 38.8px tall on a phone and
         * raised it to 44 to clear the touch-target minimum. Shrinking the
         * button with the slider above will not take it back under 44 unless
         * this one is lowered too, and lowering it is the owner's call to make
         * with that sentence in front of him.
         */
        'm_tocart_min'  => ['range', 'Back-to-cart tap height', 44,
                            'The smallest the link may be on a phone, whatever the size slider says. 44px is the touch-target minimum; below it the link is harder to hit than it should be.',
                            ['min' => 0, 'max' => 60, 'step' => 2, 'unit' => 'px']],

        /*
         * ── THE FLOATING PLACE ORDER BUTTON ─────────────────────────
         *
         * The owner's words: "the Place order should float only when on page
         * place order disappear by scroll... and the floating place order
         * button will hid immidiately as soon as the on page place order
         * button appears."
         *
         * That is an INTERSECTION QUESTION, not a scroll position, and it is
         * answered by IntersectionObserver rather than by a scroll handler
         * reading offsets. Two reasons, and both are house rules: a scroll
         * handler that calls getBoundingClientRect on every frame is the
         * layout-measuring JavaScript this project does not write, and it would
         * also be WRONG — the on-page button's position moves as sections
         * expand, so a remembered pixel would be stale the moment an address is
         * chosen.
         *
         * "always" is kept because it is what `mobile_sticky_bar` has always
         * done on Store → Ecommerce, and a shop that turned that on chose a bar
         * that is always there. Turning it into a disappearing one under them
         * would be this screen quietly overruling that one.
         */
        'm_head_align' => ['select', 'Where the header band sits', 'page',
                           'Lined up is the default on a phone: a narrowed band used to centre itself, which put a notch to the left of the logo that nothing else on the page matched.', [
                               'page'   => 'Lined up with the page below it',
                               'center' => 'Centred in the window',
                           ]],

        /*
         * ── THE REVIEWS LINE ABOVE THE ORDER SUMMARY ──────────────────
         *
         * THE WORDING IS HIS. THE NUMBERS ARE NOT, AND CANNOT BE.
         *
         * This line used to be `reassure_rating_text`, a free-text setting
         * whose shipped default read "4.8 · loved by 2,300+ UAE customers" on
         * a shop with no reviews at all — an invented figure printed at the
         * moment of payment. It was removed for that reason and the line has
         * been computed from approved reviews ever since.
         *
         * Giving the wording back without giving the figure back is the whole
         * design here: `{rating}` and `{count}` are substituted from the
         * reviews table, and a stored template containing ANY OTHER DIGIT is
         * refused and falls back to the default — see cast(). So an owner can
         * write "loved by UAE shoppers" around the number and cannot write the
         * number. RatingsTellTheTruthTest is still the reason.
         */
        'rating_on'   => ['bool', 'Show the reviews line', true,
                          'The stars and the score above the order summary. It already draws nothing until there are enough approved reviews to mean anything, so this is for a shop that never wants it.'],
        'rating_text' => ['text', 'Reviews line wording', '{rating} from {count} reviews',
                          'Use {rating} for the score and {count} for how many. Both are read from your approved reviews. Any OTHER digit is refused and the shipped wording is used instead — a number typed here would be a claim nobody earned, printed beside the pay button.'],
        'rating_min'  => ['range', 'Hide it below this many reviews', 5,
                          'Five is the shipped floor. One five-star review is true and is not a rating, which is why there is a floor at all.',
                          ['min' => 1, 'max' => 50, 'step' => 1, 'unit' => ' reviews']],

        'm_float'     => ['select', 'Floating Place order button', 'smart',
                          'A phone-only bar across the bottom carrying the total and a Place order button. Nothing on this row reaches a desktop.', [
                              'off'    => 'Never — only the button in the page',
                              'smart'  => 'Only once the in-page button scrolls away',
                              'always' => 'Always, from the moment the page loads',
                          ]],

        /*
         * ── THE ORDER-SUMMARY PRODUCT ROWS ─────────────────────────────────
         *
         * "on the checkout page (desktop and mobile both) i can not control
         * the products rows squeeze, font size, bold, spacing, quantity icons
         * sizes etc. i want the same options which we built for mobile cart
         * page."
         *
         * The same six knobs Appearance -> Cart page -> Product rows offers,
         * pointed at the rows the CHECKOUT draws — `.ci` in
         * partials/checkout/summary-items.blade.php. Nothing here reaches the
         * cart page: these are `checkoutpage_*` settings and every rule that
         * reads them is scoped under `.kbb-checkout`.
         *
         * WHY `row_h` IS THE PICTURE AND NOT A HEIGHT. The cart's squeezed row
         * has an explicit height and derives its contents from it. A checkout
         * summary line does not: `.ci` is a flex row with vertical padding, and
         * its height is whichever is taller, the square picture or the text
         * stack beside it. At every default the picture wins — 54px against
         * about 46px of name, stepper and margins — so moving this moves the
         * row, which is what "squeeze" means here. Below about 46px the text
         * stack takes over, and the two multipliers below are what shrinks
         * that. Stating it rather than inventing a height that the markup does
         * not have: an invented driver is a slider that stops working part way
         * along and cannot say why.
         *
         * THE TWO MULTIPLIERS ARE MULTIPLIERS, not pixel sizes, for the reason
         * CartPage states at length: an absolute size cannot follow the row it
         * sits in, so it stays put while everything around it moves.
         */
        'd_row_h'     => ['range', 'Picture size', 54,
                          'The square thumbnail on each order-summary line, and with it the height of the line: the row is as tall as the picture until the picture is smaller than the name and stepper beside it. Drag this to squeeze the summary.',
                          ['min' => 32, 'max' => 88, 'step' => 2, 'unit' => 'px']],
        /*
         * FOUR SIDES, because the owner asked for four: "Space above and below
         * each row, options i need from all sides, top left, right bottom."
         *
         * The line was `padding: <one value> 0` — top and bottom together, and
         * nothing at the sides. The left and right defaults are 0 for that
         * reason: 0 is what the row has today, so a shop that never opens this
         * tab keeps the row it already has.
         *
         * The old single key is gone, and the migration beside this package
         * copies whatever was saved in it into the top and bottom halves, so a
         * shop that had already set it keeps its spacing to the pixel.
         */

        /*
         * ── THE REMOVE BUTTON, THE LIST'S TOP EDGE, AND THE TAB STRIP ──────
         *
         * `rm_size` was asked for by measurement: "give the rows cross icon
         * icon size control too, currently it is i think 44 x 44". It is, on a
         * phone — a deliberate touch target, and the single biggest reason a
         * phone's summary line is 79px tall against the desktop's 65. A
         * multiplier rather than a pixel size, like every other control on
         * this tab, so the box and the glyph scale as one shape. Taking it
         * below 100% shrinks a touch target: 80% is still 35px, which is
         * past the 24px minimum, and the slider says so.
         *
         * `items_pt` IS A BUG FIX WITH A CONTROL ON IT. The quantity badge on
         * each thumbnail is pinned at `top:-7px`, so on the FIRST line it
         * reaches above the list — measured in Chromium, the badge's top edge
         * is 7px above `.co-items` — and on a phone `.panels` has
         * `overflow:hidden`, which cuts it. That is the "first is cuting from
         * top side little bit". 8px clears the badge and its 2px white ring;
         * 0 restores exactly what shipped, clipping and all.
         */
        'd_rm_size'  => ['range', 'Remove button size', 100,
                          'The × on each order-summary line. It is 44×44 on a phone -- a full touch target, and the biggest single reason a phone line is taller than a desktop one. A multiplier, so the box and the glyph stay one shape.',
                          ['min' => 50, 'max' => 140, 'step' => 5, 'unit' => '%']],
        'd_items_pt' => ['range', 'Space above the first line', 8,
                          'The quantity badge sits 7px above its picture, so on the first line it reaches above the list and gets cut. 8 clears it; 0 is the flush -- and clipped -- edge this page shipped with.',
                          ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'd_tab_min'  => ['range', 'Tab height', 0,
                          'The floor under "Order summary" and "Browsed". Zero on a desktop, where the strip is sized by its padding alone.',
                          ['min' => 0, 'max' => 60, 'step' => 1, 'unit' => 'px']],
        'd_tab_pad'  => ['range', 'Tab padding', 9,
                          'Inside each of the two tabs, above and below the words. Together with the height above, this is how tall the strip is.',
                          ['min' => 2, 'max' => 20, 'step' => 1, 'unit' => 'px']],
        'd_tab_font' => ['range', 'Tab text size', 100,
                          'The words in both tabs, and the count badge beside "Browsed", which scales with them.',
                          ['min' => 70, 'max' => 140, 'step' => 5, 'unit' => '%']],
        'd_tab_gap'  => ['range', 'Space below the tabs', 14,
                          'Between the tab strip and the first line of the right-hand summary.',
                          ['min' => 0, 'max' => 32, 'step' => 1, 'unit' => 'px']],
        'd_row_pt'    => ['range', 'Row padding — top', 10,
                          'Above each order-summary line. The first line in the list keeps its flush top edge at every value, so the summary never opens with a gap.',
                          ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'd_row_pr'    => ['range', 'Row padding — right', 0,
                          'Between the line price and the edge of the summary card. 0 is what the row has today.',
                          ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'd_row_pb'    => ['range', 'Row padding — bottom', 10,
                          'Below each line, above the divider.',
                          ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'd_row_pl'    => ['range', 'Row padding — left', 0,
                          'Between the edge of the card and the picture. 0 is what the row has today.',
                          ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'd_row_gap'   => ['range', 'Space between the picture and the text', 11,
                          'Only inside the line. The price stays pinned to the far edge.',
                          ['min' => 2, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'd_row_font'  => ['range', 'Text size in rows', 100,
                          'A nudge multiplied INTO the sizes the rows already use — the product name, the quantity figure and the line price together — never an override, so this and the picture size cannot fight and neither can silently win.',
                          ['min' => 80, 'max' => 130, 'step' => 5, 'unit' => '%']],
        'd_row_bold'  => ['bool', 'Bold text in rows', true,
                          'On is what the summary does today: a semibold name and a bold price. Off gives both a lighter weight without changing their sizes.'],
        'd_qty_size'  => ['range', 'Quantity stepper size', 100,
                          'The − and + buttons on each line, and the figure between them. A multiplier, so the control keeps its shape at every value instead of a bigger glyph rattling around in the same box.',
                          ['min' => 70, 'max' => 160, 'step' => 5, 'unit' => '%']],

        'm_row_h'     => ['range', 'Picture size', 54,
                          'The square thumbnail on each order-summary line inside the collapsible summary card at the top of the phone page, and with it the height of the line.',
                          ['min' => 32, 'max' => 88, 'step' => 2, 'unit' => 'px']],

        /*
         * ── THE REMOVE BUTTON, THE LIST'S TOP EDGE, AND THE TAB STRIP ──────
         *
         * `rm_size` was asked for by measurement: "give the rows cross icon
         * icon size control too, currently it is i think 44 x 44". It is, on a
         * phone — a deliberate touch target, and the single biggest reason a
         * phone's summary line is 79px tall against the desktop's 65. A
         * multiplier rather than a pixel size, like every other control on
         * this tab, so the box and the glyph scale as one shape. Taking it
         * below 100% shrinks a touch target: 80% is still 35px, which is
         * past the 24px minimum, and the slider says so.
         *
         * `items_pt` IS A BUG FIX WITH A CONTROL ON IT. The quantity badge on
         * each thumbnail is pinned at `top:-7px`, so on the FIRST line it
         * reaches above the list — measured in Chromium, the badge's top edge
         * is 7px above `.co-items` — and on a phone `.panels` has
         * `overflow:hidden`, which cuts it. That is the "first is cuting from
         * top side little bit". 8px clears the badge and its 2px white ring;
         * 0 restores exactly what shipped, clipping and all.
         */
        'm_rm_size'  => ['range', 'Remove button size', 100,
                          'The × on each order-summary line. It is 44×44 on a phone -- a full touch target, and the biggest single reason a phone line is taller than a desktop one. A multiplier, so the box and the glyph stay one shape.',
                          ['min' => 50, 'max' => 140, 'step' => 5, 'unit' => '%']],
        'm_items_pt' => ['range', 'Space above the first line', 8,
                          'The quantity badge sits 7px above its picture, so on the first line it reaches above the list and gets cut. 8 clears it; 0 is the flush -- and clipped -- edge this page shipped with.',
                          ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'm_tab_min'  => ['range', 'Tab height', 44,
                          'The floor under "Order summary" and "Browsed". It ships at 44px -- a full touch target -- which is also what makes the strip 52px tall on a phone.',
                          ['min' => 0, 'max' => 60, 'step' => 1, 'unit' => 'px']],
        'm_tab_pad'  => ['range', 'Tab padding', 9,
                          'Inside each of the two tabs, above and below the words. Together with the height above, this is how tall the strip is.',
                          ['min' => 2, 'max' => 20, 'step' => 1, 'unit' => 'px']],
        'm_tab_font' => ['range', 'Tab text size', 100,
                          'The words in both tabs, and the count badge beside "Browsed", which scales with them.',
                          ['min' => 70, 'max' => 140, 'step' => 5, 'unit' => '%']],
        'm_tab_gap'  => ['range', 'Space below the tabs', 14,
                          'Between the tab strip and the first line of the summary card at the top of the phone page.',
                          ['min' => 0, 'max' => 32, 'step' => 1, 'unit' => 'px']],
        'm_row_pt'    => ['range', 'Row padding — top', 10,
                          'Above each line. The summary card shows about 148px before "View full summary", so squeezing this and the bottom fits more lines into that peek.',
                          ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'm_row_pr'    => ['range', 'Row padding — right', 0,
                          'Between the line price and the edge of the card. 0 is what the row has today.',
                          ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'm_row_pb'    => ['range', 'Row padding — bottom', 10,
                          'Below each line, above the divider.',
                          ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'm_row_pl'    => ['range', 'Row padding — left', 0,
                          'Between the edge of the card and the picture. 0 is what the row has today.',
                          ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'm_row_gap'   => ['range', 'Space between the picture and the text', 11,
                          'Only inside the line. The price stays pinned to the far edge.',
                          ['min' => 2, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'm_row_font'  => ['range', 'Text size in rows', 100,
                          'A nudge multiplied INTO the sizes the rows already use — the product name, the quantity figure and the line price together — never an override.',
                          ['min' => 80, 'max' => 130, 'step' => 5, 'unit' => '%']],
        'm_row_bold'  => ['bool', 'Bold text in rows', true,
                          'On is what the summary does today: a semibold name and a bold price. Off gives both a lighter weight without changing their sizes.'],
        'm_qty_size'  => ['range', 'Quantity stepper size', 100,
                          'The − and + buttons on each line, and the figure between them. Their 44px touch target is set separately and is not reduced by this, so a smaller stepper is still as easy to hit.',
                          ['min' => 70, 'max' => 160, 'step' => 5, 'unit' => '%']],

        /*
         * ── THE SECURE-CHECKOUT HEADER ─────────────────────────────────────
         *
         * "we don't have any controls of checkout header (for desktop and
         * mobile both) give full control of height, spacing, positioning."
         *
         * The bar is three things — a logo, a lock and the words "Secure
         * checkout" — inside `.co-head .in`, and its height is entirely its
         * padding plus the taller of the two. So height IS the padding and the
         * logo size together, and they are both here rather than a single
         * "height" that would have to fight whatever the logo does.
         *
         * `head_sticky` is the positioning. It ships ON, which is what the bar
         * does today, and it is a CLASS rather than a property because a custom
         * property cannot decide whether `position:sticky` applies.
         */
        'd_head_pad_y' => ['range', 'Header padding — top and bottom', 14,
                           'Together with the logo size below, this is the height of the bar: the bar is its padding plus the taller of the logo and the secure badge.',
                           ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'd_head_pad_x' => ['range', 'Header padding — sides', 20,
                           'How far the logo sits from the left edge of the header block, and the badge from the right.',
                           ['min' => 0, 'max' => 64, 'step' => 2, 'unit' => 'px']],
        'd_head_max'   => ['range', 'Header width', 1040,
                           'The band the logo and badge are laid out in — NOT the white bar, which always runs the full width of the window. Below the window width it pulls the logo and badge towards the middle; above it, it stops having anything left to give.',
                           ['min' => 600, 'max' => 1600, 'step' => 20, 'unit' => 'px']],
        'd_head_logo'  => ['range', 'Logo size', 20,
                           'The "K-BeautyBliss" wordmark. The scroll offset that stops a focused field hiding under the bar is worked out from this and the padding above, so it follows them instead of staying at the number it was written with.',
                           ['min' => 12, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'd_head_badge' => ['range', 'Secure badge size', 12,
                           'The lock and the words beside it. The lock scales with the words.',
                           ['min' => 8, 'max' => 22, 'step' => 1, 'unit' => 'px']],
        'd_head_sticky' => ['bool', 'Header stays at the top while scrolling', true,
                            'On is what the page does today. Off lets the bar scroll away with the rest of the page, which gives a phone back about 50px of screen.'],

        'm_head_pad_y' => ['range', 'Header padding — top and bottom', 14,
                           'Together with the logo size below, this is the height of the bar.',
                           ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'm_head_pad_x' => ['range', 'Header padding — sides', 20,
                           'How far the logo sits from the screen edge, and the badge from the other one. Left where it is, it follows the page\'s own side padding so the logo lines up with everything below it; move it and it wins.',
                           ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        /*
         * THE MINIMUM IS 280 AND NOT 880, and that is the whole fix.
         *
         * It shipped at 880-1600, which is wider than every phone ever made,
         * so the slider moved and the header did not — reported, correctly, as
         * "the width of header on checkout page not working properly". A band
         * narrower than the screen is the only thing this control can do on a
         * phone, so that is the range it now offers.
         */
        'm_head_max'   => ['range', 'Header width', 1040,
                           'The band the logo and badge sit in. Anything at or above the phone\'s own width leaves them against the screen edges, which is what 1040 does — bring it down below about 360 to pull them in towards the middle.',
                           ['min' => 280, 'max' => 1040, 'step' => 10, 'unit' => 'px']],
        'm_head_logo'  => ['range', 'Logo size', 20,
                           'The "K-BeautyBliss" wordmark.',
                           ['min' => 12, 'max' => 34, 'step' => 1, 'unit' => 'px']],
        'm_head_badge' => ['range', 'Secure badge size', 12,
                           'The lock and the words beside it. On a narrow phone this is the first thing that crowds the logo, so it is worth a look at 360px.',
                           ['min' => 8, 'max' => 20, 'step' => 1, 'unit' => 'px']],
        'm_head_sticky' => ['bool', 'Header stays at the top while scrolling', true,
                            'On is what the page does today. Off gives a phone back the bar\'s height of screen, at the cost of the lock not being visible while someone types their card details.'],

        /*
         * ── TEXT SIZES ─────────────────────────────────────────────────────
         *
         * "give control of each section text sizes etc. so i can adjust
         * everything as per need."
         *
         * Six sizes per surface, each one the size of a ROLE rather than of a
         * particular sentence: every section heading moves together, every
         * field label moves together. A per-sentence control would be sixty
         * sliders and a page nobody could keep consistent.
         *
         * THE ONE FLOOR THAT IS NOT NEGOTIABLE is the field text on a phone.
         * iOS Safari zooms the page when a field smaller than 16px takes
         * focus, and it does not zoom back out — the shopper is left on a
         * checkout wider than their screen, mid-order. The stylesheet already
         * forces 16px below 820px for exactly that reason, and it keeps doing
         * so through a max(), so this slider can raise the phone's field text
         * and cannot lower it past the floor. The slider says so.
         */
        'd_t_title'  => ['range', 'Page title size', 100,
                         'The word "Checkout" at the top of the form column — 19px today.',
                         ['min' => 70, 'max' => 180, 'step' => 5, 'unit' => '%']],
        'd_t_lead'   => ['range', 'Page subtitle size', 100,
                         'The line under the title — 12.5px today.',
                         ['min' => 70, 'max' => 160, 'step' => 5, 'unit' => '%']],
        'd_t_h2'     => ['range', 'Section heading size', 100,
                         'The four numbered bars — Contact, Shipping address, Delivery, Payment — 13px today. The round number in each one scales with it, so the bar keeps its shape.',
                         ['min' => 70, 'max' => 160, 'step' => 5, 'unit' => '%']],
        'd_t_label'  => ['range', 'Field label size', 100,
                         'The small label above each field — 11px today — and with it the "(optional)" note and the wording of the tick-box lines.',
                         ['min' => 70, 'max' => 170, 'step' => 5, 'unit' => '%']],
        'd_t_input'  => ['range', 'Field text size', 100,
                         'What the shopper types, and the placeholder before they do — 14px today.',
                         ['min' => 80, 'max' => 150, 'step' => 5, 'unit' => '%']],
        /*
         * THE PLACEHOLDER, APART FROM THE FIELD, because the owner asked for
         * "the font size of placeholder of fields i need more small option".
         *
         * It multiplies INTO the field size rather than replacing it, so the
         * two sliders can never fight. And it is the one text on a phone that
         * can go small safely: iOS decides whether to zoom from the INPUT's
         * font-size, never the placeholder's, so a 70% placeholder inside a
         * 16px field zooms nothing.
         */
        'd_t_ph'     => ['range', 'Placeholder size', 100,
                         'The grey hint inside an empty field — "First and last name", "you@email.com". A share of the field text above, so it follows that slider as well as this one.',
                         ['min' => 60, 'max' => 120, 'step' => 5, 'unit' => '%']],
        'd_t_trust'  => ['range', 'Trust line size', 100,
                         'The rating line and the authenticity line in the block under Payment — 12px today.',
                         ['min' => 70, 'max' => 160, 'step' => 5, 'unit' => '%']],

        'm_t_title'  => ['range', 'Page title size', 100,
                         'The word "Checkout" at the top of the page — 19px today.',
                         ['min' => 70, 'max' => 170, 'step' => 5, 'unit' => '%']],
        'm_t_lead'   => ['range', 'Page subtitle size', 100,
                         'The line under the title — 12.5px today.',
                         ['min' => 70, 'max' => 160, 'step' => 5, 'unit' => '%']],
        'm_t_h2'     => ['range', 'Section heading size', 100,
                         'The four numbered bars — 13px today. The round number in each one scales with it.',
                         ['min' => 70, 'max' => 160, 'step' => 5, 'unit' => '%']],
        'm_t_label'  => ['range', 'Field label size', 100,
                         'The small label above each field — 11px today — the "(optional)" note and the tick-box lines.',
                         ['min' => 70, 'max' => 170, 'step' => 5, 'unit' => '%']],
        'm_t_input'  => ['range', 'Field text size', 100,
                         'What the shopper types — 16px on a phone today. Below 100% this does nothing while the floor below is on, which is how it ships; turn the floor off and the whole range works.',
                         ['min' => 70, 'max' => 150, 'step' => 5, 'unit' => '%']],
        /*
         * THE FLOOR IS A CHOICE NOW, BECAUSE IT WAS ASKED TO BE.
         *
         * "the field text size in mobile checkout page is zero, but still it's
         * showing large font size, i need to control more to decrease." It
         * was: the stylesheet clamped it with a max(16px, ...), so the slider
         * went to its minimum and the field did not move. That is a control
         * that lies, whatever its help text says, and the help text is not
         * where a shop finds out.
         *
         * The clamp is not superstition. iOS Safari zooms the page in when a
         * field smaller than 16px takes focus, and it does NOT zoom back out:
         * the shopper is left on a checkout wider than their screen, part-way
         * through paying, with no way back. So it ships ON, and turning it off
         * says what it costs rather than being a silent slider.
         *
         * The other two ways to make a field look smaller cost nothing at all,
         * and the help says so: the placeholder has its own size (60% up) and
         * iOS never measures it, and the field's BOX is set by padding rather
         * than by its text.
         */
        'm_t_input_floor' => ['bool', 'Keep the 16px floor that stops iOS zooming', true,
                              'On, as it ships. Off lets the slider above go all the way down — and lets iPhone Safari zoom the checkout in the moment a field is tapped, with no way for the shopper to zoom back out. If the aim is a smaller-looking field, the placeholder size below and the field padding on the Layout tab both get there without that.'],
        'm_t_ph'     => ['range', 'Placeholder size', 100,
                         'The grey hint inside an empty field. SAFE TO TAKE BELOW 100% on a phone: iOS decides whether to zoom the page from the field\'s own size, never the placeholder\'s, so this can go small while the field itself stays at the 16px that keeps the page still.',
                         ['min' => 60, 'max' => 120, 'step' => 5, 'unit' => '%']],
        'm_t_trust'  => ['range', 'Trust line size', 100,
                         'The rating line and the authenticity line in the block under Payment — 12px today.',
                         ['min' => 70, 'max' => 160, 'step' => 5, 'unit' => '%']],

        /*
         * ── THE ADDRESS CUE ────────────────────────────────────────────────
         *
         * "i need some home, office icons and arrows towards the + address,
         * but that must be super nice attractive and animated, so the user eye
         * will go directly there and understand that they need to address the
         * address."
         *
         * It draws ONLY on the empty state — the row that says "Please choose
         * your delivery address". The moment an address is picked that row is
         * replaced by the chosen one and every one of these settings stops
         * applying, because an animation pointing at a job already done is
         * noise on the one page where noise costs money.
         *
         * Not per surface. It is one cue with one job and two copies of it
         * would be two things to keep saying the same thing.
         *
         * IT IS ALL CSS. No script runs, nothing is measured, and the whole
         * thing is inside a `prefers-reduced-motion` guard — a shopper who has
         * asked their device for less movement gets the icons, the arrow and
         * the colour, and none of the movement.
         */
        /*
         * DELIVERY NOTES, OFF. The owner's instruction, in as many words:
         * "turn off the delivery notes by detault on checkout page."
         *
         * THE ONE DEFAULT IN THIS SCHEMA THAT DOES NOT REPRODUCE TODAY'S PAGE,
         * and it is off on purpose because it was asked for. Everything else
         * here ships as the number the page already had; this one removes a
         * field, so it is called out rather than buried.
         *
         * The field is not rendered at all rather than hidden, so nothing
         * posts `customer_note` while it is off. place() has always treated it
         * as nullable and writes null when it is absent, so an order placed
         * with the field off is the same order it would have been with the box
         * left empty — no validation branch, no second code path.
         *
         * Orders already carrying a note are untouched: this decides what the
         * checkout draws, not what the table holds.
         */
        /*
         * THE ORDER-UPDATES OPT-IN, AND WHY UNTICKED IS BOTH WHAT WAS ASKED
         * FOR AND THE RIGHT DEFAULT.
         *
         * "turn off by default the send order updates option and give control
         * on backend." It shipped pre-ticked -- `old('billing_kbb_whatsapp',
         * true)` -- so every order carried a marketing consent the shopper had
         * not actually given, only failed to withdraw. Unticked is the honest
         * shape of a consent box, and it is now a setting rather than a
         * literal, so a shop that wants it pre-ticked can have it back without
         * a release.
         *
         * SECOND DEFAULT THAT CHANGES TODAY'S PAGE, with `notes_on`. Both were
         * asked for in as many words; everything else in this schema still
         * reproduces the page exactly.
         */
        'optin_on'       => ['bool', 'Show the order-updates opt-in', true,
                             'The tick under Contact — "Send me order updates and new offers". Off removes the row entirely, and nothing is recorded either way.'],
        'optin_checked'  => ['bool', 'Start it ticked', false,
                             'Off, as asked: the shopper ticks it themselves. On restores the old behaviour, where the box arrived already ticked — which records a consent nobody actively gave, so it is worth being deliberate about.'],

        'notes_on'       => ['bool', 'Show the delivery-notes box', false,
                             'Off, as asked: the "Delivery instructions, a landmark, a preferred time" box under Delivery is not drawn. Turn it on to bring it back. Notes already saved on past orders are unaffected either way.'],

        /*
         * ── THE PLACEHOLDER'S LOOK, APART FROM ITS SIZE ────────────────────
         *
         * "the placeholder text of the fields option is still not working,
         * give proper control to adjust the font size etc."
         *
         * The size control works -- verified in Chromium at 60%: an 8.4px hint
         * inside a 14px desktop field and 9.6px inside the phone's 16px one,
         * painted, not just computed. It is per surface, so it lives on
         * Desktop -> Text sizes AND Mobile -> Text sizes, and moving one does
         * nothing to the other; that is the likeliest reason a shop sees no
         * change.
         *
         * These three are the "etc": weight, colour and slant. They are SHARED
         * rather than per surface because they are not sizing -- a hint that
         * is grey on a desktop and italic on a phone is two designs, and
         * nobody asked for two.
         *
         * The defaults are what the browser paints today: weight 400, the
         * shop's own muted grey (measured: rgb(117,117,117)), upright.
         */
        'ph_weight'      => ['select', 'Placeholder weight', '400',
                             'How heavy the grey hint inside an empty field is. Lighter reads as a hint; heavier reads as a value somebody typed, which is the thing a placeholder must never look like.', [
                                 '300' => 'Light',
                                 '400' => 'Regular — what it is today',
                                 '500' => 'Medium',
                                 '600' => 'Semibold',
                             ]],
        'ph_tone'        => ['select', 'Placeholder colour', 'muted',
                             'Against a white field. "Faint" is the quietest that still passes as readable text; anything quieter stops being a hint and starts being invisible, which is why there is no fainter option.', [
                                 'muted' => 'Grey — what it is today',
                                 'faint' => 'Faint grey',
                                 'ink'   => 'Near-black',
                                 'pink'  => 'The shop\'s pink',
                             ]],
        'ph_italic'      => ['bool', 'Placeholder in italics', false,
                             'Slanted, which separates the hint from what the shopper types more strongly than colour alone. Off is how it reads today.'],

        'addr_cue'       => ['bool', 'Point the shopper at the address button', true,
                             'The icon pair, the moving arrow and the halo on the button, on the "choose your delivery address" row. Off leaves that row exactly as it was.'],
        'addr_cue_icons' => ['bool', 'Show the home and office icons', true,
                             'A pair of overlapping marks at the start of the row — the same two the address popup uses — so the row reads as "an address goes here" before a word of it is read.'],
        'addr_cue_arrow' => ['bool', 'Show the arrow pointing at the button', true,
                             'A short dashed run and an arrowhead that travels along it towards Add address.'],
        'addr_cue_pulse' => ['bool', 'Halo around the button', true,
                             'A soft ring that grows and fades out from the button, roughly twice as slow as the arrow so the two read as one movement rather than a flicker.'],
        'addr_cue_speed' => ['range', 'Animation speed', 100,
                             'Higher is faster. Everything moving in the cue is timed off this one number, so they keep step with each other at every value.',
                             ['min' => 40, 'max' => 200, 'step' => 5, 'unit' => '%']],
        'addr_cue_size'  => ['range', 'Icon size', 100,
                             'The home and office marks and the arrow together.',
                             ['min' => 70, 'max' => 150, 'step' => 5, 'unit' => '%']],

        /*
         * ── THE AUTHENTICITY TICK ──────────────────────────────────────────
         *
         * "100% authentic K-beauty, this line icon i need continues animated
         * like a box and inside tick should becomre green and check."
         *
         * The mark is already a shield with a tick inside it. This animates
         * the tick being DRAWN — stroke-dashoffset from its own length to
         * zero — and the shield filling green behind it, then resting, then
         * going again. Drawing it rather than fading it in is the difference
         * between "a tick appeared" and "it was just checked", which is the
         * thing the owner asked for.
         *
         * Same reduced-motion guard: the tick is simply there, in green.
         */
        'trust_tick'       => ['bool', 'Animate the authenticity tick', true,
                               'The shield under Payment draws its tick and fills green, pauses, and does it again.'],
        'trust_tick_speed' => ['range', 'Tick speed', 100,
                               'Higher is faster. The pause between runs scales with it, so a slower tick also waits longer rather than drawing slowly and restarting at once.',
                               ['min' => 40, 'max' => 200, 'step' => 5, 'unit' => '%']],
    ];

    /**
     * Four tabs and not two, because layout and product rows are two jobs and
     * one list of sixteen sliders is a list nobody reads to the end of. The
     * `_rows` pair is deliberately the same six controls in the same order on
     * both surfaces, so the two can be compared by looking at them.
     */
    public const TABS = [
        'desktop'      => ['Desktop · Layout', 'The two-column checkout, from 901px up. Nothing on this tab can reach a phone.',
                           ['d_shell_pt', 'd_shell_pb',
                            'd_max', 'd_aside', 'd_gap', 'd_pad_x', 'd_pad_y',
                            'd_block_gap', 'd_sec_pad', 'd_aside_pad',
                            'd_sticky', 'd_sticky_top']],
        'desktop_head' => ['Desktop · Header', 'The secure-checkout bar across the top. Its height is its padding plus the taller of the logo and the badge, so those are the controls rather than a "height" that would fight them.',
                           ['d_head_pad_y', 'd_head_pad_x', 'd_head_max', 'd_head_align', 'd_head_logo', 'd_head_badge', 'd_head_sticky']],
        'desktop_type' => ['Desktop · Text sizes', 'Every size is a share of the size that role already uses, so 100% is the page exactly as it is and the roles keep their relationship to each other.',
                           ['d_t_title', 'd_t_lead', 'd_t_h2', 'd_t_label', 'd_t_input', 'd_t_ph', 'd_t_trust']],
        'desktop_rows' => ['Desktop · Product rows', 'The lines in the order summary on the right — picture, name, stepper and price. Nothing on this tab can reach a phone, and nothing on it can reach the cart page.',
                           ['d_items_pt', 'd_row_h', 'd_row_pt', 'd_row_pr', 'd_row_pb', 'd_row_pl',
                            'd_row_gap', 'd_row_font', 'd_row_bold', 'd_qty_size', 'd_rm_size',
                            'd_tab_min', 'd_tab_pad', 'd_tab_font', 'd_tab_gap']],
        'mobile'       => ['Mobile · Layout', 'The single-column checkout, at 900px and below. Nothing on this tab can reach a desktop.',
                           ['m_shell_pt', 'm_shell_pb',
                            'm_pad_x', 'm_pad_y', 'm_gap', 'm_block_gap', 'm_sec_pad', 'm_aside_pad',
                            'm_float']],
        'mobile_head'  => ['Mobile · Header', 'The same bar on a phone. Worth a look at 360px: the badge is the first thing that crowds the logo.',
                           ['m_head_pad_y', 'm_head_pad_x', 'm_head_max', 'm_head_align', 'm_head_logo', 'm_head_badge', 'm_head_sticky']],
        'mobile_type'  => ['Mobile · Text sizes', 'Same six roles, their own values. The field-text floor is the one control here that will not go below where it is, and it says why.',
                           ['m_t_title', 'm_t_lead', 'm_t_h2', 'm_t_label',
                            'm_t_input', 'm_t_input_floor', 'm_t_ph', 'm_t_trust']],
        'mobile_rows'  => ['Mobile · Product rows', 'The lines inside the summary card at the top of the phone page. Nothing on this tab can reach a desktop, and nothing on it can reach the cart page.',
                           ['m_items_pt', 'm_row_h', 'm_row_pt', 'm_row_pr', 'm_row_pb', 'm_row_pl',
                            'm_row_gap', 'm_row_font', 'm_row_bold', 'm_qty_size', 'm_rm_size',
                            'm_tab_min', 'm_tab_pad', 'm_tab_font', 'm_tab_gap']],
        /* ITS OWN TAB BECAUSE THE OWNER COULD NOT FIND IT. These four shipped
           at the foot of the two Layout tabs, under ten spacing sliders, and
           the report was "Back to Cart button controls i couldn't found".
           A control nobody can find is a control that does not exist. */
        'tocart'       => ['Back to cart', 'The "Go back to cart" link at the top of the page — both surfaces on one tab. Which of the five LOOKS it wears is chosen on Store → Ecommerce → Checkout → Mobile layout; everything about its SIZE is here.',
                           ['d_tocart_size', 'd_tocart_icon', 'd_tocart_r',
                            'm_tocart_size', 'm_tocart_icon', 'm_tocart_r', 'm_tocart_min']],
        'trust'        => ['Trust & reviews', 'The stars and score above the order summary. The wording is yours; the figures are read from your approved reviews and cannot be typed. The authenticity lines — "100% authentic" beside the pay button and "100% authentic K-beauty" above the summary — are words about the business rather than about this page, so they live together with the rest of them on Store → Business Details → Claims.',
                           ['rating_on', 'rating_text', 'rating_min']],
        'cues'         => ['Fields & attention', 'Which optional fields the page draws, and the two moving things on it: the cue that points at the address button while no address is chosen, and the authenticity tick under Payment. One set of values for both surfaces.',
                           ['optin_on', 'optin_checked', 'notes_on',
                            'ph_weight', 'ph_tone', 'ph_italic',
                            'addr_cue', 'addr_cue_icons', 'addr_cue_arrow', 'addr_cue_pulse', 'addr_cue_speed', 'addr_cue_size',
                            'trust_tick', 'trust_tick_speed']],
    ];

    /**
     * The keys the Squeeze preset drives to their minimum.
     *
     * SCOPED TO THE OPEN TAB, not to the whole screen. It used to walk every
     * tab, and the owner's report was exact: "when i click squeezed, it applies
     * on all tabs all checkout page settings, which is not correct." A preset
     * that reaches past the screen changes numbers nobody can see, so the only
     * way to learn what it did is to visit nine tabs. The list below is still
     * the whole set of keys that MAY be squeezed; the screen intersects it with
     * the fields of the tab in front of you.
     *
     * "make overal option Squeeze and upon selection all rows squeezed and
     * font sizes etc to minimum set."
     *
     * A LIST, NOT A MODE. A stored "squeezed" flag that overrode the sliders
     * would leave every slider on the screen showing a number the page was not
     * using — the screen would lie, and the owner would drag one and watch
     * nothing move. So the preset WRITES THE SLIDERS: press it, every value
     * below moves to its own minimum in front of you, and Save stores exactly
     * what is on the screen. Nudging one afterwards works normally, and
     * "Back to defaults" is its twin rather than a second mode to be in.
     *
     * Both surfaces at once, because the owner asked for an overall one. What
     * is NOT here is as deliberate: page width, header width, the animation
     * speeds and the switches. Squeezing a layout does not mean narrowing the
     * page it sits on, and a preset that silently turned animations off would
     * be a second thing happening under one button.
     */
    public const SQUEEZE = [
        'd_pad_x', 'd_pad_y', 'd_gap', 'd_block_gap', 'd_sec_pad', 'd_aside_pad',
        'd_row_h', 'd_row_pt', 'd_row_pb', 'd_row_gap', 'd_row_font', 'd_qty_size',
        'd_rm_size', 'd_tab_min', 'd_tab_pad', 'd_tab_font', 'd_tab_gap',
        'd_t_title', 'd_t_lead', 'd_t_h2', 'd_t_label', 'd_t_input', 'd_t_ph', 'd_t_trust',
        'd_head_pad_y', 'd_head_pad_x', 'd_head_logo', 'd_head_badge',
        'm_pad_x', 'm_pad_y', 'm_gap', 'm_block_gap', 'm_sec_pad', 'm_aside_pad',
        'm_row_h', 'm_row_pt', 'm_row_pb', 'm_row_gap', 'm_row_font', 'm_qty_size',
        'm_rm_size', 'm_tab_min', 'm_tab_pad', 'm_tab_font', 'm_tab_gap',
        'm_t_title', 'm_t_lead', 'm_t_h2', 'm_t_label', 'm_t_input', 'm_t_ph', 'm_t_trust',
        'm_head_pad_y', 'm_head_pad_x', 'm_head_logo', 'm_head_badge',
    ];

    /**
     * Where the stylesheet switches from the desktop set to the mobile one.
     *
     * Stated here so a test can assert the two agree. It is NOT a setting: the
     * query lives in a built stylesheet and a custom property cannot be read
     * by a media query — the query is resolved before custom properties
     * exist — so a control for it would have to re-emit the whole mobile block
     * from Blade. Not worth it for a number nobody has asked to move.
     */
    public const MOBILE_MAX = 900;

    private const PREFIX = 'checkoutpage_';

    /**
     * key => the custom property it is emitted as.
     *
     * The `d-`/`m-` halves of the name are what the stylesheet's media query
     * chooses between; see the class note. `d_sticky` is absent because it is
     * a class, not a property.
     */
    /**
     * key => [property, value per option].
     *
     * A select whose values are a LOOKUP rather than the stored string: a
     * colour is printed into a declaration, and the stored value is the key of
     * a list this class controls. cast() already refuses anything that is not
     * one of a select's own options, so this map cannot be reached with a
     * value it does not have — but it is a map and not an interpolation for
     * the same reason PaymentMarkArt is a constant.
     */
    private const OPTION_VARS = [
        /*
         * THE SHARED NAME DIRECTLY, and these two are the only properties in
         * this class that do that. Every other token is emitted as a `-d-` or
         * `-m-` source because the stylesheet's media query has to be able to
         * choose between them, and an inline attribute would beat it. These
         * two have nothing to choose between: one value, both surfaces. So the
         * shared name is what is written, and no rule reassigns it.
         */
        'ph_weight' => ['--cop-phw', ['300' => '300', '400' => '400', '500' => '500', '600' => '600']],
        'ph_tone' => ['--cop-phc', [
            'muted' => '#757575',
            'faint' => '#A9A2A6',
            'ink' => '#4A4348',
            'pink' => '#C13A5E',
        ]],
    ];

    private const VARS = [
        'd_max'        => '--cop-d-max',
        'd_aside'      => '--cop-d-aside',
        'd_gap'        => '--cop-d-gap',
        'd_pad_x'      => '--cop-d-padx',
        'd_pad_y'      => '--cop-d-pady',
        'd_block_gap'  => '--cop-d-block',
        'd_sec_pad'    => '--cop-d-secpad',
        'd_aside_pad'  => '--cop-d-asidepad',
        'd_sticky_top' => '--cop-d-sticktop',
        'd_shell_pt'   => '--cop-d-shellpt',
        'd_shell_pb'   => '--cop-d-shellpb',
        'd_tocart_r'   => '--cop-d-tocartr',
        'm_shell_pt'   => '--cop-m-shellpt',
        'm_shell_pb'   => '--cop-m-shellpb',
        'm_tocart_r'   => '--cop-m-tocartr',
        'm_tocart_min' => '--cop-m-tocartmin',
        'm_pad_x'      => '--cop-m-padx',
        'm_pad_y'      => '--cop-m-pady',
        'm_gap'        => '--cop-m-gap',
        'm_block_gap'  => '--cop-m-block',
        'm_sec_pad'    => '--cop-m-secpad',
        'm_aside_pad'  => '--cop-m-asidepad',
        'd_row_h'      => '--cop-d-rowh',
        /*
         * `rowp-t` and not `rowpt`: `--cop-d-rowpb` is already the row's PRICE
         * BOLD weight, and a padding that differed from it by one character
         * would be a collision waiting for somebody to mistype.
         */
        'd_items_pt'   => '--cop-d-itemspt',
        'd_tab_min'    => '--cop-d-tabmin',
        'd_tab_pad'    => '--cop-d-tabpad',
        'd_tab_gap'    => '--cop-d-tabgap',
        'm_items_pt'   => '--cop-m-itemspt',
        'm_tab_min'    => '--cop-m-tabmin',
        'm_tab_pad'    => '--cop-m-tabpad',
        'm_tab_gap'    => '--cop-m-tabgap',
        'd_row_pt'     => '--cop-d-rowp-t',
        'd_row_pr'     => '--cop-d-rowp-r',
        'd_row_pb'     => '--cop-d-rowp-b',
        'd_row_pl'     => '--cop-d-rowp-l',
        'd_row_gap'    => '--cop-d-rowgap',
        'm_row_h'      => '--cop-m-rowh',
        'm_row_pt'     => '--cop-m-rowp-t',
        'm_row_pr'     => '--cop-m-rowp-r',
        'm_row_pb'     => '--cop-m-rowp-b',
        'm_row_pl'     => '--cop-m-rowp-l',
        'm_row_gap'    => '--cop-m-rowgap',
        'd_head_pad_y' => '--cop-d-headpady',
        'd_head_pad_x' => '--cop-d-headpadx',
        'd_head_max'   => '--cop-d-headmax',
        'd_head_logo'  => '--cop-d-headlogo',
        'd_head_badge' => '--cop-d-headbadge',
        'm_head_pad_y' => '--cop-m-headpady',
        'm_head_pad_x' => '--cop-m-headpadx',
        'm_head_max'   => '--cop-m-headmax',
        'm_head_logo'  => '--cop-m-headlogo',
        'm_head_badge' => '--cop-m-headbadge',
    ];

    /**
     * key => the custom property it is emitted as, as a UNITLESS RATIO.
     *
     * Stored as a percentage because that is what the slider shows and what a
     * settings row can be read back as; emitted as `1.15` because the
     * stylesheet multiplies it into a px size and `calc(12px * 115%)` is not a
     * length.
     */
    private const RATIO_VARS = [
        'd_row_font' => '--cop-d-rowf',
        'd_qty_size' => '--cop-d-qtys',
        'm_row_font' => '--cop-m-rowf',
        'm_qty_size' => '--cop-m-qtys',
        'd_t_title'  => '--cop-d-ttitle',
        'd_t_lead'   => '--cop-d-tlead',
        'd_t_h2'     => '--cop-d-th2',
        'd_t_label'  => '--cop-d-tlabel',
        'd_t_input'  => '--cop-d-tinput',
        'd_rm_size'  => '--cop-d-rms',
        'd_tab_font' => '--cop-d-tabf',
        'm_rm_size'  => '--cop-m-rms',
        'm_tab_font' => '--cop-m-tabf',
        'd_t_ph'     => '--cop-d-tph',
        'd_t_trust'  => '--cop-d-ttrust',
        'm_t_title'  => '--cop-m-ttitle',
        'm_t_lead'   => '--cop-m-tlead',
        'm_t_h2'     => '--cop-m-th2',
        'm_t_label'  => '--cop-m-tlabel',
        'm_t_input'  => '--cop-m-tinput',
        'm_t_ph'     => '--cop-m-tph',
        'm_t_trust'  => '--cop-m-ttrust',
        'd_tocart_size' => '--cop-d-tocarts',
        'd_tocart_icon' => '--cop-d-tocartic',
        'm_tocart_size' => '--cop-m-tocarts',
        'm_tocart_icon' => '--cop-m-tocartic',
        'addr_cue_size' => '--cop-cue-s',
        /*
         * SPEED IS EMITTED AS ITS RECIPROCAL, because what the stylesheet
         * needs is a DURATION and the owner is setting a SPEED. 200% fast has
         * to become 0.5x the duration, not 2x, or every slider on this tab
         * would run backwards. inverse() does that; these two are the only
         * keys that take it.
         */
    ];

    /** key => property, emitted as the INVERSE ratio — a speed becoming a duration factor. */
    private const INVERSE_VARS = [
        'addr_cue_speed'   => '--cop-cue-t',
        'trust_tick_speed' => '--cop-tick-t',
    ];

    /**
     * key => [class when the value is FALSE, class when TRUE].
     *
     * An empty string means "no class in that state". Every one of these is a
     * switch a custom property cannot express: whether a declaration applies at
     * all, rather than what number is inside it.
     */
    private const CLASS_VARS = [
        'd_sticky'       => ['cop-nostick', ''],
        'd_head_sticky'  => ['cop-dhead-static', ''],
        'm_head_sticky'  => ['cop-mhead-static', ''],
        /* The one class here that is NOT simply "this switch is off": the
           stylesheet's floor is the default, so the class is what removes it. */
        'm_t_input_floor' => ['cop-nofloor', ''],
        'addr_cue'       => ['cop-nocue', ''],
        'addr_cue_icons' => ['cop-nocue-ic', ''],
        'addr_cue_arrow' => ['cop-nocue-ar', ''],
        'addr_cue_pulse' => ['cop-nocue-pu', ''],
        'trust_tick'     => ['cop-notick', ''],
        /* The one switch here whose ON state is the class, because upright is
           the default and italic is the departure. */
        'ph_italic'      => ['', 'cop-phit'],
    ];

    /**
     * The RESOLVED floating-bar mode as a class.
     *
     * `m_float` cannot be a custom property: what it chooses is whether a whole
     * block of declarations applies, not a number inside one. And it cannot be
     * a CLASS_VARS row, because that map has exactly two states and this has
     * three.
     *
     * Read through floatBar() rather than off the stored value, so the class on
     * the element and the decision the view made about whether to RENDER the
     * bar are the same answer from the same method. Two reads of the same
     * setting is how a bar ends up in the markup with the rule that shows it
     * switched off.
     *
     * 'smart' maps to no class at all: it is the default, and the default has
     * to leave `class="kbb-checkout"` alone.
     */
    private const FLOAT_CLASSES = [
        'off'    => 'cop-nofloat',
        'smart'  => '',
        'always' => 'cop-floatalways',
    ];

    /**
     * key => [stored value => class], for the selects whose answer is
     * structural and whose DEFAULT is the empty string.
     *
     * The two header alignments point opposite ways on purpose. A desktop band
     * is centred by default and `cop-dhead-page` is what un-centres it; a phone
     * band is lined up with the page by default and `cop-mhead-center` is what
     * centres it. Written that way round, both defaults emit nothing and the
     * element on a shop that has never opened this screen is still
     * `class="kbb-checkout"`.
     */
    private const ALIGN_CLASSES = [
        'd_head_align' => ['center' => '', 'page' => 'cop-dhead-page'],
        'm_head_align' => ['page' => '', 'center' => 'cop-mhead-center'],
    ];

    /**
     * key => [property for the name, property for the price], and the two
     * weights each takes.
     *
     * One switch, two weights, because the summary line has always drawn the
     * name semibold and the price bold and a single weight would flatten a
     * distinction nobody asked to lose.
     */
    private const WEIGHT_VARS = [
        'd_row_bold' => ['--cop-d-rowb', '--cop-d-rowpb'],
        'm_row_bold' => ['--cop-m-rowb', '--cop-m-rowpb'],
    ];

    /** [name weight, price weight] for bold on, and for bold off. */
    private const WEIGHTS_ON = [600, 700];

    private const WEIGHTS_OFF = [400, 500];

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, mixed> */
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
     * A stored value, forced back inside its schema.
     *
     * The clamp is here and not only in the browser, because the browser is
     * not the only thing that can POST to the endpoint and a slider that
     * accepts 9999 is a page with no layout left.
     */
    private function cast(string $key, mixed $value): mixed
    {
        $def = self::SCHEMA[$key] ?? null;

        if ($def === null) {
            return $value;
        }

        return match ($def[0]) {
            'bool' => (bool) $value,
            'range' => max(
                (int) $def[4]['min'],
                min((int) $def[4]['max'], (int) $value),
            ),
            /*
             * A SELECT MAY ONLY EVER HOLD ONE OF ITS OWN OPTIONS.
             *
             * This arm was missing until `ph_tone` and `ph_weight` became the
             * first selects in this schema, so they fell through to
             * `default => $value` and stored whatever arrived. Both are read
             * back through OPTION_VARS to build a CSS declaration: a stored
             * value the map has no key for is a missing-index error at best,
             * and the reason it must not be reachable at worst.
             *
             * Anything unrecognised falls back to the shipped default rather
             * than being stored, which is the same rule SlimFooter::cast()
             * states for the same reason.
             */
            'select' => isset($def[4][(string) $value]) ? (string) $value : (string) $def[2],
            /*
             * A TEMPLATE MAY CARRY NO DIGIT OF ITS OWN.
             *
             * `rating_text` is the only text key on this screen and it sits
             * beside the pay button. Its two tokens are replaced with figures
             * read from the reviews table; a digit anywhere else in it is a
             * figure the owner typed, which is precisely the invented number
             * `reassure_rating_text` was removed for. Refused rather than
             * stripped: silently deleting the "4.8" somebody typed leaves them
             * reading a line they did not write and did not agree to.
             *
             * Length is bounded too. This is stored, read on every checkout
             * render and printed; there is no wording for it that needs 200
             * characters.
             */
            'text' => self::cleanTemplate((string) $value, (string) $def[2]),
            default => $value,
        };
    }

    /**
     * A wording template, or the shipped one.
     *
     * Digits are counted AFTER the two tokens are taken out, so `{rating}` and
     * `{count}` cost nothing and `4.8` is refused. Trimmed and length-bounded
     * first, so a 10,000-character value cannot be stored and then measured.
     */
    private static function cleanTemplate(string $value, string $default): string
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 120) {
            return $default;
        }

        $withoutTokens = str_replace(['{rating}', '{count}'], '', $value);

        return preg_match('/\d/', $withoutTokens) === 1 ? $default : $value;
    }

    /**
     * The reviews line as the checkout should print it, or null.
     *
     * Null covers three cases that are all "say nothing" and must not be told
     * apart by the caller: the switch is off, there are not enough approved
     * reviews, or the reviews table has nothing real in it at all.
     */
    public function ratingLine(): ?string
    {
        $c = $this->all();

        if (! $c['rating_on']) {
            return null;
        }

        $summary = \App\Support\StoreRating::summary();

        if ($summary === null || $summary['total'] < (int) $c['rating_min']) {
            return null;
        }

        return str_replace(
            ['{rating}', '{count}'],
            [number_format($summary['average'], 1), number_format($summary['total'])],
            (string) $c['rating_text'],
        );
    }

    /**
     * Only what the owner has actually moved.
     *
     * A value equal to its default is left out entirely, so the attribute is
     * absent on a shop that has never opened the screen and carries exactly
     * the properties that differ on one that has. That is what keeps the
     * default render byte-identical, and it also keeps the attribute short
     * enough to read in a page source when something looks wrong.
     */
    public function cssVariables(): string
    {
        $c = $this->all();
        $out = [];

        foreach (self::VARS as $key => $prop) {
            if ($c[$key] !== self::SCHEMA[$key][2]) {
                $out[] = $prop.':'.$c[$key].'px';
            }
        }

        foreach (self::RATIO_VARS as $key => $prop) {
            if ($c[$key] !== self::SCHEMA[$key][2]) {
                $out[] = $prop.':'.$this->ratio((int) $c[$key]);
            }
        }

        foreach (self::OPTION_VARS as $key => [$prop, $map]) {
            if ($c[$key] !== self::SCHEMA[$key][2]) {
                $out[] = $prop.':'.$map[(string) $c[$key]];
            }
        }

        foreach (self::INVERSE_VARS as $key => $prop) {
            if ($c[$key] !== self::SCHEMA[$key][2]) {
                $out[] = $prop.':'.$this->inverse((int) $c[$key]);
            }
        }

        foreach (self::WEIGHT_VARS as $key => [$nameProp, $priceProp]) {
            if ($c[$key] !== self::SCHEMA[$key][2]) {
                [$name, $price] = $c[$key] ? self::WEIGHTS_ON : self::WEIGHTS_OFF;
                $out[] = $nameProp.':'.$name;
                $out[] = $priceProp.':'.$price;
            }
        }

        return implode(';', $out);
    }

    /**
     * A stored percentage as the unitless factor the stylesheet multiplies by.
     *
     * Trailing zeroes trimmed, so 100 would print `1` rather than `1.00` — not
     * that it ever reaches here, since a value equal to its default is left
     * out entirely.
     */
    private function ratio(int $percent): string
    {
        return rtrim(rtrim(number_format($percent / 100, 2, '.', ''), '0'), '.');
    }

    /**
     * A stored SPEED as the DURATION factor the stylesheet multiplies by.
     *
     * 200% fast is half the duration, not twice it. Clamped away from zero:
     * the schema's own minimum is 40, but a settings row hand-edited to 0 would
     * divide by it, and an animation-duration of `infinity` is a page that
     * never paints the thing it was asked to draw attention to.
     */
    private function inverse(int $percent): string
    {
        return rtrim(rtrim(number_format(100 / max(1, $percent), 3, '.', ''), '0'), '.');
    }

    /** `style="..."`, or nothing at all. */
    public function styleAttr(): string
    {
        $vars = $this->cssVariables();

        return $vars === '' ? '' : ' style="'.e($vars).'"';
    }

    /**
     * Structural switches as classes. Leading space included, or an empty
     * string — the view interpolates it straight after `kbb-checkout`.
     */
    public function bodyClass(): string
    {
        $c = $this->all();
        $classes = [];

        foreach (self::CLASS_VARS as $key => [$whenFalse, $whenTrue]) {
            $class = $c[$key] ? $whenTrue : $whenFalse;

            if ($class !== '') {
                $classes[] = $class;
            }
        }

        $float = self::FLOAT_CLASSES[$this->floatBar()] ?? '';

        if ($float !== '') {
            $classes[] = $float;
        }

        foreach (self::ALIGN_CLASSES as $key => $map) {
            $class = $map[(string) $c[$key]] ?? '';

            if ($class !== '') {
                $classes[] = $class;
            }
        }

        /*
         * Every class here is an OFF switch — `cop-floatalways` included, in
         * the sense that matters: the DEFAULT of every row above maps to the
         * empty string, so a shop that has never opened this screen renders
         * `class="kbb-checkout"` and nothing else, exactly as it did before the
         * screen existed. Naming them that way round is what makes that true
         * without a second code path.
         */
        return $classes === [] ? '' : ' '.implode(' ', $classes);
    }

    /**
     * Does this shop draw the phone-only Place order bar at all, and how?
     *
     * 'off' | 'smart' | 'always'. The view asks this rather than reading the
     * setting, because the answer is also the legacy switch: a shop that turned
     * `mobile_sticky_bar` on under Store → Ecommerce asked for an always-there
     * bar before this screen existed, and must keep it.
     *
     * THIS SCREEN WINS, and the legacy switch is consulted in exactly one
     * case: when this one is still at its shipped default. "Never" means never
     * and "Always" means always, whatever the other screen says — an owner who
     * sets a switch and then has to go and find a second one to make it stick
     * has been lied to. But a shop that turned `mobile_sticky_bar` on before
     * this screen existed asked for an always-there bar, and the default here
     * is not an instruction to take it away from them.
     */
    public function floatBar(): string
    {
        $mode = (string) $this->all()['m_float'];

        if ($mode !== self::SCHEMA['m_float'][2]) {
            return $mode;
        }

        return app(\App\Services\SettingsService::class)->get('mobile_sticky_bar', false)
            ? 'always'
            : $mode;
    }
}
