<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\ProductStyles;
use App\Services\SettingsService;

/**
 * Lane PF2 -- the product card's <img> states its frame's shape, and the first
 * rail on the homepage is not lazy.
 *
 * WHAT IT LOOKED LIKE. Lighthouse listed every card photograph under "Image
 * elements do not have explicit width and height", on /shop, every category
 * archive, the homepage rails and the product page's related row. And on a
 * phone the first rail sits straight under the banner, inside the first
 * screen, with every one of its photographs `loading="lazy"` -- not requested
 * until layout had proved it visible.
 *
 * THE OWNER: "the grid cards design must not be changed". So nothing here may
 * move a pixel: the attributes state the shape the frame ALREADY has
 * (Appearance -> Product styles -> Image shape, through the map that writes
 * `--kbb-ratio`), and `eager` is the prop /shop already passes its first card.
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

it('loads the first two cards of the first rail eagerly, and no other card', function () {
    /*
     * MUTATION, run: drop `'eagerFirst' => $eagerFor('bundles')` from the
     * bundles include in store/home.blade.php and no card is eager; pass 99
     * and the third card is.
     */
    cibCatalogue();

    $cards = cibCards((string) test()->get('/')->assertOk()->getContent());
    expect(count($cards))->toBeGreaterThan(2);

    $eager = array_values(array_filter($cards, fn ($t) => cibAttr($t, 'loading') === 'eager'));

    expect($eager)->toBe([$cards[0], $cards[1]])
        ->and(cibAttr($cards[2], 'loading'))->toBe('lazy')
        ->and(cibAttr($cards[2], 'fetchpriority'))->toBeNull();
});

it('follows the saved order, not the template, to find the first rail', function () {
    /*
     * The owner can move a rail up in Appearance -> Homepage, and the page
     * then draws it first by CSS `order` while it is still written further
     * down. The eager pair has to go where the shopper sees the first rail.
     *
     * MUTATION, run: pick the first rail by document order (the first grid
     * include) and the eager pair stays in the bundles rail.
     */
    cibCatalogue();

    $sections = app(\App\Services\HomepageSections::class);
    $keys = array_keys($sections->all());
    $keys = array_values(array_diff($keys, ['bestselling']));
    array_splice($keys, array_search('bundles', $keys, true), 0, ['bestselling']);
    $all = $sections->all();
    $payload = [];
    foreach ($keys as $i => $k) {
        $payload[$k] = ['order' => $i] + $all[$k];
    }
    $sections->save($payload);
    \App\Services\SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    $html = (string) test()->get('/')->assertOk()->getContent();
    preg_match('#<section class="sec hs hs-rail hs-bestselling.*?</section>#s', $html, $rail);
    expect($rail)->not->toBeEmpty('the Best Sellers rail did not draw');

    $inRail = cibCards($rail[0]);
    expect(count(array_filter(cibCards($html), fn ($t) => cibAttr($t, 'loading') === 'eager')))->toBe(2)
        ->and(cibAttr($inRail[0], 'loading'))->toBe('eager')
        ->and(cibAttr($inRail[1], 'loading'))->toBe('eager');
});
