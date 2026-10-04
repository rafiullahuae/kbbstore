<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\GridSection;
use App\Models\Product;
use App\Support\GridSkins;
use App\Support\ProductSource;
use App\Support\SafeUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Appearance → Grid sections. ONE section type, used as many times as wanted.
 *
 * Phase 23, Lane GS. The owner's last three sentences are the whole brief:
 *
 *   "DO ONE thing. prepare a proper grid section with all controls and it can
 *    be use anywhere, and can be edit that specific grid section. so this case
 *    we can re-use this grid section anywhere multiple times with different
 *    products etc selection."
 *
 * The 4-up "Big savings bundles" row and the 5-up "BEST SELLERS" row he named
 * are the first two INSTANCES of this type, not two sections. They are offered
 * as one-click presets (`PRESETS`) and are created, not shipped — see
 * `StorefrontEnglishUnchangedTest` and rule 1.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * IT IS NOT A SECOND SECTION MECHANISM. IT IS ROWS IN THE EXISTING ONE.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `HomepageSections` keys ordering, the Desktop/Mobile switches and the
 * dividers off `REGISTRY`, which is a const and therefore fixed. The one change
 * on that side is `HomepageSections::registry()`, which returns that const PLUS
 * `registryRows()` — one row per instance in `grid_sections`. Everything else
 * an instance gets on `Appearance → Homepage` it gets for free and through the
 * code the seventeen shipped sections already go through: `all()` merges and
 * casts it, `settle()` numbers it, `orderStyle()` moves it, `classFor()`
 * classes it, `save()` persists it.
 *
 * A parallel mechanism beside that list — a second order, a second pair of
 * device switches — is what this repo's own comments say it has paid for
 * elsewhere, and it would show up immediately as two screens disagreeing about
 * where a section sits.
 *
 * THE SKIN IS THE ONE VALUE THAT DELIBERATELY DOES **NOT** GO THROUGH IT.
 * `HomepageSections::SECTION_SCHEMA` carries a `skin` field, dropped for any
 * section whose registry row says it has no grid. An instance's registry row
 * says exactly that — `false` — even though an instance is nothing BUT a grid,
 * because the skin is edited on the instance's own screen beside the rest of
 * its controls and a value with two homes is a value that will disagree with
 * itself. `castRow()` then stores `skin => null` for the key and the console
 * draws no picker, which is the same rule applied to the same field.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT N INSTANCES COST, WHICH IS THE REAL RISK IN THIS FEATURE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `StorefrontQueryBudgetTest` gives the homepage FIVE and it measures THREE, so
 * there are two to spend, and "several instances each pulling products" is
 * precisely how a homepage becomes an N+1. Two separate reads exist and both
 * are answered from one cache entry each:
 *
 *   registryRows()  ONE query — id, name, status, position — and it is asked
 *                   about forty times per render, because HomepageSections::
 *                   all() re-reads on every classFor(). A process-level static
 *                   memo answers all forty from the first, and `Cache` answers
 *                   the first from the previous request.
 *
 *   forHome()       ONE query for the rows, then one per DISTINCT source spec.
 *                   Two instances asking for the same eight best sellers are
 *                   one query, not two — `specKey()` is what makes that true,
 *                   and it is why the owner's two presets are cheaper than they
 *                   look. Cached whole, so a warm homepage pays NOTHING.
 *
 * Measured on this project's own fixture, with THIS feature's two cache entries
 * cleared and the rest of the page warm, against a fully warm page:
 *
 *                     cold    warm
 *     0 instances       1       0
 *     1 instance        4       0
 *     3 instances       8       0
 *     6 instances      14       0
 *
 * The warm column is what the budget test sees and what a shopper gets — the
 * rails beside this section have been cached the same way since the page was
 * written, and `StorefrontQueryBudgetTest` measures the homepage at the same
 * number with six instances built as with none.
 *
 * The cold column is `1 (registry) + 1 (rows) + 2 per distinct selection`, and
 * the 2 is worth naming rather than rounding away: the products, and then the
 * `with('brand:id,name,slug')` eager load the card needs. That second query is
 * what makes the page FLAT — without it the card would read a brand per tile —
 * so it is a cost this feature buys deliberately. An EMPTY selection costs 1,
 * because Eloquent skips an eager load with nothing to hydrate.
 *
 * It is flat in the CATALOGUE — 12 products or 60 makes no difference — and
 * linear in the number of DISTINCT product selections the owner has asked for,
 * which is the irreducible number: six different product lists cannot be
 * fetched in fewer than six reads without fetching things nobody asked for.
 * `GridSectionQueryCostTest` measures all four rows and asserts both flatnesses.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * AND IT COSTS AN EMPTY SHOP NOTHING AT ALL
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * With no rows, `registryRows()` returns `[]` and `HomepageSections::registry()`
 * is `REGISTRY` byte for byte; `forHome()` returns `[]` before it reads
 * anything else; `css()` returns `''`; and the homepage's loop emits no
 * element. That is the whole of rule 1 here: applying this package moves
 * nothing until the owner creates an instance.
 */
class GridSections
{
    /**
     * The columns a card needs, and the three a SET's price cannot be read
     * without.
     *
     * Copied in shape from `Store\HomeController::CARD_COLUMNS`, which is
     * private to that controller. The three `SetPricing::COLUMNS` are spread in
     * for the reason its header gives: `SetPricing::mode()` and `::basis()`
     * read them off `getAttributes()` and fall back to "no rule, no anchor" for
     * an absent column, so a narrow select does not fail — it prices a
     * rule-priced set at the stale snapshot in `products.price`, and the grid
     * shows one number while the set's own page shows another.
     *
     * @var list<string>
     */
    public const CARD_COLUMNS = [
        'id', 'wc_id', 'slug', 'name', 'brand_id', 'price', 'sale_price',
        'sale_starts_at', 'sale_ends_at', 'stock_status', 'image',
        'rating', 'review_count', 'featured', 'type', 'total_sales',
        ...\App\Support\SetPricing::COLUMNS,
    ];

    /** Cache keys, both evicted together by flush(). */
    private const CACHE_REGISTRY = 'kbb.home.gridsections.registry';

    private const CACHE_HOME = 'kbb.home.gridsections.home';

    /** Ten minutes, the same as `kbb.home.rails` beside it. */
    private const TTL = 600;

    /**
     * The per-request memo's key IN THE CONTAINER — deliberately not a static.
     *
     * ── WHY NOT A STATIC, WHICH IS WHAT THIS WAS ────────────────────────────
     *
     * `Setting::map()` memoises in a process-level static as well as the cache,
     * and CLAUDE.md names the consequence: "within one long-lived process it
     * will not see writes made after the first call. Fine under PHP-FPM, a trap
     * in tests and queue workers."
     *
     * This was written as exactly that static, and the trap arrived within the
     * hour — not in this lane's own tests, which flush explicitly, but in FIVE
     * OTHER LANES' FILES. Pest runs the suite in one process and rolls the
     * database back between tests; a static survives the rollback, so once this
     * lane's cases had built `grid_1`…`grid_5`, `HomepageSections::registry()`
     * went on returning five instances that no longer existed for every later
     * test in the process. `HomepageSectionOrderTest` counted 24 order rules
     * where it expected 18, `HomepagePreviewTest`'s section list grew five
     * rows, and two files died on `Undefined array key "grid_1"`. A stale memo
     * does not error where it is wrong; it errors somewhere else, in somebody
     * else's file, and reads exactly like flake.
     *
     * The container is the right scope and not a workaround: production throws
     * the whole container away between requests, so a container instance IS
     * per request — the same lifetime the static was pretending to have — and
     * Pest builds a fresh Application per test, so the memo cannot outlive the
     * rows it describes. A queue worker gets the same correctness for free.
     *
     * `flush()` still clears both layers, because the cache entry outlives the
     * container.
     */
    private const MEMO = 'kbb.gridsections.registry.memo';

    /**
     * The two instances the owner named, as one-click presets.
     *
     * ── WHY THESE ARE PRESETS AND NOT DEFAULTS ──────────────────────────────
     *
     * Rule 1: every new setting ships at the value the page already renders,
     * and a shop with no instance created renders byte-identically. Seeding his
     * two rows would put two new sections on the front page of a live shop the
     * moment the package applied, which is the one thing this may not do. A
     * preset is the same content one button later, and it is his content
     * because he pressed the button.
     *
     * The numbers are his, verbatim: "the product grid 4 by default on desktop
     * and on mobile careousel" and "5 columns on desktop and in mobile 6
     * products".
     *
     * @var array<string, array<string, mixed>>
     */
    public const PRESETS = [
        'bundles' => [
            /*
             * `bestsellers` IS THE STARTING SELECTION AND THE LABEL SAYS SO.
             *
             * The shipped `bundles` rail finds its products with a `slug LIKE
             * '%set%'` category match, which is a rule this table has no column
             * for and which no preset can carry: the owner's sets category has
             * an id only his shop knows. A preset that silently guessed would
             * be a row of the wrong products under the right heading, which is
             * worse than a row he has to point at his category — one select,
             * and the screen's own help text names it.
             */
            'label' => 'Big savings bundles — 4 across, carousel on the phone (then point it at your sets category)',
            'values' => [
                'name' => 'Big savings bundles',
                'heading' => 'Big savings bundles',
                'subheading' => 'Complete routines, priced below the sum of their parts.',
                'show_heading' => true,
                'source' => 'bestsellers',
                'count' => 8,
                'mobile_count' => 8,
                'desktop_layout' => 'grid',
                'desktop_cols' => 4,
                'mobile_layout' => 'carousel',
                'mobile_cols' => 2,
                'show_view_all' => true,
                'view_all_label' => 'View all',
                'card_label' => 'Skincare sets',
                'view_all_url' => '/shop/',
            ],
        ],
        'bestsellers' => [
            'label' => 'BEST SELLERS — 5 across, 6 products on the phone',
            'values' => [
                'name' => 'Best sellers',
                'heading' => 'BEST SELLERS',
                'subheading' => '',
                'show_heading' => true,
                'source' => 'bestsellers',
                'count' => 10,
                'mobile_count' => 6,
                'desktop_layout' => 'grid',
                'desktop_cols' => 5,
                'mobile_layout' => 'carousel',
                'mobile_cols' => 2,
                'show_rank' => true,
                'show_view_all' => true,
                'view_all_label' => 'View all',
                'view_all_url' => '/shop/?orderby=popularity',
            ],
        ],
    ];

    /**
     * ONE STATEMENT OF WHAT AN INSTANCE'S CONTROLS ARE.
     *
     * Read three times and written once: the console renders it through
     * `ModuleSchema::tabs()`, the controller casts every write through
     * `ModuleSchema::cast()`, and `AdminConsoleWriteTokenTest`'s "a box with no
     * writer behind it" sweep reads the same list. A control added here appears
     * on the screen, is validated on the way in and is persisted, from one
     * place — which is the arrangement docs/M-PHASE3-SETTINGS-SCHEMA.md §1
     * measured the cost of doing four times.
     *
     * `store` is absent on every field on purpose: these do NOT live in
     * `settings` or `module_settings`, they live in columns on this instance's
     * own row. `ModuleSchema` is used here for its cast and its renderer, not
     * for its storage — the same way `HomepageSections::SECTION_SCHEMA` uses it
     * for three fields that live inside a blob.
     */
    public const SCHEMA = [
        'name' => ['type' => 'text', 'label' => 'Section name', 'default' => '', 'max' => 190,
            'help' => 'What you call it here. Shoppers never see this — it is how you tell two grids apart on Appearance → Homepage.'],

        'status' => ['type' => 'select', 'label' => 'Published', 'default' => 'draft',
            'options' => GridSection::STATUSES,
            'help' => 'A draft is listed on Appearance → Homepage so you can see it exists, and draws nothing on the shop.'],

        'show_heading' => ['type' => 'bool', 'label' => 'Show the heading', 'default' => true],
        'heading' => ['type' => 'text', 'label' => 'Heading', 'default' => '', 'max' => 190,
            'help' => 'The words above the grid. Translate it under Content → Translations like every other shop string.'],
        'subheading' => ['type' => 'text', 'label' => 'Sub-heading', 'default' => '', 'max' => 255],

        'source' => ['type' => 'select', 'label' => 'Which products', 'default' => 'bestsellers',
            'options' => GridSection::SOURCES],
        'source_brand_id' => ['type' => 'select', 'label' => 'Brand', 'default' => '',
            'options' => ['' => 'Choose a brand'],
            'help' => 'Used only when “Which products” is One brand.'],
        'source_category_id' => ['type' => 'select', 'label' => 'Category', 'default' => '',
            'options' => ['' => 'Choose a category'],
            'help' => 'Used only when “Which products” is One category.'],
        'include_children' => ['type' => 'bool', 'label' => 'Include sub-categories', 'default' => false,
            'help' => 'Also take products filed under anything beneath the chosen category.'],

        'count' => ['type' => 'int', 'label' => 'How many on desktop', 'default' => 8, 'min' => 1, 'max' => 48],
        'mobile_count' => ['type' => 'int', 'label' => 'How many on mobile', 'default' => 8, 'min' => 1, 'max' => 48,
            'help' => 'Both numbers are served by ONE query — the larger is fetched and the surplus is hidden in CSS at the other width.'],

        'desktop_layout' => ['type' => 'select', 'label' => 'Desktop layout', 'default' => 'grid',
            'options' => GridSection::LAYOUTS],
        'desktop_cols' => ['type' => 'select', 'label' => 'Desktop columns', 'default' => '4',
            'options' => GridSection::DESKTOP_COLS,
            'help' => 'In a carousel this is how many whole cards are in view at once.'],
        'mobile_layout' => ['type' => 'select', 'label' => 'Mobile layout', 'default' => 'carousel',
            'options' => GridSection::LAYOUTS],
        'mobile_cols' => ['type' => 'select', 'label' => 'Mobile columns', 'default' => '2',
            'options' => GridSection::MOBILE_COLS,
            'help' => 'For a phone grid. A phone carousel is sized by Cards in view · phone.'],
        /* Lane HC — "give this option on any carousel products section we
           create". Read only while that device's layout is a carousel. */
        'per_d' => ['type' => 'select', 'label' => 'Cards in view · laptop', 'default' => '',
            'options' => GridSection::PER_D, 'help' => 'When the laptop layout is a carousel. A half shows part of the next card.'],
        'per_m' => ['type' => 'select', 'label' => 'Cards in view · phone', 'default' => '2.3',
            'options' => GridSection::PER_M, 'help' => 'When the phone layout is a carousel. A part card at the edge tells a thumb there is more.'],
        'arrows_d' => ['type' => 'bool', 'label' => 'Arrows · laptop', 'default' => true, 'help' => 'Round arrows at either side of a laptop carousel.'],
        'arrows_m' => ['type' => 'bool', 'label' => 'Arrows · phone', 'default' => false, 'help' => 'Off: the peeking card and a swipe do the job.'],

        'skin' => ['type' => 'skin', 'label' => 'Card template', 'default' => '',
            'options' => ['' => 'Use the shop’s own grid style'],
            'help' => 'One of the shop’s existing card templates. This picks one; it never defines a new one.'],
        'card_label' => ['type' => 'text', 'label' => 'Eyebrow on every tile', 'default' => '', 'max' => 120,
            'help' => 'The small upper-case line above each product name — “SKINCARE SETS” on the reference shot. Empty draws none.'],
        'show_rank' => ['type' => 'bool', 'label' => 'Number the tiles #1, #2…', 'default' => false,
            'help' => 'What the shipped best-sellers rail does. Leave it off for anything that is not a ranking.'],

        'show_view_all' => ['type' => 'bool', 'label' => 'Show a “View all” button', 'default' => false],
        'view_all_label' => ['type' => 'text', 'label' => '“View all” text', 'default' => '', 'max' => 120,
            'help' => 'Leave it empty and the shop prints its own translated “View all”.'],
        'view_all_url' => ['type' => 'text', 'label' => '“View all” link', 'default' => '', 'max' => 500,
            'help' => 'Any address you like. It is scheme-checked before it becomes a link, so javascript: and //other-host cannot be published.'],
    ];

    /** The groups the console draws the controls in, in this order. */
    public const TABS = [
        'identity' => ['This grid', 'What you call it, and whether it is live.', ['name', 'status']],
        'heading' => ['Heading', 'The words above the grid.', ['show_heading', 'heading', 'subheading']],
        'products' => ['Which products', 'Where this grid’s products come from, and how many.',
            ['source', 'source_brand_id', 'source_category_id', 'include_children', 'count', 'mobile_count']],
        'layout' => ['Layout', 'Desktop and mobile are set separately.',
            ['desktop_layout', 'desktop_cols', 'mobile_layout', 'mobile_cols', 'per_d', 'per_m', 'arrows_d', 'arrows_m', 'skin', 'card_label', 'show_rank']],
        'viewall' => ['“View all” button', 'The button under the grid.',
            ['show_view_all', 'view_all_label', 'view_all_url']],
    ];

    /**
     * This module's point on ModuleSchema's policy axes.
     *
     * `invalid => default` is the one that matters and it is the same choice
     * `Banners::POLICY` makes for the same reason: a hand-rolled POST of
     * `source=<script>`, or the id of a brand deleted a minute ago, stores the
     * DEFAULT and the grid draws best sellers, instead of coming back in a
     * `rejected` list the screen then has to explain. `blank => keep` because
     * '' is a real value for the heading, the label and the link — clearing a
     * box has to clear it, not put the default back over it.
     */
    public const POLICY = ['max' => 500, 'blank' => 'keep', 'invalid' => 'default', 'clamp' => true, 'bool' => 'cast'];

    /**
     * The registry rows `HomepageSections::registry()` appends to its const.
     *
     * ── WHY IT IS A STATIC WITH ITS OWN MEMO ────────────────────────────────
     *
     * `HomepageSections::all()` calls the registry on every read, and
     * `classFor()` calls `all()` once per section — around forty times on one
     * homepage render, and more on the console. A query per call would be an
     * N+1 in the most literal sense. The static answers every call after the
     * first in the same process; `Cache` answers the first call after the
     * first request.
     *
     * ▲ DRAFTS ARE LISTED. An instance that draws nothing still has to be
     * visible on Appearance → Homepage, or an owner who drafted one is left
     * looking for a section that has vanished. It draws nothing because
     * `forHome()` filters on `status`, not because it is missing from the list.
     *
     * @return array<string, array{0: string, 1: string, 2: bool, 3: string|null}>
     */
    public static function registryRows(): array
    {
        $container = \Illuminate\Container\Container::getInstance();

        if ($container->bound(self::MEMO)) {
            /** @var array<string, array{0: string, 1: string, 2: bool, 3: string|null}> $memo */
            $memo = $container->make(self::MEMO);

            return $memo;
        }

        $rows = Cache::remember(self::CACHE_REGISTRY, self::TTL, static function () {
            /*
             * ── THE ONE GUARD IN THIS FILE, AND EXACTLY WHAT IT SWALLOWS ────
             *
             * `Banners::forHome()` reaches its table only AFTER a settings read
             * says the module is on, so an un-migrated shop with that feature
             * off never touches `banner_cards`. This read has no such gate: it
             * runs on every homepage, because the section registry has to be
             * complete before anything can ask what is in it.
             *
             * That matters here specifically. CLAUDE.md records five packages
             * whose migrations were copied to the live server and never ran —
             * and the shape of that failure with an ungated read is EVERY PAGE
             * OF THE SHOP 500ing on "no such table: grid_sections", from a
             * feature nobody has configured. `UpdatePackage::verify()` now
             * refuses a package that carries migrations without declaring them,
             * and that file's own note says not to rely on the door.
             *
             * So a MISSING TABLE — and nothing else — answers "no instances",
             * which is the true answer for a shop where this feature has not
             * been installed yet. SQLSTATE 42S02 is "base table or view not
             * found" on both engines; SQLite also reaches here with HY000 and
             * "no such table" in the message, which is why both are matched.
             * Every other database error is RE-THROWN, so a connection failure
             * or a permissions problem still surfaces as itself rather than as
             * a homepage quietly missing two sections.
             *
             * It leaves no state behind and cannot dirty a model — the
             * distinction CLAUDE.md's updater landmine turns on. The next
             * request after the migration runs reads the table normally; this
             * result is cached for at most ten minutes and the migration
             * beside it flushes the key anyway.
             */
            try {
                return DB::table('grid_sections')
                    ->select('id', 'name', 'status')
                    ->orderBy('position')
                    ->orderBy('id')
                    ->get()
                    ->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name, 'status' => (string) $r->status])
                    ->all();
            } catch (\Illuminate\Database\QueryException $e) {
                if ((string) $e->getCode() === '42S02' || str_contains($e->getMessage(), 'no such table')) {
                    return [];
                }

                throw $e;
            }
        });

        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $key = GridSection::keyFor((int) ($row['id'] ?? 0));

            $label = trim((string) ($row['name'] ?? '')) !== ''
                ? (string) $row['name']
                : 'Product grid #'.((int) ($row['id'] ?? 0));

            $out[$key] = [
                $label,
                ($row['status'] ?? '') === 'publish'
                    ? 'A product grid you built. Edit it in Appearance → Grid sections.'
                    : 'A product grid you built. It is a DRAFT, so it draws nothing until you publish it in Appearance → Grid sections.',
                /*
                 * `false` — no grid skin picker on the Homepage screen, even
                 * though this section is nothing but a grid. The class header
                 * has the argument: the skin is edited on the instance's own
                 * screen, and a value with two homes is a value that will
                 * disagree with itself.
                 */
                false,
                null,
            ];
        }

        $container->instance(self::MEMO, $out);

        return $out;
    }

    /**
     * Both layers, because one of them is a trap on its own.
     *
     * Called by every writer in `GridSectionApiController` and by the tests.
     * Clearing only the cache would leave the memo answering the old list for
     * the rest of THIS request; clearing only the memo would leave the next
     * request reading a stale cache entry. The memo is container-scoped rather
     * than static — see MEMO for what the static version cost — so it dies with
     * the request anyway, and this makes a write visible within it.
     */
    public static function flush(): void
    {
        \Illuminate\Container\Container::getInstance()->forgetInstance(self::MEMO);

        Cache::forget(self::CACHE_REGISTRY);
        Cache::forget(self::CACHE_HOME);
    }

    /**
     * Every PUBLISHED instance with its products, for the homepage. One cache
     * entry.
     *
     * Returns a list in `position` order of
     * `['section' => GridSection, 'items' => Collection<Product>]`, and an
     * instance whose selection came back empty is DROPPED — a heading with no
     * grid under it is a gap on the front page, and the template would have to
     * check for it anyway.
     *
     * ▲ THE ORDER OF THIS LIST IS THE DOM ORDER, NOT THE PAGE ORDER. The page
     * order is `HomepageSections`' job and is applied with CSS `order` by
     * `orderStyle()`, exactly as it is for the seventeen shipped sections. This
     * list only has to agree with `registryRows()`, which it does because both
     * sort by `position` then `id` — and it has to, or a shop on the default
     * order would draw the instances in one sequence while the console painted
     * another.
     *
     * @return list<array{section: GridSection, items: Collection}>
     */
    public function forHome(): array
    {
        if (self::registryRows() === []) {
            return [];
        }

        $built = Cache::remember(self::CACHE_HOME, self::TTL, function () {
            $sections = GridSection::query()
                ->where('status', 'publish')
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            if ($sections->isEmpty()) {
                return [];
            }

            /*
             * ── THE DE-DUPLICATION, WHICH IS WHAT KEEPS THIS OFF N+1 ────────
             *
             * Two instances asking for the same eight best sellers are ONE
             * query. The owner's two presets both read `bestsellers` at
             * different counts, so they are one query at the larger count and a
             * PHP `take()` — which is the same trick `HomeController` uses for
             * `best1`/`best2`, and for the same reason it gives there: two
             * queries meant to serve one list over a tied sort key are free to
             * disagree with each other.
             */
            $wanted = [];

            foreach ($sections as $section) {
                $spec = self::specKey($section);
                $wanted[$spec] = max($wanted[$spec] ?? 0, $section->fetchCount());
            }

            $pools = [];

            foreach ($wanted as $spec => $limit) {
                $pools[$spec] = $this->fetchPool($spec, $limit);
            }

            $out = [];

            foreach ($sections as $section) {
                $items = ($pools[self::specKey($section)] ?? collect())->take($section->fetchCount())->values();

                if ($items->isEmpty()) {
                    continue;
                }

                $out[] = ['section' => $section, 'items' => $items];
            }

            return $out;
        });

        return is_array($built) ? $built : [];
    }

    /**
     * One instance drawn on its own, for the console's preview. Never cached.
     *
     * The preview has to draw a DRAFT, because a draft is exactly what the
     * owner is looking at while he builds it — the same argument
     * `Banners::forPreview()` makes, and the same shape: one parameter apart
     * from the shop's reader rather than a second copy of the query, so the
     * preview cannot drift from what the shop will draw.
     *
     * @return array{section: GridSection, items: Collection}|null
     */
    public function forPreview(GridSection $section): ?array
    {
        $items = $this->fetchPool(self::specKey($section), $section->fetchCount())
            ->take($section->fetchCount())
            ->values();

        return ['section' => $section, 'items' => $items];
    }

    /**
     * The identity of a product selection: two instances with the same spec get
     * the same rows and therefore the same query.
     *
     * Every field that changes WHICH rows come back is in the key and nothing
     * else is — not the count (the pool is fetched at the larger and sliced),
     * not the layout, not the heading. A field added to the selection and
     * forgotten here would silently serve one instance another's products,
     * which is why `GridSectionQueryCostTest` asserts the two presets share a
     * query AND that two different brands do not.
     */
    private static function specKey(GridSection $section): string
    {
        return implode('|', [
            (string) $section->source,
            (string) (int) $section->source_brand_id,
            (string) (int) $section->source_category_id,
            $section->include_children ? '1' : '0',
            $section->source === 'manual'
                ? implode(',', array_map('intval', (array) ($section->manual_ids ?? [])))
                : '',
        ]).($section->source === 'query' ? '|'.ProductSource::spec((array) ($section->source_query ?? [])) : '');
    }

    /**
     * The rows one spec asks for, at most `$limit` of them.
     *
     * EVERY BRANCH ENDS IN `orderByDesc('id')`, and that is not decoration.
     * `total_sales` is a counter most of this catalogue shares a handful of
     * values of and `created_at` is identical to the second across an import,
     * so each of these is a LIMIT taken over a tie. `HomeController` records
     * what that costs: a rail whose membership changes on every cache rebuild
     * with no data behind the change.
     */
    private function fetchPool(string $spec, int $limit, ?int $maxFils = null): Collection
    {
        [$source, $brandId, $categoryId, $children, $manual, $query] = array_pad(explode('|', $spec, 6), 6, '');

        $base = Product::query()
            ->select(self::CARD_COLUMNS)
            ->visible()
            ->with('brand:id,name,slug');

        /*
         * ── THE PRICE CEILING, ON THE PRICE THE SHOPPER PAYS ── (Row 55, Lane HA)
         *
         * "K-Beauty Under AED 54". EffectivePrice::whereRange() is the shop's
         * own price facet: the sale price while its window is open, a rule-
         * priced set's derived figure, and — for a VARIABLE parent, whose own
         * `price` column is NULL — the cheapest price its variations charge.
         * `price <= 5400` on the raw column would have dropped every variable
         * product (NULL compares as nothing) and kept a product whose sale
         * price is under the ceiling OUT, because its regular price is over.
         * Null for every caller that does not ask, so the Grid sections
         * instances and their queries are byte-for-byte what they were.
         */
        if ($maxFils !== null && $maxFils > 0) {
            \App\Support\EffectivePrice::whereRange($base, null, $maxFils);
        }

        return match ($source) {
            /*
             * `products_total_sales_index`
             * (2026_10_11_000000_clear_caches_storefront_speed) is the index
             * this walks backwards. Checked, not assumed.
             */
            'bestsellers' => $base->orderByDesc('total_sales')->orderByDesc('id')->limit($limit)->get(),

            /*
             * TRENDING: what moved in the last seven days. trendingScores()
             * answers a capped map of product => score from the shop's own
             * records, and the CASE puts those first; everything else ties at
             * 0 and falls through to best sellers, so a quiet week still draws
             * a full row rather than an empty one.
             */
            'trending' => self::orderTrending($base)->orderByDesc('total_sales')->orderByDesc('id')->limit($limit)->get(),

            /*
             * `products_created_at_index`
             * (2026_10_12_000000_index_storefront_sorts_and_clear_caches).
             * Ordered by `id` as well, which is the primary key and therefore
             * free, and which is what breaks the tie an import creates.
             */
            'newest' => $base->latest('created_at')->orderByDesc('id')->limit($limit)->get(),

            /*
             * ▲ THE ONE SOURCE WITH NO INDEX BEHIND IT, SAID OUT LOUD.
             *
             * There is no index on `sale_price` and this lane did not add one.
             * The filter is `sale_price IS NOT NULL AND sale_price < price` — a
             * comparison BETWEEN TWO COLUMNS, which no single-column index can
             * serve, so an index on `sale_price` would narrow the scan and not
             * remove it. `HomeController`'s own `flash` rail has run exactly
             * this predicate unindexed on this page since it was written, over
             * the same table, and `StorefrontCostTest` has not flagged it.
             * Adding an index to this catalogue's size would be a guess; the
             * honest note is better than a guessed index.
             */
            'onsale' => $base->whereNotNull('sale_price')
                ->whereColumn('sale_price', '<', 'price')
                ->orderByDesc('total_sales')->orderByDesc('id')->limit($limit)->get(),

            'featured' => $base->where('featured', true)
                ->orderByDesc('total_sales')->orderByDesc('id')->limit($limit)->get(),

            'brand' => ((int) $brandId) > 0
                ? $base->where('brand_id', (int) $brandId)
                    ->orderByDesc('total_sales')->orderByDesc('id')->limit($limit)->get()
                : collect(),

            'category' => ((int) $categoryId) > 0
                ? $base->whereHas('categories', fn ($q) => $q->whereIn(
                    'categories.id',
                    $children === '1' ? $this->categoryAndDescendants((int) $categoryId) : [(int) $categoryId]
                ))->orderByDesc('total_sales')->orderByDesc('id')->limit($limit)->get()
                : collect(),

            /*
             * THE MANUAL PICK, IN THE OWNER'S OWN ORDER, AND RE-CHECKED.
             *
             * `whereIn` answers in whatever order the engine likes, so the
             * order is restored in PHP from the stored list — one pass over a
             * map, not a sort per row. `visible()` is still applied, so a
             * product unpublished after it was picked drops out silently rather
             * than appearing on the front page because somebody picked it in
             * March. That is also the re-check the migration header promises:
             * a deleted id simply returns no row.
             */
            'manual' => (function () use ($base, $manual, $limit) {
                $ids = array_values(array_filter(array_map('intval', $manual === '' ? [] : explode(',', $manual))));

                if ($ids === []) {
                    return collect();
                }

                $rows = $base->whereIn('id', array_slice($ids, 0, 48))->get()->keyBy('id');

                return collect($ids)
                    ->map(fn ($id) => $rows->get($id))
                    ->filter()
                    ->take($limit)
                    ->values();
            })(),

            /*
             * Brands and categories, mixed (Lane HC). App\Support\ProductSource
             * carries the shape; this is the one place it becomes SQL, on the
             * same base — visible(), CARD_COLUMNS, the brand eager load — so it
             * is one SELECT whatever the catalogue holds. Brands are ANY-OF and
             * categories are ANY-OF; naming both narrows to products in one of
             * those brands AND one of those categories.
             */
            'query' => $this->applyQuery($base, ProductSource::parse($query), $children === '1')->limit($limit)->get(),

            default => collect(),
        };
    }

    /**
     * The `query` source's filters and order, on a base fetchPool() built.
     * Every order ends in `id DESC`, for the reason fetchPool() gives.
     */
    private function applyQuery($base, array $q, bool $children)
    {
        if ($q['brands'] !== []) {
            $base->whereIn('brand_id', $q['brands']);
        }

        if ($q['cats'] !== []) {
            $ids = $q['cats'];

            if ($children) {
                $ids = array_values(array_unique(array_merge(...array_map(fn (int $c) => $this->categoryAndDescendants($c), $ids))));
            }

            $base->whereHas('categories', fn ($c) => $c->whereIn('categories.id', $ids));
        }

        if ($q['stock']) {
            $base->inStock();
        }

        return match ($q['sort']) {
            'trending' => self::orderTrending($base)->orderByDesc('total_sales')->orderByDesc('id'),
            'newest' => $base->latest('created_at')->orderByDesc('id'),
            'price_asc' => \App\Support\EffectivePrice::orderBy($base, 'asc')->orderByDesc('id'),
            'price_desc' => \App\Support\EffectivePrice::orderBy($base, 'desc')->orderByDesc('id'),
            'onsale' => $base->whereNotNull('sale_price')->whereColumn('sale_price', '<', 'price')
                ->orderByDesc('total_sales')->orderByDesc('id'),
            'featured' => $base->where('featured', true)->orderByDesc('total_sales')->orderByDesc('id'),
            // Integer arithmetic, so MySQL and SQLite shuffle identically and
            // the order holds for the day. The seed is an int this class made.
            'random' => $base->orderByRaw('((products.id * 7919 + '.ProductSource::daySeed().') % 10007)')->orderByDesc('id'),
            default => $base->orderByDesc('total_sales')->orderByDesc('id'),
        };
    }

    /** Trending's scored products first; the caller adds the tie-breaks. */
    private static function orderTrending($base)
    {
        $scores = self::trendingScores();

        if ($scores !== []) {
            $case = 'CASE products.id';

            foreach ($scores as $id => $score) {
                $case .= ' WHEN '.(int) $id.' THEN '.(int) $score;
            }

            $base->orderByRaw($case.' ELSE 0 END DESC');
        }

        return $base;
    }

    /**
     * The rows one product selection asks for — the homepage's own rails
     * (Best Sellers, Trending, Under AED 54) read through this, so they share
     * every source, every tie-break and every index note above with the Grid
     * sections instances rather than carrying a second copy of the queries.
     *                                                        (Row 55, Lane HA)
     *
     * @param  list<int>  $manualIds
     */
    public function pool(string $source, int $brandId, int $categoryId, array $manualIds, int $limit, ?int $maxFils = null, bool $children = false, array $query = []): Collection
    {
        if (! array_key_exists($source, GridSection::SOURCES)) {
            $source = 'bestsellers';
        }

        $parts = [
            $source,
            (string) max(0, $brandId),
            (string) max(0, $categoryId),
            $children ? '1' : '0',
            $source === 'manual' ? implode(',', array_map('intval', $manualIds)) : '',
        ];

        // The sixth part only for `query`, so every other spec — and the
        // cache entry it names — is the string it always was.
        if ($source === 'query') {
            $parts[] = ProductSource::spec($query);
        }

        $spec = implode('|', $parts);

        return $this->fetchPool($spec, max(1, min(48, $limit)), $maxFils);
    }

    /**
     * What "trending" means on this shop, and it is two things it records.
     *
     * ── THE DEFINITION ──────────────────────────────────────────────────────
     *
     *   score = 3 × units ordered in the last 7 days
     *         + 1 × product-page views in the last 7 days
     *
     * UNITS ORDERED: `order_items.quantity` on orders whose status is one of
     * Order::REAL_STATUSES (processing, on hold, shipped, completed — the shop's
     * own definition of an order that happened, shared with the revenue
     * figures) and that are not deleted, placed in the window. A cancelled,
     * failed or abandoned checkout is not demand.
     *
     * VIEWS: App\Support\ProductViews' daily counter. It is a real record but a
     * partial one — it counts product pages that draw "Buy these together" and
     * only for browsers that run JavaScript — which is why it is the lighter
     * weight: a sale is three views' worth of evidence that something is
     * moving. Neither number is invented, and a product nobody ordered or
     * looked at this week scores nothing.
     *
     * A QUIET WEEK FALLS THROUGH TO BEST SELLERS, by the ordering in
     * fetchPool(): the scored products first, then total_sales. That is said in
     * the admin's help line, so the owner is not told a row is "trending" when
     * it is a lifetime ranking.
     *
     * Two grouped queries, each capped at 60 rows — only the head of the list
     * can be ordered by it — and only ever inside a cached build.
     *
     * @return array<int, int>  product id => score, highest first
     */
    public static function trendingScores(): array
    {
        $since = now()->subDays(7);
        $scores = [];

        try {
            $units = DB::table('order_items as oi')
                ->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->whereNull('o.deleted_at')
                ->whereIn('o.status', \App\Models\Order::REAL_STATUSES)
                ->where('o.created_at', '>=', $since->toDateTimeString())
                ->whereNotNull('oi.product_id')
                ->groupBy('oi.product_id')
                ->selectRaw('oi.product_id as pid, SUM(oi.quantity) as n')
                ->orderByDesc('n')
                ->orderBy('oi.product_id')
                ->limit(60)
                ->get();

            foreach ($units as $row) {
                $scores[(int) $row->pid] = ($scores[(int) $row->pid] ?? 0) + 3 * (int) $row->n;
            }

            $views = DB::table(\App\Support\ProductViews::TABLE)
                ->where('day', '>=', $since->toDateString())
                ->groupBy('product_id')
                ->selectRaw('product_id as pid, SUM(views) as n')
                ->orderByDesc('n')
                ->orderBy('product_id')
                ->limit(60)
                ->get();

            foreach ($views as $row) {
                $scores[(int) $row->pid] = ($scores[(int) $row->pid] ?? 0) + (int) $row->n;
            }
        } catch (\Illuminate\Database\QueryException) {
            // A table not migrated yet answers "nothing is trending", and the
            // row falls through to best sellers rather than taking the page down.
            return [];
        }

        arsort($scores);

        return array_slice($scores, 0, 60, true);
    }

    /**
     * A category's id and every id beneath it.
     *
     * ONE QUERY over `id, parent_id` and a walk in PHP, rather than a `path
     * LIKE 'x/%'`. Two reasons, both checked: `categories.path` is nullable and
     * `Category::url()` still falls through to `buildPath()` for a row that has
     * none, so a LIKE would silently miss exactly the rows an older import
     * left; and a LIKE on a leading wildcard-free pattern is still a scan on a
     * table with no index on `path`. This table is tens of rows — production
     * nests four deep, per `Category::buildPath()`'s own note — so one read and
     * a walk is both correct and cheaper.
     *
     * The `$guard` is the same depth guard `buildPath()` carries, for the same
     * reason: a row that is its own ancestor is a loop, and a hand-edited
     * `parent_id` can make one.
     *
     * @return list<int>
     */
    private function categoryAndDescendants(int $id): array
    {
        $all = Category::query()->select('id', 'parent_id')->get();

        $byParent = [];

        foreach ($all as $row) {
            $byParent[(int) $row->parent_id][] = (int) $row->id;
        }

        $out = [$id];
        $frontier = [$id];
        $guard = 0;

        while ($frontier !== [] && $guard++ < 10) {
            $next = [];

            foreach ($frontier as $parent) {
                foreach ($byParent[$parent] ?? [] as $child) {
                    if (! in_array($child, $out, true)) {
                        $out[] = $child;
                        $next[] = $child;
                    }
                }
            }

            $frontier = $next;
        }

        return $out;
    }

    /* ═══════════════════════════ what the shop renders ═════════════════════ */

    /**
     * The skin an instance's grid carries.
     *
     * '' means "whatever the shop is set to", which is what
     * `GridSkins::resolve(null)` already answers — so this is one line rather
     * than a second fallback ladder, and an unknown name stored by a hand-rolled
     * POST falls back the same way every other skin reader in the shop falls
     * back.
     */
    public static function skinFor(GridSection $section): string
    {
        $skin = (string) ($section->skin ?? '');

        return GridSkins::resolve($skin !== '' && GridSkins::exists($skin) ? $skin : null);
    }

    /**
     * The "View all" address, scheme-checked, or '' for "draw no button".
     *
     * CLAUDE.md rule 5 names this case by itself: "The 'View all' link is a URL
     * from a setting and must be scheme-checked before it becomes an href".
     * `SafeUrl::href()` is the helper that exists for it, and the second
     * argument is `''` rather than its default `#` deliberately — a refused
     * link here means DRAW NOTHING, the way `SafeUrl::src()` does for a
     * picture, because a "View all" button that goes to `#` is a button that
     * looks live and does nothing.
     */
    public static function viewAllHref(GridSection $section): string
    {
        if (! $section->show_view_all) {
            return '';
        }

        return SafeUrl::href((string) ($section->view_all_url ?? ''), '');
    }

    /**
     * The stylesheet every instance shares, or '' when none will render.
     *
     * ── WHY IT IS INLINE AND NOT resources/css ──────────────────────────────
     *
     * `HomepageSections::orderStyle()` already argues this and the argument is
     * unchanged: the storefront serves BUILT css, `@vite()` resolves to a
     * hashed file under a web root that is a DIFFERENT DIRECTORY from the
     * application, and building it is a manual step nobody runs during an
     * update (`package.json` defines no `build` script — CLAUDE.md, Known
     * gaps). A rule added to `kbb.css` therefore ships INERT until somebody
     * rebuilds the bundle, and a section that silently draws itself unstyled is
     * worse than one that does not draw at all. Emitted here it is part of the
     * page and cannot be stale — and this lane touches no file under
     * `resources/css/`, so `BuiltCssSelectorsAreCurrentTest` has nothing to
     * compare and `public/build` needs no rebuild.
     *
     * ── EVERY BYTE OF IT IS A CONSTANT ──────────────────────────────────────
     *
     * Rule 5: "Anything printed unescaped is a constant, never a setting."
     * There is not one interpolation in the string below. The per-instance
     * NUMBERS — the column counts — arrive as custom properties in each
     * section's own `style` attribute, through `{{ }}`, as integers that have
     * already been through `ModuleSchema::cast()` against
     * `GridSection::DESKTOP_COLS`. The stylesheet reads them with `var()` and
     * never sees a string.
     *
     * ── AND WHY THE COUNTS ARE `var()` BUT THE SURPLUS IS A CLASS ───────────
     *
     * `grid-template-columns:repeat(var(--gs-d),…)` works. `:nth-child(var(…))`
     * does not exist — a custom property cannot appear in a selector — so
     * "show 10 on desktop and 6 on the phone" cannot be a rule parameterised by
     * a number. The surplus cards are given a class by the template instead
     * (`gs-d-only` / `gs-m-only`), which is a constant selector over a computed
     * class and needs no per-instance CSS at all.
     *
     * ── NO JAVASCRIPT, AND THEREFORE NO ARROWS ──────────────────────────────
     *
     * Rule 4 forbids JavaScript that measures layout, and a previous/next arrow
     * is the single most tempting place in this codebase to reach for
     * `clientWidth`. The carousel is `scroll-snap` over a `calc()` track and
     * the partial carries NO `<script>` at all — `GridSectionShapeTest` asserts
     * that by name against the same list `CardsBannerSectionShapeTest` uses.
     * The affordance is the peek of the next card, which is the same affordance
     * `.kbb-home .rail` has used on this page since it was written.
     *
     * ── ARABIC ──────────────────────────────────────────────────────────────
     *
     * Every inline axis here is a LOGICAL property — `padding-inline`,
     * `margin-inline`, `scroll-padding-inline-start`, `text-align:start` — so a
     * flex row inside `dir="rtl"` lays out and SCROLLS the other way with no
     * `[dir]` selector anywhere in this string. That is the whole RTL story for
     * this section and it is one that cannot rot, because there is no
     * direction-specific rule to keep in step.
     */
    public static function css(): string
    {
        return '<style>'
            /* The band. `sec`'s own padding is kept; only the inner parts are new. */
            .'.kbb-gsec .gs-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:14px}'
            .'.kbb-gsec .gs-head h2{font-size:clamp(19px,2.2vw,26px);line-height:1.15;margin:0}'
            .'.kbb-gsec .gs-head p{margin:6px 0 0;font-size:13.5px;color:#8C828A;max-width:52ch}'
            .'.kbb-gsec .gs-foot{display:flex;justify-content:center;margin-top:18px}'
            .'.kbb-gsec .gs-all{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line-2,#EFE3E8);'
                .'border-radius:999px;padding:11px 26px;font-size:13.5px;font-weight:600;color:var(--ink,#2A2228);'
                .'background:#fff;text-decoration:none;transition:.18s}'
            .'.kbb-gsec .gs-all:hover{border-color:var(--pink-deep,#C13E63);color:var(--pink-deep,#C13E63)}'

            /*
             * ── THE CELL, AND WHY EVERY TILE HAS ONE ────────────────────────
             *
             * The surplus cards need a class, and the card is
             * components/product-card.blade.php — Lane PG2's file, and not
             * this lane's to give an attribute to. A wrapper is the honest
             * answer, and it is put round EVERY tile rather than only the
             * surplus so that the markup shape does not depend on the numbers.
             *
             * `display:flex` on it is not decoration. `.kbb-pgrid .kbb-card`
             * is `height:100%`, which resolves against the GRID ITEM; move the
             * card inside a plain block wrapper and that 100% resolves against
             * an auto height and the equal-height column the tile was built
             * with is gone. A flex cell stretches its one child instead, so the
             * cards line up exactly as they do in every other grid on the shop.
             * `min-width:0` because a grid item's default min-width is auto and
             * this project has already shipped that defect once, on Coupons.
             */
            .'.kbb-gsec .gs-cell{display:flex;min-width:0}'
            .'.kbb-gsec .gs-cell>*{flex:1;min-width:0}'

            /*
             * THE COLUMN PINS ARE RULES, NEVER A CUSTOM PROPERTY THAT HAS TO BE
             * UNDONE. kbb.css:~215 records what the other way costs: `initial`
             * on a custom property is the guaranteed-invalid value rather than
             * "go back to the inherited one", so a phone breakpoint that tried
             * to revert a track property produced `grid-template-columns:none`
             * and one card per row on every phone. Each width therefore SETS
             * its own `grid-template-columns` and there is nothing to revert.
             */
            .'@media (max-width:900px){'
                .'.kbb-gsec .gs-grid{grid-template-columns:repeat(var(--gs-m,2),minmax(0,1fr))}'
                .'.kbb-gsec .gs-d-only{display:none}'
                /* The carousel, at this width only. */
                /*
                 * ▲ `scroll-padding-inline-start` MATCHES THE PADDING, AND IT
                 * READ 0 UNTIL IT WAS MEASURED.
                 *
                 * The row bleeds to the screen edges — `padding-inline` of one
                 * gutter, `margin-inline` of minus one — so the first card
                 * starts one gutter in. With `scroll-snap-type:x mandatory` and
                 * a scroll padding of 0, the browser snaps the first card's
                 * START EDGE to the scrollport's, which means it scrolls one
                 * gutter on load and EATS the left margin: measured at 390px,
                 * `grid.scrollLeft` was 22 at rest on a row nobody had touched.
                 * Matching the padding puts the snap position back at 0 and the
                 * gutter back on the screen.
                 *
                 * `inline`, not `left`: on /ar the same declaration is the
                 * RIGHT-hand gutter, with no second rule to keep in step.
                 */
                .'.kbb-gsec .gs-grid.gs-car-m{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;'
                    .'scroll-padding-inline-start:var(--site-gutter,18px);scrollbar-width:none;'
                    .'padding-inline:var(--site-gutter,18px);margin-inline:calc(var(--site-gutter,18px) * -1);'
                    .'padding-bottom:6px}'
                .'.kbb-gsec .gs-grid.gs-car-m::-webkit-scrollbar{display:none}'
                /*
                 * ONE calc() AND NOTHING MEASURES ANYTHING. `--gs-m` whole
                 * cards, `--gs-m - 1` gaps between them, and `--gs-peek` of the
                 * next one showing so the row reads as scrollable without an
                 * arrow. Identical in shape to the track arithmetic kbb.css
                 * already divides every product grid by.
                 */
                .'.kbb-gsec .gs-grid.gs-car-m>*{scroll-snap-align:start;'
                    .'flex:0 0 calc((100% - (var(--gs-m,2) - 1 + var(--gs-peek-m,var(--gs-peek,.28))) * var(--kbb-gap,14px))'
                    .' / (var(--gs-m,2) + var(--gs-peek-m,var(--gs-peek,.28))))}'
            .'}'
            .'@media (min-width:901px){'
                .'.kbb-gsec .gs-grid{grid-template-columns:repeat(var(--gs-d,4),minmax(0,1fr))}'
                .'.kbb-gsec .gs-m-only{display:none}'
                .'.kbb-gsec .gs-grid.gs-car-d{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;'
                    .'scrollbar-width:none;padding-bottom:8px}'
                .'.kbb-gsec .gs-grid.gs-car-d::-webkit-scrollbar{height:6px}'
                .'.kbb-gsec .gs-grid.gs-car-d::-webkit-scrollbar-thumb{background:var(--line-2,#EFE3E8);border-radius:999px}'
                .'.kbb-gsec .gs-grid.gs-car-d>*{scroll-snap-align:start;'
                    .'flex:0 0 calc((100% - (var(--gs-d,4) - 1 + var(--gs-peek-d,var(--gs-peek,.28))) * var(--kbb-gap,14px))'
                    .' / (var(--gs-d,4) + var(--gs-peek-d,var(--gs-peek,.28))))}'
                .'.kbb-gsec .gs-noarr-d .gs-arr{display:none}'
            .'}'
            /*
             * Lane HC: the arrows, for a carousel that has them switched on.
             * The scrolling is resources/js/kbb/ymal.js — the same module the
             * bundles and Spotted carousels use — and this section ships no
             * script of its own. Hidden per device by a class the partial sets.
             */
            .'.kbb-gsec .gs-stage{position:relative}'
            .'.kbb-gsec .gs-arr{position:absolute;top:38%;z-index:2;width:40px;height:40px;border-radius:50%;border:1px solid var(--line-2,#EFE3E8);'
                .'background:#fff;color:#2A2228;box-shadow:0 6px 18px rgba(42,34,40,.14);display:grid;place-items:center;cursor:pointer;padding:0}'
            .'.kbb-gsec .gs-arr svg{width:18px;height:18px}.kbb-gsec .gs-arr:disabled{opacity:.35;cursor:default}'
            .'.kbb-gsec .gs-prev{inset-inline-start:-10px}.kbb-gsec .gs-next{inset-inline-end:-10px}'
            .'@media (max-width:900px){.kbb-gsec .gs-noarr-m .gs-arr{display:none}.kbb-gsec .gs-arr{width:34px;height:34px;inset-inline-start:auto}.kbb-gsec .gs-prev{inset-inline-start:2px}.kbb-gsec .gs-next{inset-inline-end:2px}}'
            /*
             * `scroll-behavior` is the only motion this section has, and it is
             * the browser's own. Under reduced motion it is switched off
             * outright rather than slowed — the rule the UGC rail and the cards
             * banner both follow.
             */
            .'@media (prefers-reduced-motion:reduce){.kbb-gsec .gs-grid{scroll-behavior:auto}}'
            .'</style>';
    }

    /* ═══════════════════════════ the console's side ════════════════════════ */

    /**
     * The option sets the positional SCHEMA has no slot for, and the ADMIN's
     * reader only.
     *
     * Two queries — the brands and the categories — which the screen and the
     * guard can afford and the homepage cannot. That is why `forHome()` casts
     * NOTHING through these: it hands the stored id to the query as a bound
     * parameter and an id that names no row simply returns no products. A
     * deleted brand, a drafted one and a string of nonsense all draw the same
     * empty section, which is the same answer the cast would give for two more
     * queries on the most-hit URL on the site. `Banners::overrides()` makes the
     * identical trade and says so.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function overrides(): array
    {
        $brands = ['' => 'Choose a brand'];

        foreach (\App\Models\Brand::query()->orderBy('name')->get(['id', 'name']) as $b) {
            $brands[(string) $b->id] = (string) $b->name;
        }

        $cats = ['' => 'Choose a category'];

        foreach (Category::query()->orderBy('name')->get(['id', 'name']) as $c) {
            $cats[(string) $c->id] = (string) $c->name;
        }

        $skins = ['' => 'Use the shop’s own grid style'] + GridSkins::ALL;

        return [
            'source_brand_id' => ['options' => $brands],
            'source_category_id' => ['options' => $cats],
            'skin' => ['options' => $skins],
        ];
    }

    /**
     * The normalised fields, from the one schema, through the one policy.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fields(): array
    {
        return ModuleSchema::normalise(self::SCHEMA, self::POLICY, self::overrides());
    }

    /**
     * One instance's controls, as `ModuleSchema::tabs()` emits them — the same
     * call the rest of this console's screens are drawn by.
     *
     * @return list<array<string, mixed>>
     */
    public static function tabsFor(GridSection $section): array
    {
        return ModuleSchema::tabs(self::SCHEMA, self::TABS, self::valuesOf($section), self::POLICY, self::overrides());
    }

    /**
     * An instance's stored values in SCHEMA's own shape.
     *
     * The four selects come back as STRINGS because that is what a select's
     * option keys are and `ModuleSchema::cast()` compares against the key; an
     * int here would be compared as an int, match nothing, and every one of
     * them would silently fall back to its default the first time the screen
     * round-tripped.
     *
     * @return array<string, mixed>
     */
    public static function valuesOf(GridSection $section): array
    {
        return [
            'name' => (string) $section->name,
            'status' => (string) $section->status,
            'show_heading' => (bool) $section->show_heading,
            'heading' => (string) $section->heading,
            'subheading' => (string) $section->subheading,
            'source' => (string) $section->source,
            'source_brand_id' => $section->source_brand_id ? (string) $section->source_brand_id : '',
            'source_category_id' => $section->source_category_id ? (string) $section->source_category_id : '',
            'include_children' => (bool) $section->include_children,
            'count' => (int) $section->count,
            'mobile_count' => (int) $section->mobile_count,
            'desktop_layout' => (string) $section->desktop_layout,
            'desktop_cols' => (string) $section->desktop_cols,
            'mobile_layout' => (string) $section->mobile_layout,
            'mobile_cols' => (string) $section->mobile_cols,
            'per_d' => (string) ($section->per_d ?? ''),
            'per_m' => (string) ($section->per_m ?? '2.3'),
            'arrows_d' => (bool) ($section->arrows_d ?? true),
            'arrows_m' => (bool) ($section->arrows_m ?? false),
            'skin' => (string) $section->skin,
            'card_label' => (string) $section->card_label,
            'show_rank' => (bool) $section->show_rank,
            'show_view_all' => (bool) $section->show_view_all,
            'view_all_label' => (string) $section->view_all_label,
            'view_all_url' => (string) $section->view_all_url,
        ];
    }
}
