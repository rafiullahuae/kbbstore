<?php

declare(strict_types=1);

/*
 * 2.60.500 -- "Visit" on Catalog -> Categories (and its Brands tab) and
 * Catalog -> Brands. The owner: "i want Visit button, which will give me url
 * to visit the category page on front-end ... Same thing i need for Brands",
 * and "correct the urls also, we are using throughout the actual urls, not
 * shop/?filter_brands=".
 *
 * Measured on the preview shop in Chromium: 16 category, 14 + 14 brand links,
 * every one 200, each opening in a new tab on the right page.
 */

use App\Support\Url;
use App\Support\UrlScheme;
use Illuminate\Support\Facades\Blade;

function avlSrc(string $name): string
{
    return (string) file_get_contents(resource_path("views/admin/partials/{$name}.blade.php"));
}

it('hands both screens the shop addresses from UrlScheme, escaped, outside the verbatim script', function () {
    /*
     * The scripts sit inside @verbatim and are bundled as static files, so a
     * Blade expression inside them reaches the browser as raw text and breaks
     * the whole screen (it did, in the first draft). The addresses ride on a
     * hidden element instead. MUTATION: move it inside @verbatim. RED.
     */
    foreach (['category-tree-screen', 'brands-editor-screen'] as $screen) {
        $src = avlSrc($screen);
        $el = strpos($src, '<div hidden data-kbb-shop-urls');

        expect($el)->toBeInt()
            ->and($el)->toBeLessThan((int) strpos($src, '@verbatim'))
            ->and($src)->not->toContain('@json(\App\Support\Url::to');
    }

    $html = Blade::render('<div hidden data-kbb-shop-urls data-collection="{{ \App\Support\Url::to(\App\Support\UrlScheme::COLLECTION_BASE) }}" data-brand="{{ \App\Support\Url::to(\App\Support\UrlScheme::BRAND_BASE) }}"></div>');

    expect($html)->toContain('data-collection="'.e(Url::to(UrlScheme::COLLECTION_BASE)).'"')
        ->and($html)->toContain('data-brand="'.e(Url::to(UrlScheme::BRAND_BASE)).'"')
        ->and(Url::to(UrlScheme::COLLECTION_BASE))->toEndWith('/collections/')
        ->and(Url::to(UrlScheme::BRAND_BASE))->toEndWith('/brands/');
});

it('draws Visit as a new-tab link to the real page, every path segment encoded', function () {
    /*
     * MUTATION: drop encodeURIComponent, or rel="noopener noreferrer". RED.
     */
    $tree = avlSrc('category-tree-screen');

    expect($tree)->toContain("var href = base + segs.map(encodeURIComponent).join('/') + '/';")
        ->and($tree)->toContain('target="_blank" rel="noopener noreferrer" draggable="false"')
        ->and($tree)->toContain('+ visitLink(SHOP_COLLECTION, c.path || c.slug, c.name)')
        ->and($tree)->toContain("'<div class=\"ct-acts\">' + visitLink(SHOP_BRAND, b.slug, b.name) + '</div>'");

    $brands = avlSrc('brands-editor-screen');
    expect($brands)->toContain("esc(SHOP_BRAND + encodeURIComponent(String(b.slug)) + '/')")
        ->and($brands)->toContain('target="_blank" rel="noopener noreferrer"');
});

it('shows brands at /brands/{slug}/, the address the shop uses, not the old filter', function () {
    // MUTATION: put "/shop/?filter_brands=" back on either row. RED.
    expect(avlSrc('brands-editor-screen'))->toContain("'<span class=\"bz-path\">/brands/' + esc(b.slug) + '/</span>'")
        ->not->toContain("'<span class=\"bz-path\">/shop/?filter_brands='")
        ->and(avlSrc('category-tree-screen'))->toContain("'<span class=\"ct-path\">/brands/' + esc(b.slug) + '/</span>'")
        ->not->toContain("'<span class=\"ct-path\">/shop/?filter_brands='");
});
