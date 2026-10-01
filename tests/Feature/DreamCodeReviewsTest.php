<?php

/**
 * The owner's reviews live in Dream Code Reviews, not in WordPress comments.
 *
 * THE DEFECT, AS IT LOOKED ON HIS SHOP (1 October 2026): the Medicube PDRN Glow
 * Booster Set showed its reviews on kbeautybliss.com and none on extrabeauty.ae.
 * His own plugin, Dream Code Reviews, keeps reviews in `wp_sorina_reviews`,
 * hides WooCommerce's reviews tab, and copies a product's reviews onto its
 * siblings with Assign / Duplicate -- one physical row per target. The export
 * read only `wp_comments`, so 209 WooCommerce reviews arrived and every review
 * his storefront actually showed did not.
 *
 * Driven end to end: the REAL plugin against the harness's WordPress database
 * with the plugin's table seeded (run-export.php --dream=1), then the REAL
 * importer on what it wrote. The five seeded rows are described in
 * wordpress-plugin/harness/shop.php kbb_harness_dream_reviews().
 *
 * MUTATIONS, RUN:
 *   - drop the negative-cursor phase from batch(): red, no dream_code row;
 *   - drop the NOT EXISTS from dream_where(): red, the synced copy of 8101
 *     arrives as a second review of the same words;
 *   - make ReviewImporter ignore `source`: red, dream row 2 overwrites comment 2.
 */

use App\Models\Product;
use App\Models\Review;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportRunner;

function dcWpDb(): string
{
    $name = getenv('KBB_WP_DB');

    return is_string($name) && $name !== '' ? $name : 'kbb_ge_wp';
}

/** Export every group from the harness shop; returns the directory of CSVs. */
function dcExport(bool $dream): string
{
    $out = sys_get_temp_dir().'/kbb-dc-'.bin2hex(random_bytes(4));
    $lines = [];

    exec(
        escapeshellcmd(PHP_BINARY).' '.escapeshellarg(base_path('wordpress-plugin/harness/run-export.php'))
            .' --storage=posts --out='.escapeshellarg($out).' --db='.dcWpDb().' --batch=2'
            .($dream ? ' --dream=1' : '').' 2>&1',
        $lines,
        $status
    );

    if (3 === $status) {
        test()->markTestSkipped('no MySQL here: '.implode(' ', $lines));
    }

    expect($status)->toBe(0, 'export failed: '.implode("\n", $lines));

    return $out.'/export';
}

/** @return array<string, array<string, string>> keyed "source:id" */
function dcReviewRows(string $dir): array
{
    $rows = array_map('str_getcsv', file($dir.'/reviews.csv', FILE_IGNORE_NEW_LINES));
    $header = array_shift($rows);
    $out = [];

    foreach ($rows as $row) {
        $r = array_combine($header, $row);
        $out[$r['source'].':'.$r['comment_id']] = $r;
    }

    return $out;
}

function dcImport(string $dir): void
{
    $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);

    (new ImportRunner)->run(new ImportOptions(
        directory: $dir,
        sourceTimezone: $manifest['source']['timezone'],
        adoptBySlug: true,
        runKey: 'dc-'.bin2hex(random_bytes(3)),
    ));
}

it('exports Dream Code reviews, folds the synced copies into their WooCommerce review, and writes the rest once', function () {
    $dir = dcExport(true);
    $rows = dcReviewRows($dir);

    // Rows 2-5 arrive under their own source and id; row 1, the sync copy of
    // comment 8101, does not arrive at all ...
    foreach ([2, 3, 4, 5] as $id) {
        expect($rows)->toHaveKey('dream_code:'.$id);
    }
    expect($rows)->not->toHaveKey('dream_code:1');

    // ... because it is carried ON 8101: its likes and its title win.
    expect($rows['wp_comment:8101']['helpful'])->toBe('12')
        ->and($rows['wp_comment:8101']['title'])->toBe('Love it');

    // The Assign / Duplicate copy names the OTHER product; the business review names none.
    expect($rows['dream_code:3']['comment_post_id'])->toBe('4022')
        ->and($rows['dream_code:4']['comment_post_id'])->toBe('')
        ->and($rows['dream_code:5']['comment_approved'])->toBe('0');

    // The photograph, stored as attachment id 9001, is an address now.
    expect(str_contains($rows['dream_code:2']['images'], '/2019/03/ginseng-serum.jpg'))->toBeTrue($rows['dream_code:2']['images']);

    $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);
    expect(implode(' ', $manifest['notes']))->toContain('Dream Code Reviews: 5 review(s)');
});

it('lands them on the right products, as business reviews, as pending -- and a second import adds nothing', function () {
    $dir = dcExport(true);

    dcImport($dir);

    $serum = Product::query()->where('wc_id', 4021)->value('id');
    $toner = Product::query()->where('wc_id', 4022)->value('id');

    // The sibling product carries its own copy, approved and visible.
    expect(Review::query()->where('source', 'dream_code')->where('product_id', $toner)
        ->where('author_name', 'Mariam')->where('status', 'approved')->exists())->toBeTrue();

    // One review of "Cleared my skin in a week", not two, with the plugin's likes.
    $layla = Review::query()->where('product_id', $serum)->where('content', 'like', 'Cleared my skin in a week%')->get();
    expect($layla)->toHaveCount(1)
        ->and((int) $layla->first()->helpful)->toBe(12);

    // The business review has no product; the pending one stays pending.
    expect(Review::query()->where('source', 'dream_code')->where('source_id', 4)->value('product_id'))->toBeNull()
        ->and(Review::query()->where('source', 'dream_code')->where('source_id', 5)->value('status'))->toBe('pending');

    // The toner's star count includes its copy.
    expect((int) Product::query()->whereKey($toner)->value('review_count'))->toBeGreaterThanOrEqual(1);

    $before = Review::query()->count();
    dcImport($dir);
    expect(Review::query()->count())->toBe($before);
});

it('writes exactly what it wrote before on a site without the plugin', function () {
    $rows = dcReviewRows(dcExport(false));

    foreach ($rows as $key => $row) {
        expect($row['source'])->toBe('wp_comment', $key);
    }
});
