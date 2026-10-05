<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\StorefrontAdminController;
use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\AdminCapabilities;
use App\Support\StorefrontAdminHint;
use Tests\Support\StorefrontAdminRoutes;

/**
 * The storefront admin layer: the thin bar and the quick-edit pencil. (Lane RA)
 *
 * The owner:
 *
 *   "on any category, brand page. i want a pencil icon + edit minimal button,
 *    only administrator for now, later we will create a user-roles module, and
 *    i can select this option to allow for our specific store managers ... but
 *    now for me only. this button will open a beautiful popup having all the
 *    options for this specific page (category or brand) to upload/change
 *    background image, title, description etc etc ... the save button on
 *    poupup will save everything smooth and update the page without being
 *    refreshed and close the popup auto ... must not hang or give any error or
 *    disturb anything else."
 *
 *   "plus i need site thin top bar, will show only for administrators ...
 *    administrator name, along with logout button. but it should not disturb
 *    anything, or not conflict with anything."
 *
 * What these cases pin, and what each defect would have looked like on the
 * shop:
 *
 *   - the hint cookie: set at sign-in for an account that has the tools,
 *     readable by the page's script, expired at sign-out. Without it the bar
 *     never appears; HttpOnly, and the script cannot see it either.
 *   - the context endpoint: 401 to a guest or a forged hint, 403 to a role that
 *     holds neither capability, 200 with an ALLOWLISTED body and no-store to the
 *     owner. A model dump here would put the console's address or a column
 *     like `header_source` in front of whoever asked.
 *   - the save: the pencil's capability, CSRF, the category editor's own
 *     validation, refusal of keys it does not own (a name or slug change moves
 *     URLs), and the header handed back exactly as the page draws it.
 *   - and that a shop page served to the owner is byte for byte the page a
 *     shopper is served: the bar lives in JavaScript, never in the HTML.
 */

/* ---------------------------------------------------------------- helpers */

function raAdmin(string $role = 'owner', string $email = 'ra-owner@example.test'): AdminUser
{
    return AdminUser::create([
        'name' => 'Rafi Owner',
        'email' => $email,
        'password' => 'password-long-enough',
        'role' => $role,
    ]);
}

function raCategory(array $over = []): Category
{
    $c = Category::create(array_merge([
        'slug' => 'ra-sunscreens', 'name' => 'RA Sunscreens', 'parent_id' => null,
        'description' => 'Light Korean sunscreens for everyday wear.',
    ], $over));
    $c->forceFill(['path' => $c->slug, 'depth' => 0])->save();

    return $c->fresh();
}

function raBrand(array $over = []): Brand
{
    return Brand::create(array_merge(['slug' => 'ra-cosrx', 'name' => 'RA Cosrx'], $over));
}

/** The JSON the page would get, for an admin whose session this test holds. */
function raContext(\Tests\TestCase $test, string $path): \Illuminate\Testing\TestResponse
{
    return $test->getJson('/admin-api/storefront/context?path=' . rawurlencode($path));
}

beforeEach(function () {
    StorefrontAdminRoutes::wire($this->app);
});

/* ================================================================ the hint */

describe('the hint cookie', function () {
    it('is set at sign-in for the owner: readable by the page, Lax, on the session path', function () {
        /*
         * The page's script reads document.cookie for `kbb_ah`. HttpOnly, and
         * the owner would sign in and never see the bar.
         *
         * MUTATION, RUN: pass `true` for httpOnly in StorefrontAdminHint::make()
         * and this is red.
         */
        raAdmin();

        $response = $this->post(route('admin.login.post'), [
            'email' => 'ra-owner@example.test', 'password' => 'password-long-enough',
        ]);

        $response->assertRedirect();

        $hint = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === StorefrontAdminHint::COOKIE);

        expect($hint)->not->toBeNull('signing in did not set the storefront hint')
            ->and($hint->isHttpOnly())->toBeFalse()
            ->and(strtolower((string) $hint->getSameSite()))->toBe('lax')
            ->and($hint->getPath())->toBe((string) config('session.path', '/'))
            ->and($hint->getExpiresTime())->toBeGreaterThan(time());
    });

    it('is not set for a role that has neither storefront tool', function () {
        /*
         * A support account would otherwise make every shop page it opens ask
         * a question whose answer is always 403.
         */
        raAdmin('support', 'ra-support@example.test');

        $response = $this->post(route('admin.login.post'), [
            'email' => 'ra-support@example.test', 'password' => 'password-long-enough',
        ]);

        $names = collect($response->headers->getCookies())->map->getName()->all();

        expect($names)->not->toContain(StorefrontAdminHint::COOKIE);
    });

    it('is expired at sign-out', function () {
        /*
         * MUTATION, RUN: drop the ->withCookie(forget()) from
         * AdminAuthController::logout() and this is red.
         */
        $this->actingAs(raAdmin(), 'admin');

        $response = $this->post(route('admin.logout'));

        $hint = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === StorefrontAdminHint::COOKIE);

        expect($hint)->not->toBeNull('signing out left the hint behind')
            ->and($hint->getExpiresTime())->toBeLessThan(time());
    });
});

/* ========================================================== the context call */

describe('the context endpoint', function () {
    it('answers 401 to a guest, and to a guest carrying a forged hint', function () {
        /*
         * The hint grants nothing. A shopper who types document.cookie =
         * "kbb_ah=1" gets a 401 and no bar.
         */
        raCategory();

        raContext($this, '/collections/ra-sunscreens/')->assertStatus(401)->assertJsonMissing(['bar']);

        $this->withUnencryptedCookie(StorefrontAdminHint::COOKIE, '1');
        $forged = raContext($this, '/collections/ra-sunscreens/');

        expect($forged->status())->toBe(401)
            ->and($forged->getContent())->not->toContain('admin-api/storefront/quick-edit')
            ->and($forged->getContent())->not->toContain('Rafi');
    });

    it('keeps the bar and the pencil owner-only, answers 403 to a role with no storefront tool, and clears its hint', function () {
        /*
         * "now for me only". MUTATION, RUN: add 'manager' to
         * storefront.adminbar in AdminCapabilities and the manager's bar is
         * not null.
         *
         * Lane PH: a manager and an editor hold pageheader.manage, the custom
         * pages' "Edit header" panel, so they are answered 200 -- with no bar,
         * no pencil, and on '/' (not a custom page) no panel either. Support
         * holds none of the three and is still refused.
         */
        foreach (['manager', 'support', 'editor'] as $role) {
            $this->actingAs(raAdmin($role, "ra-{$role}@example.test"), 'admin');

            $r = raContext($this, '/');

            expect($r->status())->toBe($role === 'support' ? 403 : 200, "{$role} reached the storefront tools")
                ->and($r->json('bar'))->toBeNull()
                ->and($r->json('edit'))->toBeNull()
                ->and($r->json('pageheader'))->toBeNull();

            if ($role === 'support') {
                $hint = collect($r->headers->getCookies())->first(fn ($c) => $c->getName() === StorefrontAdminHint::COOKIE);
                expect($hint?->getExpiresTime())->toBeLessThan(time());
            }
        }
    });

    it('answers the owner with exactly the allowlisted fields, never stored', function () {
        /*
         * The body is built key by key. A model dump would carry columns like
         * `header_source` (where the import took the picture from) and
         * `seo`; an absolute URL would hand the script an address to follow.
         *
         * MUTATION, RUN: return `'edit' => $page['model']` and the key-set
         * assertion is red.
         */
        $category = raCategory(['header_source' => 'secret-term-meta-key', 'header_title' => 'Summer SPF']);
        $this->actingAs(raAdmin(), 'admin');

        $r = raContext($this, '/collections/ra-sunscreens/')->assertOk();

        expect(strtolower((string) $r->headers->get('Cache-Control')))->toContain('no-store')
            ->and(array_keys($r->json()))->toBe(['ok', 'csrf', 'admin', 'bar', 'edit', 'pageheader', 'categoryheader'])  // Lane CH: the category panel's context
            ->and(array_keys($r->json('admin')))->toBe(['name', 'initials'])
            ->and($r->json('admin.name'))->toBe('Rafi Owner')
            ->and($r->json('admin.initials'))->toBe('RO')
            ->and(array_keys($r->json('bar')))->toBe(['title', 'console', 'links', 'context', 'logout', 'clear_cache'])
            ->and(array_keys($r->json('edit')))->toBe([
                'type', 'id', 'name', 'mode', 'fields', 'placeholders', 'keys', 'endpoints', 'upload', 'css', 'more',
            ])
            ->and(array_keys($r->json('edit.fields')))->toBe(['header_image', 'header_title', 'header_subtitle', 'header_description', 'focus'])
            ->and($r->json('edit.type'))->toBe('category')
            ->and($r->json('edit.id'))->toBe($category->id)
            ->and($r->json('edit.fields.header_title'))->toBe('Summer SPF')
            ->and($r->getContent())->not->toContain('secret-term-meta-key');

        // Every link is a same-origin path, never an absolute URL.
        $hrefs = array_merge(
            array_column($r->json('bar.links'), 'href'),
            [$r->json('bar.context.href'), $r->json('bar.console'), $r->json('bar.logout')],
            array_column($r->json('edit.more'), 'href'),
            array_values($r->json('edit.endpoints')),
        );

        foreach ($hrefs as $href) {
            expect($href)->toStartWith('/')->and($href)->not->toStartWith('//');
        }

        expect($r->json('bar.context.label'))->toBe('Edit category')
            ->and($r->json('bar.context.href'))->toContain('kbb-open=category:' . $category->id);
    });

    it('knows a brand page, a product page and the homepage, and offers no pencil off them', function () {
        $brand = raBrand();
        $product = Product::create(['slug' => 'ra-serum', 'name' => 'RA Serum', 'price' => 1000, 'status' => 'publish']);
        $this->actingAs(raAdmin(), 'admin');

        $b = raContext($this, '/brands/ra-cosrx/')->assertOk();
        expect($b->json('edit.type'))->toBe('brand')
            ->and($b->json('edit.id'))->toBe($brand->id)
            ->and($b->json('edit.keys'))->toBe(StorefrontAdminController::BRAND_KEYS)
            ->and($b->json('bar.context.label'))->toBe('Edit brand');

        $p = raContext($this, '/product/ra-serum/')->assertOk();
        expect($p->json('edit'))->toBeNull()
            ->and($p->json('bar.context.label'))->toBe('Edit product')
            ->and($p->json('bar.context.href'))->toContain('kbb-open=product:' . $product->id);

        $home = raContext($this, '/')->assertOk();
        expect($home->json('edit'))->toBeNull()->and($home->json('bar.context.label'))->toBe('Homepage');

        $nowhere = raContext($this, '/no-such-page-at-all/')->assertOk();
        expect($nowhere->json('edit'))->toBeNull()->and($nowhere->json('bar.context'))->toBeNull();

        // A category address that names nothing gets no pencil either.
        expect(raContext($this, '/collections/ra-nothing-here/')->json('edit'))->toBeNull();
    });
});

/* ================================================================ the save */

describe('the quick-edit save', function () {
    it('writes the header fields and hands back the header exactly as the page draws it', function () {
        $category = raCategory(['header_style' => ['align' => 'center', 'treatment' => 'frost']]);
        $this->actingAs(raAdmin(), 'admin');

        $r = $this->postJson("/admin-api/storefront/quick-edit/category/{$category->id}", [
            'header_image' => '/uploads/categories/ra-banner.jpg',
            'header_title' => 'Sun & <b>Shade</b>',
            'header_subtitle' => 'For the UAE sun',
            'header_description' => 'Our lightest SPFs.',
            'focus' => 'right',
            'path' => '/collections/ra-sunscreens/',
        ])->assertOk();

        $fresh = $category->fresh();

        expect($fresh->header_image)->toBe('/uploads/categories/ra-banner.jpg')
            ->and($fresh->header_title)->toBe('Sun & <b>Shade</b>')
            ->and($fresh->header_subtitle)->toBe('For the UAE sun')
            ->and($fresh->header_description)->toBe('Our lightest SPFs.')
            // MERGED, not replaced: the full editor's choices survive the crop.
            ->and($fresh->header_style)->toMatchArray(['align' => 'center', 'treatment' => 'frost', 'focus' => 'right'])
            ->and($r->json('kind'))->toBe('header')
            ->and($r->json('html'))->toContain('data-kbb-title-header')
            ->and($r->json('html'))->toContain('/uploads/categories/ra-banner.jpg')
            // Tags stripped by TitleHeader and the rest escaped by the
            // component, exactly as on the page.
            ->and($r->json('html'))->toContain('>Sun &amp; Shade</h1>')
            ->and($r->json('html'))->not->toContain('<b>Shade');

        // And it is the same markup the shopper's page now carries.
        $page = $this->get('/collections/ra-sunscreens/')->getContent();
        expect($page)->toContain('>Sun &amp; Shade</h1>')->and($page)->toContain('/uploads/categories/ra-banner.jpg');
    });

    it('refuses a javascript: or data: picture with the category editor\'s own sentence', function () {
        /*
         * The same TitleHeaderInput::clean() Catalog -> Categories -> Edit uses.
         * MUTATION, RUN: return $data early from TitleHeaderInput::clean() and
         * this is red.
         */
        $category = raCategory();
        $this->actingAs(raAdmin(), 'admin');

        foreach (['javascript:alert(1)', 'data:image/svg+xml;base64,PHN2Zz4='] as $bad) {
            $this->postJson("/admin-api/storefront/quick-edit/category/{$category->id}", ['header_image' => $bad])
                ->assertStatus(422)
                ->assertJsonPath('errors.header_image.0', 'The header picture must be an uploaded file or an http(s) address.');
        }

        expect($category->fresh()->header_image)->toBeNull();
    });

    it('refuses any key it does not own, so it cannot rename or move a category', function () {
        /*
         * A name or slug change moves indexed URLs; that is the full editor's
         * decision, with its redirects. MUTATION, RUN: delete the $unknown
         * check in validatedFor() and the slug row is 200.
         */
        $category = raCategory();
        $this->actingAs(raAdmin(), 'admin');

        foreach (['name' => 'Renamed', 'slug' => 'moved', 'parent_id' => 1, 'banner' => ['enabled' => true], 'header_source' => 'x'] as $key => $value) {
            $this->postJson("/admin-api/storefront/quick-edit/category/{$category->id}", [$key => $value])
                ->assertStatus(422);
        }

        expect($category->fresh()->only('name', 'slug', 'parent_id', 'banner'))
            ->toBe(['name' => 'RA Sunscreens', 'slug' => 'ra-sunscreens', 'parent_id' => null, 'banner' => null]);

        // A brand has no style column, so no phone crop either.
        $brand = raBrand();
        $this->postJson("/admin-api/storefront/quick-edit/brand/{$brand->id}", ['focus' => 'left'])->assertStatus(422);
    });

    it('enforces the same length rules as the category editor', function () {
        $category = raCategory();
        $this->actingAs(raAdmin(), 'admin');

        $this->postJson("/admin-api/storefront/quick-edit/category/{$category->id}", ['header_title' => str_repeat('a', 301)])
            ->assertStatus(422)->assertJsonValidationErrors('header_title');
        $this->postJson("/admin-api/storefront/quick-edit/category/{$category->id}", ['focus' => 'top'])
            ->assertStatus(422);
    });

    it('is the owner\'s alone today: every other role is refused before anything is written', function () {
        /*
         * MUTATION, RUN: map the save rule to `catalog.manage` in
         * AdminCapabilities::RULES and the manager and editor rows write.
         */
        $category = raCategory();

        foreach (['manager', 'support', 'editor'] as $role) {
            $this->actingAs(raAdmin($role, "ra-w-{$role}@example.test"), 'admin');

            $this->postJson("/admin-api/storefront/quick-edit/category/{$category->id}", ['header_title' => "by {$role}"])
                ->assertStatus(403);
            $this->postJson("/admin-api/storefront/quick-edit/category/{$category->id}/preview", ['header_title' => "by {$role}"])
                ->assertStatus(403);
        }

        expect($category->fresh()->header_title)->toBeNull();

        auth('admin')->logout();
        $this->postJson("/admin-api/storefront/quick-edit/category/{$category->id}", ['header_title' => 'guest'])
            ->assertStatus(401);
        expect($category->fresh()->header_title)->toBeNull();
    });

    it('needs the CSRF token', function () {
        /*
         * The test kernel skips CSRF for every request, so this case puts the
         * real check back for one request. MUTATION, RUN: register the save
         * route with ->withoutMiddleware(ValidateCsrfToken::class) and this is
         * 200.
         */
        $category = raCategory();
        $this->actingAs(raAdmin(), 'admin');

        $this->app->instance(
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            new class($this->app, $this->app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            }
        );

        $this->postJson("/admin-api/storefront/quick-edit/category/{$category->id}", ['header_title' => 'no token'])
            ->assertStatus(419);
        expect($category->fresh()->header_title)->toBeNull();
    });

    it('rate-limits saves per admin, and previews do not spend the save budget', function () {
        /*
         * The defect, measured in the preview: with `throttle:20,1` middleware
         * every throttled route shared one domain|ip counter (the admin guard is
         * not the default guard, so the throttle never saw a user), the context
         * reads and previews used up the twenty, and after a minute of typing
         * Save answered "Too Many Attempts".
         *
         * MUTATION, RUN: key limit() on `request()->ip()` alone, dropping the
         * action, and the save after 95 previews is a 429 -- red.
         */
        $category = raCategory();
        $this->actingAs(raAdmin(), 'admin');

        for ($i = 0; $i < StorefrontAdminController::LIMITS['preview']; $i++) {
            $this->postJson("/admin-api/storefront/quick-edit/category/{$category->id}/preview", ['header_title' => "p{$i}"]);
        }

        $this->postJson("/admin-api/storefront/quick-edit/category/{$category->id}/preview", ['header_title' => 'one too many'])
            ->assertStatus(429);

        for ($i = 0; $i < StorefrontAdminController::LIMITS['save']; $i++) {
            $this->postJson("/admin-api/storefront/quick-edit/category/{$category->id}", ['header_title' => "s{$i}"])
                ->assertOk();
        }

        $this->postJson("/admin-api/storefront/quick-edit/category/{$category->id}", ['header_title' => 'over'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too many saves in a minute. Wait a moment and press Save again.');

        expect($category->fresh()->header_title)->toBe('s' . (StorefrontAdminController::LIMITS['save'] - 1));
    });

    it('previews without writing anything', function () {
        $category = raCategory();
        $this->actingAs(raAdmin(), 'admin');

        $r = $this->postJson("/admin-api/storefront/quick-edit/category/{$category->id}/preview", [
            'header_title' => 'Only a preview',
        ])->assertOk();

        expect($r->json('html'))->toContain('Only a preview')
            ->and($category->fresh()->header_title)->toBeNull();
    });

    it('edits a brand\'s own header description without touching the brand description', function () {
        /*
         * The brand directory and the page's meta read `description`; the
         * pencil writes `header_description` (new column, Lane RA).
         */
        $brand = raBrand(['description' => 'The directory line.', 'header_image' => '/uploads/brands/ra.jpg']);
        $this->actingAs(raAdmin(), 'admin');

        $r = $this->postJson("/admin-api/storefront/quick-edit/brand/{$brand->id}", [
            'header_description' => 'Only on the header.',
            'header_title' => 'COSRX',
        ])->assertOk();

        expect($brand->fresh()->description)->toBe('The directory line.')
            ->and($brand->fresh()->header_description)->toBe('Only on the header.')
            ->and($r->json('html'))->toContain('Only on the header.');
    });

    it('edits the banner when the page shows one, through PageBanner::sanitize', function () {
        $brand = raBrand(['banner' => [
            'enabled' => true, 'style' => 'full', 'image' => '/media/old.jpg', 'heading' => 'Old', 'tone' => 'light',
        ]]);
        $this->actingAs(raAdmin(), 'admin');

        expect(raContext($this, '/brands/ra-cosrx/')->json('edit.mode'))->toBe('banner');

        $r = $this->postJson("/admin-api/storefront/quick-edit/brand/{$brand->id}", [
            'header_image' => '/uploads/brands/new.jpg', 'header_title' => 'New heading',
        ])->assertOk();

        $banner = $brand->fresh()->banner;

        expect($banner['image'])->toBe('/uploads/brands/new.jpg')
            ->and($banner['heading'])->toBe('New heading')
            ->and($banner['style'])->toBe('full')
            ->and($brand->fresh()->header_image)->toBeNull()
            ->and($r->json('kind'))->toBe('banner');
    });
});

/* ======================================================= the shop's own HTML */

describe('the shop page itself', function () {
    it('is the same bytes for the owner as for a shopper: no bar, no pencil, no console address', function () {
        /*
         * The whole design rests on this. If the bar were rendered server-side
         * for an admin, a cached page could carry it to a shopper.
         * MUTATION, RUN: print `@auth('admin') <div class="kbb-adm"> @endauth`
         * in layouts/store.blade.php and this is red.
         */
        raCategory();
        $owner = raAdmin();

        $mask = fn (string $html) => preg_replace(
            ['/name="csrf-token" content="[^"]*"/', '/"csrf":"[^"]*"/', '/name="_token" value="[^"]*"/'],
            ['name="csrf-token" content=""', '"csrf":""', 'name="_token" value=""'],
            $html
        );

        $guest = $mask($this->get('/collections/ra-sunscreens/')->getContent());

        // Signed in the real way, through the console's sign-in form, so the
        // session carries the admin guard exactly as the owner's browser does.
        // (actingAs() would also make `admin` the DEFAULT guard for the test,
        // which the layout's account-panel font reads -- a test artefact.)
        $this->post(route('admin.login.post'), ['email' => $owner->email, 'password' => 'password-long-enough'])
            ->assertRedirect();
        expect(auth('admin')->check())->toBeTrue();

        $admin = $mask($this->get('/collections/ra-sunscreens/')->getContent());

        expect($admin)->toBe($guest)
            ->and($admin)->not->toContain('kbb-adm')
            ->and($admin)->not->toContain('kbb-qe')
            ->and($admin)->not->toContain('Rafi Owner')
            ->and($admin)->not->toContain('storefront/context');
    });
});

/* ============================================================== capabilities */

describe('the capabilities', function () {
    it('exist, are owner-only, and map every route this lane adds', function () {
        expect(AdminCapabilities::CAPABILITIES['storefront.adminbar'])->toBe(['owner'])
            ->and(AdminCapabilities::CAPABILITIES['storefront.quick_edit'])->toBe(['owner'])
            ->and(AdminCapabilities::forPath('POST', 'admin-api/storefront/quick-edit/{type}/{id}'))->toBe('storefront.quick_edit')
            ->and(AdminCapabilities::forPath('POST', 'admin-api/storefront/quick-edit/{type}/{id}/preview'))->toBe('storefront.quick_edit')
            ->and(AdminCapabilities::forPath('GET', 'admin-api/storefront/context'))->toBe('admin.access')
            // The other two addresses the context answer hands the script.
            ->and(AdminCapabilities::forPath('POST', 'admin-api/cache/clear'))->toBe('cache.manage')
            ->and(AdminCapabilities::forPath('POST', 'admin-api/media/upload'))->not->toBeNull();

        foreach (['manager', 'support', 'editor', 'staff', 'nonsense', null] as $role) {
            expect(AdminCapabilities::roleCan($role, 'storefront.quick_edit'))->toBeFalse()
                ->and(AdminCapabilities::roleCan($role, 'storefront.adminbar'))->toBeFalse();
        }

        // Fails closed: a storefront path this lane did not map is owner-only.
        expect(AdminCapabilities::forPath('DELETE', 'admin-api/storefront/quick-edit/{type}/{id}'))->toBeNull();
    });

    it('is mounted by routes/web.php exactly once, inside the admin-api group', function () {
        /*
         * The finished state, which is green the moment the integrator adds the
         * one require line (CLAUDE.md: "Pin the FINISHED state").
         */
        $web = (string) file_get_contents(base_path('routes/web.php'));

        expect(substr_count($web, "require __DIR__.'/storefront-admin.php';")
            + substr_count($web, "require __DIR__ . '/storefront-admin.php';"))->toBe(1);
    });
});

/* ===================================================================== the JS */

describe('the loader', function () {
    it('does nothing at all without the hint: one cookie test, then the lazy chunk', function () {
        /*
         * A shopper's browser must make no request. MUTATION, RUN: move the
         * import() above the hasAdminHint() test and the order assertion is
         * red.
         */
        $js = (string) file_get_contents(resource_path('js/kbb/admin-hint.js'));
        $body = substr($js, (int) strpos($js, 'export function initAdminLayer'));

        expect($js)->toContain("export const hasAdminHint = (cookie) => /(?:^|;\\s*)kbb_ah=/.test(String(cookie || ''));")
            ->and(strpos($body, 'if (!hasAdminHint(document.cookie)) return;'))->toBeLessThan(strpos($body, 'import('))
            // A dynamic import, so the bar's code and CSS are a separate chunk.
            ->and($js)->toContain("import('./admin/storefront-admin.js')")
            ->and($js)->not->toMatch('/^import .*storefront-admin/m');

        $app = (string) file_get_contents(resource_path('js/kbb/app.js'));
        expect(substr_count($app, "import { initAdminLayer } from './admin-hint.js';"))->toBe(1)
            ->and(substr_count($app, "    initAdminLayer,\n"))->toBe(1);

        // And the built manifest knows it as a dynamic import, never a static one.
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        expect($manifest['resources/js/kbb/app.js']['dynamicImports'] ?? [])->toContain('resources/js/kbb/admin/storefront-admin.js')
            ->and($manifest['resources/js/kbb/app.js']['imports'] ?? [])->not->toContain('resources/js/kbb/admin/storefront-admin.js');
    });

    it('recognises the hint and only the hint, in a real JavaScript engine', function () {
        if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('node is not on PATH');
        }

        $file = resource_path('js/kbb/admin-hint.js');
        $script = 'import(' . json_encode('file://' . $file) . ').then(m => console.log(JSON.stringify(['
            . 'm.hasAdminHint(""), m.hasAdminHint("kbb_cart=abc"), m.hasAdminHint("xkbb_ah=1"),'
            . 'm.hasAdminHint("kbb_ah=eyJpdiI6"), m.hasAdminHint("a=1; kbb_ah=1"), m.hasAdminHint(undefined)])))';

        $out = json_decode((string) shell_exec('node --input-type=module -e ' . escapeshellarg($script) . ' 2>&1'), true);

        expect($out)->toBe([false, false, false, true, true, false]);
    });

    it('writes data with textContent, checks every href, and measures no element', function () {
        $js = (string) file_get_contents(resource_path('js/kbb/admin/storefront-admin.js'));

        expect($js)->not->toContain('.innerHTML')
            ->and($js)->not->toContain('insertAdjacentHTML')
            ->and($js)->toContain('export function safePath(value)');

        foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'getComputedStyle', 'ResizeObserver'] as $api) {
            expect(str_contains($js, $api))->toBeFalse("the admin layer measures layout: {$api}");
        }
    });

    it('keeps the upload limit equal to the media library\'s', function () {
        $src = (string) file_get_contents(app_path('Http/Controllers/Admin/MediaUploadController.php'));
        preg_match('/MAX_BYTES = (\d+) \* 1024 \* 1024/', $src, $m);

        expect(StorefrontAdminController::UPLOAD_MAX_BYTES)->toBe(((int) ($m[1] ?? 0)) * 1024 * 1024);
    });
});

/* ===================================================== the bar's own layout */

describe('the bar\'s CSS', function () {
    it('restates each sticky offset it moves, and sits under every drawer', function () {
        /*
         * The bar pushes the sticky header down by its own height and sits at
         * z-index 89, under the header (90) and every panel (90-120), so an
         * open cart panel covers the bar rather than the bar covering the
         * panel's close button. If a sheet moves its own offset, this goes red
         * and the rule here has to move with it.
         */
        $css = (string) file_get_contents(resource_path('js/kbb/admin/storefront-admin.css'));
        $shop = (string) file_get_contents(resource_path('css/kbb/kbb-shop.css'));
        $kbb = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
        $cart = (string) file_get_contents(resource_path('css/kbb/kbb-cart.css'));

        expect($css)->toContain('.kbb-adm{')
            ->and($css)->toMatch('/\.kbb-adm\{[^}]*z-index:89;/')
            ->and($kbb)->toContain('header{position:sticky;top:0;z-index:90;')
            ->and($css)->toContain('html.kbb-adm-on body>header')
            ->and($shop)->toContain('.filtercol{align-self:start;position:sticky;top:82px;')
            ->and($css)->toContain('html.kbb-adm-on .filtercol{top:calc(82px + var(--kbb-adm-h))}')
            ->and($cart)->toContain('position:sticky;top:120px}')
            ->and($css)->toContain('html.kbb-adm-on .kbb-cartpage .sum{top:calc(120px + var(--kbb-adm-h))}');

        // Every rule outside the two namespaces is gated on html.kbb-adm-on.
        $loose = [];
        foreach (preg_split('/\}/', (string) preg_replace('#/\*.*?\*/#s', '', $css)) as $rule) {
            $selector = trim((string) strstr($rule, '{', true));
            if ($selector === '' || str_starts_with($selector, '@') || str_contains($selector, '%') || in_array($selector, ['from', 'to'], true)) {
                continue;
            }
            foreach (explode(',', $selector) as $one) {
                $one = trim($one);
                if ($one !== '' && ! preg_match('/kbb-(adm|qe)|^:root$/', $one)) {
                    $loose[] = $one;
                }
            }
        }

        expect($loose)->toBe([]);
    });
});
