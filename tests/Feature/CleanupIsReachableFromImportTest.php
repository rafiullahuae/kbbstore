<?php

/**
 * The pre-migration clean-up has a way in, and exactly one.
 *
 * THE DEFECT, AS THE OWNER MET IT: the clean-up page shipped in 2.60.336 at
 * /admin-api/cleanup/page, its route mounted, its capability granted -- and
 * nothing anywhere in the admin console linked to it. The release notes, and
 * then the instructions he was given, said "Store → Import → Clean up before
 * the migration". He opened Store Import / Export, scrolled the whole page, and
 * wrote back "i can not see any clean up option". It was the step before his
 * import, and the only way to reach it was typing an address nobody had told
 * him.
 *
 * Pinned as the FINISHED state, per CLAUDE.md: the card is drawn exactly once
 * and its link exists exactly once. Zero is the defect; two would draw the card
 * twice. MUTATION: delete `+impCleanupCard()` from the import screen's render
 * and this is red.
 */
it('draws the clean-up card once on Store Import / Export, linking to the clean-up page', function () {
    // Counted on the raw file, not comment-stripped: app.blade.php carries
    // strings like `image/*` that a /*...*/ stripper reads as the start of a
    // comment and swallows real code with -- it erased this very function on
    // the first run. None of the three needles appears in a comment.
    $code = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($code, '+impCleanupCard()'))->toBe(1, 'the import screen does not draw the clean-up card exactly once')
        ->and(substr_count($code, "impBase()+'/cleanup/page"))->toBe(1)
        ->and(substr_count($code, 'function impCleanupCard()'))->toBe(1);

    // And it is FIRST among the cards, because it is the step before upload.
    expect(strpos($code, '+impCleanupCard()'))->toBeLessThan(strpos($code, '+impFilesCard(s)'));

    // The address it opens is the one the cleanup routes actually register.
    $routes = (string) file_get_contents(base_path('routes/cleanup-admin.php'));
    expect($routes)->toContain("Route::get('/cleanup/page'");
    expect(substr_count((string) file_get_contents(base_path('routes/web.php')), "require __DIR__.'/cleanup-admin.php';"))->toBe(1);
});
