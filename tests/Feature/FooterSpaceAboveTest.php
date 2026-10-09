<?php

declare(strict_types=1);

/**
 * "Space above the footer (every page)" — Appearance → Footer → Site footer ·
 * Desktop / Site footer · Mobile → Spacing. (Lane QK4)
 *
 * THE OWNER, 9 October, under a screenshot of a product page's bottom ("Recently
 * viewed · Continue shopping", then ~30px, then the footer's pink help strip):
 * "I need the space controls above footer globally".
 *
 * What decided that gap was each page's OWN last wrapper's bottom padding, from
 * its own stylesheet, so it differed by page — measured in Chromium at 390 and
 * 1280: product 34px, shop / category / brands / brand 60px, blog 56px, an
 * article 47px, home 46px, a policy page 69px, account / search / 404 0px (their
 * last section has a ground of its own). One margin on the footer could not make
 * that one number; the control takes the trailing padding out of each page
 * type's last unpainted wrapper and makes the footer's margin the gap.
 *
 * Auto ('') is what ships, and prints NOTHING, so every page keeps the space it
 * has now (StorefrontEnglishUnchangedTest is untouched). A number prints one
 * class and one property per device on the <footer>, in both designs.
 *
 * The defects these pin, as each would look on the shop:
 *   · the gap moving on every page the moment the package is applied, before
 *     he touched anything;
 *   · picking 24 px and the footer moving on the shop page but not on the
 *     product page (a page type left out of the tail list), or moving 24px
 *     FURTHER on top of its old 60px;
 *   · the phone gap following the laptop's number, or the other way round;
 *   · "Auto" impossible to get back once a number was saved;
 *   · a value written around the console reaching the style attribute.
 *
 * MUTATION NOTES, RUN:
 *   · ship `site_d_above` with default '24px' → RED (case 1).
 *   · drop the `--kft-sa-{$dev}` line from SiteFooter::presentation() → RED (case 2).
 *   · return (int) $m[1] unclamped from SiteFooter::above() and add a '200px'
 *     key to ABOVE → RED (case 3).
 *   · swap -d and -m in the phone media block → RED (case 4).
 *   · drop `#content>.pdp-page>:last-child` from the tail list → RED (case 4).
 *   · write `.shop` without `#content>` → RED (case 4: it would also strip the
 *     product cards' hover button).
 *   · footer-classic back to a bare `<footer>` → RED (case 5).
 *   · leave `site_{dev}_above` out of FooterPages' Spacing section → RED (case 6).
 */

use App\Models\AdminUser;
use App\Services\SettingsService;
use App\Services\SiteFooter;
use App\Support\FooterPages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function saSave(array $values): array
{
    $r = app(SiteFooter::class)->save($values);
    SettingsService::forgetMemo();

    return $r;
}

/** The <footer ...> opening tag of the page, whichever design draws it. */
function saTag(\Tests\TestCase $t, string $path = '/'): string
{
    return preg_match('#<footer(?: [^>]*)?>#', $t->get($path)->getContent(), $m) ? $m[0] : '';
}

/** The body of the first `@media (<query>){ … }` in kbb.css that holds $needle. */
function saMedia(string $query, string $needle): string
{
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $offset = 0;

    while (($at = strpos($css, '@media ('.$query.'){', $offset)) !== false) {
        $depth = 0;

        for ($i = strpos($css, '{', $at); $i < strlen($css); $i++) {
            $depth += $css[$i] === '{' ? 1 : ($css[$i] === '}' ? -1 : 0);

            if ($depth === 0) {
                break;
            }
        }

        $body = substr($css, $at, $i - $at);

        if (str_contains($body, $needle)) {
            return $body;
        }

        $offset = $i;
    }

    return '';
}

it('ships Auto on both devices and prints nothing, so no page moves until he picks a number', function () {
    expect(SiteFooter::SCHEMA['site_d_above']['default'])->toBe('')
        ->and(SiteFooter::SCHEMA['site_m_above']['default'])->toBe('');

    foreach (['/', '/shop/'] as $path) {
        $tag = saTag($this, $path);
        expect($tag)->toStartWith('<footer class="kft')
            ->not->toContain('kft-sa-')
            ->not->toContain('--kft-sa');
    }
});

it('prints the class and the exact gap for each device separately once a number is picked', function () {
    $r = saSave(['site_d_above' => '64px', 'site_m_above' => '24px']);
    expect($r['written'])->toBe(['site_d_above', 'site_m_above']);

    $tag = saTag($this);
    expect($tag)->toContain(' kft-sa-d')->toContain(' kft-sa-m')
        ->toContain(';--kft-sa-d:64px;--kft-sa-m:24px"');

    // One device only: the other keeps its page spacing.
    saSave(['site_d_above' => '', 'site_m_above' => '0px']);
    $tag = saTag($this);
    expect($tag)->not->toContain('kft-sa-d')->toContain(' kft-sa-m')->toContain('--kft-sa-m:0px"');

    // ... and Auto comes back: '' is one of the select's own keys, not a blank box.
    saSave(['site_m_above' => '']);
    expect(saTag($this))->not->toContain('kft-sa-');
});

it('stores only its own options and clamps what it prints, so nothing typed around the console reaches the style', function () {
    // Through the console: not one of the options, so the module's policy
    // (`invalid => default`) stores Auto, and Auto prints nothing.
    saSave(['site_d_above' => '999px', 'site_m_above' => '24px;color:red']);
    $c = app(SiteFooter::class)->all();
    expect($c['site_d_above'])->toBe('')->and($c['site_m_above'])->toBe('')
        ->and(saTag($this))->not->toContain('kft-sa-');

    // Written straight into `settings`, past save(): still Auto, still nothing printed.
    foreach (['999px', '-8px', '24px;}body{display:none', '24', ' 24px', '1e3px'] as $raw) {
        app(SettingsService::class)->set(SiteFooter::PREFIX.'site_d_above', $raw);
        SettingsService::forgetMemo();
        expect(saTag($this))->not->toContain('kft-sa-');
    }

    // The options run 0–160 in 4px steps, and above() clamps again where it prints.
    $keys = array_keys(SiteFooter::ABOVE);
    expect($keys[0])->toBe('')->and($keys[1])->toBe('0px')->and(end($keys))->toBe('160px')
        ->and(count($keys))->toBe(42)
        ->and(SiteFooter::above(['site_d_above' => '160px'], 'd'))->toBe(160)
        ->and(SiteFooter::above(['site_d_above' => '0px'], 'd'))->toBe(0)
        ->and(SiteFooter::above(['site_d_above' => '164px'], 'd'))->toBeNull()
        ->and(SiteFooter::above([], 'm'))->toBeNull();

    foreach ($keys as $k) {
        expect($k === '' || preg_match('/^\d{1,3}px$/', (string) $k) === 1)->toBeTrue();
    }
});

it('makes the footer margin the gap on every page type, laptop and phone each reading only its own number', function () {
    $tails = ['#content>.shop', '#content>.brw', '#content>.pdp-page>:last-child',
        '#content>.kbb-journal>.wrap:last-child', '#content>.kbb-journal>.wrap:last-child>.grid:last-child',
        '#content>.kbb-home>.sec:last-child:not([class*="hs-bg-"]:not(.hs-bg-none))',
        '#content>.kbb-home>.sec:last-child:not([class*="hs-bg-"]:not(.hs-bg-none))>.wrap',
        '#content>.kbb-home>.sec:last-child>.wrap>.policy:last-child'];

    foreach (['d' => 'min-width:901px', 'm' => 'max-width:900px'] as $dev => $query) {
        $other = $dev === 'd' ? 'm' : 'd';
        $block = saMedia($query, "body>footer.kft-sa-{$dev}{");

        expect($block)->toContain("body>footer.kft-sa-{$dev}{margin-top:var(--kft-sa-{$dev})}")
            ->toContain("body:has(>footer.kft-sa-{$dev}) :is(".implode(',', $tails).'){padding-bottom:0;margin-bottom:0}')
            ->not->toContain("kft-sa-{$other}");
    }

    // Anchored at #content>: a bare `.shop` is also the product cards' hover button.
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $rules = substr($css, (int) strpos($css, '/* ══ SPACE ABOVE THE FOOTER'), 3000);
    expect(preg_match('/[(,]\.shop[,)]/', $rules))->toBe(0);
});

it('moves the previous footer design too, which stays byte for byte as it was on Auto', function () {
    saSave(['site_design' => 'classic']);
    expect(saTag($this))->toBe('<footer>');

    saSave(['site_d_above' => '40px', 'site_m_above' => '16px']);
    expect(saTag($this))->toBe('<footer class="kft-sa-d kft-sa-m" style="--kft-sa-d:40px;--kft-sa-m:16px">');
});

it('draws the control first under Spacing on both device pages and saves it through the footer endpoint', function () {
    foreach (FooterPages::pages() as $page) {
        if ($page['footer'] !== 'site') {
            continue;
        }

        $spacing = collect($page['sections'])->firstWhere('title', 'Spacing');
        expect($spacing['keys'][0] ?? null)->toBe("site_{$page['device']}_above");
    }

    $owner = AdminUser::create(['name' => 'O', 'email' => 'qk4-'.Str::random(6).'@example.com', 'password' => bcrypt('x'), 'role' => 'owner']);
    $this->actingAs($owner, 'admin')->postJson('/admin-api/slim-footer', ['settings' => ['site_d_above' => '32px']])->assertOk();
    SettingsService::forgetMemo();
    expect(saTag($this))->toContain('--kft-sa-d:32px');

    $this->actingAs($owner, 'admin')->postJson('/admin-api/slim-footer', ['settings' => ['site_d_above' => '']])->assertOk();
    SettingsService::forgetMemo();
    expect(saTag($this))->not->toContain('kft-sa-');
});

it('costs a page no query: a picked number renders with the same count as Auto', function () {
    $count = function (): int {
        SettingsService::forgetMemo();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/shop/')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $count(); // warm
    $auto = $count();
    saSave(['site_d_above' => '64px', 'site_m_above' => '64px']);
    $count();
    expect($count())->toBe($auto);
});
