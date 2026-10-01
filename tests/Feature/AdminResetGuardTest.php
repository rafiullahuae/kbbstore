<?php

/**
 * Every Reset / Restore / Revert / Back to defaults in the admin asks first.
 *
 * THE DEFECT, AS THE OWNER HIT IT (1 October 2026): "when i press any reset /
 * restore option on any page specially under Appearance pages, it should ask
 * me Are you sure? Yes / No ... few times i hit by mistake, and everything
 * messed up and restored." Appearance -> Header -> "Reset to defaults" put
 * every header setting back in one click; so did a dozen other screens. Not
 * one reset button in the admin asked.
 *
 * resources/views/admin/partials/reset-guard.blade.php catches them all in the
 * click capture phase by the start of the control's label. These cases pin:
 *   - the guard is included exactly once, OUTSIDE @verbatim (inside it, the
 *     @include prints as text and nothing is guarded -- the integrator's own
 *     first placement did exactly that);
 *   - every reset-style label written in the admin's views is one the guard's
 *     pattern matches, so a new "Reset ..." button is covered on arrival and a
 *     narrowed pattern goes red here.
 *
 * MUTATIONS, RUN: change the pattern to /^\s*(reset)\b/i and the label case is
 * red on "Back to defaults", "Restore" and "Revert"; move the @include back
 * inside the closing @verbatim block and the placement case is red.
 */
function rgGuardPattern(): string
{
    $guard = (string) file_get_contents(resource_path('views/admin/partials/reset-guard.blade.php'));
    preg_match('#var RESET_LABEL = (/.+?/i);#', $guard, $m);
    expect($m[1] ?? null)->not->toBeNull('the guard no longer declares RESET_LABEL');

    return $m[1].'u';
}

it('includes the guard exactly once, outside every @verbatim block', function () {
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($app, "@include('admin.partials.reset-guard')"))->toBe(1);

    $at = strpos($app, "@include('admin.partials.reset-guard')");
    $before = substr($app, 0, $at);
    // Directives at the start of a line only: the word also appears in comments.
    expect(preg_match_all('/^@verbatim\\b/m', $before))->toBe(preg_match_all('/^@endverbatim\\b/m', $before),
        'the include sits inside @verbatim, so Blade prints it instead of running it');
});

it('renders the dialog on the admin page and not as text', function () {
    $html = view('admin.partials.reset-guard')->render();

    expect($html)->toContain('id="kbbSureBg"')
        ->and($html)->toContain('Are you sure?')
        ->and($html)->toContain('>No</button>')
        ->and($html)->toContain('>Yes</button>')
        ->and($html)->toContain('backdrop-filter:blur(')
        ->and($html)->not->toContain('@verbatim');
});

it('matches every reset, restore and revert label written in the admin', function () {
    $pattern = rgGuardPattern();
    $labels = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views/admin'))) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php') || str_contains($file->getPathname(), 'reset-guard')) {
            continue;
        }

        preg_match_all('#<(?:button|a)\b[^>]*>\s*([^<]*?(?:reset|restore|revert|back to default)[^<]*)<#i', (string) file_get_contents($file->getPathname()), $m);

        foreach ($m[1] as $label) {
            $labels[trim(html_entity_decode($label))] = $file->getFilename();
        }
    }

    // The ones the owner named, so an empty scan cannot pass.
    expect(array_keys($labels))->toContain('Reset to defaults')
        ->and(array_keys($labels))->toContain('Back to defaults');

    foreach ($labels as $label => $file) {
        expect(preg_match($pattern, $label))->toBe(1, "\"{$label}\" in {$file} is not caught by the reset guard");
    }

    // And it leaves ordinary buttons alone.
    foreach (['Save changes', 'Use this total', 'Restored items', 'Preview', 'Reseller'] as $label) {
        expect(preg_match($pattern, $label))->toBe(0, "\"{$label}\" would be asked about");
    }
});
