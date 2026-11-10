<?php

declare(strict_types=1);

/**
 * 2.60.655 -- the "Get Free Gifts" banner as slide 1 of the homepage slider
 * (the owner, 10 Nov 2026: "add this banner by default on 1st position in
 * slider ... don't compromise on the speed").
 */

use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Services\Banners;
use App\Services\SettingsService;

function fgbsMigration(): object
{
    return require database_path('migrations/2028_01_17_100000_free_gifts_banner_first_slide.php');
}

function fgbsClean(): void
{
    foreach (['get-free-gifts-desktop.webp', 'get-free-gifts-phone.webp'] as $f) {
        @unlink(public_path('uploads/banners/'.$f));
        foreach (glob(public_path('img-cache/*/uploads/banners/'.$f)) ?: [] as $v) {
            @unlink($v);
        }
    }
}

afterEach(fn () => fgbsClean());

it('puts the free gifts banner FIRST in the homepage slider, keeps every other slide in order, and makes its small copies', function () {
    /*
     * The defect: a banner added at the end (the owner asked for slide 1), an
     * existing slide pushed out of order, or a slide served at full size
     * because its resized copies were never made (the speed promise).
     * MUTATION: drop the increment() and the new slide ties with slide 1;
     * drop ImageVariants::generateWide() and the 1920 copy check is red.
     */
    fgbsClean();
    $set = BannerSet::create(['name' => 'Home', 'slug' => 'fgbs-'.uniqid(), 'status' => 'publish', 'position' => 0, 'kind' => 'slider']);
    foreach ([0, 1, 2] as $i) {
        BannerCard::create(['banner_set_id' => $set->id, 'image' => 'uploads/banners/old-'.$i.'.webp', 'alt' => 'Old '.$i, 'position' => $i, 'status' => 'publish']);
    }
    app(SettingsService::class)->setModuleSetting(Banners::MODULE, 'set', (string) $set->id);

    fgbsMigration()->up();
    fgbsMigration()->up(); // twice: nothing more

    $order = BannerCard::query()->where('banner_set_id', $set->id)->orderBy('position')->pluck('image')->all();
    expect($order)->toBe(['uploads/banners/get-free-gifts-desktop.webp', 'uploads/banners/old-0.webp', 'uploads/banners/old-1.webp', 'uploads/banners/old-2.webp']);

    $card = BannerCard::query()->where('image', 'uploads/banners/get-free-gifts-desktop.webp')->first();
    expect($card->image_m)->toBe('uploads/banners/get-free-gifts-phone.webp')
        ->and($card->button_url)->toBe('/super-sale/')
        ->and($card->status)->toBe('publish')
        ->and(is_file(public_path('uploads/banners/get-free-gifts-desktop.webp')))->toBeTrue()
        ->and(is_file(public_path('img-cache/1920/uploads/banners/get-free-gifts-desktop.webp')))->toBeTrue()
        ->and(is_file(public_path('img-cache/800/uploads/banners/get-free-gifts-phone.webp')))->toBeTrue();
});

it('ships light pictures in the package and writes nothing when no homepage slider is chosen', function () {
    // The pictures travel in resources/ (a package may not write into public/),
    // small enough to keep the homepage light. MUTATION: ship the PNG render
    // instead and the size check is red.
    foreach (['desktop' => [3840, 1200], 'phone' => [1200, 1540]] as $k => [$w, $h]) {
        $f = resource_path('banners/free-gifts/get-free-gifts-'.$k.'.webp');
        $size = getimagesize($f);
        expect($size[0])->toBe($w)->and($size[1])->toBe($h)->and(filesize($f))->toBeLessThan(120 * 1024);
    }

    fgbsClean();
    app(SettingsService::class)->setModuleSetting(Banners::MODULE, 'set', '');
    $before = BannerCard::query()->count();
    fgbsMigration()->up();
    expect(BannerCard::query()->count())->toBe($before);
});
