<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ImageSizesApiController;
use App\Models\Brand;
use App\Models\Cart;
use App\Models\Product;
use App\Services\CartService;
use App\Support\ImageVariants;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Lane IM — the gallery strip loads thumbnails, not photographs.
 *
 * ── WHAT WAS WRONG ON THE SHOP ─────────────────────────────────────────────
 *
 * The owner, with a screenshot of a product page arrowing at the thumbnail
 * strip: "the products gallery thumbnails must load the thumbnail sizes, not
 * the full image, to reduce the page load."
 *
 * Two defects sat behind that sentence, and the first one is the reason the
 * second was invisible.
 *
 *   1. THE BATCH NEVER REACHED THE GALLERY. Admin\ImageSizesApiController is
 *      the only thing on this host that can make a smaller copy of a
 *      photograph that was already here, and `images()` walked
 *      `products.image` — the FEATURED image — and nothing else.
 *      `products.images`, the JSON array that IS the gallery, was never in the
 *      work list. So the batch could be driven to `done: true`, report
 *      `remaining: 0`, and leave every gallery shot in the catalogue with no
 *      copies at all. The strip then emitted no srcset — which is
 *      srcsetFor()'s correct behaviour, and exactly what made this impossible
 *      to see from the server — and each 66px square downloaded the whole
 *      photograph.
 *
 *      MEASURED IN CHROMIUM, after driving that controller to done: a
 *      five-shot gallery made 5 image requests for 1188.1 KB, of which four
 *      were untouched 1000x1000 originals at ~290 KB each, painted into a
 *      52x52 CSS box. 19.2 times the pixels the box can show. Only shot one
 *      had copies.
 *
 *   2. AND THE SMALLEST COPY WAS FOUR TIMES TOO BIG. ImageVariants::WIDTHS was
 *      [400, 800]. A 66px square at device-pixel-ratio 2 wants 124 real pixels
 *      and was being handed 400.
 *
 * ── THE MUTATION NOTES, ALL RUN ────────────────────────────────────────────
 *
 * Put `return $query->pluck('image');` back in ImageSizesApiController::images()
 * (the featured column alone) and "it sizes every shot in the gallery, not just
 * the featured image", "it offers a copy for every thumbnail in the strip" and
 * "it counts the gallery in what is left to do" all go red.
 *
 * Put `WIDTHS = [400, 800]` back and "it offers the strip a copy small enough
 * for a 66px square" goes red with "the smallest copy on offer carries 4.08x
 * the pixels a 66px square can show".
 *
 * Drop the `in_array($width, self::WIDTHS, true)` guard from variantUrl() and
 * "it will not build a path out of a width it did not generate" goes red with
 * "a path was built for width 0".
 *
 * Take `ImageVariants::variantUrl()` back out of EITHER
 * store/cart-inner.blade.php or partials/cart-drawer.blade.php and "it gives
 * the basket line thumbnail a copy instead of the photograph" goes red with
 * "a square on /cart is not drawing the 400px copy" — 1 where 2 is expected.
 *
 * All four were run, in this worktree, on 28 September 2026.
 */

/** A real JPEG of a given size, written into this process's own public root. */
function imPhoto(string $relative, int $width = 1000): string
{
    $path = public_path($relative);
    @mkdir(\dirname($path), 0755, true);

    $im = imagecreatetruecolor($width, $width);

    for ($y = 0; $y < $width; $y += 4) {
        imagefilledrectangle($im, 0, $y, $width, $y + 3, (int) imagecolorallocate($im, ($y * 7) % 255, ($y * 13) % 255, ($y * 3) % 255));
    }

    imagejpeg($im, $path, 90);
    imagedestroy($im);

    return $path;
}

/**
 * Empty a directory under this process's public root.
 *
 * By name only, and never through a link: public/ under the per-process root
 * that tests/bootstrap.php builds also holds `build`, which is a SYMLINK back
 * into the checkout.
 */
function imSweep(string $relative): void
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

/** A visible product wearing the given photographs. */
function imProduct(array $images, string $slug = ''): Product
{
    $brand = Brand::firstOrCreate(['slug' => 'im-anua'], ['name' => 'Anua']);

    return Product::create([
        'slug' => $slug !== '' ? $slug : 'im-'.uniqid(),
        'name' => 'Heartleaf Pore Control Cleansing Oil 200ml',
        'brand_id' => $brand->id,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
        'image' => $images[0],
        'images' => $images,
    ]);
}

/**
 * Drive the real batch to completion, the way the owner's screen drives it.
 *
 * Bounded at 200 turns so a cursor that stops advancing fails the test instead
 * of hanging the suite — which is the shape of the one bug a cursor rewrite can
 * introduce.
 *
 * @return array{made: int, turns: int}
 */
function imRunBatch(): array
{
    $runner = new ImageSizesApiController();
    $cursor = '';
    $made = 0;

    for ($turn = 1; $turn <= 200; $turn++) {
        $payload = json_decode(
            (string) $runner->run(Request::create('/', 'GET', ['after' => $cursor]))->getContent(),
            true
        );

        expect($payload['ok'] ?? false)->toBeTrue('the batch refused to run: '.json_encode($payload));

        $made += (int) $payload['made'];
        $cursor = (string) $payload['cursor'];

        if ($payload['done'] ?? false) {
            return ['made' => $made, 'turns' => $turn];
        }
    }

    throw new RuntimeException('the batch never reported done: the cursor is not advancing');
}

/** @return list<string> every <img …> tag carrying $class, in document order */
function imTags(string $html, string $class): array
{
    preg_match_all('/<img\b[^>]*>/i', $html, $m);

    return array_values(array_filter(
        $m[0],
        fn (string $tag) => preg_match('/\bclass\s*=\s*"[^"]*\b'.preg_quote($class, '/').'\b[^"]*"/i', $tag) === 1
    ));
}

/** One attribute off a tag, or null when it is absent. */
function imAttr(string $tag, string $name): ?string
{
    return preg_match('/\b'.preg_quote($name, '/').'\s*=\s*"([^"]*)"/i', $tag, $m) === 1 ? $m[1] : null;
}

/** @return array<string, string> a srcset value as [url => descriptor] */
function imCandidates(?string $value): array
{
    $out = [];

    foreach (array_filter(array_map('trim', explode(',', (string) $value))) as $candidate) {
        $parts = preg_split('/\s+/', $candidate);
        $out[$parts[0]] = $parts[1] ?? '';
    }

    return $out;
}

beforeEach(function () {
    if (! ImageVariants::available()) {
        test()->markTestSkipped('this PHP has no GD, which is the thing under test');
    }

    imSweep(ImageVariants::DIR);
    imSweep('uploads/im');
});

afterEach(function () {
    imSweep(ImageVariants::DIR);
    imSweep('uploads/im');
});

/* ─────────────────────────────── the batch reaches the gallery ─────────── */

it('sizes every shot in the gallery, not just the featured image', function () {
    /*
     * THE DEFECT, IN ONE ASSERTION. images() walked products.image alone, so a
     * five-shot gallery got copies of shot one and nothing else — and said it
     * had finished.
     *
     * MUTATION: put `return $query->pluck('image');` back and this is red with
     * "shot 2 of the gallery has no 200px copy".
     */
    $shots = [];

    for ($n = 1; $n <= 5; $n++) {
        imPhoto('uploads/im/shot-'.$n.'.jpg');
        $shots[] = '/uploads/im/shot-'.$n.'.jpg';
    }

    imProduct($shots);
    imRunBatch();

    foreach ($shots as $i => $shot) {
        foreach (ImageVariants::WIDTHS as $width) {
            expect(is_file(public_path(ImageVariants::DIR.'/'.$width.'/'.ltrim($shot, '/'))))
                ->toBeTrue('shot '.($i + 1).' of the gallery has no '.$width.'px copy');
        }
    }
});

it('counts the gallery in what is left to do, and reports zero only when it is', function () {
    // The other half of the same bug, and the half the owner would have seen:
    // a screen that said "0 remaining" over a catalogue where four shots in
    // five had never been touched.
    $shots = [];

    for ($n = 1; $n <= 4; $n++) {
        imPhoto('uploads/im/shot-'.$n.'.jpg');
        $shots[] = '/uploads/im/shot-'.$n.'.jpg';
    }

    imProduct($shots);

    $before = json_decode((string) (new ImageSizesApiController())->status()->getContent(), true);

    expect($before['total'])->toBe(4, 'the gallery shots are not counted as work at all');
    expect($before['remaining'])->toBe(4, 'the screen understates the backlog by the size of the gallery');

    imRunBatch();

    $after = json_decode((string) (new ImageSizesApiController())->status()->getContent(), true);

    expect($after['remaining'])->toBe(0);
    expect($after['done'])->toBe(4);
});

it('finishes, whatever order the gallery sorts in against the featured images', function () {
    /*
     * The cursor is the image reference itself walked ascending, and the work
     * list is now merged from two columns. A gallery shot that sorts BEFORE the
     * featured image of its own row is the case that a naive SQL filter skips
     * and a naive PHP filter loops on, so both directions are seeded here.
     */
    foreach (['a', 'm', 'z'] as $name) {
        imPhoto('uploads/im/'.$name.'.jpg');
    }

    // Featured 'm', gallery 'a' (sorts before it) and 'z' (sorts after).
    imProduct(['/uploads/im/m.jpg', '/uploads/im/a.jpg', '/uploads/im/z.jpg']);

    $run = imRunBatch();

    expect($run['made'])->toBe(3 * count(ImageVariants::WIDTHS), 'a shot was skipped or sized twice');

    foreach (['a', 'm', 'z'] as $name) {
        expect(ImageVariants::isComplete('/uploads/im/'.$name.'.jpg'))
            ->toBeTrue('/uploads/im/'.$name.'.jpg was never reached');
    }

    // And a second run does nothing at all, which is what "idempotent" has to
    // mean for a batch the owner may click twice.
    expect(imRunBatch()['made'])->toBe(0, 'a finished catalogue was sized again');
});

it('does not size the same photograph twice when two products share it', function () {
    // This catalogue reuses images across products. The work list is distinct
    // because a decode is the expensive thing here, not a stat.
    imPhoto('uploads/im/shared.jpg');

    imProduct(['/uploads/im/shared.jpg']);
    imProduct(['/uploads/im/other.jpg', '/uploads/im/shared.jpg']);
    imPhoto('uploads/im/other.jpg');

    expect(imRunBatch()['made'])->toBe(2 * count(ImageVariants::WIDTHS), 'a shared photograph was decoded more than once');
});

/* ───────────────────────────────── what the strip then renders ─────────── */

it('offers a copy for every thumbnail in the strip', function () {
    /*
     * MUTATION: put `return $query->pluck('image');` back in images() and this
     * is red with "thumbnail 2 still loads the full-size file".
     */
    $shots = [];

    for ($n = 1; $n <= 5; $n++) {
        imPhoto('uploads/im/shot-'.$n.'.jpg');
        $shots[] = '/uploads/im/shot-'.$n.'.jpg';
    }

    $product = imProduct($shots);
    imRunBatch();

    $html = (string) test()->get('/product/'.$product->slug)->assertOk()->getContent();
    $thumbs = imTags($html, 'gthumb-img');

    expect(count($thumbs))->toBe(5);

    foreach ($thumbs as $i => $tag) {
        expect(imAttr($tag, 'srcset'))
            ->not->toBeNull('thumbnail '.($i + 1).' still loads the full-size file: '.$tag);
        expect(imAttr($tag, 'sizes'))->toBe(ImageVariants::thumbSizesAttribute());
    }
});

it('offers the strip a copy small enough for a 66px square', function () {
    /*
     * The second defect. `.gthumb` is 66 CSS pixels (56 on a phone), so the
     * most a browser can ask for is 66 x 3 = 198 real pixels. WIDTHS started at
     * 400 — four times the area at the ratio 2 most handsets have.
     *
     * MUTATION: set WIDTHS back to [400, 800] and this is red with "the
     * smallest copy on offer is 400px for a box that can show 198".
     */
    $box = 66;
    $densest = 3;
    $wanted = $box * $densest;          // 198 real pixels, the most anything can ask for
    $smallest = min(ImageVariants::WIDTHS);

    /* ASSERTED AS AREA, AND WITH A BAND RATHER THAN A CEILING. Pixels are what
       is downloaded and area is what pixels come in, so a width one or two
       past the ask is not a defect — 200 against 198 is 1.02x the data and is
       the round number a directory should be named after. Four hundred is
       4.08x, which is the thing this is here to stop coming back.

       The lower bound matters as much: a copy NARROWER than 198 would be
       upscaled into the square on a ratio-3 handset, which is a soft thumbnail
       and a worse shop, not a faster one. */
    $area = ($smallest * $smallest) / ($wanted * $wanted);

    expect($smallest)->toBeGreaterThanOrEqual(
        $wanted,
        'the smallest copy is '.$smallest.'px for a square that shows '.$wanted.' — it would be upscaled'
    );
    expect($area)->toBeLessThan(
        1.5,
        'the smallest copy on offer carries '.round($area, 2).'x the pixels a '.$box.'px square can show'
    );

    // And the declaration the browser resolves against is the box, not the
    // viewport: a `w` srcset with no `sizes` resolves against 100vw and takes
    // the largest candidate every time, which is the opposite of the point.
    expect(ImageVariants::thumbSizesAttribute())->toBe($box.'px');

    imPhoto('uploads/im/shot.jpg');
    ImageVariants::generate('/uploads/im/shot.jpg');

    $candidates = imCandidates(ImageVariants::srcsetFor('/uploads/im/shot.jpg'));

    expect(array_values($candidates)[0])->toBe(min(ImageVariants::WIDTHS).'w', 'the smallest copy is not offered first');
    expect(array_key_first($candidates))
        ->toBe('/'.ImageVariants::DIR.'/'.min(ImageVariants::WIDTHS).'/uploads/im/shot.jpg');
});

it('draws a photograph that has no copy at all, from the original', function () {
    /*
     * The case that must never break, and the one most of this catalogue is in
     * until the batch has been run: an old import, a file that arrived by FTP,
     * an SVG. With `w` descriptors a browser picks a candidate and never reads
     * `src` again, so naming a file that is not there is a blank square with
     * nothing to fall back to.
     */
    $shots = [];

    for ($n = 1; $n <= 3; $n++) {
        imPhoto('uploads/im/bare-'.$n.'.jpg');
        $shots[] = '/uploads/im/bare-'.$n.'.jpg';
    }

    $product = imProduct($shots);

    // Deliberately NOT run through the batch.
    $html = (string) test()->get('/product/'.$product->slug)->assertOk()->getContent();

    foreach (imTags($html, 'gthumb-img') as $i => $tag) {
        expect(imAttr($tag, 'srcset'))->toBeNull('a copy was offered that is not on disk: '.$tag);
        expect(imAttr($tag, 'sizes'))->toBeNull('a sizes was offered with no srcset to resolve');
        expect(imAttr($tag, 'src'))->toBe($shots[$i], 'the original is not what the square falls back to');
        expect(is_file(public_path(ltrim((string) imAttr($tag, 'src'), '/'))))->toBeTrue();
    }

    // An SVG has no pixels to resample, and must draw exactly as it does today.
    $svg = imProduct(['/uploads/im/logo.svg']);
    $svgHtml = (string) test()->get('/product/'.$svg->slug)->assertOk()->getContent();

    foreach (imTags($svgHtml, 'gmain-img') as $tag) {
        expect(imAttr($tag, 'srcset'))->toBeNull('an SVG was offered a resized copy: '.$tag);
        expect(imAttr($tag, 'src'))->toBe('/uploads/im/logo.svg');
    }
});

it('keeps the main frame eager and the strip lazy, with the dimensions that hold it still', function () {
    // A width tier is exactly the kind of change that gets copied onto every
    // <img> in a file along with whatever else is on the line.
    imPhoto('uploads/im/a.jpg');
    imPhoto('uploads/im/b.jpg');

    $product = imProduct(['/uploads/im/a.jpg', '/uploads/im/b.jpg']);
    imRunBatch();

    $html = (string) test()->get('/product/'.$product->slug)->assertOk()->getContent();

    $main = imTags($html, 'gmain-img')[0];
    expect(imAttr($main, 'loading'))->toBe('eager');
    expect(imAttr($main, 'fetchpriority'))->toBe('high');

    foreach (imTags($html, 'gthumb-img') as $tag) {
        expect(imAttr($tag, 'loading'))->toBe('lazy', 'a thumbnail is not lazy: '.$tag);
        expect(imAttr($tag, 'decoding'))->toBe('async');
        expect(imAttr($tag, 'width'))->toBe('66', 'a thumbnail stopped reserving its space: '.$tag);
        expect(imAttr($tag, 'height'))->toBe('66');
    }
});

/* ─────────────────────────────────────────────── the single-URL sites ──── */

it('gives the basket line thumbnail a copy instead of the photograph', function () {
    /*
     * The same defect on a surface that cannot carry a srcset: `.kc-th` is a
     * 42px square drawn as a CSS background-image, and a background takes one
     * URL. A basket with five lines was pulling five full-resolution
     * photographs to paint five 42px squares.
     *
     * MUTATION: take ImageVariants::variantUrl() back out of
     * store/cart-inner.blade.php and this is red with "the basket line is still
     * drawing the full-size photograph".
     */
    imPhoto('uploads/im/basket.jpg');
    $product = imProduct(['/uploads/im/basket.jpg']);
    ImageVariants::generate('/uploads/im/basket.jpg');

    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED']);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 9900]);

    $html = (string) test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/cart')
        ->assertOk()
        ->getContent();

    /* COUNTED, AND ON THE DECODED DOCUMENT, for two reasons this test got wrong
       on the way to being written.

       DECODED, because the URL sits inside a `style` attribute and Blade
       escapes the quotes around it: what is in the page is
       `url(&#039;...&#039;)`, so a search for `url('...')` matches nothing at
       all and the assertion is green whatever the page does.

       COUNTED AND NOT SEARCHED, because /cart draws this line TWICE — once as
       the page's own `.cth` and once in the basket drawer that
       layouts/store.blade.php includes on every page. Looking for the copy
       anywhere is green while one of the two is still pulling the whole
       photograph, which is exactly what it did while the sweep was half done.

       MUTATION: take variantUrl() out of EITHER store/cart-inner.blade.php or
       partials/cart-drawer.blade.php and this is red at 1, not 2. */
    $plain = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);

    expect(substr_count($plain, "url('/".ImageVariants::DIR."/400/uploads/im/basket.jpg')"))
        ->toBe(2, 'a square on /cart is not drawing the 400px copy');
    expect(substr_count($plain, "url('/uploads/im/basket.jpg')"))
        ->toBe(0, 'a square on /cart is still drawing the full-size photograph as a background');
});

it('leaves the basket line on the original when there is no copy', function () {
    // Same page, no batch. A background-image has no second candidate, so a URL
    // that 404s is an empty square — this is the case that must not change.
    imPhoto('uploads/im/nocopy.jpg');
    $product = imProduct(['/uploads/im/nocopy.jpg']);

    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED']);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 9900]);

    $html = (string) test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/cart')
        ->assertOk()
        ->getContent();

    $plain = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);

    expect(str_contains($plain, ImageVariants::DIR))
        ->toBeFalse('a copy was named on a page where none exists');
    expect(substr_count($plain, "url('/uploads/im/nocopy.jpg')"))
        ->toBe(2, 'the basket line lost its photograph altogether');
});

/* ───────────────────────────────────────────────────── variantUrl ──────── */

it('hands back the original untouched whenever there is no copy to hand back', function () {
    imPhoto('uploads/im/plain.jpg');

    expect(ImageVariants::variantUrl('/uploads/im/plain.jpg', 400))->toBe('/uploads/im/plain.jpg');

    ImageVariants::generate('/uploads/im/plain.jpg');

    expect(ImageVariants::variantUrl('/uploads/im/plain.jpg', 400))
        ->toBe('/'.ImageVariants::DIR.'/400/uploads/im/plain.jpg');

    // Everything that is not this site's resizable file comes back as it went
    // in, because the alternative on a CSS background is an empty box.
    foreach ([
        '',
        '/uploads/im/logo.svg',
        'https://cdn.example.com/shot.jpg',
        'uploads/im/plain.jpg',
        '/uploads/im/plain.jpg?v=2',
        '/'.ImageVariants::DIR.'/400/uploads/im/plain.jpg',
    ] as $reference) {
        expect(ImageVariants::variantUrl($reference, 400))
            ->toBe($reference, 'a copy was invented for: '.$reference);
    }
});

it('will not build a path out of a width it did not generate', function () {
    /*
     * The width becomes a DIRECTORY NAME. A caller that could pass any integer
     * would be building a path segment out of one, and this endpoint-free
     * helper is reached from templates where a settings value could one day be
     * threaded through by mistake.
     *
     * MUTATION: drop the in_array($width, self::WIDTHS, true) guard and this is
     * red — a 401px "copy" is named for a file nothing ever wrote.
     */
    imPhoto('uploads/im/plain.jpg');
    ImageVariants::generate('/uploads/im/plain.jpg');

    /* AND THE FILE IS PUT THERE, or this proves nothing. Without the allowlist
       the only thing standing between a width and a URL is is_file(), so a
       width nothing generates comes back "safe" purely because the directory
       happens to be empty — and the assertion passes with the guard removed.
       A stale img-cache/401 from an older WIDTHS, a directory restored from a
       backup, or anything a future caller drops beside the cache is exactly
       that file existing. */
    foreach ([0, 401, 1000, 200000] as $width) {
        $planted = public_path(ImageVariants::DIR.'/'.$width.'/uploads/im/plain.jpg');
        @mkdir(\dirname($planted), 0755, true);
        copy(public_path(ImageVariants::DIR.'/'.min(ImageVariants::WIDTHS).'/uploads/im/plain.jpg'), $planted);
        expect(is_file($planted))->toBeTrue('the decoy for width '.$width.' was not written, so this proves nothing');
    }

    foreach ([0, -1, 401, 1000, 200000] as $width) {
        expect(ImageVariants::variantUrl('/uploads/im/plain.jpg', $width))
            ->toBe('/uploads/im/plain.jpg', 'a path was built for width '.$width);
    }

    // Every width this class DOES generate still answers, so the allowlist is
    // an allowlist and not an off switch.
    foreach (ImageVariants::WIDTHS as $width) {
        expect(ImageVariants::variantUrl('/uploads/im/plain.jpg', $width))
            ->toBe('/'.ImageVariants::DIR.'/'.$width.'/uploads/im/plain.jpg');
    }
});

it('will not climb out of the cache directory on its way to a copy', function () {
    /*
     * split() is the gate every method here goes through and it rejects "..",
     * an empty or "." segment, a NUL and a query. This asserts that variantUrl
     * is BEHIND that gate rather than beside it — the one way a new caller
     * could have opened a hole.
     */
    foreach ([
        '/uploads/../../etc/passwd.jpg',
        '/uploads/im/../../../secret.jpg',
        '/uploads/im/.././plain.jpg',
        "/uploads/im/plain.jpg\0.png",
        '/uploads/%2e%2e/%2e%2e/secret.jpg',
        '//evil.example.com/shot.jpg',
    ] as $hostile) {
        $answer = ImageVariants::variantUrl($hostile, 400);

        expect($answer)->toBe($hostile, 'a cache path was built for: '.$hostile);
        expect(str_contains($answer, ImageVariants::DIR))
            ->toBeFalse('a cache path was built for: '.$hostile);
    }
});
