<?php

declare(strict_types=1);

use App\Models\Product;
use App\Support\ImageVariants;

/**
 * Lane DK — a phone downloads a phone-sized photograph, or it downloads what it
 * downloads today, and never a URL that is not there.
 *
 * WHAT WAS WRONG. The tile photograph became a real <img> and stopped being
 * fetched twenty-one at a time (Lane DB, GridPhotoLoadingTest). What it did not
 * stop being was a 1000x1000 file painted into a frame measuring between 153
 * and 399 CSS pixels: on a 390px phone, nine photographs and 2.0MB of image
 * bytes to fill nine 187x180 boxes.
 *
 * THE SHAPE OF THE FIX, AND THEREFORE THE SHAPE OF THIS FILE. There is no queue
 * worker on this host and no shell, so smaller copies are made when an image is
 * uploaded and by a batch the owner runs from the Media Library — never while a
 * page is being rendered. That means a tile cannot assume a copy exists, and
 * the one thing it must never do is say one does when it does not: srcset with
 * `w` descriptors REPLACES src rather than supplementing it, so a single 404 in
 * there is a blank tile with no second chance. Most of the assertions below are
 * about the absence of a srcset, not the presence of one.
 *
 * WHY THE HTML IS READ WITH preg_match_all AND NOT WITH toContain. A class-name
 * search over a rendered page also matches the inlined stylesheet, which
 * mentions .ph-img by name. Everything here is asserted about <img> ELEMENTS
 * pulled out of the document, for the same reason GridPhotoLoadingTest strips
 * comments before reading CSS.
 */

/** @return list<string> every <img …> tag carrying $class, in document order */
function tilePhotos(string $html): array
{
    preg_match_all('/<img\b[^>]*>/i', $html, $m);

    return array_values(array_filter(
        $m[0],
        fn (string $tag) => preg_match('/\bclass\s*=\s*"[^"]*\bph-img\b[^"]*"/i', $tag) === 1
    ));
}

/** One attribute off a tag, or null when it is absent. */
function tagAttribute(string $tag, string $name): ?string
{
    return preg_match('/\b'.preg_quote($name, '/').'\s*=\s*"([^"]*)"/i', $tag, $m) === 1 ? $m[1] : null;
}

/**
 * A srcset value split into [url, descriptor] pairs.
 *
 * @return list<array{0: string, 1: string}>
 */
function srcsetCandidates(string $value): array
{
    $out = [];

    foreach (array_filter(array_map('trim', explode(',', $value))) as $candidate) {
        $parts = preg_split('/\s+/', $candidate);
        $out[] = [$parts[0], $parts[1] ?? ''];
    }

    return $out;
}

/** A real JPEG of a given size, written into the test's own public root. */
function writePhoto(string $relative, int $width, int $height = 0): string
{
    $height = $height ?: $width;
    $path = public_path($relative);
    @mkdir(dirname($path), 0755, true);

    $im = imagecreatetruecolor($width, $height);

    // Not a flat colour: a flat image resamples to a file so small that a
    // "the copy is smaller than the original" assertion would pass on nothing.
    for ($y = 0; $y < $height; $y += 4) {
        imagefilledrectangle($im, 0, $y, $width, $y + 3, (int) imagecolorallocate($im, ($y * 7) % 255, ($y * 13) % 255, ($y * 3) % 255));
    }

    imagejpeg($im, $path, 90);
    imagedestroy($im);

    return $path;
}

/** A catalogue where every visible product wears the given photograph. */
function seedCatalogueWithPhoto(string $image): void
{
    test()->seed(\Database\Seeders\DatabaseSeeder::class);

    Product::query()->visible()->get()->each(
        fn (Product $p) => $p->forceFill(['image' => $image])->save()
    );
}

/**
 * Empty a directory under the test's own public root.
 *
 * Only the two this file writes, by name. public/ under the per-process root
 * that tests/bootstrap.php builds also holds `build`, which is a SYMLINK back
 * into the checkout — a sweep that recursed through it would delete tracked
 * assets, which is the mistake that bootstrap's own sweeper documents.
 */
function sweepPublic(string $relative): void
{
    $root = public_path($relative);

    if (! is_dir($root) || is_link($root)) {
        return;
    }

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($entries as $entry) {
        $entry->isDir() && ! $entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }

    @rmdir($root);
}

beforeEach(function () {
    if (! ImageVariants::available()) {
        test()->markTestSkipped('this PHP has no GD, which is the thing under test');
    }

    /*
     * The public root that tests/bootstrap.php builds lives for the whole
     * process, not for one test, so files written by one test are on disk for
     * the next. That is exactly the wrong thing here: every assertion below is
     * about whether a particular copy exists, and a leftover from four tests
     * ago is an assertion that passes for the wrong reason — or, as it did
     * first time round, fails for one.
     */
    sweepPublic('uploads');
    sweepPublic(ImageVariants::DIR);
});

/* ------------------------------------------------ the fallback, first */

it('emits no srcset at all for a photograph that has no smaller copy', function () {
    // The original is there; nothing has ever made a copy of it. This is the
    // state of every photograph in the shop until the owner runs the batch, so
    // it is the state the tile has to render correctly.
    writePhoto('uploads/products/plain.jpg', 1000);
    seedCatalogueWithPhoto('/uploads/products/plain.jpg');

    $photos = tilePhotos((string) test()->get('/shop')->getContent());
    expect($photos)->not->toBeEmpty('the shop grid rendered no product tiles at all');

    foreach ($photos as $tag) {
        expect(tagAttribute($tag, 'srcset'))
            ->toBeNull('a tile offered a smaller copy that was never made: '.$tag);
        expect(tagAttribute($tag, 'sizes'))
            ->toBeNull('a tile described a layout width with no srcset to choose from: '.$tag);
        expect(tagAttribute($tag, 'src'))
            ->toBe('/uploads/products/plain.jpg', 'the tile stopped loading the original: '.$tag);
    }
});

it('never names a file that is not on disk, whatever the page', function () {
    // The decisive property, asserted over three different kinds of reference
    // on the same page: one photograph with copies, one without, and one on
    // somebody else's domain.
    writePhoto('uploads/products/has-copies.jpg', 1000);
    writePhoto('uploads/products/no-copies.jpg', 1000);
    ImageVariants::generate('/uploads/products/has-copies.jpg');

    seedCatalogueWithPhoto('/uploads/products/no-copies.jpg');

    $products = Product::query()->visible()->orderBy('id')->get();
    $products[0]->forceFill(['image' => '/uploads/products/has-copies.jpg'])->save();
    $products[1]->forceFill(['image' => 'https://cdn.example.com/elsewhere.jpg'])->save();

    $photos = tilePhotos((string) test()->get('/shop')->getContent());
    expect(count($photos))->toBeGreaterThan(2, 'need several tiles to cover all three cases');

    $offered = 0;

    foreach ($photos as $tag) {
        $srcset = tagAttribute($tag, 'srcset');

        if ($srcset === null) {
            continue;
        }

        foreach (srcsetCandidates($srcset) as [$url, $descriptor]) {
            $offered++;

            expect(is_file(public_path(ltrim($url, '/'))))
                ->toBeTrue('a srcset named a file this web root does not hold: '.$url);

            // And the descriptor must be the truth about that file, not a
            // number copied out of a constant: a `w` that overstates the pixels
            // behind it makes a browser pick the blurriest candidate on offer.
            $info = getimagesize(public_path(ltrim($url, '/')));
            expect((int) $info[0].'w')->toBe($descriptor, 'the width descriptor does not match the file: '.$url);
        }
    }

    expect($offered)->toBeGreaterThan(0, 'nothing was offered at all, so this proved nothing');
});

it('offers only the widths that exist, not every width it could have made', function () {
    writePhoto('uploads/products/partial.jpg', 1000);
    ImageVariants::generate('/uploads/products/partial.jpg');

    // A half-finished batch, a restored backup, a hand-deleted file: one copy
    // is gone and the other is not.
    $gone = public_path(ImageVariants::DIR.'/800/uploads/products/partial.jpg');
    expect(is_file($gone))->toBeTrue('the 800px copy was never made, so removing it proves nothing');
    unlink($gone);

    seedCatalogueWithPhoto('/uploads/products/partial.jpg');

    $tag = tilePhotos((string) test()->get('/shop')->getContent())[0];
    $candidates = srcsetCandidates((string) tagAttribute($tag, 'srcset'));

    expect(count($candidates))->toBe(1, 'the missing copy is still being offered: '.$tag);
    expect($candidates[0][1])->toBe('400w');
    expect(str_contains($candidates[0][0], '/800/'))->toBeFalse('the deleted 800px copy is in the srcset');
});

it('offers nothing for a photograph on another domain', function () {
    seedCatalogueWithPhoto('https://cdn.example.com/shot.jpg');

    foreach (tilePhotos((string) test()->get('/shop')->getContent()) as $tag) {
        expect(tagAttribute($tag, 'srcset'))
            ->toBeNull('a photograph on another origin was offered a copy from ours: '.$tag);
    }
});

/* --------------------------------------------------- what it does offer */

it('gives the tile a srcset and a sizes once the copies exist', function () {
    writePhoto('uploads/products/shot.jpg', 1000);
    expect(ImageVariants::generate('/uploads/products/shot.jpg')['made'])->toBe(2);

    seedCatalogueWithPhoto('/uploads/products/shot.jpg');

    $tag = tilePhotos((string) test()->get('/shop')->getContent())[0];

    expect(srcsetCandidates((string) tagAttribute($tag, 'srcset')))->toBe([
        ['/'.ImageVariants::DIR.'/400/uploads/products/shot.jpg', '400w'],
        ['/'.ImageVariants::DIR.'/800/uploads/products/shot.jpg', '800w'],
    ]);

    // Without `sizes` a `w` srcset is resolved against 100vw, which on a phone
    // means the largest candidate every time -- the opposite of the point.
    expect(tagAttribute($tag, 'sizes'))->toBe(ImageVariants::sizesAttribute());
    expect(str_contains((string) tagAttribute($tag, 'sizes'), 'vw'))
        ->toBeTrue('sizes must follow the viewport below the two-column breakpoint');
});

it('keeps the copies smaller than the original they came from', function () {
    $original = writePhoto('uploads/products/big.jpg', 1000);
    ImageVariants::generate('/uploads/products/big.jpg');

    foreach (ImageVariants::WIDTHS as $width) {
        $copy = public_path(ImageVariants::DIR.'/'.$width.'/uploads/products/big.jpg');

        expect(is_file($copy))->toBeTrue('no '.$width.'px copy was written');
        expect(getimagesize($copy)[0])->toBe($width, 'the '.$width.'px copy is not '.$width.'px wide');
        expect(filesize($copy))->toBeLessThan(
            filesize($original),
            'the '.$width.'px copy is not smaller than the original, so it saves nothing'
        );
    }
});

it('never makes a copy larger than the photograph it came from', function () {
    // A 300px logo has no honest 400px version. Upscaling it would put a
    // candidate in the srcset whose width descriptor promises detail that is
    // not in the file.
    writePhoto('uploads/products/tiny.jpg', 300);

    $result = ImageVariants::generate('/uploads/products/tiny.jpg');

    expect($result['made'])->toBe(0, 'a 300px original was blown up to 400px');
    expect(is_file(public_path(ImageVariants::DIR.'/400/uploads/products/tiny.jpg')))->toBeFalse();

    // And it is not left in the backlog for ever, being counted as work.
    expect(ImageVariants::isComplete('/uploads/products/tiny.jpg'))
        ->toBeTrue('a photograph too small to shrink is reported as unfinished work');
    expect(ImageVariants::srcsetFor('/uploads/products/tiny.jpg'))->toBe('');
});

it('does not move anything, because the tile still states no dimensions', function () {
    // CLS on this grid is zero and stays zero because .pc .ph is a fixed 180px
    // box and the <img> is out of flow inside it. Stating width and height here
    // would be stating the FILE's ratio for a box that does not have it, and it
    // is srcset that makes that tempting -- the copies are 400x400 and 800x800
    // and it looks safe to say so.
    writePhoto('uploads/products/shot.jpg', 1000);
    ImageVariants::generate('/uploads/products/shot.jpg');
    seedCatalogueWithPhoto('/uploads/products/shot.jpg');

    foreach (tilePhotos((string) test()->get('/shop')->getContent()) as $tag) {
        expect(tagAttribute($tag, 'width'))->toBeNull('the tile photograph states a width: '.$tag);
        expect(tagAttribute($tag, 'height'))->toBeNull('the tile photograph states a height: '.$tag);
    }
});

it('leaves the one eager tile eager and every other tile lazy', function () {
    // Lane DB's invariant, re-asserted with a srcset present, because srcset is
    // exactly the kind of change that gets copied onto every <img> in a file
    // along with whatever else is on the line.
    writePhoto('uploads/products/shot.jpg', 1000);
    ImageVariants::generate('/uploads/products/shot.jpg');
    seedCatalogueWithPhoto('/uploads/products/shot.jpg');

    $photos = tilePhotos((string) test()->get('/shop')->getContent());
    expect(count($photos))->toBeGreaterThan(1);

    $first = array_shift($photos);
    expect(tagAttribute($first, 'loading'))->toBe('eager');
    expect(tagAttribute($first, 'fetchpriority'))->toBe('high');
    expect(tagAttribute($first, 'srcset'))->not->toBeNull('the LCP tile is the one that most needs a smaller copy');

    foreach ($photos as $tag) {
        expect(tagAttribute($tag, 'loading'))->toBe('lazy', 'a tile below the first is not lazy: '.$tag);
        expect(tagAttribute($tag, 'fetchpriority'))->toBeNull('only the LCP candidate may claim priority: '.$tag);
    }
});

/* ------------------------------------------------------------- the paths */

it('refuses a path that climbs out of the web root', function () {
    /*
     * An image reference is operator data: it arrives from a CSV import and
     * from the product editor, and it decides a filesystem path here — one the
     * code READS an image from and one it WRITES an image to.
     *
     * The traversal is set up so that both halves would really work if the
     * guards were gone, because a hostile path naming a file that does not
     * exist proves nothing: every assertion below would pass over a class with
     * no path checking in it at all. storage/ is the nearest real directory
     * outside the web root — public_path('../storage/x') and
     * storage_path('x') are the same file.
     */
    $outside = storage_path('dk-outside.jpg');
    $im = imagecreatetruecolor(1000, 1000);

    for ($y = 0; $y < 1000; $y += 4) {
        imagefilledrectangle($im, 0, $y, 1000, $y + 3, (int) imagecolorallocate($im, $y % 255, 40, 90));
    }

    imagejpeg($im, $outside, 90);
    imagedestroy($im);

    /*
     * And a file exactly where `img-cache/400/../storage/...` resolves to, so
     * that a srcset built without the check would find something and offer it.
     * The decoy beside it is not decoration: POSIX resolves a path component at
     * a time, so `400/..` only cancels if `400` is a directory that exists, and
     * without it this setup would be a check that could not fail.
     */
    writePhoto(ImageVariants::DIR.'/400/uploads/products/decoy.jpg', 400);
    writePhoto(ImageVariants::DIR.'/storage/dk-outside.jpg', 400);

    $climb = '/../storage/dk-outside.jpg';

    expect(ImageVariants::isLocal($climb))->toBeFalse('a file outside the web root was treated as ours');
    expect(ImageVariants::srcsetFor($climb))->toBe('', 'a srcset was built from outside the web root');
    expect(ImageVariants::generate($climb)['made'])
        ->toBe(0, 'copies were made of a file outside the web root');

    // Nothing was written outside the cache's own tree either.
    expect(is_file(storage_path(ImageVariants::DIR.'/400/dk-outside.jpg')))->toBeFalse();

    foreach ([
        '/uploads/../../../../etc/passwd.jpg',
        '/uploads/products/../../../secret.jpg',
        "/uploads/products/x\0.jpg",
        '/./uploads/products/shot.jpg',
    ] as $hostile) {
        expect(ImageVariants::srcsetFor($hostile))->toBe('', 'srcset built for: '.$hostile);
        expect(ImageVariants::generate($hostile)['made'])->toBe(0, 'generated for: '.$hostile);
        expect(ImageVariants::isLocal($hostile))->toBeFalse('treated as ours: '.$hostile);
    }
});

it('refuses a type it cannot resample, and its own output', function () {
    foreach ([
        '/uploads/seo/logo.svg',        // XML, no pixels
        '/uploads/seo/spinner.gif',     // usually an animation; GD would flatten it
        '/uploads/products/shot.jpg?v=2', // a cache-buster the copy would not carry
        'uploads/products/shot.jpg',    // relative: a different file on every page
        '',
    ] as $reference) {
        expect(ImageVariants::srcsetFor($reference))->toBe('', 'srcset built for: '.$reference);
    }

    // And the cache is not an input to itself: a second pass must not start
    // making 400px copies of the 400px copies.
    writePhoto(ImageVariants::DIR.'/400/uploads/products/shot.jpg', 400);
    expect(ImageVariants::generate('/'.ImageVariants::DIR.'/400/uploads/products/shot.jpg')['made'])->toBe(0);
});

it('is idempotent, so an interrupted batch can simply be run again', function () {
    writePhoto('uploads/products/shot.jpg', 1000);

    expect(ImageVariants::generate('/uploads/products/shot.jpg'))
        ->toMatchArray(['made' => 2, 'skipped' => 0]);
    expect(ImageVariants::generate('/uploads/products/shot.jpg'))
        ->toMatchArray(['made' => 0, 'skipped' => 2]);
});

it('leaves no half-written file behind when it cannot finish', function () {
    // rename() is what makes a copy appear complete or not at all. The failure
    // here is a directory that cannot be created; what matters is that nothing
    // partial is left where the srcset would find it.
    writePhoto('uploads/products/shot.jpg', 1000);
    ImageVariants::generate('/uploads/products/shot.jpg');

    foreach (glob(public_path(ImageVariants::DIR.'/*/uploads/products/*')) ?: [] as $file) {
        expect(str_ends_with($file, '.part'))->toBeFalse('a part-file was left in the cache: '.$file);
    }
});

/* ------------------------------------------------------- the upload path */

it('gives a newly uploaded photograph its copies in the same request', function () {
    // There is no worker on this host, so this request is the only chance.
    $admin = \App\Models\AdminUser::create([
        'name' => 'Owner',
        'email' => 'dk-upload-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);

    $source = writePhoto('fixture-source.jpg', 1000);

    $response = test()->actingAs($admin, 'admin')->post('/admin-api/media/upload', [
        'file' => new \Illuminate\Http\UploadedFile($source, 'shot.jpg', 'image/jpeg', null, true),
        'folder' => 'products',
    ], ['Accept' => 'application/json']);

    $response->assertOk();
    expect($response->json('sized'))->toBe(2, 'the upload made no smaller copies');

    $filename = (string) $response->json('filename');

    foreach (ImageVariants::WIDTHS as $width) {
        expect(is_file(public_path(ImageVariants::DIR.'/'.$width.'/uploads/products/'.$filename)))
            ->toBeTrue('no '.$width.'px copy beside the upload');
    }
});
