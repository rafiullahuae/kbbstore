<?php

declare(strict_types=1);

use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Services\Banners;
use App\Services\SettingsService;
use App\Support\ImageVariants;

/**
 * Lane PF2 -- the homepage banner's srcset had nothing between 800w and the
 * 1920 original.
 *
 * WHAT IT LOOKED LIKE ON THE SHOP. The banner is the full width of the screen
 * (a 1280 frame at 1280, 1440 at 1440 -- measured in Chromium on the Lane PF
 * preview) and its candidates were 200w, 400w, 800w and the original. Every
 * laptop needs more than 800 device pixels, so every laptop downloaded the
 * whole original: 191,771 bytes for the first picture at 1280 where a 1280w
 * copy is 60,528. Lighthouse put the waste at ~189 KiB on the LCP element.
 *
 * WHAT IS PINNED HERE: the wide copies are offered when they are on disk, the
 * markup is byte-for-byte what it was when they are not (the property that
 * makes srcsetFor() safe with no GD and before a batch has run), they are made
 * after the response by the first homepage view that finds them missing, never
 * wider than the original, and forget() takes them with the original.
 */

function wbsPicture(string $rel, int $w, int $h): void
{
    $path = public_path($rel);
    @mkdir(\dirname($path), 0775, true);

    $im = imagecreatetruecolor($w, $h);
    // Not a flat colour: a flat picture encodes to almost nothing and a
    // "the copy is smaller" assertion would pass on nothing.
    for ($y = 0; $y < $h; $y += 4) {
        imagefilledrectangle($im, 0, $y, $w, $y + 3, (int) imagecolorallocate($im, ($y * 7) % 255, ($y * 13) % 255, ($y * 3) % 255));
    }
    imagejpeg($im, $path, 85);
    imagedestroy($im);
}

function wbsHomeWith(string $rel, int $w, int $h): void
{
    BannerCard::query()->delete();
    BannerSet::query()->delete();

    $set = BannerSet::create(['name' => 'Home', 'slug' => 'wbs-'.uniqid(), 'status' => 'publish', 'position' => 0, 'kind' => 'slider']);
    BannerCard::create([
        'banner_set_id' => $set->id, 'image' => $rel, 'image_w' => $w, 'image_h' => $h,
        'alt' => 'Offer', 'button_url' => '/shop/', 'position' => 1, 'status' => 'publish',
    ]);

    $settings = app(SettingsService::class);
    $settings->setModule(Banners::MODULE, true);
    $settings->setModuleSetting(Banners::MODULE, 'set', (string) $set->id);
}

/** The banner <img>'s srcset off the homepage, or null. */
function wbsSrcset(string $html, string $rel): ?string
{
    foreach (preg_match_all('/<img\b[^>]*>/s', $html, $m) ? $m[0] : [] as $tag) {
        if (str_contains($tag, $rel) && preg_match('/\ssrcset="([^"]*)"/', $tag, $s) === 1) {
            return $s[1];
        }
    }

    return null;
}

beforeEach(function () {
    if (! ImageVariants::available()) {
        test()->markTestSkipped('this PHP has no GD, which is the thing under test');
    }

    // tests/Pest.php holds the after-response copies off for every other test.
    ImageVariants::$holdWide = false;
    $this->wbsRel = 'uploads/banners/wbs-'.uniqid().'.jpg';
});

afterEach(function () {
    ImageVariants::forget('/'.$this->wbsRel);
    @unlink(public_path($this->wbsRel));
    ImageVariants::$holdWide = true;
});

it('offers the 1280, 1440 and 1600 copies between 800w and the original once they exist', function () {
    /*
     * MUTATION, run: take WIDE_WIDTHS out of the list bannerSrcsetFor() hands
     * srcsetWithOriginal() and the three widths are missing; point the banner
     * partial back at detailSrcsetFor() and the homepage expectation is red.
     */
    wbsPicture($this->wbsRel, 1920, 800);
    ImageVariants::generate('/'.$this->wbsRel);
    $made = ImageVariants::generateWide('/'.$this->wbsRel);

    expect($made['made'])->toBe(3);

    $srcset = ImageVariants::bannerSrcsetFor('/'.$this->wbsRel);
    $widths = array_map(fn ($c) => (int) rtrim(preg_split('/\s+/', trim($c))[1], 'w'), explode(',', $srcset));

    expect($widths)->toBe([200, 400, 800, 1280, 1440, 1600, 1920]);

    foreach ([1280, 1440, 1600] as $w) {
        expect(getimagesize(public_path(ImageVariants::DIR.'/'.$w.'/'.$this->wbsRel))[0])->toBe($w)
            ->and(filesize(public_path(ImageVariants::DIR.'/'.$w.'/'.$this->wbsRel)))
            ->toBeLessThan(filesize(public_path($this->wbsRel)));
    }

    wbsHomeWith($this->wbsRel, 1920, 800);
    $html = (string) $this->get('/')->assertOk()->getContent();

    expect(wbsSrcset($html, $this->wbsRel))->toBe($srcset)
        // The preload in <head> mirrors the element, or the browser fetches twice.
        ->and($html)->toContain('imagesrcset="'.$srcset.'"');
});

it('prints exactly the old srcset while the wide copies do not exist', function () {
    /*
     * THE PROPERTY THAT MAKES THIS SAFE TO SHIP: no GD on the host, an
     * unwritable cache, a picture the after-response job has not reached --
     * every one of them must render the page as it was. Held, so nothing is
     * made while this runs.
     *
     * MUTATION, run: list WIDE_WIDTHS without the is_file() test and this is
     * red (three candidates that would 404).
     */
    ImageVariants::$holdWide = true;
    wbsPicture($this->wbsRel, 1920, 800);
    ImageVariants::generate('/'.$this->wbsRel);

    expect(ImageVariants::bannerSrcsetFor('/'.$this->wbsRel))
        ->toBe(ImageVariants::detailSrcsetFor('/'.$this->wbsRel))
        ->and(ImageVariants::bannerSrcsetFor('/'.$this->wbsRel))->not->toContain('1280w');

    // And no copies at all is no srcset at all, as before.
    $bare = 'uploads/banners/wbs-bare-'.uniqid().'.jpg';
    wbsPicture($bare, 1920, 800);
    expect(ImageVariants::bannerSrcsetFor('/'.$bare))->toBe('');
    @unlink(public_path($bare));
});

it('makes the missing copies after the response, so the next view offers them', function () {
    /*
     * THE DEFECT THIS GUARDS AGAINST is the one this repository keeps finding:
     * a width nobody ever makes. Nothing on the live shop calls generateWide()
     * by hand and the owner was not asked to press anything, so the homepage
     * itself has to get them made -- and not inside the shopper's request.
     *
     * MUTATION, run: delete the wideAfterResponse() call in bannerSrcsetFor()
     * and the second view is still 800w-then-original.
     */
    wbsPicture($this->wbsRel, 1920, 800);
    ImageVariants::generate('/'.$this->wbsRel);
    wbsHomeWith($this->wbsRel, 1920, 800);

    $first = wbsSrcset((string) $this->get('/')->assertOk()->getContent(), $this->wbsRel);
    expect($first)->not->toContain('1280w');

    // The response is out; defer() has run (the test client terminates the
    // kernel exactly as PHP-FPM does).
    expect(is_file(public_path(ImageVariants::DIR.'/1280/'.$this->wbsRel)))->toBeTrue('the first view did not make the 1280w copy');

    $second = wbsSrcset((string) $this->get('/')->assertOk()->getContent(), $this->wbsRel);
    expect($second)->toContain('/img-cache/1280/'.$this->wbsRel.' 1280w')
        ->and($second)->toContain(' 1440w')
        ->and($second)->toContain(' 1600w');
});

it('never makes a copy as wide as the original or wider', function () {
    /*
     * A 1300px original gets the 1280w copy and nothing above it: generate()'s
     * own rule, which the banner tier goes through unchanged. A 1440w "copy"
     * of a 1300px picture would be an upscale the width descriptor lies about.
     */
    wbsPicture($this->wbsRel, 1300, 400);
    ImageVariants::generate('/'.$this->wbsRel);
    ImageVariants::generateWide('/'.$this->wbsRel);

    expect(is_file(public_path(ImageVariants::DIR.'/1280/'.$this->wbsRel)))->toBeTrue()
        ->and(is_file(public_path(ImageVariants::DIR.'/1440/'.$this->wbsRel)))->toBeFalse()
        ->and(is_file(public_path(ImageVariants::DIR.'/1600/'.$this->wbsRel)))->toBeFalse();

    expect(ImageVariants::bannerSrcsetFor('/'.$this->wbsRel))->toEndWith('1280w, /'.$this->wbsRel.' 1300w');
});

it('leaves the product catalogue out of it: isComplete() and the tile srcset do not look at the banner tier', function () {
    /*
     * Three more entries in WIDTHS would have put every product photograph in
     * the shop back into Media Library -> Image Sizes' backlog. They are a
     * separate list for that reason.
     *
     * MUTATION, run: move 1280/1440/1600 into WIDTHS and isComplete() is
     * false for this fully-sized 1920 picture.
     */
    wbsPicture($this->wbsRel, 1920, 800);
    ImageVariants::generate('/'.$this->wbsRel);

    expect(ImageVariants::isComplete('/'.$this->wbsRel))->toBeTrue();

    ImageVariants::generateWide('/'.$this->wbsRel);
    expect(ImageVariants::srcsetFor('/'.$this->wbsRel))->not->toContain('1280w');
});

it('throws the wide copies away with their original', function () {
    /*
     * A replaced banner must not go on serving the old picture to every laptop
     * because `src` is new and the 1280w candidate is stale.
     *
     * MUTATION, run: walk WIDTHS alone in forget() and the copies survive.
     */
    wbsPicture($this->wbsRel, 1920, 800);
    ImageVariants::generateWide('/'.$this->wbsRel);
    expect(is_file(public_path(ImageVariants::DIR.'/1600/'.$this->wbsRel)))->toBeTrue();

    ImageVariants::forget('/'.$this->wbsRel);

    foreach (ImageVariants::WIDE_WIDTHS as $w) {
        expect(is_file(public_path(ImageVariants::DIR.'/'.$w.'/'.$this->wbsRel)))->toBeFalse();
    }
});
