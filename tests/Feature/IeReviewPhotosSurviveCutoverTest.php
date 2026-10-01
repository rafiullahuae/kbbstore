<?php

declare(strict_types=1);

/*
 * THE CUSTOMERS' PHOTOGRAPHS SURVIVE THE CUTOVER. (Lane IE2)
 *
 * ============================================================================
 * THE DEFECT: EXPORTED, IMPORTED, AND STILL DEAD THE DAY WORDPRESS IS TURNED
 * OFF
 * ============================================================================
 *
 * The export has carried `reviews.images` since the photographs were first
 * recognised, and `ReviewImporter` has written them into `reviews.images` on
 * this shop. Both of those are ADDRESSES ON THE OLD SITE —
 * `https://kbeautybliss.com/wp-content/uploads/…` — exactly as
 * `products.image` is, and for exactly the same reason: the CSV export moves
 * the catalogue, and `MediaSideloader` moves the FILES afterwards, while the
 * old site is still up.
 *
 * `MediaSideloader` fetches what `MediaAudit::references()` yields. That method
 * walked products, brands, categories, articles and two settings — and NOT
 * reviews. `MediaRewrite::COLUMNS`, which re-points a fetched file's URL at
 * this shop, did not list `reviews.images` either.
 *
 * So the whole chain ran and reported success with every customer photograph
 * still served by WordPress:
 *
 *   · `kbb:import-media` counted them nowhere and fetched none of them;
 *   · `remote` — the number the runbook tells the owner to watch to zero
 *     before he switches the old shop off — reached ZERO with them all still
 *     remote;
 *   · and `kbb:import-media-rewrite` had nothing to re-point, so even a file
 *     copied across by hand would not have been the file the page asked for.
 *
 * On the day the old shop is switched off, every review photograph on this
 * shop becomes a broken image. IT IS THE ONE PICTURE ON A SHOP THAT CANNOT BE
 * RE-CREATED: the owner can retype a review; he cannot retype a customer's
 * photograph of her own face.
 *
 * ── AND media.csv DID NOT LIST THEM EITHER ─────────────────────────────────
 *
 * `media.csv` is the export's answer to "what does the new shop have to
 * fetch", taken from the old shop while it is still up. Its stage walked
 * products, categories, brands and articles. A customer's photograph is
 * referenced by none of those, so it was in no row of the one file whose whole
 * job is to name the files to fetch.
 *
 * ── THE FALSE GREEN THIS FILE IS WRITTEN AGAINST ───────────────────────────
 *
 * "Photographs are exported" is satisfiable by an empty column, and it was
 * nearly satisfiable by a fixture in which every review photograph was also a
 * product photograph — which is what the fixture used to be, and why this hole
 * stayed invisible. The shop now carries `layla-selfie.jpg`, referenced by
 * NOTHING but a review, and every count below is a real count of real rows.
 */

use App\Models\Review;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportRunner;
use App\Services\Import\MediaAudit;
use App\Services\Import\MediaRewrite;
use App\Services\Import\MediaSideloader;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** The export the plugin wrote. Same directory GeWpExporterTest drives. */
function irpExportDir(): string
{
    return base_path('tests/Fixtures/kbb-export');
}

function irpImport(): void
{
    $manifest = json_decode((string) file_get_contents(irpExportDir().'/manifest.json'), true);

    (new ImportRunner)->run(new ImportOptions(
        directory: irpExportDir(),
        sourceTimezone: $manifest['source']['timezone'],
        adoptBySlug: true,
    ));
}

/** @return list<array<string, string>> */
function irpMediaRows(): array
{
    $rows = array_map('str_getcsv', file(irpExportDir().'/media.csv', FILE_IGNORE_NEW_LINES));
    $header = array_shift($rows);

    return array_map(static fn (array $row): array => array_combine($header, $row), $rows);
}

/** Layla's own photograph: referenced by no product, category, brand or article. */
const IRP_SELFIE = 'https://kbeautybliss.com/wp-content/uploads/2019/03/layla-selfie.jpg';

/** Her second, stored by its PATH relative to the uploads root. */
const IRP_RELATIVE = 'https://kbeautybliss.com/wp-content/uploads/2020/01/layla-review-2.jpg';

/* ───────────────────────── the export names the files ──────────────────── */

it('names every review photograph in media.csv, which is the download list', function () {
    /*
     * media.csv is the file the new shop's downloader works from. A photograph
     * named in reviews.csv and absent here is a photograph nobody ever fetches.
     *
     * MUTATION NOTE. Remove 'review' from the `$this->sources` array in
     * class-kbb-export-stage-media.php, re-run
     * wordpress-plugin/harness/run-export.php, and this is red: no row of
     * media.csv has referenced_by = review and Layla's photograph of herself
     * is in no row at all. RUN: red.
     */
    $rows = irpMediaRows();

    $reviewRows = array_values(array_filter($rows, fn ($r) => $r['referenced_by'] === 'review'));

    expect($reviewRows)->not->toBe([], 'media.csv lists no review photographs at all');

    // Every URL reviews.csv carries is a row here, compared as sets so an
    // extra is as red as a missing one.
    $reviews = array_map('str_getcsv', file(irpExportDir().'/reviews.csv', FILE_IGNORE_NEW_LINES));
    $header = array_shift($reviews);
    $imagesAt = array_search('images', $header, true);

    $named = [];

    foreach ($reviews as $review) {
        foreach (array_filter(explode('|', (string) $review[$imagesAt])) as $url) {
            $named[$url] = true;
        }
    }

    $listed = [];

    foreach ($reviewRows as $row) {
        $listed[$row['url']] = true;
    }

    $named = array_keys($named);
    $listed = array_keys($listed);
    sort($named);
    sort($listed);

    expect($listed)->toBe($named, 'media.csv and reviews.csv disagree about the review photographs');

    // ...and the one that exists ONLY as a review photograph is among them,
    // which is what makes the set comparison above mean anything.
    expect($named)->toContain(IRP_SELFIE);

    // The row carries the comment id, which is `reviews.source_id` on this
    // shop — so a fetch that fails names the review that goes blank.
    $selfie = array_values(array_filter($reviewRows, fn ($r) => $r['url'] === IRP_SELFIE))[0];

    expect($selfie['referenced_id'])->toBe('8101');
    expect($selfie['field'])->toBe('images');

    // And it is a real file on the old shop, with real bytes, rather than a
    // URL the export invented: `exists` is the stat the exporter did.
    expect($selfie['exists'])->toBe('yes');
    expect((int) $selfie['bytes'])->toBeGreaterThan(0);
});

it('reads a photograph stored as a path the uploads directory holds', function () {
    /*
     * The two shapes the export used to NAME in manifest.json and leave
     * behind — "a bare filename, or a path relative to the uploads root" —
     * which was homework rather than a migration, and homework with a
     * deadline: `wp_commentmeta` does not survive the cutover.
     *
     * The fixture stores `2020/01/layla-review-2.jpg` under `rp_photo_paths`,
     * and `2020/01/never-uploaded.jpg` under `rp_missing`. The first is a
     * photograph because the uploads directory holds the file; the second is
     * not, because it does not — so a plugin's bookkeeping value that happens
     * to end in .jpg does not become a broken image on the new shop.
     *
     * MUTATION NOTE. Make KBB_Export_Review_Photos::url_for_stored_path()
     * return '' unconditionally, re-run the harness, and this is red on the
     * first expectation. Make it skip the is_file() check instead and it is
     * red on the second, with never-uploaded.jpg imported as a photograph.
     * RUN: red, both ways.
     */
    $reviews = array_map('str_getcsv', file(irpExportDir().'/reviews.csv', FILE_IGNORE_NEW_LINES));
    $header = array_shift($reviews);
    $imagesAt = array_search('images', $header, true);
    $idAt = array_search('comment_id', $header, true);

    $row = array_values(array_filter($reviews, fn ($r) => $r[$idAt] === '8102'))[0];
    $images = explode('|', (string) $row[$imagesAt]);

    expect($images)->toContain(IRP_RELATIVE);

    foreach ($images as $image) {
        expect($image)->not->toContain('never-uploaded');
    }

    // The manifest says which keys it read and which it left, and the key that
    // stored a path is now on the read side. That note is the owner's only
    // view of this, so it is asserted rather than left to be read.
    $manifest = json_decode((string) file_get_contents(irpExportDir().'/manifest.json'), true);
    $notes = implode("\n", $manifest['notes']);

    expect($notes)->toContain('rp_photo_paths');
    expect($notes)->toMatch('/Review photographs were read from .*rp_photo_paths/s');
});

/* ────────────────────── the import fetches and re-points ───────────────── */

it('counts a review photograph as remote, so the sideloader has work to do', function () {
    /*
     * `MediaAudit` is what the sideloader, the rewriter and the number in the
     * runbook are all computed from. A photograph it cannot see is a
     * photograph nothing fetches.
     *
     * MUTATION NOTE. Remove the `foreach (Review::query()…)` loop from
     * MediaAudit::references() and this is red: `remote` no longer contains
     * Layla's photograph, and the audit reports zero review rows while
     * `reviews.images` holds two URLs on the old host. RUN: red.
     */
    irpImport();

    // The premise: the import really did put the old host's addresses in the
    // column. Without this the case could pass on a shop with no photographs.
    $review = Review::query()->where('source', 'wp_comment')->where('source_id', 8101)->first();

    expect($review)->not->toBeNull();
    expect($review->images)->toContain(IRP_SELFIE);

    $rows = (new MediaAudit)->audit();

    $reviewRows = array_values(array_filter($rows, fn ($r) => $r['field'] === 'reviews.images'));

    expect(count($reviewRows))->toBeGreaterThanOrEqual(
        3, 'the audit sees '.count($reviewRows).' review photographs and the fixture imported more than that'
    );

    // Every one of them is REMOTE — on the old host — which is the verdict
    // that puts it on the sideloader's list.
    $remote = array_values(array_filter($reviewRows, fn ($r) => $r['verdict'] === MediaAudit::REMOTE));

    expect(count($remote))->toBe(count($reviewRows));

    // And the owner string names the review, in the vocabulary the import
    // report already uses, rather than a bare row id.
    expect($remote[0]['owner'])->toContain('review ');
    expect(implode(' ', array_column($remote, 'url')))->toContain(IRP_SELFIE);
});

it('fetches the customer photograph and re-points the review at this shop', function () {
    /*
     * THE WHOLE ROUND TRIP, AND THE ONE THAT DECIDES WHETHER THE PHOTOGRAPHS
     * SURVIVE: WordPress export -> import -> sideload the file -> re-point the
     * column. After this the review does not need the old shop at all.
     *
     * MUTATION NOTE. Remove [Review::class, 'reviews', 'images', true] from
     * MediaRewrite::COLUMNS and this is red at the last expectation: the file
     * is on this shop's disk and `reviews.images` still names kbeautybliss.com,
     * so switching WordPress off still breaks the picture. RUN: red.
     */
    irpImport();

    /* A CLOSURE, NOT A RESPONSE. `Http::response()` builds ONE stream, and the
       fake hands that same object to every matching request. The first
       download reads it to the end; every download after it reads nothing,
       and the sideloader -- correctly -- refuses to save an empty file as a
       picture ("the old host returned an empty body"). Measured: 1 fetched,
       8 failed, Layla's photograph among the eight. The closure builds a
       fresh response per request, which is what a real host does. */
    Illuminate\Support\Facades\Http::fake(['kbeautybliss.com/*' => fn () => Illuminate\Support\Facades\Http::response(
        irpJpeg(), 200, ['Content-Type' => 'image/jpeg']
    )]);

    $sideloader = new MediaSideloader(new MediaAudit);

    // Driven to completion rather than once: `batch()` is bounded by bytes,
    // files and seconds, and one call is not the whole catalogue.
    for ($i = 0; $i < 20; $i++) {
        $result = $sideloader->batch();

        if (($result['fetched'] ?? 0) === 0) {
            break;
        }
    }

    // THE FILE IS HERE. Not "the audit says so" — the disk says so.
    $landed = public_path('wp-content/uploads/2019/03/layla-selfie.jpg');

    expect(is_file($landed))->toBeTrue(
        'the customer photograph was never fetched; it dies the day WordPress is switched off'
    );
    expect(filesize($landed))->toBeGreaterThan(0);

    /*
     * ...and the review now points at THIS shop rather than at the old one.
     *
     * ▲ THE SIDELOADER RE-POINTS AS IT FETCHES, so by now there may be nothing
     *   left to propose. This first read `propose()` and required a proposal
     *   for the selfie -- and went red on a shop where the job was ALREADY
     *   DONE: batch() reports `repointed: {rows: 2}` and the review no longer
     *   names the old host, so propose() rightly finds nothing.
     *
     *   What matters is the END STATE, which is what the assertions below
     *   check. propose()/apply() still run, because a column the sideloader's
     *   inline pass does not cover must still be caught by the explicit pass --
     *   and if both were broken, the end-state assertions are what go red.
     *
     *   MUTATION NOTE, RUN: remove [Review::class, 'reviews', 'images', true]
     *   from MediaRewrite::COLUMNS -- neither pass then touches the review,
     *   `reviews.images` still names kbeautybliss.com, and the loop below is
     *   red with "still points at the site that is about to be switched off".
     */
    $rewrite = new MediaRewrite;
    $rewrite->apply($rewrite->propose(['kbeautybliss.com']));

    $review = Review::query()->where('source', 'wp_comment')->where('source_id', 8101)->first();
    $images = (array) $review->images;

    /* ▲ NOT a negated toContain() handed a needle and then a message. Pest's toContain() is
       VARIADIC: the second argument is a second NEEDLE, not a message, and
       under ->not the pair cannot fail. That is exactly how this loop was
       first written, and the mutation note above stayed GREEN against it --
       the review column removed from MediaRewrite::COLUMNS, the review still
       pointing at the old host, and every assertion here passing.
       CLAUDE.md names this trap; ExpectationsThatCannotFailTest exists for it.
       str_contains() into toBeFalse() has one argument that means one thing. */
    expect($images)->not->toBe([], 'the review has no photographs at all after the round trip');

    foreach ($images as $image) {
        expect(str_contains((string) $image, 'kbeautybliss.com'))->toBeFalse(
            "a review photograph still points at the site that is about to be switched off: {$image}"
        );
    }

    expect(implode(' ', $images))->toContain('layla-selfie.jpg');
});

/** The smallest real JPEG the sideloader's sniffer will take. */
function irpJpeg(): string
{
    $image = imagecreatetruecolor(24, 24);
    imagefilledrectangle($image, 0, 0, 23, 23, imagecolorallocate($image, 200, 120, 160));

    ob_start();
    imagejpeg($image, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}
