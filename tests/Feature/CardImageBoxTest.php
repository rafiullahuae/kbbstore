<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\ProductStyles;
use App\Services\SettingsService;

/**
 * Lane PF2 -- the product card's <img> states its frame's shape.
 *
 * WHAT IT LOOKED LIKE. Lighthouse listed every card photograph under "Image
 * elements do not have explicit width and height", on /shop, every category
 * archive, the homepage rails and the product page's related row.
 *
 * THE OWNER: "the grid cards design must not be changed". So nothing here may
 * move a pixel: the attributes state the shape the frame ALREADY has
 * (Appearance -> Product styles -> Image shape, through the map that writes
 * `--kbb-ratio`). The last case records why the homepage's first rail was NOT
 * made eager.
 */

/** @return list<string> every card <img> tag, in document order */
function cibCards(string $html): array
{
    preg_match_all('/<img\b[^>]*\bclass="kbb-card-img"[^>]*>/s', $html, $m);

    return $m[0];
}

function cibAttr(string $tag, string $name): ?string
{
    return preg_match('/\s'.preg_quote($name, '/').'="([^"]*)"/', $tag, $m) === 1 ? $m[1] : null;
}

function cibCatalogue(): void
{
    test()->seed(\Database\Seeders\DatabaseSeeder::class);

    // A path is enough: the card prints `src` whether or not the file exists,
    // and nothing here is about the bytes.
    Product::query()->visible()->get()->each(
        fn (Product $p) => $p->forceFill(['image' => '/uploads/products/cib.jpg'])->save()
    );
}

it('gives every card photograph the width and height of the shape its frame is drawn at', function () {
    /*
     * MUTATION, run: hard-code `width="400" height="400"` in the card and the
     * tall/landscape/portrait rows are red; delete the attributes and every
     * row is.
     */
    cibCatalogue();

    foreach (['square' => ['400', '400'], 'tall' => ['400', '500'], 'landscape' => ['480', '400'], 'portrait' => ['400', '408']] as $shape => [$w, $h]) {
        app(SettingsService::class)->set('image_ratio', $shape);
        \App\Services\SettingsService::forgetMemo();
        app()->forgetScopedInstances();

        foreach (['/shop/', '/'] as $page) {
            $cards = cibCards((string) test()->get($page)->assertOk()->getContent());
            expect($cards)->not->toBeEmpty("no card on {$page}");

            foreach ($cards as $tag) {
                expect(cibAttr($tag, 'width'))->toBe($w, "{$shape} on {$page}: {$tag}")
                    ->and(cibAttr($tag, 'height'))->toBe($h, "{$shape} on {$page}: {$tag}");
            }
        }
    }
});

it('reads the attributes and the frame off one map, so they cannot disagree', function () {
    /*
     * The frame is `aspect-ratio: var(--kbb-ratio)`; the attributes are
     * imageBox(). Both come from ProductStyles::IMAGE_RATIOS. If someone adds a
     * shape to one and not the other, the attribute ratio drifts from the
     * frame's -- harmless to layout (CSS width and height win) but a lie to
     * the browser about the picture.
     *
     * MUTATION: make imageBox() answer [400, 400] whatever the shape and the
     * tall, landscape and portrait rows are red here (and in the first test).
     */
    foreach (ProductStyles::IMAGE_RATIOS as $shape => $css) {
        [$a, $b] = array_map('floatval', explode('/', $css));
        [$w, $h] = ProductStyles::imageBox($shape);

        expect(round($w / $h, 3))->toBe(round($a / $b, 3), $shape)
            ->and(min($w, $h))->toBe(400);
    }

    // An unknown or empty stored value is the square the CSS falls back to.
    expect(ProductStyles::imageBox('nonsense'))->toBe([400, 400])
        ->and(ProductStyles::imageBox(null))->toBe([400, 400]);

    // And cssVariables() still prints exactly what it printed before the map.
    app(SettingsService::class)->set('image_ratio', 'landscape');
    expect(app(ProductStyles::class)->cssVariables())->toContain('--kbb-ratio:1.2/1;');
});

it('leaves every homepage card lazy, because an eager first rail cost the banner its LCP', function () {
    /*
     * TRIED AND MEASURED, NOT SHIPPED (Lane PF2). The first rail's first two
     * cards were given the card's own `eager` prop -- the one /shop passes its
     * first card -- so a phone would not wait for layout before requesting
     * them. Lighthouse 12.8.2, mobile, eight runs a side on the same catalogue:
     *
     *   before            LCP median 1,805 ms
     *   eager first two   LCP median 1,959 ms   (+154 ms, every run but one)
     *   the same, undone  LCP median 1,809 ms
     *
     * Bytes were identical (208 KiB): the lazy cards inside the first screen
     * were fetched anyway. What `eager` added is `fetchpriority="high"`, and
     * two card photographs at high priority split the connection with the
     * banner picture, which IS the LCP element on a phone. So the shipped
     * state is every homepage card lazy, and this pins it.
     *
     * MUTATION, run: pass `:eager="$loop->index < 2"` in partials/home/grid
     * and this is red.
     */
    cibCatalogue();

    $cards = cibCards((string) test()->get('/')->assertOk()->getContent());
    expect(count($cards))->toBeGreaterThan(2);

    foreach ($cards as $tag) {
        expect(cibAttr($tag, 'loading'))->toBe('lazy', $tag)
            ->and(cibAttr($tag, 'fetchpriority'))->toBeNull($tag);
    }
});
