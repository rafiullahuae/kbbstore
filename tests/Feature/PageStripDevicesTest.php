<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Services\PageBanners;
use App\Services\PageHeaders;
use App\Services\SettingsService;
use Tests\Support\PageBannersRoutes;
use Tests\Support\PageHeaderRoutes;

/**
 * The strip on desktop / on mobile, apart. (2.60.396)
 *
 * The owner, with a laptop screenshot of /super-sale/ and an arrow at the pink
 * "100% Authentic Products · Express Delivery · Free skincare consultation"
 * strip: "i need the strip display control also, to turn off for desktop /
 * mobile." Until now the strip was on for both or off for both.
 */
function sdAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'SD '.$role, 'email' => 'sd-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => $role,
    ]);
}

function sdStore(array $banner, string $page = 'collection:super-sale'): void
{
    app(SettingsService::class)->set(PageBanners::KEY, ['banners' => [$banner], 'assign' => [$page => $banner['id']]]);
    SettingsService::forgetMemo();
    app(PageBanners::class)->forget();
    app(PageHeaders::class)->forget();
}

function sdStrip(string $html): string
{
    return preg_match('#<ul class="kbb-pb-strip[^"]*">#', $html, $m) ? $m[0] : '';
}

beforeEach(function () {
    PageHeaderRoutes::wire($this->app);
    PageBannersRoutes::wire($this->app);
    SettingsService::forgetMemo();
    app(PageBanners::class)->forget();
    Product::create(['slug' => 'sd-1', 'name' => 'SD 1', 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'price' => 2000, 'sale_price' => 1000, 'stock_status' => 'instock']);
});

it('ships every strip on for both, printing exactly the markup it always did', function () {
    // A strip stored before this release has neither key: both read as on.
    $old = PageBanners::blank('super-sale', 'Super Sale');
    unset($old['strip_d'], $old['strip_m']);
    sdStore($old);

    expect(sdStrip($this->get('/super-sale')->getContent()))->toBe('<ul class="kbb-pb-strip">')
        ->and(PageBanners::blank('x', 'X'))->toMatchArray(['strip_d' => true, 'strip_m' => true]);
});

it('hides the strip on one device with a constant class the CSS turns off at that width', function () {
    /*
     * DEFECT THIS CATCHES: "turn off for desktop" saved, and the strip still
     * across the laptop page.
     * MUTATION: swap ' kbb-pb-xm' and ' kbb-pb-xd' in PageBanners::view() ->
     * red on the first expectation.
     */
    sdStore(['strip_d' => false] + PageBanners::blank('super-sale', 'Super Sale'));
    expect(sdStrip($this->get('/super-sale')->getContent()))->toBe('<ul class="kbb-pb-strip kbb-pb-xd">');

    sdStore(['strip_m' => false] + PageBanners::blank('super-sale', 'Super Sale'));
    expect(sdStrip($this->get('/super-sale')->getContent()))->toBe('<ul class="kbb-pb-strip kbb-pb-xm">');

    expect(PageBanners::CSS)->toContain('@media (min-width:901px){.kbb-pb-strip.kbb-pb-xd{display:none}}')
        ->and(PageBanners::CSS)->toContain('@media (max-width:900px){.kbb-pb-strip.kbb-pb-xm{display:none}}');
});

it('prints no strip at all when it is off on both', function () {
    sdStore(['strip_d' => false, 'strip_m' => false] + PageBanners::blank('super-sale', 'Super Sale'));

    expect($this->get('/super-sale')->getContent())->not->toContain('kbb-pb-strip');
});

it('switches the devices from the Edit header panel, and only with the strip capability', function () {
    /*
     * MUTATION: drop $devices from setPage()'s call in PageHeaderApiController
     * -> the stored strip_d stays true, red.
     */
    sdStore(PageBanners::blank('super-sale', 'Super Sale'));
    $this->actingAs(sdAdmin(), 'admin');

    $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:super-sale', 'scope' => 'page', 'bag' => PageHeaders::blank(),
        'strip' => 'super-sale', 'strip_dev' => ['d' => false, 'm' => true]])->assertOk();
    SettingsService::forgetMemo();
    app(PageBanners::class)->forget();
    expect(app(PageBanners::class)->all()['banners'][0])->toMatchArray(['strip_d' => false, 'strip_m' => true]);

    // Devices with no strip to apply to are refused, and a bad value too.
    $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:super-sale', 'scope' => 'page', 'bag' => PageHeaders::blank(),
        'strip_dev' => ['d' => true, 'm' => true]])->assertStatus(422);
    $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:super-sale', 'scope' => 'page', 'bag' => PageHeaders::blank(),
        'strip' => 'super-sale', 'strip_dev' => ['d' => 'nope', 'm' => true]])->assertStatus(422);
});

it('stores a typed value as a boolean through Pages → Page banners, never as text', function () {
    [$clean] = PageBanners::sanitize(['banners' => [['strip_d' => 'false', 'strip_m' => '<b>'] + PageBanners::blank('a', 'A')], 'assign' => []], false);

    expect($clean['banners'][0]['strip_d'])->toBeFalse()
        ->and($clean['banners'][0]['strip_m'])->toBeFalse();
});
