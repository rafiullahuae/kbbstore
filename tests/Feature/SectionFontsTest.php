<?php

declare(strict_types=1);

/**
 * Fonts & size on every homepage section, and the font library.   (Lane FS)
 *
 * The owner: "I want here another tab on every edit popup of content sections.
 * Fonts & Size. to control fonts and size etc, along with pading spacings. also
 * prepare a full list of fonts to include in our app ... but don't make heavy
 * the overal app, i need super light stuff, fully optimized without bugs."
 *
 * Each case says what the defect would look like on the shop, and carries a
 * mutation note: the change to the code that turns it red.
 */

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\HomepageHub;
use App\Services\HomepageSections;
use App\Services\SettingsService;
use App\Services\SiteLayout;
use App\Support\FontLibrary;
use App\Support\SectionType;
use App\Support\SiteFonts;
use App\Support\WebFonts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\HomepageHubRoutes;

beforeEach(function () {
    app(SettingsService::class)->set('demo_content', false);
    HomepageHubRoutes::wire(app());
});

function fsAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'FS', 'email' => 'fs-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

function fsFresh(): void
{
    SettingsService::forgetMemo();
    Setting::flushMap();
    Cache::flush();
}

function fsHome(): string
{
    fsFresh();

    return test()->get('/')->assertOk()->getContent();
}

/** The section-type <style>'s body, or null when the page has none. */
function fsStyle(string $html): ?string
{
    return preg_match('#<style id="kbb-sec-type">(.*?)</style>#s', $html, $m) === 1 ? $m[1] : null;
}

function fsPost(array $body, string $role = 'owner')
{
    test()->actingAs(fsAdmin($role), 'admin');

    return test()->postJson('/admin-api/homepage-hub/type', $body);
}

/* ═══ 1. EVERY POPUP HAS THE TAB ═══════════════════════════════════════════ */

it('gives every homepage section but the hero a Fonts & size tab', function () {
    /*
     * THE DEFECT: a section whose popup has no Fonts & size tab is the one the
     * owner asked about and cannot change — "on every edit popup".
     *
     * The hero is the one exception and it is not a popup: its Edit content
     * opens the Hero slider tab, where its slides are edited.
     *
     * MUTATION: return [] from SectionType::keys() for a NO_HEADING section, or
     * drop the `type_fields` line from HomepageHub::payload() → red.
     */
    test()->actingAs(fsAdmin(), 'admin');
    $sections = test()->getJson('/admin-api/homepage-hub')->assertOk()->json('sections');

    expect($sections)->not->toBeEmpty();

    foreach ($sections as $s) {
        if ($s['key'] === 'hero') {
            expect($s['type_fields'])->toBe([]);

            continue;
        }

        expect($s['type_fields'])->not->toBeEmpty("{$s['key']} has no Fonts & size controls");

        // Every px control opens at a number inside its own range, never ''.
        foreach ($s['type_fields'] as $f) {
            if ($f['type'] === 'px') {
                expect($f['options']['today'])->toBeGreaterThanOrEqual($f['options']['min'])
                    ->and($f['options']['today'])->toBeLessThanOrEqual($f['options']['max'])
                    ->and($f['value'])->toBe('');
            }
        }
    }

    /*
     * And the editor draws it: ONE tab definition, added by addRowTab() — the
     * function every editor kind (content, remote, grid, none, and the error
     * path) already calls — so no kind can open without it.
     */
    $hub = (string) file_get_contents(base_path('resources/views/admin/partials/homepage-hub.blade.php'));
    expect(substr_count($hub, "label: 'Fonts & size'"))->toBe(1)
        ->and($hub)->toContain("E.tabs.push({key: 'row', label: 'Show & frame', fields: fields, row: true});\n    addTypeTab();")
        ->and(substr_count($hub, "@include('admin.partials.font-picker')"))->toBe(1);
});

/* ═══ 2. NOTHING MOVES UNTIL HE MOVES IT ═══════════════════════════════════ */

it('leaves the homepage head byte-identical while nothing is moved', function () {
    /*
     * THE DEFECT: a package that adds twenty-nine fonts and changes the page
     * nobody has touched — a @font-face nobody chose, a second preload taking
     * bandwidth from the LCP image, a class on a section.
     *
     * An EMPTY saved map is the same page as no row at all, and the only font
     * the head names is Outfit, preloaded exactly as WebFonts always did.
     *
     * MUTATION: make SectionType::style() print an empty <style> for an empty
     * map, or let SiteFonts::preloadTags() emit the library tag for Outfit →
     * red.
     */
    $none = fsHome();

    app(SettingsService::class)->set(SectionType::SETTING, []);
    $empty = fsHome();

    $strip = fn (string $h) => preg_replace('/(name="_token" value|"csrf":|csrf-token" content)="?[A-Za-z0-9]+"?/', '', $h);
    expect($strip($empty))->toBe($strip($none));

    expect($none)->not->toContain('kbb-ty-')
        ->and($none)->not->toContain('kbb-sec-type')
        ->and($none)->not->toContain('kbb-site-fonts')
        ->and($none)->not->toContain('resources/fonts/lib')
        ->and(substr_count($none, 'rel="preload" as="font"'))->toBe(1)
        ->and($none)->toContain(trim(WebFonts::preloadTags(WebFonts::OUTFIT)));

    foreach (FontLibrary::FAMILIES as $key => [$family]) {
        if ($key !== 'outfit') {
            expect($none)->not->toContain("font-family:'{$family}'");
        }
    }
});

/* ═══ 3. ONE MOVE, ONE RULE ════════════════════════════════════════════════ */

it('prints exactly the one rule for one moved heading size, and the class on that section only', function () {
    /*
     * THE DEFECT: moving Best Sellers' heading moves every heading, or prints a
     * block of rules for every control at its default.
     *
     * MUTATION: drop the `isset($v[$k])` guard in SectionType::style() → a rule
     * per control, red on the exact string.
     */
    fsPost(['key' => 'bestselling', 'values' => ['h_d' => 44]])->assertOk()->assertJson(['ok' => true, 'values' => ['h_d' => 44]]);

    $html = fsHome();

    expect(fsStyle($html))->toBe('@media (min-width:901px){.kbb-ty-bestselling.kbb-ty-bestselling.kbb-ty-bestselling h2{font-size:44px}}')
        ->and(substr_count($html, 'kbb-ty-'))->toBe(1 + 3)
        ->and($html)->toMatch('#<section class="sec hs hs-rail hs-bestselling[^"]* kbb-ty-bestselling"#');
});

it('adds exactly the chosen family\'s @font-face and no preload for a section font', function () {
    /*
     * THE DEFECT: choosing one heading font loads the library — or preloads a
     * heading face, taking critical-path bandwidth from the LCP image.
     *
     * MUTATION: print every FAMILIES entry's faceCss() in SectionType::style(),
     * or add a preloadTag() for section fonts → red.
     */
    fsPost(['key' => 'trending', 'values' => ['font' => 'playfair-display', 'case' => 'upper']])->assertOk();

    $html = fsHome();
    $css = (string) fsStyle($html);

    expect(substr_count($css, '@font-face'))->toBe(1)
        ->and($css)->toContain("@font-face{font-family:'Playfair Display';font-style:normal;font-weight:400 900;font-display:swap;src:url(")
        ->and($css)->toContain(".kbb-ty-trending.kbb-ty-trending.kbb-ty-trending h2{font-family:'Playfair Display',Georgia,'Times New Roman',serif}")
        ->and($css)->toContain('h2{text-transform:uppercase}')
        ->and(substr_count($html, '@font-face'))->toBe(substr_count(fsHomeBaseline(), '@font-face') + 1)
        ->and(substr_count($html, 'rel="preload" as="font"'))->toBe(1);

    // Unused families are never printed.
    foreach (FontLibrary::FAMILIES as $key => [$family]) {
        if (! in_array($key, ['outfit', 'playfair-display'], true)) {
            expect($html)->not->toContain("font-family:'{$family}'");
        }
    }
});

function fsHomeBaseline(): string
{
    $saved = app(SettingsService::class)->all()[SectionType::SETTING] ?? [];
    app(SettingsService::class)->set(SectionType::SETTING, []);
    $html = fsHome();
    app(SettingsService::class)->set(SectionType::SETTING, $saved);
    fsFresh();

    return $html;
}

it('prints one @font-face for a family two sections share, and none for one the site already prints', function () {
    /*
     * MUTATION: drop the `$already` check, or the de-duplication in fonts() →
     * the same @font-face twice, red on the count.
     */
    fsPost(['key' => 'trending', 'values' => ['font' => 'lora']])->assertOk();
    fsPost(['key' => 'blog', 'values' => ['font' => 'lora']])->assertOk();

    expect(substr_count((string) fsStyle(fsHome()), "@font-face{font-family:'Lora'"))->toBe(1);

    app(SiteLayout::class)->save(['font_heading' => 'lora']);
    $html = fsHome();

    expect(substr_count($html, "@font-face{font-family:'Lora'"))->toBe(1)
        ->and((string) fsStyle($html))->not->toContain('@font-face');
});

it('prints Cairo for a section on an English page and never a second copy on an Arabic one', function () {
    /*
     * THE DEFECT: Cairo chosen for a heading prints its twelve rules again on
     * /ar/, where the layout already printed them — or not at all on the
     * English page, where nothing else does.
     *
     * MUTATION: drop the `$arabicPage` argument in FontLibrary::faceCss() → the
     * Arabic count goes from 12 to 24, red.
     */
    fsPost(['key' => 'trending', 'values' => ['font' => 'cairo']])->assertOk();

    $en = fsHome();
    expect(substr_count((string) fsStyle($en), "@font-face{font-family:'Cairo'"))->toBe(12);

    Setting::query()->updateOrCreate(['key' => \App\Support\Locale::SETTING_ENABLED], ['value' => '1']);
    fsFresh();
    $ar = test()->get('/ar/')->assertOk()->getContent();

    expect(substr_count($ar, "@font-face{font-family:'Cairo'"))->toBe(12)
        ->and((string) fsStyle($ar))->not->toContain('@font-face')
        ->and((string) fsStyle($ar))->toContain(".kbb-ty-trending.kbb-ty-trending.kbb-ty-trending h2{font-family:'Cairo',");
});

/* ═══ 4. REFUSED, NOT CLAMPED ══════════════════════════════════════════════ */

it('refuses a bogus font, size, control or section and stores nothing of that post', function () {
    /*
     * THE DEFECT: a 900px heading on a phone, a font name typed into the
     * request that ends up in a stylesheet, or half a post saved.
     *
     * MUTATION: clamp instead of refusing in SectionType::clean(), or skip
     * FontLibrary::exists() there → the 422s turn 200.
     */
    $bad = [
        ['key' => 'bestselling', 'values' => ['font' => 'comic-sans']],
        ['key' => 'bestselling', 'values' => ['font' => "'};body{display:none}"]],
        ['key' => 'bestselling', 'values' => ['h_d' => 900]],
        ['key' => 'bestselling', 'values' => ['h_d' => '44px']],
        ['key' => 'bestselling', 'values' => ['h_d' => '44;color:red']],
        ['key' => 'bestselling', 'values' => ['case' => 'shout']],
        ['key' => 'bestselling', 'values' => ['pt_d' => 40]],      // owned: home_bs_pt_d is its spacing
        ['key' => 'bestselling', 'values' => ['h_d' => 40, 'font' => 'nope']],
        ['key' => 'hero', 'values' => ['h_d' => 40]],
        ['key' => 'nonsense', 'values' => ['h_d' => 40]],
        ['key' => '../x', 'values' => ['h_d' => 40]],
    ];

    foreach ($bad as $body) {
        fsPost($body)->assertStatus(422);
    }

    fsFresh();
    expect(app(SettingsService::class)->all()[SectionType::SETTING] ?? [])->toBe([]);
});

it('drops a hand-edited value on the way out, so no stored string reaches the page', function () {
    /*
     * THE DEFECT: the settings row edited by hand (or by an old build) puts a
     * word into the stylesheet. Rule 5: anything printed unescaped is a
     * constant, never a setting.
     *
     * MUTATION: make readOne() copy values without checking → red.
     */
    app(SettingsService::class)->set(SectionType::SETTING, [
        'bestselling' => ['font' => '</style><script>alert(1)</script>', 'h_d' => '44;color:red', 'case' => 'x', 'zz' => 1],
        'hero' => ['h_d' => 40],
        '"><b>' => ['h_d' => 40],
    ]);

    $html = fsHome();

    expect(fsStyle($html))->toBeNull()
        ->and($html)->not->toContain('alert(1)')
        ->and($html)->not->toContain('kbb-ty-');
});

it('lets a role without homepagehub.type read the tab but not write it', function () {
    /*
     * MUTATION: drop the ['POST', 'admin-api/homepage-hub/type', …] row from
     * AdminCapabilities — the path falls to owner-only, so the editor (who may)
     * goes red; give the capability to `support` and the refusal goes red.
     */
    fsPost(['key' => 'bestselling', 'values' => ['h_d' => 40]], 'support')->assertForbidden();
    fsPost(['key' => 'bestselling', 'values' => ['h_d' => 40]], 'editor')->assertOk();
});

/* ═══ 5. A SECTION'S OWN SPACING IS MOVED, NOT DUPLICATED ══════════════════ */

it('moves a section\'s own spacing keys into the tab and never offers a second copy', function () {
    /*
     * THE DEFECT: two "Space above" sliders on Best Sellers, one of which does
     * nothing — or the old one gone and the page's spacing frozen.
     *
     * MUTATION: delete a row of SectionType::OWNED → `pt_d` reappears, red; or
     * put `pt_[dm]` back in HomepageHub::group()'s style pattern → red on group.
     */
    expect(HomepageHub::group('home_bs_pt_d'))->toBe('type')
        ->and(HomepageHub::group('home_hb_head_gap_m'))->toBe('type')
        ->and(HomepageHub::group('home_ts_size'))->toBe('type')
        ->and(HomepageHub::group('home_bs_bg'))->toBe('style');

    foreach (SectionType::OWNED as $key => $owned) {
        foreach ($owned as $s) {
            expect(SectionType::keys($key))->not->toContain($s.'_d')->not->toContain($s.'_m');
        }
    }

    test()->actingAs(fsAdmin(), 'admin');
    $all = collect(test()->getJson('/admin-api/homepage-hub')->json('sections'));

    // Read from the OTHER side too, so deleting an OWNED row is caught: every
    // content section whose own fields carry a space-above / below / under-
    // heading key is offered no SectionType copy of it.
    foreach ($all as $sec) {
        $own = collect($sec['fields'] ?? [])->where('group', 'type')->pluck('key')->implode(' ');
        $ty = collect($sec['type_fields'])->pluck('key')->all();

        if (preg_match('/_(pt|pad)_d\b/', $own) === 1) {
            expect(in_array('pt_d', $ty, true))->toBeFalse("{$sec['key']}: two space-above controls");
        }

        if (preg_match('/_pb_d\b/', $own) === 1) {
            expect(in_array('pb_d', $ty, true))->toBeFalse("{$sec['key']}: two space-below controls");
        }

        if (preg_match('/_(hg|head_gap)_d\b/', $own) === 1) {
            expect(in_array('gap_d', $ty, true))->toBeFalse("{$sec['key']}: two under-the-heading controls");
        }
    }

    // Spotted's are on its own screen's tab (the hub moves them by key).
    expect(\App\Services\SpottedSettings::SCHEMA)->toHaveKeys(['pad_top_d', 'pad_bot_d', 'head_gap_d'])
        ->and(SectionType::keys('spotted'))->not->toContain('pt_d')->not->toContain('pb_d')->not->toContain('gap_d');

    $bs = $all->firstWhere('key', 'bestselling');
    $own = collect($bs['fields'])->where('group', 'type')->pluck('key')->all();

    expect($own)->toContain('home_bs_pt_d', 'home_bs_pb_m', 'home_bs_hg_d')
        ->and(collect($bs['type_fields'])->pluck('key')->all())->not->toContain('pt_d');

    // …and it still works where it always did: its own endpoint, its own variable.
    test()->postJson('/admin-api/homepage/content', ['copy' => ['home_bs_pt_d' => '64']])->assertOk();
    expect(fsHome())->toContain('--hs-pt-d:64px');
});

/* ═══ 6. LIGHT ═════════════════════════════════════════════════════════════ */

it('costs the homepage no query, moved or not', function () {
    /*
     * THE DEFECT: the map read through SettingsService::get(), which on a shop
     * that never saved it misses and snapshots the whole settings table.
     *
     * MUTATION: read SectionType::SETTING with ->get() in HomepageSections::
     * typeMap() on a fresh install → one query more on the first run.
     */
    $count = function (): int {
        fsFresh();
        test()->get('/')->assertOk();   // warm the caches the shop warms
        SettingsService::forgetMemo();
        Setting::flushMap();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get('/')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $before = $count();

    app(SettingsService::class)->set(SectionType::SETTING, [
        'bestselling' => ['font' => 'inter', 'h_d' => 40],
        'trending' => ['font' => 'lora', 'body_m' => 13],
        'recommended' => ['pt_d' => 40, 'gap_m' => 8],
    ]);

    expect($count())->toBe($before);
});

/* ═══ 7. THE SITE'S OWN FONTS ═════════════════════════════════════════════ */

it('changes the body font with one preload of its own and the heading font with none', function () {
    /*
     * THE DEFECT: a body font chosen and the page still preloads Outfit — a
     * download nobody uses, on the critical path — or a heading font that
     * preloads too.
     *
     * MUTATION: leave the layout's preload line on WebFonts::preloadTags(OUTFIT)
     * → the Inter preload is missing, red.
     */
    expect(SiteFonts::css())->toBe('')
        ->and(SiteFonts::preloadTags())->toBe(WebFonts::preloadTags(WebFonts::OUTFIT));

    app(SiteLayout::class)->save(['font_heading' => 'cormorant-garamond']);
    $html = fsHome();
    expect(substr_count($html, 'rel="preload" as="font"'))->toBe(1)
        ->and($html)->toContain(trim(WebFonts::preloadTags(WebFonts::OUTFIT)))
        ->and($html)->toContain("h1,h2,h3{font-family:'Cormorant Garamond'");

    app(SiteLayout::class)->save(['font_body' => 'inter']);
    $html = fsHome();
    preg_match_all('#<link rel="preload" as="font"[^>]*href="([^"]+)"#', $html, $m);

    expect($m[1])->toHaveCount(1)
        ->and($m[1][0])->toContain('inter-latin')
        ->and($html)->toContain(":root,.kbb-checkout{--sans:'Inter',")
        ->and(substr_count($html, "@font-face{font-family:'Inter'"))->toBe(1);

    // A family outside the library is refused by the cast, not stored.
    $r = app(SiteLayout::class)->save(['font_body' => 'comic-sans']);
    expect($r['rejected'])->toHaveKey('font_body');
});

/* ═══ 8. THE LIBRARY ITSELF ═══════════════════════════════════════════════ */

it('carries twenty-nine OFL families, every file on disk, built, and counted honestly', function () {
    /*
     * THE DEFECT: a family in the picker whose file was never committed or
     * never built — Vite::asset() would throw and the homepage would 500 the
     * moment the owner chose it.
     *
     * MUTATION: delete one woff2 under resources/fonts/lib/, or change a byte
     * count in FontLibrary::FAMILIES → red.
     */
    expect(FontLibrary::FAMILIES)->toHaveCount(29)
        ->and(array_keys(FontLibrary::LABELS))->toEqualCanonicalizing(array_keys(FontLibrary::FAMILIES))
        ->and(FontLibrary::exists(FontLibrary::DEFAULT))->toBeTrue();

    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    foreach (FontLibrary::FAMILIES as $key => [$family, $kind, $licence, $bytes, $faces]) {
        expect($licence)->toBe('OFL-1.1')
            ->and(FontLibrary::KINDS)->toHaveKey($kind);

        $sum = 0;

        foreach ($faces as [$subset, , $file]) {
            $src = FontLibrary::DIR.$file;
            expect(file_exists(base_path($src)))->toBeTrue("missing {$src}")
                ->and(FontLibrary::RANGES)->toHaveKey($subset)
                ->and(isset($manifest[$src]['file']))->toBeTrue("not built: {$src}")
                ->and(file_exists(public_path('build/'.$manifest[$src]['file'])))->toBeTrue("built file missing: {$src}");
            $sum += filesize(base_path($src));
        }

        if ($faces !== []) {
            expect($sum)->toBe($bytes, "{$key}: byte count");
        }
    }

    // Nothing in resources/fonts/lib that the table does not name.
    $disk = collect(\Illuminate\Support\Facades\File::allFiles(base_path(FontLibrary::DIR)))
        ->map(fn ($f) => FontLibrary::DIR.str_replace('\\', '/', $f->getRelativePathname()))->sort()->values()->all();
    expect($disk)->toBe(collect(FontLibrary::sources())->sort()->values()->all());
});

it('keeps the font picker admin-only and loading one kind at a time', function () {
    /*
     * THE DEFECT: the shop shipping the catalogue, or the picker downloading
     * all twenty-nine families the moment it opens (~1.3 MB).
     *
     * MUTATION: draw every CAT row in shown() regardless of kind → red on the
     * kind filter; include the partial from layouts/store → red.
     */
    $picker = (string) file_get_contents(base_path('resources/views/admin/partials/font-picker.blade.php'));

    expect($picker)->toContain('return term ? (c.label + \' \' + c.family).toLowerCase().indexOf(term) >= 0 : c.kind === kind;')
        ->and($picker)->toContain('rows.slice(0, 10)')
        ->and($picker)->not->toContain('getBoundingClientRect')
        ->and($picker)->not->toContain('IntersectionObserver')
        ->and($picker)->not->toContain('setInterval');

    expect(fsHome())->not->toContain('kbbFontPicker');
});
