<?php

declare(strict_types=1);

/**
 * The owner chooses how many tiles go across, in both grids.
 *
 * Twelve images at "Auto" is two short rows on a wide screen; the owner asked
 * to be able to say 6, 8 or 10 and see more at once. It is a way of looking at
 * the library rather than a property of anything in it, so it never reaches the
 * server — which is also why neither grid re-renders to apply it.
 */
function mediaPickerSrc(): string
{
    return file_get_contents(base_path('resources/views/admin/partials/media-picker.blade.php'));
}

function mediaScreenSrc(): string
{
    return file_get_contents(base_path('resources/views/admin/partials/media-library-screen.blade.php'));
}

it('offers the same column choices in the picker and on the library screen', function () {
    foreach ([mediaPickerSrc(), mediaScreenSrc()] as $src) {
        expect($src)->toContain("var COLS = ['auto', 4, 6, 8, 10];");
    }
});

it('applies the column count without re-rendering either grid', function () {
    /*
     * The load-bearing detail. A repaint to change a column count would drop
     * the operator's ticked selection in the picker and their scroll position
     * in both — and on the library screen it would cost a request as well.
     * Both set a custom property on the existing grid element instead.
     */
    expect(mediaPickerSrc())->toContain("g.style.setProperty('--mp-cols', String(cols));")
        ->and(mediaScreenSrc())->toContain("g.style.setProperty('--mlib-cols', String(cols));");

    foreach ([mediaPickerSrc(), mediaScreenSrc()] as $src) {
        expect($src)->toContain('minmax(0,1fr))');
    }
});

it('falls back to auto-fill rather than to a broken grid', function () {
    /*
     * Auto is the absence of the property, not a value — so a browser that
     * never receives one, or an operator who never chooses, gets exactly the
     * grid this screen had before the chooser existed.
     */
    expect(mediaPickerSrc())->toContain("g.style.removeProperty('--mp-cols');")
        ->and(mediaScreenSrc())->toContain("g.style.removeProperty('--mlib-cols');");

    expect(mediaPickerSrc())->toContain('grid-template-columns:repeat(auto-fill,minmax(140px,1fr))');
});

it('does not reuse a class name that already means something else', function () {
    /*
     * .mlib-cols was already taken on the library screen for the detail
     * dialog's two-column layout. Reusing it would have made every column
     * button a two-column grid and the dialog a row of buttons — so the
     * chooser is .mlib-colpick. Both must still be present and distinct.
     */
    $screen = mediaScreenSrc();

    expect($screen)->toContain('.mlib-colpick{display:flex')
        ->and($screen)->toContain('.mlib-cols{display:grid');
});

it('reads and writes the choice per browser, never to the server', function () {
    foreach ([[mediaPickerSrc(), 'kbb.mp.cols'], [mediaScreenSrc(), 'kbb.mlib.cols']] as [$src, $key]) {
        expect($src)->toContain("localStorage.getItem('{$key}')")
            ->and($src)->toContain("localStorage.setItem('{$key}'");

        /*
         * Every access wrapped: localStorage throws rather than returning null
         * in a private window and with site data blocked, and a throw here
         * would take the whole screen down for a display preference.
         */
        expect(substr_count($src, 'catch (e) { return \'auto\'; }'))->toBeGreaterThan(0);
    }
});

it('gives the picker the filters the library screen has', function () {
    /*
     * The owner asked for the same filters in both. The endpoint already took
     * all of them, so this needed no backend change — which is exactly why the
     * risk here is a control that renders and is never sent.
     */
    $picker = mediaPickerSrc();

    foreach (['mp-attached', 'mp-owner', 'mp-from', 'mp-to', 'mp-clear'] as $id) {
        expect(str_contains($picker, $id))->toBeTrue("the picker has no {$id}");
    }

    foreach (["'attached=' + encodeURIComponent(filt.attached)",
              "'attached_q=' + encodeURIComponent(filt.owner)",
              "'from=' + encodeURIComponent(filt.from)",
              "'to=' + encodeURIComponent(filt.to)"] as $sent) {
        expect(str_contains($picker, $sent))->toBeTrue("a filter renders but is never sent: {$sent}");
    }
});

it('lets the owner search by product name without choosing a kind first', function () {
    /*
     * Typing a product name is the most direct thing an owner can do — they
     * know the product, not which KIND of thing owns the image. Requiring a
     * "Used by" choice first was a step that existed only because the query
     * was built that way. With no kind chosen, both send attached=any.
     *
     * Still refused for "unused", where it contradicts itself: an image
     * nothing uses has no owner whose name could match.
     */
    foreach ([mediaPickerSrc(), mediaScreenSrc()] as $src) {
        expect($src)->toContain("attached=any");
    }

    expect(mediaScreenSrc())->toContain("if (state.attached !== 'unused' && state.attached_q) {")
        ->and(mediaScreenSrc())->toContain("var ownerable = state.attached !== 'unused';");

    expect(mediaPickerSrc())->toContain("if (filt.owner && filt.attached !== 'unused') {");
});
