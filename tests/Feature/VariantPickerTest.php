<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\VariantOptions;
use Illuminate\Support\Facades\DB;
use Tests\Support\VpaCatalog;

/*
 * Lane VPA: "Card A" (dots + size chips) and "Page A" (round swatches + size
 * pills), the owner's choice from docs/variant-options/index.html.
 */

function vpaQueries(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

it('draws shade dots, a +N and size chips on the grid card', function () {
    /*
     * THE DEFECT: a variable product's tile showed only "AED 125 – AED 139" and
     * "View product"; nothing on the grid said it came in shades.
     * MUTATION: make VariantCard::html() return '' and every expectation here
     * is red.
     */
    $c = VpaCatalog::seed();

    $html = $this->get('/collections/vpa-makeup/')->assertOk()->getContent();

    expect($html)->toContain('class="vpc"')
        ->and($html)->toContain('aria-label="Shade: #13 Bright Beige"')
        ->and($html)->toContain('--c:#F1D2B6')
        // TIRTIR: nine shades, four drawn, "+5".
        ->and($html)->toContain('<span class="vpc-more">+5</span>')
        // The first TIRTIR shade is sold out: faded and crossed, and named so.
        ->and($html)->toMatch('/class="vpc-sw out"[^>]*aria-label="Shade: 17C Porcelain — Sold out"/')
        // Anua: size chips.
        ->and($html)->toContain('>250 ml</button>')
        ->and($html)->toContain('>500 ml</button>')
        // The button stays "View product" for a variable product.
        ->and(substr_count($html, '>'.__('store.product_card.view_product').'</a>'))->toBeGreaterThanOrEqual(3);
});

it('keeps a grid flat: three variable tiles or forty cost the same queries', function () {
    /*
     * THE DEFECT THIS GUARDS: a swatch query per tile -- forty tiles, forty
     * queries. VariantSwatches reads every variable product's options once.
     * Measured the BrandPageOwnerAsksTest way: the brand page lists every
     * product with no pager, so it is the grid that grows.
     * MUTATION: reset VariantSwatches' $rows memo at the top of forCard() and
     * the forty-tile count is 37 higher.
     */
    $c = VpaCatalog::seed();
    $value = $c['missha']->variants()->first()->attributeValues()->first()->id;
    $grow = function (int $from, int $to) use ($c, $value) {
        for ($i = $from; $i < $to; $i++) {
            $p = Product::create([
                'slug' => 'vpa-grow-'.$i, 'name' => 'Grow '.$i, 'type' => 'variable', 'price' => null,
                'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'brand_id' => $c['missha']->brand_id,
            ]);
            foreach ([9900, 10900] as $k => $price) {
                $v = ProductVariant::create(['product_id' => $p->id, 'price' => $price, 'stock_status' => 'instock', 'position' => $k]);
                $v->attributeValues()->attach($c['missha']->variants()->skip($k)->first()->attributeValues()->first()->id);
            }
        }
    };
    $url = '/brands/vpa-missha/';

    $grow(0, 2);
    // Warm the per-process caches (settings map, translations) first, so the
    // two measured requests differ by the grid and nothing else.
    $this->get($url)->assertOk();
    app()->forgetScopedInstances();
    $html = '';
    $three = vpaQueries(function () use (&$html, $url) {
        $html = $this->get($url)->assertOk()->getContent();
    });
    expect(substr_count($html, 'class="vpc"'))->toBe(3);

    $grow(2, 39);
    app()->forgetScopedInstances();
    $forty = vpaQueries(function () use (&$html, $url) {
        $html = $this->get($url)->assertOk()->getContent();
    });

    expect(substr_count($html, 'class="vpc"'))->toBe(40)
        ->and($forty)->toBe($three);
});

it('draws Page A on the product page and keeps it visible with Bundle section off', function () {
    /*
     * THE LIVE DEFECT: "Bundle section" switched off hid `.pm-bundles`, and
     * with it the only way to choose a shade on Missha, TIRTIR, Heimish and
     * House of Hur. The picker carries `pm-vpa`, which kbb-product.css puts
     * back on screen. MUTATION: drop ' pm-vpa' from the section's class and
     * the first expectation is red; drop the CSS rule and the second is.
     */
    $c = VpaCatalog::seed();

    $html = $this->get('/product/vpa-tirtir/')->assertOk()->getContent();
    $css = file_get_contents(resource_path('css/kbb/kbb-product.css'));

    expect($html)->toContain('<div class="pm-sec pm-bundles pm-vpa">')
        ->and($css)->toContain('.pdp-page .pm-sec.pm-bundles.pm-vpa,.pdp .pm-sec.pm-bundles.pm-vpa{display:block}')
        ->and($html)->toContain('class="vpa"')
        ->and($html)->toContain('Shade: <b dir="auto">21N Ivory</b>')
        ->and($html)->toMatch('/class="vpa-sw out" role="radio" aria-checked="false" data-v="\d+" data-n="17C Porcelain"/')
        ->and($html)->toMatch('/class="vpa-sw" role="radio" aria-checked="true" data-v="\d+" data-n="21N Ivory"/')
        // The form posts the first shade that can be bought.
        ->and($html)->toMatch('/name="variation_id" id="kbbVarId" value="'.$c['tirtir']->variants()->where('sku', 'TT-MF-21NIvory')->value('id').'"/')
        ->and($html)->toContain(e(__('store.product.variant_sku', ['sku' => 'TT-MF-21NIvory'])));
});

it('validates a swatch colour to #RRGGBB before it reaches a style attribute', function () {
    /*
     * THE DEFECT THIS GUARDS: an imported `swatch_color` never passed the
     * admin's validator, and it is printed into style="". A value like
     * `red;background:url(//evil)` must never get there. MUTATION: return
     * $value from hex() unchanged and the second line is red.
     */
    expect(VariantOptions::hex('#abc'))->toBe('#AABBCC')
        ->and(VariantOptions::hex('red;background:url(//x)'))->toBeNull()
        ->and(VariantOptions::hex('#12345G'))->toBeNull()
        ->and(VariantOptions::swatchStyle(['fill' => 'red;x:y', 'img' => null]))->toBe('--c:'.VariantOptions::NEUTRAL);
});
