<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Post;
use App\Models\Product;
use App\Models\Review;
use App\Services\Analytics;
use App\Services\PageBanners;
use App\Support\ImageVariants;

/**
 * Lane SPD -- the owner, 18 October: "the pages header, categories, normal
 * pages, brands etc. should load the actual mobile size banner images ... also
 * same thing for reviews images thumbnails. the reviews thumbnails images
 * should crop auto to small and upon click it should load the full size ...
 * i feel little bit that something still loading for a second, even the page
 * loaded completed."
 *
 * Three defects, each measured on the live shop (docs/lane-spd/README.md):
 *
 *   1. /brands/anua/ printed its banner as a bare <img src> -- every phone
 *      downloaded the 2496x664 original, 925 KB, as the page's LCP, while its
 *      img-cache copies sat on disk. The Journal's covers and the "Normal page
 *      banner" (Pages -> Page banners) were bare in the same way.
 *   2. A review card offered width copies of a 1080x1440 phone photograph,
 *      full height, to paint a 266x160 / 131x76 cover box.
 *   3. The deferred pixel loader (Analytics::LATE) took the product page's
 *      own thumbnail-strip scroll for a shopper, so fbevents.js, gtag.js and
 *      TikTok's events.js were fetched the instant the page loaded instead of
 *      after a real touch or 5 s -- the "still loading" after the page looked
 *      complete.
 *
 * Helpers are prefixed spd (a duplicate global function fatals the suite).
 */

function spdPhoto(string $relative, int $width, int $height): string
{
    $path = public_path($relative);
    @mkdir(\dirname($path), 0755, true);
    $im = imagecreatetruecolor($width, $height);

    for ($y = 0; $y < $height; $y += 4) {
        imagefilledrectangle($im, 0, $y, $width, $y + 3, (int) imagecolorallocate($im, ($y * 7) % 255, ($y * 13) % 255, ($y * 3) % 255));
    }

    imagejpeg($im, $path, 85);
    imagedestroy($im);

    return '/'.$relative;
}

/** Empty a directory under this process's public root, by name, never through a link. */
function spdSweep(string $relative): void
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

function spdBrand(?string $headerImage): Brand
{
    $brand = Brand::create(['name' => 'Spdbrand', 'slug' => 'spdbrand', 'header_image' => $headerImage]);

    Product::create([
        'slug' => 'spd-brand-product', 'name' => 'SPD Product', 'brand_id' => $brand->id,
        'price' => 1000, 'status' => 'publish', 'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple',
    ]);

    return $brand;
}

function spdImgTag(string $html, string $needle): string
{
    return preg_match('#<img\b[^>]*'.preg_quote($needle, '#').'[^>]*>#', $html, $m) === 1 ? $m[0] : '';
}

beforeEach(function () {
    if (! ImageVariants::available()) {
        test()->markTestSkipped('this PHP has no GD, which is the thing under test');
    }

    spdSweep(ImageVariants::DIR);
    spdSweep('uploads/spd');
});

afterEach(function () {
    spdSweep(ImageVariants::DIR);
    spdSweep('uploads/spd');
});

/* ───────────────────────────── 1. banners ──────────────────────────────── */

it('gives the brand banner the category banner\'s phone-sized srcset, as the LCP, never lazy', function () {
    /*
     * ON THE SHOP: <img class="brw-ph__img" src=".../20261005-072021-SNXpGGjW.webp">,
     * 925 KB to every phone, with no srcset.
     * MUTATION: drop the `srcset` branch from store/partials/brand-panel (or
     * return '' for it from BrandPanel::forBrand) and this is red -- the tag
     * carries no 400w candidate. Drop the `min-resolution: 2.5dppx` branch
     * from BrandPanel::sizes() and the `sizes` match is red -- a 3x phone
     * then needs 1,698px and takes the original again.
     */
    $image = spdPhoto('uploads/spd/brand-banner.jpg', 2400, 700);
    ImageVariants::generate($image);
    spdBrand($image);

    $html = (string) $this->get('/brands/spdbrand/')->assertOk()->getContent();
    $tag = spdImgTag($html, 'class="brw-ph__img"');

    expect($html)->toContain('<picture class="brw-ph__pic"><img class="brw-ph__img" src="/uploads/spd/brand-banner.jpg"')
        ->and($tag)->toContain('/img-cache/400/uploads/spd/brand-banner.jpg 400w')
        ->and($tag)->toContain('/uploads/spd/brand-banner.jpg 2400w')
        // Cover-aware: the phone banner draws the wide picture wider than the screen.
        ->and($tag)->toMatch('#sizes="\(max-width: 599px\) and \(min-resolution: 2\.5dppx\) calc\(max\(100vw, (\d+)px\) \* 0\.67\), \(max-width: 599px\) max\(100vw, \1px\), #')
        ->and($tag)->toContain('fetchpriority="high"')
        ->and($tag)->not->toContain('loading="lazy"');
});

it('prints the brand banner exactly as before when the picture has no copies', function () {
    /*
     * A picture still on the old host, or one with no img-cache copies, must
     * not gain a srcset naming files that 404.
     * MUTATION: make the brand branch always include the <picture> partial and
     * this is red.
     */
    $image = spdPhoto('uploads/spd/brand-bare.jpg', 2400, 700);
    spdBrand($image);

    $html = (string) $this->get('/brands/spdbrand/')->assertOk()->getContent();

    expect($html)->toContain('<img class="brw-ph__img" src="/uploads/spd/brand-bare.jpg" alt="" width="'
            .\App\Support\TitleHeader::IMG_WIDTH.'" height="'.\App\Support\TitleHeader::IMG_HEIGHT.'" decoding="async" fetchpriority="high">')
        ->and($html)->not->toContain('<picture class="brw-ph__pic">');
});

it('offers the normal page banner\'s copies to phones through its <picture>', function () {
    /*
     * Pages -> Page banners: a <picture> whose phone <source> and laptop <img>
     * named only the originals.
     * MUTATION: delete the two PageBanners::srcset() lines from
     * partials/page-banner and this is red.
     */
    $desk = spdPhoto('uploads/spd/pb-desk.jpg', 1920, 600);
    $phone = spdPhoto('uploads/spd/pb-phone.jpg', 1080, 900);
    ImageVariants::generate($desk);
    ImageVariants::generate($phone);

    $banner = PageBanners::view(['img_d' => $desk, 'img_m' => $phone, 'w_d' => 1920, 'h_d' => 600, 'w_m' => 1080, 'h_m' => 900,
        'alt' => 'Sale', 'link' => '', 'strip' => false, 'items' => [], 'id' => 'b1', 'bg' => '#ffffff', 'ink' => '#000000',
        'icon' => '#000000', 'sh_d' => 40, 'sh_m' => 40, 'fs_d' => 14, 'fs_m' => 13, 'ic_d' => 16, 'ic_m' => 14]);
    $html = view('partials.page-banner', ['pageBanner' => $banner])->render();

    expect($html)->toMatch('#<source media="\(max-width: 900px\)" srcset="/img-cache/200/uploads/spd/pb-phone\.jpg 200w, [^"]*" sizes="\(min-resolution: 2\.5dppx\) 67vw, 100vw" width="1080" height="900">#')
        ->and(spdImgTag($html, 'src="/uploads/spd/pb-desk.jpg"'))->toContain('/img-cache/400/uploads/spd/pb-desk.jpg 400w')
        ->and(spdImgTag($html, 'src="/uploads/spd/pb-desk.jpg"'))->toContain('sizes="min(100vw, 2400px)"');

    // And with no copies on disk: the bytes of before, not one attribute more.
    $bare = PageBanners::view(['img_d' => '/uploads/spd/none-d.jpg', 'img_m' => '/uploads/spd/none-m.jpg', 'w_d' => 1920, 'h_d' => 600, 'w_m' => 1080, 'h_m' => 900,
        'alt' => '', 'link' => '', 'strip' => false, 'items' => [], 'id' => 'b2', 'bg' => '#ffffff', 'ink' => '#000000',
        'icon' => '#000000', 'sh_d' => 40, 'sh_m' => 40, 'fs_d' => 14, 'fs_m' => 13, 'ic_d' => 16, 'ic_m' => 14]);
    expect(view('partials.page-banner', ['pageBanner' => $bare])->render())
        ->toContain('<picture><source media="(max-width: 900px)" srcset="/uploads/spd/none-m.jpg" width="1080" height="900"><img src="/uploads/spd/none-d.jpg" alt="" width="1920" height="600" fetchpriority="high" decoding="async"></picture>');
});

it('gives the Journal\'s covers their copies: the article hero, the index cards and "More"', function () {
    /*
     * MUTATION: take the srcset out of store/post.blade.php's hero and the
     * first expectation is red; out of store/blog.blade.php and the second.
     */
    $cover = spdPhoto('uploads/spd/post.jpg', 1600, 1000);
    ImageVariants::generate($cover);

    foreach (['spd-one', 'spd-two'] as $slug) {
        Post::create(['slug' => $slug, 'title' => 'SPD '.$slug, 'body' => '<p>Body.</p>', 'excerpt' => 'Excerpt.',
            'status' => 'published', 'published_at' => now(), 'cover' => $cover, 'tag' => 'Routine']);
    }

    $article = (string) $this->get('/blog/spd-one/')->assertOk()->getContent();
    $hero = spdImgTag($article, 'width="1200" height="600"');

    expect($hero)->toContain('/img-cache/400/uploads/spd/post.jpg 400w')
        ->and($hero)->toContain('/uploads/spd/post.jpg 1600w')
        ->and($hero)->toContain('sizes="(max-width: 720px) and (min-resolution: 2.5dppx) calc((100vw - 40px) * 0.67), (max-width: 720px) calc(100vw - 40px), 680px"')
        ->and($hero)->toContain('fetchpriority="high"');

    $index = (string) $this->get('/blog/')->assertOk()->getContent();

    expect(spdImgTag($index, 'width="800" height="500"'))->toContain('/img-cache/400/uploads/spd/post.jpg 400w');
});

/* ───────────────────────────── 2. review thumbnails ───────────────────── */

function spdReviewProduct(array $photos): Product
{
    $brand = Brand::firstOrCreate(['slug' => 'spd-rev-brand'], ['name' => 'Spd Rev']);
    $product = Product::create(['slug' => 'spd-rev-product', 'name' => 'SPD Review Product', 'brand_id' => $brand->id,
        'status' => 'publish', 'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock']);
    Review::create(['product_id' => $product->id, 'source' => 'sorina', 'author_name' => 'Noura', 'author_email' => 'n@example.test',
        'rating' => 5, 'title' => 'Lovely', 'content' => 'Soft and light.', 'images' => $photos, 'status' => 'approved', 'verified' => true]);

    return $product;
}

it('draws a review thumbnail from a small square crop, and keeps the full photograph for the popup', function () {
    /*
     * ON THE SHOP: a 1080x1440 phone photograph was offered as full-height
     * width copies (the 400w copy is 400x533) to paint a 266x160 cover box.
     * MUTATION: put `srcsetFor($u)` back as the only srcset in
     * partials/reviews.blade.php and this is red -- no c1x1 candidate.
     */
    $photo = spdPhoto('uploads/spd/rev.jpg', 1080, 1440);
    ImageVariants::generate($photo);
    $made = ImageVariants::generateCrop($photo, ImageVariants::REVIEW_THUMB_TOKEN, 1.0, ImageVariants::REVIEW_THUMB_WIDTHS);

    // Exactly 200, 400 and 600, each a square -- nothing large is written.
    expect($made['made'])->toBe(3);
    foreach (ImageVariants::REVIEW_THUMB_WIDTHS as $w) {
        expect(getimagesize(public_path('img-cache/c1x1/'.$w.'/uploads/spd/rev.jpg')))->toMatchArray([0 => $w, 1 => $w]);
    }
    expect(is_dir(public_path('img-cache/c1x1/800')))->toBeFalse();

    $product = spdReviewProduct([$photo]);
    $html = (string) $this->get('/product/'.$product->slug.'/')->assertOk()->getContent();
    preg_match('#<span class="sr-ph"[^>]*><img\b[^>]*>#', $html, $m);
    $tag = $m[0] ?? '';

    expect($tag)->toContain('src="/uploads/spd/rev.jpg"')
        ->and($tag)->toContain('srcset="/img-cache/c1x1/200/uploads/spd/rev.jpg 200w, /img-cache/c1x1/400/uploads/spd/rev.jpg 400w, /img-cache/c1x1/600/uploads/spd/rev.jpg 600w"')
        ->and($tag)->toContain('loading="lazy"')
        // A 3x phone is asked for a 2x square: the 400px one, not the 600px.
        ->and($tag)->toContain('sizes="(max-width: 760px) and (min-resolution: 2.5dppx) 29vw, (max-width: 760px) 43vw, ');

    // The popup's data names the ORIGINAL -- the full size, fetched only when opened.
    preg_match('#<script type="application/json" class="sr-data">(.*?)</script>#s', $html, $d);
    expect(json_decode($d[1] ?? '{}', true)['imgs'] ?? [])->toBe(['/uploads/spd/rev.jpg']);
});

it('falls back to the width copies, the markup of before, while a review photograph has no crops', function () {
    /*
     * MUTATION: drop the `if ($phSrcset === '')` fallback and this is red --
     * the card loses its srcset and loads the 1080x1440 original.
     */
    $photo = spdPhoto('uploads/spd/rev2.jpg', 1080, 1440);
    ImageVariants::generate($photo);
    $product = spdReviewProduct([$photo]);

    $html = (string) $this->get('/product/'.$product->slug.'/')->assertOk()->getContent();
    preg_match('#<span class="sr-ph"[^>]*><img\b[^>]*>#', $html, $m);

    expect($m[0] ?? '')->toContain('srcset="/img-cache/200/uploads/spd/rev2.jpg 200w, /img-cache/400/uploads/spd/rev2.jpg 400w, /img-cache/800/uploads/spd/rev2.jpg 800w"')
        ->and($m[0] ?? '')->not->toContain('c1x1');
});

it('caps a crop at the widths asked for and never upscales a small one', function () {
    /*
     * MUTATION: let generateCrop() ignore $widths (cropWidths() for all) and
     * the 1080 square is written at 800 and 1080 too -- red.
     */
    $small = spdPhoto('uploads/spd/small.jpg', 500, 700);
    ImageVariants::generateCrop($small, ImageVariants::REVIEW_THUMB_TOKEN, 1.0, ImageVariants::REVIEW_THUMB_WIDTHS);

    expect(ImageVariants::cropSrcsetFor($small, ImageVariants::REVIEW_THUMB_TOKEN))
        ->toBe('/img-cache/c1x1/200/uploads/spd/small.jpg 200w, /img-cache/c1x1/400/uploads/spd/small.jpg 400w, /img-cache/c1x1/500/uploads/spd/small.jpg 500w');

    // A square original has nothing to crop: '' and the width copies serve it.
    $square = spdPhoto('uploads/spd/square.jpg', 900, 900);
    expect(ImageVariants::generateCrop($square, ImageVariants::REVIEW_THUMB_TOKEN, 1.0, ImageVariants::REVIEW_THUMB_WIDTHS)['reason'])->toBe('already the frame shape');
});

/* ───────────────────────────── 3. the late load ───────────────────────── */

it('does not take an element\'s own scroll for a shopper when deciding to fetch the pixel files', function () {
    /*
     * ON THE SHOP: #gthumbs scrolled itself at 668 ms, before load, and the
     * capture listener on window heard it, so all three pixel files were
     * fetched the instant the page loaded (tools/spd-pixel-wake.cjs).
     * MUTATION: put `function on(){h=1;if(l)go()}` back in Analytics::LATE and
     * this is red. The browser behaviour, before and after, is in
     * docs/lane-spd/README.md.
     */
    expect(Analytics::LATE)->toContain("function on(e){if(e.type==='scroll'&&e.target!==document)return;h=1;if(l)go()}")
        // Still woken by every real input, and still on its own after 5 s.
        ->and(Analytics::LATE)->toContain("['pointerdown','touchstart','keydown','scroll','wheel','mousemove']")
        ->and(Analytics::LATE)->toContain('setTimeout(go,5000)');
});
