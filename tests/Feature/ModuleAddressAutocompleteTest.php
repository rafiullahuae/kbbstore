<?php

/**
 * `address_autocomplete`, and the retirement of `performance` — Lane M,
 * Phase 3's last two `todo` rows.
 *
 * ── WHAT THIS MODULE IS AND IS NOT ──────────────────────────────────────────
 *
 * It is not finished, and these tests are written so it cannot be mistaken for
 * finished. Two things are missing and neither is code: a Google Places API key
 * with a billing account behind it, and the OWNER'S ANSWER to whether shoppers'
 * half-typed home addresses may be sent to Google. Egress from the build
 * container is blocked, so not one request to Google has been made from here
 * and none could be.
 *
 * So what is pinned below is the half that can be: that the gate, the key and
 * the consent question each independently keep the checkout exactly as it is,
 * and that the ONE seam where Google's origin is written —
 * AddressAutocomplete::scriptUrl() — builds what it should and refuses what it
 * should. The suggestions themselves are the owner's to enable and nobody
 * else's.
 *
 * ── THE SHAPE, FOLLOWING ModulePortsOnOffTest ───────────────────────────────
 *
 * ON gives the new behaviour, OFF gives the old one back, and the DEFAULT is
 * what a store that has touched nothing receives. Two of those are not enough:
 * the defects this repo keeps finding live in the gap between a switch that
 * saves and a page that changes.
 *
 * Here there are THREE locks rather than one, so each is tested alone with the
 * other two open. A module that renders nothing because all three are shut
 * tells you nothing about any of them.
 *
 * ── MUTATIONS ACTUALLY RUN ──────────────────────────────────────────────────
 *
 *   - Remove the @include from resources/views/store/checkout.blade.php and
 *     "it loads Google's script only when the owner has said yes and stored a
 *     key" fails: the page carries no script at all.
 *   - Change AddressAutocomplete::config() to skip the consent check and
 *     "it sends nothing until the owner answers the privacy question" fails on
 *     both the unanswered and the no case.
 *   - Restore the registry default for `address_autocomplete` to the plugin's
 *     `true` and "it ships off by default" fails.
 *   - Change the `performance` row's status back to 'todo' and "it does not
 *     promise unbuilt work for a module that will never be built" fails.
 */

use App\Models\AdminUser;
use App\Models\Product;
use App\Services\ModuleRegistry;
use App\Services\SettingsService;
use App\Support\AddressAutocomplete;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function mAaModule(bool $on): void
{
    app(SettingsService::class)->setModule('address_autocomplete', $on);
}

function mAaSet(string $key, string $value): void
{
    app(SettingsService::class)->set($key, $value);
}

afterEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
});

/**
 * A cart with something in it, so /checkout/ renders rather than redirecting an
 * empty cart back to the shop — the same helper and the same reasoning as
 * ModuleInlineValidationTest: a checkout that quietly has no cart looks exactly
 * like a checkout whose module is off, which would make this whole file pass
 * for the wrong reason.
 */
function mAaCheckoutHtml(): string
{
    $product = Product::create([
        'slug' => 'm-aa-'.uniqid(),
        'name' => 'M Address Autocomplete Product',
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

/** Every trace this module can leave on a page, as a set. */
function mAaTraces(string $html): array
{
    return [
        'google' => substr_count($html, 'maps.googleapis.com'),
        'island' => substr_count($html, 'kbb-addr-cfg'),
        'global' => substr_count($html, 'KBB_ADDR'),
    ];
}

/** A key shaped the way Google issues them, for tests that need a valid one. */
function mAaKey(): string
{
    return 'AIzaSyD-ExampleExampleExampleExample123';
}

/* ─────────────────────────── off means off ──────────────────────────────── */

it('ships off by default, so applying the package changes the checkout by nothing', function () {
    /*
     * THE ROW SAID `true` UNTIL THIS LANE and nothing read it, which is the
     * landmine docs/FI-PHASE3-MODULE-INVENTORY.md flagged in as many words:
     * "It is seeded true, its registry default is true, and nothing reads it.
     * Whoever ports it must ship an alignment migration in the same package, or
     * that module comes on by itself on every existing store."
     *
     * This is the registry half. The stored-row half is two tests below.
     */
    expect(ModuleRegistry::REGISTRY['address_autocomplete'][3])->toBeFalse();

    // No row at all — a genuinely fresh install, where moduleEnabled() falls
    // back to the registry default rather than to anything stored.
    DB::table('module_toggles')->where('module', 'address_autocomplete')->delete();
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();

    expect(AddressAutocomplete::config(app(SettingsService::class)))->toBeNull();
    expect(mAaTraces(mAaCheckoutHtml()))->toBe(['google' => 0, 'island' => 0, 'global' => 0]);
});

it('sends nothing when the module is off, whatever else is configured', function () {
    // Both other locks OPEN, so the only thing keeping the page quiet is the
    // switch. Testing all three shut at once would prove nothing about any one.
    mAaSet(AddressAutocomplete::KEY_API, mAaKey());
    mAaSet(AddressAutocomplete::KEY_CONSENT, 'yes');
    mAaModule(false);

    expect(AddressAutocomplete::config(app(SettingsService::class)))->toBeNull();
    expect(mAaTraces(mAaCheckoutHtml()))->toBe(['google' => 0, 'island' => 0, 'global' => 0]);
});

it('sends nothing until the owner answers the privacy question', function () {
    /*
     * THE DEFECT THIS GUARDS AGAINST, stated plainly: a shop that switched the
     * module on and pasted in a key would, without this check, begin posting
     * every character a shopper types into the address box to Google — before
     * the order is placed and whether or not it ever is — without anybody
     * having agreed to that. Pasting a key and agreeing to share customers'
     * addresses are different acts and this keeps them different.
     *
     * `unanswered` is the shipped value and behaves exactly like `no`.
     */
    mAaModule(true);
    mAaSet(AddressAutocomplete::KEY_API, mAaKey());

    foreach (['unanswered', 'no'] as $answer) {
        mAaSet(AddressAutocomplete::KEY_CONSENT, $answer);
        app(SettingsService::class)->flush();
        SettingsService::forgetMemo();

        expect(AddressAutocomplete::config(app(SettingsService::class)))
            ->toBeNull("consent “{$answer}” must send nothing");
        expect(mAaTraces(mAaCheckoutHtml()))
            ->toBe(['google' => 0, 'island' => 0, 'global' => 0], "consent “{$answer}” put bytes on the page");
    }
});

it('sends nothing when no key is stored, however the question was answered', function () {
    mAaModule(true);
    mAaSet(AddressAutocomplete::KEY_CONSENT, 'yes');
    mAaSet(AddressAutocomplete::KEY_API, '');

    expect(AddressAutocomplete::config(app(SettingsService::class)))->toBeNull();
    expect(mAaTraces(mAaCheckoutHtml()))->toBe(['google' => 0, 'island' => 0, 'global' => 0]);
});

/* ──────────────────────────── on means on ───────────────────────────────── */

it('loads Google’s script only when the owner has said yes and stored a key', function () {
    mAaModule(true);
    mAaSet(AddressAutocomplete::KEY_API, mAaKey());
    mAaSet(AddressAutocomplete::KEY_CONSENT, 'yes');

    $config = AddressAutocomplete::config(app(SettingsService::class));

    expect($config)->not->toBeNull();
    expect($config['country'])->toBe('ae');

    $html = mAaCheckoutHtml();
    $traces = mAaTraces($html);

    expect($traces['island'])->toBeGreaterThan(0);
    expect($traces['global'])->toBeGreaterThan(0);
    /*
     * The URL reaches the page, with the key and the places library on it.
     *
     * Matched against the JSON-ESCAPED form, because that is what is really on
     * the page: the config travels in an `application/json` island written with
     * @json, which is json_encode with JSON_HEX_TAG|HEX_APOS|HEX_QUOT|HEX_AMP,
     * so `/` arrives as `\/` and `&` as `\u0026`. Asserting the raw URL passed
     * for the wrong reason would have been easy here — `maps.googleapis.com`
     * alone survives escaping — so the whole path is checked in the form the
     * browser is handed, which is also the evidence that the island is escaped
     * rather than interpolated.
     */
    expect($html)->toContain('maps.googleapis.com');
    expect($html)->toContain('libraries=places');
    expect($html)->toContain(mAaKey());
    expect($html)->not->toContain('<script src="https://maps.googleapis.com');

    $decoded = json_decode((string) preg_replace(
        '/.*<script type="application\/json" id="kbb-addr-cfg">(.*?)<\/script>.*/s',
        '$1',
        $html,
    ), true);

    expect($decoded['url'])->toBe(AddressAutocomplete::scriptUrl(mAaKey()));
    expect($decoded['country'])->toBe('ae');
});

/* ───────────────────────── the seam, which is the only network ──────────── */

it('refuses a key that could break out of the script tag it is written into', function () {
    /*
     * RULE 5, at the one point in this module where a setting becomes part of a
     * URL that becomes an attribute. A key carrying a quote, a space or an
     * angle bracket would close the `src` attribute; it is REFUSED rather than
     * escaped, because a Google API key that needs escaping is not a key.
     *
     * Refusing answers null, which config() turns into "render nothing" — so a
     * malformed key fails closed rather than emitting a broken script tag.
     */
    foreach ([
        '',
        '   ',
        'short',
        'AIza"onerror=alert(1)',
        'AIzaSy has a space in it xxxxxxxxxxxxxxxx',
        'AIza<script>xxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'AIzaSy&callback=evilxxxxxxxxxxxxxxxxxxxx?',
        str_repeat('A', 200),
    ] as $bad) {
        expect(AddressAutocomplete::scriptUrl($bad))->toBeNull("“{$bad}” should not become a script URL");
    }

    $url = AddressAutocomplete::scriptUrl(mAaKey());

    expect($url)->toBeString();
    expect(str_starts_with($url, 'https://maps.googleapis.com/'))->toBeTrue();
    // One origin, written once, and it is https.
    expect(substr_count((string) $url, '://'))->toBe(1);
});

it('falls back to the answer that sends nothing when the stored consent is not one of its own options', function () {
    // A select stores one of its own options or the default — and here the
    // default is the safe one, so a stored typo cannot turn sending on.
    mAaSet(AddressAutocomplete::KEY_CONSENT, 'YES');

    expect(AddressAutocomplete::consent(app(SettingsService::class)))->toBe('unanswered');

    mAaSet(AddressAutocomplete::KEY_CONSENT, 'yes');
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();

    expect(AddressAutocomplete::consent(app(SettingsService::class)))->toBe('yes');
});

/* ─────────────────────── both halves: control and reader ────────────────── */

it('gives the key and the consent question a real control on the checkout tab', function () {
    /*
     * The endpoint, not the source — the difference between "a constant lists
     * this key" and "the owner is shown a box for it", which is exactly what
     * came apart on `reassure_auth_text`.
     */
    $owner = AdminUser::create([
        'name' => 'M Owner',
        'email' => 'm-aa-owner@example.com',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);

    test()->actingAs($owner, 'admin');

    $body = test()->getJson('/admin-api/ecommerce')->assertOk()->json();

    $found = [];

    foreach ($body['tabs'] as $tab) {
        foreach ($tab['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $found[$field['name']] = $field + ['__section' => $section['key']];
            }
        }
    }

    expect($found)->toHaveKey(AddressAutocomplete::KEY_API);
    expect($found)->toHaveKey(AddressAutocomplete::KEY_CONSENT);

    // The question is a select over exactly the three values the code compares
    // against, so the screen cannot offer an answer nothing implements.
    expect($found[AddressAutocomplete::KEY_CONSENT]['type'])->toBe('select');
    expect(array_keys($found[AddressAutocomplete::KEY_CONSENT]['options']))
        ->toBe(array_keys(AddressAutocomplete::CONSENT));

    // It ships unanswered, so applying the package agrees to nothing.
    expect($found[AddressAutocomplete::KEY_CONSENT]['value'])->toBe('unanswered');
    expect($found[AddressAutocomplete::KEY_API]['value'])->toBe('');

    // Both in a named section rather than an "other" bucket.
    expect($found[AddressAutocomplete::KEY_API]['__section'])->toBe('address');
});

it('refuses a consent answer the screen does not offer', function () {
    $owner = AdminUser::create([
        'name' => 'M Owner 2',
        'email' => 'm-aa-owner2@example.com',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);

    test()->actingAs($owner, 'admin');

    test()->postJson('/admin-api/ecommerce', [
        'settings' => [AddressAutocomplete::KEY_CONSENT => 'maybe'],
    ])->assertStatus(422);

    test()->postJson('/admin-api/ecommerce', [
        'settings' => [AddressAutocomplete::KEY_CONSENT => 'yes'],
    ])->assertOk();
});

/* ─────────────────────────── the alignment migration ────────────────────── */

it('turns off a stored row the seeder wrote true while nothing read the key', function () {
    /*
     * THE LANDMINE, fired deliberately. moduleEnabled() returns the STORED row
     * whenever one exists and only falls back to the registry default when it
     * does not — so on every install ModuleSeeder has ever run against, this key
     * is `true`. Adding the reader without the migration would switch address
     * autocomplete on, on the form the shop is paid through, the moment the
     * package applied.
     */
    DB::table('module_toggles')->updateOrInsert(
        ['module' => 'address_autocomplete'],
        ['enabled' => true, 'updated_at' => now(), 'created_at' => now()],
    );

    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();

    expect(app(SettingsService::class)->moduleEnabled('address_autocomplete', false))->toBeTrue();

    require_once base_path('database/migrations/2027_01_05_000001_align_address_autocomplete_module_toggle.php');
    (require base_path('database/migrations/2027_01_05_000001_align_address_autocomplete_module_toggle.php'))->up();

    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();

    expect(app(SettingsService::class)->moduleEnabled('address_autocomplete', false))->toBeFalse();
});

/* ───────────────────────────── performance, retired ─────────────────────── */

it('does not promise unbuilt work for a module that will never be built', function () {
    /*
     * `performance` read `todo` — which Store → Modules draws as "Not ported
     * yet", a promise — and named 'Its own screen' with no console route, which
     * draws as "screen not built yet". Both told the owner something false
     * about a module that cannot exist here: the plugin's version throttles the
     * WordPress heartbeat and dequeues WordPress asset bloat, and this app is
     * not WordPress.
     *
     * `inherent` is the status that says the true thing, and the screen draws
     * it as "Already applied to every page — nothing to switch" with no switch
     * at all, the way `screen` rows are drawn.
     */
    $row = ModuleRegistry::REGISTRY['performance'];

    expect($row[9])->toBe('inherent');
    // No screen claimed, because there is none and "Its own screen" drew as
    // "screen not built yet" — a second false promise on the same row.
    expect(trim((string) $row[4]))->toBe('');
    expect(trim((string) $row[5]))->toBe('');
});

it('has already done, unconditionally, what the performance row describes', function () {
    /*
     * VERIFIED BY LOOKING AT WHAT SHIPS, not by trusting the row's own new
     * wording — the whole point of retiring it is the claim that the work is
     * already done, so the claim gets checked.
     *
     * If either of these ever goes to zero, the row is lying again and should
     * go back to being a real module rather than a retired one.
     */
    $lazy = 0;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('resources/views'))) as $file) {
        if ($file->isFile() && str_ends_with($file->getPathname(), '.blade.php')
            && str_contains((string) file_get_contents($file->getPathname()), 'loading="lazy"')) {
            $lazy++;
        }
    }

    expect($lazy)->toBeGreaterThanOrEqual(7, 'lazy loading is no longer applied unconditionally');

    $layout = (string) file_get_contents(base_path('resources/views/layouts/store.blade.php'));
    $hints = substr_count($layout, 'preconnect') + substr_count($layout, 'dns-prefetch');

    expect($hints)->toBeGreaterThanOrEqual(3, 'the connection hints are no longer applied unconditionally');
});

it('draws no switch for a module with nothing to switch', function () {
    // The console half. A `todo` row draws an INERT switch sitting in the grey
    // off position, which for a module whose work is already done would tell
    // the owner the shop is not doing something it does on every page.
    $src = (string) file_get_contents(base_path('resources/views/admin/app.blade.php'));

    expect($src)->toContain("m.status === 'inherent'");
    expect($src)->toContain("(m.status === 'screen' || m.status === 'inherent')");
});
