<?php

declare(strict_types=1);

/**
 * The Bulk Add and Bulk Likes screens themselves — the markup, not the endpoint.
 *
 * AdminReviewBulkTest drives the API. This file asserts the things that live in
 * resources/views/admin/partials/review-bulk-screens.blade.php and in
 * resources/views/admin/app.blade.php, and can only break there.
 *
 * THE TWO THIS EXISTS FOR:
 *
 *   THE TAKEOVER. 'rev-add' and 'rev-likes' were entries in REV_SRC pointing at
 *   kbb-admin-bulkadd.html and kbb-admin-bulklikes.html, two standalone files
 *   this repo has never shipped. A screen that renders for real must ALSO be in
 *   LIVE_RENDERED, or mountFrame() goes on firing a HEAD for the missing file on
 *   every single visit and paints the not-built card a moment before the real
 *   screen overwrites it. Half the takeover looks completely fine in a browser.
 *
 *   NO INERT CONTROLS. Every control on these screens must set a request field
 *   the controller actually reads. A switch that writes to a key nothing
 *   consumes looks exactly like a working one and is worse than a missing one —
 *   the owner believes they have turned something off. Each control's field is
 *   matched against the controller's own validation rules, so the two cannot
 *   drift apart silently.
 */

/** The partial this lane owns. */
function bdPartial(): string
{
    return (string) file_get_contents(
        resource_path('views/admin/partials/review-bulk-screens.blade.php')
    );
}

function bdConsole(): string
{
    return (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

function bdController(): string
{
    return (string) file_get_contents(
        app_path('Http/Controllers/Admin/ReviewBulkApiController.php')
    );
}

/* -------------------------------------------------------------- the wiring */

it('is included in the admin console', function () {
    expect(bdConsole())->toContain("@include('admin.partials.review-bulk-screens')");
});

it('stops both ids going through mountFrame, which is the other half of the takeover', function () {
    $console = bdConsole();

    $at = strpos($console, 'const LIVE_RENDERED=');
    expect($at)->not->toBeFalse('LIVE_RENDERED is missing from the console');

    $set = substr($console, $at, strpos($console, "\n", $at) - $at);

    /*
     * Without these two entries every visit to either screen fires a HEAD for a
     * file that is not there. The request is invisible — the real screen paints
     * over the result a moment later — which is exactly why it survived on nine
     * screens for months (see the LANE AV comment block above LIVE_RENDERED).
     */
    expect($set)->toContain("'rev-add'")
        ->and($set)->toContain("'rev-likes'");
});

it('adds no second sidebar entry for ids the nav already has', function () {
    $partial = bdPartial();

    /*
     * Both ids are already in the NAV const and in TITLES. Appending a button
     * here would give the owner each row twice — the mistake the Review
     * Settings and HTML Blocks partials each call out and avoid.
     *
     * Asserted as "creates no element", not as "never mentions .nav-item": the
     * screen DOES read .side .nav-item, to move the highlight onto itself and
     * to decide whether it is the screen showing. That read is how every lane
     * partial in this console works. What none of them may do is build a
     * button, which is what the Coupons and New Order screens — which really do
     * need their own entry — use these calls for.
     */
    expect($partial)->not->toContain('createElement')
        ->and($partial)->not->toContain('appendChild')
        ->and($partial)->not->toContain('insertBefore')
        ->and($partial)->not->toContain('insertAdjacentHTML');

    $console = bdConsole();

    expect($console)->toContain("['rev-add','Bulk Add'")
        ->and($console)->toContain("['rev-likes','Bulk Likes'");
});

it('leaves routes/web.php alone, as this lane is required to', function () {
    // CLAUDE.md: a lane does not edit routes/web.php. The require line is the
    // integrator's, and the header of the lane's own route file carries it.
    $web = (string) file_get_contents(base_path('routes/web.php'));

    expect($web)->not->toContain('ReviewBulkApiController');

    expect((string) file_get_contents(base_path('routes/review-bulk-admin.php')))
        ->toContain("require __DIR__.'/review-bulk-admin.php';");
});

/* --------------------------------------------------------------- the notice */

it('states on each screen what the content it makes actually is', function () {
    $partial = bdPartial();

    // Bulk Add: reviews no customer wrote, and where they end up.
    expect($partial)->toContain('What this screen creates')
        ->and($partial)->toContain('not reviews customers left')
        ->and($partial)->toContain('aggregateRating');

    // Bulk Likes: votes nobody cast.
    expect($partial)->toContain('What this screen changes')
        ->and($partial)->toContain('not a record of anyone');

    // And it says where to find the rows again afterwards, which is the part
    // that is useful rather than merely honest.
    expect($partial)->toContain('admin_bulk');
});

it('states it once and does not gate anything behind it', function () {
    $partial = bdPartial();

    /*
     * A notice is honest. A confirmation gauntlet is refusal wearing a hat: the
     * owner decided this should exist, and a screen that makes them type
     * "I UNDERSTAND" every time has been built to be unusable on purpose.
     * Nothing here blocks, and the statement appears once per screen.
     */
    expect($partial)->not->toContain('confirm(')
        ->and($partial)->not->toContain('window.confirm')
        ->and($partial)->not->toContain('prompt(');

    expect(substr_count($partial, 'What this screen creates'))->toBe(1);
    expect(substr_count($partial, 'What this screen changes'))->toBe(1);
});

/* ------------------------------------------------------- no inert controls */

it('sets only request fields the controller reads, on both screens', function () {
    $partial = bdPartial();
    $controller = bdController();

    /*
     * Every key the screen puts in a request body, matched as the ASSIGNMENT
     * that sets it, and the rule in the controller that consumes it.
     *
     * The assignment, not the bare field name — established by mutation. The
     * first version of this test asked only whether the string 'limit' appeared
     * anywhere in the partial, and deleting `limit:` from the likes payload
     * left it green, because the control that sets it, its hint and its label
     * all still said the word. A control that renders, accepts input and is
     * then dropped on the floor before the request is EXACTLY the inert control
     * this is supposed to catch, and the coarse form could not see it.
     */
    foreach ([
        // Bulk Add
        'product_ids' => 'product_ids: products,',
        'per_product' => 'per_product: parseInt(add.per_product, 10)',
        'add-status' => 'status: add.status,',
        'verified' => 'verified: !!add.verified,',
        'ratings' => 'ratings: add.ratings,',
        'authors' => 'authors: authors,',
        'bodies' => 'bodies: bodies,',
        'titles' => 'titles: lines(add.titles)',
        'date_from' => 'payload.date_from = add.date_from',
        'date_to' => 'payload.date_to = add.date_to',
        // Bulk Likes
        'scope' => 'scope: likes.scope,',
        'mode' => 'mode: likes.mode,',
        'min' => 'min: parseInt(likes.min, 10)',
        'max' => 'max: parseInt(likes.max, 10)',
        'likes-status' => 'status: likes.status',
        'limit' => 'limit: parseInt(likes.limit, 10)',
        'likes-products' => 'payload.product_ids = pickedIds()',
        'ids' => 'payload.ids = ids(likes.ids)',
    ] as $control => $assignment) {
        expect(str_contains($partial, $assignment))
            ->toBeTrue("the [{$control}] control never reaches the request: {$assignment}");
    }

    // And the other half: the controller must actually read each field name.
    // Matched as the quoted key in its validate() arrays, so renaming a field
    // on one side breaks this.
    foreach ([
        'product_ids', 'per_product', 'status', 'verified', 'ratings',
        'authors', 'bodies', 'titles', 'date_from', 'date_to',
        'scope', 'mode', 'min', 'max', 'ids', 'limit',
    ] as $field) {
        expect(str_contains($controller, "'" . $field . "'"))
            ->toBeTrue("the controller never reads [{$field}]");
    }
});

it('calls the three guarded endpoints this lane added and nothing under /api/', function () {
    $partial = bdPartial();

    expect($partial)->toContain('/review-bulk/options')
        ->and($partial)->toContain('/review-bulk/add')
        ->and($partial)->toContain('/review-bulk/likes');

    // The api() helper prefixes '/admin-api'. Nothing may reach past it to the
    // unauthenticated /api/* tree.
    expect($partial)->toContain("'/admin-api' + path")
        ->and($partial)->not->toContain("fetch('/api/");
});

/* ------------------------------------------------------------- the escaping */

it('escapes every value it writes into the page', function () {
    $partial = bdPartial();

    /*
     * Product names, reviewer names and review bodies all reach innerHTML here,
     * and every one of them is text somebody else typed — the WooCommerce
     * importer writes product names, and the textareas round-trip through
     * render(). The Reviews moderation screen shipped stored XSS in the
     * owner's own back-office by escaping every field but one.
     */
    foreach ([
        // The product name reaches innerHTML in THREE places and each is named
        // separately, with enough of its surroundings to identify it. Matching
        // the bare string 'esc(p.name)' cannot tell which of the three is
        // present — established by mutation: unescaping the picker row left the
        // coarse assertion green because the other two still matched.
        '"rbk-pick-name">\' + esc(p.name)',      // the picker row
        'data-rbk-name="\' + esc(p.name)',       // the attribute the picker stores
        '\'<tr><td>\' + esc(p.name)',            // the recomputed-figures table
        'esc(picked[id])',                       // the selected-product chips
        'esc(banner.text)',
        'esc(add.authors)',
        'esc(add.bodies)',
        'esc(add.titles)',
        'esc(likes.ids)',
        'esc(search)',
    ] as $needle) {
        // NOT toContain($needle, $message): Pest's toContain is VARIADIC, so a
        // second argument is a second needle.
        expect(str_contains($partial, $needle))
            ->toBeTrue("unescaped interpolation: {$needle} is missing");
    }

    /*
     * And the specific SHAPE of the defect, so it cannot come back by
     * copy-paste: a public value concatenated raw. This is the half that
     * catches an escape being deleted rather than one never being added.
     */
    foreach (['+ p.name +', '+p.name+', '+ picked[id] +', '+ banner.text +', '+ search +'] as $raw) {
        expect(str_contains($partial, $raw))
            ->toBeFalse("raw interpolation into innerHTML: {$raw}");
    }
});

/* ---------------------------------------------------------------- the layout */

it('lets the screen shrink below its widest child', function () {
    $css = bdPartial();

    /*
     * A grid item's default min-width is auto — "at least as wide as my
     * content" — so a card holding a wide table refuses to shrink, its inner
     * overflow-x never gets the chance to scroll, and the whole column is
     * dragged past the viewport. That shipped on the Coupons screen; see
     * tests/Feature/AdminScreenGridOverflowTest.php.
     */
    expect($css)->toMatch('/\.rbk-wrap\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.rbk-wrap\s*>\s*\*\{[^}]*min-width:0/');
});

it('gives the result tables a scroller that is allowed to be narrow', function () {
    $css = bdPartial();

    // overflow-x:auto alone is not a scroller. Without a min-width it grows to
    // its content and scrolls nothing.
    expect($css)->toMatch('/\.rbk-scroll\{[^}]*overflow-x:auto/')
        ->and($css)->toMatch('/\.rbk-scroll\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.rbk-scroll\{[^}]*max-width:100%/');
});

it('lets the star-mix tracks give way on a phone', function () {
    $css = bdPartial();

    /*
     * repeat(auto-fit, minmax(132px, 1fr)) cannot go narrower than 132px per
     * track, so five tracks plus gaps demand more than a 390px screen has.
     * minmax(min(132px,100%), 1fr) lets a track give way and keeps the floor
     * everywhere else.
     */
    expect($css)->toMatch('/\.rbk-mix\{[^}]*minmax\(min\(132px,\s*100%\),\s*1fr\)/');
});

it('bounds the product picker so it cannot scroll for ever', function () {
    $css = bdPartial();

    // An unbounded list of a few thousand products is the element that makes
    // the whole console scroll past the viewport.
    expect($css)->toMatch('/\.rbk-pick-list\{[^}]*max-height:/')
        ->and($css)->toMatch('/\.rbk-pick-list\{[^}]*overflow-y:auto/');
});

it('prefixes every rule so it cannot restyle another screen', function () {
    $partial = bdPartial();

    $at = strpos($partial, '<style>');
    $css = substr($partial, $at, strpos($partial, '</style>') - $at);

    /*
     * Comments stripped first. This block's comments name the rules on the
     * sibling screens it borrows its layout from (.rvs-wrap, .ecwrap, .rvs-sw)
     * and the files they live in (…blade.php), and every one of those looks
     * like a foreign class selector to the pattern below. Explaining where a
     * rule came from is the opposite of the defect this guards against.
     */
    $css = (string) preg_replace('#/\*.*?\*/#s', ' ', $css);

    /*
     * The console is one document. An unprefixed .card or #save here reaches
     * into whatever else is mounted. Every class selector in this block must
     * start rbk-.
     */
    preg_match_all('/\.([a-zA-Z][\w-]*)/', $css, $m);

    $foreign = array_values(array_unique(array_filter(
        $m[1],
        fn (string $c) => ! str_starts_with($c, 'rbk-')
    )));

    expect($foreign)->toBe([]);
});

/* ------------------------------------------------------------ the behaviour */

it('never paints over a screen the owner has navigated away to', function () {
    $partial = bdPartial();

    /*
     * load() is async. Without this check a response arriving after the owner
     * has moved on replaces whatever they went to. Lane BB's screen carries the
     * same guard for the same reason.
     */
    expect($partial)->toContain("SCREENS.indexOf(active.dataset.go) === -1")
        ->and($partial)->toContain('if (mine !== seq) return;');
});

it('says so plainly when the routes are not registered yet', function () {
    $partial = bdPartial();

    /*
     * A 404 here almost always means the package shipped without its
     * clear_caches migration having run, so the compiled route table does not
     * know these paths. Rendering an empty picker instead reads as "there are
     * no products", and the owner goes looking for the bug in their catalogue.
     */
    expect($partial)->toContain('e.status === 404')
        ->and($partial)->toContain('Clear the route cache and reload.');
});

it('wraps both bulk writes in a transaction', function () {
    $controller = bdController();

    /*
     * STRUCTURAL, AND THAT IS A REAL LIMITATION — said here rather than left
     * for someone to discover.
     *
     * Deleting the DB::transaction() wrapper does not turn a single behavioural
     * test in this suite red. Established by mutation, not assumed: replacing
     * it with a bare invoked closure left all 51 tests passing. Forcing a
     * failure part-way through the insert would need either a constraint these
     * rows can violate (there is none — source_id is null on every one, and
     * nulls do not collide in the reviews_source_unique index) or a throwing
     * double for App\Support\ProductRating, which is a final class of statics
     * and cannot be substituted.
     *
     * So what is pinned is the wrapper's PRESENCE, which is the half that can
     * be lost to a refactor. What it protects — a half-populated product left
     * behind by a request that died between chunk one and chunk two, with
     * `products.rating` describing rows that were rolled back — is reasoned
     * about in the controller's docblock and not demonstrated by a test.
     */
    expect(substr_count($controller, 'DB::transaction('))->toBe(2);

    // and the aggregate write is INSIDE the insert's transaction, not after it
    $add = substr($controller, strpos($controller, 'public function add('));
    $add = substr($add, 0, strpos($add, 'public function likes('));

    $open = strpos($add, 'DB::transaction(');
    $refresh = strpos($add, 'ProductRating::refresh(');
    $close = strpos($add, '$this->forgetHomeWall();');

    expect($open)->not->toBeFalse()
        ->and($refresh)->toBeGreaterThan($open)
        ->and($close)->toBeGreaterThan($refresh);
});

it('ships the clear_caches migration the new routes need', function () {
    $migrations = glob(base_path('database/migrations/*clear_caches_review_bulk*.php'));

    expect($migrations)->not->toBeEmpty();

    $source = (string) file_get_contents($migrations[0]);

    // Routes, because this package adds three; views, because the console's
    // compiled Blade is keyed by path with no content check and app.blade.php
    // already exists on the server.
    expect($source)->toContain('bootstrap/cache/routes-*.php')
        ->and($source)->toContain('framework/views/*.php')
        // MigrationConventionTest: ->after() is how the checkout outage happened.
        ->and($source)->not->toContain('->after(');
});
