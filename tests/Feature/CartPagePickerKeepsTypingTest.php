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
