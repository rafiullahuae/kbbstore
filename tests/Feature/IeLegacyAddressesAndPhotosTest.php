<?php

/*
 * LANE IE — the two things a WordPress shop carries that this migration had
 * never read, proved end to end on the plugin's own export.
 *
 * ============================================================================
 * 1. `_wp_old_slug` — EVERY ADDRESS THE OLD SHOP USED TO SERVE
 * ============================================================================
 *
 * The owner's requirement, in his words: on import the system should convert to
 * the new slugs, KEEP THE OLD RECORD, and a visitor arriving from Google on an
 * old URL must be redirected to the correct new one instead of a not-found
 * page.
 *
 * `permalinks.csv` already carried every address the old site serves TODAY, and
 * `RedirectMap` already turned the ones that moved into 301s. What neither had
 * was the addresses the old site USED to serve and no longer does.
 *
 * WordPress records those itself. Change a published post's slug and core
 * writes a `_wp_old_slug` meta row; `wp_old_slug_redirect()` then answers the
 * old address with a 301 on every front-end 404. So a product renamed in 2021
 * has been quietly redirecting ever since — Google still holds the old
 * address, and nobody has noticed, because it works.
 *
 * IT WORKS BECAUSE WORDPRESS IS RUNNING. Switch the old shop off without
 * carrying those rows and every one of those addresses becomes a hard 404 on
 * day one, and the list cannot be recovered afterwards: it only ever existed in
 * the database that was turned off. `_wp_old_slug` appeared nowhere in this
 * repository — not in the plugin, not in the importer, not in a document.
 *
 * ============================================================================
 * 2. `reviews.images` — THE COLUMN NOTHING HAS EVER FILLED
 * ============================================================================
 *
 * A `json` column from the first schema migration, cast on the model, drawn by
 * the product page with a "+n" chip, filtered on by the review wall,
 * scheme-checked by `ReviewWall::photos()`. All of it built and tested, and on
 * an imported shop it renders nothing, because the only writer was a shopper
 * uploading to the NEW site.
 *
 * A photograph is the part of a review a shop cannot re-create. The owner can
 * retype a review; he cannot retype a customer's picture of her own face.
 *
 * ── NO TEST DOUBLES ─────────────────────────────────────────────────────────
 *
 * Everything below runs on `tests/Fixtures/kbb-export/`, which is the WordPress
 * plugin's own output over WordPress-shaped MySQL tables, through
 * `App\Services\Import\ImportRunner` — the class `kbb:import` runs — and, for
 * the 301s, through the real HTTP kernel.
 */

use App\Models\Product;
use App\Models\Redirect;
use App\Models\Review;
use App\Services\Import\RedirectMap;
use App\Models\AdminUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Tests\Support\UrlsMediaAdminRoutes;

/** The export the plugin wrote. Same directory GeWpExporterTest drives. */
function ieExportDir(): string
{
    return base_path('tests/Fixtures/kbb-export');
}

/** @return list<array<string, string>> */
function iePermalinkRows(): array
{
    $rows = array_map('str_getcsv', file(ieExportDir().'/permalinks.csv', FILE_IGNORE_NEW_LINES));
    $header = array_shift($rows);

    return array_map(static fn (array $row): array => array_combine($header, $row), $rows);
}

/** Only the rows this lane added: a previous address of something. */
function ieOldSlugRows(): array
{
    return array_values(array_filter(
        iePermalinkRows(),
        static fn (array $row): bool => $row['status'] === 'old-slug',
    ));
}

/**
 * One request through the real kernel, with the path spelled EXACTLY as given.
 *
 * ▲ NOT test()->get(). `MakesHttpRequests::prepareUrlForRequest()` ends in
 * `trim(url($uri), '/')`, so the harness STRIPS THE TRAILING SLASH: asking it
 * for `/product/vitamin-c-serum/` asks this application for
 * `/product/vitamin-c-serum`. Every address in this file is a WordPress
 * permalink and carries the slash, and `CheckRedirects` compares against
 * `getPathInfo()`. Written the convenient way every assertion here reads 404
 * for a shop that is behaving correctly. `RedirectMiddlewareTest::rmFetch()`
 * and `GpAddressesLandTest::gpFetch()` exist for exactly this reason.
 */
function ieFetch(string $path): \Symfony\Component\HttpFoundation\Response
{
    return app(\Illuminate\Contracts\Http\Kernel::class)->handle(
        \Illuminate\Http\Request::create('http://localhost'.$path, 'GET'),
    );
}

/**
 * The import, then the redirect map written — which is the real running order.
 *
 * `kbb:import-redirects` is a separate command on purpose: it reads the rows
 * the import produced to work out where an old address should now point, so it
 * cannot run first.
 */
function ieImportAndMapAddresses(): array
{
    $manifest = json_decode((string) file_get_contents(ieExportDir().'/manifest.json'), true);

    (new App\Services\Import\ImportRunner)->run(new App\Services\Import\ImportOptions(
        directory: ieExportDir(),
        sourceTimezone: $manifest['source']['timezone'],
        adoptBySlug: true,
    ));

    $proposals = (new RedirectMap)->propose(iePermalinkRows());

    foreach ($proposals as $proposal) {
        if ($proposal['decision'] !== RedirectMap::MIGRATE) {
            continue;
        }

        Redirect::query()->updateOrCreate(
            ['source' => $proposal['source']],
            ['target' => $proposal['target'], 'code' => 301, 'enabled' => true, 'auto_created' => true],
        );
    }

    // CheckRedirects memoises the source index. Written rows are invisible to
    // it otherwise, and the failure looks exactly like "the row was never
    // written" — which is the wrong thing to go and debug.
    Cache::flush();

    return $proposals;
}

/* ══════════════════════════════════════════ 1. the export carries them at all */

it('carries every previous address, products included', function () {
    /*
     * THE DEFECT THIS CAUGHT, IN THIS LANE, BEFORE IT SHIPPED.
     *
     * The old-slug query first excluded `KBB_Export_Stage_Posts::NOT_CONTENT`
     * verbatim, which reads like the obviously right list and is not.
     * NOT_CONTENT answers a DIFFERENT question — it is posts.csv's list of
     * "post types this file does not carry" — and `product` is in it because
     * products.csv carries products, not because a product has no address.
     *
     * Used as-is it dropped EVERY PRODUCT RENAME: the largest source of old
     * addresses on a six-year-old catalogue, and the most valuable, because a
     * renamed product's old URL is the one on Google and in other people's
     * blog posts. The fixture's two rows for product 4021 came out of the
     * export and the other two did not.
     *
     * MUTATION NOTE, RUN: delete the `'product' !== $type` guard in
     * `KBB_Export_Stage_Permalinks::not_content_list()`, regenerate the
     * fixture, and this test goes red naming `product 4021` twice.
     */
    $rows = ieOldSlugRows();

    $addresses = array_map(
        static fn (array $row): string => $row['type'].' '.$row['wc_id'].' '.$row['slug'],
        $rows,
    );

    expect(str_contains(implode("\n", $addresses), 'product 4021 vitamin-c-serum'))->toBeTrue(
        'the export dropped a product rename. These are the old addresses Google holds for the catalogue; '
        .'what came out was: '.implode(', ', $addresses),
    );

    // TWO renames of one product, not one. A reconstruction keyed on the post
    // id rather than on the meta row emits one and loses the other, and the
    // loss is invisible because the file still has a row for that product.
    expect(str_contains(implode("\n", $addresses), 'product 4021 ginseng-elixir'))->toBeTrue(
        'the second rename of product 4021 is missing: one row per rename, not one row per product',
    );

    expect(count($rows))->toBe(4, 'the fixture carries four previous addresses: '.implode(', ', $addresses));
});

it('reconstructs a previous address from the one WordPress reports, not from a base', function () {
    /*
     * `_wp_old_slug` stores a SLUG. Turning it back into the address the site
     * served means knowing the base it sat under, and this plugin's whole
     * reason for existing is that it does not guess at bases — the brand
     * archive note in its own header is there because guessing one would have
     * written 93 redirects from an address that may never have existed.
     *
     * So it does not assemble a URL. It takes `get_permalink()`'s answer for
     * the post and swaps the LAST path segment.
     *
     * The CHILD PAGE is what makes that a measurement rather than a
     * description. `team` is a child of `about-us`, so its address is
     * /about-us/team/ and its previous address is /about-us/our-team/.
     * Anything that rebuilds the URL from a base — the obvious
     * implementation — produces /our-team/, which this shop does not serve and
     * the old site never did.
     *
     * MUTATION NOTE, RUN: make `swap_last_segment()` return
     * '/'.$to.'/' prefixed with the host instead of replacing the last
     * segment, regenerate, and this goes red with /our-team/.
     */
    $byOld = [];

    foreach (ieOldSlugRows() as $row) {
        $byOld[$row['slug']] = $row;
    }

    expect($byOld)->toHaveKey('our-team');
    expect($byOld['our-team']['permalink'])->toBe('https://kbeautybliss.com/about-us/our-team/');

    // A product sits under its WooCommerce product base, which this shop's
    // export measured as `/product` rather than assuming it.
    expect($byOld['vitamin-c-serum']['permalink'])->toBe('https://kbeautybliss.com/product/vitamin-c-serum/');

    // An article has no base at all under /%postname%/.
    expect($byOld['k-beauty-layering']['permalink'])->toBe('https://kbeautybliss.com/k-beauty-layering/');

    // And every one of them says `derived`, never `wp`. get_permalink() was
    // asked about the CURRENT address; the old one was computed from it. The
    // column means "this was measured" and this was not.
    foreach (ieOldSlugRows() as $row) {
        expect($row['source'])->toBe('derived');
    }
});

it('does not offer an attachment\'s previous address as a page redirect', function () {
    /*
     * The fixture gives attachment 9001 a `_wp_old_slug` too, because a media
     * library that has been tidied has hundreds of them.
     *
     * An attachment's address is a picture, not a page this shop serves. A row
     * for one would propose a redirect FROM an image URL, and `RedirectMap`
     * would resolve `wc_id` 9001 against nothing and file it as a question the
     * owner has to read and answer — hundreds of them, about files.
     *
     * MUTATION NOTE, RUN: remove `attachment` from the exclusion (it arrives
     * through NOT_CONTENT), regenerate, and this goes red.
     */
    $ids = array_map(static fn (array $row): string => $row['wc_id'], ieOldSlugRows());

    expect(in_array('9001', $ids, true))->toBeFalse(
        'an attachment\'s previous address is in permalinks.csv; a redirect from a picture points nowhere useful',
    );
});

/* ═════════════════════════════════════ 2. a visitor from Google gets a 301 */

it('answers a product\'s previous address with a 301 to where the product lives now', function () {
    /*
     * THE OWNER'S REQUIREMENT, END TO END AND THROUGH THE REAL KERNEL.
     *
     * Before this lane the request below reached the storefront and 404'd:
     * nothing in the export carried the address, so nothing could write the
     * row, so `CheckRedirects` had nothing to match.
     *
     * MUTATION NOTE, RUN: delete the `old_slug` entry from
     * `KBB_Export_Stage_Permalinks::sources()`, regenerate the fixture, and
     * this goes red with 404 — which is exactly what a shopper arriving from
     * Google would have seen.
     */
    ieImportAndMapAddresses();

    $product = Product::query()->where('wc_id', 4021)->first();

    expect($product)->not->toBeNull('the fixture\'s serum did not import, so this proves nothing about addresses');

    $response = ieFetch('/product/vitamin-c-serum/');

    expect($response->getStatusCode())->toBe(
        301,
        'a previous address of the serum answered '.$response->getStatusCode().'. This is the address Google '
        .'holds; WordPress has been 301ing it for years and that stops at the cutover.',
    );

    expect((string) $response->headers->get('Location'))->toEndWith('/product/'.$product->slug.'/');

    // The SECOND rename of the same product, which is the one a
    // one-row-per-product reconstruction loses.
    $second = ieFetch('/product/ginseng-elixir/');

    expect($second->getStatusCode())->toBe(301);
    expect((string) $second->headers->get('Location'))->toEndWith('/product/'.$product->slug.'/');
});

it('answers a child page\'s previous address without flattening it', function () {
    ieImportAndMapAddresses();

    /*
     * /about-us/our-team/ is the address, and the one thing that must not
     * happen is that it resolves as though it were /our-team/. This shop
     * serves single-segment addresses through a catch-all that looks for a
     * BLOG POST (see App\Support\LegacyCategoryUrls on how that turned fifteen
     * menu links into 404s), so a flattened old address is not merely wrong,
     * it is wrong in a way that looks like a missing article.
     */
    $rows = ieOldSlugRows();
    $ourTeam = null;

    foreach ($rows as $row) {
        if ($row['slug'] === 'our-team') {
            $ourTeam = $row;
        }
    }

    expect($ourTeam)->not->toBeNull();

    $proposal = null;

    foreach ((new RedirectMap)->propose([$ourTeam]) as $candidate) {
        $proposal = $candidate;
    }

    expect($proposal['source'])->toBe(
        '/about-us/our-team/',
        'the child page\'s previous address was flattened to a root-level one',
    );
});

it('counts how many previous addresses the export carried and how many resolved', function () {
    /*
     * THE NUMBER, not the adjective. "Old URLs are handled" is a claim nobody
     * can check; "four carried, three resolved, one asked about, and here is
     * which" is a report.
     *
     * This is also the shape of the answer the owner gets on the real
     * catalogue, where the numbers are in the hundreds.
     */
    ieImportAndMapAddresses();

    $rows = ieOldSlugRows();

    /*
     * `propose()` is given the old-slug rows and answers about MORE than them:
     * it also re-derives the flat WooCommerce category addresses and the
     * category nesting from the database, because those come from rows rather
     * than from a file. Counting everything it returns would count those too
     * and say nothing about this lane.
     *
     * So the count is taken on the SOURCES this lane put in the file. Written
     * the loose way this test read "28 proposals for 4 addresses" and passed
     * for the wrong reason.
     */
    $wanted = [];

    foreach ($rows as $row) {
        $wanted[(string) parse_url($row['permalink'], PHP_URL_PATH)] = true;
    }

    $proposals = array_values(array_filter(
        (new RedirectMap)->propose($rows),
        static fn (array $p): bool => isset($wanted[$p['source']]),
    ));

    $byDecision = ['migrate' => 0, 'ask' => 0, 'discard' => 0];

    foreach ($proposals as $proposal) {
        $byDecision[$proposal['decision']]++;
    }

    expect(count($rows))->toBe(4);
    expect(count($proposals))->toBe(
        4,
        'a previous address that produces no proposal at all is one this shop will 404 with nothing said about it; '
        .'got '.count($proposals).' for '.count($rows).' addresses',
    );

    /*
     * Three of the four migrate, and they are measured rather than described:
     *
     *   /product/vitamin-c-serum/  -> /product/serum-4021/
     *   /product/ginseng-elixir/   -> /product/serum-4021/
     *   /k-beauty-layering/        -> /how-to-layer-a-k-beauty-routine/
     *
     * THE FOURTH IS THE PAGE, /about-us/our-team/, and it is an `ask` with the
     * question `not-imported` — which is correct, and is the interesting one.
     * `PostImporter` refuses a WordPress page BY NAME, because this shop ships
     * its own /about/, /delivery/, /faqs/, /privacy-policy/ and
     * /terms-and-conditions/ and which of the two he wants is a content
     * decision rather than a mapping. So there is no row for the map to point
     * at, and it will not invent one.
     *
     * That address therefore 404s, and it is the SHAPE OF THE ONE THING THIS
     * LANE DOES NOT FIX: a previous address of a page whose page this shop
     * declines to import. It is asked about by name rather than lost, which is
     * the whole difference — the owner answering a handful of questions is the
     * design; the owner never being asked is the defect.
     */
    expect($byDecision['migrate'])->toBeGreaterThanOrEqual(
        3,
        'previous addresses that this shop can resolve on its own: '.json_encode($byDecision),
    );

    // Every proposal names its subject, so a question is answerable.
    foreach ($proposals as $proposal) {
        expect($proposal['subject'])->not->toBe('');
    }
});

/* ═══════════════════════════════════════════ 3. the shopper's photographs */

it('lands a shopper\'s review photographs, which no export has ever carried', function () {
    /*
     * MUTATION NOTE, RUN: remove `'images'` from
     * `KBB_Export_Stage_Reviews::columns()`, regenerate the fixture, and this
     * goes red with an empty array — the exact state every imported shop was
     * in before this lane.
     *
     * Remove the `$attributes['images']` assignment in `ReviewImporter` and it
     * goes red the same way with the column still in the file, which is the
     * half the export alone does not prove.
     */
    ieImportAndMapAddresses();

    $review = Review::query()->where('source', 'wp_comment')->where('source_id', 8101)->first();

    expect($review)->not->toBeNull();

    $photos = $review->images;

    expect(is_array($photos))->toBeTrue();
    /*
     * THREE SINCE LANE IE2. The fixture shop gave Layla's review a photograph of
     * her own face that nothing else on the shop references, because the two
     * serum shots are also product images and so reached media.csv whether or
     * not anything read reviews. The third is the one that proves it did.
     */
    expect(count($photos))->toBe(
        3,
        'Layla uploaded two photographs of the serum and one of herself, and the shop imported '.count($photos ?: []),
    );

    expect($photos[0])->toBe('https://kbeautybliss.com/wp-content/uploads/2019/03/ginseng-serum.jpg');
    expect($photos[1])->toBe('https://kbeautybliss.com/wp-content/uploads/2019/03/ginseng-serum-2.jpg');
    expect($photos[2])->toBe('https://kbeautybliss.com/wp-content/uploads/2019/03/layla-selfie.jpg');

    // The OTHER storage shape: a PHP-serialised array of uploads URLs rather
    // than attachment ids, written http:// before the shop moved to https. A
    // reader that handled only one of the two shapes would be green here on
    // the fixture's first review and silent about every shop using the other.
    $older = Review::query()->where('source', 'wp_comment')->where('source_id', 8102)->first();

    // And the THIRD shape (Lane IE2): a path relative to the uploads root,
    // read as a photograph because the uploads directory holds the file. Its
    // neighbour `rp_missing` names a file that is not there and is left out.
    expect($older->images)->toBe([
        'http://kbeautybliss.com/wp-content/uploads/2020/01/skincare-category.jpg',
        'https://kbeautybliss.com/wp-content/uploads/2020/01/layla-review-2.jpg',
    ]);
});

it('refuses a review photograph that is not a picture this shop can serve', function () {
    /*
     * The fixture puts `https://evil.test/trophy.jpg` on Layla's review, under
     * a meta key a migration plugin might plausibly have written.
     *
     * TWO separate refusals have to hold, and they are in different places:
     *
     *  · THE EXPORT refuses it, because the address is not under the old site's
     *    own uploads directory. Without that check any meta value ending in
     *    `.jpg` becomes one of this shop's review photographs, and the new shop
     *    hotlinks a stranger's server from a product page.
     *
     *  · THE IMPORT refuses anything that is not http or https, because
     *    `/api/*` here is unauthenticated and `reviews.images` is read by four
     *    endpoints. A render-time filter protects the readers that remember to
     *    call it; a column that cannot hold an executable address protects the
     *    fifth reader somebody adds next year.
     *
     * MUTATION NOTE, RUN: drop the uploads-directory test from
     * `KBB_Export_Stage_Reviews::is_own_upload()` and regenerate — evil.test
     * appears in reviews.csv and this goes red. Separately, replace
     * `SafeUrl::src($part)` with `$part` in `ReviewImporter::photographs()` and
     * the second half goes red.
     */
    $reviews = array_map('str_getcsv', file(ieExportDir().'/reviews.csv', FILE_IGNORE_NEW_LINES));
    $header = array_shift($reviews);
    $images = '';

    foreach ($reviews as $row) {
        $row = array_combine($header, $row);

        if ($row['comment_id'] === '8101') {
            $images = $row['images'];
        }
    }

    expect(str_contains($images, 'evil.test'))->toBeFalse(
        'the export carried somebody else\'s server as one of this shop\'s review photographs: '.$images,
    );

    ieImportAndMapAddresses();

    $stored = json_encode(Review::query()->where('source', 'wp_comment')->pluck('images'));

    expect(str_contains((string) $stored, 'evil.test'))->toBeFalse();
    expect(str_contains((string) $stored, 'javascript:'))->toBeFalse();
});

it('does not wipe photographs it already imported when a later export carries none', function () {
    /*
     * THE REACHABLE CASE, AND IT IS THE ONE THE EXPORT ITSELF WARNS ABOUT.
     *
     * The first version of this test defended a scenario that cannot happen —
     * a shopper adding a photograph to an IMPORTED review. Nothing in this shop
     * does that: `Store\ReviewController` creates a NEW review with source
     * `kbb`, and no admin endpoint writes `images` on an existing one. A test
     * for an unreachable case is a test that can never go red for a real
     * defect, and it is left out rather than left in looking like cover.
     *
     * What IS reachable, and is the whole reason the reviews stage reports the
     * comment meta keys it did not use:
     *
     *   Review photographs are not WooCommerce core. The export recognises them
     *   by the VALUE — an attachment id, or an address under the old site's own
     *   uploads directory. A plugin storing a bare filename, or a path relative
     *   to the uploads root, produces NO match, and `images` comes out empty
     *   for a shop that visibly has photographs. The manifest names the keys so
     *   the answer is a key name rather than a discovery.
     *
     * Now put the two together. Somebody runs an export where the key IS
     * recognised, imports it, and the photographs land. Somebody later runs a
     * narrower export, or a delta, or one from a shop where the plugin was
     * changed — `images` is empty. The import is not one run; it is a full
     * import, a delta, a cutover delta on the night, and however many re-runs
     * it takes to get a mapping right.
     *
     * Written unconditionally, that second pass DELETES every photograph the
     * first one imported, silently, and the only evidence is a product page
     * with fewer pictures than yesterday. This is the same rule `SeoImporter`
     * applies to the barcodes: never write over something this shop already
     * holds with nothing.
     *
     * MUTATION NOTE, RUN: change the `if ($photographs !== [])` guard in
     * `ReviewImporter::import()` to assign unconditionally and this goes red
     * with an empty array.
     */
    ieImportAndMapAddresses();

    $review = Review::query()->where('source', 'wp_comment')->where('source_id', 8101)->first();

    expect(count((array) $review->images))->toBe(3, 'the first pass did not import the photographs');

    /*
     * The second export: identical in every way except that its `images`
     * column is blank — which is precisely what a shop whose photo plugin this
     * export does not recognise produces.
     */
    $second = sys_get_temp_dir().'/kbb-ie-blank-'.bin2hex(random_bytes(4));

    mkdir($second, 0755, true);

    foreach (glob(ieExportDir().'/*') as $file) {
        copy($file, $second.'/'.basename($file));
    }

    $rows = array_map('str_getcsv', file($second.'/reviews.csv', FILE_IGNORE_NEW_LINES));
    $header = $rows[0];
    $column = array_search('images', $header, true);

    expect($column)->not->toBeFalse('reviews.csv has no images column, so this test proves nothing');

    $handle = fopen($second.'/reviews.csv', 'wb');

    foreach ($rows as $index => $row) {
        if ($index > 0) {
            $row[$column] = '';
        }

        fputcsv($handle, $row);
    }

    fclose($handle);

    $manifest = json_decode((string) file_get_contents(ieExportDir().'/manifest.json'), true);

    (new App\Services\Import\ImportRunner)->run(new App\Services\Import\ImportOptions(
        directory: $second,
        sourceTimezone: $manifest['source']['timezone'],
        adoptBySlug: true,
    ));

    $review->refresh();

    expect(count((array) $review->images))->toBe(
        3,
        'a second export that did not recognise this shop\'s review-photo plugin deleted the photographs a '
        .'previous import had already landed',
    );
});

it('refuses an executable address in a reviews.csv that did not come from this plugin', function () {
    /*
     * ══════════════════════════════════════════════════════════════════════
     * THIS TEST EXISTS BECAUSE A MUTATION SURVIVED.
     * ══════════════════════════════════════════════════════════════════════
     *
     * The test above asserts that `evil.test` reaches neither the export nor
     * the database, and it passes. Replacing `SafeUrl::src($part)` with `$part`
     * in `ReviewImporter::photographs()` — deleting the importer's scheme check
     * outright — left it GREEN, and left the whole suite green.
     *
     * The reason is the export is too good. It refuses an offsite address at
     * source, so on the plugin's own output nothing bad ever reaches the
     * importer, and an assertion phrased "nothing bad is in the database" is
     * satisfied by the exporter alone. Two defences, one of them asserted, and
     * no way to tell which.
     *
     * ── AND THE UNASSERTED ONE IS THE ONE THAT MATTERS MOST ────────────────
     *
     * `reviews.csv` does not only arrive from this plugin. `ImportRunner` reads
     * whatever directory it is pointed at; the shop ALSO takes a reviews file
     * through Store → Import as an upload, and through Store → Reviews →
     * Import. A file from an older exporter, a different plugin, or a
     * spreadsheet somebody edited is the ordinary case, not the exotic one.
     *
     * `/api/*` on this shop is unauthenticated and `reviews.images` is read by
     * four endpoints. CLAUDE.md's rule is that a URL arriving from data is
     * scheme-checked before it becomes an `href` or a `src`, and a render-time
     * filter only protects the readers that remember to call it.
     *
     * MUTATION NOTE, RUN: replace `SafeUrl::src($part)` with `$part` in
     * `ReviewImporter::photographs()` and this goes red with the
     * `javascript:` address in the column. It is the mutation that survived
     * everything else in this file.
     */
    $dir = sys_get_temp_dir().'/kbb-ie-hostile-'.bin2hex(random_bytes(4));

    mkdir($dir, 0755, true);

    foreach (glob(ieExportDir().'/*') as $file) {
        copy($file, $dir.'/'.basename($file));
    }

    $rows = array_map('str_getcsv', file($dir.'/reviews.csv', FILE_IGNORE_NEW_LINES));
    $header = $rows[0];
    $column = array_search('images', $header, true);

    $handle = fopen($dir.'/reviews.csv', 'wb');

    foreach ($rows as $index => $row) {
        if ($index > 0) {
            // A file this plugin did not write: an executable address, a
            // protocol-relative one carrying somebody else's host, and one
            // real picture, so the row is not simply discarded whole.
            $row[$column] = 'javascript:alert(document.cookie)'
                .'|//evil.test/x.png'
                .'|https://kbeautybliss.com/wp-content/uploads/2019/03/ginseng-serum.jpg';
        }

        fputcsv($handle, $row);
    }

    fclose($handle);

    $manifest = json_decode((string) file_get_contents(ieExportDir().'/manifest.json'), true);

    (new App\Services\Import\ImportRunner)->run(new App\Services\Import\ImportOptions(
        directory: $dir,
        sourceTimezone: $manifest['source']['timezone'],
        adoptBySlug: true,
    ));

    // JSON_UNESCAPED_SLASHES, and not decoration: json_encode() writes `\/` for
    // every slash by default, so a str_contains() for a PATH silently never
    // matches and the assertion below passes for a shop that dropped the
    // photograph. Caught by the assertion going red on correct behaviour.
    $stored = (string) json_encode(
        Review::query()->where('source', 'wp_comment')->pluck('images'),
        JSON_UNESCAPED_SLASHES,
    );

    expect(str_contains($stored, 'javascript:'))->toBeFalse(
        'an executable address reached reviews.images, which four endpoints read and one of them is '
        .'unauthenticated: '.$stored,
    );

    // A protocol-relative address carries no scheme and is somebody else's
    // host wearing this page's. `SafeUrl` refuses it for that reason, and it
    // is the spelling a check written as "does it start with javascript:"
    // lets straight through.
    expect(str_contains($stored, 'evil.test'))->toBeFalse(
        'a protocol-relative offsite address reached reviews.images: '.$stored,
    );

    // AND THE REAL PICTURE IN THE SAME CELL STILL LANDS. A refusal that threw
    // the whole row away would pass both assertions above and lose a
    // photograph for every review with one bad entry, which is a different
    // defect wearing a green test.
    expect(str_contains($stored, '/2019/03/ginseng-serum.jpg'))->toBeTrue(
        'the good photograph in the same cell was discarded along with the bad ones: '.$stored,
    );
});

/* ══════════════════════════════════ 4. the navigation, named as what it is */

it('says what it did with the navigation menu, with a count, instead of calling it plumbing', function () {
    /*
     * `docs/GQ-MIGRATION-COMPLETENESS.md` §4.4 lists `menus` and `menu_items`
     * among the tables a clean import leaves at exactly the count they had, and
     * says the loss is "counted in the manifest notes with its row count".
     *
     * THAT SENTENCE HAD NEVER BEEN TRUE OF ANYTHING. The note it refers to only
     * fires on a shop that HAS a menu, and this fixture had none — no
     * `nav_menu` term, no `nav_menu_item` post — so in every run of this suite
     * the note listed `attachment` and stopped. Nobody had read the sentence it
     * produces for a menu.
     *
     * When the fixture was given one, it produced this:
     *
     *     posts.csv does not carry these WordPress post types, WHICH ARE EITHER
     *     ANOTHER FILE'S JOB OR WORDPRESS'S OWN MACHINERY: 6 attachment,
     *     3 nav_menu_item.
     *
     * Both halves of that sentence are false about `nav_menu_item`. No file
     * carries it, and it is not machinery: it is the header and the mobile
     * drawer — which categories, in which order, under which names. The owner
     * reading his one approved discard list is told his navigation is a cache.
     *
     * GQ §5.4 already states the rule this breaks: "A discard list with false
     * entries is worse than a shorter one — he cannot tell which of the seven
     * matters, so he stops reading all seven."
     *
     * MUTATION NOTE, RUN: delete the `$this->report_navigation();` call from
     * `KBB_Export_Stage_Posts::report_skipped_types()`, regenerate the fixture,
     * and this goes red — the export goes back to describing the owner's
     * navigation as WordPress's own machinery and saying nothing else about it.
     */
    /*
     * ── AND AT PLUGIN 1.7.0 THE LOSS ITSELF WENT AWAY (Lane MN) ────────────
     *
     * Everything above is the history and is left as written: it is why the
     * note exists. What it asserted -- that the export names the navigation as
     * a LOSS, with a count of how much retyping -- is now false, because
     * menus.csv and menu_items.csv carry it and `MenuImporter` /
     * `MenuItemImporter` read it.
     *
     * The same rule decides what this test does next. A note is worth having
     * only if every line in it is true, so the assertion moves with the fact
     * rather than being deleted: the export must still say what it did with the
     * navigation, with a count, and the tables that used to be asserted EMPTY
     * are now asserted to FILL. Deleting the test would leave the one channel
     * the owner approves free to go quiet about his header again.
     *
     * MUTATION NOTE, RUN: delete `$this->report_totals();` from
     * `KBB_Export_Stage_Menu_Items::batch()`, regenerate the fixture, and this
     * goes red -- the export carries the navigation and says nothing about it,
     * which is the same defect as saying the wrong thing.
     */
    $manifest = json_decode((string) file_get_contents(ieExportDir().'/manifest.json'), true);

    $navigation = '';

    foreach ($manifest['notes'] as $note) {
        if (str_contains($note, 'THE NAVIGATION')) {
            $navigation = $note;
        }
    }

    expect($navigation)->not->toBe(
        '',
        'the export says nothing at all about the navigation. Either it is carried and the note says so, or '
        .'it is not and the note says how much retyping that is -- silence is the one answer that leaves the '
        .'owner reading a discard list that does not mention his header.',
    );

    // The COUNT, which is the whole usefulness of the note either way.
    expect(str_contains($navigation, '7 menu items across 1 menu'))->toBeTrue(
        'the navigation note carries no count: '.$navigation,
    );

    // And the orphan, which is the one nav_menu_item that is deliberately NOT
    // exported: its term relationship is gone, so it belongs to no menu and
    // WordPress renders it nowhere either.
    expect(str_contains($navigation, 'belongs to no menu'))->toBeTrue(
        'the note does not account for the menu items it left behind: '.$navigation,
    );

    // The posts stage must have STOPPED calling it machinery, which was the
    // original finding and is the half that would otherwise still be false.
    $discardList = '';

    foreach ($manifest['notes'] as $note) {
        if (str_contains($note, 'does not carry these WordPress post types')) {
            $discardList = $note;
        }
    }

    expect(str_contains($discardList, 'nav_menu_item'))->toBeFalse(
        'posts.csv\'s discard list still counts nav_menu_item among "another file\'s job or WordPress\'s '
        .'own machinery", on an export that carries it in menu_items.csv: '.$discardList,
    );

    /*
     * AND THE TABLES REALLY DO FILL, asserted rather than described. This test
     * asserted the opposite for as long as the opposite was true; a note
     * claiming the menu is imported, on a shop where nothing imported it, is
     * the same defect pointing the other way.
     */
    $before = [
        'menus' => \App\Models\Menu::query()->count(),
        'menu_items' => \DB::table('menu_items')->count(),
    ];

    ieImportAndMapAddresses();

    expect(\App\Models\Menu::query()->count())->toBe($before['menus'] + 1);
    expect(\DB::table('menu_items')->count())->toBe($before['menu_items'] + 7);
});

it('reports the same photograph keys however many requests the export takes', function () {
    /*
     * ══════════════════════════════════════════════════════════════════════
     * A BATCH IS A SEPARATE HTTP REQUEST, AND THIS NOTE IS WHY THAT MATTERS.
     * ══════════════════════════════════════════════════════════════════════
     *
     * The unused-key note is the only thing that will tell the owner his
     * review-photo plugin was not recognised. It is the whole safety net under
     * "recognise a photograph by its value rather than by a key we remember".
     *
     * The obvious way to build it is to tally the keys as the batches go by.
     * That is wrong here, and wrong in a way this fixture could never show.
     * `harness/run-export.php` states the premise: "Every batch is a separate
     * call into the runner that reloads its state from the options table,
     * exactly as a separate HTTP request would." An instance property does not
     * survive that — so an accumulating tally names only the keys seen in the
     * LAST request. On 2,514 reviews at 500 rows a batch, that is the last 14.
     *
     * And it would have been green forever, because the fixture's two reviews
     * fit in one batch at every batch size the other tests use.
     *
     * So the note is RECOMPUTED from the database at the end, the way
     * report_unrated() above it already is, and this asserts the property that
     * makes the difference visible: one row per request must say exactly what
     * everything-in-one says.
     *
     * MUTATION NOTE, RUN: tally the keys on instance properties across
     * batch() instead of recomputing, and the batch=1 run reports a different
     * set of keys from the batch=500 run — which is the live site's behaviour
     * and the fixture's, disagreeing.
     */
    $script = base_path('wordpress-plugin/harness/run-export.php');
    $db = getenv('KBB_WP_DB') ?: 'kbb_ge_wp';
    $out = sys_get_temp_dir().'/kbb-ie-batches-'.bin2hex(random_bytes(4));

    $notes = [];

    foreach ([1, 500] as $batch) {
        $lines = [];

        exec(
            escapeshellcmd(PHP_BINARY).' '.escapeshellarg($script)
            .' --storage=posts --out='.escapeshellarg($out.'/'.$batch)
            .' --db='.$db.' --batch='.$batch.' 2>&1',
            $lines,
            $status
        );

        if ($status === 3) {
            $this->markTestSkipped('no MySQL here: '.implode(' ', $lines));
        }

        expect($status)->toBe(0, 'the harness export failed: '.implode("\n", $lines));

        $manifest = json_decode((string) file_get_contents($out.'/'.$batch.'/export/manifest.json'), true);

        $notes[$batch] = array_values(array_filter(
            $manifest['notes'],
            static fn (string $note): bool => str_contains($note, 'comment meta key'),
        ));
    }

    expect($notes[1])->toBe(
        $notes[500],
        'the export names different review-photo meta keys depending on how many requests it took. The note is '
        .'the only thing that tells the owner his photo plugin was not recognised, and on the live site every '
        .'batch is a separate request.',
    );

    // And it must actually SAY something, or the assertion above is two empty
    // arrays agreeing.
    expect(count($notes[1]))->toBe(2, 'the keys taken and the keys left behind are both named: '.json_encode($notes[1]));
});

it('leaves the pictures this change is accounted for with', function () {
    /*
     * The account in docs/IE-IMPORT-READINESS.md §6 is about what the owner
     * SEES on Tools → KBB Export, and a written claim about a screen is worth
     * what the picture beside it is worth.
     *
     * Regenerated by tests/browser/ie-export-screen.mjs, which renders the
     * screen with harness/screen.php — KBB_Export_Admin::screen() writes the
     * markup, nothing about the page is reconstructed — and draws the REAL
     * notes from tests/Fixtures/kbb-export/manifest.json into #kbb-notes. Only
     * the ajax transport is stood in for, which is what
     * harness/screen-drive.mjs does and for the same reason.
     *
     * This fails if the pictures were never committed, which is the state in
     * which the document describes a screen nobody has looked at.
     */
    foreach (['export-screen-390.png', 'export-screen-1280.png'] as $shot) {
        $path = base_path('docs/lane-ie-shots/'.$shot);

        expect(is_file($path))->toBeTrue("docs/lane-ie-shots/{$shot} is missing");
        expect(filesize($path))->toBeGreaterThan(1000, "docs/lane-ie-shots/{$shot} is empty");
    }
});

it('offers the previous addresses on the screen too, not only to the shell', function () {
    /*
     * ══════════════════════════════════════════════════════════════════════
     * THE DOOR THE OWNER ACTUALLY USES.
     * ══════════════════════════════════════════════════════════════════════
     *
     * The 301 test above goes through `kbb:import-redirects --permalinks=…`,
     * and that flag is the part of this lane most likely to be forgotten: the
     * runbook's copy-pasteable commands did not carry it, because until
     * exporter 1.6.0 the file only held addresses the map could re-derive
     * anyway. Now it holds addresses that exist NOWHERE ELSE, so a run without
     * the flag reads none of them, reports a smaller map, and says nothing
     * about it. The runbook is corrected; this pins the other door.
     *
     * `docs/GP-ADDRESSES-LAND.md` §2.1 is the reason that door matters, and it
     * is worth quoting because it is the same failure one level up:
     * `RedirectMap::fromPermalinks()` "has read this exact file shape since the
     * day it was written, and NOTHING WITH A SCREEN HAD EVER HANDED IT A FILE."
     *
     * So: upload `permalinks.csv` the way the screen does, and ask the screen's
     * own endpoint. If the old-slug rows reach the proposals, the owner gets
     * them without a shell.
     *
     * MUTATION NOTE, RUN: remove the `old_slug` source from the permalinks
     * stage, regenerate, and this goes red alongside the 301 test — the screen
     * offers nothing it was not given.
     */
    UrlsMediaAdminRoutes::wire($this->app);

    $this->actingAs(AdminUser::create([
        'name' => 'Addresses Owner',
        'email' => 'ie-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]), 'admin');

    ieImportAndMapAddresses();

    $temp = sys_get_temp_dir().'/kbb-ie-'.getmypid().'-'.bin2hex(random_bytes(4)).'-permalinks.csv';

    copy(ieExportDir().'/permalinks.csv', $temp);

    $this->postJson('/admin-api/import/upload', [
        'file' => new UploadedFile($temp, 'permalinks.csv', null, null, true),
    ])->assertOk();

    $status = $this->getJson('/admin-api/urls-media/status')->assertOk()->json();

    expect($status['sources']['permalinks']['present'])->toBeTrue();

    // JSON_UNESCAPED_SLASHES again, and it caught me twice in this file: without
    // it json_encode() writes `\/` and a str_contains() for a PATH never matches,
    // so the assertions below would read "the screen offers nothing" about a
    // screen that offers everything.
    $offered = (string) json_encode($status, JSON_UNESCAPED_SLASHES);

    // The renamed product's old address, offered by the screen with no shell
    // anywhere in the path.
    expect(str_contains($offered, '/product/vitamin-c-serum/'))->toBeTrue(
        'the screen does not offer the previous addresses the export carried, so an owner with no shell never '
        .'sees them',
    );

    expect(str_contains($offered, '/product/ginseng-elixir/'))->toBeTrue(
        'the second rename is missing from the screen',
    );

    @unlink($temp);
});
