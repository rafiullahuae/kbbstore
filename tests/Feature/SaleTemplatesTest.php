<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\FreeGifts;
use App\Services\PageHeaders;
use App\Services\SettingsService;
use App\Support\Locale;
use App\Support\SaleTemplates;
use App\Support\SuperSaleBanner;
use Illuminate\Support\Facades\DB;
use Tests\Support\PageBannersRoutes;

/**
 * The Super Sale banner's occasion templates (Lane SOT).
 *
 * The owner, 6 November 2026: "select and switch the heading layout as per the
 * occasion from the list ... 11.11, UAE National Day Sale, and general we have
 * already super sale. keep that too", then New Year, then "just like
 * pre-templates chosen option and to edit it if needed. don't disturb
 * anything existing."
 *
 * Mutation notes, each a line a case below goes red without:
 *   - sanitize() storing any `template` string                          -> case 1
 *   - the partial printing `ssb-t-super` / css() appending for 'super'    -> case 2
 *   - SaleTemplates::css() returning editorCss() (all ten)               -> case 3
 *   - `{!! $ssb['num'] !!}` instead of the escaping echo                 -> case 4
 *   - WORDS' Arabic, or view() reading num_en on /ar/                    -> case 5
 *   - withTemplate() keeping his colour overrides, or dropping sizes    -> case 6
 *   - styleDefaults() ignoring the template (an untouched banner then
 *     prints the template's palette as inline overrides)                -> case 7
 *   - JS defaultsFor()/elementsFor()/applyTemplate() drifting from PHP  -> case 8
 *   - a round()/color-mix() or a `<` in a template's CSS                -> case 9
 */
function sotFlush(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app()->forgetInstance(FreeGifts::class);
    app()->forgetInstance(PageHeaders::class);
}

function sotSet(array $c): void
{
    app(SettingsService::class)->set(SuperSaleBanner::KEY, $c);
    sotFlush();
}

/** The banner the page printed -- its stylesheet and its block -- or ''. */
function sotBlock(string $html): string
{
    return preg_match('#<style id="kbb-ssb-css">.*?</div></div>\n</div>\n#s', $html, $m) ? $m[0] : '';
}

beforeEach(function () {
    PageBannersRoutes::wire($this->app);
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    \App\Models\Coupon::create(['code' => 'GLOW', 'type' => 'fixed_cart', 'amount' => 1000]);
    DB::table('settings')->where('key', SuperSaleBanner::KEY)->delete();
    \Illuminate\Support\Facades\Cache::forget('kbb.settings');
    sotFlush();
    \App\Models\Product::create(['slug' => 'ssb-sale-1', 'name' => 'SSB Sale 1', 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'price' => 2000, 'sale_price' => 1000, 'stock_status' => 'instock']);
});

it('stores one of its own templates or the default, and refuses anything else', function () {
    /*
     * DEFECT IT GUARDS: rule 5's select. The key becomes a class and picks a
     * stylesheet; anything not on the list must never reach the page.
     */
    expect(SuperSaleBanner::defaults()['template'])->toBe('super')
        ->and(SuperSaleBanner::TEMPLATES)->toBe(array_keys(SaleTemplates::TEMPLATES));

    foreach (array_keys(SaleTemplates::TEMPLATES) as $key) {
        [$c, $bad] = SuperSaleBanner::sanitize(['template' => $key]);
        expect($c['template'])->toBe($key)->and($bad)->toBe([]);
    }
    foreach (['m9', 'super"><script>', '', 'M1', 7, ['m1'], null] as $junk) {
        [$c, $bad] = SuperSaleBanner::sanitize(['template' => $junk]);
        expect($c['template'])->toBe('super');
        if ($junk !== null) {
            expect($bad)->toHaveKey('template');
        }
        // The read path: a damaged row is the shipped banner, never a 500.
        expect(SuperSaleBanner::sanitize(['template' => $junk], false))->toBe([$c, []]);
    }

    $owner = AdminUser::create(['name' => 'SOT', 'email' => 'sot-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'owner']);
    $this->actingAs($owner, 'admin')->postJson('/admin-api/page-banners', ['super_sale_banner' => ['template' => 'x1'] + SuperSaleBanner::defaults()])
        ->assertStatus(422);
    expect(DB::table('settings')->where('key', SuperSaleBanner::KEY)->exists())->toBeFalse();
    $this->actingAs($owner, 'admin')->postJson('/admin-api/page-banners', ['super_sale_banner' => SuperSaleBanner::withTemplate(SuperSaleBanner::defaults(), 'u2')])
        ->assertOk();
    sotFlush();
    expect(SuperSaleBanner::config(app(SettingsService::class)->all())['template'])->toBe('u2');
});

it('draws Super Sale, the default, byte for byte as before the templates', function () {
    /*
     * DEFECT IT GUARDS: "don't disturb anything existing". These are the
     * stylesheet and block /super-sale/ printed BEFORE this lane, captured on
     * this file's fixture with the pre-template SuperSaleBanner and partial:
     * an untouched banner, a banner saved as 'super', and one switched to a
     * template and back must all print exactly them. The picture is left out
     * of the comparison: whether the shipped cutout's file (and its img-cache
     * copies) is on disk depends on which tests ran before, not on this lane,
     * and the suite saw it both ways. MUTATION: give the default banner its class or an empty
     * ssb-num line -> red.
     */
    $pins = ['/super-sale/' => '60793aa742a0cb6032dac5b2c7a0aea3ebaabd2b', '/ar/super-sale/' => '97eaab744f23c115d04c57dfa9bf92cc87386e66'];

    foreach ([null, SuperSaleBanner::defaults(), SuperSaleBanner::withTemplate(SuperSaleBanner::withTemplate(SuperSaleBanner::defaults(), 'n3'), 'super')] as $c) {
        if ($c === null) {
            DB::table('settings')->where('key', SuperSaleBanner::KEY)->delete();
            \Illuminate\Support\Facades\Cache::forget('kbb.settings');
            sotFlush();
        } else {
            sotSet($c);
        }
        foreach ($pins as $path => $sha) {
            $block = sotBlock($this->get($path)->assertOk()->getContent());
            expect($block)->not->toBe('')->not->toContain('ssb-t-')->not->toContain('ssb-num')
                ->and(sha1((string) preg_replace('#<div class="ssb-model[^"]*"><picture>.*?</picture></div>#s', '', $block)))->toBe($sha, $path);
        }
    }
    expect(SaleTemplates::css('super'))->toBe('')->and(SaleTemplates::cls('super'))->toBe('');
});

it('prints the chosen template\'s stylesheet only, after the banner\'s own, and the same queries as Super Sale', function () {
    /*
     * DEFECT IT GUARDS: ten templates' CSS on every visit (about 64 KB, the
     * National Day skylines alone 8 KB each) -- or a template that costs a
     * query. One class, one stylesheet of its own, nothing else.
     */
    $count = function (): int {
        $this->get('/super-sale/');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/super-sale/');
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $super = $count();
    $keys = array_keys(SaleTemplates::TEMPLATES);

    foreach (array_diff($keys, ['super']) as $key) {
        sotSet(SuperSaleBanner::withTemplate(SuperSaleBanner::defaults(), $key));
        $block = sotBlock($this->get('/super-sale/')->assertOk()->getContent());

        expect($block)->toContain('<style id="kbb-ssb-css">'.SuperSaleBanner::css().SuperSaleBanner::templateCss($key).'</style>')
            ->and($block)->toContain('<div class="kbb-ssb ssb-t-'.$key.'" dir="ltr" data-ssb>')
            ->and(strlen(SaleTemplates::css($key)))->toBeLessThan(9000);
        foreach (array_diff($keys, [$key]) as $other) {
            expect($block)->not->toContain('ssb-t-'.$other);
        }
        expect($count())->toBe($super, $key);
    }
});

it('escapes the big number and every word he types over a template', function () {
    sotSet(['num_en' => '<img src=x onerror=alert(1)>"11"&11', 'title_en' => '"><svg onload=x>'] + SuperSaleBanner::withTemplate(SuperSaleBanner::defaults(), 'm1'));

    $block = sotBlock($this->get('/super-sale/')->getContent());

    // Tags are stripped on the way in (SuperSaleBanner::text()), and what is
    // left is printed escaped.
    expect($block)->not->toContain('onerror')->not->toContain('<svg onload')
        ->and($block)->toContain('<span class="ssb-num" aria-hidden="true">&quot;11&quot;&amp;11</span>')
        ->and($block)->toContain('<div class="ssb-super">&quot;&gt;</div>');

    // A template with no number draws none, whatever the stored word says.
    sotSet(['num_en' => '55'] + SuperSaleBanner::withTemplate(SuperSaleBanner::defaults(), 'u1'));
    expect(sotBlock($this->get('/super-sale/')->getContent()))->not->toContain('ssb-num');
});

it('speaks its own Arabic on /ar/super-sale/, right to left', function () {
    sotSet(SuperSaleBanner::withTemplate(SuperSaleBanner::defaults(), 'm2'));
    $block = sotBlock($this->get('/ar/super-sale/')->assertOk()->getContent());
    expect($block)->toContain('<div class="kbb-ssb ssb-t-m2" dir="rtl" data-ssb>')
        ->and($block)->toContain('<span class="ssb-num" aria-hidden="true">11.11</span><div class="ssb-super">تخفيضات ميجا</div>')
        ->and($block)->toContain('أكبر تخفيضات العام')
        ->and($block)->not->toContain('MEGA SALE');

    sotSet(SuperSaleBanner::withTemplate(SuperSaleBanner::defaults(), 'u1'));
    $block = sotBlock($this->get('/ar/super-sale/')->getContent());
    expect($block)->toContain('تخفيضات اليوم الوطني')->toContain('عيد الاتحاد · ٢ ديسمبر');

    sotSet(SuperSaleBanner::withTemplate(SuperSaleBanner::defaults(), 'n1'));
    expect(sotBlock($this->get('/ar/super-sale/')->getContent()))->toContain('تخفيضات العام الجديد')->toContain('<span class="ssb-num" aria-hidden="true">2027</span>');

    // Every template has both languages of every word it fills in.
    foreach (SaleTemplates::WORDS as $key => $words) {
        expect(array_keys($words))->toBe(['title', 'tagline', 'num'], $key);
        expect($words['title'][1])->not->toBe('')->and(preg_match('/\p{Arabic}/u', $words['title'][1]))->toBe(1, $key);
    }
});

it('fills in the template\'s words and palette, and keeps every word and size his to edit', function () {
    /*
     * DEFECT IT GUARDS: "pre-templates chosen option and to edit it if
     * needed". Picking a template fills its words and clears only his colour
     * overrides; whatever he types or sets after it is what the page draws.
     */
    $c = SuperSaleBanner::defaults();
    $c['code'] = 'GLOW';
    $c['pay_en'] = 'MY PAY LINE';
    $c['st'] = ['en' => ['d' => ['title' => ['s' => 61.0, 'c' => '#123456']]], 'ar' => []];

    $c = SuperSaleBanner::withTemplate($c, 'm1');
    expect($c['template'])->toBe('m1')
        ->and([$c['title_en'], $c['tagline_en'], $c['num_en'], $c['title_ar'], $c['num_ar']])->toBe(['MEGA SALE', '', '11.11', 'تخفيضات ميجا', '11.11'])
        ->and($c['pay_en'])->toBe('MY PAY LINE')                 // his, untouched
        ->and($c['st']['en']['d']['title'])->toBe(['s' => 61.0]); // size kept, colour cleared
    expect(SuperSaleBanner::withTemplate($c, 'nope'))->toBe($c);

    // He edits on top of the template: his words, his colour, his number size.
    $c['title_en'] = 'ELEVEN ELEVEN';
    $c['num_en'] = '11/11';
    $c['st']['en']['d']['title']['c'] = '#00FF00';
    $c['st']['en']['m']['num'] = ['s' => 50.0];
    [$clean, $bad] = SuperSaleBanner::sanitize($c);
    expect($bad)->toBe([]);
    sotSet($clean);

    $html = $this->get('/super-sale/')->getContent();
    expect($html)->toContain('<span class="ssb-num" aria-hidden="true">11/11</span><div class="ssb-super">ELEVEN ELEVEN</div>')
        ->and($html)->toContain('MY PAY LINE')
        ->and($html)->toContain('--ssb-title-cd:#00FF00')
        ->and($html)->toContain('--ssb-title-sd:6.1')
        ->and($html)->toContain('--ssb-num-sm:'.SuperSaleBanner::num(50 / 3.9));
});

it('reads a template\'s own sizes and colours as the shipped values, so an untouched template prints no style', function () {
    /*
     * DEFECT IT GUARDS: the template's palette written as the var() FALLBACK,
     * never on the element: his inline override must still win, and a
     * template nobody has touched must carry no style attribute at all.
     */
    foreach (array_keys(SaleTemplates::TEMPLATES) as $key) {
        $c = SuperSaleBanner::sanitize(SuperSaleBanner::withTemplate(SuperSaleBanner::defaults(), $key))[0];
        expect(SuperSaleBanner::styleAttr($c, 'en'))->toBe('', $key)
            ->and(SuperSaleBanner::styleAttr($c, 'ar'))->toBe('', $key);
    }

    $m1 = SuperSaleBanner::styleDefaults('en', 'd', 'm1');
    expect($m1['title']['c'])->toBe('#FFD36E')->and($m1['title']['s'])->toBe(50.0)->and($m1['num']['s'])->toBe(130.0);
    expect(SaleTemplates::css('m1'))->toContain('color:var(--ssb-title-cd,#FFD36E)')
        ->toContain('font-size:calc(var(--u)*var(--ssb-title-sd,5))')
        ->not->toMatch('/--ssb-[a-z]+-[a-z]{1,2}[md]:/');   // never SETS one of his properties

    // Arabic's own phone title for New Year, in the CSS and the defaults alike.
    expect(SuperSaleBanner::styleDefaults('ar', 'm', 'n1')['title']['s'])->toBe(21.45)
        ->and(SaleTemplates::css('n1'))->toContain('[dir=rtl] .ssb-super{font-size:calc(var(--u)*var(--ssb-title-sm,5.5))}');
});

it('switches templates and computes the style the same in the editor\'s JavaScript as in PHP', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null')) ?: (is_file('/opt/node22/bin/node') ? '/opt/node22/bin/node' : '');
    if ($node === '') {
        $this->markTestSkipped('node is not installed');
    }

    mt_srand(1111);
    $keys = array_keys(SaleTemplates::TEMPLATES);
    $els = array_merge(array_keys(SuperSaleBanner::ELEMENTS), ['num']);
    $configs = [];
    for ($i = 0; $i < 40; $i++) {
        $st = ['en' => [], 'ar' => []];
        foreach (['en', 'ar'] as $lang) {
            foreach (['m', 'd'] as $dev) {
                foreach ($els as $el) {
                    if (mt_rand(0, 2) === 0) {
                        $st[$lang][$dev][$el] = array_filter([
                            's' => mt_rand(0, 1) ? mt_rand(80, 900) / 10 : null,
                            'c' => mt_rand(0, 1) ? sprintf('#%06X', mt_rand(0, 0xFFFFFF)) : null,
                            'x' => mt_rand(0, 1) ? mt_rand(-40, 40) : null,
                            'v' => mt_rand(0, 3) === 0 ? (bool) mt_rand(0, 1) : null,
                        ], static fn ($v) => $v !== null);
                    }
                }
                if (mt_rand(0, 2) === 0) {
                    $st[$lang][$dev]['_'] = ['g' => mt_rand(0, 300) / 10];
                }
            }
        }
        [$clean] = SuperSaleBanner::sanitize(['st' => $st, 'template' => $keys[$i % count($keys)]], false);
        $configs[] = ['c' => $clean, 'to' => $keys[mt_rand(0, count($keys) - 1)]];
    }

    $spec = SuperSaleBanner::editorContext(app(SettingsService::class)->all(), null)['spec'];
    $in = tempnam(sys_get_temp_dir(), 'sot');
    file_put_contents($in, json_encode(['spec' => $spec, 'configs' => array_map(static fn ($x) => ['c' => SuperSaleBanner::forJson($x['c']), 'to' => $x['to']], $configs)]));
    $module = base_path('resources/js/kbb/admin/super-sale-banner-editor.js');
    $script = "import {styleAttr, applyTemplate} from '{$module}'; import {readFileSync} from 'node:fs';"
        ."globalThis.document={};const d=JSON.parse(readFileSync(process.argv[1],'utf8'));"
        ."process.stdout.write(JSON.stringify(d.configs.map(({c,to})=>{const a=[styleAttr(c,d.spec,'en'),styleAttr(c,d.spec,'ar')];"
        ."const s=applyTemplate(d.spec,JSON.parse(JSON.stringify(c)),to);return [...a,styleAttr(s,d.spec,'en'),styleAttr(s,d.spec,'ar'),s.template,s.title_ar,s.num_en];})));";
    $out = json_decode((string) shell_exec(escapeshellarg($node).' --input-type=module -e '.escapeshellarg($script).' '.escapeshellarg($in)), true);
    @unlink($in);

    expect($out)->toBeArray()->toHaveCount(40);
    foreach ($configs as $n => ['c' => $c, 'to' => $to]) {
        $s = SuperSaleBanner::withTemplate($c, $to);
        expect($out[$n])->toBe([
            SuperSaleBanner::styleAttr($c, 'en'), SuperSaleBanner::styleAttr($c, 'ar'),
            SuperSaleBanner::styleAttr($s, 'en'), SuperSaleBanner::styleAttr($s, 'ar'), $to, $s['title_ar'], $s['num_en'],
        ], "config $n");
    }
});

it('keeps every template\'s CSS on the landmine rules: constants, no newer function beside var(), no markup', function () {
    foreach (array_keys(SaleTemplates::TEMPLATES) as $key) {
        $css = SaleTemplates::css($key).SaleTemplates::cssForced($key);
        if ($key === 'super') {
            expect($css)->toBe('');

            continue;
        }
        expect($css)->not->toContain('<')->not->toContain('round(')->not->toContain('color-mix(')
            ->not->toContain('@import')->not->toMatch('#url\((?!"data:image/svg\+xml,)#');
        // Every rule is scoped to the banner wearing this template's class.
        preg_match_all('/(?:^|[{}])([^{}]+)\{/', $css, $m);
        expect(count($m[1]))->toBeGreaterThan(5);
        foreach ($m[1] as $prelude) {
            if (str_starts_with($prelude, '@media')) {
                continue;
            }
            foreach (explode(',', $prelude) as $sel) {
                expect(trim($sel))->toStartWith('.kbb-ssb.ssb-t-'.$key, $key.': '.$sel);
            }
        }
    }
    // The editor's picker: every template, constants only, never on a shop page.
    $ctx = SuperSaleBanner::editorContext(app(SettingsService::class)->all(), null);
    expect(array_column($ctx['spec']['templates'], 'key'))->toBe(array_keys(SaleTemplates::TEMPLATES))
        ->and($this->get('/super-sale/')->getContent())->not->toContain('css_templates')->not->toContain('Midnight Neon');
});
