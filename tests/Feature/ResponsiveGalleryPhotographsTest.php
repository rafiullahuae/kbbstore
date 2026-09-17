<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Product;
use App\Support\ImageVariants;

/**
 * Lane DN — the product page's main photograph is served at the size it is
 * drawn at, or at the size it is served at today, and never at a URL that is
 * not there.
 *
 * WHAT WAS WRONG. Lane DK gave the shop tiles phone-sized copies and left the
 * PRODUCT page — where the single largest asset on the site is — serving the
 * full-size original into a frame about 350 CSS pixels wide on a handset. The
 * partial emitted no srcset at all.
 *
 * WHY THIS IS NOT JUST "THE TILE CHANGE AGAIN". Three things are different at
 * this size, and each is a way to make the page WORSE while appearing to
 * optimise it:
 *
 *   1. The frame is ~562 CSS pixels, not 399. At device-pixel-ratio 2 a browser
 *      wants 1124, and a srcset offering only 400w and 800w would hand it the
 *      800 — a softer hero than the page serves today. The original has to be
 *      a candidate here, with its real width stated.
 *   2. The main <img> is SWAPPED by pdp.js when a thumbnail is tapped, and
 *      srcset outranks src. An <img> whose src is reassigned and whose srcset
 *      is left alone goes on showing the previous photograph.
 *   3. The <head> preloads the original unconditionally. Once the <img> can
 *      resolve to a smaller candidate, that hint is not a wasted hint, it is a
 *      whole second download of the biggest file on the page.
 *
 * WHY THE HTML IS READ WITH preg_match_all AND NOT WITH str_contains. A search
 * for a class name over a rendered page also matches the inlined stylesheet,
 * which names .gmain-img and .gthumb-img. Everything here is asserted about
 * ELEMENTS pulled out of the document.
 */

/** @return list<string> every <img …> tag carrying $class, in document order */
function dnTags(string $html, string $class): array
{
    preg_match_all('/<img\b[^>]*>/i', $html, $m);

    return array_values(array_filter(
        $m[0],
        fn (string $tag) => preg_match('/\bclass\s*=\s*"[^"]*\b'.preg_quote($class, '/').'\b[^"]*"/i', $tag) === 1
    ));
}

/** @return list<string> every <div class="gthumb …"> opening tag */
function dnThumbDivs(string $html): array
{
    preg_match_all('/<div\b[^>]*\bclass\s*=\s*"gthumb[^"]*"[^>]*>/i', $html, $m);

    return $m[0];
}

/** @return list<string> every <link rel="preload" as="image" …> tag */
function dnPreloads(string $html): array
{
    preg_match_all('/<link\b[^>]*\brel\s*=\s*"preload"[^>]*>/i', $html, $m);

    return array_values(array_filter($m[0], fn (string $t) => str_contains($t, 'as="image"')));
}

/** One attribute off a tag, or null when it is absent. */
function dnAttr(string $tag, string $name): ?string
{
    return preg_match('/\b'.preg_quote($name, '/').'\s*=\s*"([^"]*)"/i', $tag, $m) === 1 ? $m[1] : null;
}

/**
 * A srcset value as [url => descriptor].
 *
 * @return array<string, string>
 */
function dnCandidates(?string $value): array
{
    $out = [];

    foreach (array_filter(array_map('trim', explode(',', (string) $value))) as $candidate) {
        $parts = preg_split('/\s+/', $candidate);
        $out[$parts[0]] = $parts[1] ?? '';
    }

    return $out;
}

/** A real JPEG of a given size, written into this process's own public root. */
function dnWritePhoto(string $relative, int $width, int $height = 0): string
{
    $height = $height ?: $width;
    $path = public_path($relative);
    @mkdir(dirname($path), 0755, true);

    $im = imagecreatetruecolor($width, $height);

    for ($y = 0; $y < $height; $y += 4) {
        imagefilledrectangle($im, 0, $y, $width, $y + 3, (int) imagecolorallocate($im, ($y * 7) % 255, ($y * 13) % 255, ($y * 3) % 255));
    }

    imagejpeg($im, $path, 90);
    imagedestroy($im);

    return $path;
}

/**
 * Empty a directory under this process's public root.
 *
 * By name only. public/ under the per-process root that tests/bootstrap.php
 * builds also holds `build`, which is a SYMLINK back into the checkout; a sweep
 * that followed it would delete tracked assets.
 */
function dnSweep(string $relative): void
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

/** A visible product wearing the given photographs, and the page it renders. */
function dnPage(array $images): string
{
    $brand = Brand::firstOrCreate(['slug' => 'dn-anua'], ['name' => 'Anua']);

    $product = Product::create([
        'slug' => 'dn-'.uniqid(),
        'name' => 'Heartleaf Pore Control Cleansing Oil 200ml',
        'brand_id' => $brand->id,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
        'image' => $images[0],
        'images' => $images,
    ]);

    return test()->get('/product/'.$product->slug)->assertOk()->getContent();
}

beforeEach(function () {
    if (! ImageVariants::available()) {
        test()->markTestSkipped('this PHP has no GD, which is the thing under test');
    }

    dnSweep(ImageVariants::DIR);
    dnSweep('uploads/dn');
});

afterEach(function () {
    dnSweep(ImageVariants::DIR);
    dnSweep('uploads/dn');
});

/* ------------------------------------------- what it declines to offer */

it('offers no srcset at all when no copy of the photograph exists', function () {
    // The important case, and most of this catalogue: the copies are made on
    // upload and by a batch, so a photograph that predates both has none. With
    // `w` descriptors a browser picks a candidate and never reads src again, so
    // naming a file that is not there is a blank hero with no second chance.
    dnWritePhoto('uploads/dn/lonely.jpg', 1000);

    $html = dnPage(['/uploads/dn/lonely.jpg']);
    $main = dnTags($html, 'gmain-img')[0];

    expect(dnAttr($main, 'srcset'))->toBeNull('a srcset was offered with no copies on disk');
    expect(dnAttr($main, 'sizes'))->toBeNull('a sizes was offered with no srcset to resolve');
    expect(dnAttr($main, 'src'))->toBe('/uploads/dn/lonely.jpg');
});

it('offers nothing for a photograph on another domain', function () {
    $html = dnPage(['https://cdn.example.com/shot.jpg']);

    foreach (dnTags($html, 'gmain-img') as $tag) {
        expect(dnAttr($tag, 'srcset'))
            ->toBeNull('a photograph on another origin was offered a copy from ours: '.$tag);
    }
});

it('offers nothing when the original cannot be measured', function () {
    /*
     * The copies are on disk and the original is gone -- a half-restored
     * backup, a package that removed files; this repository has had both. The
     * copies alone would be a valid srcset, and a silent downgrade of the hero
     * on every high-density screen. Saying nothing keeps today's photograph.
     */
    dnWritePhoto('uploads/dn/vanished.jpg', 1000);
    ImageVariants::generate('/uploads/dn/vanished.jpg');
    @unlink(public_path('uploads/dn/vanished.jpg'));

    expect(ImageVariants::detailSrcsetFor('/uploads/dn/vanished.jpg'))->toBe('');
});

/* ------------------------------------------------- what it does offer */

it('gives the main photograph the copies AND the original it came from', function () {
    dnWritePhoto('uploads/dn/shot.jpg', 1000);
    expect(ImageVariants::generate('/uploads/dn/shot.jpg')['made'])->toBe(2);

    $html = dnPage(['/uploads/dn/shot.jpg']);
    $main = dnTags($html, 'gmain-img')[0];

    expect(dnCandidates(dnAttr($main, 'srcset')))->toBe([
        '/'.ImageVariants::DIR.'/400/uploads/dn/shot.jpg' => '400w',
        '/'.ImageVariants::DIR.'/800/uploads/dn/shot.jpg' => '800w',
        '/uploads/dn/shot.jpg' => '1000w',
    ]);

    // The original is what stops this being a downgrade: the frame is ~562 CSS
    // pixels, so at ratio 2 the browser asks for 1124 and must have something
    // bigger than 800 to answer with.
    expect(dnAttr($main, 'sizes'))->toBe(ImageVariants::detailSizesAttribute());
});

it('declares a frame that follows the viewport, not one flat number', function () {
    // Without `sizes` a `w` srcset resolves against 100vw, which on a phone
    // means the largest candidate every time -- the opposite of the point. And
    // the product frame is one column below 880px and two above it, so a single
    // flat figure is wrong on one side of that line whichever figure is picked.
    $sizes = ImageVariants::detailSizesAttribute();

    expect(str_contains($sizes, 'vw'))->toBeTrue('the frame is declared as a constant at every width');
    expect(str_contains($sizes, '880px'))->toBeTrue('the one-column breakpoint is not accounted for');
});

it('states the original at its real width, whatever that is', function () {
    /*
     * An 800px original gets an honest 400w copy and no 800w one -- generate()
     * refuses to upscale, and 800 is not wider than 800. So the list is the one
     * copy plus the original AT 800w, not at the 1000 the markup's width
     * attribute says: that attribute states the BOX's ratio, and a width
     * descriptor taken from it would promise pixels the file does not have,
     * which is how a browser ends up choosing the blurriest candidate on offer.
     */
    dnWritePhoto('uploads/dn/mid.jpg', 800);
    ImageVariants::generate('/uploads/dn/mid.jpg');

    $candidates = dnCandidates(ImageVariants::detailSrcsetFor('/uploads/dn/mid.jpg'));

    expect($candidates)->toBe([
        '/'.ImageVariants::DIR.'/400/uploads/dn/mid.jpg' => '400w',
        '/uploads/dn/mid.jpg' => '800w',
    ]);
});

it('never lets two candidates claim the same width', function () {
    // Two candidates at one descriptor is a choice a browser cannot make, and
    // the natural way to create one is to list the original beside a copy that
    // is already as wide as it is.
    foreach ([1000, 800, 600, 401] as $width) {
        $name = 'uploads/dn/w'.$width.'.jpg';
        dnWritePhoto($name, $width);
        ImageVariants::generate('/'.$name);

        $descriptors = array_values(dnCandidates(ImageVariants::detailSrcsetFor('/'.$name)));

        expect(count(array_unique($descriptors)))
            ->toBe(count($descriptors), 'two candidates claim the same width for a '.$width.'px original');
    }
});

it('gives the thumbnails copies sized for a 66px square', function () {
    dnWritePhoto('uploads/dn/a.jpg', 1000);
    dnWritePhoto('uploads/dn/b.jpg', 1000);
    ImageVariants::generate('/uploads/dn/a.jpg');
    ImageVariants::generate('/uploads/dn/b.jpg');

    $html = dnPage(['/uploads/dn/a.jpg', '/uploads/dn/b.jpg']);
    $thumbs = dnTags($html, 'gthumb-img');

    expect(count($thumbs))->toBeGreaterThan(1);

    foreach ($thumbs as $tag) {
        expect(dnAttr($tag, 'srcset'))->not->toBeNull('a thumbnail still loads the full-size file: '.$tag);
        expect(dnAttr($tag, 'sizes'))->toBe(ImageVariants::thumbSizesAttribute());

        // A 66px box has no use for the original, so it is not offered one --
        // that is srcsetFor()'s list, not detailSrcsetFor()'s.
        foreach (dnCandidates(dnAttr($tag, 'srcset')) as $url => $descriptor) {
            expect(str_contains($url, '/'.ImageVariants::DIR.'/'))
                ->toBeTrue('a thumbnail was offered something other than a copy: '.$url);
        }
    }
});

/* ------------------------------------------------------- the swap */

it('hands each thumbnail the srcset the main frame will need', function () {
    /*
     * THE BUG THIS EXISTS FOR. pdp.js swaps the main shot by assigning to the
     * <img>'s src. srcset OUTRANKS src, so an <img> that keeps the previous
     * shot's srcset keeps showing the previous shot -- a gallery that silently
     * stops working, and nothing rendered server-side would look wrong.
     */
    dnWritePhoto('uploads/dn/a.jpg', 1000);
    dnWritePhoto('uploads/dn/b.jpg', 1000);
    ImageVariants::generate('/uploads/dn/a.jpg');
    ImageVariants::generate('/uploads/dn/b.jpg');

    $html = dnPage(['/uploads/dn/a.jpg', '/uploads/dn/b.jpg']);
    $divs = dnThumbDivs($html);

    expect(count($divs))->toBeGreaterThan(1);

    foreach ($divs as $div) {
        $image = dnAttr($div, 'data-image');

        if ($image === null || $image === '') {
            continue;
        }

        expect(dnAttr($div, 'data-srcset'))->toBe(
            ImageVariants::detailSrcsetFor($image),
            'the thumbnail carries a different list from the one the frame needs'
        );
        expect(dnAttr($div, 'data-sizes'))->toBe(ImageVariants::detailSizesAttribute());
    }
});

it('moves the srcset with the src when the gallery swaps', function () {
    /*
     * The other half, in the only place it exists: the assignment is in a
     * module the suite does not execute. Read as SOURCE -- the file is full of
     * prose about srcset, so a plain search would match the comments.
     */
    $source = (string) file_get_contents(resource_path('js/kbb/pdp.js'));
    $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

    expect(preg_match('/img\.srcset\s*=/', (string) $code))
        ->toBe(1, 'the gallery swap sets src without setting srcset, so the old photograph stays on screen');

    // `|| ''` rather than a plain assignment: clearing matters as much as
    // setting. Swapping to a shot with no copies must drop the previous shot's
    // candidates, or they go on describing a file they are not copies of.
    expect(preg_match("/img\.srcset\s*=\s*[^;]*\|\|\s*''/", (string) $code))
        ->toBe(1, 'the swap cannot clear the srcset, so a shot with no copies inherits the last one\'s');
});

/* ------------------------------------------------------ the preload */

it('leaves the plain preload exactly as it was when there is no srcset', function () {
    // ProductSeoTest asserts this tag byte for byte and belongs to another
    // lane. A product with no copies on disk must still get that exact form.
    dnWritePhoto('uploads/dn/lonely.jpg', 1000);

    $html = dnPage(['/uploads/dn/lonely.jpg']);
    $preload = dnPreloads($html)[0];

    expect($preload)->toBe('<link rel="preload" as="image" href="/uploads/dn/lonely.jpg" fetchpriority="high">');
});

it('does not make the preload fetch a second copy of the hero', function () {
    /*
     * A preload naming the original while the <img> resolves to an 800w copy
     * is not a wasted hint: it is an EXTRA download of the largest file on the
     * page, on every product view, which is more than the copies save.
     * Identical lists make the browser resolve both to one candidate.
     */
    dnWritePhoto('uploads/dn/shot.jpg', 1000);
    ImageVariants::generate('/uploads/dn/shot.jpg');

    $html = dnPage(['/uploads/dn/shot.jpg']);
    $preload = dnPreloads($html)[0];
    $main = dnTags($html, 'gmain-img')[0];

    expect(dnAttr($preload, 'imagesrcset'))->toBe(
        dnAttr($main, 'srcset'),
        'the preload and the gallery would resolve to different files'
    );
    expect(dnAttr($preload, 'imagesizes'))->toBe(dnAttr($main, 'sizes'));
    expect(dnAttr($preload, 'href'))->toBe(dnAttr($main, 'src'));
});

/* ---------------------------------------------- what must not regress */

it('keeps the main shot eager and high priority and every thumbnail lazy', function () {
    // srcset is exactly the kind of change that gets copied onto every <img>
    // in a file along with whatever else is on the line, so the loading
    // invariants are re-asserted with one present.
    dnWritePhoto('uploads/dn/a.jpg', 1000);
    dnWritePhoto('uploads/dn/b.jpg', 1000);
    ImageVariants::generate('/uploads/dn/a.jpg');
    ImageVariants::generate('/uploads/dn/b.jpg');

    $html = dnPage(['/uploads/dn/a.jpg', '/uploads/dn/b.jpg']);
    $main = dnTags($html, 'gmain-img')[0];

    expect(dnAttr($main, 'loading'))->toBe('eager');
    expect(dnAttr($main, 'fetchpriority'))->toBe('high');
    expect(dnAttr($main, 'srcset'))->not->toBeNull('the LCP image is the one that most needs a smaller copy');

    foreach (dnTags($html, 'gthumb-img') as $tag) {
        expect(dnAttr($tag, 'loading'))->toBe('lazy', 'a thumbnail below the hero is not lazy: '.$tag);
        expect(dnAttr($tag, 'fetchpriority'))->toBeNull('only the LCP may claim priority: '.$tag);
    }
});

it('still states the dimensions that hold the layout still', function () {
    // .gmain is aspect-ratio:1 and .gthumb a fixed square, so these state the
    // BOX's ratio and reserve its space before any file arrives. srcset is what
    // makes changing them tempting -- the copies are 400x400 and 800x800 and it
    // looks safe to say so, which would be stating the file's size for a box
    // that is not that size.
    dnWritePhoto('uploads/dn/a.jpg', 1000);
    dnWritePhoto('uploads/dn/b.jpg', 1000);
    ImageVariants::generate('/uploads/dn/a.jpg');
    ImageVariants::generate('/uploads/dn/b.jpg');

    $html = dnPage(['/uploads/dn/a.jpg', '/uploads/dn/b.jpg']);
    $main = dnTags($html, 'gmain-img')[0];

    expect(dnAttr($main, 'width'))->toBe('1000');
    expect(dnAttr($main, 'height'))->toBe('1000');

    foreach (dnTags($html, 'gthumb-img') as $tag) {
        expect(dnAttr($tag, 'width'))->toBe('66', 'a thumbnail stopped reserving its space: '.$tag);
        expect(dnAttr($tag, 'height'))->toBe('66');
    }
});

it('keeps the alt text it had, on every photograph', function () {
    // A crawler indexes product photography on its alt, and an attribute added
    // to a line is an attribute that can displace one.
    dnWritePhoto('uploads/dn/a.jpg', 1000);
    dnWritePhoto('uploads/dn/b.jpg', 1000);
    ImageVariants::generate('/uploads/dn/a.jpg');
    ImageVariants::generate('/uploads/dn/b.jpg');

    $html = dnPage(['/uploads/dn/a.jpg', '/uploads/dn/b.jpg']);

    foreach (array_merge(dnTags($html, 'gmain-img'), dnTags($html, 'gthumb-img')) as $tag) {
        expect(dnAttr($tag, 'alt'))->not->toBeNull('a photograph lost its alt: '.$tag);
        expect(trim((string) dnAttr($tag, 'alt')))->not->toBe('', 'a photograph has an empty alt: '.$tag);
    }
});

it('never names a file that is not on disk', function () {
    /*
     * The one thing a srcset must never do. Every candidate is resolved back to
     * the public root and opened -- if any of these 404s in a browser, the hero
     * is blank and there is no falling back to src.
     */
    dnWritePhoto('uploads/dn/shot.jpg', 1000);
    ImageVariants::generate('/uploads/dn/shot.jpg');

    $html = dnPage(['/uploads/dn/shot.jpg']);

    foreach (array_merge(dnTags($html, 'gmain-img'), dnTags($html, 'gthumb-img')) as $tag) {
        $urls = array_keys(dnCandidates(dnAttr($tag, 'srcset')));
        $urls[] = (string) dnAttr($tag, 'src');

        foreach (array_filter($urls) as $url) {
            expect(is_file(public_path(ltrim($url, '/'))))
                ->toBeTrue('the gallery offers a file that is not there: '.$url);
        }
    }
});
