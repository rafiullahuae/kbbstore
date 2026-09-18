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
use App\Models\Product;
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
    $rejections = geRejections($report);

    expect($rejections)->toBe([], 'the plugin export produced rejections: '.implode(' | ', $rejections));

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
    // back named. Nothing imports that file yet; this asserts the plugin wrote
    // it, which is the half this lane owns.
    $refunds = array_map('str_getcsv', file(geExportDir().'/refunds.csv', FILE_IGNORE_NEW_LINES));
    $row = array_combine($refunds[0], $refunds[1]);

    expect($row['order_id'])->toBe('10235');
    expect($row['amount'])->toBe('99.50');
    expect($row['refunded_items'])->toBe('5506:-1:-99.50');
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
    expect(geRejections($second))->toBe([]);

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

    expect(count($paths))->toBeGreaterThan(10);

    foreach ($paths as $path) {
        expect($guard->checkPath($path))->toBe("Path outside the permitted areas: {$path}");
    }

    $result = $guard->check($paths);

    expect($result['ok'])->toBeFalse();
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
            .' --storage=posts --out='.escapeshellarg($out).' --db=kbb_ge_wp --batch=2 --flip_after=1 2>&1',
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
 * Needs MySQL and a `kbb_ge_wp` database, so it skips where those are absent.
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
            .' --db=kbb_ge_wp --batch=7 2>&1';

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
