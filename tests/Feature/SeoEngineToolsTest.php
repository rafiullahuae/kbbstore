<?php

declare(strict_types=1);

/**
 * The rest of the SEO Engine module — the parts the plan describes as "much
 * larger" than the meta block: redirects, the 404 log, the schema inspector
 * and the catalogue audit.
 *
 * All four were already built. Nothing pinned any of them, which is how
 * `seo_engine`'s registry status came to say `live` against a module whose
 * switch nothing read. These tests exist so the next person auditing this does
 * not have to establish the same four facts by hand again — and so a refactor
 * cannot quietly unwire them the way CheckRedirects was already unwired once
 * (registered as middleware, never actually running; see that class's own
 * doc comment).
 *
 * Deliberately end-to-end: a real request in, a real status code and body out.
 * "The controller returns the right array" is what the schema inspector was
 * already able to prove about itself and is not the thing that broke.
 */

use App\Http\Middleware\CheckRedirects;
use App\Models\AdminUser;
use App\Models\NotFoundLog;
use App\Models\Product;
use App\Models\Redirect;
use App\Support\RedirectManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('redirects')->delete();
    DB::table('not_found_log')->delete();
});

/* ─────────────────────────────── redirects ─────────────────────────────── */

/*
 * A note on trailing slashes, because it decides how these are written.
 *
 * CheckRedirects::findMatch compares `source` against getPathInfo() with plain
 * equality, and this site's real URLs keep a trailing slash (U-01). But
 * Laravel's test client strips one before the request is built
 * (MakesHttpRequests::prepareUrlForRequest), so a full HTTP round trip through
 * this harness can only ever exercise the slash-less spelling — the same
 * limitation SeoLayoutTest documents for canonical URLs.
 *
 * That is not a gap in coverage as long as both spellings are pinned, which is
 * why the slash-less form goes through a real request and the trailing-slash
 * form is checked against the matcher directly. The seeded Phase 9 redirects
 * store both spellings per article for exactly this reason.
 */
it('serves a stored redirect instead of the 404 the path would otherwise raise', function () {
    Redirect::create([
        'source' => '/an-old-marketing-url',
        'target' => '/shop/',
        'code' => 301,
        'enabled' => true,
    ]);

    // Not a route this app serves — which is the point. The check lives in the
    // 404 exception handler, not in middleware, because middleware demonstrably
    // did not run for most requests.
    $response = $this->get('/an-old-marketing-url')->assertStatus(301);

    /*
     * ════════════════════════════════════════════════════════════════════════
     * THE HEADER IS READ DIRECTLY, AND assertRedirect() CANNOT DO THIS
     * ════════════════════════════════════════════════════════════════════════
     *
     * This line used to be `->assertRedirect('/shop/')` and it PASSED against a
     * Location of `http://localhost/shop`. assertRedirect() builds its expected
     * URL through the same UrlGenerator the redirect went through, and that
     * generator strips a trailing slash — so both sides were stripped and the
     * assertion could not see the difference it was written to check.
     *
     * The stored target keeps its slash (U-01), and since Lane GP the Location
     * does too, because the handler goes through Url::redirect(). Asserting the
     * raw header is the only spelling of this that would go red if it stopped.
     */
    expect((string) $response->headers->get('Location'))->toEndWith('/shop/');

    expect(Redirect::where('source', '/an-old-marketing-url')->value('hits'))->toBe(1);
});

it('matches the trailing-slash spelling a real visitor arrives on', function () {
    Redirect::create([
        'source' => '/an-old-marketing-url/',
        'target' => '/shop/',
        'code' => 301,
        'enabled' => true,
    ]);

    $match = CheckRedirects::findMatch(Request::create('https://kbeautybliss.test/an-old-marketing-url/'));

    expect($match)->not->toBeNull()
        ->and($match->target)->toBe('/shop/');
});

it('ignores a redirect that has been switched off', function () {
    Redirect::create([
        'source' => '/a-disabled-url',
        'target' => '/shop/',
        'code' => 301,
        'enabled' => false,
    ]);

    $this->get('/a-disabled-url')->assertNotFound();
});

it('auto-creates a redirect when a published product slug changes', function () {
    $product = Product::create([
        'slug' => 'old-slug-'.uniqid(),
        'name' => 'Renamed Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
    ]);

    $old = $product->slug;
    $product->update(['slug' => 'new-slug-'.uniqid()]);

    expect(Redirect::where('source', '/product/'.$old.'/')->value('target'))
        ->toBe('/product/'.$product->slug.'/');
});

it('collapses a redirect chain rather than leaving A to B to C', function () {
    Redirect::create(['source' => '/a/', 'target' => '/b/', 'code' => 301, 'enabled' => true]);

    RedirectManager::autoCreate('/b/', '/c/');

    // A must now point straight at C. Two hops work in a browser and are worse
    // for SEO than one, and a slug that changes three times grows a chain a
    // link at a time if nothing repoints it.
    expect(Redirect::where('source', '/a/')->value('target'))->toBe('/c/');
    expect(Redirect::where('source', '/b/')->value('target'))->toBe('/c/');
});

/* ──────────────────────────────── 404 log ──────────────────────────────── */

it('records a genuine storefront 404 so the admin can see what is being hit', function () {
    $this->get('/no-such-page-'.uniqid().'/')->assertNotFound();

    expect(NotFoundLog::query()->count())->toBe(1);
});

it('counts a repeat hit on one row rather than logging it twice', function () {
    $path = '/no-such-page-'.uniqid().'/';

    $this->get($path)->assertNotFound();
    $this->get($path)->assertNotFound();

    expect(NotFoundLog::query()->count())->toBe(1)
        ->and(NotFoundLog::query()->value('hits'))->toBe(2);
});

it('does not log the paths that 404 constantly and legitimately', function () {
    // Logging these would bury the genuinely broken links the log exists for.
    $this->get('/favicon.ico');
    $this->get('/apple-touch-icon.png');
    $this->get('/.well-known/anything');

    expect(NotFoundLog::query()->count())->toBe(0);
});

/* ───────────────────────── the two admin SEO tools ───────────────────────── */

describe('signed in as an admin', function () {
    beforeEach(function () {
        $this->actingAs(AdminUser::create([
            'name' => 'T SEO Admin',
            'email' => 't-seo-admin@example.test',
            'password' => 'password-long-enough',
            'role' => 'owner',
        ]), 'admin');
    });

    it('shows the real JSON-LD a product page would emit', function () {
        $product = Product::create([
            'slug' => 'inspected-'.uniqid(),
            'name' => 'Inspected Serum',
            'status' => 'publish',
            'is_visible' => true,
            'price' => 12345,
            'stock_status' => 'instock',
        ]);

        $body = $this->getJson('/admin-api/schema-inspect?type=product&slug='.$product->slug)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->json();

        // The point of the tool is that it cannot drift from the page: it calls
        // the same Seo::inspect() the storefront renders through.
        expect(json_encode($body))->toContain('Inspected Serum')
            ->toContain('Product');
    });

    it('answers a slug that does not exist with a 404, not a 500', function () {
        $this->getJson('/admin-api/schema-inspect?type=product&slug=nothing-here-'.uniqid())
            ->assertNotFound()
            ->assertJsonPath('ok', false);
    });

    it('rejects a type it does not know rather than guessing', function () {
        $this->getJson('/admin-api/schema-inspect?type=wat')
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    });

    it('reports catalogue SEO gaps as counts an owner can act on', function () {
        Product::create([
            'slug' => 'gap-'.uniqid(),
            'name' => 'No Description No Image',
            'status' => 'publish',
            'is_visible' => true,
            'price' => 10000,
            'stock_status' => 'instock',
            'short_description' => 'Too short.',
        ]);

        $body = $this->getJson('/admin-api/catalogue-audit')->assertOk()->json();

        expect($body)->toHaveKey('counts');
        expect(array_sum((array) $body['counts']))->toBeGreaterThan(0);
    });

    it('keeps both SEO tools behind the admin guard', function () {
        auth('admin')->logout();

        $this->getJson('/admin-api/schema-inspect?type=home')->assertStatus(401);
        $this->getJson('/admin-api/catalogue-audit')->assertStatus(401);
    });
});
