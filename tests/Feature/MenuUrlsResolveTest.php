<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Services\MenuDemo;
use App\Support\LegacyCategoryUrls;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Lane DS — every address the navigation publishes is an address this
 * application serves.
 *
 * ── WHAT WAS BROKEN ─────────────────────────────────────────────────────────
 *
 * The menu rows seeded by 2026_09_09_070000_fix_kbeautybliss_menu_structure
 * carried kbeautybliss.com's own WooCommerce category addresses — flat at the
 * site root: /toners/, /sunscreens/, /cleansing-oils/ and eleven more. This
 * application serves category archives at /product-category/{path}/ (URL
 * Contract U-03) and never served them anywhere else.
 *
 * None of them 404'd in a way anybody could follow. routes/kbb-brands-blog.php
 * ends in `/{slug}/`, the single-segment catch-all that looks up a BLOG POST,
 * and `toners` is not a reserved slug — so all fourteen resolved to
 * PageController@post, which searched the `posts` table for an article called
 * "toners", found none, and 404'd. Fourteen of the shop's most prominent links,
 * the entire Skincare dropdown plus six top-level shortcuts, landing on the
 * not-found page by way of a lookup in the wrong table.
 *
 * The home page's routine strip had the same defect in a different shape: it
 * published /product-category/{slug}/ for six slugs hard-coded in
 * Store\HomeController, and THREE of the six named a category that has never
 * existed — `cleansing` twice and `moisturizers` once, against a seeded
 * taxonomy that spells them `cleansers` and `moisturisers`. Those three steps
 * 404'd AND rendered with no product suggestion, because the same wrong slug
 * drove both the link and the `whereHas` that picks the product. The empty
 * card looked like an empty catalogue, which is why it survived.
 *
 * ── WHAT THIS FILE PINS ─────────────────────────────────────────────────────
 *
 * Not "those fourteen are fixed" — that passes for exactly as long as nobody
 * seeds a fifteenth. Four properties, each read off the data or the router
 * rather than from a list kept here:
 *
 *   1. NO NAVIGATION SOURCE EMITS A LEGACY FLAT CATEGORY URL. Checked against
 *      all three sources that can put one in front of a shopper: the
 *      `menu_items` rows, MenuDemo::tree() (which tops up the mobile drawer
 *      when demo content is on) and what the admin's "load demo menu" button
 *      actually writes.
 *
 *   2. NOTHING THE NAVIGATION EMITS REACHES THE BLOG CATCH-ALL. This is the
 *      class of defect, stated directly: a navigation link that resolves to
 *      PageController@post is a link being answered by a lookup in the posts
 *      table, which is never what a menu item wants. It is asserted on the
 *      ROUTER — the action a path resolves to — so a new flat URL in any shape
 *      fails here even if it is not one of the fourteen.
 *
 *   3. EVERY INTERNAL NAVIGATION URL RESOLVES, with exactly one tolerance,
 *      narrow and reasoned: see menuToleratedDeadEnd().
 *
 *   4. THE HOME PAGE'S ROUTINE STEPS ALL RESOLVE AND ALL CARRY A PRODUCT. Both
 *      halves, because the bug broke both and the second half hid the first.
 *
 * ── TRAPS THIS FILE IS WRITTEN AROUND ───────────────────────────────────────
 *
 * Pest's toContain($needle, $msg) takes a SECOND NEEDLE and toHaveKey($k, $v)
 * an EXPECTED VALUE, not a message, so every message here goes through
 * expect(<bool>)->toBeTrue('…').
 *
 * Links are extracted from rendered HTML with preg_match_all over `<a … href>`,
 * never by searching for a path: the storefront inlines its stylesheets, so a
 * substring search of the page body also matches CSS text.
 *
 * NO NETWORK CALLS.
 */

/* ---------------------------------------------------------------- helpers */

/** Which registered route, if any, a path would reach. */
function menuRouteFor(string $path): ?\Illuminate\Routing\Route
{
    try {
        return Route::getRoutes()->match(Request::create($path, 'GET'));
    } catch (\Throwable) {
        return null;
    }
}

function menuIsInternal(string $href): bool
{
    return $href !== ''
        && ! preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $href)
        && ! str_starts_with($href, '#');
}

/**
 * Is a 404 from this navigation URL one that a database without an imported
 * catalogue explains?
 *
 * THE TOLERANCE, AND WHY IT IS THIS NARROW.
 *
 * Exactly one thing is tolerated: a correctly shaped category archive URL —
 * /product-category/{path}/, reaching CategoryArchiveController — whose
 * category is not in the `categories` table.
 *
 * That is a genuine data gap and not a link defect. The only categories a
 * migrated database has are DemoCatalogueSeeder's six placeholders
 * (`cleansers`, `toners`, `serums`, `moisturisers`, `sunscreens`, `masks`),
 * seeded by 2026_08_27_100000 on every install; the shop's real taxonomy
 * arrives later by WordPress import, carrying the live site's own slugs. A
 * menu item naming `eye-care` is therefore pointing at a category that will
 * exist and does not yet, and App\Support\LegacyCategoryUrls explains why
 * preserving that slug is the correct and self-healing choice rather than
 * remapping it onto a placeholder.
 *
 * WHAT IS DELIBERATELY NOT TOLERATED, AND WHAT CHANGED:
 *
 *   - PageController@post, the single-segment blog catch-all. Lane DR's
 *     chromeToleratedDeadEnd() had to tolerate it, because every one of the
 *     fourteen mega-menu items landed there and that lane could not fix them.
 *     They are fixed, so it is banned here and has been removed there too. A
 *     navigation link may never again be answered by a blog-post lookup.
 *
 *   - ProductController and BrandController. Nothing in the navigation points
 *     at a single product, and the brand pages resolve today, so neither needs
 *     tolerating and neither gets it.
 *
 *   - A path with no route at all, and a route that hard-codes its own row via
 *     ->defaults('slug', …): in both cases the CODE is making the promise.
 */
function menuToleratedDeadEnd(string $path): bool
{
    $route = menuRouteFor($path);

    if ($route === null) {
        return false;
    }

    if ($route->defaults !== [] && array_key_exists('slug', $route->defaults)) {
        return false;
    }

    if (! str_contains($route->getActionName(), 'CategoryArchiveController')) {
        return false;
    }

    // The shape has to be right as well as the controller: the tolerance is
    // for a MISSING CATEGORY, not for a malformed archive path.
    $slug = trim(preg_replace('#\?.*$#', '', $path) ?? '', '/');
    $slug = preg_replace('#^product-category/#', '', $slug) ?? '';

    if ($slug === '') {
        return false;
    }

    $leaf = basename($slug);

    return ! DB::table('categories')->where('slug', $leaf)->exists();
}

/** Every URL any menu_items row holds. */
function menuStoredUrls(): array
{
    return array_values(array_unique(array_filter(
        array_map(
            static fn ($r): string => trim((string) ($r->url ?? '')),
            DB::table('menu_items')->get(['url'])->all(),
        ),
        static fn (string $u): bool => $u !== '',
    )));
}

/**
 * Every URL in a MenuDemo-shaped nested tree, at any depth.
 *
 * LANE EC: the three tests below walk MenuDemo::tree() and every one of them
 * passes against an empty list — `array_filter([], …)` is `[]` and
 * menuBrokenAmong([]) is `[]`, so a tree that emitted nothing would be reported
 * as clean. That was already a latent hole and it stopped being latent when the
 * brand leaves became data-dependent: they are now resolved against the
 * `brands` table per render, so a broken lookup empties most of the tree
 * instead of pointing it somewhere wrong. menuDemoUrls() is the floored
 * entry point the tests use; this stays unfloored because it also walks
 * arbitrary sub-trees.
 */
function menuTreeUrls(array $items): array
{
    $out = [];

    foreach ($items as $item) {
        $url = trim((string) ($item['url'] ?? ''));

        if ($url !== '') {
            $out[] = $url;
        }

        $out = array_merge($out, menuTreeUrls($item['children'] ?? []));
    }

    return array_values(array_unique($out));
}

/**
 * The demo menu's URLs, with the floor that stops a walk over nothing being
 * reported as a walk that found nothing wrong.
 *
 * Twenty-three is the measured count rounded well down — the tree carries
 * thirteen category and collection addresses that do not depend on any table,
 * plus one brand leaf per brand this shop carries.
 */
function menuDemoUrls(): array
{
    $urls = menuTreeUrls(MenuDemo::tree());

    expect(count($urls))->toBeGreaterThan(
        15,
        'MenuDemo::tree() emitted almost no URLs, so every assertion over it below passes '
        . 'without checking anything. The tree has collapsed, not been cleaned.',
    );

    return $urls;
}

/**
 * Walk a set of navigation URLs and report the ones that are genuinely broken.
 *
 * @param  list<string>  $urls
 * @return list<string>
 */
function menuBrokenAmong(array $urls): array
{
    $broken = [];

    foreach ($urls as $url) {
        if (! menuIsInternal($url)) {
            continue;
        }

        $status = test()->get($url)->getStatusCode();

        if ($status < 400) {
            continue;
        }

        if ($status === 404 && menuToleratedDeadEnd($url)) {
            continue;
        }

        $route = menuRouteFor($url);
        $broken[] = $url . ' → ' . $status . ' (' . ($route?->getActionName() ?? 'no route') . ')';
    }

    return $broken;
}

/*
|------------------------------------------------------------------------------
| 1. No navigation source emits a WooCommerce-era flat category URL
|------------------------------------------------------------------------------
*/

it('holds no legacy flat category URL in the menu rows', function () {
    $urls = menuStoredUrls();

    expect(count($urls))->toBeGreaterThan(10, 'The menu table is almost empty; the seed did not run.');

    $legacy = array_values(array_filter($urls, LegacyCategoryUrls::isLegacy(...)));

    expect($legacy)->toBe(
        [],
        'menu_items still points at WooCommerce-era flat category URLs, which the '
        . 'root catch-all answers with a blog-post lookup: ' . implode(', ', $legacy),
    );
});

it('holds no legacy flat category URL in the demo-content menu', function () {
    $legacy = array_values(array_filter(menuDemoUrls(), LegacyCategoryUrls::isLegacy(...)));

    expect($legacy)->toBe(
        [],
        'MenuDemo::tree() still emits flat category URLs. This is not dead code — '
        . 'fill() tops up the MOBILE DRAWER whenever demo content is on: ' . implode(', ', $legacy),
    );
});

it('holds no legacy flat category URL in what the admin seed button writes', function () {
    DB::table('menu_items')->delete();
    DB::table('menus')->delete();

    $admin = AdminUser::create([
        'name' => 'Menu URL probe',
        'email' => 'menu-urls-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);

    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/mega-menu/demo')
        ->assertOk();

    $urls = menuStoredUrls();

    expect(count($urls))->toBeGreaterThan(10, 'The seed endpoint wrote almost nothing; this test is not measuring it.');

    $legacy = array_values(array_filter($urls, LegacyCategoryUrls::isLegacy(...)));

    expect($legacy)->toBe(
        [],
        'MegaMenuApiController::loadDemo() re-seeds flat category URLs, so the '
        . 'defect comes back the moment the owner presses the button: ' . implode(', ', $legacy),
    );
});

/*
|------------------------------------------------------------------------------
| 2. Nothing the navigation emits is answered by a blog-post lookup
|------------------------------------------------------------------------------
*/

it('routes no navigation URL to the blog catch-all', function () {
    $urls = array_unique(array_merge(menuStoredUrls(), menuDemoUrls()));

    $posts = [];

    foreach ($urls as $url) {
        if (! menuIsInternal($url)) {
            continue;
        }

        $action = menuRouteFor($url)?->getActionName() ?? '';

        if (str_contains($action, 'PageController@post')) {
            $posts[] = $url;
        }
    }

    expect($posts)->toBe(
        [],
        'A navigation link resolves to the single-segment blog catch-all, so the '
        . 'application answers it by looking for an ARTICLE with that slug. This is '
        . 'the shape of the original defect, whatever the path happens to be: '
        . implode(', ', $posts),
    );
});

/*
|------------------------------------------------------------------------------
| 3. Every internal navigation URL resolves
|------------------------------------------------------------------------------
*/

it('emits no dead link among the stored menu rows', function () {
    $broken = menuBrokenAmong(menuStoredUrls());

    expect($broken)->toBe([], 'Menu items point at pages that do not exist: ' . implode(', ', $broken));
});

it('emits no dead link in the demo-content menu', function () {
    $broken = menuBrokenAmong(menuDemoUrls());

    expect($broken)->toBe([], 'The demo menu points at pages that do not exist: ' . implode(', ', $broken));
});

/*
|------------------------------------------------------------------------------
| 4. The home page's routine strip
|------------------------------------------------------------------------------
*/

it('publishes six routine steps that all resolve and all carry a product', function () {
    $html = $this->get('/')->assertOk()->getContent();

    // Counted with preg_match_all over the ELEMENTS, not by searching the body
    // for a class name — the page inlines its stylesheet, so `rstep` appears as
    // CSS text as well.
    preg_match_all('#<a class="rstep" href="([^"]*)"(.*?)</a>#si', $html, $steps, PREG_SET_ORDER);

    expect(count($steps))->toBe(6, 'The home page did not render six routine steps; the parse or the strip is wrong.');

    $dead = [];
    $empty = [];

    foreach ($steps as $step) {
        [$_, $href, $body] = $step;

        $status = $this->get($href)->getStatusCode();

        if ($status >= 400) {
            $dead[] = $href . ' → ' . $status;
        }

        // The product suggestion and the link are driven by the SAME resolved
        // slug, so a step with no suggestion is the visible half of a link that
        // is about to be wrong. Asserting both is what stops one masking the
        // other again.
        if (! str_contains($body, 'class="rp"')) {
            $empty[] = $href;
        }
    }

    expect($dead)->toBe([], 'The home page routine links to category archives that 404: ' . implode(', ', $dead));

    expect($empty)->toBe(
        [],
        'A routine step rendered with no product suggestion, which means its slug matches '
        . 'no category — the same wrong slug its link is built from: ' . implode(', ', $empty),
    );
});

/*
|------------------------------------------------------------------------------
| 5. The header and the mobile drawer agree about where a category lives
|------------------------------------------------------------------------------
*/

it('publishes one address per category across the header and the mobile drawer', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect((bool) preg_match('#<header\b.*?</header>#si', $html, $h))
        ->toBeTrue('The page rendered no <header> element at all.');

    expect((bool) preg_match('#<nav[^>]+class="mmenu.*?</nav>#si', $html, $m))
        ->toBeTrue('The page rendered no mobile menu drawer at all.');

    $hrefs = static function (string $region): array {
        preg_match_all('#<a\s[^>]*href\s*=\s*"([^"]*)"#i', $region, $out);

        return array_values(array_unique(array_filter(array_map('trim', $out[1]), fn ($u) => $u !== '')));
    };

    $header = $hrefs($h[0]);
    $drawer = $hrefs($m[0]);

    expect(count($header))->toBeGreaterThan(10, 'The header rendered almost no links; the parse is wrong.');
    expect(count($drawer))->toBeGreaterThan(10, 'The mobile drawer rendered almost no links; the parse is wrong.');

    /*
     * Both chromes render from the same menu, so a category must not appear at
     * two different addresses. Compared by the LAST PATH SEGMENT: the defect
     * this guards against is one chrome keeping /toners/ while the other moves
     * to /product-category/toners/, which is the same category published twice.
     */
    $byLeaf = [];

    foreach (['header' => $header, 'drawer' => $drawer] as $where => $set) {
        foreach ($set as $url) {
            if (! menuIsInternal($url) || str_contains($url, '?')) {
                continue;
            }

            $leaf = basename(trim($url, '/'));

            if ($leaf === '') {
                continue;
            }

            $byLeaf[$leaf][$url][] = $where;
        }
    }

    $split = [];

    foreach ($byLeaf as $leaf => $urls) {
        if (count($urls) > 1) {
            $split[] = $leaf . ' (' . implode(' vs ', array_keys($urls)) . ')';
        }
    }

    expect($split)->toBe(
        [],
        'The header and the mobile drawer publish the same category at two different '
        . 'addresses: ' . implode(', ', $split),
    );
});

/*
|------------------------------------------------------------------------------
| 6. The repair migration is safe to run again
|------------------------------------------------------------------------------
*/

/** The repointing migration, as a runnable object. */
function menuRepointMigration(): object
{
    return require database_path('migrations/2026_11_07_000000_repoint_menu_category_urls.php');
}

it('changes nothing when the repointing migration runs a second time', function () {
    // It has already run once, as part of the migration set this test booted on.
    $before = DB::table('menu_items')->orderBy('id')->pluck('url', 'id')->all();

    menuRepointMigration()->up();

    $after = DB::table('menu_items')->orderBy('id')->pluck('url', 'id')->all();

    expect($after)->toBe(
        $before,
        'Re-running the repointing migration changed menu rows, so applying the package '
        . 'twice is not a no-op.',
    );
});

it('leaves a URL the owner has edited by hand alone', function () {
    /*
     * The migration recognises rows by their exact seeded URL, and that is the
     * whole mechanism protecting a hand edit: a row the owner has repointed in
     * Mega Menu no longer holds the seeded value, so it is not matched.
     *
     * Pinned against BOTH shapes of edit an owner can plausibly make — moving
     * an item somewhere else entirely, and leaving it on the old flat address
     * on purpose is NOT one of them, so a row still holding the legacy URL is
     * expected to be rewritten. The first row here is the real test; the second
     * exists so the assertion cannot pass by the migration simply doing nothing.
     */
    $hand = DB::table('menu_items')->orderBy('id')->first();

    DB::table('menu_items')->where('id', $hand->id)->update(['url' => '/shop/?orderby=popularity']);

    $stale = DB::table('menu_items')->insertGetId([
        'menu_id' => $hand->menu_id,
        'parent_id' => null,
        'position' => 99,
        'label' => 'Toners (not yet repaired)',
        'url' => '/toners/',
    ]);

    menuRepointMigration()->up();

    expect(DB::table('menu_items')->where('id', $hand->id)->value('url'))
        ->toBe('/shop/?orderby=popularity', 'The migration overwrote a URL the owner had edited by hand.');

    expect(DB::table('menu_items')->where('id', $stale)->value('url'))
        ->toBe('/product-category/toners/', 'The migration failed to repoint a row still holding the seeded URL.');
});
