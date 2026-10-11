<?php

declare(strict_types=1);

/**
 * A LOCKED MARKETING EMAIL SAYS SO, AND OFFERS THE COPY. (Lane RO2)
 *
 * The owner, 11 October: "the made with love line, i can't edit, when i edit,
 * it resets the same." The campaign had started, so the builder was
 * read-only -- but its boxes still took typing and change() quietly threw it
 * away, under a bare "Read-only." Now the boxes are greyed out inside a
 * disabled <fieldset>, the note says why in plain words, and "Duplicate to
 * edit" makes the editable copy right there.
 *
 * MUTATION, RUN: return `dis + h` from blockForm() again and the first
 * expectation is red.
 */
it('greys out a locked email\'s boxes and puts Duplicate to edit beside the reason', function () {
    $src = file_get_contents(resource_path('views/admin/partials/marketing-emails-screens.blade.php'));

    expect(substr_count($src, "'<fieldset class=\"mke-ro\" disabled>' + h + '</fieldset>'"))->toBe(2)
        ->and($src)->toContain('function lockedNote(b) {')
        ->and($src)->toContain('>Duplicate to edit</button>')
        ->and($src)->toContain("(b.kind === 'template' ? 'tdup' : 'dup')")
        ->and($src)->toContain('This campaign has been sent, so it can no longer be changed.')
        ->and($src)->not->toContain("'<div class=\"mke-h\">Read-only. '")
        // The two duplicate actions it points at are real handlers.
        ->and($src)->toContain("else if (act === 'dup') {")
        ->and($src)->toContain("else if (act === 'tdup') {");
});
