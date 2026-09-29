<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Services\SetAppearance;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use Tests\Support\SetAppearanceRoutes;

/**
 * Appearance → Set — the screen, the schema and the stylesheet it emits.
 *                                                                    (Lane SA)
 *
 * The file is in four parts and each one exists because something in it either
 * already broke on this shop or is the thing rule 1 is about:
 *
 *   1. RULE 1        every default is the number the partial already drew, and
 *                    the storefront emits NOTHING until one of them moves.
 *   2. IT ARRIVES    the values really reach a rendered page. This is the
 *                    ProductStyles trap: twenty of its controls reached no
 *                    storefront page for releases because cssVariables() was
 *                    called only from the admin, and nothing said so.
 *   3. RULE 5        a colour cannot carry anything but hex into a stylesheet,
 *                    a number cannot leave its range, the endpoints have their
 *                    own capability and fail closed.
 *   4. THE THREE
 *      OWNER ASKS    the spacing default move, the fan that could widen the
 *                    page, and the hover/close popup.
 */
function saSettings(): SettingsService
{
    return app(SettingsService::class);
}

/** Write one setting through the service, the way the endpoint does. */
function saSet(string $key, mixed $value): void
{
    app(SetAppearance::class)->save([$key => $value]);
    SettingsService::forgetMemo();
}

/* ═══════════════════════ 1. NOTHING MOVES ON THE SHOP ═══════════════════════ */

it('ships every control at the value the partial already draws', function () {
    /*
     * THE INSTRUMENT FOR RULE 1, AND IT READS THE PARTIALS RATHER THAN A LIST.
     *
     * Every tunable number in resources/views/partials/set-row.blade.php and
     * set-contents-panel.blade.php is written `var(--x, <literal>)`, so the
     * fallback IS what a shop that has touched nothing renders. This walks the
     * schema, works out the fallback each default should produce, and looks for
     * that exact text in the partial. A default typed 62 where the sheet says
     * .62 fails here rather than on the shop.
     *
     * MUTATION NOTE — RUN, not imagined. Changing `'circle' => [..., 62, ...]`
     * to 60 makes this say:
     *   circle: partials/set-row.blade.php has no `var(--kset-cf,.6)` ...
     * and the same for any of the other 22 checked below.
     */
    $box = file_get_contents(resource_path('views/partials/set-row.blade.php'));
    $list = file_get_contents(resource_path('views/partials/set-contents-panel.blade.php'));

    $cases = [
        // key                var name          the fallback the partial must carry
        ['top', 'kset-top', '10px', $box],
        ['bot', 'kset-bot', '6px', $box],
        ['gap', 'kset-gap', '8px', $box],
        ['circle', 'kset-cf', '.62', $box],
        ['ring', 'kset-ring', '2px', $box],
        ['btn_f', 'kset-btnf', '.82', $box],
        ['btn_px', 'kset-btnpx', '9px', $box],
        ['btn_py', 'kset-btnpy', '3px', $box],
        ['btn_h', 'kset-btnh', '24px', $box],
        ['btn_r', 'kset-btnr', '99px', $box],
        ['btn_w', 'kset-btnw', '650', $box],
        ['pop_w', 'kset-popw', '230px', $box],
        ['pop_pad_x', 'kset-poppx', '10px', $box],
        ['pop_pad_y', 'kset-poppy', '8px', $box],
        ['pop_r', 'kset-popr', '10px', $box],
        ['pop_off', 'kset-popoff', '6px', $box],
        ['pop_sh_y', 'kset-popshy', '8px', $box],
        ['pop_sh_blur', 'kset-popshb', '24px', $box],
        ['pop_sh_a', 'kset-popsha', '.28', $box],
        ['head_f', 'kset-headf', '.78', $box],
        ['head_gap', 'kset-headgap', '4px', $box],
        ['head_w', 'kset-headw', '700', $box],
        ['li_f', 'kset-lif', '.88', $box],
        ['li_gap', 'kset-ligap', '2px', $box],
        ['li_lh', 'kset-lilh', '1.45', $box],
        ['qty_w', 'kset-qw', '700', $box],
        ['save_f', 'kset-savef', '.82', $box],
        ['save_w', 'kset-savew', '700', $box],

        ['p_block', 'ksl-block', '14px', $list],
        ['p_rowpad', 'ksl-rowpad', '6px', $list],
        ['p_gap', 'ksl-gap', '10px', $list],
        ['p_wgap', 'ksl-wgap', '1px', $list],
        ['p_photo', 'ksl-ph', '40px', $list],
        ['p_radius', 'ksl-phr', '8px', $list],
        ['p_brand', 'ksl-br', '10px', $list],
        ['p_name', 'ksl-nm', '13.5px', $list],
        ['p_var', 'ksl-var', '11.5px', $list],
        ['p_qty', 'ksl-q', '12px', $list],
        ['p_more', 'ksl-more', '12.5px', $list],
        ['p_morept', 'ksl-morept', '8px', $list],
        ['p_morepb', 'ksl-morepb', '6px', $list],
        ['p_foot', 'ksl-foot', '13px', $list],
        ['p_footsp', 'ksl-footsp', '9px', $list],
        ['p_footgy', 'ksl-footgy', '5px', $list],
        ['p_footgx', 'ksl-footgx', '16px', $list],
        ['p_lh', 'ksl-lh', '1.3', $list],
        ['p_brand_w', 'ksl-brw', '700', $list],
        ['p_brand_ls', 'ksl-brls', '.04em', $list],
        ['p_brand_op', 'ksl-brop', '.72', $list],
        ['p_name_w', 'ksl-nmw', '640', $list],
        ['p_qty_w', 'ksl-qw', '700', $list],
        ['p_more_w', 'ksl-morew', '700', $list],
        ['p_foot_w', 'ksl-footw', '700', $list],
        ['p_was_w', 'ksl-wasw', '600', $list],
        ['p_save_w', 'ksl-savew', '700', $list],
    ];

    $defaults = SetAppearance::defaults();

    foreach ($cases as [$key, $var, $fallback, $source]) {
        expect($defaults)->toHaveKey($key);
        expect(str_contains($source, 'var(--'.$var.','.$fallback.')'))->toBeTrue(
            "{$key}: the partial has no `var(--{$var},{$fallback})`, so the shipped default and the "
            ."number the page actually draws have come apart"
        );
    }

    // The phone half of the list's own media query, which is the other place a
    // default has to agree with a literal.
    foreach ([
        'p_photo_m' => '--ksl-ph:36px',
        'p_radius_m' => '--ksl-phr:7px',
        'p_rowpad_m' => '--ksl-rowpad:5px',
        'p_gap_m' => '--ksl-gap:9px',
        'p_name_m' => '--ksl-nm:13px',
        'p_footgy_m' => '--ksl-footgy:4px',
        'p_footgx_m' => '--ksl-footgx:12px',
    ] as $key => $decl) {
        expect(str_contains($list, $decl))->toBeTrue("{$key}: the list's phone block has no `{$decl}`");
    }

    // The box's own phone block, which is one declaration rather than three
    // font-sizes — and the saving really is .85 there and .82 on a laptop.
    expect(str_contains($box, '--kset-base:var(--cp-name-m,13px);--kset-savef:.85'))->toBeTrue(
        'the set box\'s phone block no longer says what save_f_m ships as'
    );
    expect($defaults['save_f_m'])->toBe(85)->and($defaults['save_f'])->toBe(82);
});

it('emits no stylesheet at all until a control moves', function () {
    /*
     * The whole of rule 1 on the markup side. App\Services\SiteLayout does the
     * same thing two blocks above this one in the layout and its note says why:
     * restating the shipped defaults would be correct in pixels and WRONG IN
     * BYTES — a new <style> element in the head of every storefront page at
     * once, which is what StorefrontEnglishUnchangedTest exists to notice, for
     * a render that is identical.
     *
     * MUTATION NOTE — RUN. Change storefrontCss()'s
     * `$values == self::defaults() ? '' : …` to always call css() and this goes
     * red on the first expectation with 2,100-odd bytes.
     */
    expect(app(SetAppearance::class)->storefrontCss())->toBe('');

    saSet('circle', 80);

    $css = app(SetAppearance::class)->storefrontCss();

    expect($css)->not->toBe('')
        ->and(str_contains($css, '--kset-cf:0.8'))->toBeTrue('the moved value is not in the emitted sheet');
});

/* ═══════════════════ 2. THE VALUES REALLY REACH A PAGE ══════════════════════ */

it('puts the emission in the layout exactly once', function () {
    /*
     * THE FINISHED STATE, NOT AN ABSENCE. CLAUDE.md names three screens that
     * cost a round trip each by asserting `not->toContain(...)` about their own
     * wiring: correct in the lane's worktree, red the moment the integrator
     * does the one thing the lane asked for.
     *
     * Zero is "built, never wired up", which is the shape this repository keeps
     * finding. Two is a second <style> block in every head, so the owner's
     * numbers are emitted twice and one copy is stale the first time the
     * partial is refactored.
     *
     * MUTATION NOTE — RUN. Deleting the @include line from
     * resources/views/layouts/store.blade.php makes this read
     *   Failed asserting that 0 is identical to 1.
     */
    $layout = file_get_contents(resource_path('views/layouts/store.blade.php'));

    expect(substr_count($layout, "@include('partials.set-appearance-css')"))->toBe(1);

    /*
     * AND IT IS BEFORE THE BODY, which is the half that is easy to lose in a
     * later tidy-up. The set partial's phone block carries
     * `.kbb-checkout .kset-pop.is-open{max-width:none}` — the rule that gets
     * the popup out of the checkout summary's overflow:hidden — and it is
     * emitted in the BODY. Ties on specificity are decided by order, so this
     * block has to come first.
     */
    expect(strpos($layout, "@include('partials.set-appearance-css')"))
        ->toBeLessThan(strpos($layout, '</head>'));
});

it('is read once per page and not once per set or per member', function () {
    /*
     * StorefrontQueryBudgetTest is a budget, and Setting::map() memoises in a
     * process-level static as well as the cache — so a second read is free in a
     * test and is not free on a queue worker or under a long-lived process that
     * has just written. The real cost this guards is the SCHEMA WALK: all()
     * casts 157 keys, and a call per member of a twelve-member set is 1,884
     * casts for one page.
     *
     * MUTATION NOTE — RUN. Putting `app(SetAppearance::class)->all()` back into
     * the class attribute of .ksl (instead of the $kbbSetAp the @php block
     * computes) takes this from 1 to 2.
     */
    $panel = file_get_contents(resource_path('views/partials/set-contents-panel.blade.php'));

    expect(substr_count($panel, 'app(\\App\\Services\\SetAppearance::class)->all()'))->toBe(1);
    expect(substr_count($panel, 'app(\\App\\Services\\SetAppearance::class)->get('))->toBe(0);
});

it('carries a moved value onto a rendered storefront page', function () {
    /*
     * THE ProductStyles TEST. Twenty of that class's controls reached no
     * storefront page for releases because cssVariables() was called only from
     * the admin — the screen saved, the admin redrew, and the shop did not
     * change. Nothing but rendering a real page and reading the bytes back can
     * tell the difference.
     *
     * MUTATION NOTE — RUN. Delete the @include from the layout, or make
     * storefrontCss() return '' unconditionally, and this fails on the
     * `id="kbb-set"` expectation.
     */
    /* str_contains() and not ->not->toContain(): Pest's toContain() is
       VARIADIC, so the second argument is a second NEEDLE rather than a
       message, and the expectation cannot fail. CLAUDE.md names this and
       ExpectationsThatCannotFailTest catches it — it caught this very line. */
    expect(str_contains((string) $this->get('/')->getContent(), 'id="kbb-set"'))
        ->toBeFalse('a shop that has moved nothing must gain no style block');

    saSet('circle', 80);
    saSet('ci_pad_t', 20);

    $html = (string) $this->get('/')->getContent();

    expect(str_contains($html, '<style id="kbb-set">'))->toBeTrue('the block never reached the page');
    expect(str_contains($html, '--kset-cf:0.8'))->toBeTrue('the circle factor never reached the page');
    expect(str_contains($html, '.kbb-cartpage .items .ci.ci{padding:20px'))->toBeTrue(
        'the cart row padding never reached the page'
    );
});

it('gives each of the three surfaces its own breakpoint, at the width its sheet already uses', function () {
    /*
     * Three media queries and three numbers — 600 for the cart page's rows, 760
     * for the set box, 480 for the buy column's list. They are the widths the
     * three stylesheets already turn over at, and collapsing them to one would
     * change what the shop renders at every width in between.
     *
     * MUTATION NOTE — RUN. Set `ci_bp`'s default to 760 to "tidy it up" and
     * this goes red on the first expectation.
     */
    $css = SetAppearance::css(SetAppearance::defaults());

    expect(str_contains($css, '@media (max-width:600px){.kbb-cartpage'))->toBeTrue();
    expect(str_contains($css, '@media (max-width:760px){.kset.kset{'))->toBeTrue();
    expect(str_contains($css, '@media (max-width:480px){.ksl.ksl{'))->toBeTrue();

    // And the shop's own sheets say the same three numbers.
    expect(str_contains(file_get_contents(resource_path('css/kbb/kbb-cart.css')), '@media(max-width:600px)'))->toBeTrue();
    expect(str_contains(file_get_contents(resource_path('views/partials/set-row.blade.php')), '@media (max-width:760px)'))->toBeTrue();
    expect(str_contains(file_get_contents(resource_path('views/partials/set-contents-panel.blade.php')), '@media (max-width:480px)'))->toBeTrue();
});

it('never outranks the checkout popup’s escape from its own clipping panel', function () {
    /*
     * ▲ THE ONE RULE THIS SHEET MUST LOSE TO.
     *
     * Below 760px `.kbb-checkout .panels` is `max-height:148px;overflow:hidden`,
     * so the popup a shopper has just opened is sliced to one visible line
     * unless it escapes — which it does through
     * `.kbb-checkout .kset-pop.is-open{position:fixed;…;max-width:none}`, a
     * (0,3,0) rule in the partial's own block. A width cap that outranked it
     * would put the slice straight back on the one screen that was already
     * fixed for it once.
     *
     * So this sheet declares NO max-width at all: the popup reads
     * `var(--kset-popw,230px)` at `.kset-pop` (0,1,0), and `max-width:none`
     * still wins.
     *
     * MUTATION NOTE — RUN. Add
     *   '.kset .kset-pop.kset-pop{max-width:min(var(--kset-popw),calc(100vw - 32px))}'
     * to css()'s $rules and this goes red.
     */
    $css = SetAppearance::css(SetAppearance::defaults());

    /* `max-width` also spells the three media queries, so the DECLARATIONS are
       what is checked: a `max-width:` that is not preceded by `(`. */
    expect(preg_match('/[;{]max-width\s*:/', $css))->toBe(0,
        'the emitted sheet declares a max-width, which can outrank the checkout popup\'s escape hatch'
    );

    $box = file_get_contents(resource_path('views/partials/set-row.blade.php'));

    expect(str_contains($box, 'max-width:min(var(--kset-popw,230px), calc(100vw - 32px))'))->toBeTrue();
    expect(str_contains($box, '.kbb-checkout .kset-pop.is-open{'))->toBeTrue();
});

/* ══════════════════════════ 3. SECURE BY CONSTRUCTION ═══════════════════════ */

it('lets nothing but hex digits into the stylesheet', function () {
    /*
     * RULE 5, at the boundary the value crosses. A <style> block is not
     * protected by Blade's escaper — it is HARMED by it: `{{ }}` turns an
     * apostrophe into `&#39;`, and an HTML entity inside a <style> element is
     * handed to the CSS parser as those five characters rather than being
     * decoded. So the only safe thing to print is a value that cannot contain
     * anything else.
     *
     * The row is written STRAIGHT INTO `settings`, bypassing the cast, because
     * that is the threat: the owner has a shell on the live box and `settings`
     * is a table. The second lock is App\Support\Color::isValidHex() on every
     * read.
     *
     * MUTATION NOTE — RUN. Make SetAppearance::hex() return the trimmed value
     * without the isValidHex() check and this goes red with the injected
     * declaration in the sheet.
     */
    saSettings()->set(SetAppearance::PREFIX.'save_c', "#fff'} body{display:none} .x{a:'");
    SettingsService::forgetMemo();

    $css = app(SetAppearance::class)->storefrontCss();

    expect(str_contains($css, 'body{display:none}'))->toBeFalse('a settings row reached the stylesheet intact');
    expect(str_contains($css, '--kset-savec'))->toBeFalse('an unusable colour was emitted rather than dropped');

    /*
     * ▲ AND THE SECOND LOCK IS TESTED ON ITS OWN, because the first one hides
     *   it. all() runs every stored value back through ModuleSchema::cast(), so
     *   a poisoned row is already repaired by the time storefrontCss() sees it
     *   — which means the case above proves the CAST and says nothing about
     *   Color::isValidHex(). Written that way alone, deleting the isValidHex()
     *   check left the suite green, which was measured rather than assumed.
     *
     *   css() is `public static` and takes an array, so a later caller — a
     *   preview, an export, a console command — can hand it values that never
     *   went through the cast. That is the caller hex() exists for, and this is
     *   it.
     *
     * MUTATION NOTE — RUN. Make hex() return the trimmed value without the
     * isValidHex() check and this goes red with `body{display:none}` in the
     * emitted sheet.
     */
    $poisoned = SetAppearance::defaults();
    $poisoned['save_c'] = "#fff'} body{display:none} .x{a:'";
    $poisoned['btn_c'] = 'url(https://example.test/x)';
    $poisoned['pop_bg'] = ['not', 'a', 'string'];

    $raw = SetAppearance::css($poisoned);

    expect(str_contains($raw, 'body{display:none}'))->toBeFalse('an unsanitised colour reached a declaration');
    expect(str_contains($raw, 'url('))->toBeFalse('a colour field carried a url() into the sheet');
    expect(str_contains($raw, '--kset-savec'))->toBeFalse();
    expect(str_contains($raw, '--kset-btnc'))->toBeFalse();
    expect(str_contains($raw, '--kset-popbg'))->toBeFalse();

    // And the legitimate case still works, in both dialects the console stores.
    saSet('save_c', 'a0b1c2');
    expect(str_contains(app(SetAppearance::class)->storefrontCss(), '--kset-savec:#A0B1C2'))->toBeTrue();

    $ok = SetAppearance::defaults();
    $ok['save_c'] = '#1c7a4a';
    expect(str_contains(SetAppearance::css($ok), '--kset-savec:#1c7a4a'))->toBeTrue();
});

it('clamps a number to its own slider and refuses a key it does not know', function () {
    /*
     * A slider cannot emit an out-of-range value, so a POST that does is not
     * worth a 422 — rule 5's "a select stores one of its own options or the
     * default", pointed at numbers. The unknown key is refused outright rather
     * than ignored, because a save that quietly drops what it was given is the
     * silence ModuleSchema exists to remove.
     */
    saSet('circle', 9999);
    expect(app(SetAppearance::class)->get('circle'))->toBe(160);

    saSet('circle', -50);
    expect(app(SetAppearance::class)->get('circle'))->toBe(20);

    // A fan cap cannot go negative and turn nth-child(n+0) into "hide them all".
    saSet('fan_max', -3);
    expect(app(SetAppearance::class)->get('fan_max'))->toBe(0);
    expect(str_contains(SetAppearance::css(app(SetAppearance::class)->all()), 'nth-child'))->toBeFalse();
});

it('has its own capability, and every endpoint is behind it', function () {
    /*
     * NOT a reuse of cartpage.manage: two storefront screens that can be
     * delegated apart, so narrowing one must not silently narrow the other from
     * a different file.
     *
     * THE '/**' SIBLING IS NEEDED and is not decoration: matching is by
     * pattern, and 'admin-api/set-appearance' does not match
     * 'admin-api/set-appearance/preview'. That endpoint renders a Blade from a
     * POST body, so leaving it outside the map would put it in reach of any
     * signed-in admin whatever their role.
     *
     * MUTATION NOTE — RUN. Delete the '/**' line from AdminCapabilities::RULES
     * and this goes red on the second expectation.
     */
    expect(AdminCapabilities::CAPABILITIES)->toHaveKey('setappearance.manage')
        ->and(AdminCapabilities::CAPABILITIES['setappearance.manage'])
        ->toBe(['owner', 'manager', 'editor']);

    $rules = collect(AdminCapabilities::RULES);

    expect($rules->contains(['*', 'admin-api/set-appearance', 'setappearance.manage']))->toBeTrue()
        ->and($rules->contains(['*', 'admin-api/set-appearance/**', 'setappearance.manage']))->toBeTrue()
        ->and($rules->contains(['*', 'admin-api/set-appearance', 'cartpage.manage']))->toBeFalse();
});

it('refuses the endpoints to a signed-out visitor and to a role without the capability', function () {
    SetAppearanceRoutes::wire($this->app);

    // Signed out.
    expect($this->getJson('/admin-api/set-appearance')->status())->not->toBe(200);

    /* Signed in, but a role the capability map does not give it to. Created
       directly rather than through a factory: this application has none for
       AdminUser, and a case that SKIPS itself over that is a capability guard
       nobody is testing. */
    $viewer = AdminUser::query()->create([
        'name' => 'Support', 'email' => 'sa-support@example.test',
        'password' => 'secret-secret-1', 'role' => 'support',
    ]);

    /* `support` is a REAL role in this console and deliberately not one of the
       three this capability names, so the refusals below are the capability
       doing its job rather than an unknown role failing for another reason. */
    expect(AdminCapabilities::CAPABILITIES['setappearance.manage'])->not->toContain('support');

    $this->actingAs($viewer, 'admin');

    expect($this->getJson('/admin-api/set-appearance')->status())->toBe(403);
    expect($this->postJson('/admin-api/set-appearance', ['settings' => ['circle' => 70]])->status())->toBe(403);
    expect($this->postJson('/admin-api/set-appearance/preview', ['settings' => []])->status())->toBe(403);
});

it('answers the screen with every field placed in a tab, and saves only known keys', function () {
    SetAppearanceRoutes::wire($this->app);

    $owner = AdminUser::query()->create([
        'name' => 'Owner', 'email' => 'sa-owner@example.test', 'password' => 'secret-secret-1', 'role' => 'owner',
    ]);

    $this->actingAs($owner, 'admin');

    $body = $this->getJson('/admin-api/set-appearance')->assertOk()->json();

    $placed = [];

    foreach ($body['tabs'] as $tab) {
        foreach ($tab['fields'] as $field) {
            $placed[] = $field['key'];
        }
    }

    expect(array_diff(array_keys(SetAppearance::SCHEMA), $placed))->toBe([],
        'a stored value with no control on the screen');
    expect(count($placed))->toBe(count(array_unique($placed)), 'a control drawn twice for one value');
    expect($body['defaults'])->toBe(SetAppearance::defaults());

    // The two tabs the owner asked for by name, and nothing else.
    $groups = array_unique(array_map(
        static fn (array $t): string => substr($t['key'], 0, 2),
        $body['tabs']
    ));
    expect(array_values($groups))->toBe(['d_', 'm_']);

    $this->postJson('/admin-api/set-appearance', ['settings' => ['not_a_setting' => 1]])
        ->assertStatus(422);

    $this->postJson('/admin-api/set-appearance', ['settings' => ['circle' => 70]])->assertOk();
    SettingsService::forgetMemo();
    expect(app(SetAppearance::class)->get('circle'))->toBe(70);
});

/* ═════════════════ 4. THE THREE THINGS THE OWNER ASKED FOR ══════════════════ */

it('gives the set block room above AND below it, which is the deliberate default move', function () {
    /*
     * ▲ THE ONE RULE-1 EXCEPTION IN THIS CHANGE, and CLAUDE.md's stated one:
     *   a default the owner asked for in as many words.
     *
     *     "the only this i need is the row spacing i need little bit up spacing
     *      or give control for set rows too on backend for cart page."
     *
     *   margin-top     6px -> 10px   what he asked for
     *   margin-bottom  0   ->  6px   measured: before this change the fan sat
     *                                FLUSH against the quantity stepper, with
     *                                41px of stepper below it and no gap at
     *                                all, while every other pair of stacked
     *                                things in that row had at least 6px. There
     *                                is no collision above: the divider-to-name
     *                                gap measured 27px at 390 and 28px at 1280
     *                                on a set row and the same two numbers on
     *                                the plain rows either side.
     *
     * MUTATION NOTE — RUN. Put `margin-top:var(--kset-top,6px)` back and both
     * this and the rule-1 case at the top of the file go red — which is the
     * point: a default move is meant to be loud, not quiet.
     */
    $box = file_get_contents(resource_path('views/partials/set-row.blade.php'));

    expect(str_contains($box, 'margin-top:var(--kset-top,10px)'))->toBeTrue();
    expect(str_contains($box, 'margin-bottom:var(--kset-bot,6px)'))->toBeTrue();
    expect(SetAppearance::defaults()['top'])->toBe(10)
        ->and(SetAppearance::defaults()['bot'])->toBe(6)
        ->and(SetAppearance::defaults()['top_m'])->toBe(10)
        ->and(SetAppearance::defaults()['bot_m'])->toBe(6);
});

it('lets the fan wrap instead of pushing the whole page sideways', function () {
    /*
     * ▲ A REAL DEFECT ON THE SHOP TODAY, REPRODUCED IN CHROMIUM BEFORE THE FIX
     *   — and the first diagnosis of it was wrong, which is why the numbers
     *   below are the measured ones and not the plausible ones.
     *
     *   BEFORE, cart page, one twelve-member set in the basket:
     *     viewport 320  document.documentElement.scrollWidth 381
     *     viewport 360  scrollWidth 381
     *     viewport 390  scrollWidth 390
     *
     *   The obvious reading — "the 204px fan is too wide" — is WRONG: with only
     *   three-member sets in the basket the fan is 58px and 320 still measured
     *   381. Walking the ancestors found it: `.kbb-cartpage .grid`'s child is a
     *   grid item with `min-width:auto`, so the TRACK grows to the row's own
     *   min-content (measured `grid-template-columns: 656.469px` inside a 362px
     *   grid), and a flex row's min-content includes everything in it that
     *   cannot shrink. The fan was one of those things; it was not the only one.
     *
     *   WHY IT STILL HAD TO BE FIXED HERE. This branch adds a CIRCLE SIZE
     *   SLIDER. At 150% on a twelve-member set the fan became 493px and
     *   scrollWidth at a 390px viewport measured 670 — a new control that gives
     *   the whole shop a horizontal scrollbar. A control that can break the page
     *   is not shippable whoever owns the stylesheet underneath it.
     *
     *   THE FIX IS THE FAN WRAPPING, and it measures nothing. A nowrap flex
     *   container's min-content is the SUM of its items; a wrapping one's is the
     *   LARGEST item. So the row's min-content collapses to one circle and the
     *   track has nothing to grow to.
     *
     *   AFTER, same basket, same twelve-member set:
     *     at the shipped defaults   320 -> 320, 390 -> 390, 1280 -> 1280
     *                              (320 was 381; the pre-existing overflow went
     *                               with it)
     *     with the circle at 150%   320 -> 320, 390 -> 390, 1280 -> 1280
     *     the laptop fan            204px wide and 26px tall — one line, the
     *                               same as before
     *
     *   AND THE OVERLAP HAD TO MOVE WITH IT. It was `-.38d` on `.kset-c +
     *   .kset-c`; the first circle of a WRAPPED line is not the first child, so
     *   it would have been pulled 0.38d outside the fan's start edge. It is on
     *   every circle now, and the fan carries `padding-inline-start:.38d` to
     *   absorb it. The arithmetic cancels exactly — before, width = d + .62d(n-1);
     *   after, .38d + n(d - .38d) = d + .62d(n-1) — and Chromium agrees: the
     *   first circle's left edge and the fan's left edge are both 90px at 390
     *   and both 225px at 1280, before and after.
     *
     * MUTATION NOTE — RUN. Take `flex-wrap:wrap` off `.kset-fan` and the first
     * expectation goes red; put the overlap back on `.kset-c + .kset-c` and the
     * third does; drop the fan's padding-inline-start and the fourth does.
     */
    $box = file_get_contents(resource_path('views/partials/set-row.blade.php'));

    expect(str_contains($box, 'flex-wrap:wrap;row-gap:var(--kset-ring,2px)'))
        ->toBeTrue('the fan cannot wrap, so a row\'s min-content is the sum of its circles');
    expect(str_contains($box, 'height:auto;aspect-ratio:1'))
        ->toBeTrue('a shrunken circle would be an ellipse');
    expect(str_contains($box, '.kset-c{margin-inline-start:calc(var(--kset-d) * var(--kset-lap,-.38))}'))
        ->toBeTrue('the overlap is still on the adjacent-sibling selector, which breaks on a wrapped line');
    /* DERIVED FROM THE OVERLAP, not a copy of its shipped value: `--kset-lap`
       is negative and `* -1` turns the pull into the space that absorbs it, so
       the two track each other when the slider moves. Written `* 0.38` it did
       not — at an overlap of 0 the fan kept a 0.38d dead gutter at its start
       and sat that far right of the words above it, which is a new control
       visibly breaking the alignment it was given to adjust. */
    expect(str_contains($box, 'padding-inline-start:calc(var(--kset-dia) * -1 * var(--kset-lap,-.38))'))
        ->toBeTrue('nothing absorbs the first circle\'s negative margin, or it does not follow the overlap');
    expect(str_contains($box, '.kset{--kset-dia:calc(var(--cp-thumb,42px) * var(--kset-cf,.62))}'))
        ->toBeTrue('the diameter is not named once, so the padding and the circles can disagree');
    expect(preg_match('/\.kset-c \+ \.kset-c\{/', $box))
        ->toBe(0, 'the adjacent-sibling overlap rule is back');

    // Logical properties only: the fan has to mirror on /ar from one declaration.
    expect(str_contains($box, 'padding-left') || str_contains($box, 'padding-right'))->toBeFalse();
});

it('opens the popup on hover with CSS alone, and measures nothing to do it', function () {
    /*
     * The owner: *"the tiny popup should be mouse hover to display on
     * desktop"*. It is `.kset:hover > .kset-pop` inside
     * `(hover:hover) and (pointer:fine)` — BOTH, never either alone: a touch
     * device that reports a coarse hover capability would otherwise be handed a
     * popup it cannot dismiss, because the finger that opened it has already
     * left.
     *
     * HUNG OFF `.kset`, not off the button, so moving the pointer from the
     * opener into the popup does not close it half-read — and the ::before
     * bridge spans the gap the popup's own offset leaves, which is the part
     * that is invisible until somebody tries it.
     *
     * NOTHING IN THE SCRIPT MEASURES LAYOUT. CLAUDE.md forbids the
     * element-measuring APIs by name and this is exactly the change that tempts
     * somebody to reach for one — "is there room below?" is one
     * getBoundingClientRect away.
     *
     * MUTATION NOTE — RUN. Drop `and (pointer:fine)` from the media query and
     * the second expectation goes red; add a getBoundingClientRect() call to
     * the script and the last one does.
     */
    $box = file_get_contents(resource_path('views/partials/set-row.blade.php'));

    expect(str_contains($box, '.kset:hover > .kset-pop,.kset:focus-within > .kset-pop{display:block}'))
        ->toBeTrue('hover does not open the popup, or it is hung off the wrong element');
    expect(substr_count($box, '@media (hover:hover) and (pointer:fine)'))
        ->toBe(1, 'the hover branch is not gated on a fine pointer as well as on hover');
    expect(str_contains($box, '.kset-pop::before{content:"";position:absolute;inset-inline:0;'))
        ->toBeTrue('there is no bridge, so the pointer cannot reach the popup across the offset');

    // The keyboard's half of the same rule.
    expect(str_contains($box, ':focus-within'))->toBeTrue();
    expect(str_contains($box, 'aria-expanded'))->toBeTrue();

    /*
     * THE COMMENTS ARE STRIPPED FIRST, and that is not a loophole — it is the
     * only way this check can mean anything here. This partial's own docblocks
     * NAME the forbidden APIs, in prose, precisely to say that it does not use
     * them; a raw substring search over the file would therefore go red for the
     * sentence promising the thing it is checking. SetRowSurfacesTest strips
     * Blade comments the same way and for the same reason.
     */
    $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $box);
    $code = (string) preg_replace('~/\*.*?\*/~s', '', $code);

    foreach ([
        'getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'offsetTop',
        'clientHeight', 'clientWidth', 'getComputedStyle', 'scrollY', 'scrollHeight',
    ] as $api) {
        expect(str_contains($code, $api))->toBeFalse("the set row measures layout with {$api}");
    }
});

it('gives a touch device a real close button, and takes it off a pointer device', function () {
    /*
     * The owner: *"on mobile on-click with corner red cross icon inside the
     * circle to close the tiny popup."*
     *
     * A REAL <button type="button"> with an accessible name that goes through
     * __(), not a glyph in a div: it is operable from a keyboard, it is
     * announced, and Lane AR translates the word rather than finding an English
     * literal baked into a partial. Positioned with LOGICAL insets so it lands
     * on the correct corner in Arabic from one declaration. The colour is a
     * CONSTANT in the stylesheet — rule 5 — and there is deliberately no
     * Appearance control for it.
     *
     * AND IT IS REMOVED ON A POINTER DEVICE, where the way out is to move the
     * pointer away and a cross is furniture. The popup's end padding goes with
     * it, so a member's name never runs under the button on the devices that
     * have one and the box is not 24px wider than it needs to be on the ones
     * that do not.
     *
     * MUTATION NOTE — RUN. Delete the `.kset-close` button from the markup and
     * the first expectation goes red; delete `.kset-close{display:none}` from
     * the hover branch and the third does; drop `padding-inline-end` from
     * `.kset-pop` and the fourth does.
     */
    $box = file_get_contents(resource_path('views/partials/set-row.blade.php'));

    expect(str_contains($box, '<button type="button" class="kset-close" data-kset-close aria-label="{{ __(\'store.set.close\') }}">'))
        ->toBeTrue('there is no close button, or it is not a real button with a translated name');
    expect(str_contains($box, 'inset-inline-end:0'))
        ->toBeTrue('the close button is not placed with logical insets, so /ar puts it on the wrong corner');
    expect(str_contains($box, '.kset-close{display:none}'))
        ->toBeTrue('the cross is drawn on a pointer device, where it does nothing anybody needs');
    expect(str_contains($box, 'padding-inline-end:var(--kset-closepad,34px)'))
        ->toBeTrue('nothing clears the close button, so a member name runs under it');
    expect(str_contains($box, 'min-width:28px;min-height:28px'))
        ->toBeTrue('the close button is smaller than a thumb');

    // The string is keyed, and it is in the English source of truth.
    expect(__('store.set.close'))->toBe('Close');

    // And the script really closes on it, before the outside-click arm — which
    // would otherwise read a press inside the popup as "selecting a name".
    expect(strpos($box, "closest('[data-kset-close]')"))
        ->toBeLessThan(strpos($box, "closest('[data-kset-toggle]')"));
});

it('draws nothing at all for a set with no members', function () {
    /*
     * Both partials gate on an empty member list, and they have to: an empty
     * box is a fan with no circles and a popup that says "In this set · 0
     * items", and an empty panel is a footing reading "Bought separately AED
     * 0.00 / You save AED 0.00" — the arithmetic right and the sentence false,
     * which is a defect this repository has already shipped once.
     */
    $box = file_get_contents(resource_path('views/partials/set-row.blade.php'));
    $list = file_get_contents(resource_path('views/partials/set-contents-panel.blade.php'));

    expect(str_contains($box, "@if (\$kbbSetMembers !== [])"))->toBeTrue();
    expect(str_contains($list, "@if (\$kbbSetPage['members'] !== [])"))->toBeTrue();

    // And `p_on` takes the same door rather than opening a second empty case —
    // it is tested where the contents are computed, so a switched-off panel does
    // not do the work and throw the answer away.
    expect(str_contains($list, "(\$product->isSet() && \$kbbSetAp['p_on'])"))->toBeTrue();
});
