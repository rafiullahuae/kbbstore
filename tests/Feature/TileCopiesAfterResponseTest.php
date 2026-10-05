<?php

declare(strict_types=1);

use App\Models\Product;
use App\Support\ImageVariants;

/**
 * Lane PS -- "Improve image delivery: Est savings of 3,434 KiB".
 *
 * WHAT GOOGLE REPORTED ON THE SHOP (PageSpeed Insights, extrabeauty.ae, 5 Oct
 * 2026, mobile; desktop said 3,583 KiB). Every product tile on the homepage was
 *
 *   <img class="kbb-card-img" src="/wp-content/uploads/2026/09/Anua-PDRN-…webp"
 *        width="400" height="400" loading="lazy" decoding="async">
 *
 * -- no srcset. 1000-1100 px originals of 100-660 KB painted into a ~186 CSS px
 * box ("This image file is larger than it needs to be (1000x1000) for its
 * displayed dimensions (329x329)"). The card emits a srcset only for copies on
 * disk, and the imported WordPress photographs never had any: nothing on a page
 * view ever made them. The 7.7 s mobile Speed Index is those files arriving
 * after the hero.
 *
 * WHAT IS PINNED: a page that finds a tile photograph with no copies renders
 * exactly what it rendered before and makes the copies AFTER the response, so
 * the next view offers them; a response makes at most eight; nothing happens
 * with the switch off. And the guard that makes this safe to switch on: an
 * original narrower than 800 is listed in the srcset at its real width, so a
 * phone is never handed a stretched 400w copy where it used to get the 700 px
 * original.
 *
 * MUTATION NOTES. Delete the `self::tileAfterResponse($image, $fsRel);` line in
 * ImageVariants::srcsetFor() and the first test is red ("the first view did not
 * make the 200w copy"). Delete the `if ($widest < 800)` block and the last test
 * is red (the 700w original is missing from the srcset).
 */
function tcPhoto(string $rel, int $w, int $h): void
{
    $path = public_path($rel);
    @mkdir(\dirname($path), 0775, true);

    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y += 3) {
        imagefilledrectangle($im, 0, $y, $w, $y + 2, (int) imagecolorallocate($im, ($y * 7) % 255, ($y * 13) % 255, ($y * 3) % 255));
    }
    imagewebp($im, $path, 90);
    imagedestroy($im);
}

/** The srcset on the tile <img> whose src is $rel; '' when it has none; null when the tile is absent. */
function tcTileSrcset(string $html, string $rel): ?string
{
    foreach (preg_match_all('/<img\b[^>]*class="kbb-card-img"[^>]*>/s', $html, $m) ? $m[0] : [] as $tag) {
        if (str_contains($tag, 'src="/'.$rel.'"')) {
            return preg_match('/\ssrcset="([^"]*)"/', $tag, $s) === 1 ? $s[1] : '';
        }
    }

    return null;
}

beforeEach(function () {
    if (! ImageVariants::available()) {
        test()->markTestSkipped('this PHP has no GD, which is the thing under test');
    }

    config(['kbb.image_tile_after_response' => true]);
    $this->tcDir = 'wp-content/uploads/tc-'.uniqid();
    $this->tcMade = [];
    $this->seed(\Database\Seeders\DatabaseSeeder::class);
});

afterEach(function () {
    foreach ($this->tcMade as $rel) {
        ImageVariants::forget('/'.$rel);
        @unlink(public_path($rel));
    }
    @rmdir(public_path($this->tcDir));
});

it('makes a tile photograph\'s copies after the response, so the next view of the page offers them', function () {
    $rel = $this->tcDir.'/shot.webp';
    tcPhoto($rel, 1000, 1000);
    $this->tcMade[] = $rel;
    Product::query()->visible()->get()->each(fn (Product $p) => $p->forceFill(['image' => '/'.$rel])->save());

    // The view that finds no copies renders the original and no srcset -- the page as it was.
    expect(tcTileSrcset((string) $this->get('/shop')->assertOk()->getContent(), $rel))->toBe('');

    foreach ([200, 400, 800] as $w) {
        expect(is_file(public_path(ImageVariants::DIR.'/'.$w.'/'.$rel)))->toBeTrue("the first view did not make the {$w}w copy");
    }

    expect(tcTileSrcset((string) $this->get('/shop')->assertOk()->getContent(), $rel))
        ->toBe('/img-cache/200/'.$rel.' 200w, /img-cache/400/'.$rel.' 400w, /img-cache/800/'.$rel.' 800w');
});

it('sizes at most eight photographs per response, and the rest on the views after it', function () {
    $products = Product::query()->visible()->orderBy('id')->limit(12)->get();
    expect($products->count())->toBe(12);

    foreach ($products as $i => $p) {
        $rel = $this->tcDir.'/p'.$i.'.webp';
        tcPhoto($rel, 900, 900);
        $this->tcMade[] = $rel;
        $p->forceFill(['image' => '/'.$rel])->save();
    }

    $sized = fn () => count(array_filter($this->tcMade, fn ($rel) => is_file(public_path(ImageVariants::DIR.'/400/'.$rel))));

    $html = (string) $this->get('/shop')->assertOk()->getContent();
    $onPage = count(array_filter($this->tcMade, fn ($rel) => tcTileSrcset($html, $rel) !== null));
    expect($onPage)->toBeGreaterThan(8, 'the fixture must put more than eight cold tiles on one page')
        ->and($sized())->toBe(8);

    $this->get('/shop')->assertOk();
    expect($sized())->toBe($onPage);
});

it('does nothing at all with the switch off', function () {
    config(['kbb.image_tile_after_response' => false]);
    $rel = $this->tcDir.'/off.webp';
    tcPhoto($rel, 1000, 1000);
    $this->tcMade[] = $rel;
    Product::query()->visible()->get()->each(fn (Product $p) => $p->forceFill(['image' => '/'.$rel])->save());

    $this->get('/shop')->assertOk();

    expect(is_file(public_path(ImageVariants::DIR.'/400/'.$rel)))->toBeFalse();
});

it('lists an original narrower than 800 at its real width, so a phone is never handed a stretched 400w copy', function () {
    $small = $this->tcDir.'/seven-hundred.webp';
    tcPhoto($small, 700, 700);
    $this->tcMade[] = $small;
    ImageVariants::generate('/'.$small);

    expect(ImageVariants::srcsetFor('/'.$small))
        ->toBe('/img-cache/200/'.$small.' 200w, /img-cache/400/'.$small.' 400w, /'.$small.' 700w');

    // With the 800w copy on disk the original is not read and not listed: the string it always was.
    $big = $this->tcDir.'/thousand.webp';
    tcPhoto($big, 1000, 1000);
    $this->tcMade[] = $big;
    ImageVariants::generate('/'.$big);

    expect(ImageVariants::srcsetFor('/'.$big))
        ->toBe('/img-cache/200/'.$big.' 200w, /img-cache/400/'.$big.' 400w, /img-cache/800/'.$big.' 800w');
});
