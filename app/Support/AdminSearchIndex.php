<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\AccountPanel;
use App\Services\CartPage;
use App\Services\CartPanel;
use App\Services\CheckoutPage;
use App\Services\HeaderSettings;
use App\Services\HomepageContent;
use App\Services\InstagramSettings;
use App\Services\MobileHeader;
use App\Services\NewsletterSettings;
use App\Services\PageWash;
use App\Services\ProductLayout;
use App\Services\ProductStyles;
use App\Services\SectionDividers;
use App\Services\SecurityModule;
use App\Services\SiteFooter;
use App\Services\SiteLayout;
use App\Services\SlimFooter;
use App\Services\SpottedSettings;
use Illuminate\Support\Facades\Cache;

/**
 * The admin sidebar search's index: every tab, section and setting the console
 * draws, by screen. Lane SR.
 *
 * THE OWNER: "one super top function to our left menu of the backend app, to
 * find anything in the menu ... give results with proper links to that
 * specific area / page, and highlight it" -- and "the code must not put load
 * on admin side at all".
 *
 * So this is rendered ONCE, into the console document, as one inline JSON
 * block (admin/partials/admin-search.blade.php). There is no endpoint, no
 * query and nothing per keystroke: the browser parses the block the first
 * time the search box is focused and searches it in memory from then on.
 *
 * WHAT IS NOT IN HERE, deliberately: the screens themselves. The screen list,
 * its groups and its labels are read in the browser from the sidebar that is
 * already drawn (#nav), so a screen the sidebar does not show cannot be found
 * by search either, and a renamed sidebar row is renamed in search the same
 * day. This class only knows what is INSIDE each screen.
 *
 * TWO SOURCES, MERGED:
 *
 *   1. SCHEMA_SCREENS -- screens whose tabs and controls are drawn straight
 *      from a service's SCHEMA + TABS constants (ModuleSchema::tabs()). Read
 *      live from those constants, so a setting a later lane adds to
 *      HeaderSettings::SCHEMA is searchable the moment it ships, with nobody
 *      touching this file. Each mapping below was checked against the screen
 *      as drawn in Chromium: every tab label matched, and every control the
 *      screen draws in its default state. A control drawn only after another
 *      choice (Site layout -> Category header's per-style colours, Cart page's
 *      service-fee percentage) is still listed; opening it lands on its tab
 *      and lights the tab, since the row itself is not on the page yet.
 *
 *   2. CURATED -- every other screen's tabs, section headings and control
 *      labels, captured from the console as rendered (tools/sr-search-crawl.cjs
 *      walks every sidebar row and every tab) and then cleaned by hand of
 *      sample data, preview text and sentences. Each label is the EXACT text
 *      the screen draws, because that text is how the browser finds the place
 *      to scroll to and highlight.
 *
 * GUARDED BOTH WAYS by tests/Feature/AdminSidebarSearchTest.php: every NAV,
 * LATE_NAV and TITLES id in admin/app.blade.php must be a key here (a new screen with no
 * entry fails the suite, not the owner), and every curated label must still
 * occur in the admin source (a renamed heading fails rather than leaving a
 * result that lands on the screen and finds nothing to highlight).
 *
 * Everything printed from here is a code constant. No setting, no row and no
 * request value reaches it, which is what allows json() to be printed raw.
 */
final class AdminSearchIndex
{
    /**
     * Screen id => the services whose SCHEMA/TABS that screen draws, in the
     * order the screen draws them.
     *
     * @var array<string, list<class-string>>
     */
    public const SCHEMA_SCREENS = [
        'header'       => [HeaderSettings::class],
        'cartpanel'    => [CartPanel::class],
        'cartpage'     => [CartPage::class],
        'checkoutpage' => [CheckoutPage::class],
        'security'     => [SecurityModule::class],
        'spotted'      => [SpottedSettings::class],
        'sitelayout'   => [SiteLayout::class],
        'mobilehdr'    => [MobileHeader::class],
        'acctpanel'    => [AccountPanel::class],
        'newsletter'   => [NewsletterSettings::class],
        'dividers'     => [SectionDividers::class],
        'prodstyles'   => [ProductStyles::class],
        'pagewash'     => [PageWash::class],
        'instagram'    => [InstagramSettings::class],
        'productpage'  => [ProductLayout::class],
        'hpcontent'    => [HomepageContent::class],
        'wabutton'     => [\App\Services\WhatsAppButton::class],
    ];

    /**
     * Screen id => [tab label => [section or control label, ...]]. The '' tab
     * is the screen itself (no tab to switch to). A screen with nothing inside
     * worth finding -- a list of orders, a library of pictures -- is `[]`, and
     * is still a key: that is what the coverage test reads.
     *
     * @var array<string, array<string, list<string>>>
     */
    public const CURATED = [
        'dash' => [],
        'theme' => [
            '' => [
                'Brand tokens', 'Rose', 'Mega Menu', 'Mobile Header', 'Product page', 'Cart panel',
                'Shop Filters', 'Typography', 'A full colour editor', 'Performance',
            ],
        ],
        'users' => [],
        'settings' => [
            '' => [
                'Business Details', 'Payments', 'Delivery & Shipping', 'SEO & Meta', 'Localisation',
                'Security settings',
            ],
        ],
        'siteaddr' => [
            '' => [
                'Which address is this shop\'s real one', 'Main address', 'Old addresses to forward here',
                'Forward these, permanently', 'Keep this install out of Google', 'Directory Privacy',
            ],
        ],
        'cache' => [
            '' => [
                'Shop pages may be reused for', 'Browsers may keep them for', '.htaccess',
            ],
        ],
        'debug' => [
            '' => [
                'Storefront health', 'Check now', 'Found a bug on live?',
            ],
        ],
        'sandbox' => [],
        'democontent' => [
            '' => [
                'This is sample data, clearly separate from anything real', 'Create a sample order',
                'Demo Orders', 'Demo Customers', 'Demo Products', 'Demo Pages', 'Demo Blog Posts',
                'Demo Reviews', 'Demo Mega Menu', 'Demo Routine Products', 'Demo Shoppable Video',
            ],
        ],
        'catalog' => [
            'Products' => [],
            'Categories' => [],
            'Brands' => [],
            'Attributes' => [],
            'Inventory' => [],
            'Reorder' => [],
        ],
        'product-tabs' => [],
        'sets' => [],
        'product-editor' => [],
        'routines' => [
            'Cleanser' => [],
            'Toner' => [],
            'Treatment' => [],
            'Moisturiser' => [],
            'SPF' => [],
            'Concern pages' => [],
            'Wording' => [
                'Heading on the page', 'Sentence under it', 'Offer strip coupon for this routine',
                'Order on the list page', 'Shown to shoppers',
            ],
            'Settings' => [
                'Routine steps', 'Offer strip', 'Offer strip coupon code',
            ],
        ],
        'category-tree' => [
            'Categories' => [],
            'Brands' => [],
            'URLs that moved' => [],
        ],
        'brands-manager' => [],
        'modules' => [
            '' => [
                'Order emails', 'Order confirmation email', 'New-order alert to you', 'Dispatch notification',
                'Cancellation notification', 'Refund notification', 'Processing notification',
                'On-hold notification', 'Delivered notification', 'Refunded-status notification',
                'Payment-failed notification', '"Complete your order" — after 30 minutes',
                '"Complete your order" — after 24 hours', 'Feedback request after delivery',
                'Logo in order emails', 'Product Sorting', 'Brands', 'Recently Viewed', 'Marketing Pixels',
                'Abandoned Cart Recovery', 'Back-in-Stock Alerts', 'Email Capture', 'Cart & mini-cart',
                'Mini-cart promo', '“Go back to cart” link', 'Cart-page discount code box', 'Performance',
                'Performance & Speed', 'Free-shipping progress bar', 'Inclusive VAT line',
                'Cash-on-delivery fee', 'Delivery-info line', 'Checkout coupon hint', 'Checkout legal notice',
                'Reassurance block', 'Mobile order thumbnails', 'Address autocomplete',
                'Inline field validation', 'Single “Full name” field', 'Store & content',
                'Floating bottom menu (mobile)', 'Mega Menu', 'Notification Bar', 'This app only',
                'Quick view', 'Address book', 'Quantity bundles', 'Dispatch cutoff', 'Payments & shipping',
                'Payment & Shipping Rules', 'SEO Engine',
            ],
        ],
        'megamenu' => [],
        'ecommerce' => [
            'General' => [
                'Store basics', 'Store country', 'Low stock threshold', 'Catalogue layout',
                'Products per page',
            ],
            'Cart' => [
                'Free-delivery progress bar', 'Bar style', 'Mini-cart drawer', 'Mini-cart promo line',
                'Browsed tab in the cart drawer', 'Coupons', 'Cart coupon hint', 'Basket reminders',
                'Opt-in text', 'Reminder subject line', 'Reminder message', 'When to send',
            ],
            'Checkout' => [
                'Form fields', 'Full name', 'First name', 'Last name', 'Single full-name field',
                'Guest accounts', 'Take a guest\'s word for their email address', 'Coupon hint',
                'Suggested coupon code', 'Coupon hint text', 'Hint colour', 'Mobile layout',
                'Mobile order thumbnails', 'Sticky place-order bar on mobile', 'Back-to-cart button',
                'Enable Cash on Delivery', 'Cash-on-delivery fee', 'Legal notice', 'Live field validation',
                'When to mark a field', 'Mark correct fields too', 'Say what is wrong',
                'Address autocomplete', 'Google Places API key',
            ],
            'Delivery' => [
                'Delivery message', 'Delivery line under Place order', 'Delivery line text',
                'Dispatch cutoff', 'Show dispatch countdown', 'Cutoff hour (24h)',
                'Delivery days after dispatch',
            ],
            'Product page' => [
                'Review badges', 'Rating display', 'Heart icon on the capsule', 'Show the average score',
                'Show the review count', 'Count wording', 'Show units sold', 'Star colour',
                'Quantity bundles', 'Back-in-stock alerts', 'Notify-me form text', 'Alert subject line',
                'Alert message', 'Trust row', 'Returns chip',
            ],
        ],
        'tax' => [],
        'payship' => [
            '' => [
                'Hide Cash on delivery below', 'Hide Cash on delivery above',
                'Only offer free delivery when it is available',
            ],
        ],
        'shipping' => [
            'Zones' => [],
            'Extended' => [
                'Extended delivery', 'Deliver to more countries', 'Detect the shopper\'s country',
                'List countries you do not deliver to', 'Additional countries',
            ],
            'Gift wrapping' => [
                'Offer gift wrapping', 'Gift wrapping fee (AED)',
            ],
            'Delivery lines' => [
                'Delivery lines by country', 'The general line',
            ],
        ],
        'import' => [
            '' => [
                'Before you import — clean up this shop', '1 · Your exports', 'Brands', 'Tags', 'Variations',
                'Coupons', 'Order lines', 'Refunds', 'Order notes', 'SEO (Yoast)', 'Journal articles',
                'Navigation menus', 'Navigation items', 'Content blocks', 'Export manifest', 'Old addresses',
                'Picture index', 'Addresses & pictures', 'Pictures & live progress',
                'Articles this shop cannot serve',
            ],
        ],
        'orders' => [],
        'order-new' => [],
        'coupon-editor' => [],
        'payments' => [
            '' => [
                'How this shop uses it', 'Offer this at checkout', 'Label shown to shoppers',
                'Check the books',
            ],
            'Cash on delivery' => [],
            'Pay in 4 with Tabby' => [
                'Public key', 'Secret key', 'Merchant code', 'Webhook URL', 'Capture window (days)',
                'Send past-order history to Tabby',
            ],
            'Pay later with Tamara' => [
                'API token', 'Notification token', 'Registered webhook id',
                'Capture automatically when an order ships', 'Payment type', 'Instalments',
                'Products Tamara may not be used for', 'Categories Tamara may not be used for',
                'Minimum basket', 'Maximum basket',
            ],
            'Credit or debit card' => [
                'Publishable key', 'Webhook signing secret', 'Connect to Stripe', 'Set up Stripe',
                'Connect application (one-click setup)', 'Switch Connect on for that account',
                'Complete the platform profile', 'Turn on OAuth onboarding and copy the client id',
                'Copy the secret key for each mode and paste it here',
                'Press Save, then press Connect with Stripe', 'Stripe Link', 'Apple Pay', 'Google Pay',
                'Apple Pay domain file',
            ],
        ],
        'paygw' => [],
        'security' => [
            '' => [
                'It cannot see a file that was ADDED', 'What the browsers are being told',
            ],
            'What is recorded' => [],
            'The verdict line' => [],
            'File integrity' => [],
            'Content security policy' => [],
            'Evidence & retention' => [],
        ],
        'analytics' => [],
        'search' => [
            'Search' => [
                'Search box', 'Minimum characters', 'Panel before typing', 'Results in the panel',
                'Category matches', 'Brand matches', 'Result row size', 'Line between groups',
                'Line between results', 'Browser clear button', 'Brand matches on phone',
                'Most-searched terms', 'Trending row', 'Trending words · desktop', 'Trending words · phone',
                'Hide it on scroll',
            ],
            'Extended Search Results' => [
                'Extended search results', 'Brand-only queries stay strict',
                'Match multi-word brands while typing', 'Widen brand + word searches to other brands',
            ],
            'Sets in search' => [
                'Sets first', 'Which set comes first',
            ],
            'Search styles & colors' => [
                'Field roundness', 'Accent colour', 'Accent colour · hover', 'Chip background', 'Chip text',
                'Corner roundness',
            ],
        ],
        'seo' => [
            // Overview and SEO Audit list the issues THIS shop has today, so
            // their headings come and go with the data: tabs only.
            'Overview' => [],
            'Settings' => [
                'Site URL (canonical base)', 'Site name', 'Title separator', 'Title template',
                'Homepage title', 'Homepage meta description', 'Default meta description', 'Search engines',
                'Follow links', 'Default share image (1200×630)', 'Twitter / X handle', 'Facebook',
                'Pinterest', 'LinkedIn', 'YouTube', 'Organization name', 'Condition', 'Ship-to country',
                'Shipping cost (AED)', 'Free shipping over (AED)', 'Returns policy', 'Return window (days)',
                'XML sitemap', 'Product images in sitemap', 'FAQ markup on content pages',
                'Google Search Console', 'Bing Webmaster', 'Baidu', 'Google Analytics ID',
                'Meta (Facebook) Pixel',
            ],
            'Redirects & 404s' => [
                'Add a redirect', 'From (path on this site)', 'To (path or full URL)', 'Recent 404s',
            ],
            'Schema Inspector' => [
                'Inspect a page’s structured data', 'Page type', 'Slug',
            ],
            'Catalogue Audit' => [
                'Missing meta description', 'Missing image', 'Short description too thin',
            ],
            'SEO Audit' => [],
        ],
        'store-settings' => [
            'Business' => [
                'Store name', 'Site title', 'Currency', 'VAT rate (%)', 'Time zone', 'Phone number',
                'WhatsApp number', 'Support email', 'Street address', 'City', 'Emirate or region',
                'Postal code', 'Latitude', 'Longitude', 'Opening hours', 'Accent colour', 'Symbol',
                'Symbol rendering', 'Symbol position', 'Decimal places', 'Free-shipping threshold',
                'Flat delivery fee', 'Cash-on-delivery fee',
            ],
            'Tax' => [
                'Tax mode', 'Show the VAT line', 'Default rate (%)', 'Default basis',
                'Wording on the receipt', 'Tax registration number (TRN)',
            ],
            'Invoice' => [
                'Business name', 'Website', 'What the invoice calls itself', 'Invoice footer',
            ],
            'Claims' => [
                'Authenticity badge — title', 'Authenticity badge — the line under it',
                'Support badge — title', 'Brands section — the line under the heading',
                'Beside the Place order button', 'Above the order summary', 'Beside delivery and returns',
                'Announcement strip — authenticity claim',
            ],
        ],
        'customers' => [],
        'quiz-leads' => [],
        'emails' => [
            'At a glance' => [],
            'Where things are' => [
                'Sending & delivery', 'Customer emails', 'Sent mail',
            ],
            'Every email' => [
                'Order confirmation', 'New-order alert', 'Order shipped', 'Order cancelled', 'Refund sent',
                'Basket reminder', 'Account invite', 'Newsletter confirmation', 'Skin quiz plan',
                'Password reset', 'Verify email',
            ],
        ],
        'emails-sending' => [
            'Send using' => [],
            'Who it is from' => [
                'From name', 'From address', 'Replies go to', 'New-order alerts to',
            ],
            'Send a test' => [
                'Send a test to', 'Which email',
            ],
            'Domain check' => [
                'Will inboxes trust the domain?', 'DKIM', 'DMARC',
            ],
        ],
        'emails-customer' => [
            'All emails' => [
                'Order confirmed', 'Complete your order · 1', 'Complete your order · 2', 'Order processing',
                'Order on hold', 'Order shipped', 'Order delivered', 'Feedback request', 'Order cancelled',
                'Refund sent', 'Order marked refunded', 'Payment failed', 'New order alert', 'Password reset',
                'Confirm email address', 'Account invite', 'Newsletter: confirm subscription',
                'Skin quiz plan', 'Basket reminder',
            ],
            'Orders' => [],
            'To you' => [],
            'Account & sign-up' => [],
            'Shopping reminders' => [],
        ],
        'emails-branding' => [
            'Look' => [],
            'Colours & fonts' => [
                'Accent', 'Buttons', 'Heading font', 'Body font',
            ],
            'Footer of every email' => [],
            'Preview' => [],
        ],
        'emails-sent' => [
            'Sent' => [],
            'Failed' => [],
            'Orders' => [],
            'Account' => [],
            'Waiting to go out' => [],
        ],
        'mail' => [
            'Sending method' => [
                'How this store sends email',
            ],
            'Mail server (SMTP)' => [
                'SMTP host', 'Username', 'Encryption', 'Timeout (seconds)',
            ],
            'Sender & alerts' => [
                'From address', 'From name', 'New-order alerts to',
            ],
            'Footer' => [
                'Support email address', 'Support WhatsApp number',
            ],
            'More settings' => [
                'Dispatch email: how long delivery takes AFTER DISPATCH', 'Reply-To address',
                'Google account address', 'Google app password',
            ],
            'Status emails' => [],
            'Send a test' => [
                'Send a test message to',
            ],
            'Waiting to go out' => [],
            'Sent mail' => [],
        ],
        'posts' => [],
        'htmlblocks' => [],
        'media' => [],
        'ugcsections' => [
            'Sections' => [
                'Add clips', 'Paste its shortcode',
            ],
            'All clips' => [],
            'Appearance' => [
                'Tiles across on a phone', 'Tile width on a phone', 'Tile width from 900px',
                'Space between tiles', 'Corner radius',
            ],
        ],
        'instagram' => [
            '' => [
                'Instagram app ID', 'Instagram app secret', 'What a tap does',
            ],
            'Look' => [],
            'What a tile shows' => [],
        ],
        'tr-settings' => [
            '' => [
                'Arabic storefront', 'Right-to-left layout', 'Highlight untranslated text in the admin',
                'Default language',
            ],
        ],
        'tr-progress' => [
            '' => [
                'Interface text', 'Brands', 'Journal posts', 'Menu labels', 'Shoppable video sections',
                'Shoppable video clips',
            ],
        ],
        'tr-strings' => [],
        'tr-machine' => [
            '' => [
                'Your API key',
            ],
        ],
        'homepage' => [
            '' => [
                'Conversion', 'Editorial', 'Boutique', 'Preview this arrangement', 'Hero slider',
                'Delivery strip', 'Promo ticker', 'Category circles', 'Grid style', 'Best Sellers',
                'Recommended for you', 'Build your routine', 'Skin quiz', 'Top brands',
                '#KBeautyBliss spotted', 'Video rail', 'Best sellers', 'Flash sale', 'Skincare guide',
                'Two-column feature', 'About us', 'Customer reviews', 'Trust row',
            ],
        ],
        'hpcontent' => [
            // Lane HC: the one page for the whole homepage, and its editor.
            'All sections' => [
                'Edit content', 'Find a section', 'Which products', 'Automatic', 'Manual — I pick them',
                'As shipped', 'In stock only', 'Products, in order', 'Shows now', 'Show & frame', 'Save section',
            ],
            'Hero slider' => [
                'Eyebrow', 'Headline', 'Supporting line', 'Button wording', 'Where the slide links',
                'Background · from', 'Background · middle', 'Background · to', 'Inset panel · from',
                'Inset panel · to',
            ],
            'Other wording' => [
                'Elsewhere on this page',
            ],
            'Section headings' => [],
            'Big savings bundles' => [],
            'Best Sellers' => [],
            'Brands' => [],
            'Trending' => [],
            'Blog' => [],
            'Under AED' => [],
            'Two-column feature' => [],
            'About us' => [],
            'Live preview' => [],
        ],
        'spotted' => [
            'Homepage section' => [],
            'Carousel' => [],
            'Button' => [],
            'Spacing' => [],
            'Spotted page' => [],
        ],
        'banners' => [],
        'gridsections' => [],
        'prodstyles' => [
            'Layout' => [],
            'Card content' => [],
            'Spacing & type' => [],
            'Colour' => [],
            'Sticky Add to Cart' => [],
            'Shortcode builder' => [
                'Card style', 'Heading', 'View all link', 'Your shortcode',
            ],
        ],
        'mobilehdr' => [
            'Spacing' => [],
            'Text size' => [],
            'Search field' => [],
            'Icons' => [],
            'Divider' => [],
        ],
        'dividers' => [
            'Style' => [],
            'Where' => [],
            'Look' => [],
        ],
        'pagewash' => [
            'Preview' => [],
            'Colour' => [],
            'Motion' => [],
            'Where it applies' => [],
        ],
        'cartpanel' => [
            'Desktop' => [],
            'Mobile' => [],
            'Content' => [],
            'Behaviour' => [],
            'Wording' => [],
            'Colour' => [],
        ],
        'cartpage' => [
            'Layout' => [],
            'Product rows · the squeezed layout' => [],
            'Product rows · spacing and size' => [],
            'Product rows · phone' => [],
            'Recommended' => [],
            'Summary & trust' => [],
            'Docked rows' => [],
            'Desktop' => [],
            'Address popup' => [],
        ],
        'checkoutpage' => [
            'Desktop · Layout' => [],
            'Desktop · Header' => [],
            'Desktop · Text sizes' => [],
            'Desktop · Product rows' => [],
            'Mobile · Layout' => [],
            'Mobile · Header' => [],
            'Mobile · Text sizes' => [],
            'Mobile · Product rows' => [],
            'Back to cart' => [],
            'Trust & reviews' => [],
            'Fields & attention' => [],
        ],
        'acctpanel' => [
            'Welcome' => [],
            'Panel' => [],
            'Links' => [],
            'Forms' => [],
            'Sign up' => [],
        ],
        'header' => [
            'Bar' => [],
            'Logo' => [],
            'Icons' => [],
            'Menu icon' => [],
            'Navigation' => [],
            'Support' => [],
            'Flag bar' => [],
            'Breadcrumbs' => [],
        ],
        'mobilemenu' => [
            '' => [
                'Sheet height', 'Corner radius', 'Slide duration', 'Backdrop darkness', 'Grab handle',
                'Close button', 'Top of the sheet', 'Search field', 'Search placeholder', 'Heading row',
                'Heading', 'Row density', 'Sub-item columns', 'Item counts', 'One section at a time',
                'Open section', 'Open section style', 'Vertical rule', 'Rule thickness', 'Rule colour',
                'Card background', 'Open parent background', 'Open parent text', 'Foot of the sheet',
                'Support bar', 'Support wording', 'Account links', 'Account heading',
            ],
        ],
        'productpage' => [
            'Sections' => [
                'Sale / offer badge', 'Wishlist button', 'Rating capsule', 'Inline rating line', 'VAT line',
                'Short description', 'Options / bundles', 'Stock line', 'Dispatch countdown',
                'Quantity stepper', 'Buy it now button', 'Trust badges', 'Payment chips',
            ],
            'Photo & badge' => [],
            'Spacing · Page' => [],
            'Spacing · Buy column' => [],
            'Type · Buy column' => [],
            'Type · Sections & tabs' => [],
            'You may also like' => [
                'Show “You may also like”', 'What to show', 'Brand : category mix', 'Top up when short',
                'Hide out-of-stock products', 'Cards in view on a laptop', 'Cards in view on a phone', 'Arrows on a phone',
                'Move on its own', 'Seconds between moves', 'Heading', 'Heading — Arabic',
                'Small line above the heading', 'Small line — Arabic',
            ],
            'Trust · Delivery box' => [
                'Show the delivery box', 'Delivery picture', 'First line', 'Box colour', 'Box edge colour',
                'Text colour', 'Text size', 'Picture width · phone', 'Picture width · laptop',
            ],
            'Trust · Authenticity' => [
                'Show “Authenticity Guaranteed”', 'Line label', 'What it opens to', 'Tick colour',
            ],
            'Share' => [
                'Show the share icon beside the title', 'Sheet heading', 'Messenger', 'Pinterest', 'Telegram',
                'Snapchat', 'Messages (SMS)', 'Facebook', 'X (Twitter)', 'LinkedIn', 'Order of the tiles',
                'Messages', 'Tag shared links for analytics',
            ],
            'Share · Link preview card' => [
                'Use this card when a product is shared', 'Card title',
                'Shop name (for “Product name | shop name”)', 'Point style', 'Point 1', 'Point 1 · icon',
                'Point 1 · text', 'Point 2', 'Point 2 · icon', 'Point 2 · text', 'Point 3', 'Point 3 · icon',
                'Point 3 · text', 'Between the points', 'End the points with your domain',
                'Message above the link', 'Use the message for', 'Picture shape', 'Share pictures',
            ],
            'Trust · Spacing' => [],
            'Mobile sections' => [
                'Space between sections · phone', 'Image + gallery', 'Price row', 'Tabby & Tamara',
                'Bundle section', 'Ready to ship', 'Quantity + Add to cart', 'Delivery box',
                'Authenticity row', '100% authentic · delivery · pay-later rows', 'Product details + tabs',
                'Laptop · price row',
            ],
            'Desktop sections' => [
                'Buy column', 'Under the two columns',
            ],
            'Buy these together' => [
                'Show “Buy these together”', 'On phones', 'On laptops', 'How the other products are chosen',
                'Hide sold-out products', 'Prefer the same brand', 'Show the total above the button',
                'Discount for buying together', 'Show the total and buy-together discount', 'Show on phones',
                'Show on laptops', 'Discount when 3 are bought together',
                'Discount when 4 are bought together', 'Discount when 5 or more are bought together',
                'Coupons also apply to buy-together products', 'Category pairs',
                'Only categories with matches',
            ],
        ],
        'setap' => [
            '' => [
                'Set row on the cart page', 'Set box', 'Set list',
            ],
            'Desktop' => [
                'What is drawn', 'Show the set box', 'Show the member circles',
                'Show the “What’s inside” button', 'Show the saving', 'Show the “What is in this set” list',
                'Draw the list inside a panel',
            ],
            'Mobile' => [
                'The set row on the cart page', 'Set row: minimum height', 'Set row padding, top',
                'Set row padding, bottom', 'Set row: space under the set’s name',
            ],
        ],
        'bundles' => [
            '' => [
                'Show bundle options on product pages',
            ],
        ],
        'layout' => [
            '' => [
                'What each card shows', 'New badge', 'Discount badge', 'Category label', 'Brand name',
                'Stars and review count', 'Was price', 'Spacing', 'Gap between cards', 'Smallest card',
                'Space inside each card', 'Inside the card', 'Photo → first line', 'Brand → name',
                'Name → stars', 'Above the price', 'Price → Add to cart',
                'Price text size', 'Cut price', 'Sold-out label',
            ],
        ],
        'sitelayout' => [
            'Page width' => [],
            'Product grid' => [],
            'Loading more products' => [],
            'Category header' => [],
            'Category header · sizes & spacing' => [],
            'Press feedback' => [],
        ],
        'pages-store' => [
            '' => [
                'My Account', 'Skin Quiz', 'Review Wall', 'Journal',
            ],
        ],
        'pages-user' => [
            '' => [
                'Frequently Asked Questions', 'Returns & Refunds', 'Shipping & Delivery',
                'Terms & Conditions',
            ],
        ],
        'mkt-email' => [
            'Campaigns' => [],
            'Templates' => [],
            'Customer groups' => [],
            'Reports' => [],
        ],
        'newsletter' => [
            'Content' => [],
            'Messages' => [],
            'Appearance' => [],
        ],
        'labels' => [
            '' => [
                'Badges', 'Sold-out badge', 'Sold-out text', 'Sold-out colour', 'Sale badge', 'Sale text',
                'Sale colour', 'New badge', 'Counts as new for', 'New text', 'New colour', 'Bestseller badge',
                'Bestseller text', 'Bestseller colour',
            ],
        ],
        'meta' => [],
        'pixels' => [
            '' => [
                'Meta Pixel ID', 'Google (GA4) Measurement ID', 'TikTok Pixel ID',
            ],
        ],
        'searchterms' => [],
        'seokeywords' => [
            'Overview' => [],
            'Sources' => [],
            'Keyword bank' => [],
            'Pages' => [],
            'Sync' => [],
        ],
        'carttracking' => [
            'Carts' => ['Bot', 'Countries'],
            'Added products' => [],
            'Removed products' => [],
            'Blocked' => ['Block this address or its range'],
            'Settings' => [],
        ],
        'rev-all' => [],
        'rev-add' => [
            'Add reviews' => [
                'What this screen creates',
            ],
            'Helpful votes' => [
                'What this screen changes',
            ],
        ],
        'rev-assign' => [],
        'rev-io' => [],
        'rev-badge' => [
            'Badge themes' => [
                'Star colour',
            ],
            'Rating capsule' => [
                'Rating display', 'Count wording',
            ],
        ],
        'rev-settings' => [
            '' => ['Compact summary'],
        ],
        'shopfilters' => [],
        'updates' => [
            '' => [
                'Upload an update', 'If you ever lock yourself out',
            ],
        ],
        'console' => [
            '' => [
                'Aurora Green', 'Indigo', 'Rose', 'Slate', 'Midnight', 'Sidebar density',
                'Default landing page', 'Rows per page', 'Console language', 'Reduced motion',
                'K-Beauty Bliss Theme',
            ],
        ],

        // Routable ids with no sidebar row of their own -- aliases and screens
        // reached from inside another (TITLES keeps them for deep links). Keys
        // here so coverage is total; with no row to click they never surface.
        'rev-likes' => [],
        'rev-capsule' => [],
        'emails-edit' => [],
        'blog' => [],
        'ugcvideo' => [],
        'ugcstyle' => [],
    ];

    /** The file-store key json() keeps [signature, json] under. */
    public const CACHE_KEY = 'kbb.admin.search-index';

    /** @var array<string, array{0: list<string>, 1: list<array{0: int, 1: string}>}>|null */
    /**
     * Screen id => a class whose pages() answers [key, label, sections[title,
     * keys]] over SCHEMA keys of the services it names. (Lane FT)
     *
     * Appearance → Footer is drawn as four PAGES of sections, not as its two
     * services' TABS, so reading it from SCHEMA_SCREENS would offer the owner
     * tab names the screen no longer has. Its index is built from the same map
     * the screen draws from, so a section or a control moved there is found
     * here with nobody touching this file.
     *
     * @var array<string, class-string>
     */
    public const PAGE_SCREENS = [
        'slimfooter' => \App\Support\FooterPages::class,
    ];

    private static ?array $built = null;

    /**
     * screen id => [[tab labels], [[tab index or -1, label], ...]].
     *
     * Built once per process. It reads class constants only -- no database,
     * no cache, no settings -- so the cost is the autoload of the classes
     * named in SCHEMA_SCREENS, which the console's own endpoints load anyway.
     *
     * @return array<string, array{0: list<string>, 1: list<array{0: int, 1: string}>}>
     */
    public static function build(): array
    {
        if (self::$built !== null) {
            return self::$built;
        }

        $out = [];

        foreach (self::CURATED as $screen => $byTab) {
            foreach ($byTab as $tab => $labels) {
                self::add($out, $screen, (string) $tab, null);

                foreach ($labels as $label) {
                    self::add($out, $screen, (string) $tab, $label);
                }
            }

            $out[$screen] ??= [[], []];
        }

        foreach (self::SCHEMA_SCREENS as $screen => $classes) {
            foreach ($classes as $class) {
                foreach (self::schemaTabs($class) as [$tab, $labels]) {
                    self::add($out, $screen, $tab, null);

                    foreach ($labels as $label) {
                        self::add($out, $screen, $tab, $label);
                    }
                }
            }
        }

        foreach (self::PAGE_SCREENS as $screen => $class) {
            $labels = [];

            foreach ([SiteFooter::SCHEMA, SlimFooter::SCHEMA] as $schema) {
                foreach ($schema as $key => $def) {
                    $labels[$key] = trim((string) ($def['label'] ?? $def[1] ?? ''));
                }
            }

            foreach ($class::pages() as $page) {
                self::add($out, $screen, $page['label'], null);

                foreach ($page['sections'] as $section) {
                    self::add($out, $screen, $page['label'], $section['title']);

                    foreach ($section['keys'] as $key) {
                        self::add($out, $screen, $page['label'], $labels[$key] ?? '');
                    }
                }
            }
        }

        foreach ($out as $screen => [$tabs, $items]) {
            $out[$screen] = [$tabs, array_values($items)];
        }

        return self::$built = $out;
    }

    /**
     * The index as the inline JSON the console parses.
     *
     * PRINTED RAW, AND SAFE TO BE: every string in it is a code constant (see
     * the class note), and JSON_HEX_TAG turns every < and > into < and
     * >, so no label -- however a later lane writes it -- can close the
     * <script type="application/json"> block it sits in.
     */
    public static function json(): string
    {
        /*
         * CACHED, IN THE FILE STORE, UNDER ONE KEY THAT CARRIES ITS OWN
         * SIGNATURE. Building costs the autoload of seventeen settings classes
         * the console page does not otherwise need -- measured at 13 ms cold
         * without opcache -- against one small file read for this lookup, and
         * the owner's brief was "no load on the admin side at all".
         *
         *   * The FILE store, named, not the default one: this shop's default
         *     store is `database` unless the env says otherwise, and that would
         *     turn "no load" into one query on every console load.
         *   * ONE key, holding [signature, json]. The signature is the
         *     modification time of this file and of every SCHEMA_SCREENS class
         *     file, read with stat() and without loading any of them, so a
         *     package that changes a schema changes it and the next console
         *     load rebuilds -- nobody has to remember to clear anything. A key
         *     PER signature would leave a 50 KB file behind for every package
         *     ever applied; this overwrites the one it has.
         *
         * A cache that cannot be read or written is not a console that cannot
         * open: any throw falls through to building it in this request.
         */
        $sig = self::signature();

        try {
            $store = Cache::store('file');
            $hit = $store->get(self::CACHE_KEY);

            if (is_array($hit) && ($hit[0] ?? null) === $sig && is_string($hit[1] ?? null)) {
                return $hit[1];
            }
        } catch (\Throwable) {
            return self::encode();
        }

        $json = self::encode();

        try {
            $store->forever(self::CACHE_KEY, [$sig, $json]);
        } catch (\Throwable) {
            // Unwritable cache: this request already has its answer.
        }

        return $json;
    }

    /** The JSON itself, uncached. */
    public static function encode(): string
    {
        return (string) json_encode(
            self::build(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /**
     * A short hash of the source files' mtimes. App\ maps to app/ (PSR-4 in
     * composer.json), so a class's file is found by name without autoloading it.
     */
    public static function signature(): string
    {
        $parts = [(string) @filemtime(__FILE__)];

        foreach (self::PAGE_SCREENS as $class) {
            $parts[] = $class.'@'.(string) @filemtime(app_path(str_replace('\\', '/', substr($class, 4)).'.php'));
        }

        foreach ([SiteFooter::class, SlimFooter::class] as $class) {
            $parts[] = $class.'@'.(string) @filemtime(app_path(str_replace('\\', '/', substr($class, 4)).'.php'));
        }

        foreach (self::SCHEMA_SCREENS as $classes) {
            foreach ($classes as $class) {
                $file = app_path(str_replace('\\', '/', substr($class, 4)).'.php');
                $parts[] = $class.'@'.(string) @filemtime($file);
            }
        }

        return substr(md5(implode('|', $parts)), 0, 12);
    }

    /**
     * One service's tabs as [[tab label, [control labels]], ...].
     *
     * TABS rows are [label, description, keys]; SCHEMA rows are either the
     * positional [type, label, default, help, options] or the keyed form with
     * 'label' (ModuleSchema::normalise() accepts both, and so does this).
     * A key with no SCHEMA row is skipped rather than guessed at.
     *
     * @param  class-string  $class
     * @return list<array{0: string, 1: list<string>}>
     */
    public static function schemaTabs(string $class): array
    {
        $schema = defined($class.'::SCHEMA') ? constant($class.'::SCHEMA') : [];
        $tabs = defined($class.'::TABS') ? constant($class.'::TABS') : [];
        $out = [];

        foreach ($tabs as $tab) {
            $label = is_array($tab) ? (string) ($tab[0] ?? $tab['label'] ?? '') : '';

            if ($label === '') {
                continue;
            }

            $fields = [];

            foreach ((array) ($tab[2] ?? $tab['keys'] ?? []) as $key) {
                $def = is_string($key) ? ($schema[$key] ?? null) : null;
                $text = is_array($def) ? ($def['label'] ?? $def[1] ?? null) : null;

                if (is_string($text) && trim($text) !== '') {
                    $fields[] = trim($text);
                }
            }

            $out[] = [$label, $fields];
        }

        return $out;
    }

    /**
     * Add a tab (label null) or a label under a tab, once.
     *
     * @param  array<string, array{0: list<string>, 1: array<string, array{0: int, 1: string}>}>  $out
     */
    private static function add(array &$out, string $screen, string $tab, ?string $label): void
    {
        $out[$screen] ??= [[], []];
        $t = -1;

        if ($tab !== '') {
            $t = array_search($tab, $out[$screen][0], true);

            if ($t === false) {
                $out[$screen][0][] = $tab;
                $t = count($out[$screen][0]) - 1;
            }
        }

        if ($label === null || $label === '' || $label === $tab) {
            return;
        }

        $out[$screen][1][$t.'|'.$label] ??= [$t, $label];
    }
}
