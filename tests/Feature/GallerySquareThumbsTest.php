<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ImageSizesApiController;
use App\Models\Brand;
use App\Models\Product;
use App\Support\ImageVariants;
use Illuminate\Http\Request;

/**
 * Lane IM2 — the gallery strip is a row of equal squares.
 *
 * ── WHAT THE OWNER SAW ─────────────────────────────────────────────────────
 *
 * With a screenshot arrowing at the thumbnail strip under the main image: "i
 * want the product gallery images thumbnail to be cropped or load square size
 * mini 100x100 only to reduce the page load."
 *
 * The BOX was already square — 66px, 56px under 640px, written in
 * kbb-product.css. The picture inside it was not: `object-fit: contain` scales
 * a photograph until it FITS, so a 9:16 phone shot drew a narrow portrait
 * stripe with white either side while a square bottle shot filled its tile, and
 * a row of them read as tiles of different shapes. Measured in Chromium at 390
 * and 1280 (docs/lane-im2-shots): before, the five tiles painted 37x66, 66x66,
 * 66x37, 49x66 and 66x66 of actual picture; after, 66x66 five times.
 *
 * ── AND THE DEFECT THE CROP INTRODUCED, WHICH IS WHY THIS FILE EXISTS ──────
 *
 * A `w` descriptor states a candidate's WIDTH and `sizes` states the box's
 * width, and under `contain` that pair is honest: the picture is scaled until
 * it fits, so width never overstates what arrived. Under `cover` the binding
 * dimension is the image's SHORT side — and for a photograph wider than it is
 * tall that is its HEIGHT, which no descriptor in a srcset mentions.
 *
 * A 1778x1000 photograph's 200w copy is 200x112. Covering a 66px square at
 * device-pixel-ratio 3 wants 198 device pixels each way, so 112 rows would be
 * stretched to 198 — a 1.77x upscale on a tile that was sharp the day before.
 * So thumbSizesAttribute() takes the original's aspect and widens the
 * declaration by exactly max(1, aspect); a square or portrait returns the same
 * '66px' it always returned and the markup does not move a byte.
 *
 * ── THE MUTATION NOTES, ALL RUN IN THIS WORKTREE ON 29 SEPTEMBER 2026 ──────
 *
 * Put `object-fit:contain` back on `.gthumb-img` in kbb-product.css and "it
 * crops the gallery thumbnail to a square instead of letterboxing it" goes red
 * with "the strip still letterboxes: .gthumb-img is object-fit:contain".
 *
 * Make thumbSizesAttribute() return '66px' whatever it is given: three go red —
 * "it asks for a wide photograph by the width its short side needs" with "a
 * 16:9 photograph still declares a 66px box", "it widens the gallery markup
 * only for a wide shot", and "it leaves a square or portrait thumbnail
 * declaring exactly 66px" on its clamp assertion.
 * Make it widen for EVERY photograph (drop the `<= 1.0` guard):
 *   · "it leaves a square or portrait thumbnail declaring exactly 66px" goes
 *     red — a 1000x1000 shot declares 66px and a 1000x1778 one must too.
 *
 */

/** A real JPEG of a given pixel size, in this process's own public root. */
function sq2Photo(string $relative, int $width, int $height): string
{
    $path = public_path($relative);
    @mkdir(\dirname($path), 0755, true);

    $im = imagecreatetruecolor($width, $height);

    for ($y = 0; $y < $height; $y += 4) {
        imagefilledrectangle($im, 0, $y, $width, $y + 3, (int) imagecolorallocate($im, ($y * 7) % 255, ($y * 13) % 255, ($y * 3) % 255));
    }

    imagejpeg($im, $path, 90);
    imagedestroy($im);

    return $path;
}

function sq2Sweep(string $relative): void
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

function sq2RunBatch(): void
{
    $runner = new ImageSizesApiController();
    $cursor = '';

    for ($turn = 1; $turn <= 200; $turn++) {
        $payload = json_decode(
            (string) $runner->run(Request::create('/', 'GET', ['after' => $cursor]))->getContent(),
            true
        );

        expect($payload['ok'] ?? false)->toBeTrue('the batch refused to run');
        $cursor = (string) $payload['cursor'];

        if ($payload['done'] ?? false) {
            return;
        }
    }

    throw new RuntimeException('the batch never reported done');
}

beforeEach(function () {
    if (! ImageVariants::available()) {
        test()->markTestSkipped('this PHP has no GD, which is the thing under test');
    }

    sq2Sweep(ImageVariants::DIR);
    sq2Sweep('uploads/sq2');
});

afterEach(function () {
    sq2Sweep(ImageVariants::DIR);
    sq2Sweep('uploads/sq2');
});

/* ─────────────────────────────────────────── the strip is square now ───── */

it('crops the gallery thumbnail to a square instead of letterboxing it', function () {
    /*
     * The rendered geometry is CSS's, so this asserts the one declaration that
     * decides it rather than measuring a browser — CLAUDE.md forbids JavaScript
     * that sizes the page and the screenshots in docs/lane-im2-shots carry the
     * measured proof.
     *
     * MUTATION: put object-fit:contain back and this is red with "the strip
     * still letterboxes".
     */
    $css = (string) file_get_contents(base_path('resources/css/kbb/kbb-product.css'));

    expect(preg_match('/\.gthumb-img\{[^}]*object-fit:\s*cover/', $css) === 1)
        ->toBeTrue('the strip still letterboxes: .gthumb-img is not object-fit:cover');

    // The box itself was already square and must stay so: cover with a
    // non-square box would crop to a rectangle, which is not what was asked.
    expect(preg_match('/\.gthumb\{[^}]*width:66px;height:66px/', $css) === 1)
        ->toBeTrue('the .gthumb box is no longer a 66px square, so cover cannot produce a square tile');

    // And the MAIN frame is deliberately untouched: the owner asked about the
    // strip, and cropping the hero would throw away part of a product shot.
    expect(preg_match('/\.gmain-img\{[^}]*object-fit:\s*contain/', $css) === 1)
        ->toBeTrue('the main frame was cropped too, which nobody asked for');
});

it('asks for a wide photograph by the width its short side needs', function () {
    /*
     * MUTATION: make thumbSizesAttribute() ignore its argument and this is red
     * with "a 16:9 photograph still declares a 66px box".
     */
    // 1778x1000 is 1.778:1. Its 200w copy is 200x112, and a 66px square at
    // ratio 3 wants 198 — so the declaration has to reach 117px for the
    // browser to step up to the 400w copy on a dense screen.
    expect(ImageVariants::thumbSizesAttribute(1778 / 1000))
        ->toBe('118px', 'a 16:9 photograph still declares a 66px box, so its short side is upscaled 1.77x');

    expect(ImageVariants::thumbSizesAttribute(4 / 3))
        ->toBe('88px', 'a 4:3 photograph declares the wrong box');
});

it('leaves a square or portrait thumbnail declaring exactly 66px', function () {
    /*
     * THE DISPLAY-PARITY HALF. Almost every photograph in this catalogue is
     * square or portrait, and for those the crop changes nothing about which
     * file is right — so the markup must not move. If this widened for
     * everything, every tile in the shop would step up a candidate and the
     * lane would have ADDED bytes.
     *
     * MUTATION: drop the `<= 1.0` guard and this is red.
     */
    expect(ImageVariants::thumbSizesAttribute(1.0))->toBe('66px', 'a square photograph no longer declares 66px')
        ->and(ImageVariants::thumbSizesAttribute(1000 / 1778))->toBe('66px', 'a 9:16 portrait no longer declares 66px')
        ->and(ImageVariants::thumbSizesAttribute(null))->toBe('66px', 'the unknown-aspect default moved')
        // A zero-height original would divide to INF; a corrupt header should
        // fall back to today's answer rather than declare a nonsense box.
        ->and(ImageVariants::thumbSizesAttribute(INF))->toBe('66px', 'an infinite aspect is not refused')
        ->and(ImageVariants::thumbSizesAttribute(NAN))->toBe('66px', 'a NaN aspect is not refused')
        // And it is bounded, because the value becomes an attribute: a 40:1
        // banner cannot be allowed to declare a 2640px box for a 66px tile.
        ->and(ImageVariants::thumbSizesAttribute(40.0))->toBe('528px', 'the declaration is not clamped');
});

it('reads the aspect off the file and refuses to guess when it cannot', function () {
    sq2Photo('uploads/sq2/wide.jpg', 1778, 1000);

    expect(ImageVariants::aspectOf('/uploads/sq2/wide.jpg'))->toBeGreaterThan(1.7)
        ->and(ImageVariants::aspectOf('/uploads/sq2/wide.jpg'))->toBeLessThan(1.8);

    // Everything split() already refuses, refused here too — this adds no new
    // way into the filesystem.
    expect(ImageVariants::aspectOf('https://evil.test/x.jpg'))->toBeNull('another origin was opened')
        ->and(ImageVariants::aspectOf('/uploads/../../etc/passwd'))->toBeNull('a traversal was opened')
        ->and(ImageVariants::aspectOf('/uploads/sq2/missing.jpg'))->toBeNull('a file that is not there returned an aspect')
        ->and(ImageVariants::aspectOf(''))->toBeNull('an empty reference returned an aspect');
});

it('widens the gallery markup only for a wide shot, and only once copies exist', function () {
    /*
     * The header read is the cost srcsetFor() refuses to pay per tile, so it
     * has to be paid ONLY where it can buy something. A product whose gallery
     * has never been through the batch has no srcset to choose between, so the
     * aspect is never asked for and the markup is exactly what it was.
     */
    sq2Photo('uploads/sq2/g1.jpg', 1000, 1000);
    sq2Photo('uploads/sq2/g2.jpg', 1778, 1000);

    $brand = Brand::firstOrCreate(['slug' => 'sq2-anua'], ['name' => 'Anua']);
    $product = Product::create([
        'slug' => 'sq2-oil', 'name' => 'Cleansing Oil', 'brand_id' => $brand->id,
        'status' => 'publish', 'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock',
        'image' => '/uploads/sq2/g1.jpg', 'images' => ['/uploads/sq2/g1.jpg', '/uploads/sq2/g2.jpg'],
    ]);

    // BEFORE the batch: no srcset anywhere in the strip, and no widened sizes.
    $cold = (string) $this->get('/product/'.$product->slug)->assertOk()->getContent();

    expect(str_contains($cold, 'sizes="118px"'))
        ->toBeFalse('a widened sizes was emitted for a gallery with no copies at all');

    sq2RunBatch();

    $warm = (string) $this->get('/product/'.$product->slug)->assertOk()->getContent();

    preg_match_all('/<img\b[^>]*class="gthumb-img"[^>]*>/i', $warm, $m);

    expect($m[0])->toHaveCount(2, 'the strip did not render two thumbnails');

    $square = $m[0][0];
    $wide = $m[0][1];

    expect(str_contains($square, 'sizes="66px"'))
        ->toBeTrue('the square shot no longer declares 66px: '.$square);
    expect(str_contains($wide, 'sizes="118px"'))
        ->toBeTrue('the 16:9 shot still declares a 66px box, so its short side is upscaled: '.$wide);
});
