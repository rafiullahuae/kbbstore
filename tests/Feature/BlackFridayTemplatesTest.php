<?php

declare(strict_types=1);

/**
 * 2.60.662 -- Black Friday templates for the Super Sale banner (the owner, 12
 * Nov 2026: "I like A, B and C. but give option to enable disable the gold
 * curved line behind the girl picture ... from the option D ... put the light
 * animation effect etc which we have on the main site logo").
 *
 * The defects these would catch on the shop: a Black Friday pick that draws no
 * arch, an arch that cannot be switched off, the arch (or the light) leaking
 * onto banners that never asked for it, and a light that moves for a shopper
 * who asked her phone for less motion.
 */

use App\Models\Setting;
use App\Services\FreeGifts;
use App\Services\PageHeaders;
use App\Services\SettingsService;
use App\Support\Locale;
use App\Support\SaleTemplates;
use App\Support\SuperSaleBanner;
use Illuminate\Support\Facades\DB;
use Tests\Support\PageBannersRoutes;

function bftSet(array $c): void
{
    app(SettingsService::class)->set(SuperSaleBanner::KEY, $c);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app()->forgetInstance(FreeGifts::class);
    app()->forgetInstance(PageHeaders::class);
}

function bftBlock(string $html): string
{
    return preg_match('#<style id="kbb-ssb-css">.*?</div></div>\n</div>\n#s', $html, $m) ? $m[0] : '';
}

beforeEach(function () {
    PageBannersRoutes::wire($this->app);
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    \App\Models\Coupon::create(['code' => 'GLOW', 'type' => 'fixed_cart', 'amount' => 1000]);
    DB::table('settings')->where('key', SuperSaleBanner::KEY)->delete();
    \Illuminate\Support\Facades\Cache::forget('kbb.settings');
    \App\Models\Product::create(['slug' => 'bft-sale-1', 'name' => 'BFT Sale 1', 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'price' => 2000, 'sale_price' => 1000, 'stock_status' => 'instock']);
});

it('offers three Black Friday templates that draw the arch and the light across the title', function () {
    // MUTATION: drop the `arch = true` in withTemplate() -- red; drop data-t from the partial -- red.
    expect(array_slice(SuperSaleBanner::TEMPLATES, -3))->toBe(['b1', 'b2', 'b3'])
        ->and(SaleTemplates::TEMPLATES['b2'][0])->toBe('Black Friday · Neon Night');

    foreach (['b1', 'b2', 'b3'] as $key) {
        $c = SuperSaleBanner::withTemplate(SuperSaleBanner::defaults(), $key);
        expect($c['arch'])->toBeTrue()->and($c['title_en'])->toBe('BLACK FRIDAY');
        bftSet($c);
        $block = bftBlock($this->get('/super-sale/')->assertOk()->getContent());
        expect($block)->toContain('class="kbb-ssb ssb-t-'.$key.' ssb-arch"')
            ->and($block)->toContain('<div class="ssb-super" data-t="BLACK FRIDAY">BLACK FRIDAY</div>')
            ->and($block)->toContain(SuperSaleBanner::ARCH_CSS)
            ->and($block)->toContain(SaleTemplates::SHINE_KEYFRAMES)
            ->and($block)->toContain('animation:ssb-sh 7s linear infinite')
            // The light stops for a shopper who asked for less motion.
            ->and($block)->toContain('@media (prefers-reduced-motion:reduce){.kbb-ssb.ssb-t-'.$key.' .ssb-super::after{animation:none;display:none}}');
    }
    expect(bftBlock($this->get('/super-sale/')->getContent()))->toContain('>SALE</span>'); // b3's big word
});

it('lets the owner switch the arch off, and on for any template', function () {
    // MUTATION: ignore `arch` in view() -- red both ways.
    $c = SuperSaleBanner::withTemplate(SuperSaleBanner::defaults(), 'b1');
    $c['arch'] = false;
    bftSet($c);
    $block = bftBlock($this->get('/super-sale/')->assertOk()->getContent());
    expect($block)->not->toContain('ssb-arch')->and($block)->not->toContain('#ebc7a8');

    $c = SuperSaleBanner::withTemplate(SuperSaleBanner::defaults(), 'm1');
    $c['arch'] = true;
    bftSet($c);
    $block = bftBlock($this->get('/super-sale/')->assertOk()->getContent());
    expect($block)->toContain('class="kbb-ssb ssb-t-m1 ssb-arch"')->and($block)->toContain(SuperSaleBanner::ARCH_CSS)
        // The light is Black Friday's, not 11.11's.
        ->and($block)->not->toContain('data-t=')->and($block)->not->toContain('animation:ssb-sh');
});

it('leaves every banner that never asked for them exactly as it was', function () {
    // MUTATION: default `arch` to true -- red.
    expect(SuperSaleBanner::defaults()['arch'])->toBeFalse();
    foreach (['super', 'm1', 'u2', 'n3'] as $key) {
        bftSet(SuperSaleBanner::withTemplate(SuperSaleBanner::defaults(), $key));
        $block = bftBlock($this->get('/super-sale/')->assertOk()->getContent());
        expect($block)->not->toContain('ssb-arch')->and($block)->not->toContain('data-t=')->and($block)->not->toContain('animation:ssb-sh');
    }
    // A saved banner from before this release (no `arch` key at all) reads as off.
    expect(SuperSaleBanner::sanitize(['template' => 'b1'])[0]['arch'])->toBeFalse();
});

it('puts the Arabic title in the light too', function () {
    bftSet(SuperSaleBanner::withTemplate(SuperSaleBanner::defaults(), 'b2'));
    $block = bftBlock($this->get('/ar/super-sale/')->assertOk()->getContent());
    expect($block)->toContain('data-t="الجمعة البيضاء"');
});
