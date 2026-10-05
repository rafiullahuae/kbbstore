<?php

declare(strict_types=1);

use App\Services\PageHeaders;

/*
 * A long title in a Pages → Page header / category custom header, on a phone.
 * The H1 inherits `.sh h1{display:flex;flex-wrap:wrap}`, so a name like
 * "Sunscreens for the UAE sun" became a flex item of its own row: the dot sat
 * alone ABOVE the words and "41 products" alone BELOW them (Lane CH's shot 4 at
 * 390, header 248px tall). Inline text keeps the dot on the first line and the
 * count after the last word (187px).
 *
 * Mutation: delete the `.kbb-ph-t{display:block}` rule and the first case is red.
 */
it('sets a page header title as inline text on a phone, so a long name does not strand the dot and the count', function () {
    $phone = substr(PageHeaders::CSS, strpos(PageHeaders::CSS, '@media (max-width:900px)'));
    $phone = substr($phone, 0, strpos($phone, '@media (min-width:901px)'));

    expect($phone)->toContain('.kbb-home .kbb-ph>.kbb-ph-t{display:block}')
        ->and($phone)->toContain('.kbb-home .kbb-ph>.kbb-ph-t>.cnt{display:inline-block;')
        ->and($phone)->toContain('white-space:nowrap');
});

it('leaves the laptop title alone', function () {
    $laptop = substr(PageHeaders::CSS, strpos(PageHeaders::CSS, '@media (min-width:901px)'));

    expect($laptop)->not->toContain('.kbb-ph-t{display:block}');
});
