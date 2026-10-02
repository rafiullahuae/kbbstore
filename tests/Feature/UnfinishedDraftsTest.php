<?php

use App\Models\AdminUser;
use Illuminate\Support\Str;

/**
 * "Unfinished (N)" in the admin top bar, and no "leave and lose them?" popups.
 *                                                                    (Lane PM)
 *
 * THE DEFECT, IN THE OWNER'S WORDS (1 October 2026): "when now i leave
 * anything un-saved, it keep giving me weired popup, i want if i leave
 * anything, on admin top bar there should be proper log or custom where i can
 * see all my leaved things, and i can go directly there incase i couldn't find
 * or forget, and can complete. it should not give me the weired warning like
 * thing."
 *
 * What he met: window.confirm("You have 3 unsaved change(s) to this set …
 * Leave this screen and lose them?") on Banners, Set appearance and Grid
 * sections; confirm("You have unsaved reorder changes on this page. Discard
 * them?") on Catalog → Reorder; the browser's "Leave site?" on a refresh. And
 * on Header, Product styles, Site search, Mobile menu, Cart panel, Checkout
 * page and a dozen more, the opposite: nothing at all, and the edits gone.
 *
 * resources/views/admin/partials/unfinished-drafts.blade.php is the registry
 * every screen hands its unsaved buffer to. These cases pin:
 *   - it is included exactly once, OUTSIDE @verbatim, and BEFORE the screen
 *     partials that register with it as they load;
 *   - the four screens that asked no longer ask, by confirm() or beforeunload;
 *   - every covered screen registers, says when it has loaded, and says when
 *     it has saved — and each function its adapter calls still exists;
 *   - every label reaches the page as text, never as HTML;
 *   - Discard asks "Are you sure?" through the reset guard;
 *   - drafts are per admin, per console path, 14 days, and never a secret.
 *
 * The behaviour itself is driven in Chromium by tools/pm-drafts-shots.cjs and
 * tools/pm-drafts-sweep.cjs; the lane report carries the numbers.
 */
function udRead(string $path): string
{
    return (string) file_get_contents(resource_path('views/admin/'.$path));
}

function udApp(): string
{
    return udRead('app.blade.php');
}

function udRegistry(): string
{
    return udRead('partials/unfinished-drafts.blade.php');
}

/** The registry's JavaScript only, without the Blade comment above it. */
function udRegistryScript(): string
{
    $src = udRegistry();

    return substr($src, (int) strpos($src, '<script>'));
}

/** The text of one JS function, from its declaration to its closing brace at the same indent. */
function udFunction(string $src, string $signature, string $indent = '  '): string
{
    $from = strpos($src, $signature);
    expect($from)->not->toBeFalse("{$signature} is gone");

    $to = strpos($src, "\n{$indent}}\n", (int) $from);

    return substr($src, (int) $from, ($to === false ? strlen($src) : $to) - (int) $from);
}

/** The screens app.blade.php draws itself: id => what the adapter calls in that file. */
function udAppScreens(): array
{
    return [
        'header' => ['let HD=null', 'function paintHeader(', 'id="hdSave"', 'id="hdDirty"'],
        'prodstyles' => ['let PS=null', 'function paintProdStyles(', 'id="psSave"', 'id="psDirty"'],
        'acctpanel' => ['let AP=null', 'function apPaint(', 'id="apSave"', 'id="apDirty"'],
        'search' => ['let SS=null', 'function paintSiteSearch(', 'id="ssSave"', 'id="ssDirty"'],
        'mobilemenu' => ['let MM=null', 'function paintMobileMenu(', 'id="mmSave"', 'id="mmDirty"'],
        'newsletter' => ['let NL=null', 'function paintNewsletter(', 'id="nlSave"', 'id="nlDirty"'],
        'mobilehdr' => ['let MH=null', 'function paintMobileHdr(', 'id="mhSave"', 'id="mhDirty"'],
        'dividers' => ['let DV=null', 'function paintDividers(', 'id="dvSave"', 'id="dvDirty"'],
        'productpage' => ['let PP = null', 'function paintProductPage(', 'function ppFields(', 'PPDIRTY', 'id="ppSave"'],
        'homepage' => ['let HP=null', 'function paintHomepage(', 'id="hpSave"', 'id="hpDirty"'],
        'reorder' => ['let reorderType=', 'function reorderPaint(', 'async function reorderSave(', 'function reorderLocalMove('],
    ];
}

/** The partial screens: file => the id it registers under. */
function udPartialScreens(): array
{
    return [
        'banners-screen' => "'banners'",
        'grid-sections-screen' => "'gridsections'",
        'set-appearance-screen' => "'setap'",
        'cart-panel-screen' => 'SCREEN',
        'cart-page-screen' => 'SCREEN',
        'checkout-page-screen' => 'SCREEN',
        'page-wash-screen' => 'SCREEN',
        'slim-footer-screen' => 'SCREEN',
        'site-layout-screen' => 'SCREEN',
        'security-screen' => 'SCREEN',
        'ugc-appearance-screen' => 'SCREEN',
        'review-settings-screen' => 'SCREEN',
        'review-badges-screen' => 'SCREEN',
        // The product editor (applied by the integrator once Lane PK merged and
        // the owner approved): "You have unsaved changes. Leave without saving?"
        // is gone from it; an unsaved product is kept in Unfinished.
        'product-editor-screen' => "'product'",
    ];
}

/* ═══════════════════════════ 1. the include ═══════════════════════════════ */

it('includes the registry exactly once, outside @verbatim, before the screens that register with it', function () {
    /*
     * Inside @verbatim the @include prints as text and nothing is tracked. And
     * AFTER a screen partial, that screen's `window.kbbDrafts.track(...)` runs
     * while window.kbbDrafts is still undefined: its `if (window.kbbDrafts)`
     * guard skips registration and the screen is silently never covered.
     *
     * MUTATIONS, run (tools/pm-mutate.py, which runs every note in this file):
     * moved the @include below banners-screen and the third
     * expectation is red; moved it inside the preceding @verbatim block and
     * the second is red.
     */
    $app = udApp();
    $include = "@include('admin.partials.unfinished-drafts')";

    expect(substr_count($app, $include))->toBe(1);

    $at = (int) strpos($app, $include);
    $before = substr($app, 0, $at);
    expect(preg_match_all('/^@verbatim\b/m', $before))->toBe(preg_match_all('/^@endverbatim\b/m', $before),
        'the include sits inside @verbatim, so Blade prints it instead of running it');

    foreach (array_keys(udPartialScreens()) as $partial) {
        $screenAt = strpos($app, "@include('admin.partials.{$partial}')");
        expect($screenAt)->not->toBeFalse("{$partial} is no longer included");
        expect($at)->toBeLessThan((int) $screenAt, "the registry is included after {$partial}, which then registers with nothing");
    }
});

it('renders the button, the list and the bar on the admin page, not as text', function () {
    $owner = AdminUser::create([
        'name' => 'UD owner', 'email' => 'ud-'.Str::random(8).'@example.test',
        'password' => bcrypt('secret'), 'role' => 'owner',
    ]);

    $html = test()->actingAs($owner, 'admin')->get('/admin')->assertOk()->getContent();

    expect(substr_count($html, 'id="kbbUnfinished"'))->toBe(1)
        ->and($html)->toContain('data-u="'.$owner->id.'"')
        ->and($html)->toContain('id="kbbDraftsBtn"')
        ->and($html)->toContain('Unfinished (0)')
        ->and($html)->toContain('Kept in this browser only')
        ->and($html)->toContain('id="kbbDraftBar"')
        ->and($html)->toContain('window.kbbDrafts = {')
        ->and($html)->not->toContain("@include('admin.partials.unfinished-drafts')");
});

it('never names an element kbbDrafts, which the browser would hand out as window.kbbDrafts', function () {
    /*
     * The first build did exactly this: <div id="kbbDrafts">. A browser makes
     * every id a property of window, so `window.kbbDrafts` WAS the div, the
     * registry's own "already installed?" check returned early, and every
     * screen's track() threw "window.kbbDrafts.track is not a function" —
     * twenty-eight errors on load in Chromium and nothing tracked anywhere.
     *
     * MUTATION, run: renamed the wrapper back to id="kbbDrafts" and this is red.
     */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views/admin'))) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        expect((string) file_get_contents($file->getPathname()))
            ->not->toMatch('/\bid=["\']kbbDrafts["\']/', $file->getFilename().' gives an element the id kbbDrafts');
    }
});

/* ═════════════════════ 2. the popups are gone ═════════════════════════════ */

it('no longer asks before leaving Banners, Set appearance, Grid sections or Catalog → Reorder', function () {
    /*
     * MUTATIONS, run: put `return window.confirm('You have ' + changed().length
     * + …` back into grid-sections' mayLeave() and the confirm expectation is
     * red naming that file; put the beforeunload listener back into Banners and
     * the beforeunload expectation is red; put confirm('You have unsaved reorder
     * changes on this page. Discard them?') back into reorderConfirmDiscard()
     * and the last expectation is red.
     */
    foreach (['banners-screen' => 'banners', 'grid-sections-screen' => 'gridsections', 'set-appearance-screen' => 'setap'] as $partial => $id) {
        $src = udRead("partials/{$partial}.blade.php");
        $leave = udFunction($src, 'function mayLeave(');

        expect(str_contains($leave, 'confirm('))->toBeFalse("{$partial}: leaving asks again");
        expect(str_contains($leave, "window.kbbDrafts.flush('{$id}')"))->toBeTrue("{$partial}: leaving no longer keeps the draft");
        expect(str_contains($src, "addEventListener('beforeunload'"))->toBeFalse("{$partial}: a refresh asks again");
        expect(str_contains($src, 'lose them?'))->toBeFalse("{$partial}: still words a leave question");
    }

    $reorder = udFunction(udApp(), 'function reorderConfirmDiscard(', '');
    expect($reorder)->not->toContain('confirm(')
        ->and($reorder)->toContain("kbbDrafts.flush('reorder')")
        ->and($reorder)->toContain('return true;');
});

it('has no beforeunload prompt anywhere in the admin', function () {
    /*
     * The draft is written as it is typed, and again on pagehide, so there is
     * nothing for a "Leave site?" box to protect. One left anywhere is the
     * owner's popup back on every refresh.
     */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views/admin'))) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        expect((string) file_get_contents($file->getPathname()))
            ->not->toMatch("/addEventListener\\(\\s*['\"]beforeunload/", $file->getFilename().' prompts on leaving the page');
    }
});

it('keeps each screen’s own Discard working', function () {
    /*
     * Leaving no longer asks; DISCARDING still does, in the screen's own words,
     * because it throws work away on purpose. These are the buttons the brief
     * said to keep.
     */
    expect(udRead('partials/banners-screen.blade.php'))->toContain("var discard = document.querySelector('#bns-discard');")
        ->and(udRead('partials/grid-sections-screen.blade.php'))->toContain("var discard = document.querySelector('#gss-discard');")
        ->and(udRead('partials/set-appearance-screen.blade.php'))->toContain("if (t.closest('[data-sap-discard]')) {");
});

/* ════════════════ 3. every covered screen is wired, three ways ════════════ */

it('wires every screen app.blade.php draws to the registry: an adapter, ready() and saved()', function () {
    /*
     * Three halves, and a screen missing any one is broken in its own way:
     *   no adapter   track() never ran — leaving loses the edits, silently
     *   no ready()   no baseline — nothing is ever counted as a change
     *   no saved()   the row outlives the save and Open "restores" what is
     *                already saved
     *
     * MUTATIONS, run: deleted `if(window.kbbDrafts) kbbDrafts.saved('header');`
     * and this is red on header's saved(); deleted the `schema({ id: 'search'`
     * block and it is red on search's adapter; renamed paintMobileMenu in
     * app.blade.php and it is red on the needle check.
     */
    $app = udApp();
    $registry = udRegistryScript();

    foreach (udAppScreens() as $id => $needles) {
        expect(preg_match("/(schema\\(\\{|D\\.track\\(\\{)\\s*id: '".preg_quote($id, '/')."'/", $registry))
            ->toBe(1, "no adapter for '{$id}' in unfinished-drafts.blade.php");

        expect(substr_count($app, "kbbDrafts.ready('{$id}')"))->toBe(1, "'{$id}' never says it has loaded");
        expect(substr_count($app, "kbbDrafts.saved('{$id}')"))->toBeGreaterThanOrEqual(1, "'{$id}' never says it has saved");

        foreach ($needles as $needle) {
            expect(str_contains($app, $needle))->toBeTrue("the '{$id}' adapter relies on `{$needle}`, which app.blade.php no longer has");
        }
    }
});

it('wires every partial screen to the registry: track(), ready() and saved()', function () {
    /*
     * Same three halves as above, for the screens that live in their own
     * partials and register from inside their own closure.
     *
     * MUTATION, run (tools/pm-mutate.py): removed the ready(SCREEN) line from
     * cart-panel-screen and this is red naming it.
     */
    foreach (udPartialScreens() as $partial => $id) {
        $src = udRead("partials/{$partial}.blade.php");

        expect(substr_count($src, 'window.kbbDrafts.track({'))->toBe(1, "{$partial} does not register");
        // The product editor has three ways onto a product -- a new one from
        // start(), one loaded by loadProduct(), and the picker's Add product --
        // and each says it has loaded. Every other screen has one.
        $readies = $partial === 'product-editor-screen' ? 3 : 1;
        expect(substr_count($src, "window.kbbDrafts.ready({$id})"))->toBe($readies, "{$partial} never says it has loaded");
        expect(substr_count($src, "window.kbbDrafts.saved({$id})"))->toBeGreaterThanOrEqual(1, "{$partial} never says it has saved");
    }
});

it('clears a reloaded screen’s row when its own Discard reloads it', function () {
    /*
     * Product page and Homepage DISCARD BY RELOADING. Without discarded() the
     * reload's ready() finds the row still stored and puts every discarded
     * change straight back — Discard would undo itself.
     *
     * MUTATION, run: removed `kbbDrafts.discarded('homepage');` and this is red.
     */
    $app = udApp();

    expect($app)->toContain("if(e.target.id==='ppDiscard'){ PPDIRTY={sections:false,layout:false,also:false}; if(window.kbbDrafts) kbbDrafts.discarded('productpage'); renderProductPage(); return; }")
        ->and($app)->toContain("if(e.target.id==='hpDiscard'){ if(window.kbbDrafts) kbbDrafts.discarded('homepage'); renderHomepage(); return; }");
});

/* ════════════════════════ 4. text, never HTML ═════════════════════════════ */

it('prints every label as text', function () {
    /*
     * A label is a banner set's name, a grid's name, a category's name — words
     * the owner typed. The preview shot puts a set called
     * `Spring <b>sale</b> & "more"` in the list and reads it back as those
     * characters, with no <b> element. This pins the construction that makes
     * that true: the registry never assigns HTML at all.
     *
     * MUTATION, run: built the row with `li.innerHTML = '<b>' + d.label + …`
     * and this is red.
     */
    $js = udRegistryScript();

    foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'DOMParser'] as $html) {
        expect(str_contains($js, $html))->toBeFalse("the registry writes HTML with {$html}");
    }

    expect($js)->toContain('b.textContent = String(d.label')
        ->and($js)->toContain('BAR_TEXT.textContent =')
        ->and($js)->toContain('BAR_HEAD.textContent =');
});

/* ══════════════════════ 5. Discard asks first ═════════════════════════════ */

it('asks "Are you sure?" before Discard through the reset guard, and not before anything else', function () {
    /*
     * Discard throws away work nothing else holds, so it asks — through the
     * same blurred "Are you sure?" box the reset guard draws, opted in by
     * data-kbb-sure because the word Discard is not one the guard's label rule
     * knows. The rule itself is untouched.
     *
     * And no OTHER control of this lane may start with Reset / Restore /
     * Revert: "Restore them" was the obvious label for putting a draft back,
     * and the guard would have asked "Are you sure?" before giving him his own
     * work.
     *
     * MUTATION, run: removed the `asks` opt-in from the guard and the second
     * expectation is red; labelled the bar's button "Restore my changes" and
     * the loop is red.
     */
    $guard = udRead('partials/reset-guard.blade.php');
    $js = udRegistryScript();

    expect($guard)->toContain('var RESET_LABEL = /^\s*(reset|restore|revert|back to defaults?)\b/i;')
        ->and($guard)->toContain("var asks = el.getAttribute('data-kbb-sure');")
        ->and($guard)->toContain('if (!asks && !RESET_LABEL.test(label)) return;')
        ->and($guard)->toContain('text.textContent = asks');

    expect(substr_count($js, "x.setAttribute('data-kbb-sure',"))->toBe(1)
        ->and(substr_count($js, "BAR_B.setAttribute('data-kbb-sure',"))->toBe(1);

    preg_match('#var RESET_LABEL = (/.+?/i);#', $guard, $m);
    preg_match_all("/textContent = '([^']+)'/", $js, $labels);
    $labels = array_merge($labels[1], ['Open', 'Discard', 'Save', 'Use my changes']);

    foreach ($labels as $label) {
        expect(preg_match($m[1].'u', $label))->toBe(0, "\"{$label}\" would make the reset guard ask before it");
    }
});

/* ═══════════════════ 6. per admin, 14 days, no secrets ════════════════════ */

it('keeps drafts per admin and per console path, for 14 days, and never keeps a secret', function () {
    /*
     * Two admins on one computer must not see each other's rows, a draft
     * older than a fortnight is noise, and a key that names a password, token
     * or secret is never written to the browser, whatever screen offers it.
     *
     * MUTATIONS, run: dropped the data-u segment from storeKey() and the first
     * expectation is red; changed MAX_AGE to 30 days and the second is red;
     * removed `if (SECRET.test(k)) return;` from copy() and the SECRET count
     * is red.
     */
    $js = udRegistryScript();

    expect($js)->toContain("return 'kbb.drafts.v1.' + (HOME.getAttribute('data-u') || '0') + '.' + path;")
        ->and($js)->toContain('var MAX_AGE = 14 * 24 * 3600 * 1000;')
        ->and($js)->toContain('if (!(now - Number(d.at || 0) < MAX_AGE)) return;')
        ->and($js)->toContain('var SECRET = /(pass(word|wd)?|secret|token|api[_-]?key|private[_-]?key|(^|[._-])key$|salt|hash|cvv|otp)/i;')
        ->and(substr_count($js, 'SECRET.test('))->toBe(2);

    // Every localStorage touch is inside a try: it THROWS when site data is blocked.
    preg_match_all('/^.*localStorage.*$/m', $js, $lines);
    expect($lines[0])->not->toBeEmpty();
    foreach ($lines[0] as $line) {
        expect($line)->toContain('try {');
    }

    // The secret pattern catches the names it must, and not the ones it must not.
    preg_match('#var SECRET = (/.+?/i);#', $js, $m);
    foreach (['password', 'admin_password', 'api_key', 'indexnow_key', 'stripe_secret', 'webhook_token', 'key'] as $secret) {
        expect(preg_match($m[1], $secret))->toBe(1, "{$secret} would be written to the browser");
    }
    foreach (['bar_height', 'logo_text', 'keywords', 'monkey_size', 'trending_words', 'search_sets_first'] as $plain) {
        expect(preg_match($m[1], $plain))->toBe(0, "{$plain} would be refused as a secret");
    }
});

it('measures nothing on the page', function () {
    /*
     * CLAUDE.md rule 4: this project sizes with calc() and two tests forbid
     * the element-measuring APIs. The panel hangs from the top bar by CSS
     * alone, at both widths.
     */
    $src = udRegistry();

    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'clientHeight', 'scrollWidth', 'ResizeObserver', 'getComputedStyle'] as $api) {
        expect(str_contains($src, $api))->toBeFalse("the registry measures layout with {$api}");
    }
});
