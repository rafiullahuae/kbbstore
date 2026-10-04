<?php

declare(strict_types=1);

/**
 * ONE HEADING SIZE AND ONE DESCRIPTION SIZE, PER DEVICE, FOR EVERY HOMEPAGE
 * SECTION — and the "8 sets" badge gone.                          (Lane PF)
 *
 * The owner, 4 October 2026, over a screenshot: he crossed out "8 sets" beside
 * "Big savings bundles", circled the bundles' description and ticked the Best
 * Sellers heading and description — "i think the sections headings and
 * descriptions should have the same font size. in desktop and mobile both".
 *
 * Measured on 2.60.372 before this lane (tools/pf-shoot.cjs): at 1280 the
 * bundles and Spotted headings were 30px and the other six 33.28px, the
 * descriptions 13.5px, 14.5px and 14.72px; at 390 the headings were 18px and
 * 23.4px, and the bundles' and Spotted's descriptions were display:none while
 * the rest were 13.5px. After: 34px / 16px at 1280 and 24px / 16px at 390,
 * on all eight.
 *
 * Every case says what the defect looked like on the shop and how to turn it
 * red. Mutation notes marked RUN were made and seen red.
 */

use App\Models\SpottedPost;
use App\Services\HomepageContent;
use App\Services\ModuleSchema;
use App\Services\SettingsService;
use App\Services\SpottedSettings;
use App\Support\HomeHeadings;
use Illuminate\Support\Facades\Cache;
use Tests\Support\HomepageContentAdminRoutes;

beforeEach(function () {
    app(SettingsService::class)->set('demo_content', false);
});

function pfWrite(array $values): void
{
    ModuleSchema::write(app(SettingsService::class), 'homepage_content', HomepageContent::SCHEMA, $values);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
}

function pfHome(): string
{
    SettingsService::forgetMemo();
    SpottedSettings::flush();
    Cache::flush();

    return test()->get('/')->assertOk()->getContent();
}

/** kbb.css without comments, so a selector quoted in prose is not a rule. */
function pfSheet(): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/kbb/kbb.css')));
}

/**
 * Every innermost rule in the sheet: [selector, declarations, offset]. A rule
 * inside @media is found too — its selector is the text after the media's own
 * opening brace.
 *
 * @return list<array{0: string, 1: string, 2: int}>
 */
function pfRules(string $css): array
{
    preg_match_all('#([^{}]+)\{([^{}]*)\}#', $css, $m, PREG_OFFSET_CAPTURE);
    $out = [];

    foreach ($m[1] as $i => [$sel, $at]) {
        $out[] = [trim((string) preg_replace('/\s+/', ' ', $sel)), $m[2][$i][0], $at];
    }

    return $out;
}

/** A homepage with every section head drawn: the rails, brands, blog and a Spotted post. */
function pfFullHome(): string
{
    for ($i = 1; $i <= 6; $i++) {
        \App\Models\Product::create(['slug' => 'pf-p'.$i, 'name' => 'Pf product '.$i, 'type' => 'simple', 'status' => 'publish',
            'is_visible' => true, 'stock_status' => 'instock', 'price' => 3000 + $i * 100, 'total_sales' => 9000 - $i,
            'brand_id' => \App\Models\Brand::firstOrCreate(['slug' => 'pf-brand'], ['name' => 'Pf Brand'])->id]);
    }

    \App\Models\Post::create(['slug' => 'pf-post', 'title' => 'Pf post', 'body' => '<p>x</p>', 'status' => 'published', 'published_at' => now()->subDay()]);
    SpottedPost::create(['image' => '/uploads/spotted/pf.jpg', 'ig_url' => 'https://www.instagram.com/p/Pf1/', 'handle' => 'pf.glows',
        'caption' => 'Pf', 'sort' => 1, 'on_home' => true, 'on_page' => true]);

    return pfHome();
}

/* ═══ 1. ONE SIZE: every section heading is sized by the same variable ════ */

it('sizes every homepage section heading and description from one variable per device, and nothing else', function () {
    /*
     * THE DEFECT, on the shop: "Big savings bundles" at 30px beside
     * "Best-Selling Korean Skincare in the UAE" at 33.28px on a laptop, and
     * 18px beside 23.4px on a phone — because the bundles and Spotted heads are
     * `.sh` and took `.kbb-home .sh :is(h1,h2)`'s size while the row-55
     * sections carried a clamp() of their own. Two rules, two sizes.
     *
     * So: inside the sheet there is exactly ONE font-size per device for a
     * homepage section heading and ONE for its description, each a var() of
     * the four properties; and no rule naming a homepage section head
     * (.hs-head / .bndl-head / the homepage .spt-head) sets a font-size on its
     * h2 or p any other way.
     *
     * MUTATION (RUN): put `font-size:clamp(23px,2.6vw,34px)` back on
     * `.kbb-home .hs-head h2` → red on the stray rule. Change the phone rule's
     * var(--hs-h2-m) to 18px → red on the count of var() rules.
     */
    $rules = pfRules(pfSheet());
    $heads = '(?:\.hs-head|\.bndl-head|\.spt-head)';
    $unified = [];

    foreach ($rules as [$sel, $decl]) {
        if (! preg_match('/font-size\s*:\s*([^;]+)/', $decl, $fs)) {
            continue;
        }

        if (preg_match('/'.$heads.'[^,{]*\b(?:h2|p)\b|'.$heads.'\)\s*(?:h2|p)\b/', $sel) !== 1) {
            continue;
        }

        // The Spotted PAGE is not a homepage section: its own intro size is allowed.
        if (str_contains($sel, '.spt-page-sec') && ! str_contains($sel, ':not(.spt-page-sec)')) {
            continue;
        }

        expect(trim($fs[1]))->toBeIn(['var(--hs-h2-d)', 'var(--hs-h2-m)', 'var(--hs-sub-d)', 'var(--hs-sub-m)'], 'a section-specific size is left on: '.$sel.'{'.$decl.'}');
        $unified[] = trim($fs[1]);
    }

    sort($unified);
    expect($unified)->toBe(['var(--hs-h2-d)', 'var(--hs-h2-m)', 'var(--hs-sub-d)', 'var(--hs-sub-m)']);
});

it('wins over the generic `.sh` heading size by coming after it, not by luck', function () {
    /*
     * THE DEFECT: the unified rule written ABOVE `.kbb-home .sh :is(h1,h2)
     * {font-size:18px}` (the phone block at the top of the sheet). Both are
     * one class plus `.kbb-home` plus an element, so the later one wins — and
     * the bundles and Spotted headings would have gone back to 18px on a phone
     * with every rule above still "present". The generic rule cannot simply
     * go: the collection pages, the wishlist and the Spotted page share `.sh`.
     *
     * MUTATION: move the "SECTION HEADINGS: ONE SIZE PER DEVICE" block above
     * the phone `.sh` block → red.
     */
    $css = pfSheet();
    $generic = strrpos($css, '.kbb-home .sh :is(h1,h2){font-size:18px}');
    $unified = strpos($css, '.kbb-home :is(.hs-head,.bndl-head,.spt-head) h2{font-size:var(--hs-h2-m)}');

    expect($generic)->not->toBeFalse()
        ->and($unified)->not->toBeFalse()
        ->and($unified)->toBeGreaterThan($generic);

    // …and the description rule out-specifies `.kbb-home .sh p{display:none}`
    // and `.kbb-home .spt .spt-head p` (both before it), and SHOWS the line on
    // a phone: the bundles' and Spotted's descriptions were display:none under
    // 900px, which made "the same size on mobile" the size of nothing.
    // MUTATION (RUN): drop `display:block` → red.
    expect($css)->toContain('.kbb-home :is(.hs-head,.bndl-head,.spt:not(.spt-page-sec) .spt-head) p{display:block;line-height:1.55}');
});

it('puts every section heading on the page under one of the three heads the rule sizes', function () {
    /*
     * THE DEFECT the CSS check cannot see: a section that draws its <h2>
     * outside `.hs-head` / `.bndl-head` / `.spt-head` — a new section with a
     * bare <h2>, say — is sized by nobody and falls back to whatever the page
     * gives an h2. Read on the RENDERED page, with every head drawn.
     *
     * The two-column feature's panel titles (`.hs-fp`) are CARD titles, two
     * side by side, and are deliberately not section headings.
     *
     * MUTATION (RUN): in partials/home/hs-head.blade.php rename `hs-head` → red.
     */
    $page = pfFullHome();

    // From the first section under the banner to the end of About us, which
    // is last on the page: the hero's slide headline is the BANNER (and the
    // page's h1 when it draws), and the footer is the site's, not a section.
    $from = (int) strpos($page, '<section class="sec bndl');
    $to = (int) strpos($page, '</div></div></section>', (int) strpos($page, 'hs-about'));
    expect($from)->toBeGreaterThan(0)->and($to)->toBeGreaterThan($from);
    $html = substr($page, $from, $to - $from);

    preg_match_all('#<h2\b[^>]*>(.*?)</h2>#s', $html, $all, PREG_OFFSET_CAPTURE);
    $section = [];

    foreach ($all[0] as $i => [$tag, $at]) {
        $before = substr($html, max(0, $at - 400), min(400, $at));
        $text = html_entity_decode(trim(strip_tags($all[1][$i][0])), ENT_QUOTES | ENT_HTML5);

        if (preg_match('#<div class="hs-fp">(?:(?!</div>).)*$#s', $before) === 1) {
            continue; // a feature panel's card title
        }

        $open = max((int) strrpos($before, 'class="hs-head'), (int) strrpos($before, 'class="sh bndl-head'), (int) strrpos($before, 'class="sh spt-head'));
        expect($open)->toBeGreaterThan(0, 'an unsized section heading: '.$text);
        $section[] = $text;
    }

    expect($section)->toBe([
        'Big savings bundles', 'Best-Selling Korean Skincare in the UAE', 'Shop Top Korean Beauty Brands',
        // (Lane HS) The Spotted section ships as the owner's static grid,
        // under his own heading from docs/hs-owner-spotted.png.
        '#KBEAUTYBLISS Spotted', 'Trending K-Beauty This Week', 'Korean Skincare Tips & Guides',
        'K-Beauty Under AED 54', 'About K-Beauty Bliss UAE',
    ]);
});

/* ═══ 2. THE DEFAULTS, AND THE ONE PLACE THAT CHANGES THEM ════════════════ */

it('ships the Best Sellers style he ticked, 16px descriptions on a phone, and prints nothing until a size moves', function () {
    /*
     * THE DEFECT: two sources of truth that drift — kbb.css saying 34px while
     * the admin's default says 32px would draw 34 and show 32 as "current".
     * And a phone description under 16px, which is the brief's floor.
     *
     * MUTATION (RUN): change '--hs-h2-d:34px' in kbb.css to 32px → red. Add a
     * '15' option to home_hd_sub_m → red.
     */
    expect(pfSheet())->toContain('.kbb-home{--hs-h2-d:34px;--hs-h2-m:24px;--hs-sub-d:16px;--hs-sub-m:16px}');

    foreach (HomeHeadings::DEFAULTS as $key => $v) {
        expect(HomeHeadings::SCHEMA[$key]['default'])->toBe($v)
            ->and(HomeHeadings::SCHEMA[$key]['options'])->toHaveKey($v);
    }

    foreach (array_keys(HomeHeadings::SCHEMA['home_hd_sub_m']['options']) as $px) {
        expect((int) $px)->toBeGreaterThanOrEqual(16);
    }

    // Nothing printed at the defaults: kbb.css already says them, and a shop
    // that never opens the tab gets no new byte (StorefrontEnglishUnchangedTest
    // is the wider instrument).
    expect(HomeHeadings::style([]))->toBe('')
        ->and(pfHome())->not->toContain('--hs-h2-d');
});

it('prints a moved size as one rule from the select\'s own option, and nothing a setting typed', function () {
    /*
     * THE DEFECT this guards: a stored value reaching a <style> as text —
     * `16px}body{display:none` would blank the shop. A select stores one of
     * its own options or the default, and style() checks again on the way out.
     *
     * MUTATION (RUN): in HomeHeadings::values() return $v without the
     * array_key_exists() check → the raw row below reaches the page, red.
     */
    pfWrite(['home_hd_h2_d' => '30', 'home_hd_sub_m' => '18']);
    $html = pfHome();

    expect($html)->toContain('<style>.kbb-home{--hs-h2-d:30px;--hs-h2-m:24px;--hs-sub-d:16px;--hs-sub-m:18px}</style>');

    // A select refuses what is not one of its options on the way in …
    pfWrite(['home_hd_h2_m' => '99', 'home_hd_sub_d' => '16px}body{display:none']);
    expect(HomeHeadings::values(\App\Support\HomeSections::settings()))->toMatchArray(['home_hd_h2_m' => '24', 'home_hd_sub_d' => '16']);

    // … and a row written behind its back (a restore, phpMyAdmin) is refused
    // on the way out, by values() itself and again by style()'s integer cast.
    expect(HomeHeadings::values(['home_hd_sub_d' => '16px}body{display:none', 'home_hd_h2_m' => '99']))
        ->toMatchArray(['home_hd_sub_d' => '16', 'home_hd_h2_m' => '24']);
    expect(HomeHeadings::style(['home_hd_sub_d' => '16px}body{display:none', 'home_hd_h2_d' => '30']))
        ->toBe('<style>.kbb-home{--hs-h2-d:30px;--hs-h2-m:24px;--hs-sub-d:16px;--hs-sub-m:16px}</style>');
});

it('puts the four sizes on Appearance → Homepage content → Section headings, behind the homepage capability', function () {
    /*
     * THE DEFECT: a control nobody can find. CLAUDE.md rule 3 — the owner is
     * told the exact path, so the path has to be the one the screen draws.
     *
     * MUTATION (RUN): drop `...HomeHeadings::TABS` from HomepageContent::TABS → red
     * (the tab is missing from the payload).
     */
    HomepageContentAdminRoutes::wire(app());
    test()->actingAs(\App\Models\AdminUser::create(['name' => 'O', 'email' => 'pf-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'owner']), 'admin');

    $json = $this->getJson('/admin-api/homepage/content')->assertOk()->json();
    $tabs = array_column($json['tabs'], null, 'key');

    expect($tabs)->toHaveKey('headings')
        ->and($tabs['headings']['label'])->toBe('Section headings')
        ->and(HomepageContent::TABS['headings'][2])->toBe(['home_hd_h2_d', 'home_hd_h2_m', 'home_hd_sub_d', 'home_hd_sub_m'])
        ->and(HomepageContent::TABS['bundles'][2])->toContain('home_hb_count');

    $this->postJson('/admin-api/homepage/content', ['slides' => [], 'copy' => ['home_hd_h2_m' => '26']])->assertOk();
    \App\Models\Setting::flushMap();
    expect(HomeHeadings::values(\App\Support\HomeSections::settings())['home_hd_h2_m'])->toBe('26');

    // Fails closed for a role without the homepage capability.
    test()->actingAs(\App\Models\AdminUser::create(['name' => 'S', 'email' => 'pfs-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'support']), 'admin');
    $this->postJson('/admin-api/homepage/content', ['slides' => [], 'copy' => ['home_hd_h2_m' => '20']])->assertForbidden();
});

/* ═══ 3. THE "8 sets" BADGE ═══════════════════════════════════════════════ */

it('draws "Big savings bundles" without the "8 sets" badge, and the switch brings it back', function () {
    /*
     * THE DEFECT, on the shop: "Big savings bundles 8 sets" — the badge the
     * owner crossed out on his screenshot, 4 October.
     *
     * MUTATION (RUN): default `home_hb_count` to true → red on the first
     * expectation.
     */
    $html = pfHome();

    expect($html)->toContain('<h2 id="bndl-h">Big savings bundles</h2>')
        ->and(preg_match('#<h2 id="bndl-h">[^<]*<span class="cnt">#', $html))->toBe(0);

    pfWrite(['home_hb_count' => true]);

    expect(pfHome())->toMatch('#<h2 id="bndl-h">Big savings bundles <span class="cnt">\d+ sets?</span></h2>#');
});
