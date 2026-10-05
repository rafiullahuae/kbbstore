<?php

declare(strict_types=1);

use App\Services\SettingsService;
use App\Services\SpottedSettings;
use App\Support\ImageVariants;

/**
 * Lane PS -- the homepage #KBeautyBliss Spotted grid offered no srcset.
 *
 * WHAT GOOGLE REPORTED (PageSpeed Insights, extrabeauty.ae, 5 Oct 2026, mobile,
 * "Improve image delivery"): six times over,
 *
 *   ul.spt-sgl > li > a.spt-sgc > img
 *   <img src="https://extrabeauty.ae/uploads/appearance/20261005-084827-iutYTXuP.webp"
 *        alt="#KBeautyBliss Spotted photo 3" width="500" height="600" loading="lazy" …>
 *   "This image file is larger than it needs to be (586x699) for its displayed
 *    dimensions (219x284)" -- ~100 KB each, ~600 KB for the row.
 *
 * The admin upload had already made the 200w and 400w copies; the grid just
 * never named them.
 *
 * MUTATION NOTE: take the `@if (($sptSet = …) !== '') srcset=… @endif` out of
 * partials/home/spotted.blade.php and the first case is red (no srcset).
 */
function sgPicture(string $rel): void
{
    $path = public_path($rel);
    @mkdir(\dirname($path), 0775, true);
    $im = imagecreatetruecolor(586, 699);
    for ($y = 0; $y < 699; $y += 3) {
        imagefilledrectangle($im, 0, $y, 586, $y + 2, (int) imagecolorallocate($im, ($y * 5) % 255, ($y * 11) % 255, ($y * 7) % 255));
    }
    imagewebp($im, $path, 90);
    imagedestroy($im);
}

function sgHomeTag(string $rel): ?string
{
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();
    $html = (string) test()->get('/')->assertOk()->getContent();

    return preg_match('#<img src="/'.preg_quote($rel, '#').'"[^>]*>#', $html, $m) === 1 ? $m[0] : null;
}

beforeEach(function () {
    if (! ImageVariants::available()) {
        test()->markTestSkipped('this PHP has no GD');
    }
    $this->seed(\Database\Seeders\DatabaseSeeder::class);
    $this->sgRel = 'uploads/appearance/sg-'.uniqid().'.webp';
    sgPicture($this->sgRel);
    app(SettingsService::class)->set(SpottedSettings::PREFIX.'grid_1_img', '/'.$this->sgRel);
});

afterEach(function () {
    ImageVariants::forget('/'.$this->sgRel);
    @unlink(public_path($this->sgRel));
});

it('offers the upload\'s copies and the original at its real width, sized to the measured box', function () {
    ImageVariants::generate('/'.$this->sgRel);

    $tag = sgHomeTag($this->sgRel);

    expect($tag)->not->toBeNull('the Spotted grid did not draw the picture')
        ->and($tag)->toContain(' srcset="/img-cache/200/'.$this->sgRel.' 200w, /img-cache/400/'.$this->sgRel.' 400w, /'.$this->sgRel.' 586w"')
        ->and($tag)->toContain(' sizes="(max-width: 900px) 33vw, 256px"')
        ->and($tag)->toContain('loading="lazy"');
});

it('prints the tag it always printed when the picture has no copies', function () {
    expect(sgHomeTag($this->sgRel))
        ->toBe('<img src="/'.$this->sgRel.'" alt="#KBeautyBliss Spotted photo 1" width="500" height="600" loading="lazy" decoding="async">');
});

it('declares the six-in-a-row box when the owner picks six', function () {
    ImageVariants::generate('/'.$this->sgRel);
    app(SettingsService::class)->set(SpottedSettings::PREFIX.'grid_cols_d', '6');

    expect(sgHomeTag($this->sgRel))->toContain(' sizes="(max-width: 1680px) 17vw, 270px"');
});
