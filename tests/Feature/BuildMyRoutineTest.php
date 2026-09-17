<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Routine;
use App\Services\BuildMyRoutine;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\RoutineConcerns;
use App\Support\RoutineRoles;
use Illuminate\Support\Str;
use Tests\Support\BuildMyRoutineRoutes;

/**
 * Phase 10 — Build my routine (Lane FM).
 *
 * ── WHAT THESE GUARD, AND WHAT THEY DELIBERATELY DO NOT ─────────────────────
 *
 * Not "the setting round-trips". A routine screen whose only assertion is that
 * a value saved and read back proves nothing whatsoever about the storefront,
 * and eight guards on this project have already been caught asserting exactly
 * that much. Every write below is followed by a FETCH of the shopper's page,
 * and the assertion is about what the shopper got.
 *
 * The rule the whole feature stands on is one sentence, and it is the lesson
 * the previous lane paid for when it deleted seventeen invented products from
 * the skin quiz:
 *
 *     THIS PAGE MAY NAME A PRODUCT THIS SHOP REALLY SELLS, OR IT MAY NAME NO
 *     PRODUCT AT ALL.
 *
 * So the fixtures below deliberately include a draft, an out-of-stock row, a
 * product scheduled for next month and a product tagged for somebody else's
 * concern, and each one is asserted ABSENT from the rendered page. A test that
 * only checked the happy row would pass against an engine that published the
 * entire table.
 */

/* ───────────────────────────── fixtures ──────────────────────────────────── */

beforeEach(function () {
    BuildMyRoutineRoutes::wire(app());
    app(SettingsService::class)->setModule('build_my_routine', true);

    /*
     * The migration set seeds a demo catalogue — 24 products, none of them
     * tagged. Left in place they are not merely noise: every assertion about a
     * COUNT ("one product is untagged") would be measuring the seeder, and
     * every assertion that a routine cannot be filled would be true for a
     * reason this lane did not arrange. Cleared, so each test states its own
     * catalogue in full and nothing passes by accident.
     */
    Product::query()->forceDelete();
});

/** One product, with everything the storefront needs and nothing it does not. */
function fmProduct(string $name, ?string $role, array $concerns = [], array $extra = []): Product
{
    return Product::create(array_merge([
        'slug' => Str::slug($name).'-'.Str::random(6),
        'name' => $name,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
        'routine_role' => $role,
        'routine_concerns' => $concerns === [] ? null : json_encode($concerns),
    ], $extra));
}

function fmAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'FM Owner',
        'email' => 'fm-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** The full five-step catalogue an "everything is tagged" routine needs. */
function fmFullCatalogue(): array
{
    return [
        'cleanse' => fmProduct('FM Gentle Cleanser', 'cleanse'),
        'tone' => fmProduct('FM Balancing Toner', 'tone'),
        'treat' => fmProduct('FM Acne Ampoule', 'treat', ['acne']),
        'moisturise' => fmProduct('FM Barrier Cream', 'moisturise'),
        'protect' => fmProduct('FM Daily Sunscreen', 'protect'),
    ];
}

/* ─────────────────────── off means off, measured ─────────────────────────── */

it('serves both routine pages only while the module is on, and changes no other page either way', function () {
    fmFullCatalogue();

    app(SettingsService::class)->setModule('build_my_routine', false);

    $this->get('/routines')->assertNotFound();
    $this->get('/routines/acne')->assertNotFound();

    /*
     * The other half of "off means off", and the half a 404 assertion does not
     * cover: the shop itself must not differ by a byte. Fetched rather than
     * reasoned about — the module is read in exactly one place, but "I only
     * added one gate" is what every lane says before the nav entry it forgot
     * about turns up in a screenshot.
     */
    $off = $this->get('/shop')->assertOk()->getContent();

    app(SettingsService::class)->setModule('build_my_routine', true);

    $on = $this->get('/shop')->assertOk()->getContent();

    $strip = static fn (string $html) => preg_replace('/[A-Za-z0-9]{40}/', 'TOKEN', $html);

    expect($strip($on))->toBe($strip($off), 'The shop page differs with the module on.');
    expect($on)->not->toContain('/routines');

    $this->get('/routines')->assertOk();
    $this->get('/routines/acne')->assertOk();
});

/* ──────────────────── only real, sellable rows may appear ─────────────────── */

it('fills a step only from a published, visible, in-stock row', function () {
    // The one that may appear.
    fmProduct('FM Visible Cleanser', 'cleanse');

    // Three that may not, one per reason a product is not on the storefront.
    fmProduct('FM Draft Cleanser', 'cleanse', [], ['status' => 'draft']);
    fmProduct('FM Sold Out Cleanser', 'cleanse', [], ['stock_status' => 'outofstock']);
    fmProduct('FM Future Cleanser', 'cleanse', [], ['published_at' => now()->addMonth()]);

    $html = $this->get('/routines/acne')->assertOk()->getContent();

    expect($html)->toContain('FM Visible Cleanser');

    foreach (['FM Draft Cleanser', 'FM Sold Out Cleanser', 'FM Future Cleanser'] as $hidden) {
        expect($html)->not->toContain($hidden);
    }
});

it('never offers a product in a routine it was not tagged for', function () {
    fmProduct('FM Acne Only Serum', 'treat', ['acne']);
    fmProduct('FM Untargeted Cleanser', 'cleanse');

    $acne = $this->get('/routines/acne')->assertOk()->getContent();
    $hydration = $this->get('/routines/hydration')->assertOk()->getContent();

    expect($acne)->toContain('FM Acne Only Serum');
    expect($hydration)->not->toContain('FM Acne Only Serum');

    // The other half of the rule: a product that names NO concern suits every
    // routine, or the owner would have to tag one cleanser eight times.
    expect($acne)->toContain('FM Untargeted Cleanser');
    expect($hydration)->toContain('FM Untargeted Cleanser');
});

it('shows an empty step as empty rather than dropping it', function () {
    fmProduct('FM Only Cleanser', 'cleanse');

    $html = $this->get('/routines/acne')->assertOk()->getContent();

    // Five steps drawn, not one. A routine silently shortened to the steps it
    // can fill is indistinguishable from a one-step routine.
    expect(substr_count($html, 'class="rtn-step"'))->toBe(count(RoutineRoles::ORDER));
    expect($html)->toContain(__('store.routines.gap_heading'));
    expect($html)->toContain(__('store.routines.browse_shop'));
});

it('lists no routine it cannot fill a single step of', function () {
    // Nothing tagged at all.
    fmProduct('FM Untagged Thing', null);

    $html = $this->get('/routines')->assertOk()->getContent();

    expect($html)->toContain(__('store.routines.empty_heading'));

    foreach (RoutineConcerns::slugs() as $slug) {
        expect($html)->not->toContain('/routines/'.$slug.'/');
    }
});

/* ───────────────────────── manual selection ──────────────────────────────── */

it('swaps the step a shopper asked to swap, and ignores a slug that is not an option', function () {
    $first = fmProduct('FM First Toner', 'tone', [], ['position' => 1]);
    $second = fmProduct('FM Second Toner', 'tone', [], ['position' => 2]);

    $default = $this->get('/routines/acne')->assertOk()->getContent();
    expect($default)->toContain('FM First Toner');

    $swapped = $this->get('/routines/acne?tone='.$second->slug)->assertOk()->getContent();

    // The chosen one is in the card; the other is now the alternative offered.
    expect($swapped)->toContain('FM Second Toner');
    expect(strpos($swapped, 'FM Second Toner'))->toBeLessThan((int) strpos($swapped, 'FM First Toner'));

    /*
     * A slug that is not one of this step's options is not an error. It arrives
     * from a shared link or a bookmark, and the product behind it can sell out
     * between the link being sent and being opened; a routine that 404s because
     * one of five steps went out of stock is a broken page.
     */
    $bogus = $this->get('/routines/acne?tone=no-such-product')->assertOk()->getContent();
    expect($bogus)->toContain('FM First Toner');

    // And a slug belonging to a DIFFERENT step cannot be smuggled into this one.
    $crossed = $this->get('/routines/acne?tone='.$first->slug.'&cleanse='.$second->slug)->assertOk()->getContent();
    expect(substr_count($crossed, 'FM Second Toner'))->toBeGreaterThan(0);
    expect($crossed)->toContain('FM First Toner');
});

/* ─────────────────────────── the offer strip ─────────────────────────────── */

it('prints the real coupon and nothing else, and prints nothing at all when there is none', function () {
    fmFullCatalogue();

    $routines = app(BuildMyRoutine::class);
    $routines->save(['offer_scope' => 'site', 'offer_coupon' => 'FMSAVE']);

    // No such code yet: silence, not an error and not a placeholder.
    expect($this->get('/routines/acne')->getContent())->not->toContain('FMSAVE');

    Coupon::create([
        'code' => 'FMSAVE',
        'type' => 'percent',
        'amount' => 1500,          // percent ×100 — 15%, off the row
        'minimum_amount' => 20000, // fils
    ]);

    $html = $this->get('/routines/acne')->assertOk()->getContent();

    expect($html)->toContain('FMSAVE')
        ->toContain('15%')
        // The minimum is money and obeys the shop's whole-dirham policy.
        ->toContain('200');

    // Expired: the strip goes, rather than advertising a dead promotion.
    Coupon::query()->update(['expires_at' => now()->subDay()]);
    expect($this->get('/routines/acne')->getContent())->not->toContain('FMSAVE');

    // Fully redeemed: same answer.
    Coupon::query()->update(['expires_at' => null, 'usage_limit' => 3, 'usage_count' => 3]);
    expect($this->get('/routines/acne')->getContent())->not->toContain('FMSAVE');
});

it('puts the strip where the scope setting says, which is the plan’s second open question', function () {
    fmFullCatalogue();

    Coupon::create(['code' => 'FMONE', 'type' => 'percent', 'amount' => 1000]);

    $routines = app(BuildMyRoutine::class);

    // Site-wide: one strip, above the list, the same on every routine.
    $routines->save(['offer_scope' => 'site', 'offer_coupon' => 'FMONE']);

    expect($this->get('/routines')->getContent())->toContain('FMONE');
    expect($this->get('/routines/acne')->getContent())->toContain('FMONE');
    expect($this->get('/routines/hydration')->getContent())->toContain('FMONE');

    // Per routine: only the routine that names the code carries it.
    $routines->save(['offer_scope' => 'routine']);
    Routine::create(['concern' => 'acne', 'coupon_code' => 'FMONE']);

    expect($this->get('/routines/acne')->getContent())->toContain('FMONE');
    expect($this->get('/routines/hydration')->getContent())->not->toContain('FMONE');

    // Off: neither.
    $routines->save(['offer_scope' => 'off']);

    expect($this->get('/routines')->getContent())->not->toContain('FMONE');
    expect($this->get('/routines/acne')->getContent())->not->toContain('FMONE');
});

/* ───────────────────── fixed steps or a free list ────────────────────────── */

it('honours a routine’s own step list only in custom mode, which is the plan’s first open question', function () {
    fmFullCatalogue();

    Routine::create(['concern' => 'acne', 'steps' => ['cleanse', 'treat']]);

    $routines = app(BuildMyRoutine::class);

    $routines->save(['steps_mode' => 'fixed']);
    expect(substr_count($this->get('/routines/acne')->getContent(), 'class="rtn-step"'))
        ->toBe(count(RoutineRoles::ORDER));

    $routines->save(['steps_mode' => 'custom']);
    expect(substr_count($this->get('/routines/acne')->getContent(), 'class="rtn-step"'))->toBe(2);

    // A routine with no list of its own keeps the five even in custom mode.
    expect(substr_count($this->get('/routines/hydration')->getContent(), 'class="rtn-step"'))
        ->toBe(count(RoutineRoles::ORDER));
});

/* ───────────────────────────── the money ─────────────────────────────────── */

it('totals the real prices of the chosen products and prints whole dirhams', function () {
    fmProduct('FM Cheap Cleanser', 'cleanse', [], ['price' => 5000]);
    fmProduct('FM Pricey Serum', 'treat', [], ['price' => 12300, 'sale_price' => 9900]);

    $html = $this->get('/routines/acne')->assertOk()->getContent();

    // 50 + 99 = 149. The sale price is the advertised one, so the total follows
    // the markdown rather than the list price.
    expect($html)->toContain('149');
    // And it says what it covers: two steps, not five.
    expect($html)->toContain(trans_choice('store.routines.total_label', 2, ['count' => 2]));
    // No fils anywhere in the figure — App\Support\WholeDirhams is the policy.
    expect($html)->not->toContain('149.00');
});

/* ─────────────────── the admin write reaches the shopper ─────────────────── */

it('puts a product into a routine when the owner tags it, and takes it out again', function () {
    $product = fmProduct('FM Newly Tagged Toner', null);

    expect($this->get('/routines/acne')->getContent())->not->toContain('FM Newly Tagged Toner');

    $this->actingAs(fmAdmin(), 'admin')
        ->postJson('/admin-api/routine-products/'.$product->id, ['role' => 'tone', 'concerns' => ['acne']])
        ->assertOk()
        ->assertJsonPath('product.role', 'tone')
        ->assertJsonPath('product.concerns', ['acne']);

    expect($this->get('/routines/acne')->getContent())->toContain('FM Newly Tagged Toner');
    expect($this->get('/routines/hydration')->getContent())->not->toContain('FM Newly Tagged Toner');

    // Clearing the concerns widens it to every routine; clearing the role takes
    // it off every routine. Both are what the screen's controls post.
    $this->actingAs(fmAdmin(), 'admin')
        ->postJson('/admin-api/routine-products/'.$product->id, ['concerns' => []])
        ->assertOk();

    expect($this->get('/routines/hydration')->getContent())->toContain('FM Newly Tagged Toner');

    $this->actingAs(fmAdmin(), 'admin')
        ->postJson('/admin-api/routine-products/'.$product->id, ['role' => null])
        ->assertOk();

    expect($this->get('/routines/acne')->getContent())->not->toContain('FM Newly Tagged Toner');
});

it('counts what is still untagged over products a shopper could actually be shown', function () {
    fmProduct('FM Tagged', 'cleanse');
    fmProduct('FM Untagged', null);
    // A draft with no role is not a gap the owner has to close.
    fmProduct('FM Draft Untagged', null, [], ['status' => 'draft']);

    $body = $this->actingAs(fmAdmin(), 'admin')
        ->getJson('/admin-api/routines')
        ->assertOk()
        ->json();

    expect($body['coverage']['total'])->toBe(2)
        ->and($body['coverage']['tagged'])->toBe(1)
        ->and($body['coverage']['untagged'])->toBe(1)
        ->and($body['coverage']['by_role']['cleanse'])->toBe(1)
        ->and($body['coverage']['by_role']['treat'])->toBe(0);

    // And the owner is told which step of which routine has nothing behind it.
    expect($body['coverage']['routines']['acne']['empty'])->toContain('treat');
});

it('lets the owner find exactly the untagged rows, which is the question the screen exists for', function () {
    fmProduct('FM Has A Step', 'cleanse');
    fmProduct('FM Has No Step', null);

    $all = $this->actingAs(fmAdmin(), 'admin')->getJson('/admin-api/routine-products')->assertOk()->json();
    expect($all['total'])->toBe(2);

    $none = $this->actingAs(fmAdmin(), 'admin')
        ->getJson('/admin-api/routine-products?role=none')->assertOk()->json();

    expect($none['total'])->toBe(1)
        ->and($none['products'][0]['name'])->toBe('FM Has No Step');

    $cleansers = $this->actingAs(fmAdmin(), 'admin')
        ->getJson('/admin-api/routine-products?role=cleanse')->assertOk()->json();

    expect($cleansers['total'])->toBe(1)
        ->and($cleansers['products'][0]['name'])->toBe('FM Has A Step');
});

it('renames a routine, hides one, and reorders the list — all visible to the shopper', function () {
    fmFullCatalogue();
    fmProduct('FM Hydration Serum', 'treat', ['hydration']);

    $this->actingAs(fmAdmin(), 'admin')
        ->postJson('/admin-api/routines/hydration', [
            'title' => 'The Big Drink',
            'blurb' => 'Written by the owner.',
            'position' => -5,
        ])->assertOk();

    $list = $this->get('/routines')->assertOk()->getContent();

    expect($list)->toContain('The Big Drink')->toContain('Written by the owner.');
    // position -5 sorts it above everything left at 0.
    expect(strpos($list, 'The Big Drink'))->toBeLessThan((int) strpos($list, 'Acne &amp; blemishes routine'));

    $this->actingAs(fmAdmin(), 'admin')
        ->postJson('/admin-api/routines/acne', ['is_enabled' => false])
        ->assertOk();

    expect($this->get('/routines')->getContent())->not->toContain('/routines/acne/');
    $this->get('/routines/acne')->assertNotFound();

    $this->actingAs(fmAdmin(), 'admin')
        ->postJson('/admin-api/routines/not-a-concern', ['title' => 'x'])
        ->assertNotFound();
});

it('accepts an emptied text box, because clearing the coupon is how the strip comes down', function () {
    /*
     * A lane found that Store → Ecommerce refuses to save ANY empty text box:
     * ConvertEmptyStringsToNull turns it into null, ModuleSchema::cast() answers
     * `is_scalar(null) ? … : null` for a text field, and the writer reads that
     * as "rejected" — a 422 that says the value is invalid.
     *
     * Emptying `offer_coupon` is the ONLY way to take the offer strip down, so a
     * schema that depended on that bug being fixed would ship a strip the owner
     * could not remove. RoutinesApiController coerces it in this lane's own
     * endpoint, which is correct whichever way that fix lands.
     */
    Coupon::create(['code' => 'FMGONE', 'type' => 'percent', 'amount' => 500]);
    fmFullCatalogue();

    $this->actingAs(fmAdmin(), 'admin')
        ->postJson('/admin-api/routines-settings', ['settings' => ['offer_scope' => 'site', 'offer_coupon' => 'FMGONE']])
        ->assertOk();

    expect($this->get('/routines/acne')->getContent())->toContain('FMGONE');

    $this->actingAs(fmAdmin(), 'admin')
        ->postJson('/admin-api/routines-settings', ['settings' => ['offer_coupon' => null]])
        ->assertOk()
        ->assertJsonPath('settings.offer_coupon', '');

    expect($this->get('/routines/acne')->getContent())->not->toContain('FMGONE');
});

it('refuses a setting key it does not know and a role it does not know', function () {
    $product = fmProduct('FM Rule Check', null);

    $this->actingAs(fmAdmin(), 'admin')
        ->postJson('/admin-api/routines-settings', ['settings' => ['admin_path' => 'hijacked']])
        ->assertStatus(422);

    $this->actingAs(fmAdmin(), 'admin')
        ->postJson('/admin-api/routine-products/'.$product->id, ['role' => 'exfoliate'])
        ->assertStatus(422);

    $this->actingAs(fmAdmin(), 'admin')
        ->postJson('/admin-api/routine-products/'.$product->id, ['concerns' => ['not-a-concern']])
        ->assertStatus(422);

    /*
     * And a step list naming a role this build does not have. Refused at the
     * rule rather than quietly dropped, which is the difference between the
     * owner being told and the owner saving a four-step routine that renders as
     * three. The loop in saveRoutine() filters the list as well — belt and
     * braces on a column the storefront iterates — but the 422 is the half that
     * says something, so it is the half that is pinned.
     */
    $this->actingAs(fmAdmin(), 'admin')
        ->postJson('/admin-api/routines/acne', ['steps' => ['cleanse', 'exfoliate']])
        ->assertStatus(422);

    expect(Routine::query()->where('concern', 'acne')->exists())->toBeFalse();

    expect($product->fresh()->routine_role)->toBeNull();
});

/* ───────────────────── the guards around the endpoints ───────────────────── */

it('registers every endpoint behind the admin guard, and neither storefront page behind it', function () {
    $admin = [];
    $store = [];

    foreach (BuildMyRoutineRoutes::registered() as $route) {
        if (str_contains((string) $route->getAction('controller'), 'RoutinesApiController')) {
            $admin[] = $route;
        } else {
            $store[] = $route;
        }
    }

    expect($admin)->toHaveCount(5);
    expect($store)->toHaveCount(2);

    foreach ($admin as $route) {
        expect($route->gatherMiddleware())
            ->toContain('auth:admin')
            ->toContain(\App\Http\Middleware\NoStoreAdminApi::class);
    }

    foreach ($store as $route) {
        expect($route->gatherMiddleware())->not->toContain('auth:admin');
    }
});

it('refuses every admin endpoint to an anonymous caller', function () {
    $product = fmProduct('FM Guard Check', null);

    $this->getJson('/admin-api/routines')->assertStatus(401);
    $this->getJson('/admin-api/routine-products')->assertStatus(401);
    $this->postJson('/admin-api/routines-settings', ['settings' => []])->assertStatus(401);
    $this->postJson('/admin-api/routines/acne', [])->assertStatus(401);
    $this->postJson('/admin-api/routine-products/'.$product->id, ['role' => 'tone'])->assertStatus(401);

    expect($product->fresh()->routine_role)->toBeNull();
});

it('maps every write above its matching read in the capability rules', function () {
    /*
     * WRITES BEFORE READS. `admin-api/routine-products` and
     * `admin-api/routine-products/{id}` differ by one segment, and
     * AdminCapabilities matches FIRST RULE WINS — so a read rule written first
     * would be reached for the POST and an `editor` holding only catalog.view
     * could retag the whole catalogue.
     */
    $for = static function (string $method, string $uri) {
        foreach (AdminCapabilities::RULES as [$ruleMethod, $pattern, $capability]) {
            if ($ruleMethod !== '*' && $ruleMethod !== $method) {
                continue;
            }

            $regex = '#^'.str_replace(['\*\*', '\*'], ['.+', '[^/]+'], preg_quote($pattern, '#')).'$#';

            if (preg_match($regex, $uri) === 1) {
                return $capability;
            }
        }

        return null;
    };

    expect($for('POST', 'admin-api/routine-products/{id}'))->toBe('catalog.manage');
    expect($for('POST', 'admin-api/routines/{concern}'))->toBe('catalog.manage');
    expect($for('POST', 'admin-api/routines-settings'))->toBe('catalog.manage');
    expect($for('GET', 'admin-api/routine-products'))->toBe('catalog.view');
    expect($for('GET', 'admin-api/routines'))->toBe('catalog.view');
});

/* ───────────────── the new columns stay off the public API ───────────────── */

it('publishes neither new column on the unauthenticated product API', function () {
    $product = fmProduct('FM Public Check', 'treat', ['acne']);

    // Product::toApi() is an allowlist, and CLAUDE.md records three separate
    // occasions on which a column added later leaked through /api/*. A column
    // added by THIS lane is no different.
    expect(array_keys($product->toApi()))
        ->not->toContain('routine_role')
        ->not->toContain('routine_concerns');

    $body = $this->getJson('/api/products')->assertOk()->getContent();

    expect($body)->not->toContain('routine_role')->not->toContain('routine_concerns');
});

/* ────────────────────────── the module registry ──────────────────────────── */

it('is registered off by default, and something on the storefront really reads the switch', function () {
    expect(\App\Services\ModuleRegistry::REGISTRY)->toHaveKey('build_my_routine');

    [$group, $name, $desc, $default, $screen, $route, , , , $status] =
        \App\Services\ModuleRegistry::REGISTRY['build_my_routine'];

    expect($default)->toBeFalse('the module must ship off');
    expect($status)->toBe('live');
    expect($route)->toBe('routines');

    // A registry row can say `live` and be read by nothing — the defect
    // ModuleFrameworkGuardTest exists for. This is the same claim, checked from
    // the other end: with no toggle row at all, the pages 404.
    \App\Models\ModuleToggle::query()->where('module', 'build_my_routine')->delete();

    // setModule() is what forgets the cached snapshot; deleting the row alone
    // would leave the old answer memoised and this assertion would pass for the
    // wrong reason. Set it false first, then remove the row, then ask.
    app(SettingsService::class)->setModule('build_my_routine', false);
    \App\Models\ModuleToggle::query()->where('module', 'build_my_routine')->delete();
    app(SettingsService::class)->setModule('freeship_bar', true); // forgets the snapshot

    $this->get('/routines')->assertNotFound();
});
