<?php

declare(strict_types=1);

/**
 * The admin console's tick boxes must be usable without a mouse.
 *
 * Every .cbx in resources/views/admin/app.blade.php is a <span> with a click
 * handler. A span takes no focus and answers no key, so before this package
 * nobody navigating by keyboard could change any setting behind one -- the
 * column pickers, the bulk-select columns, the SEO switches, the redirect
 * enable flags. Forty-eight controls across a dozen screens.
 *
 * A previous lane found this and deliberately did NOT add role="checkbox" on
 * its own, because a role with no key handling makes the DOM assert something
 * untrue. The promise is only honest if all four parts are present together,
 * so this file pins all four, and a MutationObserver besides: aria-checked has
 * to follow the class whoever changed it, since screens toggle .on from their
 * own code and from whole re-renders. An aria-checked that only the keyboard
 * path updated would drift out of step with the box actually on the screen,
 * which is the same lie in a quieter form.
 *
 * Proven in Chromium as well as here -- reached by Tab alone from the top of
 * the page, toggled with Space and with Enter, ring measured at 2px solid
 * rgb(16,23,41). This file is what stops it being removed again.
 */
$blade = fn (): string => (string) file_get_contents(resource_path('views/admin/app.blade.php'));

$block = function () use ($blade): string {
    $source = $blade();
    $start = strpos($source, 'LANE CJ · Admin · keyboard-operable tick boxes — BEGIN');
    $end = strpos($source, 'LANE CJ · Admin · keyboard-operable tick boxes — END');

    expect($start)->not->toBeFalse();
    expect($end)->not->toBeFalse();

    return substr($source, $start, $end - $start);
};

it('gives every tick box a focus stop and the checkbox role', function () use ($block) {
    $source = $block();

    expect(str_contains($source, "el.setAttribute('role', 'checkbox')"))->toBeTrue(
        'Tick boxes are no longer given role=checkbox, so a screen reader announces nothing.'
    );
    expect(str_contains($source, "el.setAttribute('tabindex', '0')"))->toBeTrue(
        'Tick boxes are no longer focusable, so the keyboard cannot reach them at all.'
    );
});

it('toggles on Space and on Enter, through the same click the mouse uses', function () use ($block) {
    $source = $block();

    $keys = str_contains($source, "e.key !== ' ' && e.key !== 'Spacebar' && e.key !== 'Enter'");
    expect($keys)->toBeTrue('The Space/Enter guard has changed; keyboard activation may be dead.');

    // .click() and not a private toggle: the handlers this console binds are
    // el.onclick, and delegated listeners watch for real clicks on #content.
    // Dispatching a click keeps the keyboard and the mouse on one code path so
    // they cannot drift apart later.
    expect(str_contains($source, 'box.click();'))->toBeTrue(
        'Keyboard activation no longer dispatches a click, so it has stopped sharing the mouse path.'
    );

    // Space scrolls and Enter submits if they are let through.
    expect(str_contains($source, 'e.preventDefault();'))->toBeTrue(
        'The key handler no longer cancels the default, so Space will scroll the page as well as toggle.'
    );
});

it('keeps aria-checked in step with the class whoever changed it', function () use ($block) {
    $source = $block();

    $sync = str_contains(
        $source,
        "el.setAttribute('aria-checked', el.classList.contains('on') ? 'true' : 'false');"
    );
    expect($sync)->toBeTrue('aria-checked is no longer read back off the class.');

    expect(str_contains($source, 'new MutationObserver('))->toBeTrue(
        'The observer is gone, so aria-checked stops tracking toggles made by other screens.'
    );
    expect(str_contains($source, "attributeFilter: ['class']"))->toBeTrue(
        'The observer no longer watches the class attribute, so aria-checked will drift.'
    );
    expect(str_contains($source, 'childList: true'))->toBeTrue(
        'The observer no longer watches for new nodes, so re-rendered screens lose keyboard support.'
    );
});

it('draws a focus ring that is visible on a ticked box as well as an empty one', function () use ($blade) {
    $source = $blade();

    // --ink, not --accent: an .on box is already filled with --accent, so an
    // accent ring on it is a green line on a green square and the focused box
    // looks exactly like the one beside it.
    $rule = str_contains($source, '.cbx:focus-visible{outline:2px solid var(--ink);outline-offset:2px}');

    expect($rule)->toBeTrue(
        'The tick-box focus ring is gone or has changed colour; keyboard users cannot see where they are.'
    );
});

it('leaves the element, the ids and the saved payload exactly as they were', function () use ($blade) {
    $source = $blade();

    // The whole reason the spans stayed spans. Every reader asks
    // classList.contains('on') and every writer calls classList.toggle('on'),
    // including the save handlers. Swapping in a real <input> means rewriting
    // all of those in the most contended file in the repo, and a dropped field
    // there does not throw -- it blanks a stored setting on the next save.
    foreach ([
        "indexnow_on:document.getElementById('seo_indexnow_cbx').classList.contains('on')?'1':'0'",
        "llms_enabled:document.getElementById('seo_llms_cbx').classList.contains('on')?'1':'0'",
        "crawl_clean:document.getElementById('seo_crawlclean_cbx').classList.contains('on')?'1':'0'",
        "enable_merchant:document.getElementById('seo_merchant_cbx').classList.contains('on')?'1':'0'",
    ] as $line) {
        expect(str_contains($source, $line))->toBeTrue(
            "A save handler that reads a tick box by class has changed: {$line}"
        );
    }

    // Still spans. If this ever becomes an <input>, every line above has to
    // move to .checked in the same commit.
    expect(str_contains($source, '.cbx{width:18px;height:18px;'))->toBeTrue(
        'The .cbx rule has changed shape; check the class-based state still works.'
    );
});
