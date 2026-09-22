<?php

declare(strict_types=1);

use App\Services\CartPage;

/**
 * =============================================================================
 * EVERY TAB ON THE CART PAGE SCREEN DRAWS WHAT THAT TAB CONTROLS
 * =============================================================================
 *
 * The screen shipped with sixty-nine sliders and an empty right-hand side. The
 * owner asked three times for a preview, and he was right to: a control whose
 * effect you cannot see is a control you have to guess at, save, open the shop
 * on a phone, and come back to. Sixty-nine times.
 *
 * It is a DRAWING, not the real cart. Rendering the storefront inside the
 * console would mean an authenticated fetch per keystroke.
 *
 * MUTATION: delete the previewHTML() call from render(). Red.
 */
function cppSource(): string
{
    return (string) file_get_contents(base_path('resources/views/admin/partials/cart-page-screen.blade.php'));
}

it('draws a preview for every tab the screen offers', function () {
    $src = cppSource();

    /*
     * Read off CartPage::TABS rather than a list written here, so a tab added
     * later cannot arrive without one. That is the failure this guards: the
     * preview is not missing loudly, it is just absent on the one new tab.
     */
    $start = strpos($src, 'function previewHTML()');
    $fn = substr($src, (int) $start, (int) strpos($src, 'function paintPreview(', (int) $start) - (int) $start);

    foreach (array_keys(CartPage::TABS) as $tab) {
        // 'layout' is the else branch: it draws the whole page.
        if ($tab === 'layout') {
            continue;
        }

        expect(str_contains($fn, "'{$tab}'"))->toBeTrue(
            "the tab '{$tab}' has no branch in previewHTML(), so it shows whatever the fallback draws "
            .'— which is not what its controls change'
        );
    }
});

it('repaints while the slider is moving, not after it is let go', function () {
    /*
     * On `input`, and NOT by re-rendering the screen. Watching the rows squeeze
     * under your thumb is the whole point; a preview that redraws on `change`
     * tells you what you already did.
     *
     * And it must repaint the preview NODE only. render() rebuilds the
     * controls, which destroys the range input mid-drag and stops the drag
     * dead — the thing the owner would be doing when he most wants to see it.
     *
     * MUTATION: swap paintPreview() for render() in the input handler. Red on
     * the second assertion.
     */
    $src = cppSource();

    $start = (int) strpos($src, "document.addEventListener('input'");
    $handler = substr($src, $start, (int) strpos($src, "document.addEventListener('click'", $start) - $start);

    expect(str_contains($handler, 'paintPreview();'))->toBeTrue(
        'nothing repaints the preview while a control is being dragged'
    );

    expect(str_contains($handler, 'render();'))->toBeTrue(
        'the select branch no longer redraws; it must, because a select changes which fields exist'
    );

    // The repaint must replace the preview only.
    $paint = substr($src, (int) strpos($src, 'function paintPreview('));
    $paint = substr($paint, 0, 600);

    expect(str_contains($paint, "querySelector('#cps-preview')"))->toBeTrue(
        'paintPreview() does not target the preview node, so it is redrawing more than it should'
    );

    expect(str_contains($paint, 'render()'))->toBeFalse(
        'paintPreview() calls render(), which rebuilds the controls and kills the drag'
    );
});

it('sizes the drawing from the same custom properties the shop reads', function () {
    /*
     * --h, --per, --ah, --ch and the rest come straight from the saved values,
     * and every size in the preview is a calc() off them — the same arithmetic
     * cart-squeeze.blade.php does. A preview that computed its own sizes would
     * be a second implementation, and the one nobody checks is the one that
     * drifts until it is confidently wrong.
     */
    $src = cppSource();

    foreach (['--h:', '--per:', '--ah:', '--ch:', '--bf:', '--sf:'] as $var) {
        expect(str_contains($src, $var))->toBeTrue("the preview no longer carries {$var}");
    }

    expect(substr_count($src, 'calc(var(--h)'))->toBeGreaterThan(
        3,
        'the preview has stopped deriving its sizes from the row height and is computing them some other way'
    );
});

it('shows both address-popup caps, because neither is visible anywhere else', function () {
    /*
     * sheet_max_list and sheet_max are two numbers whose only effect is how far
     * up the screen a sheet stops. Without the preview an owner sets them,
     * saves, opens the shop on a phone, adds something to a basket, taps the
     * address bar — and only then finds out. Both are drawn, one above the
     * other, on the tab that owns them.
     */
    $src = cppSource();

    expect(str_contains($src, "pvSheet('list') + pvSheet('form')"))->toBeTrue(
        'the address tab no longer draws both the list cap and the form cap'
    );

    $sheet = substr($src, (int) strpos($src, 'function pvSheet('));
    $sheet = substr($sheet, 0, 400);

    expect(str_contains($sheet, 'sheet_max_list'))->toBeTrue('the list cap is not read');
    expect(str_contains($sheet, "pvNum('sheet_max'"))->toBeTrue('the form cap is not read');
});

it('says so when the shop is still on the classic layout', function () {
    /*
     * The preview draws what SQUEEZED would look like. With layout=classic --
     * which is what every shop ships with -- that is a picture of a page the
     * shop is not serving, and a preview believed to be live is worse than
     * none.
     */
    expect(str_contains(cppSource(), 'The shop is still on <b>Classic</b>'))->toBeTrue(
        'the preview no longer says it is drawing a layout the shop is not using'
    );
});

/* =============================================================================
 * THE RAIL'S TYPOGRAPHY KNOBS ALL MOVE THE DRAWING
 * =============================================================================
 *
 * Twice this week a slider shipped that saved, reported success and moved
 * nothing on the preview, and both times the owner reported it as broken —
 * correctly. Seven new controls landed on the Recommended tab; each one is
 * pinned here to the custom property pvVars() emits AND to the preview rule
 * that reads it, because either half alone is a slider that does nothing.
 */
it('moves the drawn rail with every new typography and + control', function () {
    $src = cppSource();

    $vars = substr($src, (int) strpos($src, 'function pvVars()'));
    $vars = substr($vars, 0, (int) strpos($vars, 'function pvRows('));

    // Half one: the property is emitted, from the saved value, with the shipped
    // default as its fallback.
    foreach ([
        "'--rpw:' + (pvOn('rec_price_bold')",
        "'--rlh:' + (pvNum('rec_lh', 125)",
        "'--rg:' + pvNum('rec_gap', 2)",
        "'--rig:' + pvNum('rec_img_gap', 5)",
        "'--ras:' + (pvNum('rec_add_size', 100)",
        "'--rax:' + pvNum('rec_add_x', 0)",
        "'--ray:' + pvNum('rec_add_y', 0)",
    ] as $emit) {
        expect(str_contains($vars, $emit))->toBeTrue(
            "pvVars() does not emit {$emit} — the control saves and the drawing does not move"
        );
    }

    // Half two: a rule in the preview stylesheet actually reads each one.
    foreach (['var(--rpw', 'var(--rlh', 'var(--rg', 'var(--rig', 'var(--ras', 'var(--rax', 'var(--ray'] as $read) {
        expect(str_contains($src, $read))->toBeTrue(
            "nothing in the preview stylesheet reads {$read}, so the property is emitted into a vacuum"
        );
    }
});
// MUTATION: delete the '--ras:' line from pvVars(). RED on the first loop.
// MUTATION: change `.cpv-rc .p` back to font-weight:var(--rw). RED on the
// second loop — nothing reads --rpw.

it('draws the preview card with the same arithmetic the shop card uses', function () {
    /*
     * Not the same numbers — the drawing is smaller than a phone — but the same
     * SHAPE, so a knob that behaves one way in the console cannot behave another
     * way in the shop. The name's box is two line-heights in both. The + is a
     * multiplier on its size and an offset added to the corner it is pinned to,
     * in both.
     */
    $src = cppSource();

    expect(str_contains($src, 'height:calc(var(--rlh,1.25) * 2em)'))->toBeTrue(
        'the drawn name still has a fixed height, so opening the line height crops it here '
        .'and not in the shop'
    );

    expect(str_contains($src, 'width:calc(15px * var(--ras,1))'))->toBeTrue(
        'the drawn + is a fixed size, so the size control moves nothing on the preview'
    );

    expect(str_contains($src, 'inset-inline-end:calc(-2px + var(--rax,0px))'))->toBeTrue(
        'the drawn + is pinned to a fixed corner, so the horizontal nudge moves nothing'
    );

    expect(str_contains($src, 'bottom:calc(-2px + var(--ray,0px))'))->toBeTrue(
        'the drawn + has no vertical nudge'
    );
});
// MUTATION: put `width:15px;height:15px` back on .cpv-rc .pl. RED on the
// second assertion.
