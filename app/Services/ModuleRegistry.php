<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The module registry.
 *
 * Ported from KBB Modules v2.39.0 — 29 modules in 8 groups, with the plugin's
 * own names, descriptions and default states carried across verbatim. The list
 * was extracted from the plugin source rather than retyped, so a module cannot
 * quietly go missing or change its default in translation.
 *
 * Two things the plugin does that this keeps:
 *
 *  - A module is an independent on/off switch. The storefront renders with every
 *    one of them off; nothing here is load-bearing.
 *  - Sixteen modules own their settings, twelve defer to a settings screen that
 *    already exists in this app, and one has no settings at all. The fifth field
 *    below is where that screen lives, so the admin can link straight to it
 *    instead of duplicating controls.
 *
 * Per-device visibility is this app's addition: the plugin is desktop-and-phone
 * or nothing, and the storefront here already treats those separately
 * everywhere else.
 *
 * On/off is NOT stored here. It lives in `module_toggles`, read through
 * SettingsService::moduleEnabled() — the mechanism eleven places in this app
 * already use, and the table the original schema created with the comment
 * "29 ids from the registry". This class is the catalogue and the admin's view
 * of it, not a second store. Only the per-device choice, which that table has no
 * column for, is kept alongside it in settings.
 */
class ModuleRegistry
{
    /** group key => label, in the order the plugin lists them. */
    public const GROUPS = [
        'checkout'    => 'Checkout',
        'cart'        => 'Cart & mini-cart',
        'store'       => 'Store & content',
        'payship'     => 'Payments & shipping',
        'email'       => 'Order emails',
        'catalogue'   => 'Catalogue',
        /*
         * Its own group, for one row, deliberately. Reviews was filed under
         * Catalogue on the reasoning that a review hangs off a product — true,
         * and useless to the person looking for it. The owner went to this
         * screen specifically to find Reviews, scanned the group headings, and
         * reported it missing. It was not missing; it was under a heading they
         * had no reason to open.
         *
         * Reviews is its own section in the console's own nav, with its own
         * eight screens. A reader who thinks of it that way is right, and this
         * page should agree with the nav rather than with the schema.
         */
        'reviews'     => 'Reviews',
        'marketing'   => 'Marketing',
        'performance' => 'Performance',
        'seo'         => 'SEO',
        'extra'       => 'This app only',
    ];

    /** key => [group, name, description, default on, settings screen, console route, surface, band, where, status]. */

    /*
     * Status is the honest bit.
     *
     *   live      — something on the storefront reads moduleEnabled() for this
     *               key, so the switch here works.
     *   elsewhere — the feature is real but is already switched on another
     *               screen. A second switch for the same thing is how a control
     *               ends up half working, so this one is shown but not offered.
     *   todo      — the feature is not ported yet. A switch would do nothing.
     *   screen    — not a switch at all. A built admin screen that this page
     *               lists so the owner can see it exists and open it. Always
     *               on; there is nothing to turn off.
     *
     * It is maintained by hand as each module is wired, and the alternative — a
     * toggle that silently does nothing — is exactly the fault this project has
     * hit three times.
     *
     * WHY `screen` EXISTS, added with the Media Library (Lane AX).
     *
     * The owner asked why Media and Reviews are missing from this page. They
     * were missing because REGISTRY had no key for either — an omission, not a
     * status — and this page iterates REGISTRY and nothing else, so neither
     * could ever appear however well built it was.
     *
     * The two turned out to need DIFFERENT answers, which is the whole point of
     * having a status field at all:
     *
     *   - Reviews already has a switch, on Appearance → Product page. It is
     *     `elsewhere`, and the long note on that row says where and why.
     *
     *   - The Media Library has no switch anywhere, and should not get one.
     *     It could not be `live`: `live` means a switch something reads, and
     *     Phase3ModuleSwitchesTest enforces exactly that — it greps app/ and
     *     resources/views/ for moduleEnabled('<key>') and fails any `live` row
     *     without one. Writing a reader purely to satisfy that grep would be
     *     fabricating the evidence the test exists to check, and gating an
     *     admin screen behind a toggle the owner could switch off and then not
     *     find is hostile. It could not be `elsewhere` either: the console
     *     renders that as "Switched in <screen>", and there is no switch on the
     *     Media Library to be switched in, so the owner would be told something
     *     false.
     *
     * `screen` says the true thing — this is built, it is always on, here is
     * the way to it — and the console renders it as that, with no switch drawn
     * at all rather than an inert one sitting in the grey "off" position beside
     * the words "always on".
     */
    public const REGISTRY = [
        // ── Checkout ──
        'freeship_bar' => ['checkout', 'Free-shipping progress bar', 'Animated “X away from free delivery” bar with celebration on unlock.', true, 'Appearance → Cart panel', 'cartpanel', 'drawer', 'top', 'The progress bar at the top of the cart panel, under the tabs.', 'live'],
        'vat_line' => ['checkout', 'Inclusive VAT line', 'Shows the VAT already included in the total.', true, 'Store → Ecommerce → Checkout', 'ecommerce', 'checkout', 'aside', 'A line in the order summary showing the VAT already included.', 'elsewhere'],
        'cod_fee' => ['checkout', 'Cash-on-delivery fee', 'Adds the COD surcharge when Cash on delivery is chosen.', true, 'Store → Ecommerce → Checkout', 'ecommerce', 'checkout', 'aside', 'A surcharge row in the order summary when Cash on delivery is chosen.', 'elsewhere'],
        'delivery_line' => ['checkout', 'Delivery-info line', 'Country-aware “fast delivery” message under the summary.', true, 'Store → Ecommerce', 'ecommerce', 'checkout', 'aside', 'The delivery message under the order summary.', 'live'],
        'coupon_hint' => ['checkout', 'Checkout coupon hint', 'Editable, clickable promo-code hint on the discount box.', true, 'Store → Ecommerce', 'ecommerce', 'checkout', 'mid', 'A clickable promo-code hint on the discount box.', 'live'],
        /*
         * PORTED IN LANE EH, and previously `todo`.
         *
         * §2 of the master plan filed this under "blocked on a missing source":
         * the plugin's own entry is a settings LINK pointing at kbb-theme, which
         * was never supplied, so there is no implementation to copy. That is
         * still true and this is still not a copy — it is the module's
         * description built against what this app already has. The two pages it
         * links to, /terms-and-conditions/ and /privacy-policy/, are the two the
         * register form has always linked to.
         *
         * On by default, as the plugin ships it, and with no wording of its own:
         * with nothing saved this renders no element at all, so applying the
         * package changes no live checkout. App\Support\CheckoutLegalNotice
         * carries the full reasoning for that split.
         *
         * The route is 'ecommerce:checkout' and not 'ecommerce': the Checkout
         * tab is one of five on that screen, and `product_sorting` already
         * established that a row may name its sub-tab so the owner does not have
         * to guess which one the module meant.
         */
        'legal_notice' => ['checkout', 'Checkout legal notice', 'An editable notice above the Place order button, with links to your terms and privacy pages. On by default, and shows nothing until you write it.', true, 'Store → Ecommerce → Checkout', 'ecommerce:checkout', 'checkout', 'bottom', 'The privacy and terms notice above the place-order button.', 'live'],
        'reassurance' => ['checkout', 'Reassurance block', 'Rating + authenticity block above the order summary.', true, 'Store → Ecommerce', 'ecommerce', 'checkout', 'aside', 'The rating and authenticity block above the order summary.', 'live'],
        'checkout_thumbs' => ['checkout', 'Mobile order thumbnails', 'Circular product thumbnails on the mobile place-order box.', true, 'Store → Ecommerce', 'ecommerce', 'checkout', 'bottom', 'Round product thumbnails on the mobile place-order box.', 'live'],
        'address_autocomplete' => ['checkout', 'Address autocomplete', 'Google Places suggestions on the address field (needs a key).', true, 'Store → Ecommerce', 'ecommerce', 'checkout', 'mid', 'Suggestions as the shopper types the address field.', 'todo'],
        'inline_validation' => ['checkout', 'Inline field validation', 'Live green/red validation as the customer types.', true, '', '', 'checkout', 'mid', 'Green and red marks on each field as it is filled in.', 'todo'],
        'single_name' => ['checkout', 'Single “Full name” field', 'One name field instead of first + last (auto-split on save).', true, 'Store → Ecommerce → Checkout', 'ecommerce', 'checkout', 'mid', 'One Full name field in place of first and last name.', 'elsewhere'],
        // ── Cart & mini-cart ──
        'minicart_promo' => ['cart', 'Mini-cart promo', 'Editable promo line in the cart drawer.', true, 'Appearance → Cart panel', 'cartpanel', 'drawer', 'bottom', 'The promo line above the subtotal in the cart panel.', 'live'],
        'back_to_cart' => ['cart', '“Go back to cart” link', 'Return-to-cart control beside the Checkout heading.', true, 'Store → Ecommerce', 'ecommerce', 'checkout', 'top', 'A return-to-cart link beside the Checkout heading.', 'live'],
        /*
         * The cart page's own discount-code box, and only that one.
         *
         * Not to be confused with `coupon_hint`, which governs the clickable
         * promo-code SUGGESTION printed under the box; the box itself has never
         * had a switch. Turning this off hides the input and the Apply button
         * on /cart/ and, with them, the hint that only makes sense beside them.
         * The checkout page keeps its own coupon box — that is a separate
         * surface and is not gated here.
         *
         * Off by default, which is the plugin-style default for anything this
         * registry adds: a fresh install shows no cart-page coupon box until
         * the owner asks for one. Existing installs are unaffected either way,
         * because a store that has explicitly saved Store → Modules already has
         * a `module_toggles` row and moduleEnabled() returns that row rather
         * than this default. No migration writes a value for this key, so no
         * store's decision is overwritten.
         *
         * Nothing is removed: coupons still apply, an already-applied coupon
         * still shows and can still be removed, and /cart/coupon still works.
         */
        'mobile_tabbar' => ['store', 'Floating bottom menu (mobile)', 'The floating bar pinned to the bottom of the screen on phones — Home, Shop, Quiz, Saved and Bag. Off by default; the header, its cart icon and the mobile menu all keep working without it.', false, 'No settings screen', '', 'mobile', 'mid', 'The floating Home / Shop / Quiz / Saved / Bag bar at the bottom of every page on a phone.', 'live'],
        'cart_coupon_field' => ['cart', 'Cart-page discount code box', 'The “Discount code” input and Apply button in the cart page order summary. Off by default; the checkout page has its own box and is not affected.', false, 'No settings screen', '', 'cartpage', 'mid', 'The discount code box in the order summary on the cart page.', 'live'],
        // ── Store & content ──
        'banners' => ['store', 'Banners', 'Drives the homepage hero slider — headline, eyebrow, buttons, floating product pods and the offer badge — using the theme’s own .heroslider markup. Supports scheduling. Off by default.', false, 'Appearance → Homepage', '', 'home', 'top', 'The homepage hero slider — headline, buttons and product pods.', 'elsewhere'],
        /*
         * ON by default, and the reasoning is `brands`' and `seo_engine`'s
         * rather than this registry's usual "a fresh install shows nothing the
         * owner did not ask for".
         *
         * Until 2.60.199 nothing on the storefront read this key: the bar
         * rendered its panels and the phone menu its expandable sections
         * whatever the switch said, and the only reader in the codebase was
         * the admin screen's own `module_on`. The default has to be measured
         * against what the store DOES without the switch, not against a blank
         * slate — and what it does is show the dropdowns. Shipping the gate
         * with `false` would take the header dropdowns off a live store on
         * apply, which is not a default, it is an outage.
         *
         * 2026_10_21_000000 aligns the stored toggle for the installs that
         * were seeded `false` while nothing consulted it. A genuinely fresh
         * install with no row gets this value.
         */
        'mega_menu' => ['store', 'Mega Menu', 'Drives the header mega panels from the admin using the theme\'s own design — category columns, brands and editor\'s picks — plus the expandable sections in the phone menu. On by default: the panels have always rendered, so turning this off is what changes the storefront.', true, 'Store → Mega Menu', 'megamenu', 'header', 'nav', 'The panels that drop from the category bar, and the expandable sections in the phone menu.', 'live'],
        'notification_bar' => ['store', 'Notification Bar', 'A dismissible announcement bar at the top of every page. Replaces the Cosmetics plugin. Off by default — turn on and set your message.', false, 'Its own screen', '', 'header', 'top', 'A dismissible strip above the header on every page.', 'live'],
        'product_labels' => ['store', 'Product Labels', 'Configurable Sale / New / Sold-out / Bestseller badges on product cards. Off by default — the theme’s built-in badges show until you turn it on.', false, 'Catalogue → Product Labels', 'labels', 'grid', 'card', 'Sale, New, Sold-out and Bestseller badges on product cards.', 'live'],
        /*
         * The Media Library, in 'Store & content' because that is what it is:
         * it is not a catalogue feature, it holds the images for products,
         * brands, categories AND the SEO share image, and the console files it
         * under Content.
         *
         * `site` / `all` for the hover card, which renders "nothing visible" —
         * correct, since this changes nothing a shopper ever sees.
         */
        'media_library' => ['store', 'Media Library', 'The grid of every image uploaded through the admin, with search by name, by upload date and by the product, brand or category using it — plus what each image is used by before you delete it. Always on: this is a screen, not a switch.', true, 'Content → Media Library', 'media', 'site', 'all', 'An admin screen. Nothing visible on the storefront.', 'screen'],
        // ── Payments & shipping ──
        'pay_ship_rules' => ['payship', 'Payment & Shipping Rules', 'Limit Cash on Delivery by order value and hide paid delivery when free is available. Consolidates conditional payment/shipping plugins. Off by default.', false, 'Store → Payment & Shipping Rules', 'payship', 'checkout', 'mid', 'Hides Cash on delivery and paid delivery when your rules say so.', 'live'],
        /*
         * ── Order emails ──
         *
         * The one group in this registry that ships ON.
         *
         * Everything this registry adds is off by default, on the plugin's own
         * principle that a fresh install shows nothing the owner did not ask for.
         * These five break that rule on purpose, and the reason is the same one
         * that applies to `seo_engine` above: the default has to be measured
         * against what the store does WITHOUT the switch, not against a blank
         * slate.
         *
         * Without them this store sends a customer nothing whatsoever. They pay,
         * and the only confirmation that has ever existed is the order-received
         * page, which is gated to the browser that placed the order — close the
         * tab and it is gone. The merchant is not told an order arrived at all;
         * AdminOrderController::runAction still refuses its own resend actions
         * with "outbound email is not configured for this store". Shipping these
         * off by default would mean applying a package called "order emails" and
         * changing nothing until somebody found the screen.
         *
         * The safety net that used to make ON defensible was that nothing was
         * being sent: MailConfigurator fell back to the `log` transport whenever
         * SMTP was not filled in, so on an install where nobody had completed
         * Store → Mail these wrote to storage/logs and reached no inbox.
         *
         * THAT IS NO LONGER TRUE, AND THE CHANGE WAS THE POINT. The owner's live
         * store had exactly that shape -- five order emails switched on, every
         * one of them going to a log file, nobody told. `mail_transport` now
         * defaults to the host's own mail (MailSettings::TRANSPORT_SERVER) and an
         * untouched install really sends. So these five being ON is no longer
         * harmless-because-inert; it is ON because a store that takes money and
         * says nothing is the worse default, which is what the paragraph above
         * argues and what the owner asked for in as many words.
         *
         * No migration writes a value for any of these keys, so a store that has
         * already saved Store → Modules keeps whatever it chose: moduleEnabled()
         * returns the module_toggles row when one exists and only falls back to
         * the default below when it does not.
         *
         * Every row is `live`: OrderMailer reads each key by name, and
         * Phase3ModuleSwitchesTest greps for exactly that.
         */
        'email_order_confirmation' => ['email', 'Order confirmation email', 'The receipt sent to the customer the moment an order is placed — line items as bought, the money breakdown, delivery address, delivery and payment method, and a link to the order.', true, 'Store → Mail', 'mail', 'site', 'all', 'An email to the customer when they place an order. Nothing visible on the site.', 'live'],
        'email_merchant_new_order' => ['email', 'New-order alert to you', 'Tells the store an order has come in, with the customer’s details, so you do not have to watch the admin. Goes to the address set under Store → Mail.', true, 'Store → Mail', 'mail', 'site', 'all', 'An email to you when an order is placed. Nothing visible on the site.', 'live'],
        'email_order_shipped' => ['email', 'Dispatch notification', 'Tells the customer their order has left you, sent when its status becomes Shipped.', true, 'Store → Mail', 'mail', 'site', 'all', 'An email to the customer when you mark an order Shipped. Nothing visible on the site.', 'live'],
        'email_order_cancelled' => ['email', 'Cancellation notification', 'Tells the customer an order has been cancelled and nothing further will be sent, when its status becomes Cancelled.', true, 'Store → Mail', 'mail', 'site', 'all', 'An email to the customer when an order is cancelled. Nothing visible on the site.', 'live'],
        'email_order_refunded' => ['email', 'Refund notification', 'Tells the customer money has gone back, sent when a refund actually settles — not when an order is merely marked refunded. Covers partial refunds too.', true, 'Store → Mail', 'mail', 'site', 'all', 'An email to the customer when a refund succeeds. Nothing visible on the site.', 'live'],
        /*
         * The sixth row in this group, and the only one that is not "send this
         * email or do not".
         *
         * It is here rather than on Store → Mail because that screen renders
         * MailSettings::SCHEMA through MailApiController, which knows three
         * field types -- text, secret, and a choice whose options that
         * controller supplies -- and none of them is a checkbox. An on/off put
         * there would be a text box the owner had to type a word into. This
         * screen already draws real switches, already carries the five order
         * emails, and is already where the owner goes to turn a piece of an
         * email off.
         *
         * ON by default, and safe to be: the logo only appears if one has
         * actually been uploaded under Store → Business Details. With no logo
         * saved, on and off render the same email — the wordmark — so the
         * default cannot surprise anybody. App\Services\Mail\EmailBranding is
         * the reader, and it reads this key by name.
         */
        'email_show_logo' => ['email', 'Logo in order emails', 'Prints your uploaded store logo at the top of every order email instead of the text wordmark. Uses the same logo as Store → Business Details — there is no second upload. With no logo saved, the wordmark is shown either way.', true, 'Store → Business Details', 'store-settings', 'site', 'all', 'Your logo at the top of every order email. Nothing visible on the site.', 'live'],
        // ── Catalogue ──
        // The screen is Store → Catalog → Reorder, and it has existed for some
        // time. This row said 'Its own screen' with no console route, which the
        // admin renders as "Its own screen — screen not built yet": the one
        // module whose settings the owner was told did not exist while they did.
        'product_sorting' => ['catalogue', 'Product Sorting', 'Bakes your curated product order (rwpp_sortorder) into WooCommerce’s native order so “Default sorting” shows it. Off by default.', false, 'Store → Catalog → Reorder', 'catalog:reorder', 'grid', 'all', 'Bakes your curated order into Default sorting on shop and category pages.', 'live'],
        /*
         * SAID `todo` ABOUT A FEATURE THAT HAS BEEN LIVE SINCE 2.60.109 — Lane EH.
         *
         * This row was wrong in three separate ways at once, which is why it is
         * worth the space:
         *
         *   - STATUS. `todo` renders on the Modules screen as "Not ported yet",
         *     printed against a brand directory at /korean-skincare-brands/,
         *     per-brand landing pages, 301s from /brands/ and /brand/{slug}/,
         *     an admin editor, logos and a display-mode setting — all real, all
         *     serving. §2 of the master plan still records this as "blocked on
         *     the outstanding /brands/ URL decision"; the owner settled that
         *     decision in 2.60.109 and the pages were built on it.
         *
         *   - SETTINGS SCREEN. 'Its own screen' with no console route, which the
         *     admin renders as "Its own screen — screen not built yet". The
         *     screen is Catalog → Brands and has existed as long as the pages
         *     have. This is the identical fault the `product_sorting` row
         *     carried until it was corrected to 'catalog:reorder'.
         *
         *   - DEFAULT. `false`. Now true, and NOT because the plugin says so —
         *     the plugin ships it off. It is the seo_engine argument: the
         *     default has to be measured against what this shop does WITHOUT
         *     the switch, and without it those pages serve. Adding the gate
         *     below while leaving the default off would 404 three live URL
         *     families on apply.
         *
         * `live` is now true of it: BrandController's constructor reads the key
         * for every action, and store/home.blade.php reads it for the brand
         * strip, so switching it off leaves nothing of brands on the storefront.
         */
        'brands' => ['catalogue', 'Brands', 'The brand directory, each brand’s own page with its logo and description, and the brand strip on the home page. Turn it off and those pages 404 rather than sitting there empty.', true, 'Catalog → Brands', 'catalog:brands', 'grid', 'all', 'Brand pages, logos and the brand directory.', 'live'],
        'wishlist' => ['catalogue', 'Wishlist', 'Lets shoppers save products (works for guests too, via cookie). Heart button on cards/product pages plus a [kbb_wishlist] page. Off by default.', false, 'Its own screen', '', 'grid', 'card', 'The heart on every product card, and the wishlist page.', 'live'],
        'recently_viewed' => ['catalogue', 'Recently Viewed', 'Shows each shopper the products they just looked at (cookie-based, guests included). Auto-placed on product/cart pages plus a [kbb_recently_viewed] shortcode. Off by default.', false, 'Appearance → Cart panel', 'cartpanel', 'drawer', 'mid', 'The Browsed tab in the cart panel, and a rail on the product page.', 'elsewhere'],
        /*
         * Reviews, in 'Catalogue' because a review hangs off a product and the
         * row sits beside the wishlist and cross-sell rows that do the same.
         *
         * It is REAL and has been for some time: renderReviews() in the Lane AM
         * region of app.blade.php renders 'rev-all' live in the console,
         * ReviewsApiController serves the moderation endpoints, and the
         * storefront shows reviews on every product page. It was absent from
         * this screen for one reason only — nobody ever added the key.
         *
         * `elsewhere`, NOT `screen`, and the difference is load-bearing.
         *
         * Reviews ALREADY HAS AN ON/OFF SWITCH. ProductSections::REGISTRY
         * carries its own 'reviews' entry (app/Services/ProductSections.php:33,
         * default on), and resources/views/store/product.blade.php:213 gates the
         * whole section on it — an `unless` on $modules->hidden('reviews').
         * That switch is edited from Appearance → Product page, and
         * HomepageSections carries a separate 'reviews' entry for the homepage
         * wall.
         *
         * So a `screen` row here would print "Always on — a screen, not a
         * switch" about a feature the owner can switch off on another screen,
         * which is simply false; and a `live` row would draw a SECOND, working
         * toggle for the same feature, stored in module_toggles where nothing
         * reads it. Two switches for one thing, and whichever the operator
         * flipped last would appear to do nothing — the exact fault the status
         * field exists to prevent.
         *
         * `elsewhere` prints "Switched in Appearance → Product page" and links
         * there, which is the true statement and the useful one. Shaped to match
         * recently_viewed, vat_line and cod_fee, which are `elsewhere` for the
         * same reason.
         */
        'reviews' => ['reviews', 'Reviews', 'Customer reviews on the product page — score summary, filters and review cards — plus the moderation screens under Reviews. The on/off switch lives with the rest of the product page sections; this row is here so you can find it.', true, 'Appearance → Product page', 'productpage', 'product', 'bottom', 'The reviews section near the foot of the product page.', 'elsewhere'],
        'frequently_bought' => ['catalogue', 'Frequently Bought Together', 'A “Complete your routine” block on product pages — the main item plus matches (from WooCommerce cross-sells or the same category), with one-click add-all. Lifts average order value. Off by default.', false, 'Its own screen', '', 'product', 'mid', 'The Complete your routine block on the product page.', 'live'],
        // ── Marketing ──
        'marketing_pixels' => ['marketing', 'Marketing Pixels', 'Meta Pixel, Google (GA4) and TikTok tags with standard e-commerce events (view, checkout, purchase). Off by default — add your IDs to activate.', false, 'Growth & Marketing → Marketing Pixels', 'pixels', 'site', 'all', 'Meta, GA4 and TikTok tags on every page. Nothing visible.', 'live'],
        /*
         * These two said "blocked on mail, which this app has never sent" and
         * were marked `todo` on that basis. That is no longer true and has not
         * been since 2.60.199: MailSettings::DEFAULT_TRANSPORT is the server's
         * own mail(), five order emails are live, and every message this shop
         * hands to a transport is recorded in `mail_deliveries` with its
         * Message-ID and the provider's exact refusal.
         *
         * Both are now BUILT and both are `live` — Services\CartRecovery and
         * Services\StockAlerts each read their key here, and the storefront
         * renders a form from each. They remain OFF by default and, separately,
         * carry no wording of their own, so switching one on still changes
         * nothing a shopper sees until the owner writes the words. That is the
         * `legal_notice` shape, deliberately.
         *
         * The descriptions are edited to match what was actually built rather
         * than what the plugin's blurb promised, because a description is the
         * only thing the owner reads before flipping a switch:
         *
         *   - "one-click recovery link" is gone. The reminder names the basket
         *     and links to each item's own page; it does NOT carry a link that
         *     restores a shopping session, because such a link is a bearer
         *     credential for somebody's basket and checkout details.
         *     App\Mail\CartRecoveryReminder's header argues it, and whether the
         *     owner wants the stronger version is a hand-back question.
         *   - "at your chosen intervals" now says the schedule starts empty,
         *     because an empty schedule sends nothing and that is the shipped
         *     state.
         *   - "emails everyone the moment it restocks" becomes "when the shop
         *     is next visited", which is the truth on a host with no scheduler.
         *     Services\OutboundTick's header sets out why.
         *   - "in place of Add to cart" becomes "under", which is where the form
         *     was actually put. partials/notify-me.blade.php argues the choice
         *     and the hand-back asks the owner to confirm it.
         */
        'abandoned_cart' => ['marketing', 'Abandoned Cart Recovery', 'An opt-in tick box on the cart page; emails a reminder naming the basket, on a schedule you write. Stops the moment an order is placed. Off by default, and sends nothing until you write the message and the schedule.', false, 'Store → Ecommerce → Cart', 'ecommerce', 'site', 'all', 'A tick box under the basket on the cart page.', 'live'],
        'back_in_stock' => ['marketing', 'Back-in-Stock Alerts', 'A “notify me” form under Add to cart on sold-out products; emails everyone who asked, once each, when the shop is next visited after it restocks. Doubles as a demand list for what to reorder. Off by default, and sends nothing until you write the message.', false, 'Store → Ecommerce → Product page', 'ecommerce', 'product', 'mid', 'A notify-me form under Add to cart when sold out.', 'live'],
        'newsletter' => ['marketing', 'Email Capture', 'A signup form ([kbb_signup]) with an optional timed popup. Stores subscribers locally with one-click CSV export for any email tool. No API key. Off by default.', false, 'Appearance → Homepage', 'newsletter', 'home', 'bottom', 'The signup panel near the foot of the homepage.', 'elsewhere'],
        // ── Performance ──
        'performance' => ['performance', 'Performance & Speed', 'Core Web Vitals wins: strips WordPress bloat, lazy-loads iframes, throttles heartbeat, and adds preconnect/preload. Every tweak is individually toggleable. Off by default.', false, 'Its own screen', '', 'site', 'all', 'Lazy loading and asset trimming. Nothing visible.', 'todo'],
        // ── This app only ──
        // Not in the plugin. They are real module_toggles keys the storefront
        // already reads, so leaving them off this screen would make them the one
        // pair nobody can switch.
        'quick_view' => ['extra', 'Quick view', 'A Quick view button on product cards opening a modal with price, stock, short description and add-to-cart, so a shopper does not lose a filtered listing. Hidden on touch devices, where hover has no meaning.', true, 'No settings screen', '', 'grid', 'card', 'The Quick view button revealed on hover over every product card.', 'live'],
        'address_book' => ['extra', 'Address book', 'The saved-addresses screen at /my-account/edit-address: add, edit, delete and set a default per type. Turning this off hides the dashboard card and makes the page itself 404, not just the link.', true, 'Appearance → Login / Register panel → Links', 'acctpanel', 'site', 'all', 'The Addresses screen inside a signed-in customer account.', 'live'],
        'quantity_bundles' => ['extra', 'Quantity bundles', 'Buy-more-save-more tiers on the product page, generated from the price rather than authored.', true, 'Appearance → Quantity bundles', 'bundles', 'product', 'mid', 'The bundle tiles under the price on the product page.', 'live'],
        'dispatch_cutoff' => ['extra', 'Dispatch cutoff', 'The “order within X for dispatch today” line, counting down to your cutoff time.', true, 'Store → Ecommerce', 'ecommerce', 'product', 'mid', 'A line under the Add to cart button on the product page.', 'live'],

        // ── SEO ──
        /*
         * The one place this registry deliberately diverges from the plugin's
         * own default, recorded here rather than left to be discovered.
         *
         * The plugin ships SEO Engine OFF because WordPress — with or without
         * Yoast — still writes a <title>, a canonical and an og:image when the
         * module is off. Nothing in this app does. App\Support\Seo is the only
         * thing that has ever produced a <head> here, and it has produced one
         * on every page since the layout was wired to it, ungated.
         *
         * So shipping the switch with the plugin's default would not "restore
         * the plugin's behaviour" — it would strip every meta description,
         * canonical, Open Graph tag and JSON-LD node off a live catalogue the
         * first time this package was applied, silently, with no visible
         * symptom on any page. On by default keeps what the storefront
         * already does; the owner can now turn it off on purpose, which is
         * what the switch is for.
         *
         * The settings screen is Store → SEO & Meta and has been real since
         * before 2.56.1; this row claimed it did not exist.
         */
        'seo_engine' => ['seo', 'SEO Engine', 'Meta titles & descriptions (with per-page overrides), Open Graph / Twitter cards, canonical, robots and Product / Organization schema. Turn it off to fall back to a plain page title and nothing else. On by default in this app — unlike the plugin, nothing else here writes a &lt;head&gt;.', true, 'Store → SEO & Meta', 'seo', 'site', 'all', 'Titles, descriptions and structured data. Nothing visible on the page.', 'live'],
        // ── Unknown ──
        // ── install flag ──
        // ── Carts started ──
        // ── Email captured ──
        // ── Recovery email sent ──
        // ── Recovered ──
    ];

    /** Devices a module can be limited to. */
    public const DEVICES = ['both' => 'Everywhere', 'desktop' => 'Desktop only', 'mobile' => 'Phones only'];

    private ?array $state = null;

    public function __construct(private SettingsService $settings) {}

    /**
     * Every module with its current state.
     *
     * Resolved once per request. A module absent from the saved list falls back
     * to the plugin's default, so applying this package changes nothing.
     *
     * @return array<string, array{on: bool, device: string}>
     */
    public function all(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }

        $devices = $this->settings->get('module_devices', null);
        $devices = is_array($devices) ? $devices : (is_string($devices) ? json_decode($devices, true) : []);
        $devices = is_array($devices) ? $devices : [];

        $out = [];

        foreach (self::REGISTRY as $key => [$group, $name, $desc, $default, $screen]) {
            $row = ['device' => $devices[$key] ?? 'both'];

            $out[$key] = [
                // The same call the storefront makes, so this screen and the page
                // can never disagree about whether a module is on.
                'on' => $this->settings->moduleEnabled($key, $default),
                'device' => isset(self::DEVICES[$row['device'] ?? '']) ? $row['device'] : 'both',
            ];
        }

        return $this->state = $out;
    }

    /** Is this module on? Unknown keys are off, so a typo hides a feature rather than forcing one on. */
    public function on(string $key): bool
    {
        return (bool) ($this->all()[$key]['on'] ?? false);
    }

    /** Which devices it is allowed on. */
    public function device(string $key): string
    {
        return (string) ($this->all()[$key]['device'] ?? 'both');
    }

    /**
     * The class that hides a module on the devices it is not meant for.
     *
     * Returns an empty string when the module is off entirely — the caller is
     * expected to check on() first and render nothing at all in that case,
     * rather than shipping markup the shopper cannot see.
     */
    public function classFor(string $key): string
    {
        return match ($this->device($key)) {
            'desktop' => 'm-off',
            'mobile' => 'd-off',
            default => '',
        };
    }

    /** @param array<string, array{on?: bool, device?: string}> $values */
    public function save(array $values): void
    {
        $clean = [];

        foreach ($values as $key => $row) {
            if (! isset(self::REGISTRY[$key]) || ! is_array($row)) {
                continue;
            }

            // Written to module_toggles, which is where the storefront reads it.
            $this->settings->setModule($key, (bool) ($row['on'] ?? self::REGISTRY[$key][3]));

            $clean[$key] = isset(self::DEVICES[$row['device'] ?? '']) ? $row['device'] : 'both';
        }

        $this->settings->set('module_devices', $clean);
        $this->state = null;
    }

    /** How many are on, for the admin header. */
    public function counts(): array
    {
        $all = $this->all();
        $on = count(array_filter($all, static fn ($m) => $m['on']));

        return ['on' => $on, 'total' => count($all)];
    }
}
