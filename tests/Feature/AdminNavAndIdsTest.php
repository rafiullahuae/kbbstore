<?php

declare(strict_types=1);

/**
 * One sidebar entry per screen, and no two elements sharing an id.
 *
 * The owner applied a package containing a brand-new coupon editor, opened the
 * sidebar item called "Coupons", got the old read-only usage report, and
 * reasonably told me the editor had not shipped. It had — under a second entry
 * called "Manage Coupons" directly beneath it. Two entries whose names do not
 * tell you which one does the thing is a worse outcome than the missing screen
 * it replaced.
 *
 * The second half is the same class of mistake one level down. While fixing
 * the above I added `id="cu-back"` to the usage screen without noticing the id
 * was already in use by the button that clears a coupon's detail view.
 * document.querySelector returns the FIRST match, so the existing handler would
 * have silently been given my button and that feature would have broken with no
 * error anywhere. Caught by reading, not by running — which is exactly why it
 * is pinned here.
 */
/**
 * Ids that legitimately appear twice in a source file because the two
 * occurrences are MUTUALLY EXCLUSIVE renders of the same screen — a form state
 * and a confirmation state, say. Only one is ever in the document, so
 * querySelector cannot pick the wrong one.
 *
 * A static scan cannot tell those apart from a real collision, so each is
 * listed by hand with its reason. Keep this list short: every entry is a place
 * the check has been switched off.
 */
const ALTERNATIVE_RENDERS = [
    // The New Order screen draws either the order form or, once the order has
    // been placed, the "created" panel. Never both.
    'manual-order-screen.blade.php#moScreen',
];

function adminScreenSources(): array
{
    return glob(base_path('resources/views/admin/partials/*.blade.php')) ?: [];
}

it('never renders the same element id twice in one admin screen', function () {
    $offenders = [];

    foreach (adminScreenSources() as $file) {
        $body = (string) file_get_contents($file);

        // Only literal id="..." in rendered markup. Ids built by interpolation
        // ('id="' + x + '"') are per-row and out of scope for a static check.
        preg_match_all('/\bid="([a-zA-Z][\w-]*)"/', $body, $m);

        $counts = array_count_values($m[1]);

        foreach ($counts as $id => $n) {
            if ($n > 1 && ! in_array(basename($file) . '#' . $id, ALTERNATIVE_RENDERS, true)) {
                $offenders[] = basename($file) . ": id=\"{$id}\" appears {$n} times";
            }
        }
    }

    expect($offenders)->toBe(
        [],
        "two elements share an id, so querySelector will hand the wrong one to its handler:\n  "
        . implode("\n  ", $offenders)
    );
});

it('gives the coupon screens exactly one sidebar entry between them', function () {
    /*
     * Specifically the pair that went wrong. The editor takes the "Coupons"
     * name and position; the usage report is reached from a link inside it and
     * registers nothing in the sidebar.
     */
    $editor = (string) file_get_contents(
        base_path('resources/views/admin/partials/coupon-editor-screen.blade.php')
    );
    $usage = (string) file_get_contents(
        base_path('resources/views/admin/partials/coupon-usage-screen.blade.php')
    );

    expect(str_contains($editor, "<span>Coupons</span>"))
        ->toBeTrue('the coupon editor no longer claims the "Coupons" sidebar entry');

    expect(str_contains($editor, '<span>Manage Coupons</span>'))
        ->toBeFalse('the second "Manage Coupons" sidebar entry is back');

    // The usage screen's registration must be inert, whatever it is called.
    expect(preg_match('/function addNavEntry\(\)\s*\{\s*return;/', $usage))
        ->toBe(1, 'the coupon usage screen is adding a sidebar entry again, which is the duplicate the owner hit');

    // And both directions between the two screens still exist.
    expect(str_contains($editor, "window.go('coupon-usage')"))
        ->toBeTrue('there is no way through to the usage report now that it has no sidebar entry');
    expect(str_contains($usage, "window.go('coupon-editor')"))
        ->toBeTrue('there is no way back from the usage report');
});
