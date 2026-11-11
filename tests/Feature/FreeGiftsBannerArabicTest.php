<?php

declare(strict_types=1);

/**
 * 2.60.659 -- the "Get Free Gifts" slide in Arabic, and Arabic pictures for any
 * slider slide (the owner, 11 Nov 2026: "why you didn't translate the banner?
 * do it immediately for arabic version and put there ... the tabby tamara line
 * is hiding, make it little up side, in both versions").
 *
 * The defect on the shop: /ar showed the English banner, and on phones the
 * "SHOP NOW, PAY LATER WITH TABBY OR TAMARA" line sat under the slider's bar.
 */

use App\Models\AdminUser;
use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Services\Banners;
use App\Services\SettingsService;
use App\Support\Locale;

function fgarClean(): void
{
    foreach (['get-free-gifts-desktop', 'get-free-gifts-phone', 'get-free-gifts-desktop-2', 'get-free-gifts-phone-2', 'get-free-gifts-desktop-ar-2', 'get-free-gifts-phone-ar-2'] as $n) {
        @unlink(public_path('uploads/banners/'.$n.'.webp'));
        foreach (glob(public_path('img-cache/*/uploads/banners/'.$n.'.webp')) ?: [] as $v) {
            @unlink($v);
        }
    }
}

function fgarSlider(): BannerSet
{
    $set = BannerSet::create(['name' => 'Home', 'slug' => 'fgar-'.uniqid(), 'status' => 'publish', 'position' => 0, 'kind' => 'slider']);
    BannerCard::create(['banner_set_id' => $set->id, 'image' => 'uploads/banners/fgar-old.webp', 'alt' => 'Old', 'position' => 0, 'status' => 'publish']);
    app(SettingsService::class)->setModuleSetting(Banners::MODULE, 'set', (string) $set->id);

    return $set;
}

function fgarArabicOn(): void
{
    app(SettingsService::class)->set(Locale::SETTING_ENABLED, '1');
    app(SettingsService::class)->set(Locale::SETTING_RTL, '1');
    SettingsService::forgetMemo();
    \App\Models\Setting::flushMap();
}

afterEach(fn () => fgarClean());

it('draws the Arabic free gifts pictures on /ar and the new English ones on /, with their small copies', function () {
    /*
     * MUTATION: drop the swap in Banners::load() and /ar draws the English
     * picture -- red. Point the data migration at the old files and the
     * English page still serves the line hidden under the bar -- red.
     */
    fgarClean();
    fgarArabicOn();
    $set = fgarSlider();
    (require database_path('migrations/2028_01_17_100000_free_gifts_banner_first_slide.php'))->up();
    (require database_path('migrations/2028_01_20_100100_free_gifts_banner_arabic.php'))->up();

    $card = BannerCard::query()->where('banner_set_id', $set->id)->orderBy('position')->first();
    expect($card->image)->toBe('uploads/banners/get-free-gifts-desktop-2.webp')
        ->and($card->image_m)->toBe('uploads/banners/get-free-gifts-phone-2.webp')
        ->and($card->image_ar)->toBe('uploads/banners/get-free-gifts-desktop-ar-2.webp')
        ->and($card->image_m_ar)->toBe('uploads/banners/get-free-gifts-phone-ar-2.webp')
        ->and(is_file(public_path('img-cache/1920/uploads/banners/get-free-gifts-desktop-ar-2.webp')))->toBeTrue()
        ->and(is_file(public_path('img-cache/800/uploads/banners/get-free-gifts-phone-ar-2.webp')))->toBeTrue();

    $en = $this->get('/')->assertOk()->getContent();
    expect($en)->toContain('get-free-gifts-desktop-2.webp')
        ->and($en)->toContain('get-free-gifts-phone-2.webp')
        ->and($en)->not->toContain('-ar-2.webp');

    $ar = $this->get('/ar/')->assertOk()->getContent();
    expect($ar)->toContain('get-free-gifts-desktop-ar-2.webp')
        ->and($ar)->toContain('get-free-gifts-phone-ar-2.webp')
        ->and($ar)->not->toContain('get-free-gifts-desktop-2.webp');
});

it('leaves a slide with no Arabic picture as it was on /ar, and phones crop an Arabic desktop picture rather than show English', function () {
    /* MUTATION: keep image_m when only image_ar is set and /ar phones show the English phone picture -- red. */
    fgarArabicOn();
    $set = BannerSet::create(['name' => 'H', 'slug' => 'fgar2-'.uniqid(), 'status' => 'publish', 'position' => 0, 'kind' => 'slider']);
    BannerCard::create(['banner_set_id' => $set->id, 'image' => 'uploads/banners/fgar-a.webp', 'image_m' => 'uploads/banners/fgar-a-m.webp', 'alt' => 'A', 'position' => 0, 'status' => 'publish']);
    BannerCard::create(['banner_set_id' => $set->id, 'image' => 'uploads/banners/fgar-b.webp', 'image_m' => 'uploads/banners/fgar-b-m.webp', 'image_ar' => 'uploads/banners/fgar-b-ar.webp', 'alt' => 'B', 'position' => 1, 'status' => 'publish']);
    app(SettingsService::class)->setModuleSetting(Banners::MODULE, 'set', (string) $set->id);

    $ar = $this->get('/ar/')->assertOk()->getContent();
    expect($ar)->toContain('fgar-a.webp')->and($ar)->toContain('fgar-a-m.webp')
        ->and($ar)->toContain('fgar-b-ar.webp')
        ->and($ar)->not->toContain('fgar-b-m.webp')
        ->and($ar)->not->toContain('fgar-b.webp');

    $en = $this->get('/')->assertOk()->getContent();
    expect($en)->toContain('fgar-b.webp')->and($en)->toContain('fgar-b-m.webp')->and($en)->not->toContain('fgar-b-ar.webp');
});

it('uses the Arabic picture only on a slide whose words box is off -- a box slide translates its words instead', function () {
    /*
     * The owner, 11 Nov 2026: "if we turn off the text box, then it should give
     * the two pictures upload option ... if the text box is ON, then the
     * translation should be done through the box." MUTATION: drop the box_on
     * check in Banners::load() and the box slide draws the Arabic picture -- red.
     */
    fgarArabicOn();
    $set = BannerSet::create(['name' => 'B', 'slug' => 'fgar4-'.uniqid(), 'status' => 'publish', 'position' => 0, 'kind' => 'slider']);
    BannerCard::create(['banner_set_id' => $set->id, 'image' => 'uploads/banners/fgar-box.webp', 'image_ar' => 'uploads/banners/fgar-box-ar.webp', 'box_on' => true, 'heading' => 'Hello', 'heading_ar' => 'مرحبا', 'alt' => 'Box', 'position' => 0, 'status' => 'publish']);
    app(SettingsService::class)->setModuleSetting(Banners::MODULE, 'set', (string) $set->id);

    $ar = $this->get('/ar/')->assertOk()->getContent();
    expect($ar)->toContain('fgar-box.webp')->and($ar)->not->toContain('fgar-box-ar.webp')->and($ar)->toContain('مرحبا');

    // And the console offers the Arabic buttons only while the box is off.
    $js = file_get_contents(resource_path('views/admin/partials/banners-screen.blade.php'));
    expect($js)->toContain("+ (pictures && !d.box_on")
        ->and($js)->toContain("el.onchange = key === 'box_on' ? function(){ apply(); render(); } : apply;");
});

it('saves the Arabic pictures through the same path gate as the English ones', function () {
    /* MUTATION: take image_ar off the gated list and a foreign URL is stored -- red. */
    AdminUser::query()->where('email', 'fgar@example.test')->delete();
    $owner = AdminUser::create(['name' => 'FGAR', 'email' => 'fgar@example.test', 'password' => 'fgar-password-1', 'role' => 'owner']);
    $this->actingAs($owner, 'admin');

    $set = BannerSet::create(['name' => 'G', 'slug' => 'fgar3-'.uniqid(), 'status' => 'publish', 'kind' => 'slider']);
    $card = BannerCard::create(['banner_set_id' => $set->id, 'image' => '', 'status' => 'publish']);

    foreach (['image_ar', 'image_m_ar'] as $f) {
        $this->putJson('/admin-api/banners/cards/'.$card->id, [$f => 'https://evil.test/x.jpg'])->assertOk();
        expect((string) $card->fresh()->{$f})->toBe('');

        $r = $this->putJson('/admin-api/banners/cards/'.$card->id, [$f => 'http://shop.test/uploads/banners/fgar-'.$f.'.webp'])->assertOk();
        expect((string) $card->fresh()->{$f})->toBe('uploads/banners/fgar-'.$f.'.webp')
            ->and($r->json('card.'.$f))->toBe('uploads/banners/fgar-'.$f.'.webp');
    }
});

it('ships light pictures, the right sizes', function () {
    foreach (['desktop-2' => [3840, 1200], 'phone-2' => [1200, 1540], 'desktop-ar-2' => [3840, 1200], 'phone-ar-2' => [1200, 1540]] as $k => [$w, $h]) {
        $f = resource_path('banners/free-gifts/get-free-gifts-'.$k.'.webp');
        $size = getimagesize($f);
        expect($size[0])->toBe($w)->and($size[1])->toBe($h)->and(filesize($f))->toBeLessThan(120 * 1024);
    }
});
