<?php

declare(strict_types=1);

/**
 * A re-import must not send the shop's pictures back to the old site.
 *
 * THE DEFECT, ON THE LIVE SHOP (2 October 2026). The owner: "all the images in
 * the website front-end is loading from kbeautybliss.com ... in backend we
 * have all images available". Product thumbnails, galleries and the pictures
 * inside descriptions all pointed at
 * https://kbeautybliss.com/wp-content/uploads/... while every file was on this
 * server. The export names pictures by their old address; ProductImporter
 * writes the address as read; the picture pass had re-pointed them, and each
 * re-import of Products -- two that day, both asked of him -- put the old
 * address back. Only the two picture screens ever re-pointed anything.
 *
 * ImportRunner::keepPicturesLocal() now re-points, at the end of every file a
 * live import finishes, every picture whose file is already here.
 *
 * MUTATIONS, RUN: delete the keepPicturesLocal() call from ImportRunner and the
 * first case is red (products.image is the kbeautybliss.com address after the
 * import). Passing ABSENT proposals through as well stays green, correctly:
 * MediaRewrite::apply() itself skips anything that is not REWRITE, so a
 * picture with no file here is refused twice over -- the second case pins
 * that outcome whichever layer holds it.
 */

use App\Models\Product;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportRunner;
use App\Services\Import\MediaRewrite;
use Illuminate\Support\Facades\File;

function kplClean(): void
{
    foreach (['wp-content', 'uploads'] as $root) {
        if (is_dir(public_path($root))) {
            File::deleteDirectory(public_path($root));
        }
    }
}

function kplLand(string $url): void
{
    $relative = (string) MediaRewrite::uploadsRelativeTo($url);
    File::ensureDirectoryExists(dirname(public_path($relative)));
    file_put_contents(public_path($relative), "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01".str_repeat("\x2A", 48)."\xFF\xD9");
}

function kplImport(bool $dry = false): void
{
    $dir = base_path('tests/Fixtures/kbb-export');
    $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);

    (new ImportRunner)->run(new ImportOptions(
        directory: $dir,
        sourceTimezone: $manifest['source']['timezone'],
        adoptBySlug: true,
        dryRun: $dry,
        runKey: 'kpl-'.bin2hex(random_bytes(4)),
    ));
}

beforeEach(fn () => kplClean());
afterEach(fn () => kplClean());

it('keeps a product\'s pictures on this server across a re-import', function () {
    $main = 'https://kbeautybliss.com/wp-content/uploads/2019/03/ginseng-serum.jpg';
    kplLand($main);
    kplLand('https://kbeautybliss.com/wp-content/uploads/2019/03/ginseng-serum-2.jpg');

    kplImport();
    $first = Product::query()->where('wc_id', 4021)->value('image');
    expect(str_contains((string) $first, 'kbeautybliss.com'))->toBeFalse("after the first import: {$first}");

    // The owner's case: the same export imported again.
    kplImport();
    $product = Product::query()->where('wc_id', 4021)->first();

    expect(str_contains((string) $product->image, 'kbeautybliss.com'))->toBeFalse("after the re-import: {$product->image}")
        ->and(str_contains((string) $product->image, '2019/03/ginseng-serum.jpg'))->toBeTrue((string) $product->image);

    $gallery = implode(' ', (array) $product->images);
    expect(str_contains($gallery, 'kbeautybliss.com/wp-content/uploads/2019/03/ginseng-serum-2.jpg'))->toBeFalse($gallery);
});

it('leaves a picture whose file is not here on the old site, for the picture pass to fetch', function () {
    // Nothing landed: no file is here, so nothing may be pointed at this server.
    kplImport();

    expect(Product::query()->where('wc_id', 4021)->value('image'))
        ->toBe('https://kbeautybliss.com/wp-content/uploads/2019/03/ginseng-serum.jpg');
});
