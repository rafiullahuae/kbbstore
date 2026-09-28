<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Models\Media;
use App\Services\Banners;
use App\Services\ModuleSchema;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\Hash;
use Tests\Support\BannersAdminRoutes;

/**
 * Appearance → Banners → Cards banner: the endpoints, the guard, the wiring.
 *
 * Phase 22, Lane BN.
 *
 * ── THE ROUTES ARE MOUNTED BY A HARNESS, AND THE PIN IS THE FINISHED STATE ──
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so these twelve
 * routes ship in their own file with the require line in its header. Nothing
 * dispatches to the controller until the integrator wires it up, which would
 * otherwise leave the capability guard on twelve endpoints that rewrite the
 * front page of the shop untested until a package shipped them.
 *
 * ▲ AND THE WIRING ASSERTION BELOW PINS `substr_count(...) === 1`, NOT THE
 * ABSENCE OF THE REQUIRE. CLAUDE.md names that mistake and its price: "That
 * assertion is correct in the lane's worktree and goes red the moment the
 * integrator does the one thing the lane asked for ... It happened three times
 * in one day." Zero is the "built, never wired up" shape this repository keeps
 * finding; two registers the screen's routes twice and makes every name lookup
 * ambiguous. Both are real failures. One is the finished state, and it is green
 * here today and green after the integrator's commit.
 */
function bnaOwner(string $role = 'owner'): AdminUser
{
    $user = AdminUser::create([
        'name' => 'BN '.$role,
        'email' => 'bn-'.$role.'-'.uniqid().'@example.com',
        'password' => Hash::make('secret-secret'),
        'role' => $role,
    ]);

    test()->actingAs($user, 'admin');

    return $user;
}

function bnaSet(array $attributes = []): BannerSet
{
    return BannerSet::create($attributes + [
        'name' => 'Set '.uniqid(), 'slug' => 's-'.uniqid(), 'status' => 'publish', 'position' => 0,
    ]);
}

/* ═══════════════════════════════ the wiring ═══════════════════════════════ */

it('is required from routes/web.php exactly once, once the integrator has wired it', function () {
    /*
     * ▲ THIS CASE IS RED IN THIS LANE'S WORKTREE, AND THAT IS THE PIN DOING ITS
     *   JOB. It is the shape CLAUDE.md requires and the shape four other
     *   screens in this repository already use — HomepageLiveEditTest says it
     *   in as many words: "IT IS THEREFORE RED UNTIL THE INTEGRATOR ADDS THE
     *   LINE ... the message below is the instruction."
     *
     *   The tempting alternative is the one that has cost this project three
     *   round trips: `expect($web)->not->toContain('banners-admin.php')` is
     *   green here today and goes RED the moment the integrator does the one
     *   thing this lane asked for, and the only way to green it as written is
     *   to unmount the feature.
     *
     *   ZERO is "built, never wired up", which is the state this file is in
     *   until the merge. TWO registers every route twice and makes every name
     *   lookup ambiguous, which is the failure that survives a merge and is
     *   invisible in a diff. ONE is the finished state, and it is the only
     *   number this asserts.
     *
     * MUTATION NOTE, run: adding the line to routes/web.php turns this case
     * green and nothing else in this file moves; adding it twice leaves it red
     * at 2. Both were done in a scratch copy of web.php and put back — this
     * lane does not own that file.
     */
    $routes = (string) file_get_contents(base_path('routes/banners-admin.php'));

    // The file the integrator is being asked to require really is there, with
    // the line to copy in its own header and the endpoints behind it.
    expect($routes)->toContain("require __DIR__.'/banners-admin.php';")
        ->and($routes)->toContain("Route::get('/banners'");

    $web = (string) file_get_contents(base_path('routes/web.php'));

    expect(substr_count($web, "require __DIR__.'/banners-admin.php';"))->toBe(
        1,
        "routes/web.php does not require banners-admin.php exactly once. Add\n"
        ."    require __DIR__.'/banners-admin.php';\n"
        .'inside the admin-api group, directly under the homepage-content require.'
    );
});

it('registers fourteen routes and maps every one of them to a capability', function () {
    /*
     * THE GUARD FAILS CLOSED BY CONSTRUCTION — AdminCapabilities::for() returns
     * null for a route it does not recognise and EnforceAdminCapability turns a
     * null into a 403 for everyone who is not an owner. So an unmapped route is
     * not a hole; it is a manager locked out of a screen that otherwise works.
     * This is what catches that before the owner reports it.
     *
     * MUTATION: delete the `['GET', 'admin-api/banners/**', 'banners.view']`
     * line from AdminCapabilities::RULES and this goes red naming the preview
     * and the set-detail routes.
     */
    BannersAdminRoutes::wire(app());

    $routes = BannersAdminRoutes::registered();

    /*
     * TWELVE UNTIL ROUND 7; FOURTEEN NOW. Lane BP added
     * `POST /banners/sets/{set}/preview` (the row drawn from the editor's
     * unsaved buffer) and `PUT /banners/sets/{set}/all` (the Save button). Both
     * are writes by verb and therefore land on `banners.manage` through the
     * wildcard rows below, which the loop under this line is what proves — the
     * count is advanced deliberately, and the capability check is the part that
     * had to keep holding.
     */
    expect($routes)->toHaveCount(14);

    foreach ($routes as $route) {
        $capability = AdminCapabilities::for($route);

        expect($capability)->not->toBeNull("{$route->methods()[0]} {$route->uri()} is mapped to no capability");
        expect(AdminCapabilities::CAPABILITIES)->toHaveKey($capability);
    }
});

it('gives a write path the manage capability and never the view one', function () {
    /*
     * THE ORDERING TRAP, AND IT IS NARROWER THAN IT FIRST LOOKS — measured
     * rather than assumed, because the first version of this note was wrong.
     *
     * RULES is first-match-wins, but each row also carries a METHOD, and a
     * `['GET', …]` row cannot match a POST at all. So moving the two GET lines
     * above the writes changes NOTHING, and that mutation was run and stayed
     * green; the note that claimed otherwise has been corrected rather than
     * left to read well.
     *
     * The rule that really can swallow the writes is a WILDCARD-METHOD one:
     * `['*', 'admin-api/banners/**', 'banners.view']` listed first resolves
     * every POST, PUT and DELETE under that prefix to the read capability, and
     * an editor-less account with `banners.view` could then delete the set the
     * homepage is showing. That is the shape the Instagram and ugc-sections
     * blocks in RULES are ordered against, and it is the mutation this case
     * really catches.
     *
     * MUTATION, run: insert `['*', 'admin-api/banners/**', 'banners.view']`
     * above the writes in AdminCapabilities::RULES and this goes red on the
     * first write it reaches. Run, red, put back.
     */
    BannersAdminRoutes::wire(app());

    foreach (BannersAdminRoutes::registered() as $route) {
        $writes = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [];

        expect(AdminCapabilities::for($route))
            ->toBe($writes ? 'banners.manage' : 'banners.view', $route->methods()[0].' '.$route->uri());
    }
});

it('refuses a support account and admits an editor', function () {
    /*
     * A support account answers customers; it has no reason to rewrite what the
     * front page shows. An editor does — that role exists for storefront work,
     * and this grant holds no credential and reaches no third party, which is
     * the difference from `instagram.manage`.
     */
    BannersAdminRoutes::wire(app());

    bnaOwner('support');
    test()->getJson('/admin-api/banners')->assertForbidden();
    test()->postJson('/admin-api/banners/sets', ['name' => 'X'])->assertForbidden();

    bnaOwner('editor');
    test()->getJson('/admin-api/banners')->assertOk();
    test()->postJson('/admin-api/banners/sets', ['name' => 'X'])->assertCreated();
});

/* ═══════════════════════════════ the screen ═══════════════════════════════ */

it('declares the screen id the console routes by', function () {
    // AdminNavAndIdsTest finds a settings screen by `var SCREEN = '<id>'` in its
    // partial, and ModuleRegistry's row points at `banners`. If the two ever
    // disagree the Modules page links the owner at a screen that is not there.
    $partial = (string) file_get_contents(base_path('resources/views/admin/partials/banners-screen.blade.php'));

    expect($partial)->toContain("var SCREEN = 'banners';")
        ->and(\App\Services\ModuleRegistry::REGISTRY['cards_banner'][5])->toBe('banners')
        // Its own class prefix, used nowhere else: app.blade.php binds delegated
        // listeners to `document` on bare attribute names, so a shared one is
        // handled by somebody else's screen.
        ->and($partial)->toContain('data-bns-')
        ->and($partial)->not->toContain('data-open=')
        ->and($partial)->not->toContain('data-tg=');
});

it('draws the preview from the shop’s own partial and not a second copy of it', function () {
    /*
     * A second copy of the markup in the screen's JavaScript is a copy that
     * disagrees with the shop the first time either is touched — the fault
     * HomepageLayouts::summaries() shipped and settleKeys() was split out to
     * end. So the screen asks the server, and the server renders the SAME file
     * the homepage renders.
     */
    $partial = (string) file_get_contents(base_path('resources/views/admin/partials/banners-screen.blade.php'));
    $controller = (string) file_get_contents(app_path('Http/Controllers/Admin/BannerApiController.php'));

    expect($partial)->not->toContain('kbbn-c')
        ->and($partial)->toContain('/preview')
        ->and($controller)->toContain("view('partials.home.cards-banner'");
});

/* ═════════════════════════════ the endpoints ══════════════════════════════ */

it('creates a set as a DRAFT, so a new one cannot reach the shop by accident', function () {
    BannersAdminRoutes::wire(app());
    bnaOwner();

    $body = test()->postJson('/admin-api/banners/sets', ['name' => 'Eid'])->assertCreated()->json();

    expect($body['set']['status'])->toBe('draft')
        ->and($body['set']['slug'])->toBe('eid')
        // Every control arrives at the value that makes a fresh set look like
        // the shop already looks. Rule 1: applying the package moves nothing.
        ->and($body['set']['show_arrows'])->toBeFalse()
        ->and($body['set']['show_dots'])->toBeFalse()
        ->and($body['set']['pause_on_hover'])->toBeTrue()
        ->and($body['set']['show_text'])->toBeTrue()
        ->and($body['set']['per_view'])->toBe(4)
        ->and($body['set']['peek'])->toBe(38)
        ->and($body['set']['ratio'])->toBe('3/4');

    // A second set of the same name does not collide.
    $second = test()->postJson('/admin-api/banners/sets', ['name' => 'Eid'])->assertCreated()->json();

    expect($second['set']['slug'])->toBe('eid-2');
});

it('stores one of its own options, or the default, for every enum', function () {
    /*
     * RULE 5. MUTATION: replace `Rule::in(array_keys(BannerSet::RATIOS))` with
     * `'string'` and the first expectation goes red — a ratio of
     * `; } body{display:none}` would be stored and then printed into a CSS
     * declaration on the homepage.
     *
     * The template refuses it a SECOND time, by looking the token up in the same
     * constant, which is what CardsBannerSectionShapeTest's "rogue" set pins.
     * Two doors for one value: the storefront is the one that matters and is the
     * one furthest from this validator.
     */
    BannersAdminRoutes::wire(app());
    bnaOwner();

    $set = bnaSet();

    foreach (['ratio' => '; } body{display:none}', 'animation' => 'spin', 'shadow' => 'glow', 'status' => 'live'] as $key => $bad) {
        test()->putJson('/admin-api/banners/sets/'.$set->id, [$key => $bad])->assertStatus(422);
    }

    expect($set->fresh()->ratio)->toBe('3/4');

    // And a good one is taken.
    test()->putJson('/admin-api/banners/sets/'.$set->id, ['ratio' => '16/9'])->assertOk();

    expect($set->fresh()->ratio)->toBe('16/9');
});

it('clamps every numeric control rather than refusing it', function () {
    /*
     * A `between` rule on a control the screen also clamps turns a rounding
     * difference into a 422 the owner cannot act on. The bounds are
     * BannerSet::LIMITS, read by the controller rather than retyped.
     *
     * MUTATION: delete the LIMITS loop in updateSet() and a per_view of 99
     * reaches the table, where the card's calc() divides the row by 99 and
     * draws ninety-nine slivers.
     */
    BannersAdminRoutes::wire(app());
    bnaOwner();

    $set = bnaSet();

    test()->putJson('/admin-api/banners/sets/'.$set->id, [
        'per_view' => 99, 'peek' => -40, 'gap' => 9999, 'speed_ms' => 1, 'card_radius' => 400,
    ])->assertOk();

    $fresh = $set->fresh();

    expect($fresh->per_view)->toBe(BannerSet::LIMITS['per_view'][1])
        ->and($fresh->peek)->toBe(BannerSet::LIMITS['peek'][0])
        ->and($fresh->gap)->toBe(BannerSet::LIMITS['gap'][1])
        ->and($fresh->speed_ms)->toBe(BannerSet::LIMITS['speed_ms'][0])
        ->and($fresh->card_radius)->toBe(BannerSet::LIMITS['card_radius'][1]);
});

it('puts a card’s picture into the Media Library and takes its size from it', function () {
    /*
     * The owner's standing rule, in Lane MB's words: "whenever we upload any
     * media, it should go to Media also". The picker's own upload endpoint
     * already registers, so this is the path that is NOT covered by it — a path
     * arriving from an import, a duplicate, or a later screen.
     *
     * AND IT IS WHERE width/height COME FROM. Reading the file header at RENDER
     * time would put a disk read inside the homepage; read once here, the LCP
     * image gets its explicit box for free.
     *
     * MUTATION: drop the MediaRegistrar::record() call and both halves go red —
     * no library row, and a card with no dimensions, so the homepage's first
     * image ships with no width or height and the page shifts as it arrives.
     */
    BannersAdminRoutes::wire(app());
    bnaOwner();

    $set = bnaSet();

    // A real file, because the registrar refuses a row for a file that is not
    // there — a broken thumbnail in the grid forever is what forget() exists
    // to prevent.
    $dir = public_path('uploads/banners');
    @mkdir($dir, 0775, true);
    $file = $dir.'/bn-test-'.uniqid().'.png';
    $image = imagecreatetruecolor(640, 800);
    imagepng($image, $file);
    imagedestroy($image);

    $relative = 'uploads/banners/'.basename($file);

    $card = test()->postJson('/admin-api/banners/sets/'.$set->id.'/cards', [])->assertCreated()->json();

    // THE PICKER HANDS BACK A URL, NOT A PATH — its own docblock says so — so
    // this posts what the screen really posts.
    test()->putJson('/admin-api/banners/cards/'.$card['card']['id'], [
        'image' => 'https://shop.test/'.$relative,
    ])->assertOk();

    $stored = BannerCard::query()->findOrFail($card['card']['id']);

    expect($stored->image)->toBe($relative)
        ->and($stored->image_w)->toBe(640)
        ->and($stored->image_h)->toBe(800)
        ->and(Media::query()->where('path', $relative)->exists())->toBeTrue();

    @unlink($file);
});

it('refuses a path outside the upload roots, and stores nothing rather than the text', function () {
    /*
     * `banner_cards.image` becomes an `<img src>` on the front page and a
     * `public_path()` concatenation in the registrar. A path this shop did not
     * write has no business in either.
     *
     * MUTATION: hand the raw value straight to the column instead of through
     * storedPath() and every one of these is stored verbatim.
     */
    BannersAdminRoutes::wire(app());
    bnaOwner();

    $set = bnaSet();
    $card = test()->postJson('/admin-api/banners/sets/'.$set->id.'/cards', [])->assertCreated()->json();

    foreach ([
        'https://evil.test/x.png',
        '/etc/passwd',
        'uploads/../../etc/passwd',
        'etc/wp-content/uploads/x.png',
        'uploads\\banners\\x.png',
        'javascript:alert(1)',
    ] as $bad) {
        test()->putJson('/admin-api/banners/cards/'.$card['card']['id'], ['image' => $bad])->assertOk();

        expect(BannerCard::query()->findOrFail($card['card']['id'])->image)->toBe('', $bad);
    }
});

it('clears the homepage’s pointer when the set it names is deleted', function () {
    /*
     * THE HALF THAT IS EASY TO FORGET. The shop is correct either way — the
     * join returns no rows for a missing id — but the SCREEN is not: the select
     * would show "None" while the stored value said 7, and the next save of an
     * unrelated control would either resurrect the stale id or silently rewrite
     * it.
     *
     * MUTATION: delete the setModuleSetting('set', '') in destroySet() and the
     * last expectation goes red with the old id still stored.
     */
    BannersAdminRoutes::wire(app());
    bnaOwner();

    $set = bnaSet();
    $settings = app(SettingsService::class);
    $settings->setModuleSetting(Banners::MODULE, 'set', (string) $set->id);

    test()->deleteJson('/admin-api/banners/sets/'.$set->id)->assertOk();

    expect(BannerSet::query()->find($set->id))->toBeNull()
        ->and($settings->moduleSetting(Banners::MODULE, 'set', ''))->toBe('');
});

it('takes a set’s cards with it and copies them on a duplicate', function () {
    BannersAdminRoutes::wire(app());
    bnaOwner();

    $set = bnaSet(['per_view' => 6, 'ratio' => '1/1']);

    foreach (range(1, 3) as $i) {
        BannerCard::create([
            'banner_set_id' => $set->id, 'image' => 'uploads/banners/x'.$i.'.webp',
            'heading' => 'H'.$i, 'position' => $i, 'status' => 'publish',
        ]);
    }

    $copy = test()->postJson('/admin-api/banners/sets/'.$set->id.'/duplicate')->assertCreated()->json();

    // The copy keeps the look and is always a DRAFT, so duplicating the set the
    // homepage is showing cannot produce a second publishable row the owner
    // then edits believing it is the live one.
    expect($copy['set']['status'])->toBe('draft')
        ->and($copy['set']['per_view'])->toBe(6)
        ->and($copy['set']['ratio'])->toBe('1/1')
        ->and($copy['set']['cards_count'])->toBe(3);

    // And deleting the original takes its own cards and none of the copy's.
    test()->deleteJson('/admin-api/banners/sets/'.$set->id)->assertOk();

    expect(BannerCard::query()->where('banner_set_id', $set->id)->count())->toBe(0)
        ->and(BannerCard::query()->where('banner_set_id', $copy['set']['id'])->count())->toBe(3);
});

it('offers only the sets that exist, and stores one of them or nothing', function () {
    /*
     * The module's one setting is a SELECT over another store's rows, which is
     * what `overrides()` is for — the same trade SectionDividers::overrides()
     * makes for its section picker. A posted id that names no set stores '',
     * because POLICY says `invalid => default`.
     *
     * MUTATION: remove `Banners::overrides()` from the normalise() calls in
     * Banners::all()/save() and a real set id becomes unstorable — the select
     * would have exactly one option, "None", and picking a banner would do
     * nothing at all.
     */
    BannersAdminRoutes::wire(app());
    bnaOwner();

    $set = bnaSet(['name' => 'Ramadan']);
    $draft = bnaSet(['name' => 'Eid', 'status' => 'draft']);

    $options = Banners::overrides()['set']['options'];

    expect($options)->toHaveKey((string) $set->id)
        ->and($options[(string) $set->id])->toBe('Ramadan')
        // A draft is listed and MARKED rather than hidden: an owner who has
        // drafted the set he meant to show should be told why the homepage is
        // empty, not left to wonder which of his sets vanished.
        ->and($options[(string) $draft->id])->toBe('Eid (draft — will not show)');

    $banners = app(Banners::class);

    $banners->save(['set' => (string) $set->id]);
    expect($banners->all()['set'])->toBe((string) $set->id);

    $banners->save(['set' => '9999']);
    expect($banners->all()['set'])->toBe('');

    // And an unknown key cannot ride in.
    test()->postJson('/admin-api/banners', ['settings' => ['nope' => 1]])->assertStatus(422);
});

it('offers the real sets in the control that chooses one', function () {
    /*
     * THE DEFECT THIS WOULD HAVE CAUGHT, and it shipped into the first round of
     * screenshots: the screen drew its picker from `tabs[].fields[].options`,
     * and `ModuleSchema::fields()` emits the module's OWN SCHEMA options rather
     * than `overrides()` — its docblock says so at length. So the only option
     * was the schema's literal "None", and a shop with a published set could
     * not see, in the control that chooses which banner shows, the set its
     * homepage was already showing. The row above it said "On the homepage"
     * while the select said "None".
     *
     * The fix is the shape ProductStyles and SectionDividers already use: the
     * option set arrives as its OWN top-level key and the screen draws from it.
     *
     * MUTATION: delete `'setOptions' => self::overridesOptions()` from
     * BannerApiController::show() and this goes red on the first expectation;
     * point the screen back at the field's options and it goes red on the
     * second.
     */
    BannersAdminRoutes::wire(app());
    bnaOwner();

    $set = bnaSet(['name' => 'Ramadan']);

    $body = test()->getJson('/admin-api/banners')->assertOk()->json();

    expect($body['setOptions'])->toHaveKey((string) $set->id)
        ->and($body['setOptions'][(string) $set->id])->toBe('Ramadan')
        ->and($body['setOptions'][''])->toBe('None — nothing shows on the homepage');

    $screen = (string) file_get_contents(base_path('resources/views/admin/partials/banners-screen.blade.php'));

    expect($screen)->toContain('data.setOptions');

    // And the option the screen offers is one the save really accepts — the
    // list and the cast come from the same call, so they cannot drift.
    test()->postJson('/admin-api/banners', ['settings' => ['set' => (string) $set->id]])->assertOk();

    expect(app(Banners::class)->all()['set'])->toBe((string) $set->id);
});

it('keeps the schema and the tabs naming the same fields', function () {
    /*
     * The pairing ModuleFrameworkGuardTest enforces for every enrolled module:
     * neither constant may name something the other does not. This module is
     * not on that file's list — that file is not this lane's — so the same
     * check is made here, and the lane report asks the integrator to enrol it.
     */
    $schema = array_keys(ModuleSchema::normalise(Banners::SCHEMA, Banners::POLICY, Banners::overrides()));
    $tabbed = [];

    foreach (Banners::TABS as [$label, $description, $keys]) {
        expect($label)->not->toBe('')->and($description)->not->toBe('');

        foreach ($keys as $key) {
            /*
             * `expect(in_array(...))->toBeFalse($message)` and NOT
             * `->not->toContain($key, $message)`. ExpectationsThatCannotFail
             * Test caught the second form here and is right to: toContain is
             * VARIADIC, so the message is read as a second NEEDLE and the
             * expectation can never fail. It was written the wrong way first.
             */
            expect(in_array($key, $tabbed, true))->toBeFalse(
                "{$key} is placed twice — the same control drawn twice, and whichever was filled in last appears to win"
            );
            $tabbed[] = $key;
        }
    }

    sort($schema);
    sort($tabbed);

    expect($tabbed)->toBe($schema);

    // And every field carries a label, which the guard refuses to do without.
    foreach (ModuleSchema::normalise(Banners::SCHEMA, Banners::POLICY, Banners::overrides()) as $key => $field) {
        expect($field['label'] ?? '')->not->toBe('', $key);
    }
});
