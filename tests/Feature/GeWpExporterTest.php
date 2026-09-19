<?php

/*
 * THE ROUND TRIP: the WordPress plugin's output, into this shop's real importer.
 *
 * ============================================================================
 * WHY THIS FILE IS THE DELIVERABLE AND wordpress-plugin/ IS ONLY THE MEANS
 * ============================================================================
 *
 * "Compatible" is the owner's actual requirement and it is a claim about TWO
 * systems. A plugin that writes plausible CSVs proves nothing about it; neither
 * does an importer that reads plausible CSVs. The only thing that proves it is
 * the output of the first going into the second and coming out as the right
 * rows with the right money.
 *
 * So: `tests/Fixtures/kbb-export/` is a REAL export, written by the plugin's own
 * stage classes running over WordPress-shaped tables in MySQL (see
 * wordpress-plugin/harness), and every test below feeds it to
 * App\Services\Import\ImportRunner -- the same class `kbb:import` runs, with no
 * test double anywhere in the path.
 *
 * ── AND THE FIXTURE CANNOT GO STALE ─────────────────────────────────────────
 *
 * A checked-in fixture is a snapshot, and a snapshot of a generator drifts away
 * from the generator the first time somebody edits it. `it regenerates the
 * fixture from the plugin and gets the same bytes` runs the plugin again and
 * compares, so an edit to any stage that changes the output fails here rather
 * than being discovered when the owner runs the real thing. It needs MySQL, so
 * it skips where MySQL is not there -- and the round-trip tests above it do
 * NOT, so the compatibility evidence runs on every engine and in CI.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderNote;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Review;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportRunner;
use App\Services\Update\UpdateGuard;

/** The export the plugin wrote. */
function geExportDir(): string
{
    return base_path('tests/Fixtures/kbb-export');
}

/**
 * The WordPress-shaped MySQL database the harness builds its fixture shop in.
 *
 * ── WHY THIS IS A VARIABLE AND NOT A LITERAL ────────────────────────────────
 *
 * `wordpress-plugin/harness/shop.php` DROPS AND REBUILDS every table it uses.
 * With the name hard-coded, two worktrees running this file at once tear the
 * schema down under each other mid-run, and the failure reads as a defect in
 * whichever lane happened to be in `kbb_harness_build()` at the time:
 *
 *     Base table or view not found: 1146 Table 'kbb_ge_wp.wp_options' doesn't exist
 *
 * Measured, not theorised: three tests here failed exactly that way while
 * another lane's full suite was running in a sibling worktree, and the same
 * three passed alone before and after.
 *
 * `phpunit-mysql.xml` already carries this warning about the LARAVEL database
 * and already solves it with a variable — *"The database below is the default,
 * and it is SHARED. Two worktrees running this config at once without
 * KBB_TEST_DB drop the schema under each other mid-run and report hundreds of
 * failures belonging to neither of them."* This is that same hazard, in the
 * other database, and it gets the same answer:
 *
 *     KBB_WP_DB=kbb_gl_wp vendor/bin/pest tests/Feature/GeWpExporterTest.php
 *
 * The default is unchanged, so CI and anybody running one lane at a time see
 * exactly what they saw before.
 */
function geWpDb(): string
{
    $name = getenv('KBB_WP_DB');

    return is_string($name) && $name !== '' ? $name : 'kbb_ge_wp';
}

/**
 * Every PHP file of the plugin proper.
 *
 * `glob()` with `**` does NOT recurse -- it matches one directory level -- so a
 * glob-based list silently misses `includes/stages/`, which is where eleven of
 * the seventeen stages live. An iterator is the only way to be sure the list is
 * the list.
 *
 * @return list<string>
 */
function geePluginFiles(): array
{
    $out = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('wordpress-plugin/kbb-exporter'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ('php' === $file->getExtension()) {
            $out[] = $file->getPathname();
        }
    }

    sort($out);

    return $out;
}

function geManifest(): array
{
    return json_decode((string) file_get_contents(geExportDir().'/manifest.json'), true);
}

/**
 * The import, run the way the runbook says to run this export.
 *
 * `--timezone` is not a guess: it is `source.timezone` out of the manifest,
 * which is the whole reason that key is in there. App\Services\Import\DateParser
 * refuses to default it -- "the source timezone is a required argument, not a
 * default. A caller that does not know it has to go and find out" -- and this
 * is the shop being told rather than finding out.
 */
function geImport(array $overrides = []): App\Services\Import\ImportReport
{
    $manifest = geManifest();

    $options = new ImportOptions(...array_merge([
        'directory' => geExportDir(),
        'sourceTimezone' => $manifest['source']['timezone'],
        // The demo catalogue every install carries holds the slugs `cosrx` and
        // `beauty-of-joseon`, which are real brands this shop really sells.
        // WooImportTest passes the same flag for the same reason.
        'adoptBySlug' => true,
    ], $overrides));

    return (new ImportRunner)->run($options);
}

/** Every rejection reason the run produced, entity by entity. */
function geRejections(App\Services\Import\ImportReport $report): array
{
    $out = [];

    foreach (ImportRunner::entityNames() as $entity) {
        foreach ($report->for($entity)->rejections() as $rejection) {
            $out[] = $entity.' line '.$rejection['line'].' ('.$rejection['id'].'): '.$rejection['reason'];
        }
    }

    return $out;
}

/**
 * The ROWS of this fixture that an entity declines ON PURPOSE, keyed by the
 * row rather than by the reason.
 *
 * WHY THIS LIST EXISTS AND WHY IT IS THIS NARROW. The two tests below assert
 * that the plugin's own output imports with NOTHING refused, which is the
 * whole point of a round trip -- an export this shop cannot read is not an
 * export. The list was empty until `posts.csv` had an importer, because
 * nothing opened the file.
 *
 * `posts.csv` carries the blog AND the pages AND every other post type, by the
 * exporter's own design (see class-kbb-export-stage-posts.php: a DENYlist,
 * because an allowlist "would drop it silently"). PostImporter writes the
 * Journal only, so the fixture's one WordPress page is declined -- by name, in
 * the rejection list and again in the discard list the owner approves. That is
 * a decision this repository has written down, not a row the round trip
 * failed on.
 *
 * KEYED BY ROW, NOT BY REASON TEXT, so that this list cannot be widened by
 * accident: an entity that started refusing a DIFFERENT row, or refusing this
 * one for a different reason, still fails the assertions below. The reason is
 * checked separately, once, in its own test.
 *
 * @return list<string>
 */
function geDeclinedByDesign(): array
{
    return ['posts line 3 (id=7002)'];
}

/**
 * Every refusal that is NOT one of those.
 *
 * array_diff on the row prefixes, not expect()->not->toContain() -- see the
 * comment on the first test for why that idiom passes vacuously.
 *
 * @return list<string>
 */
function geUnexpectedRejections(App\Services\Import\ImportReport $report): array
{
    $declined = geDeclinedByDesign();

    return array_values(array_filter(
        geRejections($report),
        static function (string $rejection) use ($declined): bool {
            foreach ($declined as $prefix) {
                if (str_starts_with($rejection, $prefix)) {
                    return false;
                }
            }

            return true;
        },
    ));
}

it('imports the plugin export cleanly, with nothing refused that should not be', function () {
    $report = geImport();

    /*
     * array_diff, NOT expect()->not->toContain().
     *
     * `toContain` is variadic, so `->not->toContain($needle, $message)` reads
     * the message as a SECOND NEEDLE and passes vacuously whenever the message
     * string is absent -- which it always is. An assertion that cannot fail is
     * worse than no assertion, because it is counted.
     */
    $rejections = geUnexpectedRejections($report);

    expect($rejections)->toBe([], 'the plugin export produced rejections: '.implode(' | ', $rejections));

    /*
     * AND THE ONE DECLINE IS STILL A DECLINE, asserted rather than merely
     * excused. If PostImporter stopped refusing the page -- or started
     * importing WordPress pages over this shop's own /about/ -- the list above
     * would go on being empty and this would go red instead.
     */
    $declined = array_values(array_filter(
        geRejections($report),
        static fn (string $r): bool => str_starts_with($r, 'posts line 3 (id=7002)'),
    ));

    expect($declined)->toHaveCount(1);
    expect(str_contains($declined[0], "post type 'page' is not an article"))->toBeTrue($declined[0]);

    /*
     * AND THE ARTICLE IN THE SAME FILE IS IN THE JOURNAL. The round trip is
     * only proved by the rows that landed: posts.csv was a file the contract
     * marked as a gap, written by the plugin and opened by nothing, and
     * /skincare-guide/ rendered an empty index because of it.
     */
    expect(App\Models\Post::query()->where('source_post_id', 7001)->value('slug'))
        ->toBe('how-to-layer-a-k-beauty-routine');

    // The manifest's own denominators, against the rows that actually landed.
    $manifest = geManifest();

    expect(Category::query()->whereNotNull('source_term_id')->count())->toBe($manifest['counts']['categories']);
    expect(Brand::query()->whereNotNull('source_term_id')->count())->toBe($manifest['counts']['brands']);
    expect(Product::query()->withTrashed()->whereNotNull('wc_id')->count())->toBe($manifest['counts']['products']);
    expect(Order::query()->withTrashed()->whereNotNull('wc_order_id')->count())->toBe($manifest['counts']['orders']);
    expect(OrderItem::query()->whereNotNull('wc_item_id')->count())->toBe($manifest['counts']['order_items']);
    expect(Coupon::query()->whereNotNull('wc_id')->count())->toBe($manifest['counts']['coupons']);
    expect(Review::query()->where('source', 'wp_comment')->count())->toBe($manifest['counts']['reviews']);

    // customers.csv carries three WordPress users; the guest order synthesises
    // a fourth customer with no wp_user_id, which is decision D3 and is counted
    // separately by CustomerImporter::countImported() on purpose.
    expect(Customer::query()->withTrashed()->whereNotNull('wp_user_id')->count())->toBe($manifest['counts']['customers']);
});

it('lands every amount on the fil', function () {
    geImport();

    // 99.50 regular, 89.00 sale. The fils are the point: the previous dead
    // importer in this repository assigned the decimal straight into an integer
    // column and AED 99.50 landed as 99 fils.
    $serum = Product::query()->where('wc_id', 4021)->firstOrFail();

    expect($serum->price)->toBe(9950);
    expect($serum->sale_price)->toBe(8900);

    // A thousands separator, which Money::fils() accepts only because the
    // grouping is unambiguous alongside a decimal point.
    expect(Product::query()->where('wc_id', 4022)->value('price'))->toBe(123450);

    // The order the exporter had to SUM for itself: WooCommerce stores no
    // subtotal and no fee total on an order, and both are line items.
    $guest = Order::query()->where('wc_order_id', 10234)->firstOrFail();

    expect($guest->subtotal)->toBe(123450);
    expect($guest->discount_total)->toBe(500);
    expect($guest->shipping_total)->toBe(1000);
    expect($guest->fee_total)->toBe(250);
    expect($guest->total)->toBe(124200);

    // and the totals add up, which is the property the owner would notice:
    expect($guest->subtotal - $guest->discount_total + $guest->shipping_total + $guest->fee_total + $guest->tax_total)
        ->toBe($guest->total);

    // The other order's lines sum to its total too.
    $first = Order::query()->where('wc_order_id', 10233)->firstOrFail();
    $lines = OrderItem::query()->where('order_id', $first->id)->sum('total');

    expect((int) $lines)->toBe($first->total);
});

it('keeps the WordPress ids that are a URL contract', function () {
    geImport();

    // ProductImporter's header: "`wc_id` IS A URL CONTRACT, not merely a key.
    // `?add-to-cart={id}` links built against the WooCommerce post ids are live
    // in the wild". The plugin never invents one; these are WordPress's.
    expect(Product::query()->where('wc_id', 4021)->value('slug'))->toBe('serum-4021');
    expect(Order::query()->where('wc_order_id', 10233)->value('order_number'))->toBe('KBB-1001');
    expect(OrderItem::query()->where('wc_item_id', 5501)->value('quantity'))->toBe(3);
    expect(Customer::query()->where('wp_user_id', 412)->value('email'))->toBe('buyer@example.test');
});

it('files the serum under its leaf category and not its parent', function () {
    geImport();

    // ProductImporter takes $categoryIds[0] as `products.category_id`. Ordered
    // by term id, the parent (Skincare, 15) would win over the leaf (Face
    // Cleansers, 22) purely because it was created first, and every product on
    // the shop would file itself one level too high. The plugin puts the Yoast
    // primary category first for exactly this reason.
    $serum = Product::query()->where('wc_id', 4021)->firstOrFail();

    expect(Category::query()->whereKey($serum->category_id)->value('source_term_id'))->toBe(22);

    // and it is still in both, through the pivot.
    $terms = Category::query()
        ->whereIn('id', DB::table('category_product')->where('product_id', $serum->id)->pluck('category_id'))
        ->pluck('source_term_id')
        ->sort()
        ->values()
        ->all();

    expect($terms)->toBe([15, 22]);
});

it('imports a gallery whose filename contains a comma as two images, not one', function () {
    geImport();

    // The defect ProductImporter's own comment records having shipped: a
    // comma-separated gallery split on the wrong character imports "a.jpg,b.jpg"
    // as ONE string that is not a URL, and the product page renders one broken
    // frame instead of two pictures. The plugin writes the gallery
    // pipe-separated so the importer lands on its unambiguous branch, and the
    // fixture's second image has a comma in its filename so this is a
    // measurement rather than a preference.
    $images = Product::query()->where('wc_id', 4021)->value('images');
    $images = is_string($images) ? json_decode($images, true) : $images;

    expect($images)->toHaveCount(2);
    expect($images[1])->toContain('ginseng-serum-3,-detail.jpg');

    foreach ($images as $url) {
        expect(str_starts_with($url, 'https://'))->toBeTrue("gallery entry is not a URL: {$url}");
    }
});

it('does not let a refund line leak into the order items', function () {
    geImport();

    // WooCommerce keeps a refund's lines in the SAME table as an order's, with
    // order_id pointing at the refund. Exported wholesale they would all be
    // refused -- "order 10236 is not in this database" -- which on the real shop
    // is 207 refusals in a report meant for real problems.
    expect(OrderItem::query()->where('wc_item_id', 5507)->exists())->toBeFalse();

    // and the refund itself is carried, in refunds.csv, with the line it gave
    // back named.
    $refunds = array_map('str_getcsv', file(geExportDir().'/refunds.csv', FILE_IGNORE_NEW_LINES));
    $row = array_combine($refunds[0], $refunds[1]);

    expect($row['order_id'])->toBe('10235');
    expect($row['amount'])->toBe('99.50');
    expect($row['refunded_items'])->toBe('5506:-1:-99.50');

    /*
     * AND IT ARRIVES. App\Services\Import\Entities\RefundImporter reads that
     * file now (Lane GI), so the money the plugin took out of order_items.csv
     * is not lost — it lands as one refund against the parent order, which is
     * what stops that order reading as its full total. The whole before/after
     * is in tests/Feature/GiRefundsAndNotesTest.php; this is the round trip
     * closing on the row the two lanes hand to each other.
     */
    $refund = Refund::query()->where('wc_refund_id', 10236)->firstOrFail();

    expect((int) $refund->amount)->toBe(9950)
        ->and((int) $refund->order_id)->toBe(
            (int) Order::query()->where('wc_order_id', 10235)->value('id')
        );

    // The order note travelled with it, on the same round trip.
    expect(OrderNote::query()->where('source_comment_id', 8201)->value('content'))
        ->toBe('Order status changed from Processing to Completed.');
});

it('carries the Yoast SEO the shop reads, and says what it dropped', function () {
    $report = geImport();

    $serum = Product::query()->where('wc_id', 4021)->firstOrFail();
    $seo = is_string($serum->seo) ? json_decode($serum->seo, true) : $serum->seo;

    expect($seo['desc'])->toBe("A ginseng serum, exported from the old shop's Yoast settings.");
    expect($seo['title'])->toContain('%%currentyear%%');

    // The GTIN key, which does NOT start with an underscore and which a LIKE on
    // `_yoast_wpseo_%` alone would miss entirely. docs/FX-YOAST-TIER-CENSUS.md
    // calls that "an import gap, not a data gap"; the plugin's job is to make
    // sure the data is there when the gap is closed, and this asserts the
    // column reached the file with its serialised map intact.
    $rows = array_map('str_getcsv', file(geExportDir().'/seo.csv', FILE_IGNORE_NEW_LINES));
    $header = $rows[0];

    expect($header)->toContain('wpseo_global_identifier_values');

    $first = array_combine($header, $rows[1]);

    expect(App\Support\YoastTiers::gtinFrom($first))->toBe('8809453510003');

    // and the free-tier keys this shop has no home for were seen and named,
    // rather than silently absent from the export.
    expect($header)->toContain('_yoast_wpseo_focuskw');
    expect(array_keys($report->for('seo')->discards()))->not->toBe([]);
});

it('imports the pre-WooCommerce-3.0 review that a raw copy would have refused', function () {
    geImport();

    // ReviewImporter::assertIsAReview() refuses anything whose comment_type is
    // PRESENT and is not 'review', and WooCommerce before 3.0 left it empty. A
    // straight copy would refuse every review the shop took before 2017, with a
    // message telling the owner to export with comment_type = 'review' -- which
    // is what the plugin does, conditioned on the row being a comment, on a
    // product, carrying a star rating.
    $legacy = Review::query()->where('source', 'wp_comment')->where('source_id', 8102)->first();

    expect($legacy)->not->toBeNull();
    expect($legacy->rating)->toBe(4);

    // The question (8103) and the reply (8104) are NOT reviews and are not in
    // the file. ReviewImporter would default a rating-less row to five stars,
    // which the shop then publishes to Google as an aggregateRating.
    expect(Review::query()->where('source', 'wp_comment')->whereIn('source_id', [8103, 8104])->count())->toBe(0);

    // The reply is not lost, though: it is carried on its parent's row.
    expect(Review::query()->where('source_id', 8101)->value('reply'))->toBe('Thank you Layla!');
});

it('gives the coupon the whole of its last day', function () {
    geImport();

    // WooCommerce's date_expires is a timestamp at midnight and Woo treats that
    // day as inclusive; this shop refuses a coupon once now() > expires_at. The
    // plugin emits a BARE DATE precisely so CouponImporter's correction can
    // recognise it and move it to 23:59:59 -- formatting it as a datetime would
    // defeat that branch silently and retire the code a day early.
    $coupon = Coupon::query()->where('wc_id', 9101)->firstOrFail();

    // 2027-01-01 19:59:59 UTC IS 23:59:59 in Asia/Dubai, which is the end of the
    // day the coupon names. Asserting the UTC string alone would be asserting
    // the timezone arithmetic by accident, so both are stated: the column holds
    // UTC and the shopper's last usable second is the one they would expect.
    expect($coupon->expires_at->toDateTimeString())->toBe('2027-01-01 19:59:59');
    expect($coupon->expires_at->setTimezone('Asia/Dubai')->toDateTimeString())->toBe('2027-01-01 23:59:59');
    expect($coupon->code)->toBe('welcome20');
    expect($coupon->amount)->toBe(2000);

    // The withdrawn code is not in the file at all. `coupons` has no status
    // column, so importing a draft would put a code the owner had retired back
    // into the shop as a working discount.
    expect(Coupon::query()->where('wc_id', 9102)->exists())->toBeFalse();
});

it('holds the trashed product back and says so in the manifest', function () {
    geImport();

    expect(Product::query()->withTrashed()->where('wc_id', 4024)->exists())->toBeFalse();

    // Held back is not the same as forgotten. The count is in the manifest,
    // which is the only place the owner would ever see it.
    $notes = implode(' ', geManifest()['notes']);

    expect($notes)->toContain('in the WordPress trash');
    expect($notes)->toContain('not published');
});

it('reads the order dates in the shop timezone and is checked against the GMT column', function () {
    $report = geImport();

    // 2019-03-04 11:22:33 Asia/Dubai is 07:22:33 UTC, and the shop stores UTC.
    expect(Order::query()->where('wc_order_id', 10233)->value('created_at')->toDateTimeString())
        ->toBe('2019-03-04 07:22:33');

    /*
     * AND THE EXPORT CARRIES THE COLUMN THAT CATCHES A WRONG --timezone.
     * OrderImporter::checkDeclaredTimezone() compares date_created against
     * date_created_gmt and reports a disagreement once, with a count. Run with
     * the right zone there is nothing to report; the assertion that matters is
     * that running with the WRONG one DOES report, because a check that cannot
     * fire is the dead filter this repository has already paid for once.
     */
    // adjustments() is keyed BY KIND, with a count and five samples per kind --
    // which is the shape docs/FV-IMPORT-AT-VOLUME.md §10 describes and the
    // reason a whole catalogue sharing one template is one line and not 671.
    $quiet = array_filter(
        array_keys($report->for('orders')->adjustments()),
        static fn (string $kind): bool => str_contains($kind, 'GMT column disagrees'),
    );

    expect($quiet)->toBe([]);

    $wrong = geImport(['sourceTimezone' => 'America/New_York', 'runKey' => 'wrong-tz', 'restart' => true]);

    $fired = array_filter(
        array_keys($wrong->for('orders')->adjustments()),
        static fn (string $kind): bool => str_contains($kind, 'GMT column disagrees'),
    );

    expect($fired)->not->toBe([], 'date_created_gmt is in the export but the timezone check never fired');
});

it('names the permalink shape instead of inferring it', function () {
    $rows = array_map('str_getcsv', file(geExportDir().'/permalinks.csv', FILE_IGNORE_NEW_LINES));
    $header = array_shift($rows);

    $by = [];

    foreach ($rows as $row) {
        $row = array_combine($header, $row);
        $by[$row['type']][] = $row;
    }

    /*
     * THE TWO QUESTIONS THIS MIGRATION HAS BEEN GUESSING AT.
     *
     * App\Services\Import\RedirectMap carries a banner saying that its category
     * premise did not survive being checked, and refuses to guess a brand
     * archive base at all because "inventing one writes 93 redirects from an
     * address that may never have existed". The plugin stands inside the shop
     * and asks.
     */

    // Categories: flat at the site root, which is what an empty `category_base`
    // produces and what App\Support\LegacyCategoryUrls describes.
    expect($by['category'][0]['permalink'])->toBe('https://kbeautybliss.com/skincare/');
    expect($by['category'][0]['source'])->toBe('wp');

    // Products: /product/{slug}/, which is also this shop's U-01, so they do
    // not move. "We checked and none of them moved" is the answer, and silence
    // is not.
    expect($by['product'][0]['permalink'])->toBe('https://kbeautybliss.com/product/serum-4021/');

    // Brands: NO ARCHIVE. The row exists, its permalink is empty, and its note
    // says why -- which is a measurement, and is what RedirectMap asked for.
    expect($by['brand'][0]['permalink'])->toBe('');
    expect($by['brand'][0]['note'])->toContain('no public archive on this site');

    // and the settings that produced all of it are in the manifest, so the next
    // reader does not have to take this file's word for it.
    expect(geManifest()['source']['woocommerce_permalinks']['category_base'])->toBe('');
});

it('hands permalinks.csv to the redirect map without it having to learn a new word', function () {
    geImport();

    /*
     * The other half of "compatible": permalinks.csv is read by
     * `kbb:import-redirects`, through RedirectMap::fromPermalinks(), which looks
     * for `type`, `wc_id` and `permalink` and resolves the type against its own
     * vocabulary. A file whose `type` column said `product_cat` where that
     * method expects `category` would be silently ignored, row by row, and the
     * command would report a smaller map rather than an error.
     */
    $rows = array_map('str_getcsv', file(geExportDir().'/permalinks.csv', FILE_IGNORE_NEW_LINES));
    $header = array_shift($rows);

    $permalinks = array_map(static fn (array $row): array => array_combine($header, $row), $rows);

    $proposals = (new App\Services\Import\RedirectMap)->propose($permalinks);

    $bySubject = [];

    foreach ($proposals as $proposal) {
        $bySubject[$proposal['subject']][] = $proposal;
    }

    // The products did not move: /product/{slug}/ on both sides. RedirectMap
    // puts those in `discard` with "the old address and the new one are
    // identical", which is the finding its own header asks for -- "we checked
    // 671 products and none of them moved is the answer, and silence is not".
    expect($bySubject)->toHaveKey('product 4021');

    $serum = $bySubject['product 4021'][0];

    expect($serum['decision'])->toBe(App\Services\Import\RedirectMap::DISCARD);
    expect($serum['reason'])->toContain('identical');

    // The flat category address, which is the one Google holds and which 404s
    // on this shop today.
    expect($bySubject)->toHaveKey('category 31');

    $toners = $bySubject['category 31'][0];

    expect($toners['source'])->toBe('/toners/');
    expect($toners['target'])->toBe('/product-category/toners/');

    // The brand rows carry an EMPTY permalink, because no brand archive was
    // ever served. fromPermalinks() skips a row with no URL, so they produce no
    // proposal at all -- which is right: there is no address to redirect, and
    // that is exactly what RedirectMap refused to guess at.
    expect($bySubject)->not->toHaveKey('brand 502');
});

it('lists the sizes the catalogue references and not every thumbnail WordPress made', function () {
    $rows = array_map('str_getcsv', file(geExportDir().'/media.csv', FILE_IGNORE_NEW_LINES));
    $header = array_shift($rows);

    $sizes = [];
    $urls = [];

    foreach ($rows as $row) {
        $row = array_combine($header, $row);
        $sizes[] = $row['size'];
        $urls[] = $row['url'];
    }

    // The attachment metadata registers thumbnail, medium and large for the
    // serum. Only `medium` is referenced -- from inside the description -- and
    // only `medium` is in the file. `thumbnail` and `large` were generated by
    // WordPress and nothing ever asked for them.
    expect(in_array('medium', $sizes, true))->toBeTrue();

    $unreferenced = array_filter($urls, static fn ($u) => str_contains($u, '150x150') || str_contains($u, '1024x1024'));

    expect($unreferenced)->toBe([], 'media.csv carries a size nothing references');

    // and the file says which of them are actually on disk, which is what
    // App\Services\Import\MediaAudit would otherwise only discover afterwards.
    $exists = [];

    foreach ($rows as $row) {
        $row = array_combine($header, $row);
        $exists[$row['exists']] = true;
    }

    expect($exists)->toHaveKeys(['yes', 'no']);
});

it('is idempotent: a second pass changes nothing', function () {
    geImport();

    $before = [
        'products' => Product::query()->orderBy('id')->get(['wc_id', 'slug', 'price', 'sale_price', 'status'])->toArray(),
        'orders' => Order::query()->orderBy('id')->get(['wc_order_id', 'total', 'status', 'created_at'])->toArray(),
    ];

    $second = geImport(['runKey' => 'second', 'restart' => true]);

    $after = [
        'products' => Product::query()->orderBy('id')->get(['wc_id', 'slug', 'price', 'sale_price', 'status'])->toArray(),
        'orders' => Order::query()->orderBy('id')->get(['wc_order_id', 'total', 'status', 'created_at'])->toArray(),
    ];

    expect($after)->toBe($before);

    /*
     * The one row declined by design is declined on every pass, which is
     * itself the idempotent answer -- see geDeclinedByDesign(). Anything else
     * refused on a second pass is a row the first pass wrote and the second
     * could not.
     */
    expect(geUnexpectedRejections($second))->toBe([]);

    // Not merely "the rows are the same": the importer's own dirty check has to
    // agree that nothing moved, which is the only evidence a second pass did
    // not churn.
    expect($second->for('products')->updated)->toBe(0);
    expect($second->for('orders')->updated)->toBe(0);
});

it('cannot be shipped in a Core Updates package', function () {
    /*
     * wordpress-plugin/ is WordPress code and the shop's updater must never
     * write it. App\Services\Update\UpdateGuard works by ALLOW-list, so the
     * refusal falls out of `wordpress-plugin/` not being one of its prefixes --
     * but "the guard would have caught it" is precisely the sentence that
     * preceded packages 2.60.102-.106, so it is measured.
     */
    $guard = new UpdateGuard;

    $paths = [];

    foreach (geePluginFiles() as $file) {
        $paths[] = ltrim(str_replace(base_path(), '', $file), '/');
    }

    $paths[] = 'wordpress-plugin/harness/shop.php';
    $paths[] = 'wordpress-plugin/harness/run-export.php';
    // Lane GK's own additions, named rather than left to the glob: the screen
    // renderer and the browser driver are the two files somebody looking for
    // "test tooling" might think belong in tests/ and move.
    $paths[] = 'wordpress-plugin/harness/groups.php';
    $paths[] = 'wordpress-plugin/harness/screen.php';
    $paths[] = 'wordpress-plugin/harness/screen-drive.mjs';
    // Lane GL's: the volume measurement that decides whether a group needs
    // splitting. It reads the fixture and writes a temp folder, which is
    // exactly the description of a thing somebody moves into tests/.
    $paths[] = 'wordpress-plugin/harness/volume.php';

    expect(count($paths))->toBeGreaterThan(10);

    foreach ($paths as $path) {
        expect($guard->checkPath($path))->toBe("Path outside the permitted areas: {$path}");
    }

    $result = $guard->check($paths);

    expect($result['ok'])->toBeFalse();

    /*
     * AND THE SECOND LOCK, which is a different claim: UpdateGuard refusing a
     * zip that should never have been built is a worse outcome than the zip not
     * containing it. `kbb:package` keeps wordpress-plugin/ out in the first
     * place, so this asserts the prefix is still in NEVER_SHIP rather than
     * trusting the comment beside it.
     */
    $neverShip = new ReflectionClassConstant(App\Console\Commands\BuildPackage::class, 'NEVER_SHIP');

    expect($neverShip->getValue())->toContain('wordpress-plugin/');
});

it('has a plugin header WordPress will accept', function () {
    $main = (string) file_get_contents(base_path('wordpress-plugin/kbb-exporter/kbb-exporter.php'));

    foreach (['Plugin Name:', 'Version:', 'Requires PHP:', 'License:'] as $field) {
        expect(str_contains($main, $field))->toBeTrue("the plugin header has no {$field} line");
    }

    // No Composer anywhere: it installs by upload on shared hosting, and a
    // vendor/ directory it cannot build is a plugin that cannot be installed.
    expect(file_exists(base_path('wordpress-plugin/kbb-exporter/composer.json')))->toBeFalse();
    expect(file_exists(base_path('wordpress-plugin/kbb-exporter/vendor')))->toBeFalse();

    /*
     * EVERY class file refuses to run when fetched directly over HTTP.
     *
     * A plugin directory is inside the web root on shared hosting, and a class
     * file that executes on its own is a file somebody can reach by URL. The
     * `defined( 'ABSPATH' ) || exit;` line is WordPress's own convention for
     * this and it is worth asserting rather than trusting: it is one line, in
     * thirteen files, and the one that is missing is the one that matters.
     */
    $unguarded = [];

    foreach (geePluginFiles() as $file) {
        if ($file === base_path('wordpress-plugin/kbb-exporter/kbb-exporter.php')) {
            continue;
        }

        if (! str_contains((string) file_get_contents($file), "defined( 'ABSPATH' ) || exit;")) {
            $unguarded[] = basename($file);
        }
    }

    expect($unguarded)->toBe([], 'these files run when fetched directly: '.implode(', ', $unguarded));
});

it('stays inside the PHP version its header claims', function () {
    /*
     * The plugin header says `Requires PHP: 7.4`, and that is a promise to a
     * shared host. This sandbox only has PHP 8.4, so `php -l` cannot check it —
     * and `php -l` would not catch these anyway, because calling a function
     * that does not exist is a RUNTIME error. It would fatal on the owner's
     * server, on the row that called it, halfway through an export.
     *
     * So the check is the grep: the six functions PHP 8.0 added that a plugin
     * author reaches for without thinking, plus the syntax that is 8.0-only.
     */
    $banned = [
        'str_contains(' => 'PHP 8.0',
        'str_starts_with(' => 'PHP 8.0',
        'str_ends_with(' => 'PHP 8.0',
        'array_is_list(' => 'PHP 8.1',
        'enum ' => 'PHP 8.1',
        '?->' => 'PHP 8.0 nullsafe operator',
        'match (' => 'PHP 8.0 match',
    ];

    $found = [];

    foreach (geePluginFiles() as $file) {
        // Comments quote other files' prose, which can contain anything.
        $source = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file)) ?? '';

        foreach ($banned as $needle => $since) {
            if (str_contains($source, $needle)) {
                $found[] = basename($file).' uses '.$needle.' ('.$since.')';
            }
        }
    }

    expect($found)->toBe([], implode('; ', $found));
});

it('writes the manifest last, and describes every file it wrote', function () {
    $manifest = geManifest();

    expect($manifest['format'])->toBe('kbb-export/1');
    expect($manifest['export_id'])->toMatch('/^[0-9a-f-]{36}$/');

    // "A file with no rows is still listed" -- so the count of described files
    // is the count of files, not the count of files that happened to have rows.
    $written = array_map('basename', glob(geExportDir().'/*.csv') ?: []);

    sort($written);

    $described = array_keys($manifest['files']);

    sort($described);

    expect($described)->toBe($written);

    // sha256 is of the bytes as written, so it has to still agree.
    foreach ($manifest['files'] as $file => $facts) {
        $path = geExportDir().'/'.$file;

        expect(hash_file('sha256', $path))->toBe($facts['sha256'], "{$file} does not match its manifest checksum");
        expect(filesize($path))->toBe($facts['bytes']);

        // "rows EXCLUDES the header. A file with a header and no data rows is
        // 0, not 1."
        expect(count(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) - 1)->toBe($facts['rows']);
    }

    // The timezone, which DateParser refuses to default and which nothing else
    // in the export could tell the shop.
    expect($manifest['source']['timezone'])->toBe('Asia/Dubai');
    expect($manifest['source']['order_storage'])->toBeIn(['posts', 'hpos']);

    /*
     * AND THE CENSUS OF WHAT ELSE THIS SITE HAS.
     *
     * permalinks.csv says what each thing's address is; this says what things
     * there ARE, including the ones no file carries — which is the list
     * somebody will want the first time a page turns out to be missing. The
     * trashed product and the withdrawn coupon are both counted here even
     * though neither is in a CSV, which is the point: the export states what it
     * left behind rather than leaving a hole.
     */
    expect($manifest['source']['taxonomies'])->toHaveKeys(['product_cat', 'pa_brands', 'pa_size', 'product_tag']);
    expect($manifest['source']['post_types']['product'])->toBe(['publish' => 4, 'trash' => 1]);
    expect($manifest['source']['post_types']['shop_coupon'])->toBe(['draft' => 1, 'publish' => 1]);

    // products.csv carries the four published ones and not the trashed one, and
    // the census is how you can tell that from the outside.
    expect($manifest['files']['products.csv']['rows'])->toBe($manifest['source']['post_types']['product']['publish']);
});

it('never shows a finished bar on an unfinished export', function () {
    /*
     * FOUND BY CHECKING A CLAIM THIS DOCUMENT MADE, not by a test failing.
     *
     * The media stage's total() counts the objects that can reference a picture
     * -- one per product -- and the stage writes one row per (url, referrer,
     * field). On the fixture, one product writes FOUR rows; on a real shop it is
     * a featured image plus a gallery plus whatever the description embeds. The
     * denominator under-estimates by about five to one.
     *
     * The runner used to clamp with min(100, ...), which turns that into a bar
     * that reads 100% while the export carries on for another minute. That is
     * the fake 100% docs/GD-MEDIA-SIDELOADER.md already found and removed once,
     * reintroduced through the denominator instead of through the bar.
     *
     * The harness records the highest percentage the screen would have shown
     * while `done` was still false. Anything at 100 there is the bar lying.
     */
    $script = base_path('wordpress-plugin/harness/run-export.php');
    $out = sys_get_temp_dir().'/kbb-ge-peak-'.bin2hex(random_bytes(4));

    exec(
        escapeshellcmd(PHP_BINARY).' '.escapeshellarg($script)
            .' --storage=posts --out='.escapeshellarg($out).' --db='.geWpDb().' --batch=3 2>&1',
        $lines,
        $status
    );

    if (3 === $status) {
        $this->markTestSkipped('no MySQL here: '.implode(' ', $lines));
    }

    expect($status)->toBe(0, implode("\n", $lines));

    $result = json_decode(implode("\n", $lines), true);

    expect($result['peak_while_running'])->toBeLessThan(
        100,
        'the progress bar reached 100% before the export had finished'
    );

    // and the under-estimate this is protecting against is real, measured here
    // so the guard cannot quietly become untested by the stage getting better.
    $manifest = json_decode((string) file_get_contents($out.'/export/manifest.json'), true);
    $objects = array_sum($manifest['source']['post_types']['product'])
        + $manifest['source']['taxonomies']['product_cat']
        + $manifest['source']['taxonomies']['pa_brands']
        + array_sum($manifest['source']['post_types']['post'])
        + array_sum($manifest['source']['post_types']['page']);

    expect($manifest['files']['media.csv']['rows'])->toBeGreaterThan(
        (int) ($objects / 2),
        'the media stage no longer writes several rows per object, so this guard proves nothing'
    );

    /*
     * AND THE ARITHMETIC ITSELF, because end to end on this fixture cannot see
     * it. `percent` is computed over the SUM of every stage's rows and totals,
     * and media's handful against a dozen does not move a sum that includes
     * fifty other rows. On a real shop with four gallery images per product it
     * does — which is the shape a fixture cannot have without being contorted
     * into one.
     *
     * `--probe=progress` puts the shipped state into the crossed condition
     * (written past total, done still false) and calls the shipped progress().
     * Nothing is stubbed: it is the method the admin screen calls.
     */
    $probe = [];

    exec(
        escapeshellcmd(PHP_BINARY).' '.escapeshellarg($script)
            .' --storage=posts --out='.escapeshellarg($out).' --db='.geWpDb().' --batch=500 --probe=progress 2>&1',
        $probe,
        $probeStatus
    );

    expect($probeStatus)->toBe(0, implode("\n", $probe));

    $progress = json_decode(implode("\n", $probe), true);

    expect($progress['done'])->toBeFalse();
    expect($progress['percent'])->toBe(99, 'a stage past its own estimate still showed a finished bar');
    // The denominator moved with the numerator, so the bar cannot run past its
    // own end however wrong the estimate was.
    expect($progress['rows_total'])->toBe($progress['rows_done']);
});

it('pins its settings to the export, not to the request that asked for a batch', function () {
    /*
     * The admin screen posts the whole form with EVERY batch, because that is
     * how a browser-driven, resumable job works. So an operator who ticks
     * "include trashed products" halfway through changes what the REMAINING
     * batches select -- and products.csv would then hold the first half under
     * one rule and the rest under another, with nothing in the file to say
     * where the line is and a manifest row count that matches neither.
     *
     * The runner pins the settings at start() and takes only `batch` from the
     * request, because how many rows fit in a request is a property of the host
     * and not of the data. `--flip_after=1` is the harness doing exactly what
     * the operator's tick does.
     */
    $script = base_path('wordpress-plugin/harness/run-export.php');
    $out = sys_get_temp_dir().'/kbb-ge-flip-'.bin2hex(random_bytes(4));

    exec(
        escapeshellcmd(PHP_BINARY).' '.escapeshellarg($script)
            .' --storage=posts --out='.escapeshellarg($out).' --db='.geWpDb().' --batch=2 --flip_after=1 2>&1',
        $lines,
        $status
    );

    if (3 === $status) {
        $this->markTestSkipped('no MySQL here: '.implode(' ', $lines));
    }

    expect($status)->toBe(0, implode("\n", $lines));

    foreach (['products.csv', 'coupons.csv'] as $file) {
        expect(hash_file('sha256', $out.'/export/'.$file))->toBe(
            hash_file('sha256', geExportDir().'/'.$file),
            "{$file} changed because the settings were flipped mid-export"
        );
    }

    // and the manifest still describes what is actually in the files.
    $manifest = json_decode((string) file_get_contents($out.'/export/manifest.json'), true);

    expect($manifest['counts']['products'])
        ->toBe(count(file($out.'/export/products.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) - 1);
});

/*
 * ── THE FIXTURE IS THE PLUGIN'S OUTPUT, AND STAYS THAT WAY ──────────────────
 *
 * Needs MySQL and the harness database geWpDb() names (`kbb_ge_wp` unless
 * KBB_WP_DB says otherwise), so it skips where those are absent.
 * Everything above runs everywhere.
 */
it('regenerates the fixture from the plugin and gets the same bytes, from either order storage', function () {
    $script = base_path('wordpress-plugin/harness/run-export.php');
    $out = sys_get_temp_dir().'/kbb-ge-regen-'.bin2hex(random_bytes(4));

    $results = [];

    foreach (['posts', 'hpos'] as $storage) {
        $command = escapeshellcmd(PHP_BINARY).' '.escapeshellarg($script)
            .' --storage='.$storage
            .' --out='.escapeshellarg($out.'/'.$storage)
            .' --db='.geWpDb().' --batch=7 2>&1';

        exec($command, $lines, $status);

        if (3 === $status) {
            $this->markTestSkipped('no MySQL here: '.implode(' ', $lines));
        }

        expect($status)->toBe(0, 'the harness export failed: '.implode("\n", $lines));

        $results[$storage] = $out.'/'.$storage.'/export';
        $lines = [];
    }

    foreach (glob(geExportDir().'/*.csv') as $expected) {
        $name = basename($expected);

        foreach ($results as $storage => $dir) {
            expect(file_exists($dir.'/'.$name))->toBeTrue("{$storage} produced no {$name}");
            expect(hash_file('sha256', $dir.'/'.$name))->toBe(
                hash_file('sha256', $expected),
                "{$name} from the {$storage} storage no longer matches tests/Fixtures/kbb-export. "
                .'Re-run wordpress-plugin/harness/run-export.php and commit the new fixture if the change is intended.'
            );
        }
    }

    /*
     * AND THE MANIFEST THE PLUGIN JUST WROTE, CHECKED AGAINST THE FILES IT JUST
     * WROTE.
     *
     * Found by mutation, not by design: changing the runner to count the header
     * row as data left the whole suite GREEN. Every manifest assertion above
     * reads tests/Fixtures/kbb-export/manifest.json, which is a snapshot the
     * mutation did not touch, and the regeneration compared only the CSVs --
     * so the one arithmetic that the progress bar's honesty rests on was the
     * one thing nothing re-derived. `rows` is the denominator the contract
     * exists to provide; a manifest that is one out on every file is a bar
     * that never reaches the end.
     */
    foreach ($results as $storage => $dir) {
        $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);

        expect($manifest['format'])->toBe('kbb-export/1');
        expect($manifest['source']['order_storage'])->toBe($storage);

        foreach ($manifest['files'] as $file => $facts) {
            $path = $dir.'/'.$file;

            expect(count(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) - 1)
                ->toBe($facts['rows'], "{$storage}/{$file}: the manifest's row count is not the file's row count");

            expect(hash_file('sha256', $path))->toBe($facts['sha256']);
            expect(filesize($path))->toBe($facts['bytes']);
        }

        // and `counts`, which is what the shop reports as "products imported",
        // has to agree with `files` rather than being computed a second way.
        expect($manifest['counts']['products'])->toBe($manifest['files']['products.csv']['rows']);
        expect($manifest['counts']['orders'])->toBe($manifest['files']['orders.csv']['rows']);

        /*
         * AND THE WHOLE MANIFEST, against the committed one.
         *
         * Also found by mutation: dropping `source.post_types` from the runner
         * left the suite green, because the only test that reads that key reads
         * the checked-in snapshot. Asserting the keys one at a time would fix
         * the one key and leave the next one added just as unprotected, so the
         * whole document is compared instead, minus the three fields that
         * legitimately differ per run:
         *
         *   export_id                — random, and identifying ONE export is its job
         *   generated_at             — the clock
         *   source.order_storage     — the thing this loop is varying
         *   source.post_types        — an HPOS shop has NO shop_order rows in
         *                              wp_posts, and saying so is the census
         *                              doing its job
         *   notes[0]                 — the sentence naming the storage
         *
         * The last two are asserted separately below rather than merely
         * excluded, because "these two legitimately differ" and "these two are
         * not checked" are different claims.
         */
        $expected = geManifest();

        // Unset on each array directly. `foreach ([$a, $b] as &$x)` iterates a
        // temporary array of COPIES, so unsetting through the reference changes
        // nothing -- which this test did on its first run, and reported the two
        // random export ids as the difference.
        foreach (['export_id', 'generated_at'] as $volatile) {
            unset($manifest[$volatile], $expected[$volatile]);
        }

        foreach (['order_storage', 'post_types'] as $perStorage) {
            unset($manifest['source'][$perStorage], $expected['source'][$perStorage]);
        }

        $storageNote = array_shift($manifest['notes']);

        array_shift($expected['notes']);

        expect($manifest)->toEqual(
            $expected,
            "the manifest written from the {$storage} storage no longer matches tests/Fixtures/kbb-export/manifest.json"
        );

        // The two that do differ, said out loud.
        expect($storageNote)->toContain('posts' === $storage ? 'legacy (wp_posts)' : 'HPOS (wp_wc_orders)');

        $census = json_decode((string) file_get_contents($dir.'/manifest.json'), true)['source']['post_types'];

        expect($census['product'])->toBe(['publish' => 4, 'trash' => 1]);

        // On HPOS the orders are not wp_posts rows at all, and the census says
        // so rather than pretending the shop has none.
        expect(isset($census['shop_order']))->toBe('posts' === $storage);
    }

    // --batch=7 above, against the fixture's --batch=500: 200-odd separate
    // runner instances, each reloading its checkpoint from the options table
    // exactly as a separate HTTP request would, producing the same bytes. That
    // is the resume guarantee, measured rather than designed.
});

/*
 * ════════════════════════════════════════════════════════════════════════════
 * LANE GK — THE GROUPS, AND THE DEPENDENCIES BETWEEN THEM
 * ════════════════════════════════════════════════════════════════════════════
 *
 * The owner asked for the export to be sectioned the way the import already is,
 * with a bar per section. Sectioning is a list; the dependencies are the job,
 * because every file this plugin writes has an importer on the other side that
 * resolves its foreign keys by external id — so a file that arrives before what
 * it points at does not fail, it resolves to nothing.
 *
 * docs/GK-EXPORT-GROUPS.md is the account. The four questions these tests ask
 * are the four the screen has to get right:
 *
 *   1. Does every file the plugin writes belong to exactly one group?
 *   2. Does a group exported ALONE land correctly on the other side?
 *   3. Does a dependency-violating selection say so, before the button works?
 *   4. Does manifest.json record what was exported, in the way the contract's
 *      absent-vs-zero distinction already makes readable?
 *
 * The first and third need no database and therefore run in CI, which is where
 * the dependency guard most needs to run. See wordpress-plugin/harness/groups.php
 * on why that is a second script rather than a flag on the first.
 */

/** The group declaration, read out of the plugin in its own process. */
function gkGroups(string $flags = ''): array
{
    $lines = [];

    exec(
        escapeshellcmd(PHP_BINARY).' '.escapeshellarg(base_path('wordpress-plugin/harness/groups.php'))
            .($flags === '' ? '' : ' '.$flags).' 2>&1',
        $lines,
        $status
    );

    expect($status)->toBe(0, implode("\n", $lines));

    return json_decode(implode("\n", $lines), true);
}

/**
 * One export of one selection, into its own folder. Returns the folder.
 *
 * `--confirm` is the operator ticking "this is already in the new shop" against
 * a dependency, which is the only way a partial selection starts at all.
 */
function gkExport(string $groups, string $confirm = '', string $extra = ''): string
{
    $script = base_path('wordpress-plugin/harness/groups.php');
    $out = sys_get_temp_dir().'/kbb-gk-'.preg_replace('/[^a-z]+/', '-', $groups).'-'.bin2hex(random_bytes(4));

    $lines = [];

    exec(
        escapeshellcmd(PHP_BINARY).' '.escapeshellarg(base_path('wordpress-plugin/harness/run-export.php'))
            .' --storage=posts --out='.escapeshellarg($out).' --db='.geWpDb().' --batch=500'
            .' --groups='.escapeshellarg($groups)
            .($confirm === '' ? '' : ' --confirm='.escapeshellarg($confirm))
            .($extra === '' ? '' : ' '.$extra)
            .' 2>&1',
        $lines,
        $status
    );

    if (3 === $status) {
        test()->markTestSkipped('no MySQL here: '.implode(' ', $lines));
    }

    expect($status)->toBe(0, 'exporting '.$groups.' failed: '.implode("\n", $lines));

    return $out.'/export';
}

it('puts every file the plugin writes in exactly one group', function () {
    /*
     * THE TWO LISTS THAT HAVE TO AGREE, pinned against each other.
     *
     * KBB_Export_Runner::stages() is FILTERED through the group declaration, so
     * a stage whose file no group claims is a file the plugin can no longer
     * write at all — and it would go missing quietly, because a group that does
     * not know about a file cannot notice it is absent.
     *
     * App\Services\ImportConsole\ImportWorkspace carries the same warning about
     * the same hazard between its own list and ImportRunner::entities(), and it
     * carries it because those two did drift: the SEO entity was registered on
     * one and not the other and every upload 500'd. The next stage will be added
     * by somebody who has not read this comment, which is what this is for.
     *
     * The authority on the other side is the FIXTURE — a real export of every
     * group, written by the plugin's own stages. Not a hand-typed list, which
     * would be a third list to keep in step.
     */
    $declared = gkGroups()['every_file'];

    $written = array_map('basename', glob(geExportDir().'/*.csv'));

    sort($written);

    $sorted = $declared;
    sort($sorted);

    // array_diff both ways, NOT expect()->not->toContain(): `toContain` is
    // variadic, so `->not->toContain($needle, $message)` reads the message as a
    // second needle and passes vacuously.
    $unclaimed = array_values(array_diff($written, $declared));
    $phantom = array_values(array_diff($declared, $written));

    expect($unclaimed)->toBe([], 'the plugin writes files no group claims, so they can never be exported: '
        .implode(', ', $unclaimed));

    expect($phantom)->toBe([], 'a group claims files the plugin does not write: '.implode(', ', $phantom));

    // And exactly once each: a file in two groups would be opened twice, its
    // rows counted twice, and un-ticking one group would not stop it.
    expect(count($declared))->toBe(count(array_unique($declared)));
    expect($sorted)->toBe($written);
});

it('names exactly one dependency whose damage the import report does not describe', function () {
    /*
     * THE SEVERITY IS NOT DECORATION. It is the whole basis of the screen's
     * design: a red warning that appears eight times is a warning nobody reads,
     * so `loses` means one thing only — damage the import report OF THE RUN
     * THAT CAUSES IT does not describe.
     *
     * There is one of those and it is Orders without Customers
     * (docs/FV-IMPORT-AT-VOLUME.md section 6: 14 of 80 customers lost). Every
     * other crossed edge is refused or noted by name as it happens.
     *
     * If a second one is ever added this goes red, and whoever adds it has to
     * say in docs/GK-EXPORT-GROUPS.md why the import report cannot name it.
     */
    $groups = gkGroups()['groups'];

    $loses = [];
    $edges = 0;

    foreach ($groups as $key => $group) {
        foreach ($group['needs'] as $needed => $edge) {
            $edges++;

            expect(isset($groups[$needed]))
                ->toBeTrue("{$key} depends on a group that does not exist: {$needed}");

            expect(trim($edge['consequence']))->not->toBe('',
                "{$key} needs {$needed} with no consequence written, so the screen would print a blank warning");

            if ($edge['severity'] === 'loses') {
                $loses[] = $key.':'.$needed;
            }
        }
    }

    expect($edges)->toBeGreaterThan(4, 'the dependency graph has become too small to be describing the real one');
    expect($loses)->toBe(['sales:customers']);

    // and that one names the measurement, so the sentence the owner reads is
    // the one this repository actually paid for.
    expect(str_contains($groups['sales']['needs']['customers']['consequence'], '14 of 80'))->toBeTrue();
});

it('will not start a selection whose dependencies are neither met nor confirmed', function () {
    /*
     * NOT A REFUSAL AND NOT AN AUTO-TICK — see docs/GK-EXPORT-GROUPS.md.
     *
     * Exporting Orders alone is often exactly right: the catalogue and the
     * customers may already be in the new shop from a previous export, and
     * re-exporting 671 products to get this week's orders is the waste the
     * screen exists to remove. So the answer is not "no". It is "not until you
     * have read what this does", and the confirmation is per dependency rather
     * than one blanket tick, so confirming that the customers are already there
     * does not also wave through the catalogue.
     */
    $alone = gkGroups('--selection=sales');

    expect(array_column($alone['unmet'], 'id'))->toBe(['sales:customers', 'sales:catalogue']);
    expect(array_column($alone['outstanding'], 'id'))->toBe(['sales:customers', 'sales:catalogue']);

    // The sentence names the GROUPS, because the operator ticked groups and has
    // never seen a dependency id.
    expect($alone['refusal'])->toContain('Orders needs Customers');
    expect($alone['refusal'])->toContain('Orders needs Catalogue');

    // One confirmation clears one edge and not the other.
    $half = gkGroups('--selection=sales --confirm=sales:customers');

    expect(array_column($half['unmet'], 'id'))->toBe(['sales:customers', 'sales:catalogue']);
    expect(array_column($half['outstanding'], 'id'))->toBe(['sales:catalogue']);

    $both = gkGroups('--selection=sales --confirm=sales:customers,sales:catalogue');

    expect($both['outstanding'])->toBe([]);
    expect($both['refusal'])->toBe('');

    // Adding the missing group clears it too, and leaves nothing to confirm.
    $added = gkGroups('--selection=sales,customers,catalogue');

    expect($added['outstanding'])->toBe([]);
    expect($added['unmet'])->toBe([]);

    // A selection is a SUBSET of the export's own order, never a resequencing
    // of it: the catalogue is written first so an export that dies at 60% has
    // the products and not just the tags.
    expect(gkGroups('--selection=sales,catalogue,customers')['selection'])
        ->toBe(['catalogue', 'customers', 'sales']);

    /*
     * AND THE SERVER REFUSES IT, not only the screen. The admin page disables
     * the button, which is a statement about one browser with JavaScript in it;
     * this is the statement about the export. The harness posts the selection
     * with no confirmation at all, exactly as a crafted request would.
     */
    $lines = [];

    exec(
        escapeshellcmd(PHP_BINARY).' '.escapeshellarg(base_path('wordpress-plugin/harness/run-export.php'))
            .' --storage=posts --out='.escapeshellarg(sys_get_temp_dir().'/kbb-gk-refused-'.bin2hex(random_bytes(4)))
            .' --db='.geWpDb().' --batch=500 --groups=sales 2>&1',
        $lines,
        $status
    );

    if (3 === $status) {
        $this->markTestSkipped('no MySQL here: '.implode(' ', $lines));
    }

    expect($status)->toBe(4, 'the runner started an export whose dependencies were never answered');
    expect(implode("\n", $lines))->toContain('Orders needs Customers');
});

it('exports one group at a time, and the pieces land what the whole export lands', function () {
    /*
     * THE ROUND TRIP, GROUP BY GROUP. Eight separate exports, each of one group,
     * imported one after another into ONE database in the order the owner would
     * press them — and the shop ends up holding exactly what the all-at-once
     * export puts there.
     *
     * This is the claim the screen makes and it is not provable from the
     * exporter alone: a group that writes the right CSV and lands nothing is
     * the failure this is for.
     */
    $order = ['catalogue', 'seo', 'coupons', 'customers', 'sales', 'reviews', 'content', 'addresses'];

    $confirmations = [
        'seo' => 'seo:catalogue',
        'coupons' => 'coupons:catalogue',
        'sales' => 'sales:customers,sales:catalogue',
        'reviews' => 'reviews:catalogue,reviews:customers',
        'addresses' => 'addresses:catalogue,addresses:content',
    ];

    $directories = [];

    foreach ($order as $group) {
        $directories[$group] = gkExport($group, $confirmations[$group] ?? '');
    }

    /*
     * EACH GROUP'S FILES ARE BYTE-IDENTICAL TO THE WHOLE EXPORT'S.
     *
     * Ticking a box changes WHICH files are written and nothing about WHAT is
     * in them. If a selection could change a file's contents, every claim the
     * fixture makes about columns, money and the coupon's last second would
     * hold for the full export and for nothing else.
     */
    foreach ($order as $group) {
        foreach (glob($directories[$group].'/*.csv') as $file) {
            expect(hash_file('sha256', $file))->toBe(
                hash_file('sha256', geExportDir().'/'.basename($file)),
                basename($file).' came out differently when only "'.$group.'" was ticked'
            );
        }
    }

    // Import them one folder at a time, in that order, into one shop.
    $manifest = geManifest();

    foreach ($order as $group) {
        geImport(['directory' => $directories[$group]]);
    }

    /*
     * AND THE SHOP HOLDS WHAT THE WHOLE EXPORT PUTS THERE, counted against the
     * full manifest rather than against itself.
     */
    expect(Category::query()->whereNotNull('source_term_id')->count())->toBe($manifest['counts']['categories']);
    expect(Brand::query()->whereNotNull('source_term_id')->count())->toBe($manifest['counts']['brands']);
    expect(Product::query()->withTrashed()->whereNotNull('wc_id')->count())->toBe($manifest['counts']['products']);
    expect(Coupon::query()->whereNotNull('wc_id')->count())->toBe($manifest['counts']['coupons']);
    expect(Customer::query()->withTrashed()->whereNotNull('wp_user_id')->count())->toBe($manifest['counts']['customers']);
    expect(Order::query()->withTrashed()->whereNotNull('wc_order_id')->count())->toBe($manifest['counts']['orders']);
    expect(OrderItem::query()->whereNotNull('wc_item_id')->count())->toBe($manifest['counts']['order_items']);
    expect(Refund::query()->count())->toBe($manifest['counts']['refunds']);
    expect(OrderNote::query()->whereNotNull('source_comment_id')->count())->toBe($manifest['counts']['order_notes']);
    expect(Review::query()->where('source', 'wp_comment')->count())->toBe($manifest['counts']['reviews']);
    expect(App\Models\Post::query()->whereNotNull('source_post_id')->count())->toBe(1);

    /*
     * AND THE FOREIGN KEYS RESOLVED — which is the only thing separating "the
     * rows are there" from "the shop works". An order line whose product_id is
     * null is exactly what an out-of-order import produces, and it is the
     * failure this whole lane is about.
     */
    expect(OrderItem::query()->whereNotNull('wc_item_id')->whereNull('product_id')->count())
        ->toBe(0, 'an order line lost its product, which is what importing sales before the catalogue does');

    expect(Order::query()->whereNotNull('wc_order_id')->whereNull('customer_id')->count())
        ->toBe(0, 'an order lost its customer');

    // Every customer in customers.csv is a REAL WordPress user. A guest row
    // synthesised from an order's billing email is the 14-of-80 failure.
    expect(Customer::query()->withTrashed()->whereNull('wp_user_id')->count())
        ->toBe(1, 'the guest order should synthesise exactly one customer, and no more');

    // And the SEO landed on the product it belongs to, which needs the
    // catalogue import from a DIFFERENT folder to have been seen.
    expect(Product::query()->where('wc_id', 4021)->value('seo'))->not->toBeNull();
});

it('records which groups it exported, and a group left out is absent rather than empty', function () {
    /*
     * THE CONTRACT'S OWN DISTINCTION, MADE LOAD-BEARING.
     *
     * docs/WP-EXPORT-CONTRACT.md: "A file with no rows is still listed, with
     * rows: 0. Absent from `files` means the plugin did not write it at all,
     * which is a different statement and the importer must be able to tell the
     * two apart — 'this shop has no coupons' and 'this export does not carry
     * coupons' are not the same fact."
     *
     * Until groups existed nothing produced the second case: every stage ran,
     * so every file was listed, and the distinction was a rule with no example.
     * A ticked-groups export is the example. The reader is this shop's own
     * App\Services\ImportConsole\ImportManifest::lists(), unmodified.
     */
    $directory = gkExport('catalogue');

    $manifest = json_decode((string) file_get_contents($directory.'/manifest.json'), true);

    expect(array_keys($manifest['files']))->toBe([
        'categories.csv', 'brands.csv', 'tags.csv', 'attributes.csv', 'products.csv', 'variations.csv',
    ]);

    // Not written, not listed, not on disk — all three, because any one of them
    // alone would let the other two drift.
    foreach (['orders.csv', 'customers.csv', 'coupons.csv', 'reviews.csv', 'posts.csv', 'media.csv'] as $absent) {
        expect(isset($manifest['files'][$absent]))->toBeFalse($absent.' is listed in a manifest that did not write it');
        expect(file_exists($directory.'/'.$absent))->toBeFalse($absent.' was written by a catalogue-only export');
        expect(isset($manifest['counts'][str_replace('.csv', '', $absent)]))->toBeFalse();
    }

    $read = App\Services\ImportConsole\ImportManifest::read($directory.'/manifest.json');

    expect($read->usable())->toBeTrue($read->refusal() ?? '');
    expect($read->lists('products.csv'))->toBeTrue();
    expect($read->lists('coupons.csv'))->toBeFalse('the shop cannot tell "no coupons here" from "no coupons at all"');

    // And the same fact in the owner's vocabulary, which is what he ticked.
    expect($manifest['groups']['selected'])->toBe(['catalogue']);
    expect($manifest['groups']['skipped'])
        ->toBe(['seo', 'coupons', 'customers', 'sales', 'reviews', 'content', 'addresses']);
    expect($manifest['groups']['assumed_already_imported'])->toBe([]);

    // A skipped group is stated in words too, because `files` is structure and
    // the notes are what the owner reads on the import screen.
    $notes = implode(' | ', $manifest['notes']);

    expect($notes)->toContain('This is a PARTIAL export');
    expect($notes)->toContain('NOT in this export: Coupons');

    /*
     * AND A CONFIRMED DEPENDENCY TRAVELS WITH THE EXPORT.
     *
     * "The customers are already in the new shop" is a claim about the OTHER
     * shop. The plugin cannot check it and does not pretend to; what it can do
     * is write down that it was made, so the file says why it is missing a
     * group it points at instead of that living in somebody's memory.
     */
    $sales = gkExport('sales', 'sales:customers,sales:catalogue');

    $salesManifest = json_decode((string) file_get_contents($sales.'/manifest.json'), true);

    expect(array_column($salesManifest['groups']['assumed_already_imported'], 'needs'))
        ->toBe(['customers', 'catalogue']);

    expect($salesManifest['groups']['assumed_already_imported'][0]['severity'])->toBe('loses');

    expect(implode(' | ', $salesManifest['notes']))
        ->toContain('Orders was exported without Customers because the operator confirmed');
});

it('pins the group selection to the export, so un-ticking one mid-run changes nothing', function () {
    /*
     * The admin screen posts the whole form with EVERY batch, because that is
     * how a browser-driven resumable job works. `skip_trashed` is already pinned
     * for that reason and the pin is measured; `groups` is worse if it is not.
     *
     * `stage` is an INDEX INTO THE FILTERED STAGE LIST. Shortening that list
     * between two batches does not stop the export — it carries on at the same
     * index, which now points at a different file. The result is one file half
     * written, one never opened, and a manifest describing neither.
     *
     * --flip_groups_after=1 is the harness doing exactly what clicking a
     * checkbox mid-run does.
     */
    $flipped = gkExport('catalogue,customers,sales', '', '--flip_groups_after=1');

    foreach (['products.csv', 'customers.csv', 'orders.csv', 'order_items.csv', 'refunds.csv', 'order_notes.csv'] as $file) {
        expect(file_exists($flipped.'/'.$file))->toBeTrue($file.' was never written: the selection changed mid-export');

        expect(hash_file('sha256', $flipped.'/'.$file))->toBe(
            hash_file('sha256', geExportDir().'/'.$file),
            $file.' changed because a group was un-ticked mid-export'
        );
    }

    $manifest = json_decode((string) file_get_contents($flipped.'/manifest.json'), true);

    expect($manifest['groups']['selected'])->toBe(['catalogue', 'customers', 'sales']);

    // and the manifest still describes what is actually in the files.
    foreach ($manifest['files'] as $file => $facts) {
        expect(count(file($flipped.'/'.$file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) - 1)
            ->toBe($facts['rows'], $file.": the manifest's row count is not the file's row count");
    }
});

it('never shows a finished bar on a group that has not finished', function () {
    /*
     * The same guard the whole-export bar already has, one bar down.
     *
     * docs/GE-WP-EXPORTER.md section 8.1: the media stage's total() counts
     * referencing objects and the stage writes one row per (url, referrer,
     * field), so its denominator under-estimates by about five to one. On the
     * whole-export bar that is invisible — media's handful does not move a sum
     * of fifty — but a PER-GROUP bar divides by that stage's own estimate, so
     * the "Addresses and pictures" bar is precisely where the fake 100%
     * docs/GD-MEDIA-SIDELOADER.md removed once would come back.
     *
     * The harness records the highest percentage each group's bar showed while
     * that group was not yet done. Anything at 100 there is a bar lying.
     */
    $lines = [];

    exec(
        escapeshellcmd(PHP_BINARY).' '.escapeshellarg(base_path('wordpress-plugin/harness/run-export.php'))
            .' --storage=posts --out='.escapeshellarg(sys_get_temp_dir().'/kbb-gk-peaks-'.bin2hex(random_bytes(4)))
            .' --db='.geWpDb().' --batch=1 2>&1',
        $lines,
        $status
    );

    if (3 === $status) {
        $this->markTestSkipped('no MySQL here: '.implode(' ', $lines));
    }

    expect($status)->toBe(0, implode("\n", $lines));

    $result = json_decode(implode("\n", $lines), true);

    $lying = array_keys(array_filter($result['group_peaks'], static fn (int $p): bool => $p >= 100));

    expect($lying)->toBe([], 'these group bars reached 100% before their group had finished: '.implode(', ', $lying));

    // The guard has to be able to fire: at --batch=1 every group is seen part
    // way through, so these peaks are real observations and not an empty list.
    expect(count($result['group_peaks']))->toBeGreaterThan(4);
    expect(max($result['group_peaks']))->toBeGreaterThan(0);

    // And a finished export reports every ticked group at 100 and `done`.
    foreach ($result['group_progress'] as $group) {
        expect($group['state'])->toBe('done', $group['key'].' was not done when the export was');
        expect($group['percent'])->toBe(100);
    }
});

it('still carries the picture links inside the CSVs, so nothing new has to fetch them', function () {
    /*
     * The owner named this as already true and asked for it not to be rebuilt:
     * the media LINKS travel in the CSVs (`products.image`, `products.images`,
     * and the URLs inside descriptions) and App\Services\Import\MediaSideloader
     * — shipped in 2.60.220 — downloads the files itself in batches with its own
     * progress page. Nothing in this lane touches that, and this is the check
     * that grouping did not quietly break it.
     *
     * The hazard grouping introduces is specific: media.csv sits in "Addresses
     * and pictures", so a catalogue-only export does not write it. If the image
     * links lived in media.csv rather than in products.csv, that export would
     * silently carry a catalogue with no pictures.
     */
    $directory = gkExport('catalogue');

    expect(file_exists($directory.'/media.csv'))->toBeFalse();

    $rows = array_map('str_getcsv', file($directory.'/products.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $header = array_shift($rows);

    $image = array_search('image', $header, true);
    $images = array_search('images', $header, true);

    expect($image)->not->toBeFalse('products.csv no longer carries the featured image link');
    expect($images)->not->toBeFalse('products.csv no longer carries the gallery links');

    $withPictures = array_values(array_filter($rows, static fn (array $r): bool => trim((string) $r[$image]) !== ''));

    expect($withPictures)->not->toBe([], 'a catalogue-only export carries no picture links at all');
    expect($withPictures[0][$image])->toContain('/uploads/');

    // and the sideloader reads them from the product rows, not from media.csv:
    // importing the catalogue alone leaves products with image paths to fetch.
    geImport(['directory' => $directory]);

    expect(Product::query()->where('wc_id', 4021)->value('image'))->not->toBeNull();
});

it('draws the groups, the warning and the bars in a real browser, and sends what was ticked', function () {
    /*
     * ════════════════════════════════════════════════════════════════════════
     * THE MUTATION THAT SURVIVED, AND WHAT CLOSING IT TOOK
     * ════════════════════════════════════════════════════════════════════════
     *
     * Deleting `body.set('groups', ticked().join(','))` from the admin screen —
     * so that every export is a whole export whatever the owner ticked — left
     * the whole PHP suite GREEN. Everything this lane added to the screen is
     * JavaScript and no PHP test can see a line of it. The same hole hides the
     * rest of it: a dependency warning that never appears, a Start button that
     * is pressable while a warning is unanswered, a per-group bar that draws
     * every group at 100%.
     *
     * A grep for that one line would close that one mutation and nothing else.
     * So the screen is RENDERED by KBB_Export_Admin::screen() (harness/screen.php,
     * with the same WordPress stubs the export harness uses) and DRIVEN in
     * Chromium (harness/screen-drive.mjs). Nothing about the page is
     * reconstructed: the markup and the script are the plugin's own.
     *
     * `fetch` is intercepted rather than served, because there is no WordPress
     * here to answer admin-ajax.php — and because it is the only way to hold the
     * page mid-run. A real run of this fixture finishes in under a second and
     * there is no moving bar to photograph.
     *
     * The screenshots in docs/gk-export-shots/ are taken by the same run.
     */
    if (! is_dir(base_path('node_modules/playwright'))) {
        $this->markTestSkipped('playwright is not installed here');
    }

    $chrome = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

    if (! is_file($chrome)) {
        $this->markTestSkipped('no Chromium at '.$chrome);
    }

    $out = sys_get_temp_dir().'/kbb-gk-screen-'.bin2hex(random_bytes(4));

    mkdir($out, 0755, true);

    $render = [];

    exec(
        escapeshellcmd(PHP_BINARY).' '.escapeshellarg(base_path('wordpress-plugin/harness/screen.php'))
            .' --db='.geWpDb().' > '.escapeshellarg($out.'/screen.html').' 2>&1',
        $render,
        $renderStatus
    );

    if (3 === $renderStatus) {
        $this->markTestSkipped('no MySQL here: '.implode(' ', $render));
    }

    expect($renderStatus)->toBe(0, implode("\n", $render));

    $lines = [];

    // cwd is the repository root so node resolves `playwright` by walking up
    // from the script, which is how ESM resolution works — NODE_PATH does not
    // apply to it.
    exec(
        'cd '.escapeshellarg(base_path()).' && node '
            .escapeshellarg(base_path('wordpress-plugin/harness/screen-drive.mjs'))
            .' --page='.escapeshellarg($out.'/screen.html')
            .' --out='.escapeshellarg($out.'/findings.json')
            .' --chrome='.escapeshellarg($chrome)
            .' > /dev/null 2>&1',
        $lines,
        $status
    );

    if (127 === $status) {
        $this->markTestSkipped('no node here');
    }

    expect($status)->toBe(0, 'the browser run failed: '.implode("\n", $lines));

    $found = json_decode((string) file_get_contents($out.'/findings.json'), true);

    expect($found['errors'])->toBe([], 'the screen threw in the browser: '.implode(' | ', $found['errors']));

    // ── The groups are on the page, in the export's order, all ticked ────────
    expect($found['at_rest']['groups'])
        ->toBe(['catalogue', 'seo', 'coupons', 'customers', 'sales', 'reviews', 'content', 'addresses']);
    expect($found['at_rest']['all_ticked'])->toBeTrue('the screen no longer offers the whole export by default');
    expect($found['at_rest']['warnings'])->toBe([]);
    expect($found['at_rest']['start_disabled'])->toBeFalse();

    // ── A partial selection with nothing unmet is NOT obstructed ─────────────
    // This is half the design: exporting the catalogue alone is a normal thing
    // to do and the screen must not argue about it.
    expect($found['catalogue_only']['warnings'])->toBe([]);
    expect($found['catalogue_only']['start_disabled'])->toBeFalse();

    // ── The dependency warning, and the two severities drawn differently ─────
    expect(array_column($found['sales_alone']['warnings'], 'severity'))->toBe(['loses', 'reported']);
    expect($found['sales_alone']['warnings'][0]['heading'])
        ->toBe('This can lose rows without saying so: Orders without Customers');
    expect($found['sales_alone']['warnings'][1]['heading'])
        ->toBe('Exported without what it points at: Orders without Catalogue');

    // ── And Start is not pressable until both are answered ──────────────────
    expect($found['sales_alone']['start_disabled'])->toBeTrue('the export could be started with two warnings unanswered');
    expect($found['sales_alone']['blocked'])->toBe('2 dependencies above are unanswered.');

    // One confirmation answers one warning and not the other.
    expect($found['one_confirmed']['start_disabled'])->toBeTrue();
    expect($found['one_confirmed']['blocked'])->toBe('One dependency above is unanswered.');

    // And the other way out — adding the group — ticks it and clears it.
    expect($found['after_add']['catalogue_ticked'])->toBeTrue();
    expect($found['after_add']['warnings'])->toHaveCount(1);
    expect($found['after_add']['start_disabled'])->toBeFalse();

    // ── One bar per group, in three distinguishable states ──────────────────
    $bars = $found['mid_run']['bars'];

    expect(array_column($bars, 'label'))->toBe([
        'Catalogue', 'SEO (Yoast)', 'Coupons', 'Customers', 'Orders', 'Reviews', 'Journal articles',
        'Addresses and pictures',
    ]);

    expect(array_column($bars, 'state'))
        ->toBe(['done', 'done', 'done', 'done', 'running', 'pending', 'pending', 'pending']);

    expect(array_column($bars, 'width'))->toBe(['100%', '100%', '100%', '100%', '55%', '0%', '0%', '0%']);

    expect($found['mid_run']['overall_width'])->toBe('52%');

    /*
     * ── AND WHAT THE PAGE ACTUALLY SENT ─────────────────────────────────────
     *
     * This is the assertion the surviving mutation needed. Every request the
     * page made carries the selection and the confirmations — including the
     * step requests, because the form is posted with every batch and the server
     * pins what it was given at start().
     */
    expect($found['posts'])->not->toBe([]);

    foreach ($found['posts'] as $index => $post) {
        expect(isset($post['groups']))->toBeTrue("request {$index} carried no selection at all");
        expect(isset($post['confirmed']))->toBeTrue("request {$index} carried no confirmations");
        expect($post['groups'])
            ->toBe('catalogue,seo,coupons,customers,sales,reviews,content,addresses',
                "request {$index} sent a selection that is not what was ticked");
    }

    expect($found['posts'][0]['action'])->toBe('kbb_export_start');
    expect($found['posts'][1]['action'])->toBe('kbb_export_step');
});

it('reads a request with no selection in it as the whole export, and an empty one as nothing', function () {
    /*
     * BOTH OF THESE WERE FOUND BY MUTATION AND BOTH SURVIVED FIRST TIME.
     *
     * 1. Defaulting a MISSING `groups` field to nothing instead of everything
     *    left the suite green, because the harness and the browser both always
     *    send the field. The case that does not is the one that matters: a tab
     *    left open across the plugin update, anything that predates this screen.
     *    That has to keep producing the whole export the plugin has always
     *    produced — silently exporting an empty folder instead is the worst
     *    possible reading of an ambiguous request.
     *
     * 2. Allowing an EMPTY selection to start left the suite green too. It
     *    writes no files and then writes a manifest — and a manifest is this
     *    export's proof that it finished (the contract: written last, so its
     *    presence means the export completed). An empty folder with a manifest
     *    in it is an export that says it succeeded and carries nothing.
     *
     * "Missing" and "empty" therefore have to stay tellable apart, which is why
     * the harness reads --groups= (empty) as an empty selection rather than
     * falling back to everything.
     */
    $lines = [];

    exec(
        escapeshellcmd(PHP_BINARY).' '.escapeshellarg(base_path('wordpress-plugin/harness/screen.php'))
            .' --db='.geWpDb().' --probe=settings 2>&1',
        $lines,
        $status
    );

    if (3 === $status) {
        $this->markTestSkipped('no MySQL here: '.implode(' ', $lines));
    }

    expect($status)->toBe(0, implode("\n", $lines));

    $probe = json_decode(implode("\n", $lines), true);

    $everything = ['catalogue', 'seo', 'coupons', 'customers', 'sales', 'reviews', 'content', 'addresses'];

    expect($probe['no_groups_field']['groups'])->toBe($everything);
    expect($probe['no_groups_field']['runner_groups'])->toBe($everything);

    expect($probe['empty_groups']['groups'])->toBe([]);
    expect($probe['empty_groups']['runner_groups'])->toBe([]);

    // A key no group answers to is dropped before a file is opened, and the
    // surviving ones are put back into the export's own order.
    expect($probe['junk_groups']['runner_groups'])->toBe(['catalogue', 'sales']);

    /*
     * AND AN EMPTY SELECTION DOES NOT START. Refused with a sentence, so the
     * owner is told he ticked nothing rather than handed a folder with a
     * manifest and no data in it.
     */
    $refused = [];

    exec(
        escapeshellcmd(PHP_BINARY).' '.escapeshellarg(base_path('wordpress-plugin/harness/run-export.php'))
            .' --storage=posts --out='.escapeshellarg(sys_get_temp_dir().'/kbb-gk-empty-'.bin2hex(random_bytes(4)))
            .' --db='.geWpDb().' --batch=500 --groups= 2>&1',
        $refused,
        $refusedStatus
    );

    expect($refusedStatus)->toBe(4, 'an export with nothing ticked was allowed to start');
    expect(implode("\n", $refused))->toContain('Nothing is ticked');
});

/*
 * ════════════════════════════════════════════════════════════════════════════
 * LANE GL — ONE DOWNLOADABLE ZIP PER GROUP
 * ════════════════════════════════════════════════════════════════════════════
 *
 * The owner's reply to Lane GK's eight tickable groups:
 *
 *   "you didn't group. please group it, allow to download each group seperate
 *    files. so will have no any heavy file. please do it properly and group
 *    these according."
 *
 * The selection was already there and is correct. What he is asking for is the
 * OUTPUT: today every group writes CSVs into one folder and the screen's
 * instruction is "download the folder over FTP". He wants one file per group,
 * from the browser, none of them heavy.
 *
 * docs/GL-GROUP-DOWNLOADS.md is the account. The five questions these tests ask
 * are the five that can cost him something:
 *
 *   1. Is each zip a COMPLETE IMPORT on its own? (The round trip, again, this
 *      time from inside the archives.)
 *   2. Is the archive the shape Lane GM's import screen is being built against?
 *   3. Is a zip heavy at the real shop's volume, and if so which one?
 *   4. Does adding an archive to the export folder undo the folder's guard?
 *   5. Can anybody who is not the shop manager fetch one?
 */

/** Unpack an archive into a folder of its own and return the folder. */
function glUnpack(string $archive): string
{
    $dir = sys_get_temp_dir().'/kbb-gl-unpack-'.bin2hex(random_bytes(5));

    mkdir($dir, 0755, true);

    $zip = new ZipArchive;

    expect($zip->open($archive))->toBeTrue("could not open {$archive}");
    expect($zip->extractTo($dir))->toBeTrue("could not unpack {$archive}");

    $zip->close();

    return $dir;
}

/** Every entry name in an archive, in the order the archive holds them. */
function glEntries(string $archive): array
{
    $zip = new ZipArchive;

    expect($zip->open($archive))->toBeTrue("could not open {$archive}");

    $out = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $out[] = $zip->getNameIndex($i);
    }

    $zip->close();

    return $out;
}

/** The archive a group's zip is, inside an export folder. */
function glArchive(string $dir, string $group): string
{
    $found = glob($dir.'/kbb-export-'.$group.'-*.zip');

    expect($found)->toHaveCount(1, "expected exactly one archive for {$group} in {$dir}");

    return $found[0];
}

/** KBB_Export_Zip's own answers, with the file sizes handed in. No MySQL. */
function glZipProbe(string $flags = ''): array
{
    $lines = [];

    exec(
        escapeshellcmd(PHP_BINARY).' '.escapeshellarg(base_path('wordpress-plugin/harness/groups.php'))
            .' --zip_probe=1'.($flags === '' ? '' : ' '.$flags).' 2>&1',
        $lines,
        $status
    );

    expect($status)->toBe(0, implode("\n", $lines));

    return json_decode(implode("\n", $lines), true);
}

/** The screen harness, with a real export and a real zip phase behind it. */
function glScreen(string $groups, string $confirm, string $extra): array
{
    $lines = [];

    exec(
        escapeshellcmd(PHP_BINARY).' '.escapeshellarg(base_path('wordpress-plugin/harness/screen.php'))
            .' --db='.geWpDb().' --with_export='.escapeshellarg($groups)
            .($confirm === '' ? '' : ' --confirm='.escapeshellarg($confirm))
            .' '.$extra.' 2>&1',
        $lines,
        $status
    );

    if (3 === $status) {
        test()->markTestSkipped('no MySQL here: '.implode(' ', $lines));
    }

    expect($status)->toBe(0, implode("\n", $lines));

    return ['status' => $status, 'out' => implode("\n", $lines)];
}

it('packs one zip per group, and each one imports on its own into what the whole export lands', function () {
    /*
     * ════════════════════════════════════════════════════════════════════════
     * THE ROUND TRIP, FROM INSIDE THE ARCHIVES
     * ════════════════════════════════════════════════════════════════════════
     *
     * Lane GK proved that eight separate EXPORTS, one per group, land what the
     * all-at-once export lands. This is the claim one level further out and it
     * is the claim the owner is actually making when he downloads eight files:
     * that ONE export's eight ARCHIVES, unpacked one at a time into folders of
     * their own, land the same thing.
     *
     * It is not the same claim, and the difference is the manifest. A group's
     * archive carries a manifest this class BUILT -- narrowed to its own files
     * -- rather than the one the export wrote. If that narrowing were wrong,
     * every CSV in the folder could be perfect and the import would still read
     * the wrong denominator, refuse the wrong duplicate, or be told the shop has
     * zero customers when the archive simply does not carry them.
     *
     * So: the real ImportRunner, the same class `kbb:import` runs, pointed at
     * the unpacked archive and nothing else.
     */
    $dir = gkExport(implode(',', ['catalogue', 'seo', 'coupons', 'customers', 'sales', 'reviews', 'content', 'addresses']));

    $order = ['catalogue', 'seo', 'coupons', 'customers', 'sales', 'reviews', 'content', 'addresses'];

    $unpacked = [];

    foreach ($order as $group) {
        $unpacked[$group] = glUnpack(glArchive($dir, $group));
    }

    /*
     * EVERY CSV INSIDE AN ARCHIVE IS BYTE-IDENTICAL to the one in the folder,
     * which is byte-identical to the fixture. Zipping changes WHICH file is
     * where and nothing about what is in it; if compression could alter a byte
     * then every claim this file makes about columns and money would hold for
     * the folder and for nothing the owner actually downloads.
     */
    foreach ($unpacked as $group => $folder) {
        foreach (glob($folder.'/*.csv') as $file) {
            expect(hash_file('sha256', $file))->toBe(
                hash_file('sha256', geExportDir().'/'.basename($file)),
                basename($file).' came out of the '.$group.' archive different from the export'
            );
        }
    }

    $manifest = geManifest();

    // Imported one ARCHIVE at a time, in the order the screen lists them, into
    // one shop — which is how the owner will do it.
    foreach ($order as $group) {
        $report = geImport(['directory' => $unpacked[$group]]);

        expect(geUnexpectedRejections($report))->toBe(
            [],
            'the '.$group.' archive imported with refusals: '.implode(' | ', geUnexpectedRejections($report))
        );
    }

    expect(Category::query()->whereNotNull('source_term_id')->count())->toBe($manifest['counts']['categories']);
    expect(Brand::query()->whereNotNull('source_term_id')->count())->toBe($manifest['counts']['brands']);
    expect(Product::query()->withTrashed()->whereNotNull('wc_id')->count())->toBe($manifest['counts']['products']);
    expect(Coupon::query()->whereNotNull('wc_id')->count())->toBe($manifest['counts']['coupons']);
    expect(Customer::query()->withTrashed()->whereNotNull('wp_user_id')->count())->toBe($manifest['counts']['customers']);
    expect(Order::query()->withTrashed()->whereNotNull('wc_order_id')->count())->toBe($manifest['counts']['orders']);
    expect(OrderItem::query()->whereNotNull('wc_item_id')->count())->toBe($manifest['counts']['order_items']);
    expect(Refund::query()->count())->toBe($manifest['counts']['refunds']);
    expect(OrderNote::query()->whereNotNull('source_comment_id')->count())->toBe($manifest['counts']['order_notes']);
    expect(Review::query()->where('source', 'wp_comment')->count())->toBe($manifest['counts']['reviews']);

    /*
     * AND THE FOREIGN KEYS RESOLVED ACROSS ARCHIVE BOUNDARIES, which is the only
     * thing separating "eight folders of rows" from "a shop". An order line whose
     * product_id is null is what importing the sales archive before the catalogue
     * archive produces, and eight separate downloads is precisely the situation
     * in which somebody does them in the wrong order.
     */
    expect(OrderItem::query()->whereNotNull('wc_item_id')->whereNull('product_id')->count())
        ->toBe(0, 'an order line lost its product across the archive boundary');

    expect(Order::query()->whereNotNull('wc_order_id')->whereNull('customer_id')->count())
        ->toBe(0, 'an order lost its customer across the archive boundary');

    expect(Customer::query()->withTrashed()->whereNull('wp_user_id')->count())
        ->toBe(1, 'the guest order should synthesise exactly one customer, and no more');

    expect(Product::query()->where('wc_id', 4021)->value('seo'))->not->toBeNull();
});

it('puts the group files at the archive root with no wrapping directory', function () {
    /*
     * ════════════════════════════════════════════════════════════════════════
     * THE SHAPE, WHICH IS A CONTRACT WITH LANE GM AND NOT AN IMPLEMENTATION
     * ════════════════════════════════════════════════════════════════════════
     *
     * Lane GM is teaching the Laravel import screen to accept one of these. The
     * two ends of that pipe have to agree before either is written, exactly as
     * docs/WP-EXPORT-CONTRACT.md exists because "a format invented twice is a
     * format that does not meet in the middle". So the shape is asserted here
     * rather than described: entries at the root, no wrapping directory, the
     * group's CSVs under their contract names, one manifest.json, and NOTHING
     * ELSE -- no index.php, no .htaccess, no uploads folder, no other group's
     * files.
     *
     * The "nothing else" half is the one that matters most. index.php and
     * .htaccess are the export folder's guard; sweeping the folder into a zip
     * would carry them to wherever the owner unpacks it, which is a deny-all
     * .htaccess arriving somewhere nobody expects one.
     */
    $dir = gkExport('catalogue,customers,sales', 'sales:customers,sales:catalogue');

    $expected = [
        'catalogue' => ['categories.csv', 'brands.csv', 'tags.csv', 'attributes.csv', 'products.csv', 'variations.csv'],
        'customers' => ['customers.csv'],
        'sales' => ['orders.csv', 'order_items.csv', 'refunds.csv', 'order_notes.csv'],
    ];

    foreach ($expected as $group => $files) {
        $entries = glEntries(glArchive($dir, $group));

        // NO WRAPPING DIRECTORY, said as a property of every entry rather than
        // by checking the first one: a single entry with a slash in it is the
        // whole defect, wherever in the archive it is.
        foreach ($entries as $entry) {
            expect(str_contains($entry, '/'))->toBeFalse("{$group}: {$entry} is not at the archive root");
            expect(str_starts_with($entry, '.'))->toBeFalse("{$group}: {$entry} should not be in a download");
        }

        // array_diff both ways rather than expect()->not->toContain(), which
        // passes vacuously because toContain is variadic.
        $wanted = array_merge($files, ['manifest.json']);

        expect(array_values(array_diff($wanted, $entries)))
            ->toBe([], "{$group} archive is missing: ".implode(' ', array_diff($wanted, $entries)));

        expect(array_values(array_diff($entries, $wanted)))
            ->toBe([], "{$group} archive carries something it should not: ".implode(' ', array_diff($entries, $wanted)));
    }

    // And the name says which group it is, which is the owner's own request:
    // he will have several of these in one Downloads folder.
    foreach (array_keys($expected) as $group) {
        expect(basename(glArchive($dir, $group)))->toMatch('/^kbb-export-'.$group.'-[0-9a-f]{8}\.zip$/');
    }
});

it('gives every archive of one export the same export id and a manifest narrowed to its own files', function () {
    /*
     * THE ONE FIELD THAT MAKES EIGHT DOWNLOADS ONE EXPORT.
     *
     * docs/WP-EXPORT-CONTRACT.md: "export_id identifies one export". Eight
     * archives that are each a complete kbb-export/1 export would otherwise be
     * eight unrelated exports, and the shop's duplicate guard -- which the
     * contract says exists so that "importing the same export twice" is refused
     * rather than silently merged -- would have nothing to join them on.
     *
     * And the narrowing, which is the contract's absent-versus-"rows": 0 rule
     * one level down. A catalogue archive that listed customers.csv with
     * "rows": 0 would be telling the shop THIS SHOP HAS NO CUSTOMERS, which is
     * a different fact from "this archive does not carry them" and is false.
     */
    $dir = gkExport('catalogue,customers,sales', 'sales:customers,sales:catalogue');

    $whole = json_decode((string) file_get_contents($dir.'/manifest.json'), true);

    $groups = [
        'catalogue' => ['categories.csv', 'brands.csv', 'tags.csv', 'attributes.csv', 'products.csv', 'variations.csv'],
        'customers' => ['customers.csv'],
        'sales' => ['orders.csv', 'order_items.csv', 'refunds.csv', 'order_notes.csv'],
    ];

    foreach ($groups as $group => $files) {
        $folder = glUnpack(glArchive($dir, $group));
        $mine = json_decode((string) file_get_contents($folder.'/manifest.json'), true);

        expect($mine['format'])->toBe('kbb-export/1');
        expect($mine['export_id'])->toBe($whole['export_id'], "the {$group} archive is not part of this export");
        expect($mine['generated_at'])->toBe($whole['generated_at']);
        expect($mine['source'])->toBe($whole['source']);

        // `files` is exactly this group's, and every fact in it is the export's
        // own fact rather than one recomputed a second way.
        expect(array_keys($mine['files']))->toBe($files);

        foreach ($files as $file) {
            expect($mine['files'][$file])->toBe($whole['files'][$file]);
            expect(hash_file('sha256', $folder.'/'.$file))->toBe($mine['files'][$file]['sha256']);
            expect(filesize($folder.'/'.$file))->toBe($mine['files'][$file]['bytes']);
        }

        // ABSENT, not "rows": 0. array_diff, because toContain is variadic and
        // `->not->toContain()` would pass however wrong this got.
        $foreign = array_values(array_intersect(
            array_keys($mine['files']),
            array_diff(array_keys($whole['files']), $files)
        ));

        expect($foreign)->toBe([], "the {$group} manifest describes files that are not in the archive: ".implode(' ', $foreign));

        expect($mine['groups']['selected'])->toBe([$group]);
        expect($mine['groups']['files'])->toBe($files);
        expect($mine['groups']['skipped'])->toContain('reviews');

        // And it knows what else exists, which is what answers "is there more
        // of this" from inside one archive.
        expect($mine['zip']['group'])->toBe($group);
        expect($mine['zip']['part'])->toBe(1);
        expect($mine['zip']['parts'])->toBe(1);
        expect($mine['zip']['of_export'])->toBe(['catalogue', 'customers', 'sales']);
        expect($mine['zip']['archive'])->toBe(basename(glArchive($dir, $group)));

        // The first note says, in words, that this is one group of a bigger
        // export and that the order matters. The owner reads notes; he does not
        // read `zip.of_export`.
        expect($mine['notes'][0])->toContain('ONE GROUP of export '.$whole['export_id']);
        expect($mine['notes'][0])->toContain('same export_id');
        expect($mine['notes'][0])->toContain('costs customer rows');

        // The export's own notes survive into every archive: they are facts
        // about the SHOP and no less true of a slice of it.
        foreach ($whole['notes'] as $note) {
            expect($mine['notes'])->toContain($note);
        }
    }
});

it('does not undo the folder guard by putting an archive in it', function () {
    /*
     * ════════════════════════════════════════════════════════════════════════
     * THE ARCHIVE IS THE SAME SECRET AS THE FOLDER, AND LIVES UNDER THE SAME LOCK
     * ════════════════════════════════════════════════════════════════════════
     *
     * customers.csv holds every shopper's address and their WordPress password
     * hash; reviews.csv holds the reviewer's email and the IP they posted from.
     * Zipping them does not make them less of a secret -- it makes them a SINGLE
     * FILE with a predictable name, which is strictly easier to fetch than
     * seventeen.
     *
     * The folder is already protected three ways (a random id in the path, an
     * index.php against directory listing, a deny-all .htaccess on the parent).
     * A zip written anywhere else -- the uploads root, a "downloads" folder, a
     * temp directory the web server serves -- would hand back with one hand what
     * that took with the other. So the guard files are read back AFTER the
     * archives are written, and the archives are counted inside the guarded
     * folder and outside it.
     */
    $lines = [];
    $out = sys_get_temp_dir().'/kbb-gl-guard-'.bin2hex(random_bytes(4));

    exec(
        escapeshellcmd(PHP_BINARY).' '.escapeshellarg(base_path('wordpress-plugin/harness/run-export.php'))
            .' --storage=posts --out='.escapeshellarg($out).' --db='.geWpDb().' --batch=500 2>&1',
        $lines,
        $status
    );

    if (3 === $status) {
        $this->markTestSkipped('no MySQL here: '.implode(' ', $lines));
    }

    expect($status)->toBe(0, implode("\n", $lines));

    $report = json_decode(implode("\n", $lines), true);

    expect($report['guards']['dir_index'])->toBeTrue('the export folder lost its index.php');
    expect($report['guards']['parent_index'])->toBeTrue('kbb-export/ lost its index.php');
    expect($report['guards']['parent_htaccess'])->toBeTrue('kbb-export/ lost its .htaccess');

    // The deny is unconditional and covers the whole folder, which is what makes
    // it cover a file type that did not exist when it was written.
    expect($report['guards']['htaccess_body'])->toContain('Require all denied');
    expect($report['guards']['htaccess_body'])->toContain('Deny from all');

    /*
     * ONE BOUNDED UNIT PER REQUEST, server side. The harness calls zip_step()
     * through a FRESH runner each time, exactly as a separate HTTP request
     * would, and the number of calls has to equal the number of units in the
     * plan. A step that drained the queue would finish in one call — and on a
     * fixture this small it would look identical, while on 10,571 order lines it
     * is the 110-second request limit the batching exists to respect.
     */
    expect($report['zip_steps'])->toBe(
        $report['zip']['units'],
        'the zip phase did not do exactly one bounded unit per request'
    );

    expect($report['zip']['units'])->toBeGreaterThan(8, 'a plan of one unit per group is not one unit per file');

    expect($report['guards']['archives_inside'])->toBe(8, 'one archive per group, inside the guarded folder');
    expect($report['guards']['archives_outside'])
        ->toBe(0, 'an archive was written OUTSIDE the guarded folder, which undoes the guard');

    // And the archives really are in the folder the guard covers.
    foreach (glob($report['dir'].'/*.zip') as $archive) {
        expect(str_starts_with(basename($archive), 'kbb-export-'))->toBeTrue();
    }
});

it('refuses a download without the capability, without the nonce, and answers the same way for everything it does not have', function () {
    /*
     * ════════════════════════════════════════════════════════════════════════
     * THE ENDPOINT THAT HANDS OVER EVERY SHOPPER'S PASSWORD HASH
     * ════════════════════════════════════════════════════════════════════════
     *
     * CLAUDE.md's standing warning is about /api/* being unauthenticated and
     * about `reviews` carrying author_email and ip. This is the same data behind
     * a new door, so the door gets the same scrutiny:
     *
     *   - manage_woocommerce, checked in the handler and not merely on the menu
     *     entry, because add_management_page() decides what is in a menu and not
     *     what answers a URL;
     *   - a nonce of its own, so a leaked download URL cannot be replayed as a
     *     request to start an export;
     *   - and ONE ANSWER for every way of not having the file. A group that was
     *     never exported, a group key that does not exist and a path traversal
     *     all get the same sentence and the same status, so the endpoint cannot
     *     be used to ask which groups this shop exported. That is the same shape
     *     CLAUDE.md requires of QuizSubmission::findByPublicToken(), and for the
     *     same reason: branching differently on the two restores the oracle.
     */
    $found = json_decode(
        glScreen('catalogue,customers,sales', 'sales:customers,sales:catalogue', '--probe=download --scenario=refusals')['out'],
        true
    );

    // Not a shop manager: 403, and NOT ONE BYTE of the archive.
    expect($found['no_cap']['status'])->toBe(403);
    expect($found['no_cap']['message'])->toContain('do not have permission');
    expect($found['no_cap']['bytes'])->toBe(0, 'a refusal wrote part of the archive before refusing');

    // The right user, a wrong nonce: still 403.
    expect($found['bad_nonce']['status'])->toBe(403);
    expect($found['bad_nonce']['message'])->toContain('expired');
    expect($found['bad_nonce']['bytes'])->toBe(0);

    /*
     * AND THE FOUR WAYS OF NOT HAVING IT ARE INDISTINGUISHABLE. Compared against
     * each other rather than against a literal, so that changing the sentence
     * keeps the property and diverging on any one of them fails.
     */
    $indistinguishable = ['absent_group', 'bogus_group', 'traversal', 'absent_part'];

    foreach ($indistinguishable as $case) {
        expect($found[$case]['status'])->toBe(404, $case.' answered differently');
        expect($found[$case]['bytes'])->toBe(0);
        expect($found[$case]['message'])->toBe(
            $found['absent_group']['message'],
            $case.' says something "no such download" does not, which tells a stranger which groups exist'
        );
    }

    // ── And the happy path streams the archive itself, byte for byte ─────────
    $found = json_decode(glScreen('catalogue', '', '--probe=download --scenario=ok --describe=1')['out'], true);

    expect($found['ok'])->toBeTrue();
    expect($found['name'])->toMatch('/^kbb-export-catalogue-[0-9a-f]{8}\.zip$/');

    /*
     * THE FILE IT WOULD SEND IS INSIDE THE GUARDED FOLDER. An endpoint that
     * authenticates correctly and then serves a file out of a directory the web
     * server also serves has not protected anything.
     */
    expect(str_starts_with($found['path'], $found['dir'].'/'))->toBeTrue('the archive is not inside the export folder');
    expect($found['dir'])->toContain('/kbb-export/');
    expect(file_exists($found['dir'].'/index.php'))->toBeTrue();
    expect(file_exists(dirname($found['dir']).'/.htaccess'))->toBeTrue();

    /*
     * ════════════════════════════════════════════════════════════════════════
     * AND IT STREAMS — MEASURED, BECAUSE ON A 4 KB ARCHIVE IT CANNOT BE SEEN
     * ════════════════════════════════════════════════════════════════════════
     *
     * download() reads the archive in 8 KB chunks rather than whole, because a
     * 40 MB archive read with file_get_contents() is 40 MB of PHP memory on a
     * shared host. Against this fixture's 4 KB archives both spellings behave
     * identically, so "it streams" would be a comment and the mutation that
     * replaced it would survive every other test here.
     *
     * So: the archive is padded with 96 MB of incompressible, STORED bytes and
     * the endpoint is run in a process capped at 64 MB. Streaming survives.
     * Reading it whole is a fatal error, and the mutation that does so is red —
     * docs/GL-GROUP-DOWNLOADS.md section 6, M14.
     */
    $body = sys_get_temp_dir().'/kbb-gl-stream-'.bin2hex(random_bytes(4)).'.zip';
    $err = $body.'.err';

    exec(
        escapeshellcmd(PHP_BINARY).' -d memory_limit=64M '
            .escapeshellarg(base_path('wordpress-plugin/harness/screen.php'))
            .' --db='.geWpDb().' --with_export=catalogue --probe=download --scenario=ok --pad=96'
            .' > '.escapeshellarg($body).' 2> '.escapeshellarg($err),
        $streamLines,
        $streamStatus
    );

    expect($streamStatus)->toBe(
        0,
        'the download did not survive a 96 MB archive in a 64 MB process, which is what reading it whole does: '
            .(string) file_get_contents($err)
    );

    expect(filesize($body))->toBeGreaterThan(
        90 * 1024 * 1024,
        'the endpoint sent less than the archive, so it did not stream all of it'
    );

    // And what it sent IS the archive: a stream that drops or reorders a chunk
    // is a corrupt download, which unzip -t is the only real check for.
    $zip = new ZipArchive;

    expect($zip->open($body, ZipArchive::CHECKCONS))->toBeTrue('the streamed bytes are not a valid archive');

    $names = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    $zip->close();

    expect($names)->toContain('products.csv');
    expect($names)->toContain('manifest.json');

    unlink($body);
    unlink($err);
});

it('splits a group that would be a heavy download, whole files at a time', function () {
    /*
     * THE GUARD THAT DOES NOT FIRE ON THIS SHOP, reached anyway.
     *
     * docs/GL-GROUP-DOWNLOADS.md section 3 measures the largest group at 7.98 MB
     * of CSV against a 25 MiB cap, so nothing here splits and the splitter would
     * otherwise be code no test ever runs. KBB_Export_Zip::parts_for() reads
     * nothing but the manifest's `bytes`, so a manifest asserting that orders.csv
     * is 30 MB is the whole of what a 30 MB orders.csv would give it — no
     * fixture, no MySQL, and it runs in CI where the arithmetic most needs to.
     */
    $probe = glZipProbe('--bytes=orders.csv:30000000');

    $parts = [];

    foreach ($probe['plan'] as $unit) {
        $parts[$unit['group']][$unit['part']][] = $unit['file'];
    }

    // Orders is in two parts, and no CSV is in more than one of them: a part
    // holding half of orders.csv beside the whole of order_items.csv would import
    // lines against orders that are not there.
    expect($parts['sales'])->toHaveCount(2);
    expect($parts['sales'][1])->toBe(['orders.csv', 'manifest.json']);
    expect($parts['sales'][2])->toBe(['order_items.csv', 'refunds.csv', 'order_notes.csv', 'manifest.json']);

    // Every other group is untouched — splitting one group does not reorganise
    // the export.
    expect($parts['catalogue'])->toHaveCount(1);
    expect($parts['customers'])->toHaveCount(1);

    /*
     * ════════════════════════════════════════════════════════════════════════
     * AND EACH PART'S MANIFEST DESCRIBES THAT PART — found by mutation (M8)
     * ════════════════════════════════════════════════════════════════════════
     *
     * Making files_in_part() ignore the part it was asked for, so that every
     * part answers with part 1's files, left the whole suite green: nothing on
     * this shop splits, so every group has exactly one part and the two answers
     * are the same list. The assertions above read plan(), which computes its
     * parts separately and so could not see it.
     *
     * The result of that mutation is the worst thing an archive can be — a
     * part 2 holding order_items.csv whose manifest says it holds orders.csv.
     * Every fact the shop reads about that archive would then be about a file
     * that is not in it: the wrong row count, the wrong sha256, the wrong
     * entity name.
     */
    foreach ($parts['sales'] as $part => $files) {
        $mine = $probe['manifests']['sales:'.$part];

        expect(array_keys($mine['files']))->toBe(
            array_values(array_diff($files, ['manifest.json'])),
            'the manifest in part '.$part.' does not describe part '.$part
        );

        expect($mine['groups']['files'])->toBe(array_values(array_diff($files, ['manifest.json'])));
        expect($mine['zip']['part'])->toBe($part);
        expect($mine['zip']['parts'])->toBe(2);
        expect($mine['zip']['archive'])->toContain('part'.$part.'of2');
        expect($mine['notes'][0])->toContain('part '.$part.' of 2');
    }

    // The two parts between them describe every file of the group, exactly once.
    $described = array_merge(
        array_keys($probe['manifests']['sales:1']['files']),
        array_keys($probe['manifests']['sales:2']['files'])
    );

    sort($described);

    expect($described)->toBe(['order_items.csv', 'order_notes.csv', 'orders.csv', 'refunds.csv']);
    expect(count($described))->toBe(count(array_unique($described)), 'a file is described by two parts');

    // Each part carries its own manifest, and the archive names say which is
    // which, so two files in a Downloads folder are not the same name twice.
    $names = glZipProbe('')['names'];

    expect($names['single'])->toBe('kbb-export-sales-aaaaaaaa.zip');
    expect($names['part'])->toBe('kbb-export-sales-aaaaaaaa-part2of3.zip');

    /*
     * AND A GROUP THIS EXPORT DID NOT WRITE GETS NO ARCHIVE AT ALL. `--bytes=X:-`
     * removes the file from the manifest, which is what a group that was never
     * ticked looks like: absent from `files`, never present with "rows": 0.
     */
    $without = glZipProbe('--bytes=seo.csv:-,reviews.csv:-');

    $groupsWithArchives = array_values(array_unique(array_column($without['plan'], 'group')));

    expect(array_values(array_intersect($groupsWithArchives, ['seo', 'reviews'])))
        ->toBe([], 'a group with no files in the manifest was given an archive that would 404 on a click');

    expect($groupsWithArchives)->toContain('catalogue');

    // array_filter on the key prefix, not ->not->toContain(): the manifests are
    // keyed "<group>:<part>" and a variadic toContain would pass vacuously.
    expect(array_values(array_filter(
        array_keys($without['manifests']),
        static fn (string $key): bool => str_starts_with($key, 'seo:') || str_starts_with($key, 'reviews:'),
    )))->toBe([], 'a group with no files in the manifest was given an archive manifest');
});

it('weighs every group at the real shop volume, and says whether anything needs splitting', function () {
    /*
     * ════════════════════════════════════════════════════════════════════════
     * "no any heavy file" IS A CLAIM ABOUT SIZE, SO IT IS MEASURED
     * ════════════════════════════════════════════════════════════════════════
     *
     * tests/Fixtures/kbb-export is five products and three orders. The shop is
     * 671 products, 4,159 orders, 10,571 line items, 3,712 customers and 2,514
     * reviews — four orders of magnitude away — and the decision this drives
     * (does any group need splitting into numbered parts?) cannot be made at the
     * fixture's volume. wordpress-plugin/harness/volume.php builds every CSV at
     * the real volume and zips each group through the SHIPPED class.
     *
     * This test is here so the FINDING cannot go stale. A stage that starts
     * writing five times the bytes, or a cap somebody lowers, moves a group over
     * the line — and docs/GL-GROUP-DOWNLOADS.md's table would then be a
     * measurement of a program that no longer exists.
     */
    $lines = [];

    exec(
        escapeshellcmd(PHP_BINARY).' '.escapeshellarg(base_path('wordpress-plugin/harness/volume.php'))
            .' --json 2>&1',
        $lines,
        $status
    );

    expect($status)->toBe(0, implode("\n", $lines));

    $report = json_decode(implode("\n", $lines), true);

    expect($report['groups'])->not->toBeEmpty();

    // NOTHING IS OVER THE CAP, which is the finding — stated as an assertion so
    // that it stops being true loudly rather than quietly.
    $over = array_values(array_filter($report['groups'], static fn (array $g): bool => $g['over_cap'] || $g['raw_over_cap']));

    expect($over)->toBe([], 'a group now needs splitting at the real shop volume: '.json_encode(array_column($over, 'group')));

    // One part per group: the measurement and the plan agree.
    foreach ($report['groups'] as $group) {
        expect($group['part'])->toBe('1/1', $group['group'].' was split at the real shop volume');
    }

    /*
     * THE LARGEST DOWNLOAD, named. 10 MB is the number in the class comment and
     * in the doc, and it is what "no heavy file" was taken to mean; a group that
     * reached it would be one the owner notices.
     */
    expect($report['largest_zip_bytes'])->toBeLessThan(
        10 * 1024 * 1024,
        'the largest group zip is now over 10 MB, which is the size this lane exists to avoid'
    );

    // And the whole export is bigger than any one group, which is the point:
    // he never has to fetch this in one piece.
    expect($report['folder_bytes'])->toBeGreaterThan($report['largest_zip_bytes'] * 3);

    /*
     * ONE BOUNDED UNIT PER REQUEST, measured rather than asserted. The slowest
     * single unit is the largest CSV's compression, and it has to fit inside a
     * shared host's request limit with room to spare — that limit is 110 seconds
     * on this host and the whole reason the export is batched at all.
     */
    expect($report['slowest_unit_ms'])->toBeLessThan(
        10000,
        'one zip unit now takes over ten seconds, which is a shared host timeout waiting to happen'
    );
});

it('draws a download button per group, and says so rather than 404ing when a group has no archive', function () {
    /*
     * ════════════════════════════════════════════════════════════════════════
     * EVERYTHING ADDED TO THIS SCREEN IS JAVASCRIPT, AND THAT IS A MEASURED HOLE
     * ════════════════════════════════════════════════════════════════════════
     *
     * docs/GK-EXPORT-GROUPS.md section 6.1: deleting one line of the screen's
     * script left the whole PHP suite GREEN, because no PHP test loads the page.
     * Lane GL adds a download table and a second browser-driven loop to that same
     * screen, so it is checked the same way — rendered by
     * KBB_Export_Admin::screen() and driven in Chromium.
     *
     * Three things, and the third is the owner's actual instruction that the
     * screen stay honest about what it has:
     *
     *   1. A group that WAS exported gets a real link, with the archive's name
     *      and its size on it.
     *   2. A group that was NOT gets a disabled control SAYING SO — not a link
     *      that 404s, and not a missing row, which reads as a bug.
     *   3. Every link carries the download nonce and a group key, and no path.
     */
    if (! is_dir(base_path('node_modules/playwright'))) {
        $this->markTestSkipped('playwright is not installed here');
    }

    $chrome = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

    if (! is_file($chrome)) {
        $this->markTestSkipped('no Chromium at '.$chrome);
    }

    $out = sys_get_temp_dir().'/kbb-gl-screen-'.bin2hex(random_bytes(4));

    mkdir($out, 0755, true);

    $render = [];

    /*
     * A REAL EXPORT AND A REAL ZIP PHASE BEHIND THE PAGE, and only three groups
     * of the eight — because the state that has to be got right is the one where
     * five groups have no archive. The page is then rendered on top of that
     * state, which also exercises the path that draws the table FROM THE SERVER
     * on load: he will close the tab and come back, and a download table that
     * only exists in the page that started the export is one he cannot reach.
     */
    exec(
        escapeshellcmd(PHP_BINARY).' '.escapeshellarg(base_path('wordpress-plugin/harness/screen.php'))
            .' --db='.geWpDb().' --with_export=catalogue,customers,sales'
            .' --confirm=sales:customers,sales:catalogue'
            .' > '.escapeshellarg($out.'/screen.html').' 2>'.escapeshellarg($out.'/render.err'),
        $render,
        $renderStatus
    );

    if (3 === $renderStatus) {
        $this->markTestSkipped('no MySQL here');
    }

    expect($renderStatus)->toBe(0, (string) file_get_contents($out.'/render.err'));

    $lines = [];

    exec(
        'cd '.escapeshellarg(base_path()).' && node '
            .escapeshellarg(base_path('wordpress-plugin/harness/screen-drive.mjs'))
            .' --page='.escapeshellarg($out.'/screen.html')
            .' --static=1'
            .' --out='.escapeshellarg($out.'/findings.json')
            .' --shots='.escapeshellarg(base_path('docs/gl-download-shots'))
            .' --shotname=02-after-a-run.png'
            .' --chrome='.escapeshellarg($chrome)
            .' > /dev/null 2>&1',
        $lines,
        $status
    );

    if (127 === $status) {
        $this->markTestSkipped('no node here');
    }

    expect($status)->toBe(0, 'the browser run failed: '.implode("\n", $lines));

    $found = json_decode((string) file_get_contents($out.'/findings.json'), true);

    expect($found['errors'])->toBe([], 'the screen threw in the browser: '.implode(' | ', $found['errors']));

    $rows = [];

    foreach ($found['static_downloads'] as $row) {
        $rows[$row['label']] = $row;
    }

    // Every group has a row. A group missing from the table is indistinguishable
    // from a broken page.
    expect(array_keys($rows))->toBe([
        'Catalogue', 'SEO (Yoast)', 'Coupons', 'Customers', 'Orders',
        'Reviews', 'Journal articles', 'Addresses and pictures',
    ]);

    foreach (['Catalogue', 'Customers', 'Orders'] as $label) {
        expect($rows[$label]['state'])->toBe('ready', $label.' was exported but has no download');
        expect($rows[$label]['tag'])->toBe('a', $label.' is not a real link');
        expect($rows[$label]['href'])->toContain('action=kbb_export_download');
        expect($rows[$label]['href'])->toContain('_wpnonce=');
        expect($rows[$label]['description'])->toContain('.zip');

        /*
         * NO PATH IN THE URL. The whole download design rests on the request
         * carrying a group key and nothing a filesystem could act on, and a link
         * that started carrying a file name would move that guarantee from the
         * design into a sanitiser.
         */
        expect($rows[$label]['href'])->not->toContain('..');
        expect(str_contains($rows[$label]['href'], '.csv'))->toBeFalse($label.' has a file path in its download URL');
        expect(str_contains($rows[$label]['href'], 'uploads'))->toBeFalse($label.' links into the uploads folder directly');
    }

    // ── And the five that were not exported say so ───────────────────────────
    foreach (['SEO (Yoast)', 'Coupons', 'Reviews', 'Journal articles', 'Addresses and pictures'] as $label) {
        expect($rows[$label]['state'])->toBe('absent', $label.' offers a download it does not have');
        expect($rows[$label]['tag'])->toBe('button', $label.' is a link to something that is not there');
        expect($rows[$label]['disabled'])->toBeTrue($label.' is pressable and would 404');
        expect($rows[$label]['description'])->toContain('was not in this export');
        expect($rows[$label]['href'])->toBe('');
    }
});

it('packs and draws the archives live, in a browser, one bounded unit at a time', function () {
    /*
     * THE OTHER HALF OF THE SCREEN: the loop. When the export finishes the page
     * starts posting kbb_export_zip and redraws the table between units, which is
     * how a group is packed without any single request having to survive
     * compressing 10,571 order lines on a shared host.
     *
     * Driven with fetch intercepted, for the two reasons
     * docs/GK-EXPORT-GROUPS.md gives: there is no WordPress here to answer
     * admin-ajax.php, and a real run of this fixture finishes in under a second
     * so there is no moving state to hold.
     */
    if (! is_dir(base_path('node_modules/playwright'))) {
        $this->markTestSkipped('playwright is not installed here');
    }

    $chrome = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

    if (! is_file($chrome)) {
        $this->markTestSkipped('no Chromium at '.$chrome);
    }

    $out = sys_get_temp_dir().'/kbb-gl-live-'.bin2hex(random_bytes(4));

    mkdir($out, 0755, true);

    $render = [];

    exec(
        escapeshellcmd(PHP_BINARY).' '.escapeshellarg(base_path('wordpress-plugin/harness/screen.php'))
            .' --db='.geWpDb().' > '.escapeshellarg($out.'/screen.html').' 2>&1',
        $render,
        $renderStatus
    );

    if (3 === $renderStatus) {
        $this->markTestSkipped('no MySQL here: '.implode(' ', $render));
    }

    expect($renderStatus)->toBe(0, implode("\n", $render));

    $lines = [];

    exec(
        'cd '.escapeshellarg(base_path()).' && node '
            .escapeshellarg(base_path('wordpress-plugin/harness/screen-drive.mjs'))
            .' --page='.escapeshellarg($out.'/screen.html')
            .' --out='.escapeshellarg($out.'/findings.json')
            .' --chrome='.escapeshellarg($chrome)
            .' > /dev/null 2>&1',
        $lines,
        $status
    );

    if (127 === $status) {
        $this->markTestSkipped('no node here');
    }

    expect($status)->toBe(0, 'the browser run failed: '.implode("\n", $lines));

    $found = json_decode((string) file_get_contents($out.'/findings.json'), true);

    expect($found['errors'])->toBe([], 'the screen threw in the browser: '.implode(' | ', $found['errors']));

    /*
     * THE LOOP RAN, one request per unit. One request that packed everything is
     * the timeout this design exists to avoid, and it would look identical on a
     * fixture this small — so the REQUESTS are counted, not the outcome.
     */
    expect($found['zip_posts'])->toBe(3, 'the page did not pack one bounded unit per request');

    // Every zip request carries the nonce, the same as every export request:
    // a second endpoint is a second door.
    $zipPosts = array_values(array_filter($found['posts'], static fn (array $p): bool => ($p['action'] ?? '') === 'kbb_export_zip'));

    expect($zipPosts)->not->toBeEmpty();

    foreach ($zipPosts as $post) {
        expect($post['nonce'] ?? '')->not->toBe('');
    }

    // And the table it drew has all three states on it.
    $states = array_column($found['downloads'], 'state', 'label');

    expect($states['Catalogue'])->toBe('ready');
    expect($states['SEO (Yoast)'])->toBe('absent');
    expect($states['Orders'])->toBe('ready');

    $ready = array_values(array_filter($found['downloads'], static fn (array $r): bool => $r['state'] === 'ready'));

    expect($ready)->not->toBeEmpty();

    foreach ($ready as $row) {
        expect($row['tag'])->toBe('a');
        expect($row['href'])->toContain('_wpnonce=');
        expect($row['description'])->toContain('.zip');
    }
});
