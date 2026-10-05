<?php

declare(strict_types=1);

/*
 * Store -> Analytics drew nothing: anCardHead() called `sescHtml(title)`, a
 * function that never existed (a merge joined the local `sesc` and the
 * global `escHtml`), so the first card header threw "sescHtml is not
 * defined" and the screen stopped. Found by lane AP's 94-screen click-through.
 * MUTATION: put `sescHtml(title)` back -> red.
 */
it('calls only escaping helpers that exist in the analytics screen', function () {
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect($app)->not->toContain('sescHtml(')
        ->and($app)->toContain("'<div class=\"an-sec-t\">'+sesc(title)+'</div>'")
        ->and($app)->toContain('function sesc(v){');
});
