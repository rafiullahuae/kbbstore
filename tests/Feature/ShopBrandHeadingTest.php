<?php

declare(strict_types=1);

/**
 * A one-brand listing is headed with the brand's name, not "Shop all".
 *
 * THE DEFECT, ON THE LIVE SHOP (2 October 2026). The owner, on
 * https://extrabeauty.ae/shop/?filter_brands=celimax: "it showin one default
 * heading and text. show it either the brand name as per the url. or remove it
 * completely. this thing do only for such brand urls." The page read "Shop all"
 * over "Authentic Korean skincare, curated for the UAE." -- the unfiltered
 * /shop/ heading -- above fourteen Celimax products.
 *
 * And a second defect, found while fixing it: ShopController::$listingBrand
 * (Lane PT) was set by banner() on the one-brand path and never cleared on the
 * others. The router keeps one controller instance per route, so in a process
 * serving more than one request a search after a brand listing inherited the
 * brand. The last case reproduces it.
 *
 * MUTATIONS, RUN:
 *   - delete the `if (! $category && $this->listingBrand)` block in
 *     ShopController::index(): the first case is red ("Shop all");
 *   - drop the `@if ($sub !== '')` around .psub: the first case is red (an
 *     empty <p class="psub"></p>);
 *   - delete `$this->listingBrand = null;` from banner(): the last case is red
 *     (the search is titled "Celimax").
 */

use App\Models\Brand;
use App\Models\Category;

function sbhH1(string $html): string
{
    preg_match('#<h1 class="ptitle">(.*?)</h1>#s', $html, $m);

    return html_entity_decode(trim($m[1] ?? ''), ENT_QUOTES);
}

beforeEach(function () {
    Brand::updateOrCreate(['slug' => 'celimax'], ['name' => 'Celimax']);
    Brand::updateOrCreate(['slug' => 'sbh-second'], ['name' => 'Second Brand']);
});

it('heads /shop/?filter_brands=celimax with the brand, and drops the generic line', function () {
    $html = $this->get('/shop/?filter_brands=celimax')->assertOk()->getContent();

    expect(sbhH1($html))->toBe('Celimax')
        ->and($html)->not->toContain('<h1 class="ptitle">Shop all</h1>')
        ->and($html)->not->toContain('<p class="psub">')
        ->and($html)->not->toContain(__('store.shop.sub_default'));

    preg_match('#<title>(.*?)</title>#s', $html, $t);
    expect($t[1] ?? '')->toContain('Celimax')->not->toContain('Shop all');
});

it('leaves /shop/ itself exactly as it was', function () {
    $html = $this->get('/shop/')->assertOk()->getContent();

    expect(sbhH1($html))->toBe(__('store.shop.title_all'))
        ->and($html)->toContain('<p class="psub">' . e(__('store.shop.sub_default')) . '</p>');
});

it('keeps "Shop all" when the URL names two brands, or a brand that does not exist', function () {
    expect(sbhH1($this->get('/shop/?filter_brands=celimax,sbh-second')->getContent()))->toBe(__('store.shop.title_all'))
        ->and(sbhH1($this->get('/shop/?filter_brands=nobody-sells-this')->getContent()))->toBe(__('store.shop.title_all'));
});

it('keeps a category its own name and description', function () {
    Category::create(['name' => 'Sbh Toners', 'slug' => 'sbh-toners', 'description' => 'Every toner we stock.']);

    $html = $this->get('/collections/sbh-toners/')->assertOk()->getContent();

    // Since Lane PY a category is headed by the title header's light box, not
    // the plain .ptitle -- still its own name and its own description.
    expect($html)->toContain('<h1 class="kbb-th__title" id="kbb-th-title">Sbh Toners</h1>')
        ->and($html)->toContain('<div class="kbb-th__desc">Every toner we stock.</div>');
});

it('does not carry a brand from one request into the next', function () {
    $this->get('/shop/?filter_brands=celimax')->assertOk();

    // Same process, same routed controller instance, as on a queue worker.
    $html = $this->get('/shop/?s=toner')->assertOk()->getContent();

    expect(sbhH1($html))->toBe(__('store.shop.title_search', ['term' => 'toner']))
        ->and(sbhH1($this->get('/shop/')->getContent()))->toBe(__('store.shop.title_all'));
});
