<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\BrandsApiController;
use App\Http\Controllers\Store\BrandController;
use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Route;
use Tests\Support\BrandAdminRoutes;
use Tests\Support\Phase9Routes;

/**
 * Brand logos: the directory's three display modes, the count-derived grid,
 * and the admin CRUD behind them.
 *
 * Fixtures use "t-" slugs for the same reason BrandUrlTest does — the demo
 * catalogue a migration seeds is already in the table, and the first test in a
 * process is not isolated (see tests/Pest.php).
 */
beforeEach(function () {
    Phase9Routes::wire($this->app);

    // brands_display is read through SettingsService, which caches. Each test
    // sets its own value, so the cache has to start clean or test two reads
    // test one's mode.
    app(SettingsService::class)->flush();

    /*
     * The directory renders every brand in the table, and the migration set
     * seeds a demo catalogue, so "how many brands are there" is not zero to
     * begin with. The display-mode and grid tests are about what the page does
     * with a known set, so they start from an empty table; RefreshDatabase
     * rolls the delete back with everything else.
     */
    Brand::query()->delete();
});

/** Directory with one brand that has a logo and one that does not. */
function seedBrandPair(): array
{
    return [
        Brand::create([
            'slug' => 't-with-logo', 'name' => 'T Hasalogo',
            'logo' => 'https://cdn.example.test/t-hasalogo.png',
        ]),
        Brand::create([
            'slug' => 't-no-logo', 'name' => 'T Nologo',
        ]),
    ];
}

function setBrandsDisplay(string $mode): void
{
    app(SettingsService::class)->set('brands_display', $mode);
}

/* ------------------------------------------------------------------ modes */

it('defaults to auto, drawing the logo when there is one and the initial when there is not', function () {
    seedBrandPair();

    // Nothing written: an existing site has no brands_display row at all and
    // must look exactly as it did before this shipped.
    expect(Setting::query()->where('key', 'brands_display')->exists())->toBeFalse();

    $this->get('/korean-skincare-brands/')
        ->assertOk()
        ->assertSee('https://cdn.example.test/t-hasalogo.png', escape: false)
        ->assertSee('class="brw-name"', escape: false)
        ->assertSee('T Hasalogo')
        ->assertSee('T Nologo')
        // The brand with no logo keeps the initial-letter circle.
        ->assertSee('class="brw-initial"', escape: false);
});

it('shows the logo alone in logos mode', function () {
    Brand::create([
        'slug' => 't-with-logo', 'name' => 'T Hasalogo',
        'logo' => 'https://cdn.example.test/t-hasalogo.png',
    ]);

    setBrandsDisplay('logos');

    $html = $this->get('/korean-skincare-brands/')->assertOk()->getContent();

    expect($html)->toContain('https://cdn.example.test/t-hasalogo.png')
        // The name is still the img's alt text — dropping that would make the
        // tile unreadable to a screen reader — but it is not drawn as a label.
        ->toContain('alt="T Hasalogo"')
        ->not->toContain('class="brw-name"')
        ->not->toContain('class="brw-initial"');
});

it('falls back to the name for a brand with no logo in logos mode', function () {
    // The most likely real-world case: the mode is switched on before any
    // logo has been uploaded. An empty tile is not acceptable.
    Brand::create(['slug' => 't-no-logo', 'name' => 'T Nologo']);

    setBrandsDisplay('logos');

    $html = $this->get('/korean-skincare-brands/')->assertOk()->getContent();

    expect($html)->toContain('T Nologo')
        ->toContain('class="brw-name"')
        // No logo, and no initial circle standing in for one either: the
        // fallback is the name, not a different placeholder.
        ->not->toContain('class="brw-initial"')
        ->not->toContain('class="brw-logo"');
});

it('shows the name alone in names mode, with no logo and no initial circle', function () {
    seedBrandPair();

    setBrandsDisplay('names');

    $html = $this->get('/korean-skincare-brands/')->assertOk()->getContent();

    expect($html)->toContain('T Hasalogo')
        ->toContain('T Nologo')
        ->toContain('class="brw-name"')
        ->not->toContain('https://cdn.example.test/t-hasalogo.png')
        ->not->toContain('class="brw-initial"')
        ->not->toContain('class="brw-logo"');
});

it('ignores an unrecognised mode rather than rendering an empty directory', function () {
    seedBrandPair();

    setBrandsDisplay('sideways');

    $this->get('/korean-skincare-brands/')
        ->assertOk()
        ->assertSee('T Hasalogo')
        ->assertSee('class="brw-initial"', escape: false);
});

it('escapes an operator-supplied brand name wherever it lands', function () {
    Brand::create([
        'slug' => 't-xss', 'name' => 'T <script>alert(1)</script>',
        'logo' => 'https://cdn.example.test/"onerror="alert(1)',
    ]);

    $html = $this->get('/korean-skincare-brands/')->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->not->toContain('onerror="alert(1)')
        ->toContain('&lt;script&gt;');
});

/* ------------------------------------------------------------------- grid */

it('widens the grid floor for a handful of brands and narrows it for many', function () {
    // Same CSS rule either way — repeat(auto-fill, minmax(var(--brw-min),1fr))
    // — so the floor is the only thing deciding the column count, and it is
    // derived from the count rather than fixed at 158px.
    foreach (range(1, 3) as $i) {
        Brand::create(['slug' => 't-small-' . $i, 'name' => 'T Small ' . $i]);
    }

    $this->get('/korean-skincare-brands/')
        ->assertOk()
        ->assertSee('--brw-count-min:240px', escape: false);

    foreach (range(1, 40) as $i) {
        Brand::create(['slug' => 't-many-' . $i, 'name' => 'T Many ' . $i]);
    }

    $this->get('/korean-skincare-brands/')
        ->assertOk()
        ->assertSee('--brw-count-min:148px', escape: false)
        ->assertDontSee('--brw-count-min:240px', escape: false);
});

it('derives a monotonic floor that never leaves a tiny tile or a single column', function () {
    $mins = collect([1, 3, 4, 6, 7, 12, 13, 30, 31, 93])
        ->map(fn (int $n) => BrandController::gridMinimum($n));

    // Never grows as the catalogue grows, and stays inside a range that is one
    // column on a phone and several on a desktop row.
    expect($mins->all())->toBe($mins->sortDesc()->values()->all())
        ->and($mins->min())->toBeGreaterThanOrEqual(140)
        ->and($mins->max())->toBeLessThanOrEqual(260);
});

it('caps the grid width so three brands are not stretched across the container', function () {
    foreach (range(1, 3) as $i) {
        Brand::create(['slug' => 't-small-' . $i, 'name' => 'T Small ' . $i]);
    }

    // 3 × (240 + 12px gap). max-width is min(100%, cap), so it only ever
    // narrows the grid — the layout still reflows on a narrow screen.
    $this->get('/korean-skincare-brands/')
        ->assertOk()
        ->assertSee('--brw-count-cap:756px', escape: false);
});

it('keeps the grid rule CSS-driven, with no script deciding the column count', function () {
    seedBrandPair();

    $html = $this->get('/korean-skincare-brands/')->assertOk()->getContent();

    expect($html)->toContain('repeat(auto-fill,minmax(min(var(--brw-min),100%),1fr))')
        // Phone width overrides the floor rather than the whole rule.
        ->toContain('@media (max-width:520px)')
        ->toContain('--brw-min:132px');
});

/* -------------------------------------------------------------- admin api */

it('rejects an unauthenticated caller on every brand admin route', function () {
    BrandAdminRoutes::wire($this->app);

    $brand = Brand::create(['slug' => 't-guarded', 'name' => 'T Guarded']);

    $this->getJson('/admin-api/brands')->assertStatus(401);
    $this->postJson('/admin-api/brands', ['name' => 'T Sneaky'])->assertStatus(401);
    $this->putJson('/admin-api/brands/' . $brand->id, ['name' => 'T Sneaky'])->assertStatus(401);
    $this->deleteJson('/admin-api/brands/' . $brand->id)->assertStatus(401);

    // Not merely rejected — nothing was written either.
    expect(Brand::query()->where('slug', 't-guarded')->exists())->toBeTrue()
        ->and(Brand::query()->where('name', 'T Sneaky')->exists())->toBeFalse();
});

it('mounts the brand routes into a group that already carries the admin guard', function () {
    // The header of routes/brands-admin.php tells the integrator to require it
    // inside web.php's admin-api group. If that group ever stopped being
    // guarded, mounting there would publish a catalogue editor, so it is
    // asserted rather than assumed.
    $existing = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === 'admin-api/pay-ship-rules' && $r->methods()[0] === 'GET');

    expect($existing)->not->toBeNull()
        ->and($existing->middleware())->toContain('auth:admin');
});

it('defines list, create, update and delete against the brands controller', function () {
    $before = Route::getRoutes()->getRoutes();

    Route::prefix('admin-api')->group(base_path('routes/brands-admin.php'));

    $added = collect(Route::getRoutes()->getRoutes())
        ->reject(fn ($r) => in_array($r, $before, true))
        ->values();

    expect($added)->toHaveCount(4);

    $byMethod = $added->keyBy(fn ($r) => $r->methods()[0]);

    expect($byMethod->keys()->all())->toContain('GET', 'POST', 'PUT', 'DELETE')
        ->and($byMethod['GET']->uri())->toBe('admin-api/brands')
        ->and($byMethod['GET']->getAction('controller'))->toBe(BrandsApiController::class . '@index')
        ->and($byMethod['POST']->getAction('controller'))->toBe(BrandsApiController::class . '@store')
        ->and($byMethod['PUT']->uri())->toBe('admin-api/brands/{brand}')
        ->and($byMethod['PUT']->getAction('controller'))->toBe(BrandsApiController::class . '@update')
        ->and($byMethod['DELETE']->getAction('controller'))->toBe(BrandsApiController::class . '@destroy');
});

describe('signed in as an admin', function () {
    beforeEach(function () {
        BrandAdminRoutes::wire($this->app);

        $this->admin = AdminUser::create([
            'name' => 'T Admin', 'email' => 't-admin@example.test',
            'password' => 'password-long-enough', 'role' => 'owner',
        ]);

        $this->actingAs($this->admin, 'admin');
    });

    it('creates a brand with a logo, deriving the slug from the name', function () {
        $this->postJson('/admin-api/brands', [
            'name' => 'T Beauty of Joseon',
            'logo' => 'https://cdn.example.test/boj.png',
            'description' => 'Hanbang formulas.',
        ])->assertStatus(201)->assertJsonPath('brand.slug', 't-beauty-of-joseon');

        expect(Brand::query()->where('slug', 't-beauty-of-joseon')->value('logo'))
            ->toBe('https://cdn.example.test/boj.png');
    });

    it('answers a duplicate slug with a 422, not a 500', function () {
        Brand::create(['slug' => 't-cosrx', 'name' => 'T COSRX']);

        $this->postJson('/admin-api/brands', ['name' => 'T COSRX', 'slug' => 't-cosrx'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        // Same again through the derived slug — a duplicate NAME must not slip
        // past the uniqueness rule and land as a QueryException.
        $this->postJson('/admin-api/brands', ['name' => 'T COSRX'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        expect(Brand::query()->where('slug', 't-cosrx')->count())->toBe(1);
    });

    it('lets a brand keep its own slug when it is edited', function () {
        $brand = Brand::create(['slug' => 't-anua', 'name' => 'T Anua']);

        $this->putJson('/admin-api/brands/' . $brand->id, [
            'name' => 'T Anua Beauty', 'slug' => 't-anua',
        ])->assertOk();

        expect($brand->fresh()->name)->toBe('T Anua Beauty');
    });

    it('refuses a logo URL that is not an image the browser will treat as one', function () {
        $this->postJson('/admin-api/brands', [
            'name' => 'T Payload',
            'logo' => 'data:image/svg+xml;base64,PHN2Zz48c2NyaXB0Pg==',
        ])->assertStatus(422)->assertJsonValidationErrors('logo');

        expect(Brand::query()->where('name', 'T Payload')->exists())->toBeFalse();
    });

    it('sanitises a slug that tries to be a path rather than a segment', function () {
        // The slug is a URL segment and a shop filter value. Str::slug reduces
        // it to one before the uniqueness rule ever sees it, so traversal
        // cannot be stored, and the regex rule is what catches anything it
        // leaves behind.
        $this->postJson('/admin-api/brands', ['name' => 'T Bad', 'slug' => '../../etc/passwd'])
            ->assertStatus(201)
            ->assertJsonPath('brand.slug', 'etcpasswd');

        expect(Brand::query()->pluck('slug')->contains(fn (string $s) => str_contains($s, '/')))->toBeFalse();
    });

    it('refuses a slug that reduces to nothing at all', function () {
        $this->postJson('/admin-api/brands', ['name' => 'T Bad', 'slug' => '!!!'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        expect(Brand::query()->where('name', 'T Bad')->exists())->toBeFalse();
    });

    it('lists brands with the number of products behind each', function () {
        $brand = Brand::create(['slug' => 't-listed', 'name' => 'T Listed']);
        Product::create([
            'slug' => 't-listed-serum', 'name' => 'T Listed Serum',
            'brand_id' => $brand->id, 'status' => 'publish', 'is_visible' => true, 'price' => 100,
        ]);

        $row = collect($this->getJson('/admin-api/brands')->assertOk()->json('brands'))
            ->firstWhere('slug', 't-listed');

        expect((int) $row['products_count'])->toBe(1);
    });

    it('refuses to delete a brand that still has products, then obeys force', function () {
        $brand = Brand::create(['slug' => 't-doomed', 'name' => 'T Doomed']);
        $product = Product::create([
            'slug' => 't-doomed-serum', 'name' => 'T Doomed Serum',
            'brand_id' => $brand->id, 'status' => 'publish', 'is_visible' => true, 'price' => 100,
        ]);

        $this->deleteJson('/admin-api/brands/' . $brand->id)
            ->assertStatus(422)
            ->assertJsonPath('error', 'brand_in_use')
            ->assertJsonPath('products_count', 1);

        expect(Brand::query()->whereKey($brand->id)->exists())->toBeTrue();

        $this->deleteJson('/admin-api/brands/' . $brand->id . '?force=1')->assertOk();

        // The brand goes, the product stays — unbranded, not deleted.
        expect(Brand::query()->whereKey($brand->id)->exists())->toBeFalse()
            ->and($product->fresh())->not->toBeNull()
            ->and($product->fresh()->brand_id)->toBeNull();
    });

    it('deletes an unused brand without ceremony', function () {
        $brand = Brand::create(['slug' => 't-unused', 'name' => 'T Unused']);

        $this->deleteJson('/admin-api/brands/' . $brand->id)->assertOk();

        expect(Brand::query()->whereKey($brand->id)->exists())->toBeFalse();
    });

    it('saves brands_display through updateSettings instead of silently rejecting it', function () {
        // Seventeen keys were already found reporting success and writing
        // nothing because they were missing from that allowlist. This is the
        // test that stops this one becoming the eighteenth.
        $this->putJson('/admin-api/settings', ['settings' => ['brands_display' => 'logos']])
            ->assertOk()
            ->assertJsonPath('saved', 1)
            ->assertJsonMissingPath('rejected');

        expect(Setting::query()->where('key', 'brands_display')->value('value'))->toBe('logos');

        // And it reaches the storefront, which is the only reason to save it.
        Brand::create(['slug' => 't-saved', 'name' => 'T Saved']);

        $this->get('/korean-skincare-brands/')
            ->assertOk()
            ->assertDontSee('class="brw-initial"', escape: false);
    });
});
