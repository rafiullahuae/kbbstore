<?php

declare(strict_types=1);

/*
 * THE ARABIC PRODUCT PAGE'S TAB READ ENGLISH (the owner, 23 October 2026:
 * "some products has still english titles in browser in arabic version", e.g.
 * /ar/product/dr-althea-345-relief-cream/). The heading, og:title and the
 * structured data were Arabic, but the per-product SEO title and description
 * boxes -- filled in English by the Yoast import -- were printed verbatim on
 * /ar/: <title>Buy Dr.Althea 345 Relief Cream | K-Beauty Bliss</title> and an
 * English meta description over an Arabic page.
 */

use App\Models\Brand;
use App\Models\Product;
use App\Models\Translation;
use App\Services\Translation\TranslationStore;
use Illuminate\Support\Str;
use Tests\Support\ArabicShop;

function psaProduct(array $seo): Product
{
    ArabicShop::on();
    $brand = Brand::create(['slug' => 'psa-'.Str::random(6), 'name' => 'Dr.Althea']);
    $p = Product::create(['slug' => 'psa-'.Str::random(8), 'name' => '345 Relief Cream', 'status' => 'publish',
        'is_visible' => true, 'price' => 100, 'stock_status' => 'instock', 'brand_id' => $brand->id,
        'short_description' => '<p>A soothing cream.</p>', 'seo' => $seo]);
    foreach ([['products', $p->id, 'name', 'كريم 345 ريليف'], ['products', $p->id, 'short_description', '<p>كريم مهدّئ للبشرة.</p>'], ['brands', $brand->id, 'name', 'الدكتورة ألثيا']] as [$g, $id, $f, $v]) {
        Translation::create(['locale' => 'ar', 'group' => $g, 'item_id' => $id, 'field' => $f, 'value' => $v, 'status' => 'published']);
    }
    TranslationStore::flush();

    return $p;
}

function psaHead(string $html): array
{
    preg_match('#<title>(.*?)</title>#s', $html, $t);
    preg_match('#<meta name="description" content="([^"]*)"#', $html, $d);

    return [html_entity_decode($t[1] ?? ''), html_entity_decode($d[1] ?? '')];
}

it('skips an English SEO title and description on the Arabic page, and keeps them on the English one', function () {
    $p = psaProduct(['title' => 'Buy Dr.Althea 345 Relief Cream | K-Beauty Bliss', 'desc' => 'Shop Dr.Althea 345 Relief Cream at K-Beauty Bliss.']);

    [$arTitle, $arDesc] = psaHead($this->get('/ar/product/'.$p->slug.'/')->assertOk()->getContent());
    [$enTitle, $enDesc] = psaHead($this->get('/product/'.$p->slug.'/')->assertOk()->getContent());

    // MUTATION: return $value regardless of Locale in ProductSeo::override(). RED.
    expect($arTitle)->not->toContain('Buy Dr.Althea')
        ->and($arTitle)->toContain('كريم 345 ريليف')
        ->and($arDesc)->not->toContain('Shop Dr.Althea')
        ->and($arDesc)->toContain('كريم مهدّئ للبشرة')
        // The English page still publishes the box verbatim.
        ->and($enTitle)->toBe('Buy Dr.Althea 345 Relief Cream | K-Beauty Bliss')
        ->and($enDesc)->toBe('Shop Dr.Althea 345 Relief Cream at K-Beauty Bliss.');
});

it('keeps an SEO title written in Arabic on the Arabic page', function () {
    $p = psaProduct(['title' => 'اشتري كريم ألثيا 345']);

    [$arTitle] = psaHead($this->get('/ar/product/'.$p->slug.'/')->assertOk()->getContent());

    expect($arTitle)->toBe('اشتري كريم ألثيا 345');
});
