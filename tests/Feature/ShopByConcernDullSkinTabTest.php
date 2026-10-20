<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\HomepageSections;
use App\Services\SettingsService;
use App\Support\ShopByConcern;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArabicShop;

/*
 * The owner, 20 October 2026: "in the shop by conern, i need another tab
 * 'Dull Skin' and do according the icon etc. english and arabic".
 * Migration 2027_11_14_100000 appends it to his stored tabs with its own icon
 * and hand-picked brightening products (the shop has no glow collection, so
 * the automatic source would have repeated the Hydration tab).
 */

function sbcdsMigrate(): void
{
    (require database_path('migrations/2027_11_14_100000_shop_by_concern_dull_skin_tab.php'))->up();
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
    Cache::flush();
}

function sbcdsStoreTabs(array $rows): void
{
    DB::table('settings')->updateOrInsert(['key' => 'home_sbc_tabs'], ['value' => ShopByConcern::clean($rows), 'autoload' => true]);
}

function sbcdsSwitchOn(): void
{
    $rows = [];
    foreach (app(HomepageSections::class)->all() as $key => $row) {
        $rows[$key] = ['desktop' => $key === 'sbc' ? true : $row['desktop'], 'mobile' => $key === 'sbc' ? true : $row['mobile'], 'skin' => $row['skin'], 'order' => $row['order']];
    }
    app(SettingsService::class)->set('homepage_sections', $rows);
    SettingsService::forgetMemo();
}

beforeEach(function () {
    app(SettingsService::class)->set('demo_content', false);
});

it('appends a Dull Skin tab, keeping every tab the owner has in his order', function () {
    /* DEFECT: no Dull Skin tab; or his four tabs reordered/lost.
     * MUTATION: build $rows from defaults() instead of the stored value → red. */
    $mine = array_values(array_filter(ShopByConcern::defaults(), fn ($r) => in_array($r['k'], ['spots', 'ageing', 'acne', 'hydration'], true)));
    $mine = [$mine[1], $mine[2], $mine[0], $mine[3]];
    sbcdsStoreTabs($mine);
    Product::create(['slug' => 'arencia-vitamin-c-booster-shot', 'name' => 'Arencia Vitamin C Booster Shot', 'type' => 'simple', 'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'price' => 9000]);
    Product::create(['slug' => 'anua-rice-70-glow-milky-toner', 'name' => 'Anua Rice 70 Glow Milky Toner', 'type' => 'simple', 'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'price' => 8000]);

    sbcdsMigrate();

    $rows = ShopByConcern::rows((string) DB::table('settings')->where('key', 'home_sbc_tabs')->value('value'));
    expect(array_column($rows, 'k'))->toBe([$mine[0]['k'], $mine[1]['k'], $mine[2]['k'], $mine[3]['k'], 'dullskin']);
    $d = end($rows);
    expect($d['l'])->toBe('Dull Skin')->and($d['la'])->toBe('البشرة الباهتة')->and($d['i'])->toBe('dullskin')
        ->and($d['src'])->toBe('manual')->and($d['picks'])->toHaveCount(2)->and($d['on'])->toBeTrue();

    // Running it again changes nothing.
    $before = DB::table('settings')->where('key', 'home_sbc_tabs')->value('value');
    sbcdsMigrate();
    expect(DB::table('settings')->where('key', 'home_sbc_tabs')->value('value'))->toBe($before);
});

it('draws the Dull Skin tab with its own icon in English and in Arabic', function () {
    /* DEFECT: the tab missing on the page, or the Arabic page showing English words.
     * MUTATION: drop the 'dullskin' icon from ICONS → the row falls back to Acne's face, red. */
    sbcdsStoreTabs(array_values(array_filter(ShopByConcern::defaults(), fn ($r) => $r['k'] === 'acne')));
    foreach (['arencia-vitamin-c-booster-shot', 'anua-rice-70-glow-milky-toner', 'dr-althea-vitamin-c-boosting-serum'] as $i => $slug) {
        Product::create(['slug' => $slug, 'name' => 'Glow '.$i, 'type' => 'simple', 'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'price' => 9000 + $i]);
    }
    sbcdsMigrate();
    sbcdsSwitchOn();

    $en = $this->get('/')->assertOk()->getContent();
    expect($en)->toContain('Dull Skin')->and($en)->toContain('Glow 0')
        ->and($en)->toContain('M19.6 2.9l.6 1.7');

    ArabicShop::on();
    Cache::flush();
    $ar = $this->get('/ar/')->assertOk()->getContent();
    expect($ar)->toContain('البشرة الباهتة')->and($ar)->toContain('غالبًا ما يكون بهتان البشرة');
});

it('ships the icon with its own colours, so the admin picker can offer it', function () {
    expect(ShopByConcern::ICONS)->toHaveKey('dullskin')
        ->and(ShopByConcern::TONES)->toHaveKey('dullskin')
        ->and(collect(ShopByConcern::editorVocab()['icons'])->pluck('key')->all())->toContain('dullskin');
});
