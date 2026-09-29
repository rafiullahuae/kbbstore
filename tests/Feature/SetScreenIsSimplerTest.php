<?php

declare(strict_types=1);

use App\Services\SetAppearance;

/**
 * =============================================================================
 * "ALSO MAKE IT SUPER EASIER THE SET CONTROL PAGE" — Lane CR
 * =============================================================================
 *
 * The screen was rebuilt a day earlier into two columns, eight sections and a
 * live preview, and the owner was still finding it hard. Three things were
 * still true of it, and this round makes all three worse before it makes them
 * better — fifteen controls were added to the section he is most often in:
 *
 *   1. A SECTION OPENED WITH EVERYTHING IN IT. "The set row on the cart page"
 *      went from five controls to twenty-five in this round alone.
 *   2. A SECTION WAS ONE CARD. Twenty-five sliders under one heading is a list,
 *      not a screen.
 *   3. THE PREVIEW SHOWED ALL THREE SURFACES AT ONCE, so the thing the open
 *      section was about was somewhere in 900px of frame.
 *
 * So: every section names the handful somebody actually reaches for and opens
 * with those; the cart section is four cards in the order you meet them going
 * across the row; and the preview hides the two surfaces the open section is
 * not about.
 *
 * MEASURED IN CHROMIUM, signed in, Appearance → Set → "The set row on the cart
 * page", at 390 and at 1280:
 *
 *                       before this change    after, folded    after, open
 *   controls on screen          13                  4               13
 *   cards                        1                  4                4
 *   scrollWidth              390 / 1280        390 / 1280      390 / 1280
 *
 * and the frame, read through its own document: with "What is drawn" open all
 * three surfaces are visible; with the cart section open only `.sap-s-cart`;
 * with "Rows and photographs" open only `.sap-s-list`.
 *
 * ── MUTATION NOTES, ALL RUN ────────────────────────────────────────────────
 *
 * 1. Delete the `few:` line from the cart group: the first case is red — the
 *    section with the most controls in it opens with all of them again.
 * 2. Put `ci_thumb_r` into the cart group's `few` and take it out of the
 *    group's cards: the second case is red — a control the fold promises and
 *    the section cannot draw.
 * 3. Change `var detail = false` to `true`: the third case is red.
 * 4. Remove `.sap-s-cart` from the preview view: the fifth case is red — the
 *    screen would post a focus rule for a class the frame does not carry, and
 *    the preview would simply never change.
 */

function crScreen(): string
{
    return (string) file_get_contents(
        resource_path('views/admin/partials/set-appearance-screen.blade.php')
    );
}

/** `id => few-string`, read out of the shipped Blade. */
function crFewLists(): array
{
    $blade = crScreen();
    $out = [];

    preg_match_all("/id:\s*'([a-z_]+)',(.*?)(?=\n    \{|\n  \];)/s", $blade, $m, PREG_SET_ORDER);

    foreach ($m as $group) {
        preg_match("/few:\s*'([^']*)'/", $group[2], $few);
        $out[$group[1]] = $few[1] ?? null;
    }

    return $out;
}

it('opens every crowded section with a handful rather than with all of it', function () {
    $few = crFewLists();

    expect($few)->not->toBe([]);

    foreach ($few as $id => $list) {
        $keys = SetAppearance::TABS['d_'.$id][2] ?? null;

        // The groups are the screen's own names, not the API's, so the size of
        // a section is counted from the table rather than guessed.
        $size = 0;
        foreach (SetAppearance::TABS as $tab) {
            $size = max($size, count($tab[2]));
        }

        if ($list === null) {
            // A section may open whole — but only a small one. "Where the phone
            // sizes start" is three controls and folding it would be theatre.
            continue;
        }

        $named = array_values(array_filter(explode(' ', $list)));

        expect(count($named))->toBeGreaterThan(1, "the `{$id}` section folds to almost nothing");
        expect(count($named))->toBeLessThan(16, "the `{$id}` section's fold hides almost nothing");
    }

    /*
     * The section this lane grew, named rather than inferred: it is the one the
     * owner is in, and the reason this case exists.
     */
    expect($few['cart'] ?? null)->toBeString();
    expect(count(array_filter(explode(' ', (string) $few['cart']))))->toBe(8);
});

it('never promises a control a section cannot draw', function () {
    /*
     * A key in `few` that is not in that section's own cards is a control the
     * fold says it is showing and the screen never renders. It would read as a
     * setting that has silently vanished, which is worse than a long list.
     */
    $blade = crScreen();

    foreach (crFewLists() as $id => $list) {
        if ($list === null) {
            continue;
        }

        // Every key this group places, from its cards — `from:` expands through
        // TABS, `keys:` is explicit.
        preg_match("/id:\s*'{$id}',(.*?)(?=\n    \{|\n  \];)/s", $blade, $body);
        $placed = [];

        preg_match_all("/\bfrom:\s*'([a-z0-9_]+)'/", $body[1] ?? '', $cards);
        foreach ($cards[1] as $tab) {
            foreach (SetAppearance::TABS[$tab][2] as $k) {
                $placed[$k] = true;
            }
        }

        preg_match_all('/\bkeys:\s*\[([^\]]*)\]/s', $body[1] ?? '', $lists);
        foreach ($lists[1] as $chunk) {
            preg_match_all("/'([a-z0-9_]+)'/", $chunk, $keys);
            foreach ($keys[1] as $k) {
                $placed[$k] = true;
            }
        }

        foreach (array_filter(explode(' ', $list)) as $key) {
            expect(array_key_exists($key, SetAppearance::SCHEMA))->toBeTrue(
                "`{$id}` opens with `{$key}`, which is not a setting."
            );
            expect(isset($placed[$key]))->toBeTrue(
                "`{$id}` opens with `{$key}`, which that section does not draw."
            );
        }
    }
});

it('ships folded, and opens folded again on every section change', function () {
    $blade = crScreen();

    expect($blade)->toContain('var detail = false;');

    /*
     * A section opened from the strip resets the fold. Without this line the
     * owner presses "Show all" once in one section and every other section he
     * visits afterwards opens with forty controls — the state he asked to be
     * rid of, reached by using the feature that was meant to fix it.
     */
    expect(preg_match('/group = g\.dataset\.sapGroup;\s*(?:\/\*.*?\*\/\s*)?detail = false;/s', $blade))->toBe(1);

    /*
     * AND THE FOLD BUTTON IS CHECKED BEFORE THE SECTION BUTTONS. The "Show all"
     * control at the foot of a section sits in a card beside the prev/next
     * buttons, which carry data-sap-group — checked the other way round, a
     * press on it would move the owner to the next section instead of
     * unfolding the one he is in.
     */
    $fold = strpos($blade, "t.closest('[data-sap-detail]')");
    $section = strpos($blade, "t.closest('[data-sap-group]')");

    expect($fold)->toBeInt()->and($section)->toBeInt()->and($fold)->toBeLessThan($section);
});

it('draws the cart section as four cards in the order you meet the row', function () {
    /*
     * Twenty-five controls under one heading is a list. These are the parts of
     * the row, going across it: the room around it, the picture, the words, the
     * controls at the foot.
     */
    $blade = crScreen();

    preg_match("/id:\s*'cart',(.*?)(?=\n    \{)/s", $blade, $body);
    $section = $body[1] ?? '';

    foreach (['Room around the row', 'The picture', 'The words'] as $title) {
        expect($section)->toContain("title: '".str_replace("'", "\\'", $title)."'");
    }

    // Four on the laptop tab and four on the phone tab.
    expect(substr_count($section, 'title:'))->toBe(8);
});

it('shows the preview surface the open section is about, and no other', function () {
    /*
     * Sent down the live-overlay channel that already exists rather than as a
     * new message or a new render: the frame filters what arrives to the
     * characters a declaration block is made of, so a class selector and
     * `display:none` survive exactly as a custom property does and nothing new
     * has to be trusted.
     */
    $blade = crScreen();
    $view = (string) file_get_contents(
        resource_path('views/admin/previews/set-appearance.blade.php')
    );

    expect($blade)->toContain('function focusCss()');

    foreach (['cart', 'box', 'list'] as $surface) {
        expect($view)->toContain('sap-line sap-s-'.$surface);
    }

    /*
     * Every section is placed on a surface. A section missing from the map
     * would quietly fall back to showing everything, which is the behaviour
     * this replaces — so the map is total, and that is checked rather than
     * described.
     */
    preg_match('/var FOCUS = \{(.*?)\};/s', $blade, $map);

    foreach (array_keys(crFewLists()) as $id) {
        expect($map[1] ?? '')->toContain($id.':');
    }

    // And the filter the frame applies passes what is being sent.
    $allowed = '/[^-A-Za-z0-9_#.,:;(){}%\s@]/';
    expect(preg_match($allowed, '.sap-s-box{display:none}'))->toBe(0);
});
