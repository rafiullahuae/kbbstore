<?php

declare(strict_types=1);

/**
 * Bullets hanging outside a blog post's text column. (16 October 2026)
 *
 * What it looked like on the shop: on /blog/pdrn-skincare-what-is-it-does-it-
 * actually-work/ the bullets of "Hyaluronic acid, Peptides, ..." sat to the
 * left of every paragraph, outside the column, because a list in .abody had
 * no start indent. Measured in Chromium (tools/blg-list.cjs): 16px outside at
 * 390 and 1280, English and Arabic; 0 with the rule below.
 *
 * MUTATION: delete the :is(ul,ol) rule -- red.
 */
it('indents blog lists on the start side so their bullets stay inside the column', function () {
    $view = (string) file_get_contents(resource_path('views/store/post.blade.php'));

    expect($view)->toContain('.kbb-journal .abody :is(ul,ol){padding-inline-start:1.4em;margin:0 0 18px}');
});
