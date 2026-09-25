<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildMyRoutineRoutes;

/**
 * =============================================================================
 * WHAT A COMPONENT NEEDS LOADED, SAID OUT LOUD
 * =============================================================================
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────
 *
 * resources/views/components/product-grid.blade.php reads `$p->brand` and
 * `$p->categories->first()` for every tile. Neither is loaded by the component;
 * both have to arrive already loaded on the models the caller hands it. Nothing
 * says so. The component does not declare it, the caller cannot see it, and a
 * caller that gets it wrong produces no error, no warning and no wrong pixel —
 * only one `category_product` read and one `brands` read PER TILE.
 *
 * Measured on the brand landing page, the same page at 1, 2, 5 and 10 tiles,
 * each tile carrying its own brand and its own category:
 *
 *   eager loads as shipped      5   5   5   5     slope 0
 *   without `categories:id,name`  5   6   9  14   slope 1.0
 *   without either              5   7  13  23     slope 2.0
 *
 * Today every caller happens to get it right, so every page is flat and no test
 * anywhere would notice if one stopped. That is the whole problem: the shop is
 * correct by coincidence, and the coincidence is somebody's memory.
 *
 * ── WHY THIS SHAPE AND NOT ONE OF THE OTHER THREE ───────────────────────────
 *
 * A COMPONENT THAT LOADS WHAT IT NEEDS (`loadMissing`) fixes the grid and makes
 * the CARD worse. <x-product-card> is handed ONE model, so `loadMissing` inside
 * it is one query per card — the N+1 it was meant to remove, now written into
 * the component where no caller can eager-load it away.
 *
 * A SCANNER THAT WALKS EVERY CALLER AND CHECKS ITS EAGER LOAD cannot be
 * written honestly. The query behind `<x-product-card :product="$step['chosen']" />`
 * is in App\Services\BuildMyRoutine; behind `<x-product-grid :products="$products" />`
 * on a CMS page it is inside a Cache::remember() closure in App\Support\Shortcodes.
 * Statically pairing a Blade variable with the query that filled it is guesswork,
 * and a guard that guesses gets silenced.
 *
 * Model::preventLazyLoading() GLOBALLY is the thorough answer and it is priced
 * rather than asserted: the whole suite was run once with it on and every
 * violation recorded. THE COUNT IS IN docs/q10-component-load-contract.md.
 * What that run showed is why this file enables it for NINE RENDERS instead of
 * for the whole suite — on those nine, the noise is zero.
 *
 * So: the requirement is DECLARED beside the component (see the docblock at the
 * top of each template named in CLC_CONTRACTS, and the map below), the
 * declaration is checked against what the template actually reads, every call
 * site has to be named, and each named page is rendered with lazy loading
 * forbidden.
 *
 * ── THE ONE THING THIS MECHANISM CANNOT SEE, STATED UP FRONT ────────────────
 *
 * Illuminate\Database\Eloquent\Builder::hydrate() copies
 * Model::preventsLazyLoading() onto each row it builds, and ONLY when the
 * result has more than one row:
 *
 *     if (count($items) > 1) { $model->preventsLazyLoading = ...; }
 *
 * A page that renders ONE tile therefore reports nothing, however badly its
 * query is written — measured below, and recorded in the doc. Every fixture in
 * this file renders at least three of the thing it is about, and every case
 * asserts that count before it asserts anything else. A guard whose fixture
 * renders one tile is a guard that cannot fail.
 *
 * ── MUTATIONS, ALL RUN. See docs/q10-component-load-contract.md for the two
 *    that came back GREEN and what they exposed. ───────────────────────────
 */

/**
 * Templates that are handed a model and read a relation off it, with the
 * relations each one reads.
 *
 * This is the CONTRACT. `clc_contracts_match_the_templates` below re-derives
 * each list from the template's own source, so an entry cannot drift from what
 * the file does; `clc_every_relation_reading_component_is_contracted` re-derives
 * the LIST OF TEMPLATES the same way, so a fourth one cannot be added without
 * appearing here.
 */
const CLC_CONTRACTS = [
    'resources/views/components/product-grid.blade.php' => ['brand', 'categories'],
    'resources/views/components/product-card.blade.php' => ['brand'],
    /*
     * NOT under components/, and included here anyway. It is a component in
     * every sense that matters to this file — handed a collection of models,
     * reads a relation off each one, used by three unrelated pages — and
     * leaving it out would mean this guard's boundary was a directory name
     * rather than the defect. Not edited by this lane: `partials/home/` is
     * another lane's ground, so its contract is declared here only.
     */
    'resources/views/partials/home/grid.blade.php' => ['brand'],
];

/**
 * How each contracted template is invoked, and every file allowed to invoke it.
 *
 * The needles are what a caller writes. `<x-product-grid` is the Blade tag;
 * `'components.product-grid'` is the same template rendered as a view, which
 * App\Support\Shortcodes does for `[kbb_products]` — and which is why the
 * previous round's grep for the tag alone concluded this component had a single
 * caller. It has two.
 *
 * The value is the page this file renders to cover that call site. A call site
 * with nowhere to be covered is a call site nobody is checking.
 */
const CLC_INVOCATIONS = [
    'resources/views/components/product-grid.blade.php' => ['<x-product-grid', "'components.product-grid'"],
    'resources/views/components/product-card.blade.php' => ['<x-product-card', "'components.product-card'"],
    'resources/views/partials/home/grid.blade.php' => ["'partials.home.grid'"],
];

const CLC_COVERED = [
    'resources/views/store/brands.blade.php' => 'the brand landing page, /korean-skincare-brands/{slug}/',
    'app/Support/Shortcodes.php' => 'a CMS page carrying [kbb_products]',
    'resources/views/store/shop.blade.php' => '/shop',
    'resources/views/store/product.blade.php' => 'the related rail on a product page',
    'resources/views/store/routines.blade.php' => '/routines/{concern}',
    'resources/views/store/collection.blade.php' => '/concern/{concern}/',
    'resources/views/store/wishlist.blade.php' => '/my-wishlist',
    'resources/views/store/home.blade.php' => 'the homepage, rendered cold',
];

/* ══════════════════════════ reading the source ═══════════════════════════ */

/** $source with every PHP comment blanked out, line numbers intact. */
function clcPhpCode(string $source): string
{
    $out = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $out .= str_repeat("\n", substr_count($token[1], "\n"));

            continue;
        }

        $out .= is_array($token) ? $token[1] : $token;
    }

    return $out;
}

/**
 * The same for a Blade template, and it matters more here than anywhere.
 *
 * Four files in this repository write `<x-product-card>` or `<x-product-grid>`
 * inside a comment to point at one — store/home.blade.php, store/routines.blade.php
 * and both components' own usage docblocks. A scanner that reads prose reports
 * four callers that do not exist and gets an allowlist entry each, which is how
 * an allowlist stops meaning anything.
 *
 * `{{-- --}}` and `/* *\/` are blanked wholesale. A `//` line is blanked only
 * when the line STARTS with it: `//` also appears inside `https://` in a real
 * attribute, and eating to end of line from there would delete markup.
 */
function clcBladeCode(string $source): string
{
    $blank = static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n"));

    $source = (string) preg_replace_callback('/\{\{--.*?--\}\}/s', $blank, $source);
    $source = (string) preg_replace_callback('#/\*.*?\*/#s', $blank, $source);

    return (string) preg_replace('/^\s*\/\/.*$/m', '', $source);
}

function clcCode(string $path): string
{
    $source = (string) file_get_contents($path);

    return str_ends_with($path, '.blade.php') ? clcBladeCode($source) : clcPhpCode($source);
}

/** Every file a call site could hide in, relative to the project root. */
function clcSourceFiles(): array
{
    $out = [];

    foreach (['app', 'resources/views'] as $dir) {
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir))) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $out[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }
    }

    sort($out);

    return $out;
}

/**
 * Every relation name declared on any model, derived from the models themselves.
 *
 * DERIVED AND NOT LISTED, for the reason VariantPriceMemoRawWriteGuardTest gives
 * for checking MEMO_TABLES against the real statement: a hand-written list of
 * relation names is a list that stops being true, quietly, the first time
 * somebody adds a relation. This one cannot.
 */
function clcRelationNames(): array
{
    static $names = null;

    if ($names !== null) {
        return $names;
    }

    $names = [];

    foreach (glob(base_path('app/Models/*.php')) ?: [] as $path) {
        $parts = preg_split('/\bpublic function\s+/', clcPhpCode((string) file_get_contents($path))) ?: [];

        foreach (array_slice($parts, 1) as $part) {
            if (! preg_match('/^(\w+)\s*\(\s*\)/', $part, $m)) {
                continue;
            }

            $body = strstr($part, "\n    }", true);
            $body = $body === false ? substr($part, 0, 600) : $body;

            if (preg_match('/\$this->(belongsToMany|belongsTo|hasManyThrough|hasMany|hasOne|morphMany|morphOne|morphTo)\(/', $body)) {
                $names[$m[1]] = true;
            }
        }
    }

    $names = array_keys($names);
    sort($names);

    return $names;
}

/** The relation names $code reads off something, as `->name`. */
function clcRelationsRead(string $code): array
{
    $found = [];

    foreach (clcRelationNames() as $name) {
        if (preg_match('/->' . preg_quote($name, '/') . '\b(?!\s*\()/', $code)) {
            $found[] = $name;
        }
    }

    sort($found);

    return $found;
}

/* ═══════════════════ watching for a lazy load, at render ══════════════════ */

/**
 * Run $fn with lazy loading forbidden, and report what it did anyway.
 *
 * A CALLBACK, NOT THE EXCEPTION. Throwing stops at the first violation and
 * reports one relation off one model; recording renders the whole page and
 * reports every relation on it, which is the difference between "something is
 * lazy on /shop" and a list a reader can act on. Both halves of the global
 * state are put back in a `finally`, because leaving preventLazyLoading() on
 * would change every test that runs after this file in the same process.
 */
function clcWatch(callable $fn): array
{
    $violations = [];

    Model::preventLazyLoading();
    Model::handleLazyLoadingViolationUsing(function ($model, $relation) use (&$violations): void {
        $key = get_class($model) . '::$' . $relation;
        $violations[$key] = ($violations[$key] ?? 0) + 1;
    });

    try {
        $fn();
    } finally {
        Model::preventLazyLoading(false);
        Model::handleLazyLoadingViolationUsing(null);
    }

    ksort($violations);

    return $violations;
}

/** One page, rendered with the guard on. */
function clcPage(string $path, array $cookies = []): array
{
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    $html = '';

    $violations = clcWatch(function () use ($path, $cookies, &$html): void {
        $html = test()
            ->withUnencryptedCookies($cookies)
            ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
            ->get($path)
            ->assertOk()
            ->getContent();
    });

    return [
        'violations' => $violations,
        // Both tiles at once: <x-product-card> renders `<div class="pc">`,
        // the skinned grid and partials/home/grid render `<a class="kbb-card"`.
        'tiles' => substr_count($html, '<div class="pc">') + substr_count($html, '<a class="kbb-card"'),
    ];
}

function clcFailure(string $where, array $violations): string
{
    $lines = [];

    foreach ($violations as $key => $count) {
        $lines[] = '    ' . $key . '  x' . $count;
    }

    return $where . " read a relation that was not eager-loaded, once per row:\n"
        . implode("\n", $lines) . "\n\n"
        . "Every template in CLC_CONTRACTS (top of this file) states what it needs;\n"
        . "the query behind this page has to load it. This is not a wrong pixel —\n"
        . "the page renders correctly and costs one statement per tile.";
}

/* ══════════════════════════════ the fixture ═══════════════════════════════ */

/**
 * ONE CATALOGUE, BUILT ONCE, for every page below.
 *
 * Nine products on one brand and one category, so every page here has at least
 * three tiles to draw — which is what makes the mechanism able to see anything
 * at all (see the note about hydrate() at the top).
 */
function clcCatalogue(): array
{
    Product::query()->forceDelete();
    Cache::flush();

    BuildMyRoutineRoutes::wire(app());
    app(SettingsService::class)->setModule('build_my_routine', true);

    $brand = Brand::create(['slug' => 'clc-house', 'name' => 'CLC House']);
    $category = Category::create(['slug' => 'clc-cat', 'name' => 'CLC Category']);

    $make = function (string $slug, array $extra = []) use ($brand, $category): Product {
        $product = Product::create(array_merge([
            'slug' => 'clc-' . $slug,
            'name' => 'CLC ' . $slug,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 10000,
            'stock_status' => 'instock',
            'type' => 'simple',
            'brand_id' => $brand->id,
        ], $extra));

        $product->categories()->attach($category->id);

        return $product;
    };

    $plain = [];

    foreach (['a', 'b', 'c', 'd'] as $slug) {
        $plain[] = $make($slug);
    }

    // Five roles, all tagged `acne`: /routines/acne draws a card per step and
    // /concern/acne/ needs ConcernCollections::MIN_PRODUCTS, which is 3.
    foreach (['cleanse', 'tone', 'treat', 'moisturise', 'protect'] as $role) {
        $make('r-' . $role, [
            'routine_role' => $role,
            'routine_concerns' => json_encode(['acne']),
        ]);
    }

    // A published CMS page carrying the shortcode, at an address routes/web.php
    // already owns.
    Page::query()->updateOrCreate(['slug' => 'about'], [
        'title' => 'CLC Shortcode Page',
        'content' => '<p>Before</p>[kbb_products limit="6"]<p>After</p>',
        'status' => 'published',
    ]);

    return ['plain' => $plain, 'product' => $plain[0]];
}

/* ═══════════════════ 1. the contract matches the source ═══════════════════ */

it('derives the relation names from the models rather than listing them', function () {
    /*
     * The scanner's own fixture, asserted before it is trusted. If the
     * derivation broke — a refactor to `protected function`, a change of
     * formatting — clcRelationsRead() would find nothing in any template, every
     * case below would pass, and this file would assert nothing at all. That is
     * the failure mode a scanner has and a behavioural test does not.
     */
    $names = clcRelationNames();

    expect(count($names))->toBeGreaterThan(20)
        ->and($names)->toContain('brand')
        ->and($names)->toContain('categories')
        ->and($names)->toContain('variants')
        ->and($names)->toContain('attributeValues')
        // And something that is NOT a relation does not get in.
        ->and($names)->not->toContain('rating');
});

it('states, for each contracted template, exactly the relations it reads', function () {
    $wrong = [];

    foreach (CLC_CONTRACTS as $path => $declared) {
        expect(is_file(base_path($path)))->toBeTrue($path . ' is contracted and does not exist');

        $read = clcRelationsRead(clcCode(base_path($path)));

        sort($declared);

        if ($read !== $declared) {
            $wrong[] = sprintf(
                '%s declares [%s] and reads [%s]',
                $path,
                implode(', ', $declared),
                implode(', ', $read)
            );
        }
    }

    expect($wrong)->toBe([], implode("\n", array_merge(
        ['A contracted template reads a different set of relations than it declares.'],
        ['Update CLC_CONTRACTS in this file AND the docblock at the top of the'],
        ['template, and make sure every caller listed in CLC_COVERED eager-loads'],
        ['the new one — it is one statement per tile until they do:'],
        [''],
        $wrong,
    )));
});

it('has a contract for every component that reads a relation off a model', function () {
    /*
     * THE HOLE THIS CLOSES. The two components under components/ are the ones
     * that exist today. A third — a review tile, a bundle card, a brand strip —
     * written six months from now with `$item->brand` in it is the next copy of
     * this defect, and nothing else in the suite would have an opinion about it.
     */
    $missing = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('resources/views/components'))) as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $relative = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
        $read = clcRelationsRead(clcCode($file->getPathname()));

        if ($read !== [] && ! array_key_exists($relative, CLC_CONTRACTS)) {
            $missing[] = $relative . ' reads ' . implode(', ', $read);
        }
    }

    expect($missing)->toBe([], implode("\n", array_merge(
        ['A component reads a relation off a model it was handed and does not say so.'],
        ['The caller cannot see the requirement and gets one statement per row when'],
        ['it forgets. Add it to CLC_CONTRACTS, name its callers in CLC_COVERED, and'],
        ['render one of them below:'],
        [''],
        $missing,
    )));
});

/* ═══════════════════ 2. every caller is named and covered ═════════════════ */

it('knows every caller of every contracted template', function () {
    $unknown = [];
    $seen = [];

    foreach (clcSourceFiles() as $relative) {
        if (array_key_exists($relative, CLC_CONTRACTS)) {
            continue;
        }

        $code = clcCode(base_path($relative));

        foreach (CLC_INVOCATIONS as $template => $needles) {
            foreach ($needles as $needle) {
                if (! str_contains($code, $needle)) {
                    continue;
                }

                $line = substr_count(substr($code, 0, (int) strpos($code, $needle)), "\n") + 1;
                $seen[$relative] = true;

                if (! array_key_exists($relative, CLC_COVERED)) {
                    $unknown[] = $relative . ':' . $line . '  renders ' . $template;
                }
            }
        }
    }

    expect($unknown)->toBe([], implode("\n", array_merge(
        ['A new caller of a component that needs relations eager-loaded. The query'],
        ['behind it has to load what CLC_CONTRACTS says the template reads, and this'],
        ['file has to render the page so that stays true:'],
        [''],
        $unknown,
    )));

    /*
     * AND THE OTHER DIRECTION, which is the half a list like this usually
     * lacks. An entry in CLC_COVERED that no longer calls anything is a page
     * being rendered below for no reason, and — worse — a reader's evidence
     * that the call site is covered when the call site is gone.
     */
    $stale = array_values(array_diff(array_keys(CLC_COVERED), array_keys($seen)));

    expect($stale)->toBe([], 'CLC_COVERED names a file that no longer renders a contracted template: '
        . implode(', ', $stale));
});

/* ═════════════════ 3. the mechanism, proved on the real defect ════════════ */

it('reports the grid reading brand and categories off a query that loaded neither', function () {
    /*
     * THE LATENT DEFECT, REPRODUCED. This is what every page below is being
     * checked against, so it is demonstrated once rather than assumed: the same
     * component, the same products, rendered from a query with no eager load.
     *
     * ▲ THE QUERY RUNS INSIDE THE WINDOW, and it has to. Builder::hydrate()
     * copies the flag onto each row AS IT BUILDS IT — a collection fetched
     * before the guard was switched on carries `preventsLazyLoading = false`
     * and reports nothing however it is rendered. The first version of this
     * case fetched outside and came back clean.
     */
    clcCatalogue();

    $html = '';

    $violations = clcWatch(function () use (&$html): void {
        $bare = Product::query()->where('status', 'publish')->limit(6)->get();
        $html = view('components.product-grid', ['products' => $bare])->render();
    });

    expect(substr_count($html, '<a class="kbb-card"'))->toBe(6, 'the grid rendered nothing, so this case measured nothing');

    expect($violations)->toBe([
        'App\Models\Product::$brand' => 6,
        'App\Models\Product::$categories' => 6,
    ]);

    // And the eager-loaded query, through the same component, is silent — so
    // the assertion above is about the eager load and not about the component.
    $ok = clcWatch(function (): void {
        $loaded = Product::query()->where('status', 'publish')
            ->with(['brand:id,name,slug', 'categories:id,name'])->limit(6)->get();
        view('components.product-grid', ['products' => $loaded])->render();
    });

    expect($ok)->toBe([]);
});

it('costs one statement per tile for each relation the caller did not load', function () {
    /*
     * WHAT THE VIOLATION ABOVE IS WORTH, IN STATEMENTS, so this file's subject
     * is a cost and not a style rule. Six tiles, then twelve, through the same
     * component — the count of `category_product` reads is the tile count.
     */
    clcCatalogue();

    $count = function (int $tiles): array {
        $sql = [];
        DB::listen(function ($event) use (&$sql): void { $sql[] = $event->sql; });

        $rows = Product::query()->where('status', 'publish')->limit($tiles)->get();
        $html = view('components.product-grid', ['products' => $rows])->render();

        return [
            'rendered' => substr_count($html, '<a class="kbb-card"'),
            'pivot' => count(array_filter($sql, static fn (string $s): bool => str_contains($s, 'category_product'))),
        ];
    };

    $small = $count(3);
    $large = $count(9);

    // The fixture varies, asserted before anything is compared.
    expect($small['rendered'])->toBe(3)->and($large['rendered'])->toBe(9);

    // One pivot read per tile, both times: the slope is exactly 1.
    expect($small['pivot'])->toBe(3)->and($large['pivot'])->toBe(9);
});

/* ═════════════ 4. every covered page, rendered with the guard on ══════════ */

it('renders every page that calls a contracted template without one lazy load', function () {
    /*
     * ONE CASE FOR ALL EIGHT, and one catalogue for all eight. Each page is
     * asserted to have drawn at least three tiles BEFORE its violations are
     * read: a page that rendered an empty grid reports no lazy loads and looks
     * exactly like a page that is fine. Three of the previous round's ten
     * fixtures rendered zero of the thing they were varying.
     *
     * The homepage is rendered cold — its grids are fragment-cached, so a warm
     * one would re-serve HTML built before the guard was on and measure nothing.
     */
    $fixture = clcCatalogue();

    $pages = [
        '/shop' => [],
        '/korean-skincare-brands/clc-house/' => [],
        '/product/' . $fixture['product']->slug => [],
        '/routines/acne' => [],
        '/concern/acne/' => [],
        '/about' => [],
        '/my-wishlist' => ['kbb_wishlist' => implode(',', array_map(
            static fn (Product $p): int => $p->id,
            $fixture['plain']
        ))],
    ];

    $thin = [];
    $offenders = [];

    foreach ($pages as $path => $cookies) {
        $result = clcPage($path, $cookies);

        if ($result['tiles'] < 3) {
            $thin[] = $path . ' rendered ' . $result['tiles'] . ' tiles';
        }

        if ($result['violations'] !== []) {
            $offenders[] = clcFailure($path, $result['violations']);
        }
    }

    // The homepage last, and cold, because Cache::flush() would otherwise throw
    // away the fragments the pages above have just built and change what they
    // cost — this file does not measure cost, but a reader comparing it with
    // StorefrontQueryBudgetTest should not have to wonder.
    Cache::flush();
    $home = clcPage('/');

    if ($home['tiles'] < 3) {
        $thin[] = '/ rendered ' . $home['tiles'] . ' tiles';
    }

    if ($home['violations'] !== []) {
        $offenders[] = clcFailure('/', $home['violations']);
    }

    expect($thin)->toBe([], "A page below rendered almost nothing, so its result means nothing:\n  "
        . implode("\n  ", $thin));

    expect($offenders)->toBe([], implode("\n\n", $offenders));
});
