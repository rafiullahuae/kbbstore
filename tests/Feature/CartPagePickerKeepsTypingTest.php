<?php

declare(strict_types=1);

/**
 * =============================================================================
 * THE PRODUCT PICKER MUST KEEP WHAT THE OWNER TYPED
 * =============================================================================
 *
 * `search()` ends by calling `render()`, which rebuilds the entire screen —
 * including the search box. The box was drawn with no `value`, so 220ms after
 * every keystroke it emptied itself.
 *
 * Reported as "it's not letting me write anything", which is exactly how it
 * behaved: you could type a letter, watch it vanish, and type it again forever.
 * Nothing errored and the endpoint was working the whole time.
 *
 * Two halves, and both are needed. Without the `value` the text is gone; with
 * the value but without the caret restored, `focus()` on a freshly created
 * input puts the caret at position 0 and the next character types itself in
 * front of the word.
 *
 * MUTATION: drop `value="…"` from the input. Red.
 */
function cppkSource(): string
{
    return (string) file_get_contents(base_path('resources/views/admin/partials/cart-page-screen.blade.php'));
}

it('draws the search box with whatever was typed into it', function () {
    $src = cppkSource();

    expect(preg_match('/id="cps-q"[^>]*value="\' \+ esc\(term\) \+ \'"/', $src))->toBe(
        1,
        'the picker search box is rendered without its value, so every re-render empties it'
    );

    /*
     * And the term must live OUTSIDE render(). A `var term` local to the input
     * handler is the shape this bug had: correct at the moment of typing and
     * gone by the time anything redrew.
     */
    $fnStart = (int) strpos($src, 'async function search(');
    $decl = (int) strpos($src, "var term = '';");

    expect($decl)->not->toBe(0, 'there is no module-level term to render');
    expect($decl)->toBeLessThan($fnStart, 'the term is declared inside search(), not outside it');
});

it('puts the caret back at the end after a redraw', function () {
    /*
     * focus() on a newly created input lands the caret at 0. With the value
     * restored but the caret not, typing "toner" gives you "renot" — which
     * reads as a different bug and would have been reported as one.
     */
    $src = cppkSource();

    expect(str_contains($src, 'q.setSelectionRange(q.value.length, q.value.length)'))->toBeTrue(
        'the caret is not restored, so each keystroke after a redraw lands at the front of the word'
    );

    // Guarded, because setSelectionRange throws on some input types.
    $block = substr($src, (int) strpos($src, "querySelector('#cps-q')"), 400);

    expect(str_contains($block, 'try {'))->toBeTrue(
        'setSelectionRange is unguarded; it throws on input types that do not support selection, '
        .'which would take the whole screen down rather than mis-place a caret'
    );
});

it('searches for what was typed, not for whatever the box holds now', function () {
    /*
     * The debounce fires 220ms later. If the request read the term back off the
     * live variable rather than the argument it was called with, a fast typist
     * would search for a prefix of their own word — intermittently, and only on
     * a slow connection.
     */
    $src = cppkSource();

    $fn = substr($src, (int) strpos($src, 'async function search('));
    $fn = substr($fn, 0, 700);

    expect(str_contains($fn, "encodeURIComponent(t)"))->toBeTrue(
        'search() sends something other than the term it was called with'
    );
});

it('shows the chosen products as a list, above the search, in rail order', function () {
    /*
     * The owner: "currently just check boxes green showing, which i can't find
     * in 1000s of products, the selected ones."
     *
     * He was right. A tick inside a search result is only visible while that
     * exact search is on screen — type a new term and the evidence that
     * anything is chosen disappears. The panel's job is to answer "what is in
     * the rail", and it could not.
     *
     * Drawn FIRST, so the answer is above the question.
     *
     * MUTATION: move the picked block below the search fields. Red.
     */
    $src = cppkSource();

    $fn = substr($src, (int) strpos($src, 'function pickerHTML('));
    $fn = substr($fn, 0, (int) strpos($fn, "\n  }\n", (int) strpos($fn, 'cps-card')));

    expect(str_contains($fn, 'cps-picked-wrap'))->toBeTrue('the chosen products are not drawn as their own block');

    expect(strpos($fn, 'cps-picked-wrap'))->toBeLessThan(
        (int) strpos($fn, 'id="cps-q"'),
        'the chosen list is drawn below the search box, so it is off screen exactly when a long '
        .'result list pushes it there'
    );

    // The count, because "is anything chosen" should not require counting rows.
    expect(str_contains($fn, "In the rail <b>' + chosen.length"))->toBeTrue(
        'the panel no longer says how many products are in the rail'
    );
});

it('lets the owner order the rail, and disables the arrows that would do nothing', function () {
    /*
     * "give products sorting function there to control which product should
     * display on which place in the rail."
     *
     * `chosen` IS the order — CartPage::recommended() reorders the queried rows
     * against the stored list — so moving a row here is the whole feature and
     * there is no second sort field that could fall out of step.
     *
     * MUTATION: delete the data-cps-up branch from the click handler. Red.
     */
    $src = cppkSource();

    expect(str_contains($src, 'data-cps-up='))->toBeTrue('there is no way to move a product earlier');
    expect(str_contains($src, 'data-cps-down='))->toBeTrue('there is no way to move a product later');

    $handler = substr($src, (int) strpos($src, "var up = e.target.closest('[data-cps-up]')"));
    $handler = substr($handler, 0, 700);

    expect(str_contains($handler, 'chosen.splice(from, 1)'))->toBeTrue('the move does not reorder chosen');

    /*
     * Bounds, not wrap-around. Moving the first product "earlier" must do
     * nothing rather than send it to the end, which is what an unguarded
     * splice(-1) would do — silently, and only for the row a careless finger
     * hits first.
     */
    expect(str_contains($handler, 'to >= 0 && to < chosen.length'))->toBeTrue(
        'the move is unbounded: moving the first product up would wrap it to the end'
    );

    expect(str_contains($src, "(first ? ' disabled' : '')"))->toBeTrue(
        'the arrow that cannot move anything is still live'
    );
});
