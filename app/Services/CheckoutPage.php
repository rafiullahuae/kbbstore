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
        'd_row_pad'   => ['range', 'Space above and below each row', 10,
                          'The gap between one order-summary line and the next. The first line keeps its flush top edge at every value.',
                          ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],
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
        'm_row_pad'   => ['range', 'Space above and below each row', 10,
                          'The gap between one order-summary line and the next. The summary card shows about 148px before "View full summary", so squeezing this fits more lines in that peek.',
                          ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],
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
                           'The band the logo and badge are laid out in. It is set apart from the page width below it on purpose — a header that runs wider than the form is a common and deliberate look.',
                           ['min' => 880, 'max' => 1600, 'step' => 20, 'unit' => 'px']],
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
                           'How far the logo sits from the screen edge, and the badge from the other one.',
                           ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'm_head_max'   => ['range', 'Header width', 1040,
                           'Wider than any phone at every value, so on a phone this changes nothing — it is here so the two tabs carry the same controls and neither has a gap where the other has a slider.',
                           ['min' => 880, 'max' => 1600, 'step' => 20, 'unit' => 'px']],
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
                         'What the shopper types — 16px on a phone today. RAISING THIS WORKS NORMALLY; LOWERING IT STOPS AT 16px, and the stylesheet enforces that floor whatever this says. iOS Safari zooms the page when a field smaller than 16px takes focus and does not zoom back out, which leaves the shopper on a checkout wider than their screen in the middle of paying.',
                         ['min' => 100, 'max' => 150, 'step' => 5, 'unit' => '%']],
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
                           ['d_max', 'd_aside', 'd_gap', 'd_pad_x', 'd_pad_y',
                            'd_block_gap', 'd_sec_pad', 'd_aside_pad',
                            'd_sticky', 'd_sticky_top']],
        'desktop_head' => ['Desktop · Header', 'The secure-checkout bar across the top. Its height is its padding plus the taller of the logo and the badge, so those are the controls rather than a "height" that would fight them.',
                           ['d_head_pad_y', 'd_head_pad_x', 'd_head_max', 'd_head_logo', 'd_head_badge', 'd_head_sticky']],
        'desktop_type' => ['Desktop · Text sizes', 'Every size is a share of the size that role already uses, so 100% is the page exactly as it is and the roles keep their relationship to each other.',
                           ['d_t_title', 'd_t_lead', 'd_t_h2', 'd_t_label', 'd_t_input', 'd_t_trust']],
        'desktop_rows' => ['Desktop · Product rows', 'The lines in the order summary on the right — picture, name, stepper and price. Nothing on this tab can reach a phone, and nothing on it can reach the cart page.',
                           ['d_row_h', 'd_row_pad', 'd_row_gap', 'd_row_font', 'd_row_bold', 'd_qty_size']],
        'mobile'       => ['Mobile · Layout', 'The single-column checkout, at 900px and below. Nothing on this tab can reach a desktop.',
                           ['m_pad_x', 'm_pad_y', 'm_gap', 'm_block_gap', 'm_sec_pad', 'm_aside_pad']],
        'mobile_head'  => ['Mobile · Header', 'The same bar on a phone. Worth a look at 360px: the badge is the first thing that crowds the logo.',
                           ['m_head_pad_y', 'm_head_pad_x', 'm_head_max', 'm_head_logo', 'm_head_badge', 'm_head_sticky']],
        'mobile_type'  => ['Mobile · Text sizes', 'Same six roles, their own values. The field-text floor is the one control here that will not go below where it is, and it says why.',
                           ['m_t_title', 'm_t_lead', 'm_t_h2', 'm_t_label', 'm_t_input', 'm_t_trust']],
        'mobile_rows'  => ['Mobile · Product rows', 'The lines inside the summary card at the top of the phone page. Nothing on this tab can reach a desktop, and nothing on it can reach the cart page.',
                           ['m_row_h', 'm_row_pad', 'm_row_gap', 'm_row_font', 'm_row_bold', 'm_qty_size']],
        'cues'         => ['Attention & trust', 'The two moving things on this page: the cue that points at the address button while no address is chosen, and the authenticity tick under Payment. One set of values for both surfaces — one cue doing one job.',
                           ['addr_cue', 'addr_cue_icons', 'addr_cue_arrow', 'addr_cue_pulse', 'addr_cue_speed', 'addr_cue_size',
                            'trust_tick', 'trust_tick_speed']],
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
        'm_pad_x'      => '--cop-m-padx',
        'm_pad_y'      => '--cop-m-pady',
        'm_gap'        => '--cop-m-gap',
        'm_block_gap'  => '--cop-m-block',
        'm_sec_pad'    => '--cop-m-secpad',
        'm_aside_pad'  => '--cop-m-asidepad',
        'd_row_h'      => '--cop-d-rowh',
        'd_row_pad'    => '--cop-d-rowpad',
        'd_row_gap'    => '--cop-d-rowgap',
        'm_row_h'      => '--cop-m-rowh',
        'm_row_pad'    => '--cop-m-rowpad',
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
        'd_t_trust'  => '--cop-d-ttrust',
        'm_t_title'  => '--cop-m-ttitle',
        'm_t_lead'   => '--cop-m-tlead',
        'm_t_h2'     => '--cop-m-th2',
        'm_t_label'  => '--cop-m-tlabel',
        'm_t_input'  => '--cop-m-tinput',
        'm_t_trust'  => '--cop-m-ttrust',
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
        'addr_cue'       => ['cop-nocue', ''],
        'addr_cue_icons' => ['cop-nocue-ic', ''],
        'addr_cue_arrow' => ['cop-nocue-ar', ''],
        'addr_cue_pulse' => ['cop-nocue-pu', ''],
        'trust_tick'     => ['cop-notick', ''],
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
            default => $value,
        };
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

        /*
         * Every class here is an OFF switch, so the default — everything on —
         * produces an empty string and the element renders exactly as it did
         * before this screen existed. Naming them that way round is what makes
         * that true without a second code path.
         */
        return $classes === [] ? '' : ' '.implode(' ', $classes);
    }
}
