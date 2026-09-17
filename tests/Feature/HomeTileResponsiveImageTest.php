<?php

/**
 * The homepage product strip, and the srcset it did not have.
 *
 * ── THE MEASUREMENT THAT FOUND IT
 *
 * Rendering /shop, / and a product page against a catalogue whose variants had
 * been generated, then counting <img> tags with and without a srcset:
 *
 *     PAGE /shop/                 imgs=1  with srcset=1  without=0
 *     PAGE /                      imgs=2  with srcset=0  without=2
 *     PAGE /product/<slug>/       imgs=1  with srcset=1  without=0
 *
 * The responsive-image work reached <x-product-card> (every grid) and
 * partials/product-gallery.blade.php (the product page) and stopped there. The
 * homepage draws its "spotted" strip from its own markup in
 * store/home.blade.php, so those four product photographs — on the most-visited
 * page of the shop — were still handing a 1000x1000 original to a box that is
 * never wider than about 424 CSS pixels. Measured cost of that original against
 * its copies: 346.7KB versus 65.7KB at 400w.
 *
 * ── WHAT IS PINNED
 *
 * That the strip offers the copies WHEN THEY EXIST, and that it emits nothing
 * at all when they do not — which is the property the whole pipeline rests on
 * (ImageVariants::srcsetFor() is built from the filesystem precisely so a
 * catalogue that has never run the batch renders exactly the markup it renders
 * today, rather than a srcset full of 404s).
 */

use App\Models\Product;
use App\Support\ImageVariants;

function htriImage(string $rel, int $w = 1000): void
{
    $path = public_path(ltrim($rel, '/'));
    @mkdir(dirname($path), 0755, true);

    $im = imagecreatetruecolor($w, $w);
    imagefilledrectangle($im, 0, 0, $w, $w, imagecolorallocate($im, 200, 120, 150));
    imagejpeg($im, $path, 85);
    imagedestroy($im);
}

/** The product the homepage strip actually draws, with an image on it. */
function htriProduct(string $rel): Product
{
    $p = Product::query()->where('status', 'publish')->where('is_visible', true)->firstOrFail();
    $p->forceFill(['image' => $rel])->save();

    return $p;
}

/** Every <img> on the page whose src is this file. */
function htriTagsFor(string $html, string $rel): array
{
    preg_match_all('/<img\b[^>]*>/i', $html, $m);

    return array_values(array_filter(
        $m[0],
        static fn (string $tag): bool => str_contains($tag, 'src="' . $rel . '"')
    ));
}

it('offers the phone-sized copies on the homepage strip once they exist', function () {
    $rel = '/uploads/htri/hero.jpg';
    htriImage($rel);
    htriProduct($rel);

    expect(ImageVariants::generate($rel)['made'])->toBe(2);

    $tags = htriTagsFor($this->get('/')->assertOk()->getContent(), $rel);

    expect($tags)->not->toBeEmpty();

    foreach ($tags as $tag) {
        expect($tag)->toContain('/img-cache/400/uploads/htri/hero.jpg 400w')
            ->and($tag)->toContain('/img-cache/800/uploads/htri/hero.jpg 800w')
            // The declaration, not just the candidates: a srcset with `w`
            // descriptors and no sizes is read as `sizes=100vw`, which makes
            // the browser pick the LARGEST candidate every time and costs more
            // than emitting nothing would have.
            //
            // WHICH declaration depends on which strip drew the tile — the
            // homepage carries two, `.ugc` (its own markup) and `.kbb-pgrid`
            // (partials/home/grid.blade.php), and they are different shapes
            // measured separately. Both are pinned; neither may be absent.
            ->and($tag)->toMatch(
                '/sizes="(?:'
                . preg_quote(ImageVariants::homeTileSizesAttribute(), '/')
                . '|'
                . preg_quote(ImageVariants::skinGridSizesAttribute(), '/')
                . ')"/'
            );
    }
});

it('emits no srcset on the homepage strip when no copies have been made', function () {
    // The state every catalogue is in before the owner runs Media Library ->
    // Image Sizes, and the state this shop is in today. A srcset naming a file
    // that is not there is worse than none: the browser fetches it, gets a 404,
    // and the tile is blank with no second candidate to fall back to.
    $rel = '/uploads/htri/uncopied.jpg';
    htriImage($rel);
    htriProduct($rel);

    $tags = htriTagsFor($this->get('/')->assertOk()->getContent(), $rel);

    expect($tags)->not->toBeEmpty();

    foreach ($tags as $tag) {
        expect($tag)->not->toContain('srcset')
            ->and($tag)->not->toContain('sizes');
    }
});
