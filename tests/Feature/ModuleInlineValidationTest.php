<?php

/**
 * `inline_validation`, switched on and off against a real checkout — Lane FI.
 *
 * Not "the class exists" and not "the setting saves". Every test here flips the
 * switch the owner would flip on Store → Modules, or moves the control the
 * owner would move on Store → Ecommerce → Checkout, and then asks for the page
 * a shopper would ask for. The brief this lane works to says a module guard
 * that asserts a setting round-trips proves nothing about the storefront, and
 * the defects this repo keeps finding are all in that gap:
 *
 *   `single_name`   — the toggle saved; the checkout ignored it.
 *   quick view      — a button, a modal, CSS, a route and a controller, and no
 *                     listener.
 *   `seo_engine`    — a working form writing into a void.
 *
 * Following ModulePortsOnOffTest's shape, each behaviour is pinned three ways
 * because two are not enough: ON gives the new behaviour, OFF gives the old one
 * BACK, and the DEFAULT is what a store that has touched nothing receives.
 *
 * ── WHAT EACH TEST FAILS AGAINST ────────────────────────────────────────────
 *
 * Proven by reverting, not asserted:
 *
 *   - Remove the include from store/checkout.blade.php and the four tests that
 *     fetch the page with the module on fail: the marks, the island, and each
 *     of the three settings.
 *   - Restore the registry default to the plugin's `true` and
 *     'ships off by default' fails.
 *   - Restore ModuleSeeder's `'inline_validation' => true` and
 *     'a store the seeder has touched' fails.
 *   - Drop the alignment migration and that same test fails.
 *   - Drop one of the three fields from EcommerceApiController and
 *     'draws a real control' fails on that field.
 */

use App\Models\AdminUser;
use App\Models\Product;
use App\Services\ModuleRegistry;
use App\Services\SettingsService;
use App\Support\InlineValidation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function fiModule(string $key, bool $on): void
{
    app(SettingsService::class)->setModule($key, $on);
}

afterEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
});

/**
 * A cart with something in it, so /checkout/ renders rather than redirecting an
 * empty cart back to the shop. Same shape and the same reasoning as
 * ModulePortsOnOffTest's helper: the cookie is handed over unencrypted with
 * EncryptCookies dropped, because a cookie set without Laravel's envelope
 * arrives as nothing, and a checkout that quietly has no cart looks exactly
 * like a checkout whose module is off — which would make this whole file pass
 * for the wrong reason.
 */
function fiCheckoutHtml(): string
{
    $product = Product::create([
        'slug' => 'fi-iv-'.uniqid(),
        'name' => 'FI Inline Validation Product',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 5000,
        'stock_status' => 'instock',
    ]);

    $cart = \App\Models\Cart::create([
        'token' => (string) Illuminate\Support\Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 5000]);

    return test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(\App\Services\CartService::COOKIE, $cart->token)
        ->get('/checkout/')->assertOk()->getContent();
}

/**
 * The traces this module leaves, every one of them, in one list.
 *
 * Checked as a SET rather than one marker, because "off means off" is a claim
 * about the whole of what the module emits and a test that looked for one class
 * name would pass with the stylesheet still on the page. These are the three
 * separate things the partial can put there: the message element's class, the
 * Woo row class the script writes, and the config island's own key.
 */
const FI_IV_TRACES = ['kbb-iv-msg', 'woocommerce-invalid', 'woocommerce-validated', '"when"'];

function fiTraceCount(string $html): int
{
    $n = 0;

    foreach (FI_IV_TRACES as $needle) {
        $n += substr_count($html, $needle);
    }

    return $n;
}

/* ───────────────────────────── the default ───────────────────────────────── */

it('ships off by default, so applying the package changes no checkout', function () {
    /*
     * OFF, and deliberately NOT the plugin's own default for the checkout
     * group, which is on. App\Support\InlineValidation's header argues it: the
     * default is measured against what this store does WITHOUT the switch, and
     * without it this checkout marks nothing at all.
     */
    expect(ModuleRegistry::REGISTRY['inline_validation'][3])->toBeFalse();

    // No row at all — a genuinely fresh install, where moduleEnabled() falls
    // back to the registry default.
    DB::table('module_toggles')->where('module', 'inline_validation')->delete();
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();

    expect(InlineValidation::config(app(SettingsService::class)))->toBeNull();
    expect(fiTraceCount(fiCheckoutHtml()))->toBe(0);
});

it('seeds a fresh install off, so the seeder and the registry agree', function () {
    /*
     * ModuleSeeder wrote `true` for this key, copied from the plugin along with
     * the rest of the checkout group, while nothing in the app read it. Run the
     * real seeder against a clean table and check what it leaves — reading the
     * constant would only prove the constant.
     */
    DB::table('module_toggles')->where('module', 'inline_validation')->delete();

    (new Database\Seeders\ModuleSeeder)->run();

    expect((bool) DB::table('module_toggles')->where('module', 'inline_validation')->value('enabled'))
        ->toBeFalse('ModuleSeeder still seeds inline_validation on');
});

it('takes the switch down on a store the seeder already touched', function () {
    /*
     * The alignment migration's whole job, exercised rather than assumed.
     *
     * An earlier draft of this test read the row the suite happened to have and
     * asserted nothing when it was absent — a guard that passes whether or not
     * the migration exists, which is the fault six guards on this project were
     * caught committing. So the pre-migration state is CREATED here, the
     * storefront is checked to be genuinely marking, the migration is then run
     * the way the updater runs it, and the storefront is checked again.
     */
    DB::table('module_toggles')->where('module', 'inline_validation')->delete();
    DB::table('module_toggles')->insert([
        'module' => 'inline_validation',
        'enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();

    /*
     * This is what applying the package WITHOUT the migration would do:
     * moduleEnabled() returns the stored row whenever one exists and only falls
     * back to the registry default when it does not, so every install the
     * seeder ever ran against would come up marking the one form the shop is
     * paid through, chosen by nobody.
     */
    expect(app(SettingsService::class)->moduleEnabled('inline_validation', false))->toBeTrue();
    expect(fiTraceCount(fiCheckoutHtml()))->toBeGreaterThan(0);

    (require database_path('migrations/2026_11_14_000001_align_inline_validation_module_toggle.php'))->up();

    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();

    expect((bool) DB::table('module_toggles')->where('module', 'inline_validation')->value('enabled'))->toBeFalse();
    expect(fiTraceCount(fiCheckoutHtml()))->toBe(0);
});

it('leaves an absent row absent rather than writing one that says the same thing', function () {
    // The one difference from the mega_menu migration this is modelled on, and
    // the migration's own comment argues it: absent already means off here.
    DB::table('module_toggles')->where('module', 'inline_validation')->delete();

    (require database_path('migrations/2026_11_14_000001_align_inline_validation_module_toggle.php'))->up();

    expect(DB::table('module_toggles')->where('module', 'inline_validation')->exists())->toBeFalse();
});

/* ─────────────────────────── on, and then off again ──────────────────────── */

it('puts the marks on the checkout once the module is switched on', function () {
    fiModule('inline_validation', true);

    $html = fiCheckoutHtml();

    // The message element's style, the Woo classes the script writes, and the
    // island the script reads — the module is all three or it is not ported.
    expect($html)->toContain('kbb-iv-msg');
    expect($html)->toContain('woocommerce-invalid');
    expect($html)->toContain('woocommerce-validated');

    // The wording is the keyed English, rendered by the server rather than
    // typed into the script.
    expect($html)->toContain(__('store.checkout.validate_email'));
});

it('leaves no trace on the checkout when the module is switched off again', function () {
    fiModule('inline_validation', true);
    expect(fiTraceCount(fiCheckoutHtml()))->toBeGreaterThan(0);

    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();

    fiModule('inline_validation', false);

    // Not one of them. No style block left behind with nothing to select, no
    // script sitting inert, no island. The plan's own rule for a switched-off
    // module, checked by fetching the page.
    expect(fiTraceCount(fiCheckoutHtml()))->toBe(0);
});

it('does not reach any other form on the shop', function () {
    fiModule('inline_validation', true);

    // The cart page has .form-row rows of its own and the module is scoped to
    // #kbbCheckoutForm; a style block that leaked onto every page would mark
    // those too.
    $cart = test()->get('/cart/')->assertOk()->getContent();

    expect(fiTraceCount($cart))->toBe(0);
});

/* ───────────────────── each control changes the page ─────────────────────── */

it('carries the timing setting through to the page', function () {
    fiModule('inline_validation', true);

    // The default, and it is 'blur' rather than the plugin blurb's "as they
    // type" — the argument is on InlineValidation.
    expect(InlineValidation::DEFAULT_WHEN)->toBe('blur');
    expect(fiCheckoutHtml())->toContain('"when":"blur"');

    app(SettingsService::class)->set(InlineValidation::KEY_WHEN, 'type');
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();

    expect(fiCheckoutHtml())->toContain('"when":"type"');
});

it('falls a stored typo back to the default rather than shipping it to the browser', function () {
    fiModule('inline_validation', true);

    app(SettingsService::class)->set(InlineValidation::KEY_WHEN, 'whenever');
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();

    expect(fiCheckoutHtml())->toContain('"when":"blur"');
    expect(fiCheckoutHtml())->not->toContain('whenever');
});

it('carries the green half and the wording half through independently', function () {
    fiModule('inline_validation', true);

    // Both on by default.
    expect(fiCheckoutHtml())->toContain('"ok":true');
    expect(fiCheckoutHtml())->toContain('"hint":true');

    app(SettingsService::class)->set(InlineValidation::KEY_OK, false);
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();

    $html = fiCheckoutHtml();
    expect($html)->toContain('"ok":false');
    // The other half is untouched by it, which is what makes them two controls
    // rather than one.
    expect($html)->toContain('"hint":true');

    app(SettingsService::class)->set(InlineValidation::KEY_HINT, false);
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();

    expect(fiCheckoutHtml())->toContain('"hint":false');
});

/* ───────────────────── the control exists, on the screen ─────────────────── */

function fiAsOwner(): void
{
    $owner = AdminUser::create([
        'name' => 'FI Owner',
        'email' => 'fi-owner@example.com',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);

    test()->actingAs($owner, 'admin');
}

it('draws a real control for each of the three settings on Store → Ecommerce → Checkout', function () {
    /*
     * The ENDPOINT, not the source constant. This is the difference between "a
     * constant lists this key" and "the owner is shown a box for it" — the two
     * that came apart on `reassure_auth_text`, which looked settings-driven for
     * its whole life and was not.
     */
    fiAsOwner();

    $body = test()->getJson('/admin-api/ecommerce')->assertOk()->json();

    $checkout = collect($body['tabs'])->firstWhere('key', 'checkout');
    expect($checkout)->not->toBeNull();

    $drawn = [];

    foreach ($checkout['sections'] as $section) {
        foreach ($section['fields'] as $field) {
            $drawn[$field['name']] = $field;
        }
    }

    foreach ([InlineValidation::KEY_WHEN, InlineValidation::KEY_OK, InlineValidation::KEY_HINT] as $key) {
        expect(isset($drawn[$key]))->toBeTrue("{$key} has no control on Store → Ecommerce → Checkout");
        expect($drawn[$key]['type'])->not->toBe('', "{$key} renders with no type");
        expect($drawn[$key])->toHaveKey('value');
    }

    // The select offers exactly the two values the script compares against, and
    // not a third the page could not honour.
    expect($drawn[InlineValidation::KEY_WHEN]['options'])->toBe(InlineValidation::WHEN);

    // And it is in a named section rather than falling into the console's
    // catch-all, which is where a field the owner cannot find ends up.
    $named = collect($checkout['sections'])->firstWhere('key', 'validation');
    expect($named)->not->toBeNull('the three controls are in no section of their own');
});

it('saves the three settings through the screen the owner really uses', function () {
    /*
     * Posted at the real endpoint, and then read back through the same reader
     * the checkout uses — so this fails if the value is written under a
     * different key, dropped by the cast, or read from somewhere else.
     *
     * Only these three keys are sent. The rest of the tab is deliberately not
     * included: EcommerceApiController::save() ignores a key that is absent,
     * and sending the whole tab would make this test fail for a reason that has
     * nothing to do with this module — see this file's closing note.
     */
    fiAsOwner();
    fiModule('inline_validation', true);

    test()->postJson('/admin-api/ecommerce', ['settings' => [
        InlineValidation::KEY_WHEN => 'type',
        InlineValidation::KEY_OK => false,
        InlineValidation::KEY_HINT => false,
    ]])->assertOk()->assertJson(['ok' => true]);

    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();

    expect(InlineValidation::config(app(SettingsService::class)))
        ->toBe(['when' => 'type', 'ok' => false, 'hint' => false]);

    // And it reaches the shopper, which is the half a settings round-trip
    // cannot show.
    expect(fiCheckoutHtml())->toContain('"when":"type"');
});

it('refuses a timing value the script could not honour', function () {
    fiAsOwner();

    test()->postJson('/admin-api/ecommerce', ['settings' => [
        InlineValidation::KEY_WHEN => 'sometimes',
    ]])->assertStatus(422);
});

/* ─────────────────────────── the registry tells the truth ────────────────── */

it('names the tab the controls are really on', function () {
    $row = ModuleRegistry::REGISTRY['inline_validation'];

    // Was 'todo' with no screen at all — the row the console renders as "Not
    // ported yet" beside a switch that does nothing.
    expect($row[9])->toBe('live');
    expect($row[4])->toBe('Store → Ecommerce → Checkout');
    expect($row[5])->toBe('ecommerce:checkout');
});

/* ───────────────────────── the wording is translatable ───────────────────── */

it('keys every sentence the shopper can be shown', function () {
    /*
     * StorefrontStringsAreKeyedTest walks the directory and would catch a
     * sentence typed into the Blade. It cannot catch one typed into the JSON
     * island through a variable, so the four are named here: each must resolve
     * to real English rather than echoing its own key back, which is what
     * Laravel returns for a key that does not exist.
     */
    foreach (['required', 'email', 'state', 'phone'] as $rule) {
        $key = "store.checkout.validate_{$rule}";

        expect(__($key))->not->toBe($key, "{$key} has no English");
        expect(__($key))->toEndWith('.');
    }
});
