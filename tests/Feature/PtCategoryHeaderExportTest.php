<?php

declare(strict_types=1);

/*
 * ════════════════════════════════════════════════════════════════════════════
 * LANE PT — exporter 1.11.0 carries each category's banner, title and subtitle
 * ════════════════════════════════════════════════════════════════════════════
 *
 * THE DEFECT: "We have a banner image on each category on the old site. Need
 * to bring that on the category pages as title background." Exporter 1.10.1
 * wrote categories.csv as term_id, name, slug, parent, description, image,
 * position -- the banner was in no file, so the new shop could not draw it,
 * whatever it did.
 *
 * Where a Rey shop keeps a category banner could not be checked from here
 * (kbeautybliss.com is not reachable from this machine), so the exporter does
 * not guess one meta key: it reads every picture-, cover-, banner-, header-,
 * title- and subtitle-named key, resolves whatever shape the value is in, and
 * writes a census of EVERY term-meta key into manifest.json so the owner's
 * first export names the real storage. Driven end to end here: the REAL plugin
 * over the harness's WordPress database (run-export.php), each shape seeded in
 * wordpress-plugin/harness/shop.php -- see the comment there.
 *
 * MUTATIONS, RUN (each red, then restored):
 *   - `thumbnail_id` dropped from SKIP: red, the census names WooCommerce's
 *     own thumbnail as a banner candidate (`it names every term-meta key`);
 *   - the `from_id()` fallback to the post's Elementor data removed: red, the
 *     Rey page cover on Toners exports an empty banner_image;
 *   - `'_' === substr( $key, 0, 1 )` removed from skipped(): red, ACF's
 *     `_header_banner` field reference becomes a banner candidate in the note;
 *   - the census call removed from header_census(): red, no TERM META note.
 */

/** The harness database; see GeWpExporterTest::geWpDb() for why it is a variable. */
function ptxWpDb(): string
{
    $name = getenv('KBB_WP_DB');

    return is_string($name) && $name !== '' ? $name : 'kbb_ge_wp';
}

/** @return array{dir: string, manifest: array<string, mixed>} */
function ptxExport(array $flags = []): array
{
    $out = sys_get_temp_dir().'/kbb-ptx-'.bin2hex(random_bytes(4));
    $lines = [];

    exec(
        escapeshellcmd(PHP_BINARY).' '.escapeshellarg(base_path('wordpress-plugin/harness/run-export.php'))
            .' --storage=posts --out='.escapeshellarg($out).' --db='.ptxWpDb().' --batch=2'
            .' '.implode(' ', array_map('escapeshellarg', $flags)).' 2>&1',
        $lines,
        $status
    );

    if (3 === $status) {
        test()->markTestSkipped('no MySQL here: '.implode(' ', $lines));
    }

    expect($status)->toBe(0, 'export failed: '.implode("\n", $lines));

    return [
        'dir' => $out.'/export',
        'manifest' => json_decode((string) file_get_contents($out.'/export/manifest.json'), true),
    ];
}

/** @return array<string, array<string, string>> keyed by term_id */
function ptxRows(string $path): array
{
    $handle = fopen($path, 'r');
    $header = fgetcsv($handle, null, ',', '"', '');
    $out = [];

    while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
        $assoc = array_combine($header, $row);
        $out[$assoc['term_id']] = $assoc;
    }

    fclose($handle);

    return $out;
}

it('writes the banner, where it came from, the title and the subtitle for every category and brand', function () {
    $export = ptxExport();

    $categories = ptxRows($export['dir'].'/categories.csv');
    $brands = ptxRows($export['dir'].'/brands.csv');

    $pick = fn (array $row): array => array_intersect_key($row, array_flip(['banner_image', 'banner_source_key', 'title_override', 'subtitle']));

    // An attachment id, with ACF's `_field` twin beside it, plus a title and a subtitle.
    expect($pick($categories['15']))->toBe([
        'banner_image' => 'https://kbeautybliss.com/wp-content/uploads/2020/01/skincare-banner.jpg',
        'banner_source_key' => 'header_banner',
        'title_override' => 'Korean Skincare',
        'subtitle' => 'Gentle, effective and authentic',
    ]);

    // A plain URL string.
    expect($pick($categories['22'])['banner_image'])->toBe('https://kbeautybliss.com/wp-content/uploads/2020/02/face-cleansers-cover.jpg');

    // Nothing at all: every column empty, and the row still there.
    expect($pick($categories['31']))->toBe(['banner_image' => '', 'banner_source_key' => '', 'title_override' => '', 'subtitle' => '']);

    // A brand, with a serialized array carrying `url`.
    expect($pick($brands['502'])['banner_image'])->toBe('https://kbeautybliss.com/wp-content/uploads/2020/01/boj-banner.jpg')
        ->and($pick($brands['501'])['banner_image'])->toBe('');

    // The thumbnail is still the `image` column, untouched -- and not a banner.
    expect($categories['15']['image'])->toBe('https://kbeautybliss.com/wp-content/uploads/2020/01/skincare-category.jpg');
});

it('follows a Rey page cover to the picture inside the global section', function () {
    $export = ptxExport(['--rey=1']);

    $toners = ptxRows($export['dir'].'/categories.csv')['31'];

    expect($toners['banner_image'])->toBe('https://kbeautybliss.com/wp-content/uploads/2023/06/toners-cover.jpg')
        ->and($toners['banner_source_key'])->toBe('cover_section (rey-global-sections 18180)');
});

it('names every term-meta key the shop has, so the owner\'s first export says where the banner lives', function () {
    $export = ptxExport();

    $notes = array_values(array_filter(
        $export['manifest']['notes'],
        static fn (string $n): bool => str_starts_with($n, 'TERM META ON'),
    ));

    expect($notes)->toHaveCount(2);

    [$categories, $brands] = $notes;

    expect($categories)->toStartWith('TERM META ON `product_cat` (categories.csv): 8 keys -- ');

    foreach (['_header_banner x1', 'cover_image x1', 'display_type x1', 'header_banner x1', 'order x1', 'page_subtitle x1', 'page_title x1', 'thumbnail_id x1'] as $key) {
        expect(str_contains($categories, $key))->toBeTrue($key);
    }

    // The two banner-named keys are the candidates; ACF's `_header_banner`
    // reference and WooCommerce's own `thumbnail_id` are not.
    expect($categories)->toEndWith('Read as banner candidates: cover_image, header_banner -- banner_source_key on each row says which one it came from.');

    expect($brands)->toStartWith('TERM META ON `pa_brands` (brands.csv): 3 keys -- ');
});

it('lists each banner in media.csv, so the new shop\'s picture fetch names it', function () {
    $export = ptxExport();

    $rows = array_map('str_getcsv', file($export['dir'].'/media.csv', FILE_IGNORE_NEW_LINES));
    $header = array_shift($rows);

    $banners = [];

    foreach ($rows as $row) {
        $row = array_combine($header, $row);

        if ($row['field'] === 'banner_image') {
            $banners[] = $row['referenced_by'].' '.$row['referenced_id'].' '.$row['url'];
        }
    }

    expect($banners)->toBe([
        'category 15 https://kbeautybliss.com/wp-content/uploads/2020/01/skincare-banner.jpg',
        'category 22 https://kbeautybliss.com/wp-content/uploads/2020/02/face-cleansers-cover.jpg',
        'brand 502 https://kbeautybliss.com/wp-content/uploads/2020/01/boj-banner.jpg',
    ]);
});

it('is 1.11.0 in all four places the version lives, and the changelog says why', function () {
    $plugin = (string) file_get_contents(base_path('wordpress-plugin/kbb-exporter/kbb-exporter.php'));
    $runner = (string) file_get_contents(base_path('wordpress-plugin/kbb-exporter/includes/class-kbb-export-runner.php'));
    $log = (string) file_get_contents(base_path('wordpress-plugin/kbb-exporter/CHANGELOG.md'));

    expect($plugin)->toContain(' * Version:           1.11.0')
        ->and($plugin)->toContain("define( 'KBB_EXPORTER_VERSION', '1.11.0' );")
        ->and($plugin)->toContain("\$kbb_exporter_mine      = '1.11.0';")
        ->and($runner)->toContain("const PLUGIN_VERSION = '1.11.0';")
        ->and($log)->toContain("## 1.11.0\n");
});
