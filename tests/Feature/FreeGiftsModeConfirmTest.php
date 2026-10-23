<?php

declare(strict_types=1);

/*
 * MARKETING -> FREE GIFTS: OFF / TESTING / LIVE ASK YES OR NO FIRST. (Lane PD)
 *
 * What it looked like (owner, 23 October): "for free gifts enable, test off,
 * all these three buttons will ask yes / no confirmation." One tap on a mode
 * card switched gifts for every shopper at once, with nothing in between.
 *
 * Mutation note: drop the card's data-kbb-sure and this is red; the click then
 * reaches run() with no question asked (partials/reset-guard only asks for a
 * control that carries one).
 */
it('asks Yes / No on Off, Testing and Live before the click saves', function () {
    $js = (string) file_get_contents(resource_path('views/admin/partials/free-gifts-screen.blade.php'));

    expect($js)->toContain("'data-kbb-sure': mode === m[0] ? null : m[2] + ' Switch free gifts to ' + m[1] + '?'")
        ->and(substr_count($js, "api('POST', '/switch'"))->toBe(1);
});
