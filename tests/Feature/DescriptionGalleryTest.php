<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Support\DescriptionGallery;
use App\Support\RichText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Underline and picture galleries in the admin's rich-text boxes, and how the
 * shop draws them. (16 October 2026)
 *
 * The owner: "in backend input description etc box, i need an underline ...
 * and also to insert media ... in single column or multiple columns with
 * multiple images", then "each image should have a separate custom link ...
 * and the alt text ... copied from the linked product/page title."
 *
 * Measured end to end on a preview (tools/rgm-e2e.cjs): the blog editor shows
 * U and Media; three pictures in 2 columns, the first linked to a product by
 * name with its alt text filled from the product; saved; on the shop the grid
 * has 2 columns at 1280 and at 390, the link and alt survive, every picture is
 * lazy, scrollWidth equals the width, no console error, no failed request.
 */

function dgProducts(int $n, array $over = []): void
{
    static $k = 0;
    for ($i = 0; $i < $n; $i++) {
        $k++;
        Product::create(array_merge([
            'slug' => 'dg-prod-' . $k, 'name' => 'DG Product ' . $k, 'price' => 1000,
            'status' => 'publish', 'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple',
        ], $over));
    }
}

function dgAdmin(string $role): AdminUser
{
    return AdminUser::create([
        'name' => 'DG ' . $role, 'email' => 'dg-' . $role . '-' . Str::random(8) . '@example.com',
        'password' => bcrypt('secret'), 'role' => $role,
    ]);
}

it('keeps a gallery, its links, alt text and underline through the sanitiser, and drops what a pasted box could abuse', function () {
    // What the editor saves: contenteditable is the editor's, style is never
    // allowed (a position:fixed overlay), class carries the columns.
    $in = '<p><u>Glow</u></p><div class="kbb-gal kbb-gal-3" contenteditable="false" style="position:fixed">'
        . '<a href="/product/x/" onclick="alert(1)"><img src="/uploads/a.webp" alt="X serum" loading="lazy"></a>'
        . '<a href="javascript:alert(1)"><img src="/uploads/b.webp" alt=""></a></div>';

    $out = RichText::clean($in);

    expect($out)->toContain('<u>Glow</u>')
        ->toContain('<div class="kbb-gal kbb-gal-3">')
        ->toContain('<a href="/product/x/"><img src="/uploads/a.webp" alt="X serum" loading="lazy"></a>')
        ->not->toContain('contenteditable')
        ->not->toContain('style=')
        ->not->toContain('onclick')
        ->not->toContain('javascript:');
});

it('gives gallery pictures lazy loading and async decoding at render, and leaves everything else byte for byte', function () {
    // MUTATION: drop the str_contains early return and the plain HTML below is
    // still identical (the regex only matches galleries); drop the srcset check
    // in picture() and the second expand() adds a second loading=.
    $plain = '<p>No gallery here.</p><p><img src="/uploads/a.webp" alt="a"></p>';
    expect(DescriptionGallery::expand($plain))->toBe($plain);

    $g = '<div class="kbb-gal kbb-gal-4"><a href="/product/x/"><img src="/uploads/a.webp" alt="X"></a><img src="/uploads/b.webp" alt=""></div>';
    $once = DescriptionGallery::expand($g);

    expect($once)->toContain('<div class="kbb-gal kbb-gal-4">')
        ->and(substr_count($once, 'loading="lazy"'))->toBe(2)
        ->and(substr_count($once, 'decoding="async"'))->toBe(2)
        ->and($once)->toContain('<a href="/product/x/">')
        ->and(DescriptionGallery::expand($once))->toBe($once);   // idempotent

    // At most 2 a row on a phone, whatever the columns.
    expect(DescriptionGallery::sizes(1))->toBe('(max-width: 600px) 100vw, 760px')
        ->and(DescriptionGallery::sizes(3))->toBe('(max-width: 600px) 50vw, 253px')
        ->and(DescriptionGallery::sizes(4))->toBe('(max-width: 600px) 50vw, 190px');
});

it('draws a product description gallery on the product page through forStorefront', function () {
    $html = RichText::forStorefront('<p>Text</p><div class="kbb-gal kbb-gal-2"><img src="/uploads/a.webp" alt="A"><img src="/uploads/b.webp" alt="B"></div>');

    expect($html)->toContain('<div class="kbb-gal kbb-gal-2">')->toContain('decoding="async"');
});

it('lists link targets for a content editor, refuses support, and costs the same with 3 products or 40', function () {
    test()->actingAs(dgAdmin('support'), 'admin')->getJson('/admin-api/media/link-targets')->assertForbidden();

    $editor = dgAdmin('editor');

    dgProducts(3);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $r = test()->actingAs($editor, 'admin')->getJson('/admin-api/media/link-targets')->assertOk();
    $few = count(DB::getQueryLog());
    DB::disableQueryLog();

    $item = collect($r->json('items'))->firstWhere(0, 'product');
    expect($item)->toHaveCount(3)->and($item[2])->toStartWith('/product/');

    dgProducts(37);
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->actingAs($editor, 'admin')->getJson('/admin-api/media/link-targets')->assertOk();
    $many = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($many)->toBe($few);
});

it('only names a product that is on the shop, and only its name and address', function () {
    dgProducts(1, ['name' => 'Live One']);
    dgProducts(1, ['name' => 'Draft One', 'status' => 'draft']);
    dgProducts(1, ['name' => 'Hidden One', 'is_visible' => 0]);

    $items = test()->actingAs(dgAdmin('owner'), 'admin')->getJson('/admin-api/media/link-targets')->assertOk()->json('items');
    $names = array_column(array_filter($items, fn ($i) => $i[0] === 'product'), 1);

    expect($names)->toContain('Live One')->not->toContain('Draft One')->not->toContain('Hidden One');
    foreach ($items as $i) {
        expect($i)->toHaveCount(3);
    }
});

it('wires the toolbar into every rich-text editor and the script into the admin exactly once', function () {
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    $web = (string) file_get_contents(base_path('routes/web.php'));

    expect(substr_count($app, "@include('admin.partials.rte-media')"))->toBe(1)
        ->and(substr_count($web, "require __DIR__.'/rte-media-admin.php';"))->toBe(1);

    foreach (['post-editor-screen', 'page-editor-screen'] as $f) {
        $src = (string) file_get_contents(resource_path("views/admin/partials/{$f}.blade.php"));
        expect($src)->toContain('data-cmd="underline"')->toContain('data-rte-media');
    }
    $tabs = (string) file_get_contents(resource_path('views/admin/partials/product-tabs-screen.blade.php'));
    expect($tabs)->toContain('data-kpt-cmd="underline"')->toContain('data-rte-media');
    $pe = (string) file_get_contents(resource_path('views/admin/partials/product-editor-screen.blade.php'));
    expect($pe)->toContain("['kbbMedia', '▦ Gallery'")->toContain("(b[0] === 'kbbMedia' ? ' data-rte-media' : '')");

    // No layout measuring (CLAUDE.md rule 4), no timers, no polling.
    $js = (string) file_get_contents(resource_path('views/admin/partials/rte-media.blade.php'));
    expect($js)->not->toContain('getBoundingClientRect')->not->toContain('offsetTop')->not->toContain('offsetHeight')
        ->not->toContain('setInterval')->not->toContain('setTimeout');
});
