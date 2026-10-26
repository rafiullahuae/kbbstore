<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Setting;
use App\Services\PageHeaders;
use App\Services\SettingsService;
use App\Support\Locale;
use Illuminate\Support\Facades\DB;
use Tests\Support\PageHeaderRoutes;

/**
 * Pages → Page header → Header picture → Arabic desktop / Arabic phone picture.
 * (Lane PHA)
 *
 * The owner: "on super sale page. i want the header in arabic for arabic
 * language. we have used the banner image in english. can u please create the
 * same banner but in arabic ... also convert this in the desktop version too."
 *
 * Before this, /ar/super-sale/ could only show the English banner: the bag had
 * one desktop and one phone picture for every language.
 */
const PHA_D = '/wp-content/uploads/2026/10/sale-en-wide.webp';
const PHA_M = '/wp-content/uploads/2026/10/sale-en-tall.webp';
const PHA_DA = '/wp-content/uploads/2026/10/sale-ar-wide.webp';
const PHA_MA = '/wp-content/uploads/2026/10/sale-ar-tall.webp';

function phaArabicOn(): void
{
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
}

function phaStore(array $pics): void
{
    $bag = PageHeaders::defaults()['pages']['collection:super-sale'];
    foreach ($pics as $k => $v) {
        $bag[$k] = $v;
    }
    app(SettingsService::class)->set(PageHeaders::KEY, ['global' => PageHeaders::blank(), 'pages' => ['collection:super-sale' => $bag]]);
    SettingsService::forgetMemo();
}

function phaProducts(int $n, string $tag = 'x'): void
{
    for ($i = 1; $i <= $n; $i++) {
        Product::create(['slug' => "pha-{$tag}-{$i}", 'name' => "PHA {$i}", 'type' => 'simple', 'status' => 'publish',
            'is_visible' => true, 'price' => 2000, 'sale_price' => 1000, 'stock_status' => 'instock']);
    }
}

/** The <picture> the configured header printed, or ''. */
function phaPicture(string $html): string
{
    return preg_match('#<picture class="kbb-ph-i">.*?</picture>#s', $html, $m) ? $m[0] : '';
}

function phaExpect(string $d, ?string $m): string
{
    return '<picture class="kbb-ph-i">'.($m !== null ? '<source media="(max-width: 900px)" srcset="'.$m.'">' : '').'<img src="'.$d.'" alt="" decoding="async"></picture>';
}

beforeEach(function () {
    PageHeaderRoutes::wire($this->app);
    phaArabicOn();
});

it('shows the Arabic desktop and phone pictures on /ar/super-sale/ and the English ones on /super-sale/', function () {
    /*
     * DEFECT THIS CATCHES: /ar/super-sale/ showing the English banner (the
     * owner's complaint), or the Arabic banner leaking onto the English page.
     * MUTATION: drop the `if ($ar ...)` block in PageHeaders::picture() -> the
     * Arabic page prints the English pair, red; make it unconditional -> the
     * English page prints the Arabic pair, red.
     */
    phaProducts(1);
    phaStore(['img' => PHA_D, 'img_m' => PHA_M, 'img_ar' => PHA_DA, 'img_m_ar' => PHA_MA]);

    expect(phaPicture($this->get('/ar/super-sale/')->assertOk()->getContent()))->toBe(phaExpect(PHA_DA, PHA_MA))
        ->and(phaPicture($this->get('/super-sale/')->assertOk()->getContent()))->toBe(phaExpect(PHA_D, PHA_M));
});

it('leaves the English page byte-identical whatever the Arabic pictures hold', function () {
    /*
     * "Nothing the owner did not ask about may change": the English page with
     * Arabic pictures set is the same page, byte for byte, as without them.
     * MUTATION: let picture() read img_ar when the locale is not 'ar' -> red.
     */
    phaProducts(1);
    phaStore(['img' => PHA_D, 'img_m' => PHA_M]);
    $before = $this->get('/super-sale/')->getContent();

    phaStore(['img' => PHA_D, 'img_m' => PHA_M, 'img_ar' => PHA_DA, 'img_m_ar' => PHA_MA]);
    $after = $this->get('/super-sale/')->getContent();

    expect($after)->toBe($before);

    // And an English bag with only Arabic pictures draws no picture in English.
    phaStore(['img_ar' => PHA_DA, 'img_m_ar' => PHA_MA]);
    expect(phaPicture($this->get('/super-sale/')->getContent()))->toBe('');
});

it('falls back to the English picture for whichever Arabic picture is empty', function () {
    /*
     * Each combination of the rule, as PageHeaders::picture() documents it.
     * DEFECT THIS CATCHES: an Arabic phone picture alone becoming the Arabic
     * DESKTOP picture while an English desktop exists (a tall phone banner
     * stretched over a laptop), or an empty Arabic picture blanking the header.
     * MUTATION: `$d = $dAr !== '' ? $dAr : $dEn` -> `$dAr ?: ($mAr ?: $dEn)`
     * -> the "phone only" row is red; drop the `$m === ''` guard -> the
     * "desktop only, English has a phone picture" row is red.
     */
    $cases = [
        // English bag + Arabic bag => [desktop, phone] on the Arabic page
        'both Arabic' => [['img' => PHA_D, 'img_m' => PHA_M, 'img_ar' => PHA_DA, 'img_m_ar' => PHA_MA], [PHA_DA, PHA_MA]],
        'Arabic desktop only, English has a phone picture' => [['img' => PHA_D, 'img_m' => PHA_M, 'img_ar' => PHA_DA], [PHA_DA, PHA_M]],
        'Arabic desktop only, English has none for phone' => [['img' => PHA_D, 'img_ar' => PHA_DA], [PHA_DA, PHA_DA]],
        'Arabic phone only' => [['img' => PHA_D, 'img_m' => PHA_M, 'img_m_ar' => PHA_MA], [PHA_D, PHA_MA]],
        'Arabic phone only, English desktop only' => [['img' => PHA_D, 'img_m_ar' => PHA_MA], [PHA_D, PHA_MA]],
        'Arabic phone only, no English picture at all' => [['img_m_ar' => PHA_MA], [PHA_MA, PHA_MA]],
        'Arabic desktop only, no English picture at all' => [['img_ar' => PHA_DA], [PHA_DA, PHA_DA]],
        'no Arabic picture' => [['img' => PHA_D, 'img_m' => PHA_M], [PHA_D, PHA_M]],
    ];

    foreach ($cases as $name => [$pics, [$d, $m]]) {
        $bag = array_replace(PageHeaders::blank(), $pics);
        $ar = PageHeaders::picture($bag, true);
        expect([$ar['d'], $ar['m'], $ar['two']])->toBe([$d, $m, $d !== $m], $name);

        // The English page never moves.
        expect(PageHeaders::picture($bag, false))->toBe(PageHeaders::picture(array_replace($bag, ['img_ar' => '', 'img_m_ar' => '']), false), $name);
    }

    expect(PageHeaders::picture(PageHeaders::blank(), true))->toBeNull();

    // And through the page: phone-only Arabic keeps the English desktop.
    phaProducts(1);
    phaStore(['img' => PHA_D, 'img_m' => PHA_M, 'img_m_ar' => PHA_MA]);
    expect(phaPicture($this->get('/ar/super-sale/')->getContent()))->toBe(phaExpect(PHA_D, PHA_MA));
    phaStore(['img' => PHA_D, 'img_ar' => PHA_DA]);
    expect(phaPicture($this->get('/ar/super-sale/')->getContent()))->toBe(phaExpect(PHA_DA, null));
});

it('refuses a javascript: or data: address in the Arabic pictures, and never prints one from a stored row', function () {
    /*
     * The same door as the English pictures. MUTATION: leave img_ar / img_m_ar
     * out of the validation loop in PageHeaders::bag() -> 200 and stored, red;
     * skip SafeUrl::src() for them in picture() -> the address reaches src, red.
     */
    $this->actingAs(AdminUser::create(['name' => 'PHA', 'email' => 'pha-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'owner']), 'admin');

    foreach (['img_ar' => 'Arabic desktop picture', 'img_m_ar' => 'Arabic phone picture'] as $k => $label) {
        foreach (['javascript:alert(1)', 'data:image/svg+xml,<svg onload=alert(1)>', '/x.png" onerror="alert(1)'] as $bad) {
            $bag = array_replace(PageHeaders::blank(), [$k => $bad]);
            $this->postJson('/admin-api/page-header', ['global' => $bag, 'pages' => []])->assertStatus(422);
            $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:super-sale', 'scope' => 'page', 'bag' => $bag])->assertStatus(422);

            $rejected = [];
            PageHeaders::bag($bag, 'g', $rejected);
            expect($rejected)->toBe(['g.'.$k => $label]);
        }
    }
    expect(DB::table('settings')->where('key', PageHeaders::KEY)->count())->toBe(0);

    phaProducts(1);
    $bag = PageHeaders::blank();
    $bag['img_ar'] = 'javascript:alert(1)';
    $bag['img_m_ar'] = 'data:text/html,x';
    $bag['img'] = PHA_D;
    DB::table('settings')->insert(['key' => PageHeaders::KEY, 'value' => json_encode(['global' => PageHeaders::blank(), 'pages' => ['collection:super-sale' => $bag]]), 'autoload' => true]);
    Setting::flushMap();
    app(SettingsService::class)->flush();

    $html = $this->get('/ar/super-sale/')->getContent();
    expect($html)->not->toContain('javascript:alert')
        ->and($html)->not->toContain('data:text/html')
        ->and(phaPicture($html))->toBe(phaExpect(PHA_D, null));
});

it('round-trips both Arabic pictures through the console save and the on-page apply', function () {
    /*
     * MUTATION: drop img_ar / img_m_ar from PageHeaders::blank() -> bag()
     * never copies them, the saved bag comes back without them, red.
     */
    $this->actingAs(AdminUser::create(['name' => 'PHA', 'email' => 'pha-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'owner']), 'admin');

    $bag = array_replace(PageHeaders::blank(), ['img' => PHA_D, 'img_ar' => PHA_DA, 'img_m_ar' => PHA_MA]);
    $this->postJson('/admin-api/page-header', ['global' => PageHeaders::blank(), 'pages' => ['collection:super-sale' => $bag]])->assertOk();
    app(PageHeaders::class)->forget();
    SettingsService::forgetMemo();

    $saved = app(PageHeaders::class)->bagFor('collection:super-sale');
    expect([$saved['img_ar'], $saved['img_m_ar']])->toBe([PHA_DA, PHA_MA]);

    $bag['img_m_ar'] = '';
    $this->postJson('/admin-api/page-header/apply', ['key' => 'collection:new-in', 'scope' => 'page', 'bag' => $bag])->assertOk();
    app(PageHeaders::class)->forget();
    SettingsService::forgetMemo();

    $saved = app(PageHeaders::class)->bagFor('collection:new-in');
    expect([$saved['img_ar'], $saved['img_m_ar']])->toBe([PHA_DA, '']);

    // The console screen hands the editor the bag with both keys.
    $this->getJson('/admin-api/page-header')->assertOk()
        ->assertJsonPath('pages.collection:super-sale.img_ar', PHA_DA)
        ->assertJsonPath('pages.collection:super-sale.img_m_ar', PHA_MA);
});

it('adds no query to the Arabic page, at three products or forty', function () {
    /*
     * The Arabic pictures come from the bag the request already holds.
     * MUTATION: read the setting again for the Arabic keys in picture() ->
     * on is dearer than off, red.
     */
    $measure = function (int $n, bool $on): int {
        Product::query()->delete();
        phaProducts($n, "q{$n}".($on ? 'on' : 'off'));
        phaStore($on ? ['img' => PHA_D, 'img_ar' => PHA_DA, 'img_m_ar' => PHA_MA] : ['img' => PHA_D]);
        $this->get('/ar/super-sale/');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->get('/ar/super-sale/')->getContent();
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect(str_contains($html, PHA_DA))->toBe($on);

        return $q;
    };

    $off3 = $measure(3, false);
    $on3 = $measure(3, true);
    $on40 = $measure(40, true);

    expect($on3)->toBe($off3)->and($on40)->toBe($on3);
});

it('gives the editor both Arabic picture controls with their hint, and mirrors the rule in JavaScript', function () {
    /*
     * Pages → Page header → Header picture: the controls the owner sets them
     * with, shared by the console screen and the on-page "Edit header" panel.
     * MUTATION: drop either pic() row or the hint -> red; drop the Arabic block
     * from the JS picture() -> the live panel on /ar previews the English
     * picture, red.
     */
    $js = file_get_contents(base_path('resources/js/kbb/admin/page-header-editor.js'));

    expect(substr_count($js, "pic('img_ar', 'Arabic desktop picture')"))->toBe(1)
        ->and(substr_count($js, "pic('img_m_ar', 'Arabic phone picture')"))->toBe(1)
        ->and($js)->toContain('Shown on the Arabic site (/ar). Empty = the English picture.')
        ->and($js)->toContain('const dAr = safeSrc(bag.img_ar);')
        ->and($js)->toContain('if (!mAr && dAr && !m) mAr = dAr;')
        ->and($js)->toContain('d = dAr || dEn;')
        ->and($js)->toContain('m = mAr || mEn;');

    // The built bundle the console loads carries it too.
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
    $built = '';
    foreach ($manifest as $entry) {
        if (str_contains((string) ($entry['file'] ?? ''), 'page-header-editor')) {
            $built .= (string) @file_get_contents(public_path('build/'.$entry['file']));
        }
    }
    expect($built)->toContain('Arabic desktop picture')->and($built)->toContain('img_m_ar');
});
