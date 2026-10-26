<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Setting;
use App\Services\PageHeaders;
use App\Services\SettingsService;
use App\Support\Locale;
use Illuminate\Support\Facades\DB;
use Tests\Support\PageHeaderRoutes;

/**
 * The owner, 19 October 2026: "can you please apply this super sale banners on
 * arabic version. but don't disturb anything else. give us choice to use this
 * exact banner, or upload our own one simple image." / "must be edit from the
 * super page front-end".
 *
 * Migration 2027_11_12_100000 ships the two Arabic banners and sets them as the
 * Super Sale header's Arabic pictures. Before it, /ar/super-sale/ showed the
 * English "SUPER SALE / UP TO 40% OFF" banner on the Arabic shop.
 */
const SSAB_EN_D = 'https://kbeautybliss.com/uploads/appearance/kbb-super-sale-desktop.webp';
const SSAB_EN_M = 'https://kbeautybliss.com/uploads/appearance/kbeautybliss-2026-super-sale.webp';

function ssabRun(): void
{
    (require database_path('migrations/2027_11_12_100000_super_sale_arabic_banners.php'))->up();
    Setting::flushMap();
    SettingsService::forgetMemo();
}

function ssabStore(array $pics): void
{
    $bag = PageHeaders::defaults()['pages']['collection:super-sale'];
    foreach ($pics as $k => $v) {
        $bag[$k] = $v;
    }
    app(SettingsService::class)->set(PageHeaders::KEY, ['global' => PageHeaders::blank(), 'pages' => ['collection:super-sale' => $bag]]);
    Setting::flushMap();
    SettingsService::forgetMemo();
}

function ssabBag(): array
{
    return json_decode((string) DB::table('settings')->where('key', PageHeaders::KEY)->value('value'), true)['pages']['collection:super-sale'];
}

function ssabCleanup(): void
{
    foreach (['kbb-super-sale-desktop-ar.webp', 'kbeautybliss-2026-super-sale-ar.webp'] as $f) {
        @unlink(public_path('uploads/appearance/'.$f));
    }
    @rmdir(public_path('uploads/appearance'));
    @rmdir(public_path('uploads'));
}

beforeEach(function () {
    PageHeaderRoutes::wire($this->app);
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    // Lane SSB: the live Super Sale banner ships ON and takes the header
    // picture's place. These cases pin the PICTURE path, which is what the page
    // draws with that banner switched off (SuperSaleLiveBannerTest pins both).
    app(SettingsService::class)->set(\App\Support\SuperSaleBanner::KEY, ['on' => false]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    Product::create(['slug' => 'ssab-1', 'name' => 'SSAB 1', 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'price' => 2000, 'sale_price' => 1000, 'stock_status' => 'instock']);
});

afterEach(fn () => ssabCleanup());

it('puts the Arabic banners on /ar/super-sale/ and leaves /super-sale/ byte-identical', function () {
    /*
     * DEFECT: /ar/super-sale/ showed the English banner on the Arabic shop.
     * MUTATION: return before the settings write in the migration -> the Arabic
     * page still prints the English pair, red.
     */
    ssabStore(['img' => SSAB_EN_D, 'img_m' => SSAB_EN_M]);
    $enBefore = $this->get('/super-sale/')->assertOk()->getContent();

    ssabRun();

    $ar = $this->get('/ar/super-sale/')->assertOk()->getContent();
    expect($ar)->toContain('srcset="https://kbeautybliss.com/uploads/appearance/kbeautybliss-2026-super-sale-ar.webp"')
        ->and($ar)->toContain('<img src="https://kbeautybliss.com/uploads/appearance/kbb-super-sale-desktop-ar.webp"')
        ->and($this->get('/super-sale/')->getContent())->toBe($enBefore)
        ->and(is_file(public_path('uploads/appearance/kbb-super-sale-desktop-ar.webp')))->toBeTrue()
        ->and(is_file(public_path('uploads/appearance/kbeautybliss-2026-super-sale-ar.webp')))->toBeTrue();

    // In the Media Library, so the on-page "Edit header" panel can pick them again.
    expect(DB::table('media')->whereIn('path', ['uploads/appearance/kbb-super-sale-desktop-ar.webp', 'uploads/appearance/kbeautybliss-2026-super-sale-ar.webp'])->count())->toBe(2);
});

it('never replaces an Arabic picture the owner chose', function () {
    /*
     * "give us choice ... or upload our own one simple image": his own upload
     * must survive this package. MUTATION: drop the "both empty" guard -> red.
     */
    ssabStore(['img' => SSAB_EN_D, 'img_m' => SSAB_EN_M, 'img_m_ar' => 'https://kbeautybliss.com/uploads/appearance/mine.webp']);

    ssabRun();

    expect(ssabBag()['img_m_ar'])->toBe('https://kbeautybliss.com/uploads/appearance/mine.webp')
        ->and(ssabBag()['img_ar'] ?? '')->toBe('');
});

it('does nothing when the page does not show the English banners these translate', function () {
    /*
     * An Arabic translation of a banner the page no longer shows would be a
     * different promotion on /ar. MUTATION: drop the filename guard -> red.
     */
    ssabStore(['img' => 'https://kbeautybliss.com/uploads/appearance/other.webp']);
    $before = DB::table('settings')->where('key', PageHeaders::KEY)->value('value');

    ssabRun();

    expect(DB::table('settings')->where('key', PageHeaders::KEY)->value('value'))->toBe($before)
        ->and(is_file(public_path('uploads/appearance/kbb-super-sale-desktop-ar.webp')))->toBeFalse();
});

it('ships both banners in the package as small webp files', function () {
    foreach (['kbb-super-sale-desktop-ar.webp' => [1925, 468], 'kbeautybliss-2026-super-sale-ar.webp' => [900, 520]] as $f => [$w, $h]) {
        $info = getimagesize(resource_path('banners/'.$f));
        expect($info[0])->toBe($w)->and($info[1])->toBe($h)->and($info['mime'])->toBe('image/webp')
            ->and(filesize(resource_path('banners/'.$f)))->toBeLessThan(60 * 1024);
    }
});
