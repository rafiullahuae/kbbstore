<?php

declare(strict_types=1);

/*
 * Homepage → Top brands: the photo cards ask for right-sized copies.
 *
 * PageSpeed, desktop, 6 October 2026: "Improve image delivery — Est savings of
 * 2,108 KiB". Every brand photo card was `<img src="/uploads/brands/…">` with
 * no srcset — 903 KiB for Anua, 268 KiB for Beauty of Joseon — painted into a
 * 400x500 frame a seventh of a laptop wide. They are lazy, but near enough the
 * top on a laptop that the browser fetched them before the load event, so the
 * tab's loading bar ran on behind 2 MB of photographs the owner had uploaded
 * that morning. Every other photograph on the shop already offers the
 * img-cache copies; this card did not ask.
 */

use App\Models\Brand;
use App\Models\Product;
use App\Services\HomepageContent;
use App\Services\ModuleSchema;
use App\Services\SettingsService;
use App\Support\ImageVariants;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    app(SettingsService::class)->set('demo_content', false);
});

function hbpHome(): string
{
    SettingsService::forgetMemo();
    \App\Models\Setting::flushMap();
    Cache::flush();

    return test()->get('/')->assertOk()->getContent();
}

function hbpCard(string $html, Brand $brand): string
{
    return preg_match('#<a class="hs-brand[^"]*" href="'.preg_quote($brand->url(), '#').'">.*?</a>#s', $html, $m) === 1 ? $m[0] : '';
}

function hbpBrand(string $photo): Brand
{
    $b = Brand::create(['slug' => 'hbp-'.uniqid(), 'name' => 'Photo Brand', 'banner' => ['image' => $photo]]);
    Product::create(['slug' => 'hbp-p-'.uniqid(), 'name' => 'Photo Brand Toner', 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'stock_status' => 'instock', 'price' => 3000, 'total_sales' => 99999, 'brand_id' => $b->id]);

    return $b;
}

it('offers the 200, 400 and 800 copies with a quarter-screen size once they exist', function () {
    /* MUTATION: drop the srcset from partials/home/hs-brands.blade.php -> red. */
    $rel = 'uploads/hbp-'.uniqid().'/anua.webp';
    $made = [];
    foreach ([200, 400, 800] as $w) {
        $f = public_path(ImageVariants::DIR.'/'.$w.'/'.$rel);
        @mkdir(dirname($f), 0777, true);
        file_put_contents($f, 'x');
        $made[] = $f;
    }

    try {
        $brand = hbpBrand('/'.$rel);
        $card = hbpCard(hbpHome(), $brand);

        expect($card)->toContain('src="/'.$rel.'"')
            ->toContain('srcset="/img-cache/200/'.$rel.' 200w, /img-cache/400/'.$rel.' 400w, /img-cache/800/'.$rel.' 800w"')
            ->toContain('sizes="'.ImageVariants::homeBrandSizesAttribute().'"');
        // A laptop at 1280 with the card a seventh wide asks for at most the 400w copy at DPR 1.
        expect(ImageVariants::homeBrandSizesAttribute())->toBe('(max-width: 900px) 50vw, (max-width: 1440px) 25vw, 360px');
    } finally {
        foreach ($made as $f) {
            @unlink($f);
        }
        foreach ([200, 400, 800] as $w) {
            @rmdir(dirname(public_path(ImageVariants::DIR.'/'.$w.'/'.$rel)));
        }
    }
});

it('draws exactly what it drew before while no copy exists', function () {
    // With nothing on disk srcsetFor() answers '' and the card must not change a
    // byte: a srcset naming files that do not exist is a blank tile.
    $brand = hbpBrand('/uploads/hbp-none/'.uniqid().'.webp');
    $card = hbpCard(hbpHome(), $brand);

    // The photo's own <img> (the phone-only <source> beside it keeps its blank srcset).
    expect($card)->toMatch('#<img src="/uploads/hbp-none/[^"]+\.webp" alt="Photo Brand" width="400" height="500" loading="lazy" decoding="async">#')
        ->and(preg_match('#<img src="/uploads/hbp-none/[^>]*>#', $card, $img))->toBe(1)
        ->and($img[0])->not->toContain('srcset=')->not->toContain('sizes=');
});
