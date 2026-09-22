<?php

declare(strict_types=1);

use App\Services\HeaderSettings;
use App\Services\MobileHeader;
use App\Models\AdminUser;

/**
 * =============================================================================
 * THE MOBILE HEADER PREVIEW WAS LYING ABOUT FIVE OF ITS OWN CONTROLS
 * =============================================================================
 *
 * The owner adjusts this header from the preview. So a control that moves the
 * phone and not the preview is, to them, a control that does not work — and
 * "the mobile search box height need to be controlled" was exactly that report.
 *
 * The storefront was fine. `header .sbox .search-in{min-height:var(--mh-sh)}`
 * and the four size variables beside it are in kbb.css AND in the built
 * bundle — checked, not assumed. The screen was the broken half:
 *
 *   1. `.mhp-sbox em` was declared TWICE in the admin stylesheet at the same
 *      specificity. The first carried `font-size:var(--mhp-st)` and zeroed the
 *      vertical padding; the second, further down, carried `font-size:10.5px`
 *      and `padding:7px`. Later wins. So Typed text moved nothing at all, and
 *      Field height could not shrink the box below the ~27px that 7px of
 *      padding plus a line of text holds it open at — which is most of its
 *      range. Same story for `.mhp-sbox em::before` and the magnifier.
 *
 *   2. Placeholder, Cart and wishlist count, and Trending words fed variables
 *      the preview never emitted, onto elements the preview never drew. Three
 *      sliders over a mock with no counter and no trending row.
 *
 * The first is a cascade bug and the fix is one rule instead of two. The second
 * is a census problem, which is what this file is: every `--mhp-*` the script
 * writes must be read by a rule, and every one a rule reads must be written by
 * the script. Neither direction can rot without turning this red.
 *
 * MUTATION: put back the early `.mhp-sbox em{...}` block, or drop `--mhp-badge`
 * from the `marks` template. Red either way.
 */
function mhpSource(): string
{
    return (string) file_get_contents(base_path('resources/views/admin/app.blade.php'));
}

/**
 * The same source with every comment stripped. A comment that MENTIONS a
 * selector is prose, not a rule, and counting rules without this turned an
 * earlier test in this repo red over its own explanation.
 */
function mhpRules(): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', mhpSource());
}

/** The body of mhPreview(), where the preview's variables are written. */
function mhpScript(): string
{
    $src = mhpSource();
    $from = strpos($src, 'function mhPreview(){');

    expect($from)->not->toBeFalse('mhPreview() has been renamed; re-point this test');

    return substr($src, (int) $from, (int) strpos($src, "\nfunction paintMobileHdr", (int) $from) - (int) $from);
}

it('draws the search box with exactly one rule, so no size control loses the cascade', function () {
    /*
     * Not "the rule says X" — a literal like that goes red the next time
     * somebody adjusts a colour. The assertion is the SHAPE of the problem:
     * two rules for one element at one specificity, where only the last is
     * really in force.
     */
    $src = mhpRules();

    /*
     * ANCHORED AT THE START OF A LINE, because `.mhp-snb .mhp-sbox em` and its
     * two siblings are a DIFFERENT and higher specificity — they are state
     * rules and they are meant to win. The bug was two rules that were equal.
     */
    foreach (['.mhp-sbox em{', '.mhp-sbox em::before{'] as $selector) {
        expect(preg_match_all('/^' . preg_quote($selector, '/') . '/m', $src))->toBe(
            1,
            "{$selector} is declared more than once at the same specificity; whichever declarations "
            .'sit in the earlier copy are dead, which is how Typed text and the magnifier went quiet'
        );
    }
});

it('lets the field height alone decide the field height', function () {
    /*
     * Vertical padding on the box fights min-height and wins below its own
     * size, which put a floor under Field height well above the bottom of its
     * range. The storefront has none for the same reason.
     */
    $src = mhpRules();
    expect(preg_match('/^\.mhp-sbox em\{/m', $src, $m, PREG_OFFSET_CAPTURE))->toBe(1);
    $rule = substr($src, (int) $m[0][1]);
    $rule = substr($rule, 0, (int) strpos($rule, '}'));

    expect(str_contains($rule, 'min-height:var(--mhp-sh'))->toBeTrue('Field height no longer reaches the box');

    expect(preg_match('/padding:\s*0\s/', $rule))->toBe(
        1,
        'the search box has vertical padding again, so Field height cannot take it below '
        .'the height that padding holds it open at'
    );
});

it('emits a variable for every size control and reads every variable it emits', function () {
    $script = mhpScript();
    $src = mhpRules();

    // Written by the script: --mhp-x:...
    preg_match_all('/--mhp-([a-z]+)\s*:/', $script, $w);
    // Written by the `keep` helper, which names the variable as its second argument.
    preg_match_all('/keep\([^,]+,\s*\'([a-z]+)\'\)/', $script, $k);

    $written = array_unique(array_merge($w[1], $k[1]));

    // Read by a CSS rule: var(--mhp-x ...)
    preg_match_all('/var\(--mhp-([a-z]+)/', $src, $r);
    $read = array_unique($r[1]);

    sort($written);
    sort($read);

    expect(array_diff($written, $read))->toBe(
        [],
        'the preview writes a variable no rule reads, so a control moves a number and nothing on screen'
    );

    expect(array_diff($read, $written))->toBe(
        [],
        'a rule reads a variable the preview never writes, so it is stuck on its fallback for ever'
    );
});

it('reads every size control the schema offers', function () {
    /*
     * Derived from the schema, not from a list kept here. A control added to
     * MobileHeader with nothing drawing it turns this red on the day it lands,
     * rather than on the day the owner drags it and nothing happens.
     */
    $script = mhpScript();

    $sizes = array_filter(
        array_keys(MobileHeader::SCHEMA),
        fn ($k) => str_starts_with($k, 'size_') || $k === 'search_h'
    );

    expect($sizes)->not->toBeEmpty();

    foreach ($sizes as $key) {
        expect(str_contains($script, "'{$key}'"))->toBeTrue(
            "the preview never reads {$key}, so that slider moves the phone and not the screen"
        );
    }
});

it('draws a counter and a trending row for the controls that size them', function () {
    $script = mhpScript();

    expect(str_contains($script, 'mhp-trend'))->toBeTrue('nothing on the preview carries the trending size');
    expect(preg_match('/mhp-act[^`]*<b>/', $script))->toBe(1, 'the icons carry no counter to size');
});

it('draws the trending row only when the storefront would', function () {
    /*
     * `trending_show` is off by default and belongs to another screen. A
     * preview that always drew the row would be the same lie in the other
     * direction: a row most shops do not have.
     */
    $script = mhpScript();

    expect(str_contains($script, 'MH.context'))->toBeTrue(
        'the trending row is drawn unconditionally, so shops with trending off see a row they do not have'
    );
});

it('sends the storefront\'s trending state with the settings', function () {
    $admin = AdminUser::create([
        'name' => 'MH Owner',
        'email' => 'mh-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);

    $this->actingAs($admin, 'admin');

    app(HeaderSettings::class)->save(['trending_show' => false]);

    $this->getJson('/admin-api/mobile-header')
        ->assertOk()
        ->assertJsonPath('context.trending', false);

    app(HeaderSettings::class)->save(['trending_show' => true]);

    $this->getJson('/admin-api/mobile-header')
        ->assertOk()
        ->assertJsonPath('context.trending', true);
});
