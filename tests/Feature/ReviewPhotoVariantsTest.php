<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ImageSizesApiController;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Review;
use App\Support\ImageVariants;
use Illuminate\Http\Request;

/**
 * Lane IM2 — review photographs get phone-sized copies, and the wall uses them.
 *
 * ── WHAT WAS WRONG ON THE SHOP ─────────────────────────────────────────────
 *
 * Admin\ImageSizesApiController::images() is the only work list on this host
 * that can produce a smaller copy of a photograph that was already here. Lane
 * IM taught it about `products.images` and `product_variants.image`. It still
 * knew nothing about `reviews.images`, so a photograph a shopper attached to a
 * review could never have a copy for anything to find — not because the batch
 * skipped it, but because it was never work at all. The batch would walk to
 * `done: true` and report `remaining: 0` over a shop whose review wall was
 * still serving phone-camera originals.
 *
 * MEASURED IN CHROMIUM on the preview (docs/lane-im2-shots): a product page
 * with four photographed reviews made 7 review-photo requests for 3315.7 KB, of
 * which every one was an untouched 1080x1920 or 1920x1080 original painted into
 * a card 266px wide and 160px tall. After: 7 requests, 165.4 KB — a 95% cut,
 * and not one line of the fallback behaviour moved.
 *
 * It is about to matter far more than it did: the WordPress importer brings
 * review photographs across from plugin 1.6.0 (reviews.csv gained an `images`
 * column), so a shop with two of these today has thousands after one import.
 *
 * ── THE MUTATION NOTES, ALL RUN IN THIS WORKTREE ON 29 SEPTEMBER 2026 ──────
 *
 * Delete the `$reviewImages` query and its foreach from
 * ImageSizesApiController::images():
 *   · "it sizes a photograph attached to a review" goes red with
 *     "a review photograph has no 200px copy".
 *   · "it counts review photographs in what is left to do" goes red — `total`
 *     is 1 where 3 is expected.
 *   · "it offers the review wall a copy instead of the photograph" goes red
 *     with "the review card is still pointing at the original".
 *   · "it leaves a review row carrying no photographs out of the work list"
 *     goes red too — `total` is 1 where 2 is expected — because with reviews
 *     out of the list the real photograph is not counted either.
 *
 * Take the `srcset`/`sizes` pair back out of partials/reviews.blade.php and
 * "it offers the review wall a copy instead of the photograph" goes red with
 * "the review card carries no srcset".
 *
 * Make ImageVariants::reviewPhotoSizesAttribute() ignore its $columns argument
 * and return a constant, and "it declares the review card's real width, which
 * the owner can change" goes red — the two-column and the six-column shop
 * declare the same box.
 */

/** A real JPEG of a given pixel size, in this process's own public root. */
function im2Photo(string $relative, int $width = 1080, int $height = 1920): string
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

/**
 * Empty a directory under this process's public root.
 *
 * By name only and never through a link: public/ under the per-process root
 * tests/bootstrap.php builds also holds `build`, a SYMLINK into the checkout.
 */
function im2Sweep(string $relative): void
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

/** A visible product. */
function im2Product(array $images = [], string $slug = 'im2-cleansing-oil'): Product
{
    $brand = Brand::firstOrCreate(['slug' => 'im2-anua'], ['name' => 'Anua']);

    return Product::create([
        'slug' => $slug,
        'name' => 'Heartleaf Pore Control Cleansing Oil 200ml',
        'brand_id' => $brand->id,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
        'image' => $images[0] ?? null,
        'images' => $images,
        'description' => '<p>A gentle daily cleansing oil.</p>',
    ]);
}

/** An approved review carrying photographs, of the kind the wall draws. */
function im2Review(Product $product, array $photos, string $name = 'Noura'): Review
{
    return Review::create([
        'product_id' => $product->id,
        'source' => 'sorina',
        'author_name' => $name,
        'author_email' => 'noura@example.test',
        'rating' => 5,
        'title' => 'Lovely',
        'content' => 'Cleared my skin in a fortnight.',
        'images' => $photos,
        'status' => 'approved',
        'verified' => true,
    ]);
}

/** Drive the real batch to completion, the way the owner's screen drives it. */
function im2RunBatch(): int
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
            return $made;
        }
    }

    throw new RuntimeException('the batch never reported done: the cursor is not advancing');
}

beforeEach(function () {
    if (! ImageVariants::available()) {
        test()->markTestSkipped('this PHP has no GD, which is the thing under test');
    }

    im2Sweep(ImageVariants::DIR);
    im2Sweep('uploads/im2');
});

afterEach(function () {
    im2Sweep(ImageVariants::DIR);
    im2Sweep('uploads/im2');
});

/* ───────────────────────────── the batch reaches review photographs ────── */

it('sizes a photograph attached to a review', function () {
    /*
     * THE DEFECT, IN ONE ASSERTION. images() walked products and product
     * variants, so reviews.images was never work and the batch reported it had
     * finished over a wall full of originals.
     *
     * MUTATION: delete the $reviewImages query and its foreach and this is red
     * with "a review photograph has no 200px copy".
     */
    im2Photo('uploads/im2/product.jpg', 1000, 1000);
    im2Photo('uploads/im2/rev-1.jpg');
    im2Photo('uploads/im2/rev-2.jpg', 1920, 1080);

    $product = im2Product(['/uploads/im2/product.jpg']);
    im2Review($product, ['/uploads/im2/rev-1.jpg', '/uploads/im2/rev-2.jpg']);

    im2RunBatch();

    foreach (['rev-1', 'rev-2'] as $shot) {
        foreach (ImageVariants::WIDTHS as $width) {
            expect(is_file(public_path(ImageVariants::DIR.'/'.$width.'/uploads/im2/'.$shot.'.jpg')))
                ->toBeTrue('a review photograph has no '.$width.'px copy: '.$shot);
        }
    }
});

it('counts review photographs in what is left to do', function () {
    // The half the owner would have SEEN: a screen saying "0 remaining" over a
    // shop whose review photographs had never been touched.
    im2Photo('uploads/im2/product.jpg', 1000, 1000);
    im2Photo('uploads/im2/rev-1.jpg');
    im2Photo('uploads/im2/rev-2.jpg');

    $product = im2Product(['/uploads/im2/product.jpg']);
    im2Review($product, ['/uploads/im2/rev-1.jpg', '/uploads/im2/rev-2.jpg']);

    $before = json_decode((string) (new ImageSizesApiController())->status()->getContent(), true);

    expect($before['total'])->toBe(3, 'the review photographs are not counted as work at all')
        ->and($before['remaining'])->toBe(3, 'the backlog does not include the review photographs');

    im2RunBatch();

    $after = json_decode((string) (new ImageSizesApiController())->status()->getContent(), true);

    expect($after['remaining'])->toBe(0, 'the batch says there is work left after running to done')
        ->and($after['done'])->toBe(3, 'the batch did not finish every photograph it counted');
});

it('walks review photographs with a cursor that resumes rather than restarting', function () {
    /*
     * THE PROPERTY THAT MAKES THE WHOLE BATCH SAFE, asserted on the column this
     * lane added rather than assumed to have carried over.
     *
     * The cursor is the image reference itself, walked ascending, and it has to
     * mean ONE thing across products, variants and reviews together — which is
     * why reviews.images is merged into the same flat sorted set rather than
     * range-filtered in SQL. A review photograph that sorts before a product's
     * featured image must still be reachable, and resuming on a cursor must
     * never do the same file twice or skip the next one.
     */
    im2Photo('uploads/im2/aaa-product.jpg', 1000, 1000);
    im2Photo('uploads/im2/bbb-review.jpg');
    im2Photo('uploads/im2/ccc-review.jpg');

    $product = im2Product(['/uploads/im2/aaa-product.jpg']);
    im2Review($product, ['/uploads/im2/ccc-review.jpg']);
    im2Review($product, ['/uploads/im2/bbb-review.jpg'], 'Hessa');

    $runner = new ImageSizesApiController();

    // One turn, then resume from the cursor it handed back. Every photograph
    // must end up sized, and the walk must terminate.
    $first = json_decode((string) $runner->run(Request::create('/', 'GET', ['after' => '']))->getContent(), true);

    expect($first['ok'])->toBeTrue()
        ->and($first['cursor'])->not->toBe('', 'the first turn advanced no cursor at all');

    $second = json_decode(
        (string) $runner->run(Request::create('/', 'GET', ['after' => $first['cursor']]))->getContent(),
        true
    );

    expect($second['ok'])->toBeTrue()
        ->and($second['done'])->toBeTrue('the walk did not finish on the second turn over three files');

    foreach (['aaa-product', 'bbb-review', 'ccc-review'] as $stem) {
        expect(is_file(public_path(ImageVariants::DIR.'/200/uploads/im2/'.$stem.'.jpg')))
            ->toBeTrue('resuming on the cursor skipped '.$stem);
    }
});

it('leaves a review row carrying no photographs out of the work list entirely', function () {
    // The SQL predicates are only there to skip rows with nothing to do, and a
    // shop's reviews table is overwhelmingly such rows. If they ever start
    // filtering anything else this goes red.
    im2Photo('uploads/im2/product.jpg', 1000, 1000);
    im2Photo('uploads/im2/rev-1.jpg');

    $product = im2Product(['/uploads/im2/product.jpg']);
    im2Review($product, []);                                // an empty array
    im2Review($product, ['/uploads/im2/rev-1.jpg'], 'Maha'); // and a real one

    Review::create([
        'product_id' => $product->id, 'author_name' => 'Silent', 'rating' => 4,
        'content' => 'No photo.', 'images' => null, 'status' => 'approved',
    ]);

    $status = json_decode((string) (new ImageSizesApiController())->status()->getContent(), true);

    expect($status['total'])->toBe(2, 'a review with no photographs is being counted as work');
});

/* ────────────────────────────────────── and the wall uses what was made ── */

it('offers the review wall a copy instead of the photograph', function () {
    /*
     * MUTATION: take the srcset/sizes pair back out of partials/reviews.blade.php
     * and this is red with "the review card carries no srcset". Delete the
     * reviews block from images() instead and it is red with "the review card
     * is still pointing at the original" — the two defects are separable and
     * this asserts both halves.
     */
    im2Photo('uploads/im2/product.jpg', 1000, 1000);
    im2Photo('uploads/im2/rev-1.jpg');

    $product = im2Product(['/uploads/im2/product.jpg']);
    im2Review($product, ['/uploads/im2/rev-1.jpg']);

    im2RunBatch();

    $html = $this->get('/product/'.$product->slug)->assertOk()->getContent();

    preg_match('/<img\b[^>]*\/uploads\/im2\/rev-1\.jpg[^>]*>/i', $html, $m);

    expect(str_contains($html, '/uploads/im2/rev-1.jpg'))
        ->toBeTrue('the review photograph is not on the page at all');
    expect($m)->not->toBeEmpty('the review photograph is not drawn as an <img>');

    $tag = $m[0];

    expect(str_contains($tag, 'srcset='))
        ->toBeTrue('the review card carries no srcset: '.$tag);

    foreach (ImageVariants::WIDTHS as $width) {
        expect(str_contains($tag, '/'.ImageVariants::DIR.'/'.$width.'/uploads/im2/rev-1.jpg '.$width.'w'))
            ->toBeTrue('the review card is still pointing at the original — no '.$width.'w candidate: '.$tag);
    }

    expect(str_contains($tag, 'sizes='))
        ->toBeTrue('a srcset with no sizes makes the browser assume 100vw: '.$tag);
});

it('says nothing at all when a review photograph has no copies', function () {
    /*
     * THE CASE THAT GOVERNS, and the reason srcsetFor() reads the disk. With
     * `w` descriptors a browser picks a candidate and never looks at `src`, so
     * a srcset naming a file that is not there is a blank card with nothing to
     * fall back to. A shop that has never run the batch — which is every shop
     * the day this package is applied — must render exactly what it renders
     * today.
     */
    im2Photo('uploads/im2/product.jpg', 1000, 1000);
    im2Photo('uploads/im2/rev-1.jpg');

    $product = im2Product(['/uploads/im2/product.jpg']);
    im2Review($product, ['/uploads/im2/rev-1.jpg']);

    // Deliberately NO batch.
    $html = $this->get('/product/'.$product->slug)->assertOk()->getContent();

    preg_match('/<img\b[^>]*\/uploads\/im2\/rev-1\.jpg[^>]*>/i', $html, $m);

    expect($m)->not->toBeEmpty('the review photograph is not drawn at all');
    expect(str_contains($m[0], 'srcset'))
        ->toBeFalse('a srcset was emitted for a photograph with no copies on disk: '.$m[0]);
    expect(str_contains($m[0], 'src="/uploads/im2/rev-1.jpg"'))
        ->toBeTrue('the fallback no longer draws the original: '.$m[0]);
});

it('declares the review card real width, which the owner can change', function () {
    /*
     * `--sr-cols` is set from Review Settings, so the card's width is the
     * owner's. A hardcoded sizes would be right at four columns and half the
     * truth at two, which is a soft review photograph on the setting he is
     * most likely to reach for.
     *
     * MUTATION: make reviewPhotoSizesAttribute() ignore $columns and this is
     * red — two columns and six declare the same box.
     */
    $two = ImageVariants::reviewPhotoSizesAttribute(2, false);
    $six = ImageVariants::reviewPhotoSizesAttribute(6, false);

    expect($two)->not->toBe($six, 'the declared box does not follow the column count');

    // A two-column wall draws a card about three times the width a six-column
    // one does, so the declaration has to be bigger by roughly that much.
    preg_match('#/\s*2\s*-#', $two, $a);
    preg_match('#/\s*6\s*-#', $six, $b);

    expect($a)->not->toBeEmpty('two columns is not divided by 2: '.$two)
        ->and($b)->not->toBeEmpty('six columns is not divided by 6: '.$six);

    // And the two-up grid inside one card is half of the one-up box, less the
    // 4px gap the stylesheet puts between them.
    expect(ImageVariants::reviewPhotoSizesAttribute(4, true))
        ->not->toBe(ImageVariants::reviewPhotoSizesAttribute(4, false),
            'a card with four photographs declares the same width as a card with one');

    // A setting the owner can type has to survive being typed wrongly.
    expect(ImageVariants::reviewPhotoSizesAttribute(0, false))->toBeString()
        ->and(ImageVariants::reviewPhotoSizesAttribute(-3, false))
        ->toBe(ImageVariants::reviewPhotoSizesAttribute(1, false),
            'a nonsense column count does not clamp to a real one');
});

it('says nothing rather than something malformed when a filename holds a comma', function () {
    /*
     * A LATENT BUG THIS LANE MADE REACHABLE.
     *
     * A srcset is a COMMA-separated list, so a comma anywhere in a candidate's
     * URL splits it into two malformed ones and the browser is entitled to
     * discard the whole attribute. detailSrcsetFor() has refused a comma since
     * it was written; srcsetFor() did not — the wrong way round, since that
     * method has one caller and this one has nine.
     *
     * It could not be hit before: every path in this catalogue is written by
     * MediaUploadController as `Ymd-His-<random>.ext`. Review photographs
     * change that. Their addresses come from the WordPress import, out of a
     * database this shop did not author, and a filename an operator typed into
     * WordPress in 2019 can hold anything the filesystem allows.
     *
     * MUTATION: drop the `str_contains($rel, ',')` guard from srcsetFor() and
     * this is red with "a malformed srcset was emitted".
     */
    im2Photo('uploads/im2/product.jpg', 1000, 1000);
    im2Photo('uploads/im2/hello,world.jpg', 900, 900);

    // Attached to a REVIEW, which is how such a name reaches this shop at all.
    $product = im2Product(['/uploads/im2/product.jpg']);
    im2Review($product, ['/uploads/im2/hello,world.jpg']);

    im2RunBatch();

    // The copies are made — the comma is only a problem in a srcset, and
    // refusing to resize the file would be a different and worse answer.
    expect(is_file(public_path(ImageVariants::DIR.'/200/uploads/im2/hello,world.jpg')))
        ->toBeTrue('a comma in the filename stopped the copy being made at all');

    $srcset = ImageVariants::srcsetFor('/uploads/im2/hello,world.jpg');

    expect($srcset)->toBe('', 'a malformed srcset was emitted: '.$srcset);

    // And the big-image method has always agreed, which is the point.
    expect(ImageVariants::detailSrcsetFor('/uploads/im2/hello,world.jpg'))->toBe('');

    // variantUrl() is a SINGLE url, not a list, so a comma is harmless there
    // and refusing it would cost the basket and the admin grids their copy for
    // no reason.
    expect(ImageVariants::variantUrl('/uploads/im2/hello,world.jpg', 200))
        ->toBe('/'.ImageVariants::DIR.'/200/uploads/im2/hello,world.jpg',
            'variantUrl refused a comma it has no reason to refuse');
});
