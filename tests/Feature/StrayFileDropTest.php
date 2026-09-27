<?php

declare(strict_types=1);

/**
 * A file dropped on the gaps between the drop zones must not take the page with
 * it.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Found by Lane P2 driving Chromium, and it is data loss rather than a
 * nuisance. Let a JPEG go two pixels outside a zone — on the arrange toolbar,
 * on the padding between the two media cards — and no handler claims it, so the
 * BROWSER handles it: it navigates to the file. The half-filled product form
 * goes with it, unsaved, and the owner is looking at a photograph in a tab
 * where his console used to be.
 *
 * Every zone in this console swallows its own drops. A zone can only cover the
 * pixels it occupies, and the gaps belong to nobody. This pins the floor under
 * all of them.
 */
function sfdConsole(): string
{
    static $s = null;

    return $s ??= (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

/** Comments stripped: the prose below names these very identifiers. */
function sfdCode(): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', sfdConsole());
}

/*
 * MUTATION: delete the listener block and this is red. RUN: red.
 */
it('refuses the browser the chance to open a file dropped on the console', function () {
    $code = sfdCode();

    expect($code)->toContain("['dragover','drop'].forEach")
        ->and($code)->toContain('e.preventDefault();');

    /*
     * BOTH events, and dragover is not the optional one. Without a prevented
     * `dragover` the drop event never fires at all — so a guard on `drop`
     * alone would look right and stop nothing.
     */
    foreach (['dragover', 'drop'] as $name) {
        expect($code)->toContain("'".$name."'");
    }
});

/*
 * MUTATION: drop the `draggingFiles` check so the guard prevents EVERY drag,
 * and this is red.
 *
 * It matters: the product editor reorders its gallery by dragging tiles, and
 * the shoppable-video editor reorders tagged products the same way. A blanket
 * preventDefault would break both, and it would break them silently — the drag
 * would simply stop working, with nothing in the console.
 */
it('only swallows a drag that is carrying files, so a reorder still works', function () {
    $code = sfdCode();

    expect($code)->toContain('function draggingFiles(e)')
        ->and($code)->toContain("if(draggingFiles(e)) e.preventDefault();");

    // The predicate is a real test of the payload, not a constant.
    expect($code)->toContain("String(t[i]).toLowerCase()==='files'");
});

/*
 * The floor has to be equal to the kit's own predicate, or a file that a zone
 * accepts is one the floor lets through, or the reverse.
 *
 * MUTATION: change either to match 'file' instead of 'files' and this is red.
 */
it('agrees with the upload kit about what a file drag is', function () {
    $kit = (string) file_get_contents(resource_path('views/admin/partials/upload-kit.blade.php'));

    $kitCode = (string) preg_replace('#/\*.*?\*/#s', '', $kit);

    /*
     * The floor is deliberately a SEPARATE copy rather than a call into the
     * kit: it runs at the foot of the console's own boot, before any partial
     * has defined window.kbbUpload, and a floor that waits for a later include
     * is not a floor. The duplication is the price, and this is what stops the
     * two drifting.
     */
    foreach ([$kitCode, sfdCode()] as $source) {
        expect($source)->toMatch("/toLowerCase\(\)\s*===\s*'files'/");
    }
});

/*
 * And it is installed once, at the top level, rather than per screen — a
 * per-screen guard is absent on exactly the screens nobody thought about.
 *
 * MUTATION: wrap the block in a renderX() so it only arms on one screen and
 * this is red.
 */
it('installs the floor once, for the whole console', function () {
    $code = sfdCode();

    expect(substr_count($code, "['dragover','drop'].forEach"))->toBe(1);

    // On `document`, not on a screen container that is replaced on every
    // navigation — a listener on #content dies with the first repaint.
    expect($code)->toMatch("/document\.addEventListener\(name,/");
});
