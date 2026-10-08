<?php

declare(strict_types=1);

/**
 * The desktop menu marks the page you are on. (Lane MN, 2.60.441)
 *
 * THE DEFECT, AS THE OWNER SAW IT: "when i go to any page/category or brand etc
 * from the top menu in desktop, it's not highlighting etc as open or current
 * page." Nothing marked it. partials/nav-bar.blade.php printed every link the
 * same on every page — no aria-current, no class — and kbb.css had no rule to
 * paint one. Opening "Skincare" left the bar looking exactly as it did on the
 * home page.
 *
 * Asserted on the rendered pages, not on NavCurrent alone, because the rules
 * depend on what the controllers hand the layout (the category's parents, the
 * product's categories) and on the locale middleware having already turned
 * /ar/... back into the canonical path.
 *
 * MUTATIONS, each run and put back:
 *   - drop the `{!! NavCurrent::attr(...) !!}` from the top-level link in
 *     nav-bar.blade.php: every "marks ..." case below is red;
 *   - make score() ignore a link's own query string: the query-string case
 *     is red -- Shop and New In then tie on /shop/?orderby=date and Shop,
 *     first in the menu, takes it, so New In can never be marked;
 *   - stop categoryTrail() walking the loaded parent rows: the short-address
 *     case is red (/collections/nc-essences/ marks nothing);
 *   - follow `$node->parent` without the relationLoaded() check in
 *     categoryTrail(): "costs no query" is red on the product page;
 *   - delete the `:has(...)` neighbour rule from kbb.css: "one indicator" is red.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Services\HeaderSettings;
use App\Services\SettingsService;
use App\Support\NavCurrent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function ncSeed(): void
{
    app(SettingsService::class)->set('language_ar_enabled', true);

    $skin = Category::create(['name' => 'Skincare', 'slug' => 'nc-skincare', 'path' => 'nc-skincare', 'depth' => 0, 'position' => 0]);
    $serums = Category::create(['name' => 'Serums', 'slug' => 'nc-serums', 'parent_id' => $skin->id, 'path' => 'nc-skincare/nc-serums', 'depth' => 1, 'position' => 0]);
    $vc = Category::create(['name' => 'Vitamin C', 'slug' => 'nc-vitamin-c', 'parent_id' => $serums->id, 'path' => 'nc-skincare/nc-serums/nc-vitamin-c', 'depth' => 2, 'position' => 0]);
    // A short-address child: its path is its own slug, not a prefix of the
    // parent's, so only the parent row the controller loaded can place it.
    Category::create(['name' => 'Toners', 'slug' => 'nc-toners', 'parent_id' => $skin->id, 'path' => 'nc-toners', 'short_url' => true, 'depth' => 1, 'position' => 1]);
    // And one the menu does not list at all, so nothing but that parent row
    // can say it belongs under Skincare.
    Category::create(['name' => 'Essences', 'slug' => 'nc-essences', 'parent_id' => $skin->id, 'path' => 'nc-essences', 'short_url' => true, 'depth' => 1, 'position' => 3]);
    Category::create(['name' => 'Masks', 'slug' => 'nc-masks', 'path' => 'nc-masks', 'depth' => 0, 'position' => 2]);

    $cosrx = Brand::updateOrCreate(['slug' => 'nc-cosrx'], ['name' => 'NC COSRX']);
    Brand::updateOrCreate(['slug' => 'nc-anua'], ['name' => 'NC Anua']);

    $product = Product::create([
        'slug' => 'nc-serum', 'name' => 'NC Vitamin C Serum', 'sku' => 'NC-1',
        'brand_id' => $cosrx->id, 'category_id' => $vc->id, 'type' => 'simple',
        'status' => 'publish', 'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock',
    ]);
    $product->categories()->sync([$skin->id, $serums->id, $vc->id]);

    Post::create(['slug' => 'nc-routine', 'title' => 'A morning routine', 'body' => '<p>Body.</p>', 'excerpt' => 'Ex.', 'status' => 'published', 'published_at' => now()->subDay()]);
    Page::firstOrCreate(['slug' => 'delivery'], ['title' => 'Delivery', 'content' => '<p>Placeholder.</p>', 'status' => 'published']);

    // The one desktop menu: whatever a migration seeded steps aside.
    Menu::query()->update(['show_desktop' => false, 'show_mobile' => false]);
    $menu = Menu::create(['name' => 'Main', 'slug' => 'nc-main', 'show_desktop' => true, 'show_mobile' => true, 'show_footer' => false]);
    $pos = 0;
    $add = function (string $label, string $url, ?int $parent = null) use ($menu, &$pos): MenuItem {
        return MenuItem::create(['menu_id' => $menu->id, 'parent_id' => $parent, 'label' => $label, 'url' => $url,
            'target_type' => 'custom', 'visibility' => 'always', 'new_tab' => false, 'position' => $pos++]);
    };

    $add('Home', '/');
    $add('Shop', '/shop/');
    $add('New In', '/shop/?orderby=date');
    $sk = $add('Skincare', '/collections/nc-skincare/');
    $add('Serums', '/collections/nc-skincare/nc-serums/', $sk->id);
    $add('Toners', '/collections/nc-toners/', $sk->id);
    $add('Masks', '/collections/nc-masks/');
    $br = $add('Brands', '/brands/');
    $add('COSRX', '/brands/nc-cosrx/', $br->id);
    $add('Anua', '/brands/nc-anua/', $br->id);
    $add('Blog', '/blog/');
    $add('Delivery', '/delivery/');

    Cache::flush();
}

/** [label => aria-current] for the TOP-LEVEL links that carry one. */
function ncTop(string $html): array
{
    preg_match_all('#<a class="navlink" href="[^"]*"[^>]*?aria-current="(page|true)">\s*([^<\s][^<]*?)\s*(?:<|$)#', $html, $m, PREG_SET_ORDER);

    return array_map(fn ($x) => [trim($x[2]) => $x[1]], $m);
}

/** [href => aria-current] for the PANEL links that carry one. */
function ncPanel(string $html): array
{
    preg_match_all('#<a (?:class="mcol-link" )?href="([^"]*)"[^>]*?aria-current="(page|true)">#', $html, $m, PREG_SET_ORDER);

    $out = [];
    foreach ($m as $x) {
        $out[$x[1]] = $x[2];
    }

    return $out;
}

function ncPage(string $path): string
{
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    return (string) test()->get($path)->assertOk()->getContent();
}

beforeEach(function () {
    ncSeed();
});

it('marks the item that IS the page, as aria-current="page"', function () {
    expect(ncTop(ncPage('/delivery/')))->toBe([['Delivery' => 'page']])
        ->and(ncTop(ncPage('/')))->toBe([['Home' => 'page']])
        ->and(ncTop(ncPage('/blog/')))->toBe([['Blog' => 'page']]);
});

it('marks a category above the category page, and the panel link that IS the page', function () {
    $html = ncPage('/collections/nc-skincare/nc-serums/');

    expect(ncTop($html))->toBe([['Skincare' => 'true']])
        ->and(ncPanel($html))->toBe(['/collections/nc-skincare/nc-serums/' => 'page']);
});

it('marks a deeper descendant through its nearest ancestor in the panel', function () {
    $html = ncPage('/collections/nc-skincare/nc-serums/nc-vitamin-c/');

    expect(ncTop($html))->toBe([['Skincare' => 'true']])
        ->and(ncPanel($html))->toBe(['/collections/nc-skincare/nc-serums/' => 'true']);
});

it('places a short-address child under its parent from the row the controller loaded', function () {
    // /collections/nc-toners/ is not under /collections/nc-skincare/ by path at all.
    $html = ncPage('/collections/nc-toners/');

    expect(ncTop($html))->toBe([['Skincare' => 'true']])
        ->and(ncPanel($html))->toBe(['/collections/nc-toners/' => 'page']);

    $html = ncPage('/collections/nc-essences/');

    expect(ncTop($html))->toBe([['Skincare' => 'true']])
        ->and(ncPanel($html))->toBe([]);
});

it("marks the item a product's most specific category sits under", function () {
    $html = ncPage('/product/nc-serum/');

    expect(ncTop($html))->toBe([['Skincare' => 'true']])
        ->and(ncPanel($html))->toBe(['/collections/nc-skincare/nc-serums/' => 'true']);
});

it('marks Brands on a brand page, and the brand in its panel', function () {
    $html = ncPage('/brands/nc-cosrx/');

    expect(ncTop($html))->toBe([['Brands' => 'true']])
        ->and(ncPanel($html))->toBe(['/brands/nc-cosrx/' => 'page']);
});

it('marks Blog on an article', function () {
    expect(ncTop(ncPage('/blog/nc-routine/')))->toBe([['Blog' => 'true']]);
});

it('maps /ar/ onto the same item, with the Arabic href', function () {
    $html = ncPage('/ar/collections/nc-skincare/nc-serums/');

    expect(ncTop($html))->toBe([['Skincare' => 'true']])
        ->and($html)->toContain('<a class="navlink" href="/ar/collections/nc-skincare/"')
        ->and(ncPanel($html))->toBe(['/ar/collections/nc-skincare/nc-serums/' => 'page']);
});

it("ignores the page's query string but honours a link's own", function () {
    // /shop/ lights Shop, not New In (/shop/?orderby=date) -- the two share a path.
    expect(ncTop(ncPage('/shop/')))->toBe([['Shop' => 'page']])
        ->and(ncTop(ncPage('/shop/?orderby=date')))->toBe([['New In' => 'page']])
        ->and(ncTop(ncPage('/shop/?orderby=price&pg=2')))->toBe([['Shop' => 'page']])
        ->and(ncTop(ncPage('/collections/nc-masks/?orderby=price')))->toBe([['Masks' => 'page']]);
});

it('marks exactly one top-level item on every page type', function () {
    foreach (['/', '/shop/', '/shop/?orderby=date', '/collections/nc-skincare/nc-serums/', '/collections/nc-toners/',
        '/product/nc-serum/', '/brands/nc-cosrx/', '/brands/', '/blog/', '/blog/nc-routine/', '/delivery/',
        '/ar/collections/nc-skincare/nc-serums/', '/ar/product/nc-serum/'] as $path) {
        $n = preg_match_all('#<a class="navlink"[^>]*aria-current=#', ncPage($path));
        expect($n)->toBe(1, $path);
    }
});

it('changes nothing but the attribute, and nothing at all when switched off', function () {
    $mask = fn (string $h) => preg_replace('#(name="_token" value|csrf-token" content|"csrf":|nonce=)"[^"]*"#', '$1""', $h);

    $on = $mask(ncPage('/brands/nc-cosrx/'));
    app(HeaderSettings::class)->save(['nav_current' => false]);
    $off = $mask(ncPage('/brands/nc-cosrx/'));

    // On <a> only: the brand page's own breadcrumb is a <span aria-current>.
    $links = '#(<a [^>]*?) aria-current="(?:page|true)">#';

    expect(preg_match_all($links, $on))->toBe(2)
        ->and(preg_match_all($links, $off))->toBe(0)
        // The only difference is the two attributes: no newline eaten, no
        // class, no style, the phone menu and window.KBB untouched.
        ->and(preg_replace($links, '$1>', $on))->toBe($off);
});

it('costs no query on the product, category and brand pages', function () {
    $count = function (string $path): int {
        ncPage($path); // warm the nav and settings caches
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        ncPage($path);
        DB::getEventDispatcher()?->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return $n;
    };

    $paths = ['/product/nc-serum/', '/collections/nc-skincare/nc-serums/nc-vitamin-c/', '/brands/nc-cosrx/'];
    $on = array_map($count, $paths);
    app(HeaderSettings::class)->save(['nav_current' => false]);
    $off = array_map($count, $paths);

    expect($on)->toBe($off);
});

/*
|------------------------------------------------------------------------------
| The matcher on its own
|------------------------------------------------------------------------------
*/

function ncHere(string $path, array $trail = [], array $query = []): array
{
    return ['path' => $path, 'query' => $query, 'host' => 'shop.test', 'trail' => $trail];
}

it('prefers an exact match to an ancestor match', function () {
    $nav = [['label' => 'A', 'url' => '/collections/skincare/'], ['label' => 'B', 'url' => '/collections/skincare/serums/']];
    $out = NavCurrent::mark($nav, ncHere('/collections/skincare/serums', ['/collections/skincare']));

    expect($out[0]['current'] ?? null)->toBeNull()->and($out[1]['current'])->toBe('page');
});

it("prefers an item's own link to the same address inside another panel", function () {
    $nav = [
        ['label' => 'Shop', 'url' => '/shop/', 'children' => [['label' => 'All brands', 'url' => '/brands/']]],
        ['label' => 'Brands', 'url' => '/brands/'],
    ];
    $out = NavCurrent::mark($nav, ncHere('/brands'));

    expect($out[0]['current'] ?? null)->toBeNull()
        ->and($out[0]['children'][0]['current'] ?? null)->toBeNull()
        ->and($out[1]['current'])->toBe('page');
});

it('normalises slashes, case, legacy prefixes and its own host; ignores other hosts and #', function () {
    $mark = fn (string $url, string $path) => NavCurrent::mark([['url' => $url]], ncHere($path))[0]['current'] ?? null;

    expect($mark('/Product-Category/Skincare', '/collections/skincare'))->toBe('page')
        ->and($mark('/korean-skincare-brands/', '/brands/cosrx'))->toBe('true')
        ->and($mark('https://shop.test/blog/', '/blog/x'))->toBe('true')
        ->and($mark('https://elsewhere.test/blog/', '/blog/x'))->toBeNull()
        ->and($mark('javascript:alert(1)', '/blog'))->toBeNull()
        ->and($mark('#', '/'))->toBeNull()
        // Home is home only: '/' is not "above" every page. (It was: path('/')
        // came back '' and '' is a prefix of everything -- caught here.)
        ->and($mark('/', '/shop'))->toBeNull()
        // A segment boundary, not a string prefix.
        ->and($mark('/collections/skin/', '/collections/skincare'))->toBeNull();
});

it('returns the tree untouched when nothing matches', function () {
    $nav = [['label' => 'A', 'url' => '/a/', 'children' => [['url' => '/b/']]]];

    expect(NavCurrent::mark($nav, ncHere('/zzz')))->toBe($nav);
});

it('prints only its own constants, whatever the tree or the settings say', function () {
    expect(NavCurrent::attr(['current' => 'page']))->toBe(' aria-current="page"')
        ->and(NavCurrent::attr(['current' => 'true']))->toBe(' aria-current="true"')
        ->and(NavCurrent::attr(['current' => '"><script>']))->toBe('')
        ->and(NavCurrent::attr([]))->toBe('')
        ->and(NavCurrent::barClass(['nav_current' => true, 'nav_current_style' => 'dot']))->toBe(' nc-dot')
        ->and(NavCurrent::barClass(['nav_current' => true, 'nav_current_style' => 'line']))->toBe('')
        ->and(NavCurrent::barClass(['nav_current' => true, 'nav_current_style' => 'x" onmouseover="']))->toBe('')
        ->and(NavCurrent::barClass(['nav_current' => false, 'nav_current_style' => 'dot']))->toBe('')
        ->and(NavCurrent::barStyle(['nav_current' => true, 'nav_current_colour' => '#C13E63']))->toBeNull()
        ->and(NavCurrent::barStyle(['nav_current' => true, 'nav_current_colour' => '#1F7A50']))->toBe('--nav-cur:#1F7A50')
        ->and(NavCurrent::barStyle(['nav_current' => true, 'nav_current_colour' => 'red;}body{x']))->toBeNull()
        ->and(NavCurrent::barStyle(['nav_current' => false, 'nav_current_colour' => '#1F7A50']))->toBeNull();
});

/*
|------------------------------------------------------------------------------
| The control, the wiring and the stylesheet
|------------------------------------------------------------------------------
*/

it('ships ON in Appearance → Header → Navigation, with its style and colour', function () {
    $all = app(HeaderSettings::class)->all();

    expect($all['nav_current'])->toBeTrue()
        ->and($all['nav_current_style'])->toBe('line')
        ->and($all['nav_current_colour'])->toBe(NavCurrent::DEFAULT_COLOUR)
        ->and(HeaderSettings::TABS['nav'][2])->toContain('nav_current', 'nav_current_style', 'nav_current_colour')
        ->and(array_keys(HeaderSettings::SCHEMA['nav_current_style'][4]))->toBe(['line', 'dot', 'text']);
});

it('is wired once into the desktop bar and nowhere into the phone menu', function () {
    $bar = file_get_contents(resource_path('views/partials/nav-bar.blade.php'));

    expect(substr_count($bar, 'NavCurrent::mark('))->toBe(1)
        ->and(substr_count($bar, 'NavCurrent::attr('))->toBe(4)
        ->and(file_get_contents(resource_path('views/partials/mobile-chrome.blade.php')))->not->toContain('NavCurrent')
        ->and(file_get_contents(resource_path('views/partials/mobile-menu-item.blade.php')))->not->toContain('NavCurrent');
});

/**
 * Class-level specificity of a selector: classes, attributes and pseudo-classes,
 * with :not()/:is()/:has() counted by their arguments. Rough (it counts every
 * argument of :is() rather than the largest), which only ever over-counts the
 * pointer's own retract rules — the comparison below still holds.
 */
function ncSpec(string $selector): int
{
    $selector = preg_replace('#::?(after|before)\b#', '', $selector);
    $selector = preg_replace('#:(not|is|has|where)\(#', '(', $selector);

    return preg_match_all('#\.[\w-]+|\[[^\]]+\]|:[\w-]+#', $selector);
}

it('paints the current item with rules the page stylesheets cannot beat', function () {
    $css = file_get_contents(resource_path('css/kbb/kbb.css'));
    $rules = [
        'colour' => '.mbar .navlink[aria-current]{',
        'hold' => '.mbar:not(.nc-text) .navlink[aria-current]:not([style])::after{transform:scaleX(1)',
        'neighbour' => '.mbar .wrap:has(> .navitem:hover) > .navitem:not(:hover) > .navlink[aria-current]::after{transform:scaleX(0)}',
        'panel' => '.mbar .drop a[aria-current]{',
    ];

    foreach ($rules as $name => $rule) {
        expect(substr_count($css, $rule))->toBe(1, $name);
    }

    // Every rule is at least (0,3,0); the sheets that load after kbb.css carry
    // `a{color:inherit}` (0,0,1) and must never name the bar at all.
    foreach (glob(resource_path('css/kbb/*.css')) as $sheet) {
        if (basename($sheet) === 'kbb.css') {
            continue;
        }
        expect(preg_match('#navlink|\.mbar\b|\.navitem\b|aria-current#', (string) file_get_contents($sheet)))->toBe(0, basename($sheet));
    }

    // One indicator: the open panel's pointer retracts the held line on the
    // current item, and hovering ANY other item retracts it as well.
    $hold = ncSpec('.mbar:not(.nc-text) .navlink[aria-current]:not([style])::after');
    expect(ncSpec('.mbar .navlink[aria-current]'))->toBeGreaterThanOrEqual(3)
        ->and(ncSpec('.mbar .navitem.mg-a.mg-p:hover > .navlink::after'))->toBeGreaterThan($hold)
        ->and(substr_count($css, '.mbar .navitem:is(.mg-l,.mg-s).mg-p:hover > .navlink::after{transform:scaleX(0)}'))->toBe(1)
        ->and(substr_count($css, '.mbar .navitem.mg-a.mg-p:hover > .navlink::after{transform:scaleX(0)}'))->toBe(1)
        ->and(ncSpec('.mbar .wrap:has(> .navitem:hover) > .navitem:not(:hover) > .navlink[aria-current]::after'))->toBeGreaterThan($hold);
});

it('ships the rules in the built stylesheet the shop actually loads', function () {
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $built = (string) file_get_contents(public_path('build/' . $manifest['resources/css/kbb/kbb.css']['file']));

    expect($built)->toContain('.mbar .navlink[aria-current]{')
        ->and($built)->toContain('.mbar .wrap:has(>.navitem:hover)>.navitem:not(:hover)>.navlink[aria-current]:after{transform:scaleX(0)}');
});
