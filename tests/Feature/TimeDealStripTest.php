<?php

declare(strict_types=1);

/*
 * THE TIME DEAL STRIP (Lane TDS; the owner, 27 Nov 2026):
 *
 *   "i need strip on the top of the site, should not sticky. with Black Friday theme with count down from now till mid
 *    night 12. heading BLACK FRIDAY TIME DEAL sub heading: Last chance to get amazing deals button: Shop Now."
 *   ... design K "Holographic", then: "the sub line must come under main heading, and must not overlap in any screen
 *    size ... also give controls to manage and on / off."
 *
 * What each case guards on the shop: the strip shows above the header while its time runs, prints NOTHING when off or
 * over, never shows on the checkout, prints every word escaped, refuses a link that is not a shop path or https, reads
 * in Arabic on /ar with the Arabic page link, costs no query, and its console endpoint is timedeal.manage, failing
 * closed. Mutation notes are on the cases.
 */

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\TimeDealStrip;
use App\Support\AdminCapabilities;
use App\Support\Locale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function tdsFresh(): void
{
    SettingsService::forgetMemo();
    Setting::flushMap();
}

function tdsSet(array $v): void
{
    app(SettingsService::class)->set(TimeDealStrip::KEY, array_merge(app(TimeDealStrip::class)->all(), $v));
    tdsFresh();
}

function tdsAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'TDS ' . $role, 'email' => 'tds-' . $role . '-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => $role]);
}

afterEach(fn () => CarbonImmutable::setTestNow());

it('shows the strip above the header while its time runs, with his words, and nothing once it is off or over', function () {
    tdsSet(['mode' => 'until', 'ends_at' => '2030-01-01T00:00', 'on' => true]);
    $html = $this->get('/')->assertOk()->getContent();
    expect($html)->toContain('id="kbb-tds"')
        ->and($html)->toContain('BLACK FRIDAY TIME DEAL')
        ->and($html)->toContain('Last chance to get amazing deals')
        ->and($html)->toContain('>Shop Now</a>')
        ->and(strpos($html, 'id="kbb-tds"'))->toBeLessThan(strpos($html, '<header'));
    // the sub-line is INSIDE the heading block, under it (a column), never beside the clock
    expect($html)->toMatch('#<span class="kds-t"><span class="kds-h">[^<]+</span><span class="kds-s">#');

    // off: not one byte of it (mutation: drop the visible() guard in the layout and this is red)
    tdsSet(['on' => false]);
    $off = $this->get('/')->assertOk()->getContent();
    expect($off)->not->toContain('kbb-tds')->not->toContain('.kds{');

    // over: past its end it is gone too
    tdsSet(['on' => true, 'ends_at' => '2020-01-01T00:00']);
    expect($this->get('/')->getContent())->not->toContain('kbb-tds');

    // every day until midnight: always something to count
    tdsSet(['mode' => 'daily']);
    expect($this->get('/')->getContent())->toContain('data-daily="1"');
});

it('counts to midnight UAE time tonight as shipped, and stops at it', function () {
    tdsFresh();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-27 23:00', TimeDealStrip::ZONE));
    $s = app(TimeDealStrip::class);
    expect($s->visible())->toBeTrue()
        ->and($s->view()['left'])->toBe(3600)
        ->and($s->view()['end'])->toBe(CarbonImmutable::parse('2026-11-28 00:00', TimeDealStrip::ZONE)->getTimestamp() * 1000);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-28 00:00:01', TimeDealStrip::ZONE));
    expect(app(TimeDealStrip::class)->visible())->toBeFalse();
});

it('prints every word escaped and refuses a link that is not a shop page or https', function () {
    tdsSet(['mode' => 'daily', 'heading_en' => '<img src=x onerror=alert(1)>Deal']);
    $html = $this->get('/')->getContent();
    expect($html)->toContain('&lt;img src=x onerror=alert(1)&gt;Deal')->not->toContain('<img src=x onerror');

    $r = app(TimeDealStrip::class)->save(['link' => 'javascript:alert(1)']);
    expect($r['ok'])->toBeFalse()->and($r['rejected'])->toBe(['link']);
    expect(app(TimeDealStrip::class)->save(['link' => '//evil.example/x'])['ok'])->toBeFalse()
        ->and(app(TimeDealStrip::class)->save(['link' => 'http://evil.example'])['ok'])->toBeFalse()
        ->and(app(TimeDealStrip::class)->save(['link' => 'https://kbeautybliss.com/super-sale/'])['ok'])->toBeTrue()
        ->and(app(TimeDealStrip::class)->save(['mode' => 'forever'])['ok'])->toBeFalse()
        ->and(app(TimeDealStrip::class)->save(['ends_at' => '2026-13-40T99:99'])['ok'])->toBeFalse()
        ->and(app(TimeDealStrip::class)->save(['heading_en' => str_repeat('x', 81)])['ok'])->toBeFalse();
});

it('reads in Arabic on /ar with the Arabic page link', function () {
    app(SettingsService::class)->set(Locale::SETTING_ENABLED, true);
    tdsSet(['mode' => 'daily']);
    $html = $this->get('/ar/')->assertOk()->getContent();
    expect($html)->toContain('dir="rtl" data-end')
        ->and($html)->toContain('الجمعة البيضاء · عرض لفترة محدودة')
        ->and($html)->toContain('href="/ar/super-sale/"');
});

it('is never on the checkout', function () {
    tdsSet(['mode' => 'daily']);
    $view = file_get_contents(resource_path('views/layouts/store.blade.php'));
    $i = strpos($view, "@include('partials.time-deal-strip')");
    // inside the same @unless (bare) as the header: the checkout, which is bare, never draws it
    expect($i)->toBeGreaterThan(strpos($view, "@unless (View::hasSection('bare'))"))
        ->and($i)->toBeLessThan(strpos($view, '@endunless', $i))
        ->and(substr_count($view, "@include('partials.time-deal-strip')"))->toBe(1);
});

it('costs no query on a shop page: the settings map is already read', function () {
    $count = function (): int {
        $this->get('/');            // warm: the settings map is cached after a write
        tdsFresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/');
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    tdsSet(['on' => false]);
    $off = $count();
    tdsSet(['on' => true, 'mode' => 'daily']);
    $on = $count();
    expect($this->get('/')->getContent())->toContain('kbb-tds')
        ->and($on)->toBe($off);
});

it('saves from Appearance -> Time deal strip for timedeal.manage only, and the screen is wired exactly once', function () {
    $owner = tdsAdmin();
    $r = $this->actingAs($owner, 'admin')->getJson('/admin-api/time-deal-strip')->assertOk();
    expect($r->json('values.heading_en'))->toBe('BLACK FRIDAY TIME DEAL')->and($r->json('css'))->toBe(TimeDealStrip::CSS);

    $r = $this->actingAs($owner, 'admin')->postJson('/admin-api/time-deal-strip', ['settings' => ['on' => false, 'sub_en' => 'Ends at midnight']])->assertOk();
    expect($r->json('values.on'))->toBeFalse()->and($r->json('values.sub_en'))->toBe('Ends at midnight');
    $this->actingAs($owner, 'admin')->postJson('/admin-api/time-deal-strip', ['settings' => ['link' => 'javascript:x']])->assertStatus(422);

    expect(AdminCapabilities::forPath('POST', 'admin-api/time-deal-strip'))->toBe('timedeal.manage')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/time-deal-strip'))->toBe('timedeal.manage');
    $this->actingAs(tdsAdmin('support'), 'admin')->postJson('/admin-api/time-deal-strip', ['settings' => ['on' => true]])->assertForbidden();

    expect(substr_count(file_get_contents(base_path('routes/web.php')), "require __DIR__.'/time-deal-admin.php';"))->toBe(1)
        ->and(substr_count(file_get_contents(resource_path('views/admin/app.blade.php')), "@include('admin.partials.time-deal-screen')"))->toBe(1);
});

/*
 * (27 Nov) "the corner cross icon to hide / close this strip if user want, also in mobile the cross" and "in the desktop
 * i want little spacings". The cross ships on, closes the strip for the rest of that shopper's visit (sessionStorage,
 * keyed to this deal's end, so the next deal shows again), and Appearance -> Time deal strip -> Close button turns it
 * off. Mutation: drop the closable branch from the partial and the first expectation is red.
 */
it('carries a close cross that hides it for the visit, and the switch that takes the cross away', function () {
    tdsSet(['mode' => 'daily', 'closable' => true]);
    $html = $this->get('/')->getContent();
    expect($html)->toContain('<button type="button" class="kds-x" data-kds-x aria-label="Close">')
        ->and($html)->toContain("sessionStorage.setItem(k,'1')")
        ->and($html)->toContain("var k='kbbTds'+s.dataset.end");
    // more air on computers only; phones keep their spacing
    expect(TimeDealStrip::CSS)->toContain('@media (min-width:901px){.kds{gap:36px;min-height:56px');

    tdsSet(['closable' => false]);
    expect($this->get('/')->getContent())->toContain('id="kbb-tds"')->not->toContain('data-kds-x aria-label');
});
