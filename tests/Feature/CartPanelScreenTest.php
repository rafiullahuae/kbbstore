<?php

declare(strict_types=1);

/*
 * Appearance → Cart panel: the screen's own file, and the console's wiring to it.
 *
 * ── WHY THESE READ THE FILE INSTEAD OF RENDERING THE CONSOLE ────────────────
 *
 * CartPageScreenTest renders /admin and looks for its partial in the output,
 * which is the stronger test and the one to copy — a Blade directive left inside
 * a raw block ships as literal text, `php -l` is green over it because it is not
 * PHP, and rendering is the only thing that catches it.
 *
 * This lane cannot do that yet. The `@include` that puts this partial in the
 * document is app.blade.php's line and CLAUDE.md gives that file to the
 * integrator, so until he adds it there is nothing in the rendered console to
 * look for. The Blade-leak check is therefore made against the file, which
 * catches the same three shapes one step earlier, and the case that pins the
 * include is the handshake with him — see its own comment.
 */

use App\Services\CartPanel;
use App\Services\ModuleSchema;

function cartPanelPartialPath(): string
{
    return resource_path('views/admin/partials/cart-panel-screen.blade.php');
}

function cartPanelPartial(): string
{
    return (string) file_get_contents(cartPanelPartialPath());
}

function cartPanelConsole(): string
{
    return (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

/* ------------------------------------------------------------------------
 | 1. The screen is a file of its own
 |------------------------------------------------------------------------*/

it('keeps the cart panel screen in its own partial', function () {
    /*
     * The move itself. renderCartPanel() and the `cpp-` style block were lines
     * inside a 22,000-line Blade that three lanes edit at once; every other
     * screen extracted for that reason is a file under admin/partials and this
     * is now one of them.
     */
    expect(is_file(cartPanelPartialPath()))->toBeTrue();

    $partial = cartPanelPartial();

    // The screen, the endpoint it reads, and the preview it draws.
    expect($partial)->toContain("var SCREEN = 'cartpanel';")
        ->and($partial)->toContain("/admin-api/cart-panel")
        // The controls, and the mock the previews are drawn from.
        ->and($partial)->toContain('.cpp-wrap{')
        ->and($partial)->toContain('.cpv-panel{');
});

it('reaches the browser as JavaScript rather than as literal Blade', function () {
    /*
     * The defect this exists for, in CartPageScreenTest's words: a previous lane
     * nearly took the whole admin panel down by putting a Blade directive inside
     * a raw block. It shipped as visible text and was a SyntaxError in the
     * script that builds half the console.
     *
     * MUTATION: put `@json([1,2])` anywhere inside this partial's raw block.
     * RED on the first assertion, and nothing else in the suite notices.
     */
    $partial = cartPanelPartial();

    // Everything after the opening of the raw block: the docblock above it is a
    // Blade comment and is allowed to contain whatever it needs to.
    $raw = substr($partial, (int) strpos($partial, '<style>'));

    expect($raw)->not->toContain('@json(')
        ->and($raw)->not->toContain('@if (')
        ->and($raw)->not->toContain('@endif')
        ->and($raw)->not->toContain('@php')
        ->and($raw)->not->toContain('{{');

    /*
     * AND THE RAW BLOCK IS CLOSED. An unclosed one swallows the rest of
     * app.blade.php — every partial included after this one — and the console
     * serves them as text.
     */
    expect(substr_count($partial, '@verbatim'))->toBe(1);
    expect(substr_count($partial, '@endverbatim'))->toBe(1);
    expect(strpos($partial, '@verbatim'))->toBeLessThan((int) strpos($partial, '@endverbatim'));
});

it('registers its own sidebar row and wraps window.go instead of editing the nav arrays', function () {
    $partial = cartPanelPartial();

    // The console's own extension point, the same one Cache, Routines, the cart
    // page and the checkout page use.
    expect($partial)->toContain("group: 'Appearance'")
        ->and($partial)->toContain("label: 'Cart panel'")
        ->and($partial)->toContain('window.kbbAddNavEntry(')
        /*
         * WRAPPED, NOT REPLACED. Nine partials do this and one that forgot to
         * call the handler it replaced would black out every screen registered
         * before it — which is every screen app.blade.php draws itself.
         *
         * MUTATION: delete the `return previousGo.apply(...)` line. The console
         * routes nothing but this screen, and this is red.
         */
        ->and($partial)->toContain('var previousGo = window.go;')
        ->and($partial)->toContain('return previousGo.apply(this, arguments);');
});

/* ------------------------------------------------------------------------
 | 2. The include — the integrator's line, pinned at one
 |------------------------------------------------------------------------*/

it('is included from the admin bundle exactly once', function () {
    /*
     * EXACTLY ONCE, AND NOT "NOT AT ALL".
     *
     * CLAUDE.md is explicit that a lane which cannot edit app.blade.php must not
     * pin the ABSENCE of its own include: `not->toContain(...)` is green in this
     * worktree and goes red the moment the integrator does the one thing the
     * lane asked him for, and the only way to green it as written is to unmount
     * the screen. It happened three times in one day.
     *
     * So this pins the finished state, which is also the state that can regress:
     *
     *   0  built, never wired up. The partial defines window.kbbCartPanelScreen
     *      and wraps window.go, and neither line ever runs — Appearance → Cart
     *      panel keeps drawing app.blade.php's dead copy and every control this
     *      lane added is invisible. This is the shape this repository keeps
     *      finding.
     *   2  the sidebar row is registered twice and, worse, window.go is wrapped
     *      around its own wrapper: two `input` listeners on `document`, so every
     *      drag repaints the preview twice and Save posts twice.
     *
     * THIS CASE IS RED IN THE LANE'S WORKTREE AND THAT IS THE HANDSHAKE, not an
     * oversight. One line greens it, at the end of app.blade.php beside the
     * other partials:
     *
     *     @include('admin.partials.cart-panel-screen')
     *
     * and the same commit deletes the dead renderCartPanel/`cpp-` copy this file
     * replaced. Nothing else in this test file depends on the wiring, so the
     * rest of the screen is under test either way.
     */
    $count = substr_count(cartPanelConsole(), "@include('admin.partials.cart-panel-screen')");

    expect($count)->toBe(
        1,
        "app.blade.php includes admin.partials.cart-panel-screen {$count} times; it must be exactly one. "
        .'Zero means the screen is built and never wired up; two wraps window.go around its own wrapper.'
    );
});

/* ------------------------------------------------------------------------
 | 3. Every stored value has a control, and every control a value
 |------------------------------------------------------------------------*/

it('puts every schema key on exactly one tab', function () {
    /*
     * ModuleFrameworkGuardTest already asserts this for `cart_panel` across the
     * whole framework. It is repeated here in this lane's own file because this
     * is the round that adds twenty-five keys to the schema, and the failure it
     * catches — a key added to SCHEMA and forgotten in TABS — is a setting the
     * shop reads with no control anywhere to write it. Named, so the message
     * says which key rather than that a count did not match.
     */
    $placed = [];

    foreach (CartPanel::TABS as $tab => $spec) {
        foreach ($spec[2] as $key) {
            expect(array_key_exists($key, CartPanel::SCHEMA))->toBeTrue(
                "tab '{$tab}' draws '{$key}', which is not in SCHEMA"
            );
            $placed[$key] = ($placed[$key] ?? 0) + 1;
        }
    }

    foreach (array_keys(CartPanel::SCHEMA) as $key) {
        expect($placed[$key] ?? 0)->toBe(
            1,
            "cartpanel_{$key} appears on ".($placed[$key] ?? 0).' tabs; it must appear on exactly one'
        );
    }
});

it('normalises every field on the shared shape', function () {
    // Every field survives ModuleSchema::field(), which is what the renderer and
    // the cast both read. A malformed row here draws a control that cannot be
    // saved — three selects shipped as sliders on the checkout screen that way.
    foreach (CartPanel::SCHEMA as $key => $def) {
        $field = ModuleSchema::field($key, $def, CartPanel::POLICY);

        expect($field['type'])->toBeIn(['range', 'bool', 'text', 'colour', 'select']);
        expect($field['label'])->not->toBe('');
    }
});
