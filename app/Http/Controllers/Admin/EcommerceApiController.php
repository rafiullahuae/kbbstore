<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Store\ShopController;
use App\Models\PaymentProvider;
use App\Services\SettingsService;
use App\Support\Shortcodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Store → Ecommerce: every storefront setting in one place, grouped into tabs.
 *
 * The schema below is the single source of truth — it drives the form, the
 * validation and the defaults. Adding a setting means adding a line here, not
 * writing another panel. This is a deliberate step towards the Phase 3 schema
 * renderer; when that lands, this schema feeds it unchanged.
 */
class EcommerceApiController extends Controller
{
    /**
     * tab => [key => [type, label, default, help, options]]
     *
     * Types: bool · int · text · textarea · select · colour · money
     */
    private function schema(): array
    {
        return [
            'general' => [
                'label' => 'General',
                'sections' => [
                    'basics' => ['Store basics', 'Country and catalogue defaults.', 'globe', ['store_country', 'low_stock_at']],
                    'layout' => ['Catalogue layout', 'How many products, and how wide.', 'grid', ['products_per_page', 'grid_columns']],
                ],
                'fields' => [
                    'store_country'    => ['select', 'Store country', 'AE', 'Used for shipping zones and tax.', ['AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'KW' => 'Kuwait', 'QA' => 'Qatar', 'BH' => 'Bahrain', 'OM' => 'Oman']],
                    'products_per_page'=> ['int', 'Products per page', 24, 'Shop and category archives.'],
                    'grid_columns'     => ['int', 'Grid columns', 4, 'Default column count on product grids.'],
                    'low_stock_at'     => ['int', 'Low stock threshold', 5, 'Shows a scarcity note at or below this.'],
                ],
            ],
            'cart' => [
                'label' => 'Cart',
                'sections' => [
                    'freeship' => ['Free delivery', 'The progress bar shoppers see as they approach the threshold.', 'truck', ['freeship_bar', 'freeship_bar_style']],
                    'drawer' => ['Mini-cart drawer', 'The panel that slides in when something is added.', 'bag', ['minicart_promo', 'show_browsed']],
                    'coupons' => ['Coupons', 'The hint under the coupon box on the cart page.', 'pct', ['cart_coupon_text']],
                    /*
                     * The `abandoned_cart` module's wording and schedule —
                     * Lane EN. Here rather than on a bespoke screen for the
                     * reason the back-in-stock section on the Product page tab
                     * gives: this schema draws its own controls, and a screen
                     * of its own would have meant editing
                     * resources/views/admin/app.blade.php, which this lane does
                     * not own.
                     *
                     * The switch is on Store → Modules. These four boxes are
                     * the words and the timing, and every one of them ships
                     * empty — see Services\CartRecovery's header for what each
                     * empty box stops.
                     */
                    'reminders' => ['Basket reminders', 'The opt-in box on the cart page, the email it leads to, and when it is sent. Leave these empty and no box appears and nothing is sent, whatever the switch on Store → Modules says.', 'bag',
                        ['cart_recovery_optin_label', 'cart_recovery_subject', 'cart_recovery_body', 'cart_recovery_schedule']],
                ],
                'fields' => [
                    /*
                     * FOUR BLANK BOXES, AND THEY FAIL IN DIFFERENT PLACES ON
                     * PURPOSE.
                     *
                     *   optin_label  blank -> no tick box, so no address is
                     *                ever collected.
                     *   subject/body blank -> nothing is sent, though addresses
                     *                already given are kept and will be used
                     *                once the words are written. That is the
                     *                deliberate asymmetry: consent is worth
                     *                honouring from the moment it is given, and
                     *                Store → Mail → Sent mail names the gap in
                     *                words rather than letting it be silent.
                     *   schedule     blank -> there is no time at which
                     *                anything is due, so nothing is sent.
                     *
                     * The schedule is deliberately NOT given a default. How
                     * long after, and how many messages, is the owner's
                     * judgement about his own customers, not a number a
                     * developer picks — and a default here would start a shop
                     * emailing people on a timetable nobody chose.
                     */
                    'cart_recovery_optin_label' => ['textarea', 'Opt-in text', '', 'The line beside the tick box on the cart page, e.g. “Email me a reminder about this basket.” Empty means no box, and no addresses collected.'],
                    'cart_recovery_subject'     => ['text', 'Reminder subject line', '', 'Used exactly as written, for every message in the sequence. Empty means nothing is ever sent.'],
                    'cart_recovery_body'        => ['textarea', 'Reminder message', '', 'Your own words. The basket contents, a link back to it, the reason the email arrived and the unsubscribe link are added for you. Empty means nothing is ever sent.'],
                    'cart_recovery_schedule'    => ['text', 'When to send', '', 'Hours after the shopper ticks the box, separated by commas — “4, 24” sends two reminders, one at four hours and one at twenty-four. Each is measured from the tick, not from the previous message. Empty means nothing is ever sent.'],
                    'freeship_bar'        => ['bool', 'Free-delivery progress bar', true, 'Shown in the cart, drawer and checkout.'],
                    'freeship_bar_style'  => ['select', 'Bar style', 'mint', '', ['mint' => 'Mint', 'candy' => 'Candy', 'gold' => 'Gold', 'mono' => 'Mono', 'rider' => 'Rider']],
                    'minicart_promo'      => ['textarea', 'Mini-cart promo line', '', 'Appears above the subtotal in the drawer. HTML allowed.'],
                    'show_browsed'        => ['bool', 'Browsed tab in the cart drawer', true, 'Recently viewed products, with one-tap add.'],
                    'cart_coupon_text'    => ['textarea', 'Cart coupon hint', '', 'Shown under the coupon box on the cart page.'],
                ],
            ],
            'checkout' => [
                'label' => 'Checkout',
                'sections' => [
                    'fields' => ['Form fields', 'What the shopper is asked for.', 'card', ['checkout_single_name']],
                    'coupon' => ['Coupon hint', 'The suggested code above the contact section.', 'pct', ['checkout_coupon', 'checkout_coupon_text', 'checkout_coupon_color']],
                    'mobile' => ['Mobile layout', 'The place-order box shoppers see on a phone.', 'phone', ['checkout_thumbs_style', 'mobile_sticky_bar', 'backtocart_style']],
                    'fees' => ['Fees', 'Charges added at checkout.', 'pct', ['cod_enabled', 'cod_fee']],
                    /*
                     * The `legal_notice` module's control — Lane EH.
                     *
                     * The module's registry row has always named Store →
                     * Ecommerce as its settings screen, and until now there was
                     * nothing there: the row read "Not ported yet". It is added
                     * HERE rather than on a screen of its own because this
                     * schema is, in its own header's words, "a deliberate step
                     * towards the Phase 3 schema renderer" — the console draws
                     * this tab generically, so a field added to the list below
                     * gets a real control with no change to
                     * resources/views/admin/app.blade.php, which no single lane
                     * owns.
                     */
                    'legal' => ['Legal notice', 'The line above the Place order button. Leave it empty to show nothing.', 'card', ['checkout_legal_text']],
                ],
                'fields' => [
                    'checkout_single_name'  => ['bool', 'Single full-name field', true, 'Off splits it into first and last name.'],
                    /*
                     * Read by App\Support\CheckoutLegalNotice, which is read by
                     * partials/checkout/legal-notice.blade.php. Both halves, and
                     * ModuleFrameworkGuardTest fails if either goes missing.
                     *
                     * The default is '' on purpose and that is not an oversight:
                     * this app applies onto a live store, and a sentence of
                     * legal wording defaulted into existence would appear above
                     * Place order without the owner ever writing it. The full
                     * reasoning is in CheckoutLegalNotice's header.
                     */
                    'checkout_legal_text'   => ['textarea', 'Legal notice', '', 'Shown above the Place order button. Write {terms} or {privacy} where you want a link to those pages. Empty shows nothing.'],
                    // Not a plain setting — this is the on/off switch for the
                    // Cash on Delivery row in payment_providers, the same flag
                    // the checkout's own gateway list already reads. show()
                    // and save() below read and write it specially so this one
                    // field lives with the fee it belongs beside, without a
                    // second place deciding whether COD is offered.
                    'cod_enabled'           => ['bool', 'Enable Cash on Delivery', true, 'Offered as a payment method at checkout when on.'],
                    /*
                     * THE VAT FIELDS HAVE MOVED — Lane CU.
                     *
                     * vat_enabled, vat_rate, vat_basis and vat_label used to be
                     * a "VAT line" section here. They are now on Store ->
                     * Business Details -> Tax, beside the per-country table and
                     * the switch that decides whether tax is charged at all,
                     * because the owner asked for "a seperate tab for 'Tax'"
                     * and went to Business Details to look for it.
                     *
                     * They are NOT left here as well. Two screens editing one
                     * setting is how the shop ends up with two answers to one
                     * question, and a value saved on the screen the owner is
                     * not looking at is a change he cannot see. Their values are
                     * untouched: only which screen writes them has changed.
                     */
                    /*
                     * BLANK DEFAULTS, deliberately. These were 'GLOW30' and
                     * 'Need more discount? Try {code} for 30% off ✨' — an
                     * invented code and an invented percentage, pre-filled into
                     * the form, so a shop that had never had either advertised a
                     * 30% discount at the moment of payment and refused it the
                     * instant the badge was tapped. Support\CheckoutCouponHint
                     * will not advertise a code that does not exist, and this
                     * screen no longer suggests one that does not.
                     *
                     * Left blank, the hint text is built from what the coupon is
                     * really worth, so the wording cannot misstate the discount
                     * either.
                     */
                    'checkout_coupon'       => ['text', 'Suggested coupon code', '', 'Shown as a clickable hint — only if a coupon with this code exists and is live.'],
                    'checkout_coupon_text'  => ['text', 'Coupon hint text', '', 'Use {code} where the code should appear. Left blank, the line states the code’s real value.'],
                    'checkout_coupon_color' => ['colour', 'Hint colour', '#1f7d52', ''],
                    'checkout_thumbs_style' => ['select', 'Mobile order thumbnails', 'badges', '', ['badges' => 'Badges', 'stack' => 'Stack', 'names' => 'Names', 'scroll' => 'Scroll', 'rings' => 'Rings', 'total' => 'Total', 'off' => 'Off']],
                    'mobile_sticky_bar'     => ['bool', 'Sticky place-order bar on mobile', false, 'The on-page box is shown either way.'],
                    'cod_fee'               => ['money', 'Cash-on-delivery fee', 0, 'Added to the order total when COD is chosen.'],
                    'backtocart_style'      => ['select', 'Back-to-cart button', 'ghost_rect', '', ['ghost_rect' => 'Ghost', 'text_chevron' => 'Text', 'icon_round' => 'Icon only', 'solid' => 'Solid']],
                ],
            ],
            'delivery' => [
                'label' => 'Delivery',
                'sections' => [
                    'message' => ['Delivery message', 'The line under the place-order button.', 'truck', ['delivery_line_enabled', 'delivery_default_text']],
                    'cutoff' => ['Dispatch cutoff', 'The countdown on the product page.', 'box', ['dispatch_cutoff', 'dispatch_cutoff_hour', 'dispatch_days']],
                ],
                'fields' => [
                    'delivery_line_enabled' => ['bool', 'Delivery line under Place order', true, ''],
                    'delivery_default_text' => ['text', 'Delivery line text', '1–3 days fast delivery all over UAE', ''],
                    'dispatch_cutoff'       => ['bool', 'Show dispatch countdown', true, '“Order within 4h 12m for delivery by …”'],
                    'dispatch_cutoff_hour'  => ['int', 'Cutoff hour (24h)', 15, 'Orders before this ship the same working day. True wherever the shopper is, so everyone is told it.'],
                    /*
                     * THIS NUMBER DESCRIBES ONE COUNTRY AND THE HELP NOW SAYS SO.
                     *
                     * It is a single global transit time, so the arrival date it
                     * builds can only be true of the store country — and the
                     * product page used to print it at every visitor on earth.
                     * Shoppers elsewhere now get the dispatch date alone. There
                     * is deliberately no per-country version of this field: no
                     * transit time outside the store country has been measured,
                     * and a second per-country delivery screen beside Delivery
                     * lines would be the duplication this pass removed.
                     */
                    'dispatch_days'         => ['int', 'Delivery days after dispatch', 2, 'Transit time inside your store country only — Friday is skipped automatically. Shoppers elsewhere are told the dispatch date and no arrival date, because no transit time has been measured for them.'],
                ],
            ],
            'product' => [
                'label' => 'Product page',
                'sections' => [
                    'badges' => ['Review badges', 'The rating shown above the price.', 'star',
                        ['review_capsule_style', 'review_badge_heart', 'review_badge_avg', 'review_badge_count',
                         'review_badge_label', 'review_badge_sold', 'review_badge_colour']],
                    'bundles' => ['Quantity bundles', 'Buy-more-save-more tiers on every product.', 'box', ['bundles_enabled']],
                    /*
                     * The `back_in_stock` module's wording — Lane EN.
                     *
                     * HERE rather than on a screen of its own, and for exactly
                     * the reason the legal-notice section above gives: this
                     * schema is "a deliberate step towards the Phase 3 schema
                     * renderer", the console draws these tabs generically, and
                     * a field added to the list below gets a real control with
                     * NO change to resources/views/admin/app.blade.php — which
                     * no single lane owns and which this lane is forbidden to
                     * edit. A bespoke screen would have needed one.
                     *
                     * THE SWITCH IS NOT HERE. It is on Store → Modules, like
                     * every other module's, and duplicating it would be the
                     * "two screens editing one setting" fault this file already
                     * records against the VAT fields. These three boxes are the
                     * WORDS only, and all three ship empty: with the module on
                     * and these blank, the form does not appear and nothing is
                     * ever sent. Services\StockAlerts' header sets out why both
                     * halves are needed and why neither has a default.
                     */
                    'stockalert' => ['Back-in-stock alerts', 'The notify-me form on sold-out products, and the email it leads to. Leave these empty and nothing appears and nothing is sent, whatever the switch on Store → Modules says.', 'box',
                        ['stock_alert_form_label', 'stock_alert_subject', 'stock_alert_body']],
                    'fbt' => ['Frequently bought together', 'A companion-products block below the buy box.', 'box', ['frequently_bought', 'fbt_title', 'fbt_count']],
                    'ratings' => ['Ratings', 'How the review score is shown.', 'star', ['review_capsule_style']],
                    /*
                     * THE TRUST ROW WAS TWO PROMISES WITH NOTHING BEHIND THEM.
                     *
                     * store/product.blade.php printed "Fast UAE delivery" and
                     * "Easy 14-day returns" as literals. No returns window is
                     * recorded anywhere in this application, and the delivery
                     * line was shown to a Gulf shopper as readily as a UAE one
                     * — the same claim Lane CF removed from the checkout for
                     * being the wrong promise.
                     *
                     * Nothing was invented in their place. Both are now the
                     * owner's own words, BLANK BY DEFAULT, and the chip is not
                     * rendered until one is written here.
                     *
                     * EXCEPT THE DELIVERY ONE, WHICH IS NO LONGER WRITTEN HERE.
                     *
                     * `trust_delivery_text` was one global string with no
                     * country check, shown to every visitor on earth — the very
                     * defect Lane CO had just removed from the home page, blank
                     * by default and so armed rather than firing. A single
                     * global string cannot be made country-aware: it can only
                     * ever be true of one country and the shop cannot know
                     * which. So the chip was re-sourced rather than gated. It
                     * reads `delivery_texts` through App\Support\DeliveryLine,
                     * the one reader the home page and the checkout already
                     * share, and the field is gone from this screen so that
                     * ONE screen writes the sentence — Store → Delivery &
                     * Shipping → Delivery lines. Two screens both claiming to
                     * set "the delivery line" is the duplication this project
                     * has had to merge twice already.
                     */
                    'trust' => ['Trust row', 'The chips under Add to cart. The delivery chip is written per country under Store → Delivery & Shipping → Delivery lines, so every screen agrees; blank means the chip is not shown — write only what the shop actually does.', 'shield', ['trust_returns_text']],
                ],
                'fields' => [
                    /*
                     * BLANK DEFAULTS, and unlike most blank defaults on this
                     * screen these three are the feature's safety catch rather
                     * than a nicety — Lane EN.
                     *
                     * Read by Services\StockAlerts, which refuses to show the
                     * form without the first and refuses to SEND without both
                     * the second and the third. A default sentence here would
                     * mean a shop that switched the module on to see what it did
                     * started emailing its customers in words nobody wrote. The
                     * `checkout_legal_text` field above is blank for the same
                     * reason and Support\CheckoutLegalNotice's header argues it
                     * at length.
                     *
                     * SettingsService::get() returns its default only when the
                     * row is ABSENT and an owner who clears a box stores '' —
                     * so the default and the cleared state have to be the same
                     * value, and they are.
                     */
                    'stock_alert_form_label' => ['textarea', 'Notify-me form text', '', 'The line above the form on a sold-out product, e.g. “Sold out — we will email you the moment it is back.” Empty means no form at all.'],
                    'stock_alert_subject'    => ['text', 'Alert subject line', '', 'Used exactly as written. Do not put the product name in it — a subject shows on a locked phone screen and in every mail server’s log. Empty means nothing is ever sent.'],
                    'stock_alert_body'       => ['textarea', 'Alert message', '', 'Your own words. The product name, a link to it, the reason the email arrived and the unsubscribe link are added for you. Empty means nothing is ever sent.'],
                    'bundles_enabled'       => ['bool', 'Quantity bundles', true, 'Tiers are configured in Appearance → Quantity bundles.'],
                    'frequently_bought'     => ['bool', 'Frequently bought together', false, ''],
                    'fbt_title'             => ['text', 'Bundle block title', 'Complete your routine', ''],
                    'fbt_count'             => ['int', 'Companion products', 3, ''],
                    'review_capsule_style'  => ['select', 'Rating display', 'capsule', 'Two badges at once is usually one too many.', ['capsule' => 'Capsule only', 'inline' => 'Inline only', 'both' => 'Capsule and inline', 'off' => 'Hidden']],
                    'review_badge_heart'    => ['bool', 'Heart icon on the capsule', true, ''],
                    'review_badge_avg'      => ['bool', 'Show the average score', true, ''],
                    'review_badge_count'    => ['bool', 'Show the review count', true, ''],
                    'review_badge_label'    => ['text', 'Count wording', '{n} reviews', 'Use {n} where the number should appear.'],
                    'review_badge_sold'     => ['bool', 'Show units sold', true, 'Only appears above 1,000 sales.'],
                    'review_badge_colour'   => ['colour', 'Star colour', '#E8A33D', ''],
                    'trust_returns_text'    => ['text', 'Returns chip', '', 'e.g. "Easy 14-day returns". Left blank, no returns chip is shown — do not promise a window the shop does not keep.'],
                ],
            ],
            /*
             * THERE IS NO 'search' TAB HERE ANY MORE. Store -> Site Search owns
             * search, and this one could not have worked even in principle.
             *
             * It offered four fields. The audit found one of them,
             * `search_limit_products`, had no reader anywhere in the codebase.
             * The other three are read — but not from where this screen wrote
             * them, so all four were inert:
             *
             *   HeaderSettings keeps every one of its fields INSIDE a single
             *   settings row called `header_settings`, a JSON blob, and
             *   SearchController reads them through it. save() below calls
             *   SettingsService::set($name, ...), which writes a TOP-LEVEL
             *   settings row named `search_min_chars`. Nothing ever reads that
             *   row. The screen still showed the value back, because show()
             *   reads it from the same top-level key it wrote -- which is
             *   exactly why this survived: it round-tripped perfectly and
             *   changed nothing.
             *
             * Measured through the real endpoints before removing it:
             * POST /admin-api/ecommerce {search_limit_categories: 0} answers
             * ok:true, and HeaderSettings::get('search_limit_categories') still
             * returns 3. POST /admin-api/site-search {search_results_max: 3}
             * changes the panel from 5 products to 3 immediately.
             *
             * ON THE DUPLICATE OWNERSHIP, which is what let a field like this
             * exist: Site Search is the owner and this screen is not. It says
             * so in its own docblock, it validates against
             * HeaderSettings::SCHEMA so an unknown key is refused with a 422
             * rather than silently stored, and its writes reach the search
             * code. Nothing is lost by dropping this tab -- `search_min_chars`,
             * `search_limit_categories` and `search_limit_brands` are all on
             * Site Search already, and the working equivalent of
             * `search_limit_products` is `search_results_max`, which is also
             * there and is what actually sets the product count.
             *
             * The help text was wrong as well: "Zero hides the group" was not
             * true of `search_limit_products` under any value, since nothing
             * read it. It IS true of the Site Search fields, whose readers
             * guard on `if ($n = ...)`.
             *
             * The values already written to those dead rows are DELETED by
             * 2026_10_07_000000_clear_caches_ecommerce_search_tab, not copied
             * into header_settings. Copying them would change live search
             * behaviour on upgrade for a setting the operator was never
             * actually applying -- a silent change of the shape this repo has
             * been bitten by before. They never took effect; they should not
             * start now.
             */
        ];
    }


    /**
     * Preview content, keyed by section or field name.
     *
     * Three kinds, so the eye icon always means the same thing:
     *   where   — a miniature of the real UI with the affected part ringed
     *   compare — options side by side
     *   rule    — settings with no visual output, explained in words
     *
     * A field with no entry here simply gets no icon.
     */
    private function previews(): array
    {
        $ring = fn (string $inner, int $n = 1) =>
            '<div class="ecmark" style="display:block"><span class="ecco">' . $n . '</span>' . $inner . '</div>';

        return [
            // ---- sections ----
            'freeship' => [
                'caption' => 'Where this appears — cart drawer',
                'stage' => $ring('<div style="font-size:12.5px;margin-bottom:7px">🎉 <b>You\'ve unlocked free delivery!</b></div><div class="ecmb"><i style="width:100%"></i></div>')
                    . '<div class="ecmr" style="margin-top:13px"><span>Subtotal</span><span>د.إ1,050</span></div>',
                'legend' => ['The message and the bar together. Shown in the cart page, the mini-cart drawer and the checkout summary.'],
            ],
            'drawer' => [
                'caption' => 'Where this appears — the drawer',
                'stage' => '<div style="display:flex;gap:18px;border-bottom:1px solid #e9edf3;padding-bottom:8px;font-size:12.5px;font-weight:600"><span style="color:#C13E63;border-bottom:2px solid #E0567B;padding-bottom:7px">Cart</span><span style="color:#7b8697">Browsed</span></div>'
                    . $ring('<div style="background:#fff0f4;border-radius:8px;padding:9px 11px;font-size:11.5px;color:#5e545a;margin-top:13px">🎁 Need extra 30% off? Use code <b>GLOW30</b></div>')
                    . '<div class="ecmr" style="margin-top:10px"><span>Subtotal</span><span style="font-weight:800">د.إ1,050</span></div>',
                'legend' => ['The promo line, above the subtotal.'],
            ],
            'mobile' => [
                'caption' => 'Where this appears — mobile checkout',
                'stage' => '<div style="font-size:11px;font-weight:700;margin-bottom:8px">Your bag <span style="color:#7b8697;font-weight:400">· 10 items</span></div>'
                    . $ring('<div style="display:flex;gap:7px">'
                        . '<span style="width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,#ffd1e2,#ff9fc1)"></span>'
                        . '<span style="width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,#cfe6ff,#8fc0f0)"></span>'
                        . '<span style="width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,#ffe9a8,#f3c969)"></span></div>'),
                'legend' => ['The product strip in the place-order box. Mobile only — the desktop summary is unaffected.'],
            ],
            'fees' => [
                'caption' => 'Effect on the checkout summary',
                'stage' => '<div class="ecmr"><span>Subtotal</span><span>د.إ1,050</span></div>'
                    . '<div class="ecmr"><span>Delivery</span><span style="color:#1F7D52;font-weight:700">Free</span></div>'
                    . $ring('<div class="ecmr"><span>Cash-on-delivery fee</span><span>د.إ5</span></div>')
                    . '<div class="ecmr" style="border-top:1px solid #e9edf3;margin-top:6px;padding-top:9px"><span style="font-weight:800">Total</span><span style="font-weight:800">د.إ1,055</span></div>',
                'legend' => ['Shown only when the shopper selects cash on delivery, and applied server-side so it cannot be skipped.'],
            ],
            // The 'behaviour' preview went with the Search tab it illustrated.
            // It described a threshold this screen was not able to set.


            'basics' => [
                'caption' => 'Where the country is used',
                'stage' => '<div style="font-size:12.5px;line-height:1.9">'
                    . $ring('<div>Shipping zone matched → <b>UAE, free over د.إ199</b></div>')
                    . '<div style="margin-top:6px">Checkout country → <b>United Arab Emirates</b></div>'
                    . '<div>VAT line → <b>Inclusive of 5% VAT</b></div></div>',
                'legend' => ['The country decides which shipping zone applies, what checkout defaults to, and the VAT wording.'],
            ],
            'layout' => [
                'caption' => 'The shop archive at these settings',
                'stage' => $ring('<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:7px">'
                    . str_repeat('<span style="height:44px;border-radius:7px;background:linear-gradient(135deg,#ffd1e2,#ff9fc1)"></span>', 8) . '</div>')
                    . '<div style="font-size:12px;color:#7b8697;margin-top:9px">24 per page, 4 columns</div>',
                'legend' => ['The grid on shop and category pages. Columns also apply to every [kbb_products] shortcode.'],
            ],
            'coupons' => [
                'caption' => 'Where this appears — cart page',
                'stage' => '<div style="display:flex;gap:8px"><span style="flex:1;border:1px solid #e9edf3;border-radius:9px;padding:8px 11px;font-size:12.5px;color:#7b8697">Discount code</span><span style="background:#E0567B;color:#fff;border-radius:9px;padding:8px 16px;font-size:12.5px;font-weight:700">Apply</span></div>'
                    . $ring('<div style="font-size:11.5px;color:#1F7D52;margin-top:9px">🎁 Use code <b>GLOW30</b> for an extra 30% off.</div>'),
                'legend' => ['The hint below the coupon field. The code is clickable and applies without typing.'],
            ],
            'fields' => [
                'caption' => 'The contact and address sections',
                'stage' => $ring('<div style="font-size:12.5px"><b>Full name</b> <span style="color:#7b8697">— one field</span></div>')
                    . '<div style="font-size:12.5px;margin-top:9px;color:#7b8697">Off splits it into <b>First name</b> and <b>Last name</b>.</div>',
                'legend' => ['Fewer fields means fewer abandoned checkouts; two fields give cleaner data for shipping labels.'],
            ],
            'coupon' => [
                'caption' => 'Where this appears — checkout',
                'stage' => '<div style="border:1px dashed #1F7D52;border-radius:10px;padding:11px;background:#f6fdf9"><div style="font-size:12.5px;font-weight:600">🎁 Have a discount code?</div>'
                    . $ring('<div style="font-size:11.5px;color:#1F7D52;margin-top:8px">Need more discount? Try <b>GLOW30</b> for 30% off ✨</div>') . '</div>',
                'legend' => ['The hint under the promo field. Clicking the code applies it immediately.'],
            ],
            'message' => [
                'caption' => 'Where this appears',
                'stage' => $ring('<div style="font-size:12px;color:#3c4655">🚚 1–3 days fast delivery all over UAE</div>'),
                'legend' => ['Sits directly beneath Place order, in both the desktop summary and the mobile box.'],
            ],
            'cutoff' => [
                'caption' => 'Where this appears — product page',
                'stage' => $ring('<div style="font-size:12.5px">Order within <b>4h 12m</b> for delivery by <b>Mon, 31 Aug</b></div>')
                    . '<div style="font-size:12.5px;margin-top:9px;color:#7b8697">Outside your store country, where no transit time has been measured, the same line reads <b>Order within 4h 12m to ship on Sat, 29 Aug</b> — when the parcel leaves, and no arrival date.</div>',
                'legend' => [
                    'Counts down to the cutoff hour, then rolls to the next working day. Friday is skipped automatically.',
                    'The arrival date is only shown to shoppers in your store country, because “Delivery days after dispatch” is one number and only describes that one country. Everyone else is told when the parcel ships. To say something about delivery elsewhere, write it under Store → Delivery & Shipping → Delivery lines.',
                ],
            ],
            'bundles' => [
                'caption' => 'Where this appears — buy box',
                'stage' => $ring('<div style="border:1px solid #E0567B;border-radius:10px;padding:9px 11px;display:flex;align-items:center;gap:9px;font-size:12.5px"><span style="width:14px;height:14px;border-radius:50%;border:4px solid #E0567B"></span>2-pack bundle<span style="flex:1"></span><s style="color:#7b8697">د.إ110</s> <b>د.إ105</b><span style="background:#e8f6ee;color:#1F7D52;border-radius:99px;padding:2px 8px;font-size:11px;font-weight:700">Save 5%</span></div>'),
                'legend' => ['Tiers are configured in Appearance → Quantity bundles. This switch only shows or hides them.'],
            ],
            'fbt' => [
                'caption' => 'Where this appears',
                'stage' => $ring('<div style="font-size:12.5px;font-weight:700;margin-bottom:8px">Complete your routine</div><div style="display:flex;align-items:center;gap:8px"><span style="width:40px;height:40px;border-radius:9px;background:linear-gradient(135deg,#ffd1e2,#ff9fc1)"></span><span style="color:#7b8697">+</span><span style="width:40px;height:40px;border-radius:9px;background:linear-gradient(135deg,#cfe6ff,#8fc0f0)"></span><span style="color:#7b8697">+</span><span style="width:40px;height:40px;border-radius:9px;background:linear-gradient(135deg,#ffe9a8,#f3c969)"></span></div>'),
                'legend' => ['Sits between the buy box and the details. Off by default.'],
            ],
            'ratings' => [
                'caption' => 'The two display styles',
                'stage' => $ring('<span style="display:inline-flex;align-items:center;gap:8px;background:#FFF1F5;border-radius:99px;padding:6px 14px"><span style="width:22px;height:22px;border-radius:50%;background:#fff;display:grid;place-items:center;color:#E0567B">♥</span><span style="color:#E8A33D">★★★★★</span><b>4.9</b><span style="font-size:11.5px;color:#7b8697">3,204 reviews</span></span>')
                    . '<div style="margin-top:12px;font-size:12.5px;color:#7b8697">★★★★★ 4.9 · <u>3,204 reviews</u> ← the inline line</div>',
                'legend' => ['Capsule only · inline only · both · hidden. “Both” is what the finalized design uses.'],
            ],

            'badges' => [
                'caption' => 'The two badge styles',
                'stage' => $ring('<span style="display:inline-flex;align-items:center;gap:8px;background:#FFF1F5;border-radius:99px;padding:6px 14px"><span style="width:22px;height:22px;border-radius:50%;background:#fff;display:grid;place-items:center;color:#E0567B">&#10084;</span><span style="color:#E8A33D">&#9733;&#9733;&#9733;&#9733;&#9733;</span><b>4.9</b><span style="font-size:11.5px;color:#7b8697">3,204 reviews</span></span>')
                    . '<div style="margin-top:12px;font-size:12.5px;color:#7b8697">&#9733;&#9733;&#9733;&#9733;&#9733; 4.9 · <u>3,204 reviews</u> · 12k+ sold &nbsp;&larr; the inline line</div>',
                'legend' => ['Capsule only, inline only, both, or hidden. Showing both puts two rating badges above the price, which reads as a duplicate.'],
            ],
            // ---- individual settings ----
            'freeship_bar' => [
                'caption' => 'Turned off',
                'stage' => '<div style="opacity:.45;font-size:12.5px">— no bar, no message —</div><div class="ecmr" style="margin-top:11px"><span>Subtotal</span><span>د.إ1,050</span></div>',
                'legend' => ['Only the bar is removed. Free delivery still applies at the threshold.'],
            ],
            'freeship_bar_style' => [
                'caption' => 'Style comparison',
                'stage' => '<div style="display:flex;gap:9px;flex-wrap:wrap">'
                    . '<i style="width:56px;height:32px;border-radius:7px;border:2px solid #E0567B;display:block;background:repeating-linear-gradient(45deg,#2fae6f,#2fae6f 6px,#48c98a 6px,#48c98a 12px)"></i>'
                    . '<i style="width:56px;height:32px;border-radius:7px;border:1px solid #e9edf3;display:block;background:repeating-linear-gradient(45deg,#e0567b,#e0567b 6px,#ff9fc1 6px,#ff9fc1 12px)"></i>'
                    . '<i style="width:56px;height:32px;border-radius:7px;border:1px solid #e9edf3;display:block;background:repeating-linear-gradient(45deg,#c9a227,#c9a227 6px,#e6c65c 6px,#e6c65c 12px)"></i>'
                    . '<i style="width:56px;height:32px;border-radius:7px;border:1px solid #e9edf3;display:block;background:repeating-linear-gradient(45deg,#475569,#475569 6px,#94a3b8 6px,#94a3b8 12px)"></i>'
                    . '<i style="width:56px;height:32px;border-radius:7px;border:1px solid #e9edf3;display:block;background:linear-gradient(90deg,#2fae6f,#48c98a)"></i></div>'
                    . $ring('<div class="ecmb" style="margin-top:14px"><i style="width:78%"></i></div>'),
                'legend' => ['Mint · Candy · Gold · Mono · Rider. The rider variant adds a scooter that travels along the track.'],
            ],
            'minicart_promo' => [
                'caption' => 'Live preview — drawer footer',
                'stage' => $ring('<div style="background:#fff0f4;border-radius:8px;padding:9px 11px;font-size:11.5px;color:#5e545a">🎁 Need extra 30% off? Use code <b>GLOW30</b> at checkout.</div>'),
                'legend' => ['Your text, as the shopper sees it. Empty hides the line. A code wrapped in &lt;b data-code="GLOW30"&gt; becomes clickable and applies itself.'],
            ],
            'show_browsed' => [
                'caption' => 'Where this appears',
                'stage' => '<div style="display:flex;gap:18px;border-bottom:1px solid #e9edf3;padding-bottom:8px;font-size:12.5px;font-weight:600"><span style="color:#C13E63;border-bottom:2px solid #E0567B;padding-bottom:7px">Cart</span>'
                    . '<span class="ecmark" style="color:#7b8697"><span class="ecco">1</span>Browsed</span></div>'
                    . '<div style="display:flex;align-items:center;gap:11px;margin-top:14px"><span style="width:38px;height:38px;border-radius:9px;background:linear-gradient(135deg,#ffd1e2,#ff9fc1)"></span>'
                    . '<span style="flex:1;font-size:12.5px">Relief Sun SPF50+<br><b style="color:#C13E63">د.إ71</b></span>'
                    . '<span class="ecmark"><span class="ecco">2</span><span style="border:1px solid #e9edf3;border-radius:50%;width:30px;height:30px;display:grid;place-items:center;color:#C13E63;font-weight:700">+</span></span></div>',
                'legend' => ['The second tab in the drawer.', 'One-tap add at the current price. Items already in the cart are never suggested.'],
            ],
            'cod_fee' => [
                'caption' => 'Effect on the total',
                'stage' => $ring('<div class="ecmr"><span>Cash-on-delivery fee</span><span>د.إ5</span></div>')
                    . '<div class="ecmr" style="border-top:1px solid #e9edf3;margin-top:6px;padding-top:9px"><span style="font-weight:800">Total</span><span style="font-weight:800">د.إ1,055</span></div>',
                'legend' => ['Entered in fils: 500 means د.إ5.00. Set 0 to hide the line entirely.'],
            ],
            'checkout_thumbs_style' => [
                'caption' => 'Style comparison — mobile place-order box',
                'stage' => $ring('<div style="display:flex;gap:7px">'
                    . '<span style="width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,#ffd1e2,#ff9fc1);position:relative"><b style="position:absolute;top:-5px;right:-5px;background:#2A2228;color:#fff;font-size:9px;border-radius:99px;padding:1px 5px">×2</b></span>'
                    . '<span style="width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,#cfe6ff,#8fc0f0);position:relative"><b style="position:absolute;top:-5px;right:-5px;background:#2A2228;color:#fff;font-size:9px;border-radius:99px;padding:1px 5px">×5</b></span>'
                    . '<span style="width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,#ffe9a8,#f3c969)"></span></div>'),
                'legend' => ['Badges shows a quantity on each circle · Names lists product names · Off hides the strip but keeps the totals.'],
            ],
            'mobile_sticky_bar' => [
                'caption' => 'Turned on — a bar pinned to the bottom',
                'stage' => '<div style="border:1px solid #e9edf3;border-radius:9px;padding:9px 11px;display:flex;align-items:center;gap:12px;background:#fff">'
                    . '<div><div style="font-size:10px;color:#7b8697">Total</div><div style="font-weight:800;font-size:14px">د.إ1,050</div></div>'
                    . '<div style="flex:1"></div><span style="background:#E0567B;color:#fff;border-radius:99px;padding:8px 16px;font-size:12.5px;font-weight:700">Place order</span></div>',
                'legend' => ['Optional and off by default. The on-page box is the design; this is an extra for keeping the total visible while scrolling.'],
            ],
            'low_stock_at' => [
                'caption' => 'Effect on the product page',
                'stage' => $ring('<div style="font-size:12.5px;color:#B7791F;font-weight:600">● Only 3 left · order soon</div>')
                    . '<div style="font-size:12px;color:#7b8697;margin-top:9px">Above the threshold it reads “In stock · ready to ship”.</div>',
                'legend' => ['Shown only when the stock count is known and at or below this number. Set 0 to never show it.'],
            ],
            'bundles_enabled' => [
                'caption' => 'Where this appears — product page',
                'stage' => $ring('<div style="border:1px solid #E0567B;border-radius:10px;padding:9px 11px;display:flex;align-items:center;gap:9px;font-size:12.5px"><span style="width:14px;height:14px;border-radius:50%;border:4px solid #E0567B"></span>2-pack bundle<span style="flex:1"></span><s style="color:#7b8697">د.إ110</s> <b>د.إ105</b><span style="background:#e8f6ee;color:#1F7D52;border-radius:99px;padding:2px 8px;font-size:11px;font-weight:700">Save 5%</span></div>'),
                'legend' => ['The tiers themselves are configured in Appearance → Quantity bundles. This switch only shows or hides them.'],
            ],
            'review_capsule_style' => [
                'caption' => 'Rating display options',
                'stage' => $ring('<span style="display:inline-flex;align-items:center;gap:8px;background:#FFF1F5;border-radius:99px;padding:6px 14px"><span style="width:22px;height:22px;border-radius:50%;background:#fff;display:grid;place-items:center;color:#E0567B">♥</span><span style="color:#E8A33D">★★★★★</span><b>4.9</b> <span style="font-size:11.5px;color:#7b8697">3,204 reviews</span></span>')
                    . '<div style="margin-top:12px;font-size:12.5px;color:#7b8697">★★★★★ 4.9 · <u>3,204 reviews</u> &nbsp;← the inline line</div>',
                'legend' => ['Capsule only · inline only · both · hidden. “Both” is what the finalized design uses.'],
            ],
        ];
    }

    public function __construct(private SettingsService $settings) {}

    public function show(): JsonResponse
    {
        $tabs = [];

        foreach ($this->schema() as $key => $tab) {
            $fields = [];

            foreach ($tab['fields'] as $name => $def) {
                [$type, $label, $default, $help] = array_pad($def, 4, '');

                $fields[$name] = [
                    'name' => $name,
                    'type' => $type,
                    'label' => $label,
                    'help' => $help,
                    'options' => $def[4] ?? null,
                    'value' => $name === 'cod_enabled'
                        ? (bool) (PaymentProvider::find('cod')?->enabled ?? $default)
                        : $this->settings->get($name, $default),
                    'default' => $default,
                    // The admin looks up the preview by name; a field with no
                    // entry simply shows no eye icon.
                    'preview' => $name,
                ];
            }

            // Sections group the fields. Anything not placed in a section falls
            // into a general one, so a new field never goes missing from the UI.
            $sections = [];
            $placed = [];

            foreach ($tab['sections'] ?? [] as $sk => [$slabel, $sdesc, $sicon, $names]) {
                $inSection = [];

                foreach ($names as $n) {
                    if (isset($fields[$n])) {
                        $inSection[] = $fields[$n];
                        $placed[] = $n;
                    }
                }

                if ($inSection) {
                    $sections[] = ['key' => $sk, 'label' => $slabel, 'desc' => $sdesc, 'icon' => $sicon, 'preview' => $sk, 'fields' => $inSection];
                }
            }

            $rest = array_values(array_diff(array_keys($fields), $placed));

            if ($rest) {
                $sections[] = [
                    'key' => 'other',
                    'label' => $tab['label'],
                    'desc' => '',
                    'icon' => 'box',
                    'preview' => null,
                    'fields' => array_map(fn ($n) => $fields[$n], $rest),
                ];
            }

            $tabs[] = [
                'key' => $key,
                'label' => $tab['label'],
                'count' => count($fields),
                'sections' => $sections,
            ];
        }

        return response()->json(['tabs' => $tabs, 'previews' => $this->previews()]);
    }

    public function save(Request $request): JsonResponse
    {
        $incoming = (array) $request->input('settings', []);
        $saved = 0;

        foreach ($this->schema() as $tab) {
            foreach ($tab['fields'] as $name => $def) {
                if (! array_key_exists($name, $incoming)) {
                    continue;
                }

                $value = $this->cast($def[0], $incoming[$name], $def[4] ?? null, $name);

                if ($value === null) {
                    return response()->json(['ok' => false, 'error' => "“{$def[1]}” is not a valid value."], 422);
                }

                if ($name === 'cod_enabled') {
                    // Written to the provider row the checkout's own gateway
                    // list already reads (CheckoutController::gateways()),
                    // rather than a settings key nothing else would consult.
                    PaymentProvider::updateOrCreate(
                        ['id' => 'cod'],
                        ['title' => 'Cash on delivery', 'enabled' => $value]
                    );
                } else {
                    $this->settings->set($name, $value);
                }

                $saved++;
            }
        }

        // Anything cached from these settings has to go, or a change appears
        // only after the TTL and looks like it did not save.
        Shortcodes::flush();
        ShopController::flushSidebarCache();
        Cache::forget('kbb.home.rails');
        Cache::forget('kbb.home.brands');

        return response()->json(['ok' => true, 'saved' => $saved]);
    }

    /**
     * Bounds for `int` fields whose reader genuinely has one.
     *
     * Deliberately short. A wrong ceiling is worse than none: it blocks a value
     * the operator is entitled to enter and there is nothing on the screen to
     * say why. So this lists only the fields where the bound is a fact about
     * what the number MEANS — an hour of the day has 24 of them — and every
     * other `int` is simply required to be a non-negative whole number.
     */
    private const INT_BOUNDS = [
        'dispatch_cutoff_hour' => [0, 23],
    ];

    /**
     * Returns null when the value is not acceptable for its type.
     *
     * TWO THINGS THIS USED TO GET WRONG, both on `'int', 'money'`, which was
     * `is_numeric($raw) && (int) $raw >= 0 ? (int) $raw : null`:
     *
     *  - is_numeric('12.50') is true and `(int) '12.50'` is 12. The one money
     *    field here is the COD fee, in fils, so a hand-typed "12.50" was stored
     *    as 12 fils — AED 0.12 instead of AED 12.50 — and the screen said
     *    "Saved". That is the silent-corruption shape: a hundredfold error that
     *    reads back as a plausible number. Refused now, with the fils value the
     *    operator probably meant named in the message.
     *  - there was no ceiling. `is_numeric` is happy with 99,999,999,999, and
     *    the fee is added into `orders.total`, a signed 32-bit column. MySQL in
     *    strict mode answers that INSERT with ERROR 1264 and the checkout 500s;
     *    SQLite stores it, which is why the test suite could not see it.
     */
    private function cast(string $type, mixed $raw, ?array $options, ?string $name = null): mixed
    {
        return match ($type) {
            'bool' => (bool) $raw,
            'money' => $this->castFils($raw),
            'int' => $this->castInt($raw, self::INT_BOUNDS[$name] ?? null),
            'select' => $options && array_key_exists((string) $raw, $options) ? (string) $raw : null,
            'colour' => preg_match('/^#[0-9a-f]{6}$/i', (string) $raw) ? (string) $raw : null,
            default => is_string($raw) && mb_strlen($raw) <= 2000 ? $raw : null,
        };
    }

    /** A whole number of fils, within what a 32-bit money column can hold. */
    private function castFils(mixed $raw): ?int
    {
        $value = trim((string) $raw);

        if (preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        // Length first, so the string cannot overflow a PHP int on the way to
        // the comparison and wrap into something that looks acceptable.
        if (strlen(ltrim($value, '0')) > 10 || (int) $value > \App\Services\Import\Money::MAX_FILS) {
            return null;
        }

        return (int) $value;
    }

    /** A non-negative whole number, inside its bounds where it has any. */
    private function castInt(mixed $raw, ?array $bounds): ?int
    {
        $value = trim((string) $raw);

        if (preg_match('/^\d+$/', $value) !== 1 || strlen(ltrim($value, '0')) > 10) {
            return null;
        }

        $n = (int) $value;

        if ($bounds !== null && ($n < $bounds[0] || $n > $bounds[1])) {
            return null;
        }

        return $n;
    }
}
