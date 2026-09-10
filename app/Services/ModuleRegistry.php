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
        'catalogue'   => 'Catalogue',
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
     *
     * It is maintained by hand as each module is wired, and the alternative — a
     * toggle that silently does nothing — is exactly the fault this project has
     * hit three times.
     */
    public const REGISTRY = [
        // ── Checkout ──
        'freeship_bar' => ['checkout', 'Free-shipping progress bar', 'Animated “X away from free delivery” bar with celebration on unlock.', true, 'Appearance → Cart panel', 'cartpanel', 'drawer', 'top', 'The progress bar at the top of the cart panel, under the tabs.', 'live'],
        'vat_line' => ['checkout', 'Inclusive VAT line', 'Shows the VAT already included in the total.', true, 'Store → Ecommerce → Checkout', 'ecommerce', 'checkout', 'aside', 'A line in the order summary showing the VAT already included.', 'elsewhere'],
        'cod_fee' => ['checkout', 'Cash-on-delivery fee', 'Adds the COD surcharge when Cash on delivery is chosen.', true, 'Store → Ecommerce → Checkout', 'ecommerce', 'checkout', 'aside', 'A surcharge row in the order summary when Cash on delivery is chosen.', 'elsewhere'],
        'delivery_line' => ['checkout', 'Delivery-info line', 'Country-aware “fast delivery” message under the summary.', true, 'Store → Ecommerce', 'ecommerce', 'checkout', 'aside', 'The delivery message under the order summary.', 'live'],
        'coupon_hint' => ['checkout', 'Checkout coupon hint', 'Editable, clickable promo-code hint on the discount box.', true, 'Store → Ecommerce', 'ecommerce', 'checkout', 'mid', 'A clickable promo-code hint on the discount box.', 'live'],
        'legal_notice' => ['checkout', 'Checkout legal notice', 'Editable privacy / terms notice with page links.', true, 'Store → Ecommerce', 'ecommerce', 'checkout', 'bottom', 'The privacy and terms notice above the place-order button.', 'todo'],
        'reassurance' => ['checkout', 'Reassurance block', 'Rating + authenticity block above the order summary.', true, 'Store → Ecommerce', 'ecommerce', 'checkout', 'aside', 'The rating and authenticity block above the order summary.', 'live'],
        'checkout_thumbs' => ['checkout', 'Mobile order thumbnails', 'Circular product thumbnails on the mobile place-order box.', true, 'Store → Ecommerce', 'ecommerce', 'checkout', 'bottom', 'Round product thumbnails on the mobile place-order box.', 'live'],
        'address_autocomplete' => ['checkout', 'Address autocomplete', 'Google Places suggestions on the address field (needs a key).', true, 'Store → Ecommerce', 'ecommerce', 'checkout', 'mid', 'Suggestions as the shopper types the address field.', 'todo'],
        'inline_validation' => ['checkout', 'Inline field validation', 'Live green/red validation as the customer types.', true, '', '', 'checkout', 'mid', 'Green and red marks on each field as it is filled in.', 'todo'],
        'single_name' => ['checkout', 'Single “Full name” field', 'One name field instead of first + last (auto-split on save).', true, 'Store → Ecommerce → Checkout', 'ecommerce', 'checkout', 'mid', 'One Full name field in place of first and last name.', 'elsewhere'],
        // ── Cart & mini-cart ──
        'minicart_promo' => ['cart', 'Mini-cart promo', 'Editable promo line in the cart drawer.', true, 'Appearance → Cart panel', 'cartpanel', 'drawer', 'bottom', 'The promo line above the subtotal in the cart panel.', 'live'],
        'back_to_cart' => ['cart', '“Go back to cart” link', 'Return-to-cart control beside the Checkout heading.', true, 'Store → Ecommerce', 'ecommerce', 'checkout', 'top', 'A return-to-cart link beside the Checkout heading.', 'live'],
        // ── Store & content ──
        'banners' => ['store', 'Banners', 'Drives the homepage hero slider — headline, eyebrow, buttons, floating product pods and the offer badge — using the theme’s own .heroslider markup. Supports scheduling. Off by default.', false, 'Appearance → Homepage', '', 'home', 'top', 'The homepage hero slider — headline, buttons and product pods.', 'elsewhere'],
        'mega_menu' => ['store', 'Mega Menu', 'Drives the header mega panels from the admin using the theme\'s own design — category columns, brands and editor\'s picks — plus a mobile slide-in overlay. Off by default.', false, 'Store → Mega Menu', 'megamenu', 'header', 'nav', 'The panels that drop from the category bar, and the phone overlay.', 'live'],
        'notification_bar' => ['store', 'Notification Bar', 'A dismissible announcement bar at the top of every page. Replaces the Cosmetics plugin. Off by default — turn on and set your message.', false, 'Its own screen', '', 'header', 'top', 'A dismissible strip above the header on every page.', 'live'],
        'product_labels' => ['store', 'Product Labels', 'Configurable Sale / New / Sold-out / Bestseller badges on product cards. Off by default — the theme’s built-in badges show until you turn it on.', false, 'Catalogue → Product Labels', 'labels', 'grid', 'card', 'Sale, New, Sold-out and Bestseller badges on product cards.', 'live'],
        // ── Payments & shipping ──
        'pay_ship_rules' => ['payship', 'Payment & Shipping Rules', 'Limit Cash on Delivery by order value and hide paid delivery when free is available. Consolidates conditional payment/shipping plugins. Off by default.', false, 'Store → Payment & Shipping Rules', 'payship', 'checkout', 'mid', 'Hides Cash on delivery and paid delivery when your rules say so.', 'live'],
        // ── Catalogue ──
        'product_sorting' => ['catalogue', 'Product Sorting', 'Bakes your curated product order (rwpp_sortorder) into WooCommerce’s native order so “Default sorting” shows it. Off by default.', false, 'Its own screen', '', 'grid', 'all', 'Bakes your curated order into Default sorting on shop and category pages.', 'todo'],
        'brands' => ['catalogue', 'Brands', 'Brand taxonomy with logos, brand pages and a [kbb_brands] directory. Works with WooCommerce’s native brand taxonomy. Off by default.', false, 'Its own screen', '', 'grid', 'all', 'Brand pages, logos and the brand directory.', 'todo'],
        'wishlist' => ['catalogue', 'Wishlist', 'Lets shoppers save products (works for guests too, via cookie). Heart button on cards/product pages plus a [kbb_wishlist] page. Off by default.', false, 'Its own screen', '', 'grid', 'card', 'The heart on every product card, and the wishlist page.', 'live'],
        'recently_viewed' => ['catalogue', 'Recently Viewed', 'Shows each shopper the products they just looked at (cookie-based, guests included). Auto-placed on product/cart pages plus a [kbb_recently_viewed] shortcode. Off by default.', false, 'Appearance → Cart panel', 'cartpanel', 'drawer', 'mid', 'The Browsed tab in the cart panel, and a rail on the product page.', 'elsewhere'],
        'frequently_bought' => ['catalogue', 'Frequently Bought Together', 'A “Complete your routine” block on product pages — the main item plus matches (from WooCommerce cross-sells or the same category), with one-click add-all. Lifts average order value. Off by default.', false, 'Its own screen', '', 'product', 'mid', 'The Complete your routine block on the product page.', 'live'],
        // ── Marketing ──
        'marketing_pixels' => ['marketing', 'Marketing Pixels', 'Meta Pixel, Google (GA4) and TikTok tags with standard e-commerce events (view, checkout, purchase). Off by default — add your IDs to activate.', false, 'Growth & Marketing → Marketing Pixels', 'pixels', 'site', 'all', 'Meta, GA4 and TikTok tags on every page. Nothing visible.', 'live'],
        'abandoned_cart' => ['marketing', 'Abandoned Cart Recovery', 'Captures carts and emails a one-click recovery link at your chosen intervals via your normal mailer. Off by default.', false, 'Its own screen', '', 'site', 'all', 'Captures carts and emails a recovery link. Nothing visible.', 'todo'],
        'back_in_stock' => ['marketing', 'Back-in-Stock Alerts', 'A “notify me” form on sold-out products; emails everyone the moment it restocks. Doubles as a demand list for what to reorder. Off by default.', false, 'Its own screen', '', 'product', 'mid', 'A notify-me form in place of Add to cart when sold out.', 'todo'],
        'newsletter' => ['marketing', 'Email Capture', 'A signup form ([kbb_signup]) with an optional timed popup. Stores subscribers locally with one-click CSV export for any email tool. No API key. Off by default.', false, 'Appearance → Homepage', 'newsletter', 'home', 'bottom', 'The signup panel near the foot of the homepage.', 'elsewhere'],
        // ── Performance ──
        'performance' => ['performance', 'Performance & Speed', 'Core Web Vitals wins: strips WordPress bloat, lazy-loads iframes, throttles heartbeat, and adds preconnect/preload. Every tweak is individually toggleable. Off by default.', false, 'Its own screen', '', 'site', 'all', 'Lazy loading and asset trimming. Nothing visible.', 'todo'],
        // ── This app only ──
        // Not in the plugin. They are real module_toggles keys the storefront
        // already reads, so leaving them off this screen would make them the one
        // pair nobody can switch.
        'quantity_bundles' => ['extra', 'Quantity bundles', 'Buy-more-save-more tiers on the product page, generated from the price rather than authored.', true, 'Appearance → Quantity bundles', 'bundles', 'product', 'mid', 'The bundle tiles under the price on the product page.', 'live'],
        'dispatch_cutoff' => ['extra', 'Dispatch cutoff', 'The “order within X for dispatch today” line, counting down to your cutoff time.', true, 'Store → Ecommerce', 'ecommerce', 'product', 'mid', 'A line under the Add to cart button on the product page.', 'live'],

        // ── SEO ──
        'seo_engine' => ['seo', 'SEO Engine', 'Meta titles & descriptions (with per-page overrides), Open Graph / Twitter cards, canonical, robots and Product / Organization schema. Defers automatically if Yoast or Rank Math is active. Off by default.', false, 'Its own screen', '', 'site', 'all', 'Titles, descriptions and structured data. Nothing visible on the page.', 'todo'],
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
