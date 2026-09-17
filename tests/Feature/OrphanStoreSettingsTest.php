<?php

declare(strict_types=1);

/**
 * Lane DN — two settings the storefront reads, and whether anything can write
 * them.
 *
 * A KEY WITH A READER AND NO WRITER is the quietest defect this console
 * produces. Nothing errors, no page is blank, no test fails: the storefront
 * simply runs on whatever a seeder put there, for ever, and the owner has no
 * way to know the value is his to choose. Both keys below are read on every
 * page load of some part of the shop.
 *
 * AND THE HALF-FIX IS WORSE THAN THE GAP. A control drawn on a screen whose key
 * is not in AdminController::SETTING_RULES posts happily, is dropped, and the
 * endpoint still answers ok — the standing warning at the top of that list, and
 * a mistake this console has already made. So every assertion here comes in
 * pairs: the control is drawn, AND the key is in the payload, AND the write
 * lands in the database and is read back by the code that consumes it.
 *
 * WHAT WAS ACTUALLY FOUND, because the brief for this lane said both keys were
 * orphaned and only one was:
 *
 *   brand_accent          orphaned. Read at StoreComposer:74, seeded, and
 *                         written by nothing. Fixed here.
 *   hide_paid_when_free   NOT orphaned. Store → Payment & Shipping Rules edits
 *                         it through PayShipRules::save(), which writes that
 *                         exact key deliberately rather than inventing a second
 *                         one. Nothing needed building; it is pinned instead,
 *                         because a chain that runs through a SCHEMA constant,
 *                         a controller and a settings service is one an
 *                         innocent-looking rename breaks silently.
 */

use App\Http\Controllers\Admin\AdminController;
use App\Models\AdminUser;
use App\Services\ModuleSchema;
use App\Services\PayShipRules;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Hash;

function dnConsole(): string
{
    static $src = null;

    return $src ??= (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

/** The Business Details screen's markup, and separately its save handler. */
function dnBizMarkup(): string
{
    $src = dnConsole();
    $start = strpos($src, 'async function renderStoreSettings(');
    $end = strpos($src, "document.getElementById('set_save_biz').onclick", (int) $start);

    expect($start)->not->toBeFalse('cannot find renderStoreSettings — this check is blind');
    expect($end)->not->toBeFalse('cannot find the Business Details save — this check is blind');

    return substr($src, (int) $start, (int) $end - (int) $start);
}

function dnBizHandler(): string
{
    $src = dnConsole();
    $start = strpos($src, "document.getElementById('set_save_biz').onclick");
    $end = strpos($src, 'function seoSel(', (int) $start);

    expect($end)->not->toBeFalse('cannot find the end of the save handler — this check is blind');

    return substr($src, (int) $start, (int) $end - (int) $start);
}

/** Signed in as an owner, which is what the settings endpoint requires. */
function dnAsOwner(): void
{
    $owner = AdminUser::create([
        'name' => 'DN Owner',
        'email' => 'dn-owner@example.com',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);

    test()->actingAs($owner, 'admin');
}

/*
 * SettingsService memoises in a process-level static as well as in the cache
 * (CLAUDE.md), and Setting::map() keeps a third copy. Every test here writes a
 * setting through an endpoint and then reads it back, which is exactly the
 * shape that trap is set for.
 */
afterEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    \App\Models\Setting::flushMap();
});

/* ===================================================== brand_accent ===== */

it('draws a control for the brand accent colour', function () {
    $markup = dnBizMarkup();

    expect(str_contains($markup, "'set_brand_accent'"))
        ->toBeTrue('nothing on Business Details draws the brand colour field');

    // The band it sits in, so the field is not an unlabelled box: this screen's
    // standard is a heading plus one line saying when you would touch it.
    expect(str_contains($markup, 'Your brand colour'))
        ->toBeTrue('the brand colour field has no band of its own');
});

it('posts the brand accent colour when Business Details is saved', function () {
    /*
     * THE OTHER HALF, and the one an earlier lane on this screen shipped
     * without: a field that is drawn and never sent is a field the owner fills
     * in, saves, and watches do nothing.
     */
    expect(preg_match("/brand_accent:\s*sval\('set_brand_accent'\)/", dnBizHandler()))
        ->toBe(1, 'the Business Details save does not post brand_accent');
});

it('accepts the brand accent colour at the settings endpoint', function () {
    // And the third half, which is neither of the two above: a key that is not
    // in SETTING_RULES is dropped while the endpoint still answers ok.
    expect(array_key_exists('brand_accent', AdminController::SETTING_RULES))
        ->toBeTrue('brand_accent is not on the endpoint allowlist, so saving it writes nothing');

    dnAsOwner();

    test()->putJson('/admin-api/settings', [
        'settings' => ['brand_accent' => '#123ABC'],
    ])->assertOk()->assertJsonPath('ok', true);

    expect(app(SettingsService::class)->get('brand_accent'))->toBe('#123abc');
});

it('repaints the storefront with the colour that was saved', function () {
    /*
     * End to end, because every link in the chain above can be right while the
     * page still ignores the value: StoreComposer only emits an override when
     * Color::isValidHex() passes AND the colour differs from the design
     * default, and layouts/store.blade.php is what turns it into --pink.
     */
    dnAsOwner();

    test()->putJson('/admin-api/settings', [
        'settings' => ['brand_accent' => '#2E7D32'],
    ])->assertOk();

    $html = (string) test()->get('/')->assertOk()->getContent();

    expect(str_contains($html, '--pink:#2e7d32'))
        ->toBeTrue('the storefront is not painting the colour the owner saved');
});

it('refuses a colour the storefront would silently ignore', function () {
    /*
     * `text` would have been the easy rule and the wrong one. StoreComposer
     * validates and falls back, so "rose pink" would save, report Saved, and
     * change nothing — the owner would be looking at the old colour with the
     * new one in the box.
     */
    dnAsOwner();

    test()->putJson('/admin-api/settings', [
        'settings' => ['brand_accent' => 'rose pink'],
    ])->assertStatus(422);

    expect(app(SettingsService::class)->get('brand_accent', 'unset'))->toBe('unset');
});

it('lets a cleared box mean the theme’s own colour', function () {
    /*
     * SettingsService::get() returns its default only when the ROW is absent,
     * and a cleared input stores '' rather than nothing — so if '' were
     * refused, the default would be unreachable once a colour had been set.
     * The reader treats anything it cannot parse as absent, which is what makes
     * blank the honest way back.
     */
    dnAsOwner();

    test()->putJson('/admin-api/settings', [
        'settings' => ['brand_accent' => '#2E7D32'],
    ])->assertOk();

    test()->putJson('/admin-api/settings', [
        'settings' => ['brand_accent' => ''],
    ])->assertOk();

    expect(app(SettingsService::class)->get('brand_accent'))->toBe('');

    $html = (string) test()->get('/')->assertOk()->getContent();

    expect(str_contains($html, 'id="kbb-brand-accent"'))
        ->toBeFalse('a cleared colour still overrides the theme');
});

it('no longer tells the owner that no colour can be edited', function () {
    /*
     * The console said so twice on the Theme screen, and a console that says a
     * thing is impossible is how a missing writer survives being noticed. Read
     * as rendered COPY: these are sentences, so they are searched for as
     * sentences, but the banned phrasing is assembled at run time so that this
     * test's own source cannot satisfy a grep of the console.
     */
    $src = dnConsole();

    $gone = [
        'There is no colour editor' . ' behind this console',
        'nothing here writes' . ' a palette',
        'They are part of the theme&rsquo;s stylesheet' . ' rather than a setting',
    ];

    foreach ($gone as $claim) {
        expect(str_contains($src, $claim))
            ->toBeFalse('the Theme screen still claims no colour is editable: '.$claim);
    }

    // And it points at the screen that does edit it, rather than going quiet.
    expect(substr_count($src, 'Business Details &rarr; Your brand colour'))
        ->toBeGreaterThan(0, 'nothing on the Theme screen says where the accent colour is set');
});

/* ============================================== hide_paid_when_free ===== */

it('already has a control, contrary to the brief for this lane', function () {
    /*
     * Store → Payment & Shipping Rules draws its fields from PayShipRules
     * ::SCHEMA over /admin-api/pay-ship-rules, so "the control renders" is a
     * question about that payload rather than about markup in the console: the
     * screen's own field list is built from whatever show() answers.
     */
    expect(array_key_exists('hide_paid_free', PayShipRules::SCHEMA))
        ->toBeTrue('the Payment & Shipping Rules screen no longer offers the switch');

    dnAsOwner();

    $body = test()->getJson('/admin-api/pay-ship-rules')->assertOk()->json();

    $keys = [];

    foreach ($body['tabs'] as $tab) {
        foreach ($tab['fields'] as $field) {
            $keys[] = $field['key'];
        }
    }

    expect(in_array('hide_paid_free', $keys, true))
        ->toBeTrue('the switch is in the schema but not in the field list the screen draws from');
});

it('writes the key the shop actually reads, not one of its own', function () {
    /*
     * THE LINK THAT IS EASY TO BREAK. `hide_paid_free` is the field name and
     * `hide_paid_when_free` is the setting five call sites read — PayShipRules
     * ::FREE_KEY exists precisely so that the module edits the key that already
     * had readers instead of inventing a second one. A rename on either side
     * that missed the other would leave a switch that saves something nothing
     * reads, and a shop that goes on behaving the old way while the console
     * says otherwise.
     */
    dnAsOwner();

    test()->postJson('/admin-api/pay-ship-rules', [
        'settings' => ['hide_paid_free' => false],
    ])->assertOk()->assertJsonPath('ok', true);

    expect((bool) app(SettingsService::class)->get('hide_paid_when_free', true))
        ->toBeFalse('turning the switch off did not reach hide_paid_when_free');

    test()->postJson('/admin-api/pay-ship-rules', [
        'settings' => ['hide_paid_free' => true],
    ])->assertOk();

    expect((bool) app(SettingsService::class)->get('hide_paid_when_free', true))
        ->toBeTrue('turning the switch back on did not reach hide_paid_when_free');
});

it('reads back through the same screen it was saved from', function () {
    // A save that lands in a key the screen cannot read again shows the owner
    // the old position of a switch he has just moved.
    dnAsOwner();

    test()->postJson('/admin-api/pay-ship-rules', [
        'settings' => ['hide_paid_free' => false],
    ])->assertOk();

    $body = test()->getJson('/admin-api/pay-ship-rules')->assertOk()->json();

    $value = null;

    foreach ($body['tabs'] as $tab) {
        foreach ($tab['fields'] as $field) {
            if ($field['key'] === 'hide_paid_free') {
                $value = $field['value'];
            }
        }
    }

    expect($value)->toBeFalse('the screen reports the switch on after it was turned off');
});

it('explains the switch in words rather than pointing at a screen that is not there', function () {
    /*
     * The help text said "The same setting as Store → Ecommerce → Delivery.
     * Changing it here changes it there", and there is no such control: nothing
     * in the console writes this key but this screen. An owner following that
     * sentence goes looking for a second switch to cross-check against and
     * finds nothing, which is worse than no help at all.
     */
    /*
     * Read through ModuleSchema rather than by position. PayShipRules moved onto
     * the shared schema in Lane EH, so `[3]` — which was the help slot in the
     * positional `[type, label, default, help]` form — is no longer a key that
     * exists. field() widens either form to the same named shape, which is what
     * a test asking "what does the help say" wanted in the first place.
     */
    $help = ModuleSchema::field('hide_paid_free', PayShipRules::SCHEMA['hide_paid_free'])['help'];

    expect(str_contains($help, 'Ecommerce'))
        ->toBeFalse('the help still sends the owner to a screen that does not carry this setting');

    // And it says what the switch does, in the words a shopper would see it in.
    expect(str_contains($help, 'free delivery') && str_contains($help, 'paid delivery'))
        ->toBeTrue('the help no longer says what the switch actually does: '.$help);
});
