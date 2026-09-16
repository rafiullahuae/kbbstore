<?php

declare(strict_types=1);

use App\Models\Product;

/**
 * Two properties of the storefront grid, pinned so they cannot quietly lapse:
 * how its photographs are loaded, and why nothing on the page moves.
 *
 * 1. EVERY PRODUCT PHOTOGRAPH IS A REAL <img>, AND ALL BUT ONE ARE LAZY.
 *
 *    components/product-card.blade.php used to paint the photograph as a CSS
 *    background on .ph. A background cannot be lazy-loaded, so /shop fetched
 *    every tile's photograph the moment the page opened: measured against a
 *    671-product catalogue, twenty-one photographs and 4.4MB, of which four
 *    are above the fold on a 390px phone. It also cannot be seen by the
 *    preload scanner and is not indexable by image search.
 *
 *    The card that is the Largest Contentful Paint candidate -- the first tile
 *    of the shop grid -- is the single exception, and says so with
 *    loading="eager" and fetchpriority="high". Nothing else may claim
 *    priority: the related-products grid on a product page is a thousand
 *    pixels below the fold and must never outrank the product's own shot.
 *
 * 2. NEITHER THE TILE NOR THE NAV BAR CAN CHANGE HEIGHT AFTER FIRST PAINT.
 *
 *    This is the Cumulative Layout Shift half, and it is asserted as the two
 *    mechanisms rather than as a score, because a score is a thing you
 *    measured once on one machine.
 *
 *    The tile: .pc .ph is a fixed-height box and .pc .ph-img is taken out of
 *    flow inside it, so the photograph's own dimensions never reach layout.
 *    The old background got this right by accident and the conversion had to
 *    keep it.
 *
 *    The nav bar: nav-fit.js lowers --nav-scale once the bundle has run so
 *    that eleven menu items fit on one row. Everything it touches is
 *    horizontal except font-size, and while the line box was allowed to follow
 *    font-size the bar lost six pixels of height after first paint and shoved
 *    the entire page up with it -- 0.236 on the home page at 1280x800, a
 *    failing CLS, and 0.000 on a phone because .mbar is display:none there.
 *    So: no property that decides this element's height may reference
 *    --nav-scale.
 *
 * WHY THE CSS IS READ WITH ITS COMMENTS STRIPPED. The comments beside both
 * rules describe the thing they forbid, in the words they forbid it in. A
 * guard that greps the raw file reads its own explanation as code and passes
 * over a regression; five lanes here have now been bitten by that shape of
 * mistake. cssRules() below removes /* ... *\/ before anything looks at a
 * declaration, and the rules themselves are additionally worded so that a
 * failure of the stripper could not fake a pass either.
 */

/** kbb.css with every comment removed. */
function cssSource(): string
{
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));

    return (string) preg_replace('#/\*.*?\*/#s', '', $css);
}

/**
 * The declaration block of one rule, by exact selector.
 *
 * Exact, not "contains": `.pc .ph` and `.pc .ph-img` are different rules and a
 * substring match would hand back whichever came first.
 *
 * @return string the text between { and }, or '' when the selector is absent
 */
function cssBlock(string $selector): string
{
    $css = cssSource();
    $pattern = '/(?:^|[},;])\s*' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/m';

    return preg_match($pattern, $css, $m) === 1 ? trim($m[1]) : '';
}

/**
 * One declaration's value out of a block, or null.
 *
 * Values here contain calc() with spaces and commas in them, so the value runs
 * to the first semicolon at parenthesis depth zero rather than to the first
 * semicolon.
 */
function cssValue(string $block, string $property): ?string
{
    $offset = 0;
    $length = strlen($block);

    while ($offset < $length) {
        $end = strpos($block, ';', $offset);
        $declaration = substr($block, $offset, $end === false ? null : $end - $offset);
        $offset = $end === false ? $length : $end + 1;

        // A semicolon can only end a declaration at depth zero.
        if (substr_count($declaration, '(') !== substr_count($declaration, ')')) {
            continue;
        }

        [$name, $value] = array_pad(explode(':', $declaration, 2), 2, null);

        if ($value !== null && strtolower(trim($name)) === strtolower($property)) {
            return trim($value);
        }
    }

    return null;
}

/**
 * A shorthand value split into its components, respecting parentheses.
 *
 * "13px calc(13px * var(--nav-scale))" is two components, not four.
 *
 * @return list<string>
 */
function cssComponents(string $value): array
{
    $parts = [];
    $current = '';
    $depth = 0;

    foreach (str_split($value) as $ch) {
        if ($ch === '(') {
            $depth++;
        } elseif ($ch === ')') {
            $depth--;
        }

        if ($depth === 0 && ($ch === ' ' || $ch === "\t" || $ch === "\n")) {
            if ($current !== '') {
                $parts[] = $current;
                $current = '';
            }

            continue;
        }

        $current .= $ch;
    }

    if ($current !== '') {
        $parts[] = $current;
    }

    return $parts;
}

/**
 * @return list<string> every <img …> tag carrying $class, in document order
 */
function imagesWithClass(string $html, string $class): array
{
    preg_match_all('/<img\b[^>]*>/i', $html, $m);

    return array_values(array_filter(
        $m[0],
        fn (string $tag) => preg_match('/\bclass\s*=\s*"[^"]*\b' . preg_quote($class, '/') . '\b[^"]*"/i', $tag) === 1
    ));
}

/** @return list<string> the style attribute of every .ph frame on the page */
function frameStyles(string $html): array
{
    preg_match_all('/<div\b[^>]*\bclass\s*=\s*"ph"[^>]*>/i', $html, $m);

    return array_map(
        fn (string $tag) => preg_match('/\bstyle\s*=\s*"([^"]*)"/i', $tag, $s) === 1 ? $s[1] : '',
        $m[0]
    );
}

/** A catalogue whose products all carry a photograph. */
function seedPhotographedCatalogue(): void
{
    test()->seed(\Database\Seeders\DatabaseSeeder::class);

    Product::query()->visible()->get()->each(function (Product $p, int $i) {
        $p->forceFill(['image' => 'https://cdn.example.com/shot-' . $i . '.jpg'])->save();
    });
}

it('paints every product tile photograph as an img and not as a CSS background', function () {
    seedPhotographedCatalogue();

    $html = (string) test()->get('/shop')->getContent();

    $frames = frameStyles($html);
    expect($frames)->not->toBeEmpty('the shop grid rendered no product tiles at all');

    foreach ($frames as $style) {
        expect(str_contains($style, 'url('))
            ->toBeFalse('a product tile still paints its photograph as a CSS background: ' . $style);
    }

    $photos = imagesWithClass($html, 'ph-img');
    expect(count($photos))
        ->toBe(count($frames), 'every tile has a photograph, so every frame should carry one <img>');

    foreach ($photos as $tag) {
        expect(preg_match('/\balt\s*=\s*"\s*"/i', $tag))
            ->toBe(0, 'a product tile photograph has an empty alt: ' . $tag);
    }
});

it('loads only the first tile of the shop grid eagerly and lazies the rest', function () {
    seedPhotographedCatalogue();

    $photos = imagesWithClass((string) test()->get('/shop')->getContent(), 'ph-img');
    expect(count($photos))->toBeGreaterThan(1, 'need more than one tile to tell eager from lazy');

    $first = array_shift($photos);

    expect(str_contains($first, 'loading="eager"'))
        ->toBeTrue('the first tile is the LCP candidate and must not be lazy: ' . $first);
    expect(str_contains($first, 'fetchpriority="high"'))
        ->toBeTrue('the first tile should be fetched at high priority: ' . $first);

    foreach ($photos as $tag) {
        expect(str_contains($tag, 'loading="lazy"'))
            ->toBeTrue('a tile below the first is not lazy-loaded: ' . $tag);
        expect(str_contains($tag, 'fetchpriority="high"'))
            ->toBeFalse('only the LCP candidate may claim high priority: ' . $tag);
    }
});

it('never gives a related-products tile priority over the product page shot', function () {
    seedPhotographedCatalogue();

    $product = Product::query()->visible()->firstOrFail();
    $html = (string) test()->get('/product/' . $product->slug . '/')->getContent();

    $photos = imagesWithClass($html, 'ph-img');
    expect($photos)->not->toBeEmpty('the product page rendered no related-products grid');

    foreach ($photos as $tag) {
        expect(str_contains($tag, 'loading="lazy"'))
            ->toBeTrue('a related-products tile is not lazy-loaded: ' . $tag);
        expect(str_contains($tag, 'fetchpriority'))
            ->toBeFalse('a related-products tile claims fetch priority: ' . $tag);
    }
});

it('reserves the tile photograph frame in CSS and keeps the img out of flow', function () {
    $frame = cssBlock('.pc .ph');
    expect($frame)->not->toBe('', 'the .pc .ph rule has gone from kbb.css');

    $height = cssValue($frame, 'height');
    expect($height)->not->toBeNull('.pc .ph must declare a height, or the tile sizes itself from the photograph');
    expect(preg_match('/^\d+(\.\d+)?px$/', (string) $height))
        ->toBe(1, '.pc .ph height must be a fixed length so the space is reserved before the bytes arrive, got: ' . $height);

    $img = cssBlock('.pc .ph-img');
    expect($img)->not->toBe('', 'the .pc .ph-img rule has gone from kbb.css');
    expect(cssValue($img, 'position'))
        ->toBe('absolute', 'the tile photograph must be out of flow, or its dimensions reach layout');
    expect(cssValue($img, 'inset'))
        ->toBe('0', 'the tile photograph must fill its reserved frame');
});

it('keeps the nav bar height independent of the scale nav-fit applies', function () {
    $block = cssBlock('.navlink');
    expect($block)->not->toBe('', 'the .navlink rule has gone from kbb.css');

    // The line box is what font-size used to drag down with it.
    $lineHeight = cssValue($block, 'line-height');
    expect($lineHeight)->not->toBeNull(
        '.navlink must fix its line-height, or shrinking the font shortens the bar and moves the page'
    );
    expect(preg_match('/^\d+(\.\d+)?px$/', (string) $lineHeight))
        ->toBe(1, '.navlink line-height must be a fixed length, got: ' . $lineHeight);

    // And nothing else that decides the element's height may follow the scale.
    $vertical = [];

    foreach (['height', 'min-height', 'max-height', 'padding-top', 'padding-bottom',
        'border-top-width', 'border-bottom-width', 'line-height'] as $property) {
        $value = cssValue($block, $property);

        if ($value !== null) {
            $vertical[$property] = $value;
        }
    }

    // padding's shorthand: components one and three are the vertical pair.
    $padding = cssValue($block, 'padding');

    if ($padding !== null) {
        $parts = cssComponents($padding);
        $vertical['padding (top)'] = $parts[0] ?? '';
        $vertical['padding (bottom)'] = $parts[2] ?? $parts[0] ?? '';
    }

    foreach ($vertical as $property => $value) {
        expect(str_contains($value, '--nav-scale'))
            ->toBeFalse($property . ' follows --nav-scale, so the bar changes height when nav-fit.js runs: ' . $value);
    }
});
