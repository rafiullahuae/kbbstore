<?php

declare(strict_types=1);

use App\Models\AdminUser;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Support\ProductEditorRoutes;

/**
 * Lane AT — the duplicate product editors are retired, and stay retired.
 *
 * THE SITUATION THIS CLOSES. The admin had grown three ways to write a product,
 * built by three lanes in quick succession:
 *
 *   1. cpOpenDetail()  — Lane AF's inline two-column panel, from the list.
 *   2. cpOpenCreate()  — Lane AK's narrower create form, plus
 *                        CatalogProductCreateApiController and its three routes.
 *   3. the product editor — Lane AO's full screen: gallery, several categories,
 *                        rich copy, SEO, scheduled publishing.
 *
 * The owner was already bitten by it: the Edit button pointed at (1), so (3)
 * looked as though it had never shipped. Two of them are now gone and (3) is
 * the only product editor in the console.
 *
 * WHAT IS ASSERTED HERE, AND WHY IN THIS SHAPE.
 *
 * The retired paths are asserted against the REGISTERED route table and against
 * a real request, NOT against the text of a route file. A route file that no
 * longer lists a path proves nothing on its own: routes/web.php still carries
 * `require __DIR__.'/catalog-product-create-admin.php';` — CLAUDE.md forbids
 * this lane from editing that file — so the require still runs, and the only
 * question that matters is what the router ends up holding. This session has
 * already found two registered endpoints that could never have worked, which is
 * the same mistake read from the other side.
 *
 * The surviving editor is asserted to be STILL MOUNTED and still behind
 * `auth:admin`, read back off the registered routes. A subtractive package that
 * quietly took the replacement down with the thing it replaced would leave the
 * owner unable to edit a product at all, and every "is it gone?" assertion below
 * would still be green.
 */

/* ------------------------------------------------------- the retired routes */

/** The three paths Lane AK registered, which must no longer exist. */
function atRetiredPaths(): array
{
    return [
        'admin-api/catalog-product-slug',
        'admin-api/catalog-product-create',
        'admin-api/catalog-product-image/{id}',
    ];
}

it('no longer registers any of the retired create routes', function () {
    // Wire the surviving editor exactly as the integrator is told to, so this
    // is asserted against the route table the application really ends up with
    // rather than against a half-booted one.
    ProductEditorRoutes::wire(app());

    $uris = collect(RouteFacade::getRoutes()->getRoutes())
        ->map(fn ($r) => $r->uri())
        ->all();

    // One needle per call: toContain() is variadic, so a "message" passed here
    // would silently become a second needle rather than a message.
    foreach (atRetiredPaths() as $path) {
        expect($uris)->not->toContain($path);
    }

    // Nothing anywhere in the table still points at the deleted controller,
    // whatever path it might have been mounted under.
    $actions = collect(RouteFacade::getRoutes()->getRoutes())
        ->map(fn ($r) => (string) ($r->getAction('controller') ?? ''))
        ->filter()
        ->all();

    foreach ($actions as $action) {
        expect($action)->not->toContain('CatalogProductCreateApiController');
    }
});

it('really does 404 the retired paths, rather than merely not listing them', function () {
    ProductEditorRoutes::wire(app());

    /*
     * Signed in as an admin ON PURPOSE. An anonymous 401 would be indistinguish-
     * able from the guard doing its job on a route that still exists, and would
     * pass just as happily if the endpoints were all still there. The only
     * answer that proves removal is "no such path" to a caller who would
     * otherwise be allowed through.
     */
    test()->actingAs(AdminUser::create([
        'name' => 'AT Owner',
        'email' => 'at-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]), 'admin');

    /*
     * GET is asserted at 404 and POST at 405, and the difference is the proof
     * rather than an inconsistency to be smoothed over.
     *
     * routes/web.php ends in `Route::fallback(fn () => abort(404))`, and a
     * Laravel fallback is registered for GET ONLY — its URI pattern is
     * `{fallbackPlaceholder}` where `.*`. So an unrouted GET reaches the
     * fallback and becomes a real 404, while an unrouted POST matches that
     * pattern on the path but not on the method and comes back 405 from the
     * router itself, before any controller.
     *
     * 405 on a POST is therefore the STRONGER of the two statements for these
     * three, because all three were POST endpoints: it says the router holds no
     * POST route for that URI at all. Asserting a flat 404 everywhere would have
     * meant writing a test that could only pass by accident.
     */
    foreach (['/admin-api/catalog-product-slug', '/admin-api/catalog-product-create', '/admin-api/catalog-product-image/1'] as $path) {
        expect(test()->getJson($path)->getStatusCode())->toBe(404);

        // Never 2xx, never a validation 422, never a 401 from a guard on a
        // route that still exists — the router refuses the method outright.
        expect(test()->postJson($path, ['name' => 'x'])->getStatusCode())->toBe(405);
    }
});

it('has deleted the retired controller class, not merely unrouted it', function () {
    // An unrouted controller is one require line away from being live again.
    expect(class_exists(\App\Http\Controllers\Admin\CatalogProductCreateApiController::class))
        ->toBeFalse('the retired create controller is still autoloadable');

    expect(file_exists(base_path('app/Http/Controllers/Admin/CatalogProductCreateApiController.php')))
        ->toBeFalse();
});

it('removes the retired route file and its require together', function () {
    /*
     * This asserted the opposite while the lane was building: routes/web.php
     * was not its file to edit and still carried the require, and a require of
     * a path that does not exist is a FATAL error, not a missing feature -- it
     * would take the storefront down with the admin. So the file stayed as an
     * inert tombstone until the integrator could remove both together.
     *
     * The integrator has. Inverted, because the old assertion would now pin a
     * dead file into the tree forever, and because THIS is the pairing that
     * actually matters: neither may exist without the other. A require with no
     * file is a fatal boot error; a file with no require is dead code that
     * still looks wired.
     */
    $file = base_path('routes/catalog-product-create-admin.php');
    $web = (string) file_get_contents(base_path('routes/web.php'));

    expect(file_exists($file))->toBeFalse('the retired route file is still on disk')
        ->and($web)->not->toContain('catalog-product-create-admin.php');

    // And the application still boots, which is the thing a bad require breaks.
    expect(app('router')->getRoutes()->getRoutes())->not->toBeEmpty();
});

/* ----------------------------------------------------- the survivor is intact */

it('still mounts the full product editor, behind auth:admin', function () {
    ProductEditorRoutes::wire(app());

    $routes = ProductEditorRoutes::registered();

    expect($routes)->not->toBeEmpty('the surviving editor lost its routes too');

    /*
     * Read off the REGISTERED routes rather than trusted from the harness.
     * RouteRegistrar::middleware() REPLACES rather than appends, so a harness
     * that chains it twice guards nothing while reading as though it did.
     */
    foreach ($routes as $route) {
        // toContain() is VARIADIC in Pest — every argument is another needle,
        // not a failure message. Passing a message here would assert that the
        // middleware list contains the message itself, which is a test that can
        // only ever fail, and one that fails for a reason unrelated to the
        // guard it claims to check. (This test made exactly that mistake once.)
        expect($route->gatherMiddleware())->toContain('web', 'auth:admin', \App\Http\Middleware\NoStoreAdminApi::class);
    }

    $uris = collect($routes)->map(fn ($r) => $r->uri())->all();

    // One create path and one edit path, both on the survivor.
    expect($uris)->toContain('admin-api/product-editor-create')
        ->toContain('admin-api/product-editor-save/{id}')
        ->toContain('admin-api/product-editor-load/{id}');
});

it('refuses the surviving editor to anyone who is not an admin', function () {
    ProductEditorRoutes::wire(app());

    // The retired endpoints being gone is worth nothing if what replaced them
    // is open. /api/* in this application is unauthenticated BY DESIGN, so the
    // line these routes sit on is the only thing between the public and the
    // shop's own catalogue.
    expect(test()->postJson('/admin-api/product-editor-create', ['name' => 'x'])->getStatusCode())->toBe(401)
        ->and(test()->postJson('/admin-api/product-editor-save/1', ['name' => 'x'])->getStatusCode())->toBe(401);
});

/* ------------------------------------------------------------- the console UI */

it('has removed both retired screens from the admin console', function () {
    $view = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    // The whole Lane AK region, and the Lane AF detail panel.
    expect($view)->not->toContain('LANE AK · Catalog · Products · Add + image')
        ->and($view)->not->toContain('function cpOpenCreate')
        ->and($view)->not->toContain('window.cpOpenCreate = ')
        ->and($view)->not->toContain('async function cpOpenDetail')
        ->and($view)->not->toContain('function cpImageBoxHtml')
        ->and($view)->not->toContain('function cpWireImageBox');

    // And nothing in the document still calls them. A removed function that
    // something still calls is a JavaScript error, not a test failure, which is
    // exactly why this is asserted on the source as well as in the browser.
    expect($view)->not->toContain('cpOpenDetail(+b.dataset.cpedit)')
        ->and($view)->not->toContain('window.cpOpenCreate()');

    // No caller anywhere for the retired endpoints.
    expect($view)->not->toContain('/admin-api/catalog-product-create')
        ->and($view)->not->toContain('/admin-api/catalog-product-slug')
        ->and($view)->not->toContain('/admin-api/catalog-product-image/');
});

it('points Edit and Add product at the one surviving editor', function () {
    $view = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    // Edit, on each row of the products list, opens the full editor.
    expect($view)->toContain('window.peoEdit')
        ->and($view)->toContain("window.go('product-editor')");

    // The editor screen itself is still included in the document.
    expect($view)->toContain("@include('admin.partials.product-editor-screen')");
});

it('keeps the inline list cells, which are a workflow rather than a duplicate', function () {
    $view = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    /*
     * CatalogProductsApiController::update() and the cells that drive it STAY.
     * Editing a price or a stock number without leaving the list is a real
     * workflow, not a second editor, and retiring it would have cost the owner
     * something the full editor does not replace: changing twenty prices
     * without opening twenty screens.
     */
    expect($view)->toContain('function cpOpenCell')
        ->and($view)->toContain('/admin-api/catalog-products-save/')
        ->and($view)->toContain('window.catProducts');
});

it('renders the whole admin document with the retired screens gone', function () {
    // One 600KB Blade file with several lanes editing it at once: a stray brace
    // left behind by a deletion takes down the entire admin console.
    $html = view('admin.app')->render();

    expect($html)->toContain('window.catProducts')
        ->and($html)->toContain('peoEdit')
        ->and($html)->not->toContain('cpOpenCreate');
});
