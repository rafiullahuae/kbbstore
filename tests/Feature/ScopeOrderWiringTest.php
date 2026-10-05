<?php

declare(strict_types=1);

/**
 * The Reorder screen's one sentence of explanation (Lane SO), shipped as
 * docs/so-wiring.json because resources/views/admin/app.blade.php is the
 * integrator's file.
 *
 * THE DEFECT IT PREVENTS: the screen told the owner "This is the product's
 * real, global sort order ... A product shared across more than one moves
 * everywhere it appears" -- the very behaviour he reported as a bug, and no
 * longer true once each category and brand keeps its own order.
 *
 * Pins the FINISHED state, green before and after the integrator applies it:
 * the JSON is applied in memory where it has not been applied yet, exactly as
 * tools/so-wire.php does, and the result must carry the new sentence once and
 * the old one not at all. MUTATION, RUN: change the anchor in the JSON by one
 * character -> found 0 times, red (before wiring); delete the replacement from
 * a wired app.blade.php -> the old sentence is back, red.
 */
it('says on the Reorder screen that every category and brand keeps its own order', function () {
    $edits = json_decode((string) file_get_contents(base_path('docs/so-wiring.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($edits)->toHaveCount(1);

    $files = [];
    foreach ($edits as $e) {
        $files[$e['file']] ??= (string) file_get_contents(base_path($e['file']));

        if (! str_contains($files[$e['file']], $e['replacement'])) {
            expect(substr_count($files[$e['file']], $e['anchor']))->toBe($e['count'], "block {$e['n']} anchor");
            $files[$e['file']] = str_replace($e['anchor'], $e['replacement'], $files[$e['file']]);
        }
    }

    $app = $files['resources/views/admin/app.blade.php'];

    expect(substr_count($app, "This \${reorderType}'s own order"))->toBe(1)
        ->and($app)->not->toContain('This is the product\'s real, global sort order')
        ->and($app)->not->toContain('moves everywhere it appears');
});
