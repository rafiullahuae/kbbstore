<?php

declare(strict_types=1);

use App\Http\Controllers\Store\PageController;
use App\Models\Post;
use Illuminate\Support\Facades\Route;
use Tests\Support\Phase9Routes;

/**
 * The hazard this whole change turns on.
 *
 * Serving an article from /{slug}/ means registering a route that matches any
 * single path segment — the same shape as /cart, /checkout and /shop. If it
 * ever wins, the storefront's own pages become 404s (there is no post by that
 * name) or, worse, an article that happens to be slugged "checkout".
 *
 * Two independent guards, so one of them failing is not an outage:
 *
 *   1. ORDER — the route is registered last, from the end of web.php.
 *   2. PATTERN — PageController::slugPattern() refuses the reserved segments
 *      outright, so the route cannot match /cart even when ordering is wrong.
 *
 * Guard 1 is tested by asking the real router which action serves each path.
 * Guard 2 is tested against the pattern directly, with the routes NOT wired,
 * so it is genuinely the pattern doing the work rather than order quietly
 * covering for it.
 */

/** Paths a root-level article slug could plausibly swallow. */
dataset('reserved paths', [
    ['/cart', 'CartController'],
    ['/checkout', 'CheckoutController'],
    ['/shop', 'ShopController'],
    ['/my-wishlist', 'WishlistController'],
    ['/new-in', 'CollectionController'],
    ['/best-sellers', 'CollectionController'],
    ['/skin-quiz', 'PageController'],
    ['/about', 'PageController@show'],
    ['/skincare-guide', 'PageController@blog'],
    ['/korean-skincare-brands', 'BrandController@index'],
    ['/brands', 'BrandController@legacyIndex'],
]);

it('leaves storefront routes with their own controllers', function (string $path, string $expected) {
    Phase9Routes::wire($this->app);

    expect(Phase9Routes::actionFor($path))->toContain($expected);
})->with('reserved paths');

it('still leaves them alone when a post is slugged to impersonate one', function () {
    // The nastiest version of the bug: real content whose slug collides.
    foreach (['cart', 'checkout', 'shop'] as $slug) {
        Post::create([
            'slug' => $slug,
            'title' => 'Impostor ' . $slug,
            'body' => '<p>This must never be served at /' . $slug . '.</p>',
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    Phase9Routes::wire($this->app);

    expect(Phase9Routes::actionFor('/cart'))->toContain('CartController')
        ->and(Phase9Routes::actionFor('/checkout'))->toContain('CheckoutController')
        ->and(Phase9Routes::actionFor('/shop'))->toContain('ShopController');

    // And end to end: the cart page is the cart, not the article.
    $this->get('/cart')->assertOk()->assertDontSee('Impostor cart');
});

it('serves /cart and /shop as real pages, not 404s', function () {
    Phase9Routes::wire($this->app);

    $this->get('/cart')->assertOk();
    $this->get('/shop')->assertOk();

    // /checkout legitimately redirects to the cart when the cart is empty, so
    // assert it reached CheckoutController rather than asserting a status.
    $this->get('/checkout')->assertStatus(302);
    expect(Phase9Routes::actionFor('/checkout'))->toContain('CheckoutController@page');
});

it('refuses every reserved segment in the slug pattern itself', function () {
    // Routes deliberately not wired: this is the pattern alone, with ordering
    // taken away.
    $regex = '#^(?:' . PageController::slugPattern() . ')$#D';

    foreach (PageController::RESERVED_SLUGS as $reserved) {
        expect(preg_match($regex, $reserved))
            ->toBe(0, "slugPattern() must not match the reserved segment '{$reserved}'");
    }
});

/**
 * The guard that keeps the guard honest.
 *
 * RESERVED_SLUGS is a hand-written list, and a hand-written list of everything
 * the router serves goes stale the first time someone adds a route. Rather
 * than trusting that it was updated, walk the routes the application actually
 * registered and check every static first segment against the pattern. Adding
 * a route to web.php without adding it here is then a red suite, not a page
 * that silently disappears behind an article.
 */
it('refuses the first segment of every route the application registers', function () {
    Phase9Routes::wire($this->app);

    $regex = '#^(?:' . PageController::slugPattern() . ')$#D';
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        $uri = trim($route->uri(), '/');
        $segment = explode('/', $uri)[0];

        // Skip the root, and skip parameter segments — {slug} is this very
        // route, and a parameter is not a fixed address to protect.
        if ($segment === '' || str_contains($segment, '{')) {
            continue;
        }

        $checked++;

        expect(preg_match($regex, $segment))->toBe(
            0,
            "The route '/{$uri}' starts with '{$segment}', which the root-level "
            . 'post slug route would swallow. Add it to '
            . 'PageController::RESERVED_SLUGS.'
        );
    }

    // Guard against the loop silently checking nothing.
    expect($checked)->toBeGreaterThan(20);
});

it('refuses the configured admin path even though it is not a constant', function () {
    // The admin path is settable (KBB_ADMIN_PATH, or the admin_path setting),
    // so a hard-coded list would go stale the moment anyone moved the admin.
    $adminPath = trim(\App\Services\AdminPathService::current(), '/');
    $regex = '#^(?:' . PageController::slugPattern() . ')$#D';

    expect($adminPath)->not->toBe('')
        ->and(preg_match($regex, $adminPath))->toBe(0);
});

it('refuses file-shaped, underscore and uppercase paths', function () {
    $regex = '#^(?:' . PageController::slugPattern() . ')$#D';

    foreach ([
        'robots.txt', 'sitemap.xml', 'llms.txt', 'index.php', 'favicon.ico',
        'refund_returns', '_kbb-health', '_design-check',
        'Cart', 'a--b', '-lead', 'trail-',
    ] as $path) {
        expect(preg_match($regex, $path))->toBe(0, "slugPattern() must not match '{$path}'");
    }
});

it('still matches the real live post slugs', function () {
    $regex = '#^(?:' . PageController::slugPattern() . ')$#D';

    foreach ([
        'heartleaf-extract-transforming-k-beauty-skincare',
        'k-beauty-bliss-10-best-korean-moisturizers-for-sensitive-skin',
        'k-beauty-face-masks-the-ultimate-guide-to-relaxing-and-rejuvenating-at-home',
        'k-beauty-bliss-a-beginners-guide-to-korean-skincare',
        'k-beauty-bliss-how-to-repair-a-damaged-skin-barrier-with-k-beauty',
    ] as $slug) {
        expect(preg_match($regex, $slug))->toBe(1, "slugPattern() must match the live slug '{$slug}'");
    }
});
