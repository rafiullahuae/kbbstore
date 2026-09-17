<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Address;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\Setting;
use Closure;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * The storefront, rendered, for the pages a shopper actually reads.
 *
 * WHY THIS IS NOT tests/Feature/StorefrontRouteWalkTest.php.
 *
 * That file walks the ROUTER and asserts a status code. This one needs the
 * BYTES, and it needs to render the same page twice inside one process with two
 * different copies of resources/views. The two have different jobs and
 * different failure modes, so the second is not bolted onto the first — but the
 * coverage guard below means this file cannot fall behind the router either: a
 * storefront GET route with no entry here is a red test, exactly as it is
 * there.
 *
 * Only pages whose body is rendered from a Blade template are listed. A JSON
 * endpoint, a redirect and a file served off disk have no interface strings in
 * them, and asserting on their bytes would be asserting on nothing.
 */
final class EnglishRenderWalk
{
    /**
     * The tree whose English output is the contract: the commit this
     * conversion is applied ON TOP OF, not the one the lane happened to branch
     * from. Update it only together with a reviewed copy change — and only to
     * the merge's own first parent, never to the merge itself, which would
     * compare the new text with itself and assert nothing.
     *
     * MOVED AT MERGE, from 78b110ef. The lane branched before the bilingual-SEO
     * lane landed, and that lane moved hreflang out of layouts/store.blade.php
     * into App\Support\Seo — leaving exactly one blank line where the old
     * block stood, in the <head> of every page that uses the layout. The walk
     * saw it, correctly, as a byte difference and blamed this conversion for it.
     *
     * It is NOT masked and NOT approved as a reflow, because it is not this
     * lane's difference to approve: the fix is to compare against the tree this
     * lane is actually being applied to, which is the merge's first parent.
     * Every page then differs by nothing at all, which is the claim this test
     * exists to make.
     */
    /*
     * MOVED AGAIN, and this time for a REVIEWED COPY CHANGE rather than a
     * neighbouring lane's whitespace — which is the one reason this docblock
     * permits.
     *
     * The owner settled the rounding question: whole dirhams, and if a price
     * would carry fils, adjust the price. The four receipt surfaces had just
     * been widened to full precision to stop them disagreeing with the emailed
     * copy, so under his answer they printed AED 220.00 where he asked for
     * AED 220. They now ask Money::receiptDecimals(), which prints whole
     * dirhams whenever every figure on that receipt truthfully is one, and
     * widens only when one is not.
     *
     * That changes the bytes of the account pages ON PURPOSE, so the contract
     * moves with it. What the previous base guaranteed is not lost: the text
     * conversion was proved byte-identical against 77149bd at the moment it
     * merged, and that proof is recorded in its merge commit. From here the
     * guard answers the next question — has anything since changed the English?
     */
    /*
     * MOVED AGAIN, for the other half of the same reviewed copy change — Lane FA.
     *
     * 0a0f77f made the four ACCOUNT receipts print whole dirhams. The owner's
     * rule applies to the basket he is looking at as well: the cart summary and
     * the checkout ledger ask Money::receiptDecimals() too now, through
     * CartService::totals()' `decimals` key, so a whole-dirham basket prints
     * whole dirhams and one still holding a price from before the policy widens
     * as a whole column and adds up. That changes the bytes of /cart and
     * /checkout on purpose, so the contract moves with it.
     *
     * What the earlier bases guaranteed is not lost. The text conversion was
     * proved byte-identical against 77149bd at the moment it merged and that
     * proof is in its merge commit; 0a0f77f's receipt change is in this one's
     * history. From here the guard answers the next question — has anything
     * SINCE changed the English?
     */
    public const BASE_COMMIT = '52ef58a0ed7049226eca675aa97b5fd22e6310e7';

    /** resources/views as of $commit, materialised under a temp directory. */
    public static function baseViews(string $commit = self::BASE_COMMIT): string
    {
        $root = base_path();
        $dir = sys_get_temp_dir() . '/kbb-english-base-' . substr($commit, 0, 12) . '-' . getmypid();

        if (is_dir($dir . '/resources/views')) {
            return $dir . '/resources/views';
        }

        // `.git` is a directory in a clone and a FILE in a git worktree, which is
        // how several lanes on this repo run the suite.
        if (! file_exists($root . '/.git')) {
            throw new RuntimeException(
                'This test reads the pre-conversion templates out of git and this checkout has no .git. '
                . 'It cannot be run against an exported tree.'
            );
        }

        @mkdir($dir, 0777, true);

        $cmd = sprintf(
            'git -C %s archive %s resources/views 2>&1 | tar -x -C %s 2>&1',
            escapeshellarg($root),
            escapeshellarg($commit),
            escapeshellarg($dir)
        );

        exec($cmd, $out, $code);

        if ($code !== 0 || ! is_dir($dir . '/resources/views')) {
            throw new RuntimeException(
                "Could not extract resources/views at {$commit}: " . implode("\n", $out)
            );
        }

        return $dir . '/resources/views';
    }

    /** Point the view finder at one root and forget everything it had resolved. */
    public static function useViewPath(string $path): void
    {
        $factory = app('view');
        $factory->getFinder()->setPaths([$path]);
        $factory->flushFinderCache();
        $factory->flushState();

        \Illuminate\View\Component::flushCache();
        \Illuminate\View\Component::forgetComponentsResolver();
        \Illuminate\View\Component::forgetFactory();

        config(['view.paths' => [$path]]);
    }

    /** A readable "here is where they part" for a failure message. */
    public static function firstDifference(string $a, string $b): string
    {
        if ($a === $b) {
            return 'identical';
        }

        $len = min(strlen($a), strlen($b));
        $i = 0;
        while ($i < $len && $a[$i] === $b[$i]) {
            $i++;
        }

        $from = max(0, $i - 60);

        return sprintf(
            "at byte %d\n        before: …%s…\n        after:  …%s…",
            $i,
            str_replace("\n", '⏎', substr($a, $from, 160)),
            str_replace("\n", '⏎', substr($b, $from, 160))
        );
    }

    /** The session key Illuminate's session guard reads for the `customer` guard. */
    public static function customerSessionKey(): string
    {
        return 'login_customer_' . sha1(\Illuminate\Auth\SessionGuard::class);
    }

    /**
     * Realistic storefront data. Deliberately the same shape as
     * StorefrontRouteWalkTest's seed, because the pages are the same pages.
     *
     * @return array<string, mixed>
     */
    public static function seed(\Tests\TestCase $test): array
    {
        $test->seed(\Database\Seeders\DatabaseSeeder::class);
        $test->seed(\Database\Seeders\DemoReviewsSeeder::class);

        $post = Post::create([
            'slug' => 'walk-article',
            'title' => 'Walk Article',
            'body' => '<p>Body copy.</p>',
            'excerpt' => 'An article, so the journal and the root-slug route have one.',
            'status' => 'published',
            'published_at' => now(),
        ]);

        foreach (['delivery', 'refund_returns', 'faqs', 'about', 'contact-us'] as $slug) {
            Page::firstOrCreate(
                ['slug' => $slug],
                ['title' => ucfirst($slug), 'content' => '<p>Placeholder.</p>', 'status' => 'published']
            );
        }

        $customer = Customer::create([
            'name' => 'Ada Shopper',
            'email' => 'walk@example.com',
            'password' => 'password123',
        ]);

        $product = Product::query()->visible()->first();

        $order = Order::create([
            'order_number' => 'WALK00001',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'processing',
            'currency' => 'AED',
            'subtotal' => 20000,
            'discount_total' => 0,
            'shipping_total' => 2000,
            'fee_total' => 0,
            'gift_fee' => 0,
            'tax_total' => 0,
            'total' => 22000,
            'shipping_method' => 'Standard delivery',
            'payment_method' => 'cod',
            'payment_method_title' => 'Cash on delivery',
            'shipping_address' => [
                'first_name' => 'Ada', 'last_name' => 'Shopper',
                'line1' => '12 Marina Walk', 'city' => 'Dubai',
                'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971500000000',
            ],
        ]);

        $order->items()->create([
            'name' => $product?->name ?? 'Rice Toner',
            'product_id' => $product?->id,
            'brand' => 'Beauty of Joseon',
            'quantity' => 2,
            'unit_price' => 10000,
            'subtotal' => 20000,
            'total' => 20000,
        ]);

        $address = $customer->addresses()->create([
            'first_name' => 'Ada',
            'last_name' => 'Shopper',
            'line1' => '1 Test Street',
            'city' => 'Dubai',
            'country' => 'AE',
        ]);

        /*
         * A CART WITH SOMETHING IN IT, because the two pages with the most
         * interface text on them are both empty-state pages otherwise: /cart/
         * renders "Your bag is empty" and /checkout/ does not render at all, it
         * 302s back to the cart. Neither would have exercised the summary rows,
         * the quantity controls, the free-delivery bar, the four checkout steps
         * or the order block — which is most of this conversion.
         *
         * Two lines rather than one, and one of them on sale, so the struck
         * "was" price and the discount row both draw.
         */
        $cart = \App\Models\Cart::create([
            'token' => 'english-render-walk-cart',
            'currency' => 'AED',
            'status' => 'active',
            'shipping_country' => 'AE',
            'last_activity_at' => now(),
        ]);

        foreach (Product::query()->visible()->take(2)->get() as $line) {
            $cart->items()->create([
                'product_id' => $line->id,
                'quantity' => 2,
                'unit_price' => $line->effectivePrice(),
            ]);
        }

        config(['kbb.health_token' => 'walk-health-token']);
        Setting::updateOrCreate(['key' => 'indexnow_key'], ['value' => 'walkindexnowkey123']);
        Setting::flushMap();

        return compact('post', 'customer', 'product', 'order', 'address', 'cart');
    }

    /**
     * Router URI => how to request it, for every storefront GET route.
     *
     * `render` is true for the pages whose body comes out of a Blade template.
     * Everything else is listed with `render => false` and a reason, so the
     * coverage guard still sees it and nothing can be dropped silently.
     *
     * @param  array<string, mixed>  $seed
     * @return array<string, array{params?: array<string, mixed>, query?: array<string, string>, render: bool, auth?: bool, why?: string}>
     */
    public static function expectations(array $seed): array
    {
        $product = $seed['product'];
        $order = $seed['order'];
        $address = $seed['address'];
        $customer = $seed['customer'];
        $brandSlug = Brand::query()->value('slug');
        $catSlug = Category::query()->value('slug');

        $json = ['render' => false, 'why' => 'JSON, no interface strings in the body'];
        $redirect = ['render' => false, 'why' => 'redirect, no body'];
        $file = ['render' => false, 'why' => 'served off disk / machine-facing file'];

        return [
            // --- machine-facing, never read by a shopper ---------------------
            'up' => $file,
            'sitemap.xml' => $file,
            'robots.txt' => $file,
            'llms.txt' => $file,
            '{key}.txt' => ['params' => ['key' => fn () => \App\Services\Seo\IndexNow::key()]] + $file,
            '_kbb-health' => ['query' => ['token' => 'walk-health-token']] + $json,
            'storage/{path}' => ['params' => ['path' => 'kbb/app.css']] + $file,
            // A developer preview, not a storefront page. See below.
            '_design-check' => ['render' => false, 'why' => 'developer preview, not shopper-facing (see the exclusions list)'],
            'app' => ['render' => false, 'why' => '404 to a shopper; admin-only developer preview'],

            // --- catalogue ---------------------------------------------------
            '/' => ['render' => true],
            'shop' => ['render' => true],
            'shop/page/{page}' => ['params' => ['page' => '2']] + $redirect,
            'product-category/{path}' => ['params' => ['path' => $catSlug], 'render' => true],
            'product/{slug}' => ['params' => ['slug' => $product->slug], 'render' => true],
            'product' => $redirect,
            'quick-view/{id}' => ['params' => ['id' => (string) $product->id], 'render' => true],
            'new-in' => ['render' => true],
            'best-sellers' => ['render' => true],
            'super-sale' => ['render' => true],
            'everything-under-54-aed' => ['render' => true],

            // --- brands ------------------------------------------------------
            'korean-skincare-brands' => ['render' => true],
            'korean-skincare-brands/{slug}' => ['params' => ['slug' => $brandSlug], 'render' => true],
            'brands' => $redirect,
            'brand/{slug}' => ['params' => ['slug' => $brandSlug]] + $redirect,

            // --- journal -----------------------------------------------------
            'skincare-guide' => ['render' => true],
            'skincare-guide/{slug}' => ['params' => ['slug' => 'walk-article']] + $redirect,
            '{slug}' => ['params' => ['slug' => 'walk-article'], 'render' => true],
            'blog' => $redirect,
            'post/{slug?}' => ['params' => ['slug' => 'walk-article']] + $redirect,

            // --- editable content pages --------------------------------------
            'privacy-policy' => ['render' => true],
            'terms-and-conditions' => ['render' => true],
            'delivery' => ['render' => true],
            'refund_returns' => ['render' => true],
            'faqs' => ['render' => true],
            'about' => ['render' => true],
            'contact-us' => ['render' => true],

            // --- standalone pages --------------------------------------------
            /*
             * NOT BYTE-COMPARED, AND THIS IS A LOSS OF COVERAGE — Lane FB.
             *
             * Every other entry here is compared against the PRE-CONVERSION
             * template checked out of BASE_COMMIT, which proves the interface
             * -string lane changed no English. That comparison is meaningless
             * for this page now: Lane FB deliberately changed its English,
             * deleting the seventeen invented products, their prices and the
             * bundle saving that the results panel printed. The base template
             * renders a page that is SUPPOSED to differ, so the only two
             * outcomes available were a permanent failure or this line.
             *
             * It is turned off rather than papered over with an approvedReflows
             * pattern because the change is a <style> block and most of a
             * <script>, not a sentence inside an element — a pattern wide
             * enough to cover it would stop the guard seeing this page at all
             * while still claiming to check it.
             *
             * TO RESTORE IT: re-baseline BASE_COMMIT to a commit that contains
             * Lane FB's quiz change and set this back to true. Until then the
             * page's own guard is QuizRecommendsNoInventedProductTest, which
             * pins the properties that actually matter — no price, no inline
             * catalogue, no unauthorised discount.
             */
            'skin-quiz' => ['render' => false],
            'reviews' => ['render' => true],

            // --- cart and checkout -------------------------------------------
            'cart' => ['render' => true],
            'api/cart/drawer' => $json,
            'api/cart/debug' => $redirect,
            'checkout' => $redirect,
            'checkout/success' => ['render' => true],
            'checkout/pending' => $redirect,

            // --- wishlist -----------------------------------------------------
            'my-wishlist' => ['render' => true],
            'wishlist' => $redirect,
            'wishlist/ids' => $json,

            // --- account -------------------------------------------------------
            'my-account' => ['render' => true],
            'track-my-order' => ['render' => true],
            'my-account/forgot' => ['render' => true],
            'my-account/orders' => ['render' => true, 'auth' => true],
            'my-account/orders/{id}' => ['params' => ['id' => fn () => (string) $order->id], 'render' => true, 'auth' => true],
            'my-account/edit-address' => ['render' => true, 'auth' => true],
            'my-account/edit-address/{id}' => ['params' => ['id' => fn () => (string) $address->id], 'render' => true, 'auth' => true],
            'my-account/verify' => $redirect,
            'newsletter/confirm/{id}' => ['params' => ['id' => '999999'], 'render' => true],
            'newsletter/unsubscribe/{id}' => ['params' => ['id' => '999999'], 'render' => true],
            'mail-preferences/{kind}/{id}' => ['params' => ['kind' => 'stock', 'id' => '999999'], 'render' => true],
            'my-account/verify/{id}/{hash}' => [
                'params' => ['id' => (string) $customer->id, 'hash' => 'not-the-hash'],
                'render' => false,
                'why' => '404, no storefront body',
            ],
            'my-account/reset/{id}/{token}' => [
                'params' => ['id' => (string) $customer->id, 'token' => str_repeat('a1b2c3d4', 8)],
                'render' => true,
            ],

            // --- storefront JSON endpoints -------------------------------------
            'api/search' => ['query' => ['q' => 'serum']] + $json,
            'api/search/starter' => $json,
            'api/human-check' => $json,
            'reviews/captcha' => ['render' => false, 'why' => 'an SVG image'],

            // --- public API ------------------------------------------------------
            'api/products' => $json,
            'api/products/{slug}' => ['params' => ['slug' => $product->slug]] + $json,
            'api/products/{slug}/reviews' => ['params' => ['slug' => $product->slug]] + $json,
            'api/posts' => $json,
            'api/posts/{slug}' => ['params' => ['slug' => 'walk-article']] + $json,
            'api/reviews' => $json,
            'api/settings' => $json,

            // --- catch-all --------------------------------------------------------
            '{fallbackPlaceholder}' => [
                'params' => ['fallbackPlaceholder' => 'no-such-page-at-all'],
                'render' => false,
                'why' => '404 page; rendered below under its own name',
            ],
        ];
    }

    /**
     * The order the emails and the printed documents are a picture of.
     *
     * Deliberately awkward rather than tidy — two lines, a variant, a coupon
     * discount, gift wrapping, a cash-on-delivery surcharge, a gift message and
     * an order note — for the same reason
     * tests/Feature/OrderEmailPreviewsTest.php builds the same shape: a
     * template whose discount row nobody has ever rendered is a template whose
     * discount row nobody has ever checked.
     *
     * Fixed dates and a fixed id, because the documents print both.
     */
    public static function documentOrder(): \App\Models\Order
    {
        $order = Order::create([
            'id' => 10427,
            'order_number' => 'KBB-10427',
            'email' => 'aisha.khan@example.com',
            'phone' => '+971 50 123 4567',
            'status' => 'processing',
            'currency' => 'AED',
            'billing_address' => [
                'first_name' => 'Aisha', 'last_name' => 'Khan',
                'line1' => 'Apartment 1204, Marina Heights', 'city' => 'Dubai',
                'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971 50 123 4567',
            ],
            'shipping_address' => [
                'first_name' => 'Noura', 'last_name' => 'Al Mansoori',
                'line1' => 'Villa 7, Street 21', 'line2' => 'Al Barsha South 2',
                'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE',
                'phone' => '+971 55 987 6543',
            ],
            'subtotal' => 46300,
            'discount_total' => 4000,
            'coupon_code' => 'GLOW10',
            'shipping_total' => 2000,
            'fee_total' => 3050,
            'gift_fee' => 1500,
            'is_gift' => true,
            'gift_note' => "Happy birthday, Mama.\nLove from all of us x",
            'customer_note' => 'Please ring the doorbell twice — the buzzer is broken.',
            'tax_total' => 0,
            'total' => 47350,
            'shipping_method' => 'Standard delivery (1–3 working days)',
            'payment_method' => 'cod',
            'payment_method_title' => 'Cash on delivery',
            'paid_at' => '2026-09-14 09:44:00',
            'created_at' => '2026-09-14 09:41:00',
        ]);

        $order->items()->create([
            'name' => 'Rice Daily Moisturizing Toner 150ml',
            'brand' => 'Haruharu Wonder', 'sku' => 'HH-RT-150',
            'quantity' => 2, 'unit_price' => 19900, 'subtotal' => 39800, 'total' => 39800,
        ]);

        $order->items()->create([
            'name' => 'Centella Ampoule',
            'brand' => 'SKIN1004', 'sku' => 'SK-CA-030',
            'variant_attributes' => ['30ml'],
            'quantity' => 1, 'unit_price' => 6500, 'subtotal' => 6500, 'total' => 6500,
        ]);

        return $order->fresh('items');
    }

    /** The shop's own details, so the masthead, the support block and the invoice header all draw. */
    public static function documentSettings(): void
    {
        $settings = app(\App\Services\SettingsService::class);

        foreach ([
            'store_name' => 'K Beauty Bliss',
            'support_whatsapp' => '+971 58 505 2611',
            'support_email' => 'hello@kbeautybliss.com',
            'social_instagram' => 'https://www.instagram.com/kbeauty.bliss/',
            'mail_signature' => "Warmly,\nthe K Beauty Bliss team",
            'invoice_business_name' => 'K Beauty Bliss Trading LLC',
            'invoice_address' => "Office 1902, Burlington Tower\nBusiness Bay, Dubai\nUnited Arab Emirates",
            'invoice_trn' => '100123456700003',
            'invoice_email' => 'info@kbeautybliss.com',
            'invoice_phone' => '+971 58 505 2611',
            'invoice_website' => 'kbeautybliss.com',
            'invoice_footer' => "Payment received in full — no further amount is due.\nReturns accepted within 14 days on unopened items.",
        ] as $key => $value) {
            $settings->set($key, $value);
        }

        \App\Models\Setting::flushMap();
    }

    /** Every storefront GET route URI the router has registered. */
    public static function registeredUris(): array
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            if ($uri === 'admin' || str_starts_with($uri, 'admin/') || str_starts_with($uri, 'admin-api/')) {
                continue;
            }

            $uris[] = $uri;
        }

        return array_values(array_unique($uris));
    }

    /** Turn a router URI plus parameters into a requestable path. */
    public static function pathFor(string $uri, array $spec): string
    {
        $params = $spec['params'] ?? [];

        $path = preg_replace_callback('/\{([a-zA-Z_]+)\??\}/', function ($m) use ($params, $uri) {
            $value = $params[$m[1]] ?? null;
            $value = $value instanceof Closure ? $value() : $value;

            if ($value === null) {
                throw new RuntimeException("No parameter '{$m[1]}' given for route '{$uri}'.");
            }

            return (string) $value;
        }, $uri);

        $path = '/' . ltrim($path, '/');

        if (! empty($spec['query'])) {
            $path .= '?' . http_build_query($spec['query']);
        }

        return $path;
    }

    /**
     * Mask the handful of values that genuinely differ between two renders of
     * the SAME template, so the comparison is about the words and not about a
     * token.
     *
     * KEPT AS NARROW AS IT IS DELIBERATELY. Every entry below is proved
     * necessary AND proved sufficient by the control pass in the test: the same
     * views are rendered twice and the two must be identical AFTER masking. If
     * an entry here were unnecessary the control would pass without it; if the
     * set were incomplete the control would fail. And the mutation check in the
     * same file proves the masking is not so broad that it swallows a real
     * change to the words.
     */

    /**
     * The two paragraphs whose INDENTATION changed, and nothing else.
     *
     * Both were prose written across four or five lines of template with a
     * <b> run or a link in the middle of the sentence. A sentence cut into
     * "before the link" and "after the link" is the one shape a translator
     * cannot reorder, and Arabic reorders — so each is now a single __() with
     * the markup passed in as a placeholder. The words are identical, the tags
     * are identical, and the line breaks and leading spaces that used to sit
     * between them are gone: HTML collapses them to one space either way, so
     * nothing a reader sees has moved.
     *
     * Expressed as a rule over the ELEMENT rather than as a wall of literal
     * text, so it cannot silently stop matching when the shop URL in the middle
     * of the second one changes. Each rule is required to fire exactly once.
     *
     * @return array<string, string> a name => the pattern that selects the element's inner text
     */
    public static function approvedReflows(): array
    {
        return [
            // resources/views/store/review-wall.blade.php, the empty state
            'review wall: empty state body' => [
                'pattern' => '#(<div class="sr-empty">\s*<h2>[^<]*</h2>\s*<p>)(.*?)(</p>)#s',
                'hits' => 1,
            ],
            // resources/views/store/review-wall.blade.php, the "not built" note
            'review wall: what is not built' => [
                'pattern' => '#(<p class="sr-unbuilt">)(.*?)(</p>)#s',
                'hits' => 1,
            ],
            /*
             * resources/views/store/newsletter/unsubscribe.blade.php and
             * resources/views/store/mail-preferences/confirm.blade.php — the same
             * shape of note on two pages, two or three lines of template each and
             * now one pair of sentences.
             */
            'newsletter unsubscribe: the note under the button' => [
                'pattern' => '#(<p class="muted" style="margin-top:16px;font-size:13px;">)(\s*Order confirmations,.*?)(</p>)#s',
                'hits' => 1,
            ],
            'email preferences: the note under the button' => [
                'pattern' => '#(<p class="muted" style="margin-top:16px;font-size:13px;">)(\s*This stops both.*?)(</p>)#s',
                'hits' => 1,
            ],
        ];
    }

    /** One element's inner text, with its template indentation collapsed away. */
    public static function collapseInner(string $html, string $pattern, int &$hits): string
    {
        return (string) preg_replace_callback($pattern, function (array $m) use (&$hits): string {
            $hits++;

            return $m[1] . trim(preg_replace('/\s*\n\s*/', ' ', $m[2])) . $m[3];
        }, $html);
    }

    /**
     * The approved differences in the EMAILS and the printed documents.
     *
     * Same rule as the pages: applied to the BEFORE side, counted, and each one
     * has to keep matching or it is removed.
     *
     * All of them are REFLOWS: paragraphs written across two to four lines of
     * template with a link or an @if in the middle of the sentence. Each is now
     * one __() with the markup passed in as a placeholder, so the line breaks
     * between the words are gone. HTML collapses them to a single space either
     * way, so nothing a reader sees has moved.
     *
     * There is no escaping entry here, and there was: the seven sentences on
     * this shop that contain an apostrophe would have gained an &#039; from
     * Blade's {{ }}. App\Support\Phrase::inline() is why they did not.
     *
     * @return array<string, array{pattern: string, with: string, hits: int}>
     */
    public static function approvedDocumentDifferences(): array
    {
        return [
            // emails/order-confirmation.blade.php — the receipt's opening line
            'confirmation lead' => [
                'pattern' => '/Everything you chose is listed below,\s+exactly as it was when you ordered/',
                'with' => 'Everything you chose is listed below, exactly as it was when you ordered',
                'hits' => 1,
            ],
            // emails/order-confirmation.blade.php + emails/order-status.blade.php
            'device note: before the link' => [
                'pattern' => '/Anywhere else,\s+<a href=/',
                'with' => 'Anywhere else, <a href=',
                'hits' => 3,
            ],
            'device note: after the link (confirmation)' => [
                'pattern' => '/<\/a>\s+and your orders are all listed there under/',
                'with' => '</a> and your orders are all listed there under',
                'hits' => 1,
            ],
            'device note: after the link (status)' => [
                'pattern' => '/<\/a>\s+and look for/',
                'with' => '</a> and look for',
                'hits' => 2,
            ],
            // emails/layout.blade.php — the merchant alert's own footer
            'merchant alert footer' => [
                'pattern' => '/It goes to the address set under Store → Mail,\s+and you can switch it off/',
                'with' => 'It goes to the address set under Store → Mail, and you can switch it off',
                'hits' => 1,
            ],
            // emails/order-refunded.blade.php — the manual-refund paragraph
            'refund: arranged by hand' => [
                'pattern' => '/so we will\s+arrange the money with you directly\. If you have not heard from us, reply to this message\s+and we will sort it out\./',
                'with' => 'so we will arrange the money with you directly. If you have not heard from us, reply to this message and we will sort it out.',
                'hits' => 1,
            ],
        ];
    }

    /**
     * Apply a set of approved differences to the BEFORE side and report how
     * often each one fired, so a rule that has stopped matching is a failure
     * rather than a silent no-op.
     *
     * @param  array<string, array{pattern: string, with: string, hits: int}>  $rules
     * @param  array<string, string>  $documents
     * @return array<string, array{expected: int, actual: int}>
     */
    public static function applyApproved(array $rules, array &$documents): array
    {
        $report = [];

        foreach ($rules as $name => $rule) {
            $fired = 0;

            foreach ($documents as $key => $body) {
                $count = 0;
                $documents[$key] = (string) preg_replace($rule['pattern'], $rule['with'], $body, -1, $count);
                $fired += $count;
            }

            $report[$name] = ['expected' => $rule['hits'], 'actual' => $fired];
        }

        return $report;
    }

    public static function mask(string $html): string
    {
        // Laravel's per-session CSRF token: 40 chars of Str::random.
        $html = preg_replace('/(name="_token" value=")[A-Za-z0-9]{40}(")/', '$1TOKEN$2', $html);
        $html = preg_replace('/(<meta name="csrf-token" content=")[A-Za-z0-9]{40}(")/', '$1TOKEN$2', $html);
        // The same token again, handed to the front-end script as JSON.
        $html = preg_replace('/("csrf":")[A-Za-z0-9]{40}(")/', '$1TOKEN$2', $html);
        // The sign-up sum's one-time token and its question, issued per render.
        $html = preg_replace('/(name="hc_token" value=")[^"]*(")/', '$1TOKEN$2', $html);
        $html = preg_replace('/(data-hc-q>)[^<]*(<)/', '$1SUM$2', $html);

        return $html;
    }
}
