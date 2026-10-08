<?php

declare(strict_types=1);

/**
 * THE PHONE CATEGORY PAGE, AS THE OWNER ASKED.                    (2.60.393)
 *
 * "turn off the filters for now. and make number of columns to select 1 or 2,
 * max. and reduce the capsule size of sort. and the background image on mobile
 * should cover the whole area by middle and center, doesn't matter what size
 * the image is. also reduce spacing between header area and sort etc row."
 *
 * Measured at 390 before -> after: Filters button shown -> gone; no column
 * buttons -> 1 / 2; sort 44px -> 34px tall; header picture shrunk to fit
 * (135px) -> covering the header, centred (190px); header to sort row 44px ->
 * 22px. Laptop unchanged (5 columns, filter rail as before).
 *
 * MUTATIONS, each red here:
 *   · filters_m default true                      -> "hides the Filters button"
 *   · drop the data-mcols echo in shop.blade      -> "pins one column"
 *   · let ?mcols take any value                   -> "refuses any other value"
 *   · the migration's update() removed            -> "turns a saved ON off"
 */

use App\Models\Category;
use App\Models\Product;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

function plCategory(): void
{
    $c = Category::query()->create(['name' => 'Sunscreens', 'slug' => 'pl-sun', 'parent_id' => null, 'header_image' => '/uploads/pl/x.jpg']);
    $c->forceFill(['path' => $c->slug, 'depth' => 0])->save();
    $p = Product::create(['slug' => 'pl-1', 'name' => 'PL One', 'type' => 'simple', 'status' => 'publish', 'is_visible' => true, 'price' => 1000, 'stock_status' => 'instock']);
    $p->categories()->sync([$c->id]);
}

beforeEach(function () {
    SettingsService::forgetMemo();
});

it('hides the Filters button on phones and offers 1 / 2 column buttons, both as shipped', function () {
    plCategory();
    $html = (string) $this->get('/collections/pl-sun/')->assertOk()->getContent();

    expect($html)->toContain('<div class="gtop kbb-nofilt-m kbb-mcols">')
        ->and(substr_count($html, 'id="colselm"'))->toBe(1)
        ->and($html)->toContain('data-m="1"')->toContain('data-m="2" class="on"');

    $css = (string) file_get_contents(resource_path('css/kbb/kbb-shop.css'));
    expect($css)->toContain('.gtop.kbb-nofilt-m .mobi-filter{display:none}')
        ->and($css)->toContain('#grid[data-mcols="1"]{grid-template-columns:minmax(0,1fr)}')
        ->and($css)->toContain('.gtop .sortsel select{min-height:34px;height:34px;')
        ->and($css)->toContain('.kbb-th + .wrap.shop{padding-top:0}');
});

it('pins one column with ?mcols=1 and refuses any other value', function () {
    plCategory();

    expect((string) $this->get('/collections/pl-sun/?mcols=1')->getContent())->toContain(' data-mcols="1">')
        ->and((string) $this->get('/collections/pl-sun/?mcols=%22%3E%3Cscript%3E')->getContent())->not->toContain('data-mcols');
});

it('covers the phone header with the picture by default, centred', function () {
    // Lane CB2: the old category header, pinned where it still is -- "Old
    // category header"; every category draws the brand design by default now.
    app(\App\Services\SiteLayout::class)->save(['catb_hero' => 'header']);
    plCategory();
    $html = (string) $this->get('/collections/pl-sun/')->getContent();

    expect($html)->toMatch('#<section class="kbb-th kbb-th--img[^"]*"#')->and($html)->not->toContain('kbb-th--pw');
});

it('turns a saved "whole picture on phones" off, as he asked, and nothing else', function () {
    DB::table('settings')->insert([
        ['key' => 'layout_cat_header_phone_whole', 'value' => '1', 'autoload' => true],
        ['key' => 'layout_show_count', 'value' => '1', 'autoload' => true],
    ]);

    ob_start();
    (require database_path('migrations/2027_08_20_100000_phone_listing_header_cover.php'))->up();
    ob_end_clean();

    expect(DB::table('settings')->where('key', 'layout_cat_header_phone_whole')->value('value'))->toBe('0')
        ->and(DB::table('settings')->where('key', 'layout_show_count')->value('value'))->toBe('1');
});
