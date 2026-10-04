<?php

declare(strict_types=1);

/*
 * Lane PD -- "on product page, the html blocks has font size issue, it should
 * come in line, the headings. also in mobile also it should come in one row
 * the 3 columns [...] in original site it's in 3 columns."
 *
 * The defect, measured in Chromium on the seeded Anua block (tools/pd-shots.cjs,
 * docs/lane-pd-shots/MEASUREMENTS.json, BEFORE side):
 *   - 1280px: each heading 17px in a 118.3px text column; "ANTI-SEBUM P" is
 *     7.28em wide in the shop's face, 123.8px, so it broke onto two lines.
 *   - 390px: `@container (max-width:599px)` turned every row into one column,
 *     so the three ingredients stacked into three rows, 1300px tall.
 * AFTER: 14px / 13.6px, one line each; three 108.7px columns at 390, 303px tall.
 */

use App\Models\Block;
use App\Models\Product;
use App\Services\SettingsService;
use App\Support\GlobalSections;
use App\Support\RichText;
use Illuminate\Support\Str;

function pdCss(): string
{
    return (string) file_get_contents(resource_path('css/kbb/kbb-product.css'));
}

function pdBuiltCss(): string
{
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    return (string) file_get_contents(public_path('build/' . $manifest['resources/css/kbb/kbb-product.css']['file']));
}

/** Width of "ANTI-SEBUM P", in em, in the shop's heading face (measured, see header). */
const PD_ANTI_SEBUM_EM = 7.28;

it('sizes a heading inside a column by the column, so an ingredient name fits on one line', function () {
    $css = pdCss();

    expect($css)->toContain('.kbb-eblock__col{min-width:0;container:kbb-eb-col/inline-size}')
        ->and($css)->toContain(".kbb-eblock__col :is(h2,h3,h4).kbb-eblock__heading,\n.kbb-eblock__col h4.kbb-eblock__title{font-size:clamp(10px,12.5cqi,14px);");

    preg_match('/\.kbb-eblock__col h4\.kbb-eblock__title\{font-size:clamp\((\d+)px,([\d.]+)cqi,(\d+)px\)/', $css, $m);
    [$min, $share, $max] = [(float) $m[1], (float) $m[2] / 100, (float) $m[3]];

    /*
     * The arithmetic the browser does. For any column W the heading is
     * clamp(min, share*W, max) px and the name is 7.28 of those wide; it fits
     * when that is <= W. Mutation: raise 12.5cqi to 14cqi and the 118.3px
     * desktop column overflows (0.14 * 7.28 = 1.02 > 1) -- red.
     */
    foreach ([118.3, 108.7, 258.7, 90.0] as $w) {
        $size = max($min, min($share * $w, $max));
        expect($size * PD_ANTI_SEBUM_EM)->toBeLessThanOrEqual($w, "column {$w}px");
    }

    // The defect itself: 17px in the 118.3px column did not fit.
    expect(17 * PD_ANTI_SEBUM_EM)->toBeGreaterThan(118.3);
});

it('keeps a three-column row three across on a phone, stacks a picture over its words in a narrow column, and the switch restores the stack', function () {
    $css = pdCss();

    // Mutation: delete the row--3 line and a phone is back to one per line.
    expect($css)->toContain("@container kbb-eb (max-width:599px){\n  .kbb-eblock .kbb-eblock__row--3{grid-template-columns:repeat(3,minmax(0,1fr));gap:16px 10px}\n  .kbb-eblock--stack .kbb-eblock__row--3{grid-template-columns:minmax(0,1fr);gap:18px 22px}\n}")
        ->and($css)->toContain('@container kbb-eb-col (max-width:179px){')
        ->and($css)->toContain('.kbb-eblock{container:kbb-eb/inline-size;')
        // The old unnamed query would now answer for the nearest COLUMN and stack a desktop pair.
        ->and($css)->not->toContain('@container (max-width:599px)');

    // The narrow-column threshold sits between the phone column and the desktop one.
    expect(108.7)->toBeLessThan(179.0)->and(258.7)->toBeGreaterThan(179.0);
});

it('ships the same rules in the built stylesheet the page loads', function () {
    $built = pdBuiltCss();

    expect($built)->toContain('container:kbb-eb-col/inline-size')
        ->and($built)->toContain('font-size:clamp(10px,12.5cqi,14px)')
        ->and($built)->toContain('.kbb-eblock .kbb-eblock__row--3{grid-template-columns:repeat(3,minmax(0,1fr))')
        ->and($built)->toContain('.kbb-dvid__v{');
});

it('draws the block byte for byte with the switch on, and marks it --stack with the switch off', function () {
    $content = '<div class="kbb-eblock"><div class="kbb-eblock__row kbb-eblock__row--3"><div class="kbb-eblock__col"><h4 class="kbb-eblock__heading">ANTI-SEBUM P</h4></div><div class="kbb-eblock__col">b</div><div class="kbb-eblock__col">c</div></div></div>';
    Block::query()->forceCreate(['slug' => 'pd-' . Str::random(5), 'name' => 'G', 'content' => $content, 'status' => 'published', 'wc_id' => 18159, 'source' => 'rey_global_section']);

    $copy = '<p>[rey_global_section id="18159"]</p>';

    expect(GlobalSections::expand($copy))->toBe(RichText::clean($content));

    app()->forgetInstance('kbb.global_sections');
    app(SettingsService::class)->setModule('eblock_phone_columns', false);

    // Mutation: drop the module check in GlobalSections::expand() -- red.
    expect(GlobalSections::expand($copy))->toStartWith('<div class="kbb-eblock kbb-eblock--stack">')
        ->and(substr_count(GlobalSections::expand($copy), 'kbb-eblock--stack'))->toBe(1)
        ->and(GlobalSections::expand('<p>No block here.</p>'))->toBe('<p>No block here.</p>');
});

it('costs no query on a product page whose copy names no block and no video', function () {
    $p = Product::create(['name' => 'Plain', 'slug' => 'pd-plain-' . Str::random(5), 'type' => 'simple', 'status' => 'publish', 'is_visible' => true, 'price' => 100, 'stock_status' => 'instock', 'description' => "<p>Just words.</p>\n<p>More words.</p>"]);

    \Illuminate\Support\Facades\Cache::flush();
    $this->get('/product/' . $p->slug . '/')->assertOk();

    \Illuminate\Support\Facades\DB::enableQueryLog();
    $this->get('/product/' . $p->slug . '/')->assertOk();
    $queries = collect(\Illuminate\Support\Facades\DB::getQueryLog())->pluck('query')->implode("\n");
    \Illuminate\Support\Facades\DB::disableQueryLog();

    expect($queries)->not->toContain('module_toggles')->and($queries)->not->toContain('"blocks"');
});
