<?php

declare(strict_types=1);

/**
 * The Reviews screens must not destroy the control the owner is using.
 *
 * FIVE DEFECTS, ALL THE SAME SHAPE, ALL FOUND IN A BROWSER. Every screen in the
 * Reviews group renders itself by writing a whole new document into #content.
 * That is fine for a repaint nobody is touching and fatal for one they are: the
 * element under the caret, or under the finger, is removed and rebuilt.
 *
 *   1. Import / Export. run() re-rendered twice per press, and a file input's
 *      selection cannot be restored from script. The screen's OWN documented
 *      sequence is "Check the file" and then "Import" — and the second press
 *      answered "Choose a CSV file first." with the file name still printed
 *      above the message. Measured in Chromium: after Check, #rio-file.files
 *      .length was 0 while .rio-name still read "import-sample.csv".
 *
 *   2. Assign / Duplicate, review search. Debounced into loadReviews(), which
 *      rendered twice. Typing "Amira", pausing, then typing " H" left the box
 *      reading " HAmira" — the new input is built with the value already in it,
 *      so the caret lands at 0. document.activeElement was BODY.
 *
 *   3. Assign / Duplicate, product search. The same, via loadProducts().
 *
 *   4. Bulk Tools, product search. The same, via load().
 *
 *   5. Bulk Tools, the product checkboxes. Every tick called render().
 *      Playwright's .check() failed with "Element is not attached to the DOM":
 *      the row was rebuilt mid-tap. On a phone that is a tap that does nothing.
 *
 * WHAT THIS FILE CAN AND CANNOT SEE. It reads the shipped source. It cannot run
 * the console — AdminMobileOverflowTest and AdminProductPickerBrowserTest are
 * where a real browser lives, and both are opt-in behind KBB_BROWSER_TESTS. So
 * this pins the STRUCTURE that makes the defect impossible rather than the
 * symptom: the loaders repaint a named sub-region instead of the page, the
 * chosen file is held outside the input, and each screen keeps a keepFocus()
 * around its repaints.
 *
 * Deliberately not asserted by counting the string "render()": every one of
 * these functions still calls render() on the paths where the page really does
 * change shape, and a count would go green the moment somebody renamed it.
 */
function rsrPartial(string $name): string
{
    return (string) file_get_contents(resource_path('views/admin/partials/'.$name.'.blade.php'));
}

function rsrConsole(): string
{
    return (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

/**
 * The body of a JavaScript function in one of these files, braces balanced.
 *
 * Needed because the assertions below are about what ONE function does. Matched
 * over the whole file, "paintPicker()" appears in the function that defines it
 * and in three callers, and an assertion that cannot tell them apart passes
 * against a file where the call was deleted.
 */
function rsrFn(string $src, string $declaration): string
{
    $at = strpos($src, $declaration);

    if ($at === false) {
        return '';
    }

    $i = strpos($src, '{', $at);

    if ($i === false) {
        return '';
    }

    $depth = 0;

    for ($j = $i; $j < strlen($src); $j++) {
        if ($src[$j] === '{') {
            $depth++;
        } elseif ($src[$j] === '}') {
            $depth--;

            if ($depth === 0) {
                return substr($src, $i, $j - $i + 1);
            }
        }
    }

    return '';
}

/* ───────────────── 1. the file the importer was given ───────────────── */

it('keeps the chosen CSV when Check re-renders the screen under it', function () {
    $io = rsrPartial('reviews-io-screen');

    /*
     * The File object, not the input. A File stays valid for as long as
     * anything holds it; the <input> that produced it does not survive
     * render().
     */
    expect(str_contains($io, 'var chosen = null;'))
        ->toBeTrue('the import screen no longer holds the chosen File outside the input element');

    $run = rsrFn($io, 'async function run(mode)');

    expect($run)->not->toBe('', 'run() is gone from the import screen');

    // The live input FIRST — it is still where a new choice comes from — then
    // the held File, which is what survives a repaint.
    expect(str_contains($run, '(input && input.files && input.files[0]) || chosen'))
        ->toBeTrue('run() reads only the input again, so Check-then-Import loses the file');

    // And it is cleared after a real import, not after a check: a checked file
    // is still the file about to be imported.
    expect(str_contains($run, "if (mode === 'import') { chosen = null;"))
        ->toBeTrue('the held file is no longer released after an import, or is released after a check too');
});

it('does not wipe the message that says the import worked', function () {
    $io = rsrPartial('reviews-io-screen');

    /*
     * A successful import reloads the totals, and load() used to clear the
     * banner on the way past — so the one sentence confirming that three
     * thousand reviews had landed was removed a moment after it appeared.
     */
    $load = rsrFn($io, 'async function load(keep)');

    expect($load)->not->toBe('', 'load() no longer takes the keep flag');
    expect(str_contains($load, 'if (!keep) banner = null;'))
        ->toBeTrue('load() clears the banner unconditionally again');
    expect(str_contains(rsrFn($io, 'async function run(mode)'), 'load(true)'))
        ->toBeTrue('the import path no longer asks load() to keep its message');
});

/* ───────────────── 2 & 3. the two Assign search boxes ───────────────── */

it('repaints only the review list when the Assign search runs', function () {
    $ras = rsrPartial('review-assign-screen');

    $load = rsrFn($ras, 'async function loadReviews(keep)');

    expect($load)->not->toBe('', 'loadReviews() is gone from the assign screen');

    /*
     * The "Looking…" repaint, which used to be a full render() fired BEFORE the
     * request even left, is the one that cost the caret most often.
     */
    expect(str_contains($load, 'busy = true;'))->toBeTrue();
    expect(str_contains($load, "busy = true;\n    paintReviews();"))
        ->toBeTrue('loadReviews() renders the whole screen again before its request goes out');

    // And the partial repaint exists and is scoped to the list.
    $paint = rsrFn($ras, 'function paintReviews()');

    expect(str_contains($paint, "document.querySelector('#ras-list')"))
        ->toBeTrue('paintReviews() no longer targets the review list alone');
    expect(str_contains($paint, 'keepFocus('))
        ->toBeTrue('paintReviews() repaints without restoring the caret');
});

it('repaints only the destination hits when the Assign product search runs', function () {
    $ras = rsrPartial('review-assign-screen');

    $load = rsrFn($ras, 'async function loadProducts()');

    expect($load)->not->toBe('', 'loadProducts() is gone from the assign screen');
    expect(str_contains($load, 'paintTargets();'))
        ->toBeTrue('loadProducts() does not repaint the hits on their own');
    expect(str_contains($load, 'render();'))
        ->toBeFalse('loadProducts() renders the whole screen again, which empties the search box');

    $paint = rsrFn($ras, 'function paintTargets()');

    expect(str_contains($paint, "document.querySelector('#ras-hits')"))
        ->toBeTrue('paintTargets() no longer targets the destination list alone');
});

it('gives the two Assign search boxes a timer each', function () {
    $ras = rsrPartial('review-assign-screen');

    /*
     * One shared timer meant typing in the product box cancelled a pending
     * review search and the other way round. The two boxes are on one screen
     * and an owner uses them one after the other, so the first search simply
     * never ran.
     */
    $debounce = rsrFn($ras, 'function debounce(key, fn)');

    expect($debounce)->not->toBe('', 'debounce() takes one argument again — the two boxes share a timer');
    expect(str_contains($debounce, 'timers[key]'))
        ->toBeTrue('debounce() keeps one timer again, so one search box cancels the other');

    expect(str_contains($ras, "debounce('reviews', loadReviews)"))
        ->toBeTrue('the review search no longer names its own timer');
    expect(str_contains($ras, "debounce('products', loadProducts)"))
        ->toBeTrue('the product search no longer names its own timer');
});

it('chooses a destination product without redrawing the page', function () {
    $ras = rsrPartial('review-assign-screen');

    $bind = rsrFn($ras, 'function bindTargets()');

    expect($bind)->not->toBe('', 'bindTargets() is gone from the assign screen');
    expect(str_contains($bind, "classList.toggle('ras-on'"))
        ->toBeTrue('picking a destination no longer toggles the highlight in place');
    expect(str_contains($bind, 'render();'))
        ->toBeFalse('picking a destination renders the whole screen again, emptying both search boxes');
});

/* ───────────────── 4 & 5. Bulk Tools, search and ticking ───────────────── */

it('repaints only the picker when the Bulk Tools product search runs', function () {
    $rbk = rsrPartial('review-bulk-screens');

    $load = rsrFn($rbk, 'async function load()');

    expect($load)->not->toBe('', 'load() is gone from the bulk screen');
    expect(str_contains($load, "busy = true;\n    paintPicker();"))
        ->toBeTrue('load() renders the whole screen again before its request goes out');

    /*
     * The search input must live OUTSIDE everything paintPicker() replaces.
     * That is the property, not the repaint: an input inside the repainted
     * region loses focus however carefully the repaint is written.
     */
    $picker = rsrFn($rbk, 'function picker()');

    expect(str_contains($picker, 'id="rbk-search"'))
        ->toBeTrue('the picker no longer draws the search box outside the repainted list');
    expect(str_contains($picker, 'id="rbk-picklist"'))
        ->toBeTrue('the picker no longer gives its rows a container of their own to repaint');

    $paint = rsrFn($rbk, 'function paintPicker()');

    expect(str_contains($paint, "document.querySelector('#rbk-picklist')"))
        ->toBeTrue('paintPicker() no longer targets the row list alone');
    expect(str_contains($paint, "id=\"rbk-search\""))
        ->toBeFalse('paintPicker() writes the search box again, so it is replaced under the caret');
    expect(str_contains($paint, 'keepFocus('))
        ->toBeTrue('paintPicker() repaints without restoring the caret');
});

it('ticks a product without rebuilding the row under the finger', function () {
    $rbk = rsrPartial('review-bulk-screens');

    $bind = rsrFn($rbk, 'function bindPicks()');

    expect($bind)->not->toBe('', 'bindPicks() is gone from the bulk screen');

    // Two patches, no repaint of the list the checkbox sits in.
    expect(str_contains($bind, 'paintChips();'))
        ->toBeTrue('a tick no longer patches the selected-product chips in place');
    expect(str_contains($bind, 'repaintCounts();'))
        ->toBeTrue('a tick no longer patches the planned-rows counter in place');
    expect(str_contains($bind, 'render();'))
        ->toBeFalse('a tick renders the whole screen again, which takes the checkbox out from under the finger');

    // Removing a chip unticks its row in place rather than rebuilding the list.
    $unpick = rsrFn($rbk, 'function bindUnpick()');

    expect(str_contains($unpick, "document.querySelector('[data-rbk-pick=\"' + id + '\"]')"))
        ->toBeTrue('removing a chip no longer unticks the matching row in place');
    expect(str_contains($unpick, 'render();'))
        ->toBeFalse('removing a chip renders the whole screen again');
});

/* ───────────────── the sidebar count ───────────────── */

it('never prints a review count that was typed in by hand', function () {
    $console = rsrConsole();

    /*
     * NAV carried the All Reviews row as ['rev-all','All Reviews','<path…>','3'].
     * navItemHTML() prints a fourth element as a count chip and buildNav() sums
     * the numeric ones into the group badge, so the sidebar said "3" beside All
     * Reviews and "3" beside Reviews while twelve were waiting — and said 3 on a
     * shop with none waiting at all. A number in a menu is a promise that it is
     * the number.
     */
    preg_match("/\['rev-all','All Reviews',('(?:[^'\\\\]|\\\\.)*')(,'([^']*)')?\]/", $console, $m);

    expect($m)->not->toBeEmpty('the All Reviews NAV row has changed shape — recheck this guard');
    expect($m[3] ?? null)->toBeNull('the All Reviews row carries a hard-coded count chip again');

    // And the real one is fetched.
    $badge = rsrPartial('review-queue-badge');

    expect(str_contains($badge, "'/reviews/list?per_page=1'"))
        ->toBeTrue('the queue badge no longer reads the count from the moderation endpoint');
    expect(str_contains($badge, 'counts.pending'))
        ->toBeTrue('the queue badge no longer reads the pending count specifically');

    // It must be included, or it is a file that runs nowhere.
    expect(str_contains($console, "@include('admin.partials.review-queue-badge')"))
        ->toBeTrue('the queue badge partial is not included by the console');
});

/* ───────────────── what the admin claims about a reply ───────────────── */

it('does not promise that a reply is published when the shop never prints it', function () {
    /*
     * MEASURED, not reasoned about. Store\ProductController selects
     * id, author_name, rating, title, content, verified, created_at, images and
     * helpful into the collection the product page renders — not `reply` — and
     * partials/reviews.blade.php contains the word nowhere. A reply typed into
     * All Reviews is stored, shown on that screen and carried by both CSV
     * exports, and appears on no product page.
     *
     * The modal used to invite one with the placeholder "Shown publicly under
     * the review…".
     */
    $controller = (string) file_get_contents(app_path('Http/Controllers/Store/ProductController.php'));
    $partial = (string) file_get_contents(resource_path('views/partials/reviews.blade.php'));

    $shopPrintsReply = str_contains($partial, 'reply');

    expect(str_contains($controller, "'verified', 'created_at', 'images', 'helpful'"))
        ->toBeTrue('the storefront review query has changed — recheck whether it now carries the reply');

    $console = rsrConsole();

    if ($shopPrintsReply) {
        // Someone landed the storefront half. Then the admin must stop
        // apologising for it — and this branch is how they find that line.
        expect(str_contains($console, 'The product page does not print replies yet'))
            ->toBeFalse('the shop prints replies now; drop the note in the reply modal that says it does not');

        return;
    }

    expect(str_contains($console, 'placeholder="Shown publicly under the review'))
        ->toBeFalse('the reply box promises the reply is published, and the product page does not print it');

    expect(str_contains($console, 'The product page does not print replies yet'))
        ->toBeTrue('the reply box no longer says that replies are not published');
});

/* ───────────────── the merged screen stays reachable ───────────────── */

it('still opens Bulk Tools from the id whose sidebar row was merged away', function () {
    $rbk = rsrPartial('review-bulk-screens');

    /*
     * FOUND BY DRIVING IT, not by reading it. Merging the two rows broke the
     * 'rev-likes' bookmark twice over, and both halves are pinned here.
     *
     * 1. The console's go() highlights the row whose data-go matches the id.
     *    'rev-likes' has no row now, so NOTHING was highlighted — and both
     *    render() and bootIfCurrent() decide whether to paint by looking for
     *    a highlighted row. ?go=rev-likes painted an empty screen.
     *
     * 2. The boot block at the end of the first script navigates ?go= and #
     *    BEFORE this partial is parsed, so the partial cannot learn what was
     *    asked for from its own go() wrapper on a cold load. It reads the
     *    address itself.
     *
     * Verified in Chromium after the fix: /admin?go=rev-likes and
     * /admin#rev-likes both open Bulk Tools with the Helpful votes tab active
     * and the Bulk Tools row highlighted.
     */
    expect(str_contains($rbk, 'var ROW = ADD;'))
        ->toBeTrue('the partial no longer names the single row both ids live under');

    // The highlight follows the ROW, not the id that was asked for.
    expect(str_contains($rbk, "b.classList.toggle('on', b.dataset.go === ROW);"))
        ->toBeTrue("go('rev-likes') highlights an id that has no sidebar row, so nothing is highlighted");

    // And a cold load reads the address rather than the (absent) highlight.
    $requested = rsrFn($rbk, 'function requested()');

    expect($requested)->not->toBe('', 'the partial no longer reads ?go= / # for itself');
    expect(str_contains($requested, "get('go')"))->toBeTrue();
    expect(str_contains($requested, 'window.location.hash'))->toBeTrue();

    $boot = rsrFn($rbk, 'function bootIfCurrent()');

    expect(str_contains($boot, 'var asked = requested();'))
        ->toBeTrue('the boot path ignores what the address bar asked for');
    expect(str_contains($boot, 'screen = asked || active.dataset.go;'))
        ->toBeTrue('a ?go=rev-likes bookmark no longer opens on the Helpful votes tab');
});

it('does not throw away the sentence that says the move worked', function () {
    $ras = rsrPartial('review-assign-screen');

    /*
     * A move or a copy re-reads the review list, and that reload cleared the
     * banner it had just put up. Driven in Chromium: "Moved 1 review." was on
     * screen for about as long as the request took and then was not.
     */
    expect(str_contains($ras, 'async function loadReviews(keep)'))
        ->toBeTrue('loadReviews() takes no keep flag, so a reload clears the message above it');
    expect(str_contains($ras, 'loadReviews(true);'))
        ->toBeTrue('the move/copy path no longer asks the reload to keep its message');
});
