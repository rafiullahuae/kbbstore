<?php

declare(strict_types=1);

use App\Models\ProductTab;
use App\Support\ProductTabs;

/**
 * Catalog -> Product tabs, the screen. (Lane PT)
 *
 * The API and the storefront are ProductTabsTest's. This file is about the
 * partial: that it cannot restyle or hijack the rest of the console, that it
 * escapes everything an operator typed, that it offers no second upload path,
 * and that the Arabic boxes it draws come from the SERVER's allowlist rather
 * than from a list this screen keeps itself.
 */
function ptScreenPath(): string
{
    return resource_path('views/admin/partials/product-tabs-screen.blade.php');
}

/**
 * The screen's SOURCE, with every comment stripped first.
 *
 * ▲ THE STRIP IS THE WHOLE POINT. This screen explains what it does in prose,
 *   and a scan of the raw text would match the EXPLANATION and pass a screen
 *   that does not have the thing in it.
 */
function ptScreenCode(): string
{
    $src = (string) file_get_contents(ptScreenPath());
    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

    return (string) preg_replace('#^\s*//.*$#m', '', $src);
}

it('escapes every operator string it prints', function () {
    /*
     * A tab title, a product name, a search term and a server error are all
     * operator or shopper input. CLAUDE.md rule 5: anything printed unescaped
     * is a constant, never a setting. The screen builds its markup with string
     * concatenation, so an un-escaped interpolation is stored XSS in the back
     * office -- reachable, on this screen, by anyone who can name a product.
     *
     * MUTATION, RUN: change `esc(tab.title)` in globalRow() to `tab.title` and
     * this is red.
     */
    $code = ptScreenCode();

    foreach ([
        'tab.title', 'state.error', 'state.query', 'state.product.name',
        'p.name', 'p.status', 'tab.label',
    ] as $value) {
        expect(str_contains($code, 'esc('.$value.')'))->toBeTrue(
            $value.' is operator-supplied and must be escaped.'
        );
    }
});

it('prints exactly one thing unescaped, and it is the body being edited', function () {
    /*
     * The tab BODY goes into the contenteditable pane as HTML, because it IS
     * the HTML being edited -- the same thing the Pages and Posts editors do
     * with `content` and `body`. It is safe for a reason that is stated rather
     * than assumed: App\Support\RichText::clean() ran over it on the server
     * before it could be stored, so what comes back out of the database has
     * already been through the allowlist.
     *
     * This case exists so a SECOND unescaped interpolation cannot be added
     * quietly. Anything else printed raw on this screen is a defect.
     *
     * MUTATION, RUN: add `+ tab.title +` without esc() anywhere in the render
     * and this is red on the count.
     */
    $code = ptScreenCode();

    /*
     * There is exactly ONE raw insertion point in the whole screen, and it is
     * rte()'s, which writes the HTML being edited into the contenteditable
     * pane. Named here so a second one cannot be added quietly.
     */
    expect(substr_count($code, "+ (html || '')"))->toBe(1,
        'rte() is the one place this screen writes HTML in unescaped');

    /*
     * And nothing else interpolates a property straight into the MARKUP. The
     * pattern catches `+ tab.title` and lets `+ esc(tab.title)` through, which
     * is the difference this case is about, and it is applied only to the lines
     * that build markup -- a line holding a '<' -- because
     * `var key = 'global:' + tab.id;` is a key being assembled, not a value
     * being printed, and that key is escaped where it IS printed.
     */
    $bare = [];

    foreach (explode("\n", $code) as $line) {
        if (! str_contains($line, '<')) {
            continue;
        }

        if (preg_match_all('/\+\s+(?!esc\()([a-z][A-Za-z0-9_]*\.[A-Za-z0-9_.]+)/', $line, $m) === 0) {
            continue;
        }

        foreach ($m[1] as $found) {
            // `.map`/`.join` are CALLS that return already-escaped markup.
            if (! str_ends_with($found, '.map') && ! str_ends_with($found, '.join')) {
                $bare[] = $found;
            }
        }
    }

    $bare = array_values(array_unique($bare));

    expect($bare)->toBe([], 'these are printed without esc(): '.implode(', ', $bare));
});

it('prefixes every class and data attribute it owns', function () {
    /*
     * app.blade.php binds delegated listeners to `document` itself, each
     * claiming a bare attribute name -- so an unprefixed `data-` attribute here
     * is a listener somewhere else in the console firing on this screen's
     * buttons. The class rules are the same argument for styles: a bare `.row`
     * rule would restyle every other screen in the console.
     *
     * MUTATION, RUN: rename `.kpt-row` to `.row` and this is red.
     */
    $src = (string) file_get_contents(ptScreenPath());

    preg_match_all('/^\.([a-z][a-z0-9-]*)/mi', $src, $classes);

    $leaked = array_values(array_unique(array_filter(
        $classes[1] ?? [],
        fn (string $c) => ! str_starts_with($c, 'kpt-') && ! str_starts_with($c, 'is-')
    )));

    expect($leaked)->toBe([], 'these class rules are not kpt-prefixed and would restyle other screens');

    preg_match_all('/data-([a-z][a-z0-9-]*)/', $src, $attrs);

    /*
     * `data-sec` and `data-go` are the CONSOLE's own attributes, read here to
     * find the sidebar row and the nav group. `data-ph` is the placeholder
     * attribute the rich pane's own CSS rule reads, scoped to .kpt-rte-area.
     * `data-kbbar-*` belongs to admin/partials/arabic-boxes.blade.php and is
     * read, never written, by this screen.
     */
    $foreign = array_values(array_unique(array_filter(
        $attrs[1] ?? [],
        fn (string $a) => ! str_starts_with($a, 'kpt-') && ! in_array($a, ['sec', 'go', 'ph'], true)
    )));

    expect($foreign)->toBe([], 'these data attributes are not data-kpt- prefixed');
});

it('offers no upload path at all, because a picture comes from the Media Library', function () {
    /*
     * There is exactly ONE file-upload endpoint in this application and a
     * second one is what routes/brands-admin and routes/catalog-admin each went
     * out of their way to avoid: two of them drift, and the one that drifts is
     * the one with the content-type, size and SVG rules in it.
     */
    $code = ptScreenCode();

    foreach (['<input type="file"', 'FormData', 'media/upload'] as $needle) {
        expect(str_contains($code, $needle))->toBeFalse(
            'this screen must not carry its own upload path ('.$needle.')'
        );
    }
});

it('asks the server which fields have an Arabic box, instead of listing them itself', function () {
    /*
     * KBBArabic.boxIf() draws a box only for a field the SERVER's shape knows,
     * so a field added to ProductTab::$translatable grows a box here without
     * this file being touched -- and a box is never offered for a field
     * saveTranslations() would silently drop.
     *
     * MUTATION, RUN: change boxIf to box in arabic() and this is red.
     */
    $code = ptScreenCode();

    expect(str_contains($code, 'KBBArabic.boxIf('))->toBeTrue()
        ->and(str_contains($code, 'KBBArabic.collect('))->toBeTrue(
            'the Arabic half has to be read back on save or it is a box that does nothing'
        );
});

it('registers its sidebar row through the one supported function', function () {
    /*
     * Five partials used to build the row by hand, each ending `if (!anchor)
     * return;` -- so renaming ONE row in NAV removed a DIFFERENT screen from
     * the sidebar with no error anywhere. kbbAddNavEntry degrades loudly
     * instead. app.blade.php's own header says so at length.
     */
    $code = ptScreenCode();

    expect(str_contains($code, 'window.kbbAddNavEntry({'))->toBeTrue()
        ->and(str_contains($code, "group: 'Catalog'"))->toBeTrue();
});

it('renders before it loads, so a deep link does not draw the screen twice', function () {
    /*
     * The condition LATE_RENDERED carries in app.blade.php: the deep-link
     * replay's marker inside #content has to be destroyed by the time its task
     * runs. render() is called synchronously, before load().
     */
    $code = ptScreenCode();

    $position = strpos($code, 'render();'."\n".'    load();');

    expect($position)->not->toBeFalse('render() must be called synchronously before load()');
});

it('sends the whole order in one request, so a re-order is never half saved', function () {
    $code = ptScreenCode();

    expect(str_contains($code, "api('/product-tabs/order', { order: order })"))->toBeTrue();

    // Renumbered from a floor with a gap, NOT by swapping two positions: a
    // swap breaks the moment two rows legitimately share a position.
    expect(str_contains($code, 'floor + (i * 10)'))->toBeTrue();
});

it('bounds the on/off control to its own two values before it is sent', function () {
    // CLAUDE.md rule 5: a select stores one of its own options or the default.
    // Twice, because the server bounds it again against the same two.
    $code = ptScreenCode();

    expect(str_contains($code, "payload.is_enabled = on.value === '1'"))->toBeTrue();
});

it('draws a global tab and a built-in tab in ONE ordered list', function () {
    /*
     * The single ordering scale is the decision this screen exists to make
     * visible. Two lists would hide it: the owner could not see that the tab he
     * is adding lands after How to use until he looked at a product page.
     */
    $code = ptScreenCode();

    expect(str_contains($code, "rows.sort(function (a, b) { return a.position - b.position; })"))
        ->toBeTrue('built-ins and globals must be interleaved by position');
});

it('names all three states of an inherited tab in words', function () {
    /*
     * "Is this one mine or the shop's?" is the question the per-product half
     * exists to answer, and shading alone does not answer it.
     */
    $code = ptScreenCode();

    foreach (['Inherited', 'Overridden', 'Hidden here'] as $word) {
        expect(str_contains($code, $word))->toBeTrue($word.' must be named on the screen');
    }
});

it('holds the screen\'s defaults identical to the server\'s', function () {
    /*
     * The re-order floors are literals in the browser AND constants on the
     * server, because the browser has to renumber without a round trip. Two
     * copies of one number drift; this is the test that says so.
     *
     * MUTATION, RUN: change ProductTabs::DEFAULT_PRODUCT_POSITION to 600 and
     * this is red.
     */
    $code = ptScreenCode();

    expect(str_contains($code, "var floor = scope === 'global' ? "
        .ProductTabs::DEFAULT_GLOBAL_POSITION.' : '.ProductTabs::DEFAULT_PRODUCT_POSITION.';'))
        ->toBeTrue('the screen\'s re-order floors must match ProductTabs\'s own');
});

it('keeps the storefront partial\'s two raw prints and adds no third', function () {
    /*
     * resources/views/partials/product-tabs.blade.php prints a body with
     * {!! !!} twice -- the desktop panel and the mobile accordion -- and has
     * done since before this lane. This lane added NO new unescaped print to
     * it, which is the promise the whole security argument rests on: a tab body
     * travels exactly the path a description already travelled.
     *
     * MUTATION, RUN: add a third `{!! $tab['body'] !!}` to the partial and this
     * is red.
     */
    // Comments stripped FIRST. That file explains the two raw prints in prose,
    // and a scan of the raw text would count the explanation as a third one.
    $src = (string) preg_replace(
        '/\{\{--.*?--\}\}/s',
        '',
        (string) file_get_contents(resource_path('views/partials/product-tabs.blade.php'))
    );

    expect(substr_count($src, '{!!'))->toBe(2, 'the partial prints raw exactly twice')
        ->and(substr_count($src, "{{ \$tab['title'] }}"))->toBe(2,
            'and it prints the title ESCAPED, in both presentations');
});

it('leaves the tab strip\'s overflow behaviour exactly as it was', function () {
    /*
     * At 720px and below the strip is not rendered at all -- the accordion
     * replaces it -- and above that it is `overflow-x:auto` with
     * `white-space:nowrap`, so a long set of titles SCROLLS rather than
     * wrapping. That is the shop's behaviour today and this lane did not move
     * it: a tab the owner adds joins a strip that already knew how to overflow.
     *
     * Pinned because "give full control" is exactly the request under which
     * somebody would be tempted to make the strip wrap, which would move every
     * product page in the shop.
     */
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-product.css'));

    expect(str_contains($css, '.dtabbar{display:flex;gap:4px;border-bottom:1px solid var(--line-2);margin-bottom:18px;overflow-x:auto}'))
        ->toBeTrue('the strip still scrolls rather than wrapping')
        ->and(str_contains($css, '@media(max-width:720px){.dtabbar,.dpanel{display:none}.macc{display:block}}'))
        ->toBeTrue('and below 720px there is no strip at all, only the accordion');
});

it('has an admin path that names itself, for the owner and for the report', function () {
    // CLAUDE.md, what every lane owes, item 3: every patch that adds a control
    // names its exact path. The sidebar row and the breadcrumb have to agree.
    $code = ptScreenCode();

    expect(str_contains($code, "label: 'Product tabs'"))->toBeTrue()
        ->and(str_contains($code, "title.textContent = 'Product tabs'"))->toBeTrue()
        ->and(str_contains($code, "crumb.textContent = 'Catalog'"))->toBeTrue();
});

it('ships with nothing switched on, so applying the package moves nothing', function () {
    /*
     * CLAUDE.md, item 1: any NEW setting ships at the value the page already
     * has. This feature has no setting at all -- it has an empty table -- which
     * is the strongest form of the same promise. Asserted here rather than
     * assumed because "empty" is a claim about the migration.
     */
    expect(ProductTab::query()->count())->toBe(0,
        'the product_tabs table ships empty and stays empty until the owner writes one');
});

/* ═════════════════════════════ WHERE IT SHOWS — the control (round 2) ════ */

it('draws the five rule options from the SERVER, not from a list of its own', function () {
    /*
     * ON THE SHOP: a screen holding its own copy of the vocabulary is a screen
     * that offers an option the validator refuses. The owner picks it, presses
     * Save, and gets "That tab could not be saved" with no way to tell which
     * field was wrong -- which is the shape of every drifting-constant bug in
     * this repository.
     *
     * MUTATION, RUN: replace `boot.audiences ||` with a literal array and this
     * is red.
     */
    $code = ptScreenCode();

    expect(str_contains($code, 'var options = boot.audiences'))->toBeTrue(
        'the option VALUES must come from the server'
    );

    // And the sentences beside them are the screen's, keyed by the server's
    // word, so a value with no sentence still renders as itself rather than
    // as an empty option.
    foreach (['global', 'products', 'categories', 'brands', 'sets'] as $value) {
        expect(str_contains($code, $value.':'))->toBeTrue($value.' has no words beside it');
    }
});

it('checks the chosen rule against the server\'s list before it reaches state', function () {
    /*
     * CLAUDE.md rule 5: a select stores one of its own options or the default.
     * A <select> is a DOM element and anything on the page can put a fourth
     * <option> in it, so the change handler validates the value rather than
     * trusting the control it came from -- and the server then checks the same
     * value twice more.
     *
     * MUTATION, RUN: assign `t.value` straight to `state.rule.audience` and
     * this is red.
     */
    $code = ptScreenCode();

    expect(str_contains($code, 'allowed.indexOf(t.value) === -1'))->toBeTrue()
        ->and(str_contains($code, 'state.boot.audience_default'))->toBeTrue(
            'and it falls back to the default the server named'
        );
});

it('clears the picked targets when the rule changes', function () {
    /*
     * ON THE SHOP: eleven product ids left behind on a rule that now says
     * "brands" is a row whose meaning depends on which field the next reader
     * looks at -- and the next reader is a matcher written a year from now. The
     * server clears them too; this is the same decision on both sides of the
     * wire, which is what stops the screen and the row disagreeing about what
     * was saved.
     */
    $code = ptScreenCode();

    expect(str_contains($code, 'state.rule = { audience: chosen, ids: [] };'))->toBeTrue();
});

it('picks products with the console\'s one type-ahead, not a second one', function () {
    /*
     * window.kbbProductPicker is the shared module, and its own header records
     * what two copies of a type-ahead cost the last time: New Order's and the
     * order detail screen's drifted apart, and fixing one left the other
     * exactly as it was.
     *
     * attach() AFTER EVERY RENDER is the half that matters here. This screen
     * redraws itself on every tick and every chip removal, so a picker bound
     * once at open would be holding elements that are no longer in the
     * document -- which is precisely the "appears and disappears instantly"
     * the owner reported about New Order.
     *
     * MUTATION, RUN: move attachRulePicker() out of render() and into the
     * open handler, and this is red.
     */
    $code = ptScreenCode();

    expect(str_contains($code, 'window.kbbProductPicker({'))->toBeTrue()
        ->and(str_contains($code, 'rulePicker.attach();'))->toBeTrue()
        ->and(str_contains($code, 'try { attachRulePicker(); } catch (e) {}'))->toBeTrue(
            'the picker must be re-attached from render(), not from the open handler'
        );
});

it('uses a tick list for categories and brands and a search for products', function () {
    /*
     * A MEASUREMENT, NOT A PREFERENCE. This shop has seven hundred products, a
     * few dozen categories nested four deep and ninety-three brands. A
     * type-ahead over ninety-three brands is a round trip to answer a question
     * a list already answers; a tick list over seven hundred products is a
     * screen nobody can use. The bootstrap carries the two short lists in full
     * and deliberately does not carry products.
     */
    $code = ptScreenCode();

    expect(str_contains($code, "ticks(rule.audience)"))->toBeTrue()
        ->and(str_contains($code, "rule.audience === 'products'"))->toBeTrue();

    // A category shows its path, so two "Masks" in different branches can be
    // told apart.
    expect(str_contains($code, "kind === 'categories' && row.path"))->toBeTrue();
});

it('offers the rule on a global tab and on nothing else', function () {
    /*
     * ON THE SHOP: a per-product tab already names its product and an override
     * already names the tab it covers. The server leaves `audience` out of
     * THEIR rules, so a control drawn on those forms would be one the owner can
     * set and that then silently does nothing -- the worst kind, because the
     * screen says it saved.
     *
     * MUTATION, RUN: make isGlobalRow() return true and this is red.
     */
    $code = ptScreenCode();

    expect(str_contains($code, "isGlobalRow(kind) ? audienceField() : ''"))->toBeTrue()
        ->and(str_contains($code, "return kind === 'new-global' || String(kind).indexOf('global:') === 0;"))
        ->toBeTrue();
});

it('says on every row where that tab shows', function () {
    /*
     * The list is the screen's answer to "what have I set up", and a row that
     * reads the same whether it is on seven hundred products or on two is not
     * an answer. Every global row carries a sentence, and a rule with nothing
     * picked SAYS SO -- that is the state the owner would otherwise have to
     * discover by opening a product page.
     */
    $code = ptScreenCode();

    expect(str_contains($code, 'esc(audienceSummary(tab))'))->toBeTrue()
        ->and(str_contains($code, 'nothing picked yet, so it shows nowhere'))->toBeTrue();
});

it('names every target from a list it already has, never one lookup per chip', function () {
    /*
     * Resolving a chip's name with a request each would be an N+1 on a screen
     * the owner opens every time. Categories and brands come from the two lists
     * the bootstrap carries in full; products come from `product_names`, which
     * the server builds in ONE query over the union of every tab's ids.
     *
     * MUTATION, RUN: have chipName() fetch, and this is red.
     */
    $code = ptScreenCode();

    expect(str_contains($code, 'boot.product_names && boot.product_names[id]'))->toBeTrue()
        ->and(preg_match('/function chipName\(.*?\n\s*\}/s', $code, $m))->toBe(1);

    expect(str_contains($m[0], 'await'))->toBeFalse('chipName must not fetch anything')
        ->and(str_contains($m[0], 'api('))->toBeFalse();
});

it('still prefixes every class and data attribute it owns', function () {
    // The round-2 control adds a dozen of each. Re-asserted here rather than
    // trusted, because app.blade.php binds delegated listeners to `document`
    // itself and an unprefixed attribute is another screen's listener firing
    // on these buttons.
    $src = (string) file_get_contents(ptScreenPath());

    preg_match_all('/^\.([a-z][a-z0-9-]*)/mi', $src, $classes);

    $leaked = array_values(array_unique(array_filter(
        $classes[1] ?? [],
        fn (string $c) => ! str_starts_with($c, 'kpt-') && ! str_starts_with($c, 'is-')
    )));

    expect($leaked)->toBe([]);

    preg_match_all('/data-([a-z][a-z0-9-]*)/', $src, $attrs);

    $foreign = array_values(array_unique(array_filter(
        $attrs[1] ?? [],
        fn (string $a) => ! str_starts_with($a, 'kpt-') && ! in_array($a, ['sec', 'go', 'ph'], true)
    )));

    expect($foreign)->toBe([]);
});
