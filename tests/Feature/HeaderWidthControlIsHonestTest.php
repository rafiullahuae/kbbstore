<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Services\HeaderSettings;
use App\Services\SiteLayout;

/**
 * Appearance → Header → Bar → "Content width" does nothing on the shipped shop,
 * and now it says so.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * WHAT THE OWNER REPORTED: "along with the site width setting, make the header
 * width setting too, or it should be auto set as per the site width, we have
 * this option, but header remains still same width and nothing adjusting auto
 * as per the set width of the site."
 *
 * HALF OF THAT TURNED OUT TO BE FIXED ALREADY and the measurement is in this
 * file's sibling below: with `header_follows` on, `header .wrap` is exactly as
 * wide as the page container — 1200 against 1200 at viewport 1280, 1680 and
 * 1920, driven in Chromium. Lane H1 fixed that and it shipped in 2.60.284.
 *
 * THE HALF THAT WAS REAL is the control he was actually dragging. HeaderSettings
 * ::maxWidthCss() reads `max_width` ONLY when `header_follows` is false, and
 * that switch ships ON — so on the shipped shop the "Content width" slider is
 * inert, and its help text was the EMPTY STRING. He dragged it, pressed save,
 * was told "Saved", and nothing moved, with no explanation on the screen and
 * nothing in the help to send him to the switch that was overruling it.
 *
 * A control that cannot win is worse than a missing one: the missing control
 * sends you looking, and this one tells you you have already done it.
 */
it('tells you the header width slider is ignored while the header follows the site', function () {
    [, , , $help] = HeaderSettings::SCHEMA['max_width'];

    expect(trim($help))->not->toBe('', 'the header width slider ships inert, so it may not ship with empty help');

    /*
     * It has to name the OTHER control, not merely admit to being conditional.
     * "Only applies sometimes" leaves the owner exactly where he was; the whole
     * value of the sentence is the path at the end of it.
     */
    expect($help)->toContain('Header follows the site width')
        ->and($help)->toContain('Site layout');
});

/*
 * MUTATION: return the payload without the inert marking (drop the foreach in
 * HeaderApiController::show()) and this is red. Run.
 */
it('marks the header width field inert exactly while the switch is on', function () {
    $admin = AdminUser::create([
        'name' => 'Header',
        'email' => 'header-'.uniqid().'@example.com',
        'password' => bcrypt('x'),
        'role' => 'owner',
    ]);

    $field = function () use ($admin) {
        $body = test()->actingAs($admin, 'admin')->getJson('/admin-api/header')->assertOk()->json();

        foreach (($body['tabs'] ?? []) as $tab) {
            foreach (($tab['fields'] ?? []) as $f) {
                if (($f['key'] ?? '') === 'max_width') {
                    return $f;
                }
            }
        }

        return null;
    };

    /* ── as it ships: the switch is on, so the slider is inert ── */
    expect(app(SiteLayout::class)->get('header_follows'))->toBeTrue('the shipped default moved; this test is about that default');

    $on = $field();

    expect($on)->not->toBeNull('the header endpoint no longer returns a max_width field');
    expect($on['inert'] ?? null)->toBeTrue();
    expect($on['inert_why'] ?? '')->toContain('Site layout');

    /* ── and the moment the switch is off it is a live control again ── */
    app(SiteLayout::class)->save(['header_follows' => false]);
    \App\Services\SettingsService::forgetMemo();

    $off = $field();

    expect($off['inert'] ?? null)->toBeFalse('turning the switch off must hand the slider back, or the fix is just a permanent disable');
    expect($off['inert_why'] ?? 'x')->toBe('');
});

/*
 * The half that was already working, pinned so it cannot regress quietly: while
 * the switch is on, the header is told to take the PAGE's width token and not a
 * number of its own.
 *
 * `var(--site-max)` and not `1680px` is the load-bearing detail, and
 * SiteWidthSystemTest records why: a number written here looks identical the day
 * it is saved and then freezes, so moving Site width later would leave the
 * header behind with nothing reporting it.
 *
 * MUTATION: make maxWidthCss() return the header's own number in both arms and
 * this is red.
 */
it('hands the header the page width token, not a frozen number', function () {
    $header = app(HeaderSettings::class);

    $css = fn () => $header->cssVariables();

    app(SiteLayout::class)->save(['header_follows' => true, 'max' => 1200]);
    \App\Services\SettingsService::forgetMemo();

    expect(app(HeaderSettings::class)->cssVariables())->toContain('--hd-max:var(--site-max)');

    app(SiteLayout::class)->save(['header_follows' => false]);
    \App\Services\SettingsService::forgetMemo();

    $off = app(HeaderSettings::class)->cssVariables();

    expect($off)->not->toContain('--hd-max:var(--site-max)');
    expect($off)->toContain('--hd-max:'.HeaderSettings::SCHEMA['max_width'][2].'px');
});
