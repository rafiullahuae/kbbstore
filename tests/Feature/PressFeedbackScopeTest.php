<?php

declare(strict_types=1);

/**
 * PRESS FEEDBACK, ON SMALL THINGS ONLY — Lane FP.
 *
 * The owner: "the click tap is giving some background color in area of click,
 * that we did for header and small icons things, but i don't want in mobile
 * menu, in search box and other big stuff. it's good only for small things like
 * icons etc."
 *
 * What the shop did (C · Ripple, Lane RD): resources/js/kbb/press.js marked
 * EVERY control -- any link, button, select, label and the search box -- so a
 * pink wave ran across a 390px-wide menu row, the 366px search box, every
 * 268px filter row, a 171px product card and Add to cart. Measured in Chromium
 * at 390 (docs/fp-shots, census in the hand-back): before, 15 of 17 controls on
 * /shop/ were marked; after, only the 44-46px icon buttons are.
 *
 * Shipped ON, as he asked (CLAUDE.md, 30 September), with "Every button and
 * row" one click away in Appearance → Site layout → Press feedback → Which
 * controls respond.
 */

use App\Models\AdminUser;
use App\Services\SettingsService;
use App\Services\SiteLayout;
use Illuminate\Support\Str;
use Tests\Support\SiteLayoutAdminRoutes;

function fpsAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Scope owner', 'email' => 'fps-'.Str::random(8).'@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);
}

function fpsSet(string $key, string $value): void
{
    app(SettingsService::class)->set('layout_'.$key, $value);
    SettingsService::forgetMemo();
}

/** press.js without comments. */
function fpsJs(): string
{
    return (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', (string) file_get_contents(resource_path('js/kbb/press.js')));
}

/** @return list<string> the selectors in press.js's SMALL list */
function fpsSmall(): array
{
    preg_match("/const SMALL = ((?:'[^']*'\s*\+?\s*)+);/", fpsJs(), $m);
    expect($m)->not->toBeEmpty('press.js has no SMALL list');
    preg_match_all("/'([^']*)'/", $m[1], $parts);

    return array_values(array_filter(array_map('trim', explode(',', implode('', $parts[1])))));
}

/** @return list<string> kbb.css's ICONS shape, read off D's icon rule, which names all of them */
function fpsIcons(): array
{
    $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/kbb/kbb.css')));
    preg_match('/html\[data-press="d"\] :is\(([^)]*)\)\{--kbb-sq:\.86\}/', $css, $m);
    expect($m)->not->toBeEmpty('kbb.css has no ICONS rule under D');

    return array_values(array_map('trim', explode(',', $m[1])));
}

beforeEach(function () {
    SiteLayoutAdminRoutes::wire($this->app);
    SettingsService::forgetMemo();
});

it('ships at small icons only, and the <html> tag is the bytes it was', function () {
    /*
     * `icons` adds nothing to the page: press.js reads the absence. So applying
     * the package changes no page's markup, only which elements the script
     * marks.
     *
     * MUTATION, RUN: default 'icons' -> 'all' -> red on the first line and on
     * the attribute.
     */
    expect(SiteLayout::SCHEMA['press_scope'][2])->toBe('icons')
        ->and(app(SiteLayout::class)->all()['press_scope'])->toBe('icons')
        ->and(app(SiteLayout::class)->pressAttribute())->toBe(' data-press="c"');

    $html = (string) $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, '<html lang="en" dir="ltr" data-press="c">'))->toBe(1)
        ->and($html)->not->toContain('data-press-all');
});

it('puts back every button and row with one constant attribute, and only while a style is on', function () {
    /*
     * MUTATION, RUN: drop the `$press !== 'off'` guard -> red on Off: the tag
     * would carry a token for a feature that is switched off.
     */
    fpsSet('press_scope', 'all');

    expect(app(SiteLayout::class)->pressAttribute())->toBe(' data-press="c" data-press-all');
    expect(substr_count((string) $this->get('/')->getContent(), '<html lang="en" dir="ltr" data-press="c" data-press-all>'))->toBe(1);

    fpsSet('press', 'off');

    expect(app(SiteLayout::class)->pressAttribute())->toBe('');
});

it('sits under the style on the Press feedback tab and stores only its own two options', function () {
    /*
     * Rule 3: Appearance → Site layout → Press feedback → "Which controls
     * respond". Rule 5: a select stores one of its own options or nothing.
     *
     * MUTATION, RUN: drop 'press_scope' from PRESS_KEYS -> red on the tab.
     */
    $this->actingAs(fpsAdmin(), 'admin');

    $press = collect($this->getJson('/admin-api/site-layout')->assertOk()->json('tabs'))->firstWhere('key', 'press');
    $field = collect($press['fields'])->firstWhere('key', 'press_scope');

    expect(collect($press['fields'])->pluck('key')->all())->toBe(['press', 'press_scope'])
        ->and($field['label'])->toBe('Which controls respond')
        ->and($field['value'])->toBe('icons')
        ->and(array_keys($field['options']))->toBe(['icons', 'all']);

    $this->postJson('/admin-api/site-layout', ['settings' => ['press_scope' => 'everything']])
        ->assertStatus(422)
        ->assertJson(['rejected' => ['press_scope']]);

    $this->postJson('/admin-api/site-layout', ['settings' => ['press_scope' => 'all']])->assertOk();
    SettingsService::forgetMemo();
    expect(app(SiteLayout::class)->all()['press_scope'])->toBe('all');

    // A row written behind the screen's back renders the shipped answer.
    fpsSet('press_scope', '" onload="x');
    expect(app(SiteLayout::class)->pressAttribute())->toBe(' data-press="c"');
});

it('marks only the ICON controls kbb.css styles as icons, unless the owner widened it', function () {
    /*
     * An ALLOWLIST: exactly the ICONS shape, so the two lists cannot drift and a
     * big block added tomorrow gets nothing rather than a wave nobody chose.
     *
     * MUTATION, RUN: put `.mm-it` in SMALL -> red twice (not in ICONS; a big
     * thing). MUTATION, RUN: `closest(CONTROL)` back in controlOf() -> red.
     */
    $small = fpsSmall();
    $icons = fpsIcons();

    sort($small);
    sort($icons);
    expect($small)->toBe($icons);

    foreach (['.mm-it', '.mrow', '.search-in', '.fopt', '.ftog', '.pchip', '.mobi-filter', '.kbb-card-cart',
        '.btn', '.addcart', '.navlink', '.brw-card', '.sortsel select', 'a[href]', 'button', 'select'] as $big) {
        expect(in_array($big, $small, true))->toBeFalse("{$big} is not a small icon");
    }

    $js = fpsJs();

    expect($js)->toContain("const reach = document.documentElement.hasAttribute('data-press-all') ? CONTROL : SMALL;")
        ->and($js)->toContain('target.closest(reach)')
        ->and($js)->not->toContain('closest(CONTROL)');

    // The guard still comes before any listener: Off adds none.
    expect(strpos($js, "if (!document.documentElement.hasAttribute('data-press')) return;"))
        ->toBeLessThan(strpos($js, 'addEventListener'));
});

it('is in the bundle the shop is served', function () {
    /*
     * MUTATION, RUN: edit press.js and skip `npx vite build` -> red.
     */
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $bundle = (string) file_get_contents(public_path('build/'.$manifest['resources/js/kbb/app.js']['file']));

    expect($bundle)->toContain('data-press-all')
        ->and($bundle)->toContain('.pts-auth-x, .colsel button, .trail');
});

it('shows the same thing on the admin samples: the icons respond, Add to cart and the pill do not', function () {
    /*
     * The "Try it" card reads the scope as it stands, checked against its own
     * options before it becomes an attribute.
     */
    $screen = (string) file_get_contents(resource_path('views/admin/partials/site-layout-screen.blade.php'));

    expect(substr_count($screen, "data-sls-scope=\"' + esc(sv) + '\""))->toBe(1)
        ->and(substr_count($screen, "if (!t.classList.contains('slp-i') && !t.closest('[data-sls-scope=\"all\"]')) return;"))->toBe(1);
});
