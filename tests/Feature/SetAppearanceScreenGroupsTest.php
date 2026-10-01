<?php

declare(strict_types=1);

use App\Services\SetAppearance;

/**
 * Appearance → Set: the screen's shape, and everything it must not lose.
 *                                                                   (Lane SA3)
 *
 * The owner: *"on this set backend controls page, i want the preview on the
 * right side, so i can avoid the long page. also make the tabs or sections
 * properly, not just throw the long page. make it super nice. and preview must
 * work in real time upon changing controls."*
 *
 * What that turned into is a two-column layout with a pinned preview, and 198
 * controls regrouped from thirteen cards into eight named sections with one on
 * screen at a time. This file pins the three things that can silently go wrong
 * with that:
 *
 *   1. A CONTROL CANNOT GO MISSING. The grouping is a layout table in the
 *      screen's own JavaScript, and a layout table is exactly the kind of thing
 *      that forgets a key when the schema grows one. The table's coverage of
 *      the schema is asserted here to be total and duplicate-free.
 *   2. THE PINNING IS CSS. Rule 4 forbids JavaScript that measures layout, and
 *      a sticky column is the single most tempting place on this screen to
 *      reach for a scroll listener and a getBoundingClientRect.
 *   3. NOTHING THAT ALREADY WORKED WAS LOST IN THE RE-LAYOUT. The save bar's
 *      count, Discard, the leave-page and close-tab prompts, the XSRF header,
 *      the sidebar entry and the window.go wrap all still have to be there —
 *      this was a re-layout, and a re-layout that dropped the unsaved-changes
 *      guard would cost the owner an afternoon of slider work.
 */
function saScreenBlade(): string
{
    return (string) file_get_contents(
        resource_path('views/admin/partials/set-appearance-screen.blade.php')
    );
}

/**
 * The screen with its prose taken out.
 *
 * Needed because this file's strongest assertion is that certain NAMES do not
 * appear — the element-measuring APIs — and the screen's own docblock explains
 * at length why it does not use them. Scanning the raw file would find the
 * explanation and call it the offence, which is the classic way a
 * forbidden-name test comes to be deleted rather than fixed.
 */
function saScreenCode(): string
{
    $blade = saScreenBlade();

    $blade = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $blade);
    $blade = (string) preg_replace('#/\*.*?\*/#s', '', $blade);

    return (string) preg_replace('#^\s*//.*$#m', '', $blade);
}

/**
 * Every schema key the screen's GROUPS table places, read out of the shipped
 * Blade rather than restated here.
 *
 * Two shapes: a section is either a whole card from the API (`from: 'd_cart'`,
 * expanded through SetAppearance::TABS) or an explicit list of keys.
 *
 * @return list<string>
 */
function saGroupedKeys(): array
{
    $blade = saScreenBlade();
    $out = [];

    preg_match_all("/\bfrom:\s*'([a-z0-9_]+)'/", $blade, $cards);

    foreach ($cards[1] as $tab) {
        expect(array_key_exists($tab, SetAppearance::TABS))->toBeTrue(
            "The screen groups a card `{$tab}` that SetAppearance::TABS does not declare."
        );

        foreach (SetAppearance::TABS[$tab][2] as $key) {
            $out[] = $key;
        }
    }

    preg_match_all('/\bkeys:\s*\[([^\]]*)\]/s', $blade, $lists);

    foreach ($lists[1] as $body) {
        preg_match_all("/'([a-z0-9_]+)'/", $body, $keys);

        foreach ($keys[1] as $key) {
            $out[] = $key;
        }
    }

    return $out;
}

it('places every one of the 198 controls in exactly one section', function () {
    /*
     * THE FAILURE THIS EXISTS FOR. The thirteen cards the API returns are
     * regrouped by subject — `d_list_size` and `d_list_type` between them held
     * 49 controls covering the photographs, the words, the fold and the footing
     * — and that regrouping is a list of key names in the screen. Add a control
     * to SetAppearance::SCHEMA and forget the screen, and it is a setting the
     * owner can never reach, on a screen whose whole complaint was that it had
     * too much on it to find anything.
     *
     * The screen draws an "Not yet grouped" card for exactly this case so the
     * control is still reachable. This test is what stops that card from ever
     * being what the owner sees.
     *
     * MUTATION NOTE — RUN. Delete 'p_radius' from the `rows` group's desk
     * section in the Blade and this goes red naming it as unplaced.
     */
    $grouped = saGroupedKeys();
    $schema = array_keys(SetAppearance::SCHEMA);

    $counts = array_count_values($grouped);
    $twice = array_keys(array_filter($counts, static fn (int $n): bool => $n > 1));

    expect($twice)->toBe([], 'A control is drawn in two sections at once: '.implode(', ', $twice));

    $missing = array_values(array_diff($schema, $grouped));
    $unknown = array_values(array_diff($grouped, $schema));

    expect($missing)->toBe([], 'The screen never draws: '.implode(', ', $missing));
    expect($unknown)->toBe([], 'The screen groups keys the schema does not have: '.implode(', ', $unknown));
    /*
     * ▲ ADVANCED DELIBERATELY, 29 September (Lane CR): 198 -> 213. Fifteen
     *   controls added to the set's row on the cart page — a minimum height,
     *   the picture's size and radius, the brand and the name sizes, the space
     *   above and below the stepper, where the circles sit, and the phone's
     *   own twin of each measurement. They are placed by the `cart` section,
     *   which takes the API's two cards whole, so the placement is total
     *   without a line being added to the table above.
     */
    expect(count($grouped))->toBe(213);
});

it('names eight sections, and keeps Desktop and Mobile above them', function () {
    $blade = saScreenBlade();

    /* Desktop/Mobile is the axis the owner asked for by name and it has to stay
       ABOVE the section strip: the sections are what changed, and a screen that
       buried the breakpoint switch inside one of them would have answered a
       question he did not ask. */
    $tabStrip = strpos($blade, "data-sap-tab=\"desk\"");
    $groupStrip = strpos($blade, 'class="sap-nav"');

    expect($tabStrip)->toBeInt()->and($groupStrip)->toBeInt()
        ->and($tabStrip)->toBeLessThan($groupStrip);

    foreach ([
        'What is drawn',
        'The set row on the cart page',
        'The fanned stack and its popup',
        'The panel and the hang',
        'Rows and photographs',
        'Words',
        'The fold and the footing',
        'Where the phone sizes start',
    ] as $label) {
        expect(str_contains($blade, "label: '".str_replace("'", "\\'", $label)."'"))->toBeTrue(
            "The section “{$label}” is gone from the screen."
        );
    }

    /* The five cards the API already had as one subject are reused whole, prose
       and all, rather than being retyped here — a description retyped in the
       screen is a description that stops agreeing with the schema. */
    preg_match_all("/\bfrom:\s*'([a-z0-9_]+)'/", $blade, $cards);

    expect(count(array_unique($cards[1])))->toBeGreaterThan(4);
});

it('pins the preview with CSS and measures nothing to do it', function () {
    /*
     * Rule 4: no JavaScript that measures layout. A sticky preview column is
     * the single most tempting place on this screen to reach for a scroll
     * listener — and it is also the place where a scroll listener would be
     * worst, because it would run on every frame of the drag the owner is
     * making while the preview is redrawing.
     *
     * MUTATION NOTE — RUN. Add `window.addEventListener('scroll', function () {
     * document.querySelector('.sap-side').getBoundingClientRect(); });` to the
     * screen and this goes red on getBoundingClientRect.
     */
    $blade = saScreenBlade();

    expect(str_contains($blade, '.sap-side{min-width:0;order:-1}'))->toBeTrue();
    expect(preg_match('/\.sap-side\{order:0;position:sticky;top:\d+px\}/', $blade))->toBe(1);

    /* Two columns, and the second one is not a strip: the buy column the owner
       is judging is 582px at 1280 and 346px at 390, so the preview has to be
       able to get wide enough to show it honestly. */
    expect(preg_match(
        '/\.sap-body\{grid-template-columns:minmax\(0,1fr\) minmax\(\d+px,(\d+)px\)\}/',
        $blade,
        $m
    ))->toBe(1);

    expect((int) $m[1])->toBeGreaterThanOrEqual(400);

    $code = saScreenCode();

    foreach ([
        'getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'clientHeight',
        'ResizeObserver', 'IntersectionObserver', 'getComputedStyle', 'scrollHeight',
        'scrollTop', 'innerHeight', 'matchMedia',
    ] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse(
            "The screen measures layout in JavaScript with {$forbidden}; this project sizes with calc() "
            .'and custom properties, and two other tests forbid these by name.'
        );
    }

    /* `.sap-top` is display:contents and NOT a box. A real box there becomes
       the sticky save bar's containing block, and a sticky element only sticks
       within its parent's bounds — the bar would come unstuck about forty
       pixels down the page.
       MUTATION NOTE — RUN. Change `.sap-top{display:contents}` to
       `.sap-top{display:grid;gap:14px}` and this goes red. */
    expect(str_contains($blade, '.sap-top{display:contents}'))->toBeTrue();

    /* And the frame is no longer clamped by its own stage. `max-width:100%`
       inside a flex stage silently shrank every width button to the panel's
       width, so "Desktop · 1280" resolved the media query at about 700px and
       drew the PHONE branch under a label that said Desktop.
       MUTATION NOTE — RUN. Put `max-width:100%` back on .sap-frame, or take
       `flex:none` off it, and this goes red. */
    expect(preg_match('/\.sap-frame\{[^}]*\}/', $blade, $frame))->toBe(1);
    expect($frame[0])->toContain('flex:none')->not->toContain('max-width');
    expect(str_contains($blade, '.sap-stage{border:1px solid var(--border,#e6e6e6);border-radius:11px;overflow:auto'))
        ->toBeTrue('The stage must scroll, or a 1280px frame has nowhere to be 1280px wide.');
});

it('keeps every behaviour the re-layout was not supposed to touch', function () {
    /*
     * This was a re-layout of a working screen. Each of these is something the
     * owner would only discover was gone by losing work:
     *
     *   the count            tells him the thing he just dragged registered
     *   Discard              puts a session of experimenting back
     *   the kept draft       leaving by the sidebar keeps the typing in
     *                        Unfinished (top bar), and nothing asks — the
     *                        two prompts that used to stand here were the
     *                        owner's "weired popup" (Lane PM, 1 Oct 2026)
     *   the XSRF header      without it every POST is a 419 and the preview
     *                        never draws at all
     *   the sidebar entry    this screen has no other way into the nav
     *   the window.go wrap   and it must still delegate to the previous one
     *
     * MUTATION NOTE — RUN. Delete the kbbDrafts.track( registration and this
     * goes red on that line; put a beforeunload listener back and the last
     * expectation is red.
     */
    $blade = saScreenBlade();

    foreach ([
        "window.kbbAddNavEntry({" => 'the sidebar entry',
        'var previousGo = window.go;' => 'the previous window.go',
        'return previousGo.apply(this, arguments);' => 'delegating to it',
        'window.kbbDrafts.track({' => 'the Unfinished registration that keeps the typing',
        "window.kbbDrafts.flush('setap')" => 'handing the typing over on the way out',
        'function mayLeave(' => 'the leave-screen hand-over',
        "'X-XSRF-TOKEN': cookie('XSRF-TOKEN')" => 'the XSRF header',
        'data-sap-discard' => 'Discard',
        'data-sap-save' => 'Save',
        'function refreshBar(' => 'the in-place unsaved count',
        'function renderControls(' => 'the redraw that leaves the preview frame alone',
        'unsaved change' => 'the words on the bar',
        'moved from shipped' => 'the count against the shipped values',
        'data-sap-reset=' => 'the per-control “shipped” button',
    ] as $needle => $what) {
        expect(str_contains($blade, $needle))->toBeTrue("The re-layout lost {$what}.");
    }

    // And nothing asks on the way out any more: no "Leave site?" on a refresh.
    expect(str_contains($blade, "addEventListener('beforeunload'"))->toBeFalse(
        'The close-tab prompt is back; the owner asked for it to go (Lane PM).'
    );

    /* Everything this screen clicks is prefixed, because app.blade.php binds a
       dozen delegated listeners to `document` on BARE attribute names and a
       click on any element carrying one is handled by whichever screen it
       belongs to. */
    preg_match_all('/data-(?!sap-)[a-z-]+=/', saScreenCode(), $bare);

    /* `data-sec=` is the console's OWN nav attribute and this screen only reads
       it, in a querySelector, to open the Appearance group — it binds nothing
       to it. Everything else this screen attaches a listener to is sap-. */
    expect(array_values(array_unique($bare[0])))->toBe(['data-sec=']);

    /* And the whole screen is still behind one capability — the endpoints it
       calls are the same three, and it invents none. */
    preg_match_all("#(?:BASE \+|\bapi\()\s*'(/[a-z-]+[^']*)'#", saScreenCode(), $calls);

    $paths = array_values(array_unique($calls[1]));
    sort($paths);

    expect($paths)->toBe(['/set-appearance', '/set-appearance/preview']);
});

it('drives the live preview by posting into the frame, never by reaching into it', function () {
    /*
     * The frame is `sandbox="allow-scripts"` with no allow-same-origin, so it
     * holds an OPAQUE origin and `frame.contentDocument` is null. That is the
     * security property that makes it safe to render a Blade from a POST body
     * into it, and the live preview must not be the thing that gives it up.
     *
     * So the screen posts a message in and the frame's own script applies it,
     * and the screen identifies the frame by WINDOW HANDLE rather than by
     * origin — a sandboxed frame's origin serialises to the string "null",
     * which is also what a file:// page and every other sandboxed frame
     * reports, so an origin check here would prove nothing at all.
     *
     * MUTATION NOTE — RUN. Change the handler's guard to
     * `if (e.origin !== 'null') return;` and the contentWindow expectation
     * below goes red.
     */
    $blade = saScreenBlade();
    $preview = (string) file_get_contents(
        resource_path('views/admin/previews/set-appearance.blade.php')
    );

    /* The attribute itself, not the word anywhere in the file: the docblock
       above it explains at length what allow-same-origin would cost. */
    expect(preg_match_all('/sandbox="([^"]*)"/', $blade, $sandboxes))->toBe(1);
    expect($sandboxes[1])->toBe(['allow-scripts']);

    expect(str_contains($blade, 'e.source !== frame.contentWindow'))->toBeTrue();
    expect(str_contains($blade, 'pvWin.postMessage({ kbbSetLive:'))->toBeTrue();

    // And the receiving end reads only its own parent, and allowlists the text.
    expect(str_contains($preview, 'e.source !== window.parent'))->toBeTrue();
    expect(str_contains($preview, 'function clean(css)'))->toBeTrue();
    expect(str_contains($preview, 'live.textContent = clean(e.data.css)'))->toBeTrue();
    expect(str_contains($preview, 'innerHTML'))->toBeFalse();

    /* The overlay goes in a style element of its own, AFTER the server's, so it
       wins on document order with no !important and no extra class. */
    expect(strpos($preview, '<style id="kbb-set">'))
        ->toBeLessThan(strpos($preview, '<style id="kbb-set-live">'));
});
