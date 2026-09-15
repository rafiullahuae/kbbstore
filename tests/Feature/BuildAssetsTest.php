<?php

/*
 * A migration in this project relocates assets and removes public/build as a
 * side effect. Any `git add -A` after running migrations therefore stages the
 * deletion of every compiled asset, and it has happened: the 2.60.109 bump
 * committed exactly that, which silently dropped the rebuilt bundle carrying
 * the quick-view handler out of the package.
 *
 * These assert against git's tracked content rather than the working tree, for
 * two reasons: the working tree is unreliable here precisely because running
 * this suite deletes those files, and what ships is what is committed. A
 * package is built from `git show`, so committed content is the thing that
 * matters.
 */

function tracked(string $path): ?string
{
    $out = shell_exec('git -C '.escapeshellarg(base_path()).' show HEAD:'.escapeshellarg($path).' 2>/dev/null');

    return ($out === null || $out === '') ? null : $out;
}

it('has every asset the Vite manifest references committed', function () {
    $manifestRaw = tracked('public/build/manifest.json');

    expect($manifestRaw)->not->toBeNull('public/build/manifest.json is not committed — assets were probably deleted by a migration and staged with git add -A.');

    $manifest = json_decode((string) $manifestRaw, true);
    expect($manifest)->toBeArray()->not->toBeEmpty();

    $missing = [];
    foreach ($manifest as $entry) {
        foreach (array_merge([$entry['file'] ?? null], $entry['css'] ?? []) as $file) {
            if ($file !== null && tracked('public/build/'.$file) === null) {
                $missing[] = $file;
            }
        }
    }

    expect($missing)->toBe([], 'Assets referenced by the manifest are not committed: '.implode(', ', $missing));
});

it('ships a bundle containing the quick-view handler', function () {
    // Quick view existed for months with a button, a modal, CSS, a route and a
    // controller, and nothing in the shipped JavaScript listening for it. The
    // handler living in resources/js is not enough: public/build is what the
    // server serves, so the built bundle is what has to carry it.
    $files = preg_split('/\R/', (string) shell_exec(
        'git -C '.escapeshellarg(base_path()).' ls-files public/build/assets 2>/dev/null'
    )) ?: [];

    $found = false;
    foreach ($files as $file) {
        if ($file !== '' && str_ends_with($file, '.js') && str_contains((string) tracked($file), 'data-kbb-qv')) {
            $found = true;
            break;
        }
    }

    expect($found)->toBeTrue('No committed bundle contains the quick-view handler — resources/js changed without rebuilding public/build.');
});

/** Byte offsets of every occurrence of $needle in $haystack. */
function positionsOf(string $haystack, string $needle): array
{
    $at = [];
    $from = 0;
    while (($pos = strpos($haystack, $needle, $from)) !== false) {
        $at[] = $pos;
        $from = $pos + 1;
    }

    return $at;
}

/** Every committed bundle under public/build/assets, as git has it. */
function trackedBundles(): array
{
    $files = preg_split('/\R/', (string) shell_exec(
        'git -C '.escapeshellarg(base_path()).' ls-files public/build/assets 2>/dev/null'
    )) ?: [];

    $out = [];
    foreach ($files as $file) {
        if ($file !== '' && str_ends_with($file, '.js')) {
            $out[$file] = (string) tracked($file);
        }
    }

    return $out;
}

it('ships a bundle containing the mobile summary and Browsed handlers', function () {
    // "View full summary" and the Browsed pill are the checkout's two mobile
    // controls, and both live in resources/js/kbb/checkout.js. Same reasoning
    // as the quick-view case above: the built bundle is what the server serves.
    $needles = [
        'kbbSummary' => 'the mobile "View full summary" toggle',
        'data-stab' => 'the Order summary / Browsed pill tabs',
    ];

    foreach ($needles as $needle => $what) {
        $found = false;
        foreach (trackedBundles() as $source) {
            if (str_contains($source, $needle)) {
                $found = true;
                break;
            }
        }

        expect($found)->toBeTrue(
            "No committed bundle contains {$needle} — {$what} shipped without rebuilding public/build."
        );
    }
});

it('toggles the summary open class on the element the checkout CSS selects', function () {
    /*
     * The bug this pins was not a missing listener. Both handlers existed, in
     * resources/js AND in the shipped bundle — the expander simply put `open`
     * on #kbbPanels while every rule that implements the expansion is written
     * against the summary:
     *
     *     .kbb-checkout .summary.open .panels   { max-height:1600px }
     *     .kbb-checkout .summary.open .peekfade { display:none }
     *
     * So the class landed on an element no selector matches, the panel stayed
     * clipped at its 148px peek, and both mobile controls read as dead: the
     * Browsed tab swaps panels inside that same clipped box, so it could only
     * ever show the first item and a half of the list.
     *
     * Asserting the two halves agree is the point. A handler that toggles a
     * class nothing styles passes every "is it wired up" check there is.
     */
    $css = (string) tracked('resources/css/kbb/kbb-checkout.css');
    $js = (string) tracked('resources/js/kbb/checkout.js');
    $blade = (string) tracked('resources/views/store/checkout.blade.php');

    expect(str_contains($css, '.summary.open .panels'))->toBeTrue(
        'The checkout CSS no longer keys the expansion off .summary.open — this test and the JS need to move with it.');

    // The element carrying the class has to be the .summary aside itself.
    expect(str_contains($blade, '<aside class="summary" id="kbbSummary">'))->toBeTrue(
        'The summary aside is no longer #kbbSummary, so the toggle target has drifted from the CSS.');

    expect((bool) preg_match("/getElementById\('kbbSummary'\)\??\.classList\.toggle\('open'\)/", $js))->toBeTrue(
        'The mobile expander must toggle `open` on #kbbSummary — the CSS selects .summary.open, so toggling it anywhere else (it used to be #kbbPanels) is a live handler with no visible effect.');

    /*
     * The committed bundle has to agree, not just the source. Matched by
     * proximity rather than by an exact string: the minifier renames locals and
     * lowers `?.` differently between versions, but the toggle stays within a
     * few dozen characters of the id it looks up.
     */
    $inBundle = false;
    foreach (trackedBundles() as $source) {
        foreach (positionsOf($source, 'kbbSummary') as $at) {
            if (str_contains(substr($source, $at, 140), 'classList.toggle("open")')) {
                $inBundle = true;
                break 2;
            }
        }
    }

    expect($inBundle)->toBeTrue('No committed bundle toggles `open` on #kbbSummary — the fix is in resources/js but public/build was not rebuilt.');
});

it('ships a bundle that adds from the checkout Browsed tab without opening the drawer', function () {
    /*
     * Same reasoning as the two above, and the same bug one turn further on.
     *
     * checkout.js used to look for `.badd` with `data-add`. The markup has
     * always rendered `.baddbtn` with `data-kbb-add`, so that branch never ran
     * once; what actually added the product was cart.js's global listener,
     * which opens the cart drawer. Correct on a product card, wrong on the
     * checkout, and indistinguishable from a dead handler in every test that
     * only asks whether a listener exists somewhere.
     *
     * Three strings, because all three have to be in the SHIPPED bundle for a
     * tap to do anything at all:
     *
     *   - the attribute checkout.js now binds;
     *   - the id of the Browsed list, which carries the endpoint URL and is
     *     also what cart.js checks before declining to open the drawer;
     *   - one of the slots the response is swapped into — a handler that fires
     *     and repaints nothing is precisely the failure being pinned.
     */
    $needles = [
        'data-kbb-checkout-add' => 'the checkout Browsed add handler',
        'kbbBrowsedList' => 'the Browsed list id (the endpoint URL, and cart.js declining the drawer)',
        'kbb-order-slot' => 'the totals/free-delivery region the response is swapped into',
    ];

    foreach ($needles as $needle => $what) {
        $found = false;
        foreach (trackedBundles() as $source) {
            if (str_contains($source, $needle)) {
                $found = true;
                break;
            }
        }

        expect($found)->toBeTrue(
            "No committed bundle contains {$needle} — {$what} shipped without rebuilding public/build."
        );
    }
});

it('leaves the drawer alone for every add outside the checkout Browsed list', function () {
    /*
     * The scope of the change, pinned from the other side. cart.js still has to
     * open the drawer for product cards, the shop grid, quick view and the PDP;
     * only #kbbBrowsedList is declined. If that guard is ever widened — to
     * `[data-kbb-add]` generally, say — adding to the cart goes silent across
     * the whole site and nothing else in this suite would notice.
     */
    $js = (string) tracked('resources/js/kbb/cart.js');

    expect($js)->toContain("add.closest('#kbbBrowsedList')");

    // The guard must sit inside the data-kbb-add branch and before the drawer
    // is opened, or it declines nothing.
    $branch = strpos($js, "closest('[data-kbb-add]')");
    $guard = strpos($js, "add.closest('#kbbBrowsedList')");
    $opens = strpos($js, 'const data = await addToCart(');

    expect($branch)->not->toBeFalse()
        ->and($guard)->not->toBeFalse()
        ->and($opens)->not->toBeFalse()
        ->and($guard)->toBeGreaterThan($branch)
        ->and($guard)->toBeLessThan($opens);
});
