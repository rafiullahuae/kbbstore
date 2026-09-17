<?php

/*
 * THE TWO FILES THE MIGRATION NEVER OPENED.
 *
 * `ImportRunner::entities()` was seven importers and there was no eighth:
 * coupons.csv and reviews.csv sat in the export folder and nothing read them.
 * The dry-run lane made that visible, which is as far as a dry run can go. This
 * is the half that carries the data, and these are its guards.
 *
 * EVERY ASSERTION HERE IS ABOUT ROWS IN THE DATABASE AFTER THE RUN, not about
 * the report's own counters, and that is deliberate. An import guard that
 * imports zero rows and asserts on a tally is a guard that passes while
 * carrying nothing — six guards on this project have already been caught in
 * exactly that shape. Where a counter IS asserted it is asserted BESIDE the
 * rows it claims to describe.
 *
 * The fixture is built rather than checked in: a CSV in the repository is a
 * thing nobody can read a diff of. It is deterministic.
 */

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Services\CouponService;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportRunner;
use App\Services\Import\Money;
use App\Support\ReviewStatus;

/* --------------------------------------------------------------- fixture */

function feWrite(string $path, array $header, array $rows): void
{
    $handle = fopen($path, 'wb');
    fputcsv($handle, $header);

    foreach ($rows as $row) {
        fputcsv($handle, array_map(static fn (string $key): string => (string) ($row[$key] ?? ''), $header));
    }

    fclose($handle);
}

/**
 * A WooCommerce-shaped export carrying one of each hazard.
 *
 * @return string the directory
 */
function feExport(?array $couponRows = null, ?array $reviewRows = null): string
{
    $dir = sys_get_temp_dir().'/kbb-fe-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    feWrite($dir.'/categories.csv', ['term_id', 'name', 'slug', 'parent'], [
        ['term_id' => '7001', 'name' => 'Skincare', 'slug' => 'skincare-fe', 'parent' => ''],
        ['term_id' => '7002', 'name' => 'Masks', 'slug' => 'masks-fe', 'parent' => '7001'],
    ]);

    feWrite($dir.'/products.csv',
        ['id', 'name', 'slug', 'sku', 'status', 'regular_price', 'stock_status', 'category_term_ids'], [
            ['id' => '11001', 'name' => 'Plain Serum', 'slug' => 'plain-serum-fe', 'sku' => 'FE-1',
                'status' => 'publish', 'regular_price' => '80', 'stock_status' => 'instock', 'category_term_ids' => '7001'],
            ['id' => '11002', 'name' => 'Sheet Mask', 'slug' => 'sheet-mask-fe', 'sku' => 'FE-2',
                'status' => 'publish', 'regular_price' => '30', 'stock_status' => 'instock', 'category_term_ids' => '7002'],
            ['id' => '11003', 'name' => 'Night Cream', 'slug' => 'night-cream-fe', 'sku' => 'FE-3',
                'status' => 'publish', 'regular_price' => '150', 'stock_status' => 'instock', 'category_term_ids' => '7001'],
        ]);

    feWrite($dir.'/customers.csv', ['user_id', 'email', 'first_name', 'last_name'], [
        ['user_id' => '412', 'email' => 'Layla@Example.com', 'first_name' => 'Layla', 'last_name' => 'K'],
    ]);

    feWrite($dir.'/coupons.csv', [
        'id', 'code', 'post_status', 'description', 'discount_type', 'coupon_amount',
        'date_expires', 'usage_count', 'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items',
        'free_shipping', 'individual_use', 'exclude_sale_items', 'minimum_amount', 'maximum_amount',
        'product_ids', 'exclude_product_ids', 'product_categories', 'customer_email', 'used_by',
        'meta:_wjecf_free_product_ids',
    ], $couponRows ?? feCouponRows());

    feWrite($dir.'/reviews.csv', [
        'comment_id', 'comment_post_id', 'comment_type', 'author', 'email', 'rating', 'title', 'content',
        'comment_approved', 'comment_date', 'verified', 'user_id', 'ip', 'meta:_wc_review_photo',
    ], $reviewRows ?? feReviewRows());

    return $dir;
}

function feCouponRows(): array
{
    return [
        // Ordinary percentage, live, global limit, bare expiry date.
        ['id' => '41001', 'code' => 'WELCOME20', 'post_status' => 'publish', 'discount_type' => 'percent',
            'coupon_amount' => '20', 'date_expires' => '2027-01-01', 'usage_count' => '462', 'usage_limit' => '1000'],
        // A fractional percentage: the shape that is not exact in binary.
        ['id' => '41002', 'code' => 'HALFPC', 'post_status' => 'publish', 'discount_type' => 'percent',
            'coupon_amount' => '12.5', 'usage_count' => '3'],
        // Fixed cart, carrying fils in BOTH a discount and a threshold, with a
        // whole minimum beside them. The owner prices in whole dirhams and
        // nothing in WooCommerce ever enforced that.
        ['id' => '41003', 'code' => 'AED99OFF', 'post_status' => 'publish', 'discount_type' => 'fixed_cart',
            'coupon_amount' => '99.50', 'minimum_amount' => '250', 'maximum_amount' => '499.50',
            'usage_count' => '11'],
        // Fixed per unit, plus the four flags the brief assumed had no home.
        ['id' => '41004', 'code' => 'TENOFFEACH', 'post_status' => 'publish', 'discount_type' => 'fixed_product',
            'coupon_amount' => '10', 'free_shipping' => 'yes', 'individual_use' => 'yes',
            'exclude_sale_items' => 'yes', 'limit_usage_to_x_items' => '2'],
        // EXPIRED, and still worth importing: orders reference it.
        ['id' => '41005', 'code' => 'EXPIRED10', 'post_status' => 'publish', 'discount_type' => 'percent',
            'coupon_amount' => '10', 'date_expires' => '2022-01-01', 'usage_count' => '118'],
        // Restricted by WordPress post and term ids that DO translate.
        ['id' => '41006', 'code' => 'MASKSONLY', 'post_status' => 'publish', 'discount_type' => 'percent',
            'coupon_amount' => '15', 'product_ids' => '11002', 'product_categories' => '7002',
            'exclude_product_ids' => '11003', 'usage_count' => '4'],
        // Restricted to products this shop does not have: would go catalogue-wide.
        ['id' => '41007', 'code' => 'GHOSTONLY', 'post_status' => 'publish', 'discount_type' => 'percent',
            'coupon_amount' => '50', 'product_ids' => '99998,99999'],
        // An EXCLUSION naming a product that is not here. Inert, not a refusal.
        ['id' => '41008', 'code' => 'NOTTHEGHOST', 'post_status' => 'publish', 'discount_type' => 'percent',
            'coupon_amount' => '5', 'exclude_product_ids' => '99998'],
        // The per-customer limit, which nothing can carry.
        ['id' => '41009', 'code' => 'ONEPERPERSON', 'post_status' => 'publish', 'discount_type' => 'fixed_cart',
            'coupon_amount' => '25', 'usage_limit_per_user' => '1', 'usage_count' => '207',
            'used_by' => '412,layla@example.com', 'customer_email' => 'Layla@Example.com'],
        // Withdrawn in WordPress. `coupons` has no status column.
        ['id' => '41010', 'code' => 'NOTLIVE', 'post_status' => 'draft', 'discount_type' => 'percent', 'coupon_amount' => '30'],
        ['id' => '41011', 'code' => 'BINNED', 'post_status' => 'trash', 'discount_type' => 'percent', 'coupon_amount' => '40'],
        // More than 100%.
        ['id' => '41012', 'code' => 'TOOMUCH', 'post_status' => 'publish', 'discount_type' => 'percent', 'coupon_amount' => '150'],
        // A discount type this shop cannot express.
        ['id' => '41013', 'code' => 'PERPRODPC', 'post_status' => 'publish', 'discount_type' => 'percent_product', 'coupon_amount' => '10'],
        // Mixed case, and Woo's other spelling of "no limit".
        ['id' => '41014', 'code' => 'MixedCase', 'post_status' => 'publish', 'discount_type' => 'percent', 'coupon_amount' => '5'],
        ['id' => '41015', 'code' => 'ZEROLIMIT', 'post_status' => 'publish', 'discount_type' => 'percent',
            'coupon_amount' => '5', 'usage_limit' => '0'],
        ['id' => '41016', 'code' => 'NOAMOUNT', 'post_status' => 'publish', 'discount_type' => 'percent', 'coupon_amount' => ''],
        // Legal, and worth saying out loud.
        ['id' => '41018', 'code' => 'FREEBASKET', 'post_status' => 'publish', 'discount_type' => 'percent', 'coupon_amount' => '100'],
        // An expiry that already carries a time: honoured as written.
        ['id' => '41019', 'code' => 'TIMED', 'post_status' => 'publish', 'discount_type' => 'percent',
            'coupon_amount' => '5', 'date_expires' => '2027-06-30 12:00:00'],
    ];
}

function feReviewRows(): array
{
    return [
        ['comment_id' => '51001', 'comment_post_id' => '11001', 'comment_type' => 'review', 'author' => 'Layla',
            'email' => 'Layla@Example.com', 'rating' => '5', 'title' => 'Lovely', 'content' => 'Cleared my skin in a week',
            'comment_approved' => '1', 'comment_date' => '2023-04-02 11:00:00', 'verified' => '1',
            'user_id' => '412', 'ip' => '203.0.113.9'],
        ['comment_id' => '51002', 'comment_post_id' => '11001', 'comment_type' => 'review', 'author' => 'Noor',
            'email' => 'noor@example.com', 'rating' => '3', 'content' => 'It is fine', 'comment_approved' => '1',
            'comment_date' => '2023-05-02 11:00:00', 'ip' => '198.51.100.4'],
        // No author: WooCommerce leaves comment_author empty for a signed-in reviewer.
        ['comment_id' => '51003', 'comment_post_id' => '11002', 'comment_type' => 'review', 'author' => '',
            'email' => 'ghostwriter@example.com', 'rating' => '4', 'content' => 'Good enough',
            'comment_approved' => '1', 'comment_date' => '2023-06-02 11:00:00'],
        // The product is gone.
        ['comment_id' => '51004', 'comment_post_id' => '99998', 'comment_type' => 'review', 'author' => 'Sara',
            'rating' => '5', 'content' => 'Best serum ever', 'comment_approved' => '1', 'comment_date' => '2023-06-03 11:00:00'],
        // Ratings out of range, and no rating at all.
        ['comment_id' => '51005', 'comment_post_id' => '11001', 'comment_type' => 'review', 'author' => 'Zero',
            'rating' => '0', 'content' => 'no stars', 'comment_approved' => '1', 'comment_date' => '2023-06-04 11:00:00'],
        ['comment_id' => '51006', 'comment_post_id' => '11001', 'comment_type' => 'review', 'author' => 'Six',
            'rating' => '6', 'content' => 'six stars', 'comment_approved' => '1', 'comment_date' => '2023-06-05 11:00:00'],
        ['comment_id' => '51007', 'comment_post_id' => '11001', 'comment_type' => 'review', 'author' => 'Asker',
            'rating' => '', 'content' => 'Is this fragrance free?', 'comment_approved' => '1', 'comment_date' => '2023-06-06 11:00:00'],
        // The whole moderation vocabulary.
        ['comment_id' => '51008', 'comment_post_id' => '11001', 'comment_type' => 'review', 'author' => 'Held',
            'rating' => '1', 'content' => 'awaiting moderation', 'comment_approved' => '0', 'comment_date' => '2023-06-07 11:00:00'],
        ['comment_id' => '51009', 'comment_post_id' => '11001', 'comment_type' => 'review', 'author' => 'Bot',
            'rating' => '5', 'content' => 'buy cheap watches', 'comment_approved' => 'spam', 'comment_date' => '2023-06-08 11:00:00'],
        ['comment_id' => '51010', 'comment_post_id' => '11001', 'comment_type' => 'review', 'author' => 'Deleted',
            'rating' => '2', 'content' => 'owner binned this', 'comment_approved' => 'trash', 'comment_date' => '2023-06-09 11:00:00'],
        ['comment_id' => '51011', 'comment_post_id' => '11001', 'comment_type' => 'review', 'author' => 'Weird',
            'rating' => '5', 'content' => 'x', 'comment_approved' => 'post-trashed', 'comment_date' => '2023-06-10 11:00:00'],
        // Not a review at all.
        ['comment_id' => '51012', 'comment_post_id' => '11001', 'comment_type' => 'comment', 'author' => 'Reader',
            'rating' => '5', 'content' => 'nice post', 'comment_approved' => '1', 'comment_date' => '2023-06-11 11:00:00'],
        ['comment_id' => '51013', 'comment_post_id' => '11003', 'comment_type' => 'review', 'author' => 'Marky',
            'email' => 'marky@example.com', 'rating' => '5',
            'content' => '<p>Rich <b>text</b></p><script>alert(1)</script>', 'comment_approved' => '1',
            'comment_date' => '2023-06-12 11:00:00', 'meta:_wc_review_photo' => 'IMG_4432.jpg'],
        ['comment_id' => '51014', 'comment_post_id' => '11003', 'comment_type' => 'review', 'author' => 'Broken',
            'email' => 'not-an-address', 'rating' => '4', 'content' => 'ok', 'comment_approved' => '1',
            'comment_date' => '2023-06-13 11:00:00'],
        // product_id 0 in WordPress: a review of the shop.
        ['comment_id' => '51015', 'comment_post_id' => '0', 'comment_type' => 'review', 'author' => 'Fan',
            'rating' => '5', 'content' => 'Great shop, fast delivery', 'comment_approved' => '1',
            'comment_date' => '2023-06-14 11:00:00'],
        // No date at all: /reviews and the homepage wall are ordered by it.
        ['comment_id' => '51016', 'comment_post_id' => '11002', 'comment_type' => 'review', 'author' => 'Undated',
            'rating' => '5', 'content' => 'no date on this one', 'comment_approved' => '1', 'comment_date' => ''],
    ];
}

function feRun(string $dir, array $overrides = []): App\Services\Import\ImportReport
{
    return (new ImportRunner)->run(new ImportOptions(...array_merge([
        'directory' => $dir,
        'runKey' => 'fe-'.bin2hex(random_bytes(4)),
    ], $overrides)));
}

function feCoupon(string $code): ?Coupon
{
    return Coupon::query()->where('code', $code)->first();
}

function feReview(int $commentId): ?Review
{
    return Review::query()->where('source', 'wp_comment')->where('source_id', $commentId)->first();
}

/** @return array<string, int> headline => count */
function feChanges(App\Services\Import\ImportReport $report, string $entity, string $bucket = 'adjustments'): array
{
    $groups = $bucket === 'adjustments'
        ? $report->for($entity)->adjustments()
        : $report->for($entity)->discards();

    return array_map(static fn (array $g): int => $g['count'], $groups);
}

function feMatch(array $changes, string $needle): ?string
{
    foreach (array_keys($changes) as $headline) {
        if (str_contains($headline, $needle)) {
            return $headline;
        }
    }

    return null;
}

function feReasons(App\Services\Import\ImportReport $report, string $entity): string
{
    return implode(' || ', array_column($report->for($entity)->rejections(), 'reason'));
}

/* ------------------------------------------------- the entities are registered */

it('opens coupons.csv and reviews.csv, in an order their dependencies fix', function () {
    $names = ImportRunner::entityNames();

    expect($names)->toContain('coupons')->toContain('reviews');

    // Coupons AFTER products (their restriction lists name products by
    // WooCommerce id) and BEFORE orders (an order names its code).
    expect(array_search('coupons', $names, true))
        ->toBeGreaterThan(array_search('products', $names, true))
        ->toBeLessThan(array_search('orders', $names, true));

    // Reviews after both products and customers.
    expect(array_search('reviews', $names, true))
        ->toBeGreaterThan(array_search('products', $names, true))
        ->toBeGreaterThan(array_search('customers', $names, true));

    // And the screen's hand-maintained mirror still agrees with the runner.
    expect(App\Services\ImportConsole\ImportWorkspace::entities())->toBe($names);

    // The files are no longer in the "nothing opens this" list, which is the
    // whole of what the previous lane could say about them.
    $report = feRun(feExport());
    $unread = implode(' ', array_column(
        $report->for('export')->discards()['a file in the export folder that no importer opens -- this application has no entity for it, so nothing in it reaches the database and nothing else in this report mentions it']['samples'] ?? [],
        'field'
    ));

    expect($unread)->not->toContain('coupons.csv')->not->toContain('reviews.csv');
});

/* ------------------------------------------------------ coupons: the units */

it('converts a percentage into hundredths of a percent and a fixed amount into fils', function () {
    feRun(feExport());

    // The rows, not the report. `amount` is one column whose unit depends on
    // `type`, and this is the whole of that mapping.
    expect(feCoupon('welcome20')->amount)->toBe(2000)      // 20%
        ->and(feCoupon('welcome20')->type)->toBe('percent')
        ->and(feCoupon('halfpc')->amount)->toBe(1250)      // 12.5%
        ->and(feCoupon('freebasket')->amount)->toBe(10000) // 100%
        ->and(feCoupon('aed99off')->amount)->toBe(9950)    // AED 99.50 in fils
        ->and(feCoupon('aed99off')->type)->toBe('fixed_cart')
        ->and(feCoupon('tenoffeach')->amount)->toBe(1000)  // AED 10.00 per unit
        ->and(feCoupon('tenoffeach')->type)->toBe('fixed_product')
        // Money columns beside it, in fils too.
        ->and(feCoupon('aed99off')->minimum_amount)->toBe(25000);

    // And no float was constructed anywhere on the way: 12.5% is the value
    // that is not representable in binary, and Money::fils() is what parses it.
    expect(Money::fils('12.5', 'x'))->toBe(1250)->toBeInt();
});

it('prices those imported coupons through the real discount engine', function () {
    // The conversion is only right if the money that comes out is right, and
    // only CouponService can say that. AED 160 basket, 2 x AED 80.
    feRun(feExport());

    $product = Product::query()->where('sku', 'FE-1')->firstOrFail();
    $cart = App\Models\Cart::create(['token' => 'fe-price']);
    App\Models\CartItem::create([
        'cart_id' => $cart->id, 'product_id' => $product->id,
        'quantity' => 2, 'unit_price' => $product->price,
    ]);
    $cart->load('items.product');

    $service = app(CouponService::class);

    expect($product->price)->toBe(8000)
        ->and($service->discountFor(feCoupon('welcome20'), $cart))->toBe(3200)    // 20% of 160.00
        ->and($service->discountFor(feCoupon('halfpc'), $cart))->toBe(2000)       // 12.5% of 160.00
        /*
         * AED 99.50 flat, and the shop hands back AED 100.00. Not a typo and
         * not this importer rounding: CouponService::discountFor() ends with
         * WholeDirhams::away(), the owner's whole-dirham rule applied to a
         * DERIVED figure. Its comment reasons that a fixed coupon passes
         * through untouched "because its amount is refused unless it is whole
         * (CouponAdminApiController)" -- true of every coupon he types, and
         * NOT true of an imported one, because this importer is a second door
         * into `coupons` that does not go through that controller. So the
         * 50 fils the export carried cost the shop 50 fils on every order that
         * uses this code, which is why importing it silently is not an option
         * and the next test pins the report that says so.
         */
        ->and($service->discountFor(feCoupon('aed99off'), $cart))->toBe(10000)
        ->and($service->discountFor(feCoupon('tenoffeach'), $cart))->toBe(2000)   // AED 10 x 2 units
        ->and($service->discountFor(feCoupon('freebasket'), $cart))->toBe(16000); // the whole basket
});

it('names every imported coupon amount that carries fils, and leaves the value alone', function () {
    $report = feRun(feExport());

    /*
     * THE ROWS FIRST. The whole point is that the import did NOT round: a
     * 40,000-row migration must not die on a rounding policy, and it must not
     * quietly edit the owner's money either.
     */
    expect(feCoupon('aed99off')->amount)->toBe(9950)          // AED 99.50, exactly as exported
        ->and(feCoupon('aed99off')->maximum_amount)->toBe(49950)
        ->and(feCoupon('aed99off')->minimum_amount)->toBe(25000); // whole, so not reported

    // THEN THE REPORT, which is the half that makes the exactness honest.
    $changes = feChanges($report, 'coupons');
    $headline = feMatch($changes, 'carrying fils');

    expect($headline)->not->toBeNull()
        ->and($headline)->toContain('kbb:whole-dirhams');

    // Two fields on the one coupon: the discount and the maximum spend. The
    // whole minimum spend beside them is not reported, which is the difference
    // between a channel and a noise generator.
    expect($changes[$headline])->toBe(2);

    $samples = $report->for('coupons')->adjustments()[$headline]['samples'];
    $fields = array_column($samples, 'field');
    sort($fields);

    expect($fields)->toBe(['amount', 'maximum_amount']);

    // And the sample for the discount says what the SHOPPER will be given,
    // not a re-formatting of what the export said -- because discountFor()
    // rounds it up and the gap is the shop's money.
    $amount = collect($samples)->firstWhere('field', 'amount');

    expect($amount['before'])->toContain('99.50')
        ->and($amount['after'])->toContain('100')
        ->and($amount['after'])->toContain('rounded UP');

    /*
     * A PERCENTAGE IS NOT MONEY IN THIS COLUMN. 12.5% is stored as 1250, which
     * is not a whole number of dirhams and is not supposed to be one; reporting
     * it here would invite the audit to turn a 12.5% sale into a 13% one.
     * WholeDirhamAudit::couponGroup() excludes percentage coupons for exactly
     * this reason and so does this.
     */
    expect(feCoupon('halfpc')->amount)->toBe(1250)
        ->and(array_column($samples, 'id'))->not->toContain('halfpc');
});

it('refuses a percentage over 100 rather than importing a code that empties the basket', function () {
    $report = feRun(feExport());

    expect(feCoupon('toomuch'))->toBeNull()
        ->and(feReasons($report, 'coupons'))->toContain('more than 100%');
});

/* ------------------------------------------- coupons: the fields that DO exist */

it('carries the restrictions and flags this shop really does enforce', function () {
    feRun(feExport());

    $c = feCoupon('tenoffeach');

    expect($c->free_shipping)->toBeTrue()
        ->and($c->individual_use)->toBeTrue()
        ->and($c->exclude_sale_items)->toBeTrue()
        ->and($c->limit_usage_to_x_items)->toBe(2);

    expect(feCoupon('oneperperson')->allowed_emails)->toBe(['layla@example.com']);
});

it('translates WordPress post and term ids into this shop\'s own primary keys', function () {
    feRun(feExport());

    $mask = Product::query()->where('sku', 'FE-2')->firstOrFail();
    $cream = Product::query()->where('sku', 'FE-3')->firstOrFail();
    $masks = App\Models\Category::query()->where('slug', 'masks-fe')->firstOrFail();

    $c = feCoupon('masksonly');

    // The export said 11002 / 7002 / 11003 — WordPress ids. CouponService
    // tests these against $product->id, so a straight copy would restrict the
    // coupon to whatever products happen to hold those local ids.
    expect($c->product_ids)->toBe([$mask->id])
        ->and($c->category_ids)->toBe([$masks->id])
        ->and($c->excluded_product_ids)->toBe([$cream->id])
        ->and($mask->id)->not->toBe(11002);
});

it('refuses a restriction whose every id is missing, because empty means unrestricted', function () {
    $report = feRun(feExport());

    // The money case. CouponService reads `if ($coupon->product_ids && ...)`,
    // so an empty list is NO restriction — a 50% code meant for two
    // discontinued lines would apply to the entire catalogue.
    expect(feCoupon('ghostonly'))->toBeNull()
        ->and(feReasons($report, 'coupons'))->toContain('valid on the whole catalogue');

    // And the mirror image: an EXCLUSION naming a product that is not here is
    // inert — it cannot be in a basket — so the coupon still imports.
    $kept = feCoupon('nottheghost');

    expect($kept)->not->toBeNull()
        ->and($kept->excluded_product_ids)->toBeNull()
        ->and(feMatch(feChanges($report, 'coupons'), 'not in this shop and were dropped'))->not->toBeNull();
});

/* ------------------------------------------------------- coupons: usage counts */

it('imports usage_count verbatim on a fresh shop, so an exhausted code stays exhausted', function () {
    feRun(feExport());

    expect(feCoupon('welcome20')->usage_count)->toBe(462)
        ->and(feCoupon('welcome20')->usage_limit)->toBe(1000)
        ->and(feCoupon('expired10')->usage_count)->toBe(118)
        // Woo's other spelling of "no limit". 0 here would be a code nobody
        // can ever use: CouponService checks usage_count >= usage_limit.
        ->and(feCoupon('zerolimit')->usage_limit)->toBeNull();

    // No redemption rows are invented. Every one of them would carry a NULL
    // order_id that Store -> Coupons would then report as usage history.
    expect(CouponRedemption::query()->count())->toBe(0);
});

it('adds this shop\'s own uses to the export\'s count instead of clobbering them', function () {
    /*
     * THE DELTA PASS, which is the case that costs money. After cutover the
     * shop trades; a second import of the same unchanged export must not reset
     * the counter to WooCommerce's number and hand the public three more uses
     * of an exhausted code.
     */
    $dir = feExport();
    feRun($dir);

    $coupon = feCoupon('welcome20');
    $customer = Customer::query()->firstOrFail();

    // Three live uses, the way this shop makes them.
    foreach (range(1, 3) as $n) {
        CouponRedemption::create([
            'coupon_id' => $coupon->id, 'customer_id' => $customer->id,
            'order_id' => null, 'email' => 'shopper'.$n.'@example.com', 'amount' => 1000,
        ]);
        Coupon::whereKey($coupon->id)->increment('usage_count');
    }

    expect(feCoupon('welcome20')->usage_count)->toBe(465);

    feRun($dir, ['restart' => true]);

    expect(feCoupon('welcome20')->usage_count)->toBe(465);

    // And it survives a release: both sides fall by one together.
    $row = CouponRedemption::query()->where('coupon_id', $coupon->id)->firstOrFail();
    $row->update(['released_at' => now()]);
    Coupon::whereKey($coupon->id)->decrement('usage_count');

    expect(feCoupon('welcome20')->usage_count)->toBe(464);

    feRun($dir, ['restart' => true]);

    expect(feCoupon('welcome20')->usage_count)->toBe(464);
});

/* ------------------------------------------------------------- coupons: expiry */

it('imports an expired coupon, because the orders that used it reference it', function () {
    feRun(feExport());

    $expired = feCoupon('expired10');

    expect($expired)->not->toBeNull()
        ->and($expired->usage_count)->toBe(118)
        ->and($expired->expires_at->isPast())->toBeTrue();

    // Still refused at checkout, by the shop's own rule rather than by absence.
    $cart = App\Models\Cart::create(['token' => 'fe-expired']);
    $result = app(CouponService::class)->validate('expired10', $cart->load('items'));

    expect($result['ok'])->toBeFalse()->and($result['error'])->toContain('expired');
});

it('moves a bare expiry date to the end of that day, the way WooCommerce reads it', function () {
    $report = feRun(feExport());

    /*
     * Woo's date_expires is inclusive of the day named; this schema refuses a
     * coupon once now() > expires_at. Importing midnight retires the code a
     * day early, silently.
     */
    $c = feCoupon('welcome20');

    expect($c->expires_at->setTimezone('Asia/Dubai')->toDateTimeString())->toBe('2027-01-01 23:59:59')
        ->and(feMatch(feChanges($report, 'coupons'), 'bare expiry date moved'))->not->toBeNull();

    // An expiry that already carries a time is honoured as written, not moved.
    expect(feCoupon('timed')->expires_at->setTimezone('Asia/Dubai')->toDateTimeString())
        ->toBe('2027-06-30 12:00:00');
});

/* -------------------------------------------- coupons: what has no home at all */

it('refuses a draft or trashed coupon, because `coupons` has no status column', function () {
    $report = feRun(feExport());

    expect(feCoupon('notlive'))->toBeNull()
        ->and(feCoupon('binned'))->toBeNull()
        ->and(feReasons($report, 'coupons'))->toContain('no status column');

    // The premise, asserted rather than assumed: if a status column is ever
    // added, this refusal is wrong and must be revisited.
    expect(Schema::hasColumn('coupons', 'status'))->toBeFalse();
});

it('names the per-customer limit it cannot carry, with the value on it', function () {
    $report = feRun(feExport());

    // The limit IS imported — it is enforced from here on.
    expect(feCoupon('oneperperson')->usage_limit_per_user)->toBe(1)
        ->and(feCoupon('oneperperson')->usage_count)->toBe(207);

    $discards = $report->for('coupons')->discards();

    $whoUsedIt = feMatch(feChanges($report, 'coupons', 'discards'), 'which shoppers have already used');
    $usedBy = feMatch(feChanges($report, 'coupons', 'discards'), 'used_by is in this export');

    expect($whoUsedIt)->not->toBeNull(
        'a one-per-customer code imported and every customer who had already used it could use it again, '
        .'with nothing in the report saying so'
    )->and($usedBy)->not->toBeNull();

    // The value, not just the column name — "usage_limit_per_user" is a name
    // and "limit of 1 per customer, 207 uses already made" is a decision.
    expect($discards[$whoUsedIt]['samples'][0]['before'])->toContain('limit of 1 per customer')
        ->and($discards[$usedBy]['samples'][0]['before'])->toContain('layla@example.com');
});

it('refuses a discount type this shop would have to re-price to accept', function () {
    $report = feRun(feExport());

    expect(feCoupon('perprodpc'))->toBeNull()
        ->and(feReasons($report, 'coupons'))->toContain('per-product percentage');
});

it('reports nothing about a row it refused', function () {
    /*
     * Found by running: a coupon refused on its amount had ALREADY reported
     * "code lower-cased", so a run importing twelve coupons said nineteen
     * values went in changed. The adjusted channel's promise is "this row IS
     * in the database and is not what the export said", and the owner reads it
     * to decide what to approve — a refused row needs no approval.
     */
    $report = feRun(feExport());
    $changes = feChanges($report, 'coupons');
    $headline = feMatch($changes, 'lower-cased');

    expect($headline)->not->toBeNull()
        ->and($changes[$headline])->toBe(Coupon::query()->whereNotNull('wc_id')->count())
        ->and($changes[$headline])->toBe($report->for('coupons')->created);
});

/* ------------------------------------------------------------------- reviews */

it('maps WordPress comment_approved onto this schema\'s own three values', function () {
    feRun(feExport());

    expect(feReview(51001)->status)->toBe(ReviewStatus::APPROVED)   // '1'
        ->and(feReview(51008)->status)->toBe(ReviewStatus::PENDING) // '0'
        ->and(feReview(51009)->status)->toBe(ReviewStatus::SPAM);   // 'spam'

    // Every stored value is in the vocabulary. `rejected` was one screen's
    // private spelling and nothing may store it again.
    expect(Review::query()->pluck('status')->unique()->values()->all())
        ->each->toBeIn(ReviewStatus::ALL);
});

it('folds trash onto spam and says so, because they are not the same word', function () {
    $report = feRun(feExport());

    expect(feReview(51010)->status)->toBe(ReviewStatus::SPAM)
        ->and(feMatch(feChanges($report, 'reviews'), "'trash' imported as 'spam'"))->not->toBeNull();
});

it('refuses a moderation value with no equivalent rather than folding it onto pending', function () {
    $report = feRun(feExport());

    /*
     * ReviewStatus::normalise() folds the unknown onto `pending`, which is
     * right for one hand-edited row and wrong for an import: a whole export
     * under an unrecognised spelling would land silently in the queue and the
     * report would say every row imported.
     */
    expect(feReview(51011))->toBeNull()
        ->and(feReasons($report, 'reviews'))->toContain('post-trashed');
});

it('refuses a review whose product is gone, rather than publishing it as a review of the shop', function () {
    $report = feRun(feExport());

    expect(feReview(51004))->toBeNull()
        ->and(feReasons($report, 'reviews'))->toContain('product post id 99998');

    // reviews.product_id NULL is not "unknown" — it is Review::scopeBusiness(),
    // which /reviews and the homepage wall publish as a review of the business.
    expect(Review::query()->business()->pluck('content')->all())
        ->not->toContain('Best serum ever');
});

it('imports a genuine shop review, which is the one row that may carry no product', function () {
    feRun(feExport());

    $fan = feReview(51015);

    expect($fan)->not->toBeNull()
        ->and($fan->product_id)->toBeNull()
        ->and(Review::query()->business()->pluck('source_id')->all())->toBe([51015]);
});

it('imports a review with no author as Anonymous rather than as a blank card', function () {
    $report = feRun(feExport());

    $row = feReview(51003);

    expect($row)->not->toBeNull()
        ->and($row->author_name)->toBe('Anonymous')
        ->and(feMatch(feChanges($report, 'reviews'), 'no author name'))->not->toBeNull();
});

it('refuses a rating outside 1 to 5, and a review with no rating at all', function () {
    $report = feRun(feExport());

    expect(feReview(51005))->toBeNull()   // 0
        ->and(feReview(51006))->toBeNull() // 6
        ->and(feReview(51007))->toBeNull(); // none

    $reasons = feReasons($report, 'reviews');

    expect($reasons)->toContain('outside 1 to 5')->toContain('rating is empty');

    /*
     * The premise for refusing the empty one, asserted rather than assumed:
     * reviews.rating DEFAULTS TO FIVE, so a row imported without one would
     * publish a perfect score nobody wrote and average it into
     * products.rating and into the schema.org aggregateRating. Demonstrated by
     * writing a row without the column rather than by reading the migration.
     */
    $id = DB::table('reviews')->insertGetId([
        'source' => 'fe-premise', 'source_id' => 1, 'author_name' => 'No rating given',
        'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::table('reviews')->where('id', $id)->value('rating'))->toEqual(5);

    // Every rating that DID get in is a usable star rating.
    expect(Review::query()->pluck('rating')->all())->each->toBeGreaterThanOrEqual(1);
    expect(Review::query()->pluck('rating')->all())->each->toBeLessThanOrEqual(5);
});

it('refuses a WordPress comment that is not a review', function () {
    $report = feRun(feExport());

    expect(feReview(51012))->toBeNull()
        ->and(feReasons($report, 'reviews'))->toContain('is not a review');
});

it('recomputes every affected product\'s rating and review count from APPROVED reviews only', function () {
    feRun(feExport());

    $serum = Product::query()->where('sku', 'FE-1')->firstOrFail();
    $mask = Product::query()->where('sku', 'FE-2')->firstOrFail();

    /*
     * FE-1 carries five imported reviews: 5 and 3 approved, 1 pending, 5 spam
     * and 2 (trash folded to spam); three more were refused. Only the two
     * approved ones may count — these columns are what the shop cards print,
     * what ?sort=rating orders by, and what the product page publishes to
     * Google as schema.org aggregateRating.
     */
    expect((float) $serum->rating)->toBe(4.0)
        ->and($serum->review_count)->toBe(2)
        ->and((float) $mask->rating)->toBe(4.5)
        ->and($mask->review_count)->toBe(2);

    /*
     * And the aggregate really is approved-only, which is the half a count of
     * 2 alone does not prove: there are five rows on this product and the spam
     * five-star among them would have pulled the average to 3.2 over a count
     * of 5 if status were not asked about.
     */
    expect(Review::query()->where('product_id', $serum->id)->count())->toBe(5)
        ->and(Review::query()->where('product_id', $serum->id)->avg('rating'))->not->toBe(4.0);
});

it('names the review it had to stamp with today\'s date', function () {
    $report = feRun(feExport());

    $undated = feReview(51016);

    expect($undated)->not->toBeNull()
        ->and($undated->created_at->isToday())->toBeTrue()
        ->and(feMatch(feChanges($report, 'reviews'), 'no readable review date'))->not->toBeNull();
});

it('puts the imported rating into the product page\'s structured data', function () {
    feRun(feExport());

    $serum = Product::query()->where('sku', 'FE-1')->firstOrFail();
    $html = $this->get('/product/'.$serum->slug)->assertOk()->getContent();

    expect($html)->toContain('AggregateRating')
        ->toContain('"ratingValue":"4"')
        ->toContain('"reviewCount":2');
});

/* ------------------------------------------------------------- the leak check */

it('never hands an imported reviewer\'s email or IP to the public api', function () {
    /*
     * CLAUDE.md names `reviews` in the list of things /api/* has leaked, and
     * an import is the moment author_email and ip stop being a handful of rows
     * and become the whole shop's reviewer base, harvestable in one request.
     */
    feRun(feExport());

    $stored = feReview(51001);

    // The premise: the columns really are populated, so a clean response is
    // evidence of an allowlist rather than of an empty table.
    expect($stored->author_email)->toBe('layla@example.com')
        ->and($stored->ip)->toBe('203.0.113.9')
        ->and($stored->status)->toBe('approved');

    foreach (['/api/reviews', '/api/reviews?limit=100', '/api/products/'.Product::query()->where('sku', 'FE-1')->value('slug').'/reviews'] as $url) {
        $raw = $this->getJson($url)->assertOk()->getContent();

        expect($raw)->not->toContain('layla@example.com')
            ->not->toContain('203.0.113.9')
            ->not->toContain('198.51.100.4')
            ->not->toContain('author_email')
            ->not->toContain('"ip"');
    }

    // The storefront pages that print reviews, too — the allowlist is on the
    // API and these render whole models into a blade template.
    $html = $this->get('/product/'.Product::query()->where('sku', 'FE-1')->value('slug'))->assertOk()->getContent();

    expect($html)->not->toContain('layla@example.com')->not->toContain('203.0.113.9');

    $wall = $this->get('/reviews')->assertOk()->getContent();

    expect($wall)->not->toContain('layla@example.com')->not->toContain('203.0.113.9');
});

it('does not publish an imported review that was not approved', function () {
    feRun(feExport());

    $raw = $this->getJson('/api/reviews?limit=100')->assertOk()->getContent();

    expect($raw)->not->toContain('awaiting moderation')   // pending
        ->not->toContain('buy cheap watches')             // spam
        ->not->toContain('owner binned this')             // trash -> spam
        ->toContain('Cleared my skin in a week');         // approved
});

/* ------------------------------------------------ idempotence and interruption */

it('reports created 0 and updated 0 on a second pass over an unchanged export', function () {
    $dir = feExport();

    $first = feRun($dir);

    $coupons = Coupon::query()->whereNotNull('wc_id')->count();
    $reviews = Review::query()->where('source', 'wp_comment')->count();

    expect($coupons)->toBeGreaterThan(0)->and($reviews)->toBeGreaterThan(0);

    // restart: a FINISHED entity starts again from row one anyway, and this
    // makes the intent explicit rather than relying on that.
    $second = feRun($dir, ['restart' => true]);

    foreach (['coupons', 'reviews'] as $entity) {
        expect($second->for($entity)->created)->toBe(0, $entity.' inserted rows on a second identical pass')
            ->and($second->for($entity)->updated)->toBe(0, $entity.' rewrote rows that had not changed')
            ->and($second->for($entity)->unchanged)->toBe($first->for($entity)->created);
    }

    // And the table did not grow, which is the fact the counters claim.
    expect(Coupon::query()->whereNotNull('wc_id')->count())->toBe($coupons)
        ->and(Review::query()->where('source', 'wp_comment')->count())->toBe($reviews);
});

it('resumes both entities mid-file after a run is killed', function () {
    $dir = feExport();
    $key = 'fe-resume-'.bin2hex(random_bytes(4));

    /*
     * The shared host kills the request. --limit is exactly that: the entity
     * is left UNFINISHED, so the next run carries on from the last committed
     * batch rather than starting over.
     */
    $partial = (new ImportRunner)->run(new ImportOptions(
        directory: $dir, runKey: $key, batchSize: 2, limit: 4,
    ));

    $couponsAfterKill = Coupon::query()->whereNotNull('wc_id')->count();
    $reviewsAfterKill = Review::query()->where('source', 'wp_comment')->count();

    expect($couponsAfterKill)->toBeGreaterThan(0)
        ->and($couponsAfterKill)->toBeLessThan(count(feCouponRows()))
        ->and($reviewsAfterKill)->toBeGreaterThan(0);

    // Same command again. It continues.
    $resumed = (new ImportRunner)->run(new ImportOptions(
        directory: $dir, runKey: $key, batchSize: 2,
    ));

    expect($resumed->for('coupons')->skipped)->toBeGreaterThan(0)
        ->and($resumed->for('reviews')->skipped)->toBeGreaterThan(0);

    $coupons = Coupon::query()->whereNotNull('wc_id')->orderBy('wc_id')
        ->get(['wc_id', 'code', 'amount', 'usage_count'])->toArray();
    $reviews = Review::query()->where('source', 'wp_comment')->orderBy('source_id')
        ->get(['source_id', 'rating', 'status', 'author_name'])->toArray();

    expect($coupons)->not->toBeEmpty()->and($reviews)->not->toBeEmpty();

    // Nothing was duplicated by the interruption.
    expect(Coupon::query()->whereNotNull('wc_id')->count())->toBe(count($coupons))
        ->and(Review::query()->where('source', 'wp_comment')->distinct()->count('source_id'))
        ->toBe(count($reviews));

    /*
     * THE CONTROL, and it is the only thing resume actually has to promise:
     * the interrupted-then-resumed run landed on the SAME ROWS a single
     * uninterrupted run does. Asserted by clearing both tables and importing
     * the same export again in one go, under a fresh run key, then comparing
     * the rows column by column -- not by comparing counters, which agree in
     * plenty of cases where the contents do not.
     */
    Review::query()->where('source', 'wp_comment')->delete();
    Coupon::query()->whereNotNull('wc_id')->delete();

    (new ImportRunner)->run(new ImportOptions(
        directory: $dir, runKey: 'fe-control-'.bin2hex(random_bytes(4)),
    ));

    $controlCoupons = Coupon::query()->whereNotNull('wc_id')->orderBy('wc_id')
        ->get(['wc_id', 'code', 'amount', 'usage_count'])->toArray();
    $controlReviews = Review::query()->where('source', 'wp_comment')->orderBy('source_id')
        ->get(['source_id', 'rating', 'status', 'author_name'])->toArray();

    expect($controlCoupons)->toEqual($coupons)
        ->and($controlReviews)->toEqual($reviews);

    // A third pass over the finished export changes nothing, restart and all.
    $again = (new ImportRunner)->run(new ImportOptions(
        directory: $dir, runKey: 'fe-third-'.bin2hex(random_bytes(4)),
    ));

    expect($again->for('coupons')->created)->toBe(0)
        ->and($again->for('reviews')->created)->toBe(0)
        ->and($again->for('coupons')->updated)->toBe(0)
        ->and($again->for('reviews')->updated)->toBe(0);
});

it('runs either entity on its own under --only', function () {
    $dir = feExport();

    // Products first, because a coupon's restriction list and a review's
    // product both need them.
    feRun($dir, ['only' => ['categories', 'brands', 'products', 'customers']]);

    expect(Coupon::query()->whereNotNull('wc_id')->count())->toBe(0)
        ->and(Review::query()->where('source', 'wp_comment')->count())->toBe(0);

    feRun($dir, ['only' => ['coupons']]);

    expect(Coupon::query()->whereNotNull('wc_id')->count())->toBeGreaterThan(0)
        ->and(Review::query()->where('source', 'wp_comment')->count())->toBe(0);

    feRun($dir, ['only' => ['reviews']]);

    expect(Review::query()->where('source', 'wp_comment')->count())->toBeGreaterThan(0);
});

it('writes nothing at all on a dry run, aggregates included', function () {
    $dir = feExport();

    // Seed the catalogue for real first, so the dry run has products to
    // resolve against and a rating to move.
    feRun($dir, ['only' => ['categories', 'brands', 'products', 'customers']]);

    $serum = Product::query()->where('sku', 'FE-1')->firstOrFail();

    expect((float) $serum->rating)->toBe(0.0)->and($serum->review_count)->toBe(0);

    $report = feRun($dir, ['dryRun' => true]);

    expect($report->for('coupons')->created)->toBeGreaterThan(0)
        ->and($report->for('reviews')->created)->toBeGreaterThan(0);

    // Nothing survived — including the ProductRating::refresh() that
    // ReviewImporter::finalise() runs, which looks like a write outside the
    // rollback and is not.
    expect(Coupon::query()->whereNotNull('wc_id')->count())->toBe(0)
        ->and(Review::query()->where('source', 'wp_comment')->count())->toBe(0);

    $serum->refresh();

    expect((float) $serum->rating)->toBe(0.0)->and($serum->review_count)->toBe(0);
});
