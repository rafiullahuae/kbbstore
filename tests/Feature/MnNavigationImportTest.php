<?php

/*
 * THE NAVIGATION, END TO END: the plugin's own export into this shop's real
 * importer, and out again as the header a shopper sees.
 *
 * ============================================================================
 * WHY THIS FILE IS THE DELIVERABLE
 * ============================================================================
 *
 * `docs/IE-IMPORT-READINESS.md` §5.1 is the owner's standing loss: *"The
 * navigation menu is retyped. Nothing imports it. The `menus` and `menu_items`
 * tables have `source_term_id` and `source_post_id` columns waiting, so this is
 * a gap a later lane can close — but it is not closed, and on cutover night the
 * header and the mobile drawer are entered by hand."*
 *
 * Closing it is a claim about TWO systems, so every test below runs over
 * `tests/Fixtures/kbb-export/` — a REAL export written by the plugin's own stage
 * classes over WordPress-shaped MySQL tables — and feeds it to
 * `App\Services\Import\ImportRunner`, the class `kbb:import` runs. No test
 * double anywhere in the path, and no hand-typed CSV standing in for one the
 * plugin would have written.
 *
 * ── THE THREE QUESTIONS, AND WHICH TEST ANSWERS EACH ────────────────────────
 *
 *   WHERE DOES EACH POINTER LAND?  `it resolves every kind of pointer …`
 *   WHAT ABOUT ONE THAT CANNOT?    `it parks an item whose target …` and the
 *                                  three tests under it
 *   IS IT SAFE TO RUN TWICE?       `it imports the same export twice …` and
 *                                  `it leaves a menu the owner typed …`
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Post;
use App\Models\Product;
use App\Services\Import\Entities\MenuItemImporter;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportRunner;
use App\Services\ImportConsole\ImportWorkspace;
use App\Services\NavigationService;
use App\Support\UrlScheme;
use Illuminate\Support\Facades\Cache;

function mnExportDir(): string
{
    return base_path('tests/Fixtures/kbb-export');
}

function mnManifest(): array
{
    return json_decode((string) file_get_contents(mnExportDir().'/manifest.json'), true);
}

/**
 * The whole export, imported the way the runbook says to import it.
 *
 * The WHOLE export and not `--only=menus,menu-items`, because the navigation is
 * the one entity whose correctness is entirely about the others: a category
 * item resolves against `categories.source_term_id`, which only CategoryImporter
 * writes. A slice would test the resolution against an empty shop and report
 * seven parked items as a pass.
 */
function mnImport(array $overrides = []): App\Services\Import\ImportReport
{
    $manifest = mnManifest();

    $options = new ImportOptions(...array_merge([
        'directory' => mnExportDir(),
        'sourceTimezone' => $manifest['source']['timezone'],
        // The demo catalogue every install ships holds the slugs `cosrx` and
        // `beauty-of-joseon`, which are real brands this shop really sells.
        // WooImportTest and GeWpExporterTest pass this for the same reason.
        'adoptBySlug' => true,
        /*
         * A FRESH RUN KEY PER CALL, and it is not a detail. `import_checkpoints`
         * is keyed on (runKey, entity), so a second call under the same key
         * RESUMES -- it skips every row the first call committed and reports
         * nothing at all. Every idempotence test below would then be asserting
         * that a run which read no rows wrote no rows.
         */
        'runKey' => 'mn-'.bin2hex(random_bytes(4)),
    ], $overrides));

    return (new ImportRunner)->run($options);
}

/**
 * Mount a menu on the desktop header, the way the admin screen mounts one.
 *
 * `MegaMenuApiController::updateMenu()` steals the slot from whoever holds it,
 * because exactly one menu can hold a given slot. Every install ships the
 * seeded `K-Beauty Bliss Menu` with show_desktop = true and
 * `NavigationService::menu()` takes the FIRST match, so a test that only sets
 * its own flag is reading the seeded menu and asserting nothing.
 */
function mnMount(Menu $menu): void
{
    Menu::query()->where('show_desktop', true)->update(['show_desktop' => false]);

    $menu->update(['show_desktop' => true]);

    app(NavigationService::class)->flush();
}

/** Every imported item, keyed by its WordPress post id. */
function mnItems(): array
{
    $out = [];

    foreach (MenuItem::query()->whereNotNull('source_post_id')->get() as $item) {
        $out[(int) $item->source_post_id] = $item;
    }

    return $out;
}

/** Every label in a rendered tree, parents and children, in render order. */
function mnLabels(array $tree): array
{
    $out = [];

    foreach ($tree as $node) {
        $out[] = $node['label'];

        foreach (mnLabels($node['children'] ?? []) as $child) {
            $out[] = $child;
        }
    }

    return $out;
}

/** Every adjustment sample this entity produced, flattened. */
function mnAdjustmentSamples(App\Services\Import\ImportReport $report, string $entity): array
{
    $out = [];

    foreach ($report->for($entity)->adjustments() as $adjustment) {
        foreach ($adjustment['samples'] ?? [] as $sample) {
            $out[] = ($sample['before'] ?? '').' => '.($sample['after'] ?? '');
        }
    }

    return $out;
}

/*
|--------------------------------------------------------------------------
| The export end
|--------------------------------------------------------------------------
*/

it('carries the navigation in two files the contract names, and the plugin says which build wrote them', function () {
    /*
     * THE THREE COPIES OF THE VERSION, PINNED TOGETHER. The plugin header is
     * what WordPress shows on Plugins; KBB_EXPORTER_VERSION is what the code
     * reads; KBB_Export_Runner::PLUGIN_VERSION is the only one that reaches
     * manifest.json. The 1.5.0 changelog entry claims a guard covers this and
     * for one release it covered two of the three, so every export written by
     * the 1.5.0 plugin said 1.0.0.
     *
     * MUTATION NOTE — BOTH HALVES RUN. Setting PLUGIN_VERSION back to '1.6.0'
     * while leaving the header at 1.7.0 fails on the PLUGIN_VERSION line;
     * setting the header back to 1.6.0 while leaving the other two fails on the
     * header line. Two literals, two failures, which is the point of there
     * being three copies.
     */
    $header = (string) file_get_contents(base_path('wordpress-plugin/kbb-exporter/kbb-exporter.php'));
    $runner = (string) file_get_contents(base_path('wordpress-plugin/kbb-exporter/includes/class-kbb-export-runner.php'));

    expect(str_contains($header, 'Version:           1.7.0'))->toBeTrue('the plugin header is not at 1.7.0');
    expect(str_contains($header, "define( 'KBB_EXPORTER_VERSION', '1.7.0' );"))->toBeTrue('KBB_EXPORTER_VERSION is not at 1.7.0');
    expect(str_contains($runner, "const PLUGIN_VERSION = '1.7.0';"))->toBeTrue('PLUGIN_VERSION is not at 1.7.0');

    $manifest = mnManifest();

    expect($manifest['source']['plugin_version'])->toBe('1.7.0');

    // Both files are described, with their row counts, and the contract's rule
    // is that `rows` excludes the header.
    expect($manifest['files']['menus.csv']['rows'])->toBe(1);
    expect($manifest['files']['menu_items.csv']['rows'])->toBe(7);

    foreach (['menus.csv', 'menu_items.csv'] as $file) {
        $lines = file(mnExportDir().'/'.$file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        expect(count($lines) - 1)->toBe($manifest['files'][$file]['rows'], $file.': the manifest disagrees with the file');
        expect(hash_file('sha256', mnExportDir().'/'.$file))->toBe($manifest['files'][$file]['sha256'], $file.': the manifest disagrees with the bytes');
    }

    // And the group that carries them, so an export with Navigation unticked
    // carries neither file rather than two empty ones.
    expect(in_array('navigation', $manifest['groups']['selected'], true))->toBeTrue();
});

it('resolves the label off the object when the owner never typed one', function () {
    /*
     * THE THING THAT WAS MEASURED RATHER THAN ASSUMED. WordPress writes
     * `post_title` on a nav_menu_item only when the owner types a label OVER
     * the default; leave the box alone and the column is the empty string and
     * the theme prints the target's own name. Most items on a real menu are
     * that shape.
     *
     * `menu_items.label` on this shop is NOT NULL, so an export emitting
     * post_title would have produced a file most of whose rows this importer
     * REFUSES — which is the failure that would have been found on the owner's
     * server rather than here.
     *
     * MUTATION NOTE — RAN, THROUGH THE PLUGIN ITSELF. Changing
     * KBB_Export_Stage_Menu_Items::batch()'s `$label` to the item's own
     * `post_title` and re-running wordpress-plugin/harness/run-export.php
     * produces a menu_items.csv with THREE EMPTY LABELS — 7504, 7505 and 7507,
     * the three the owner never renamed. Importing that export reports exactly
     * three rejections from `menu-items`, because `menu_items.label` is NOT
     * NULL. Measured against a real export, not asserted.
     */
    $rows = array_map('str_getcsv', file(mnExportDir().'/menu_items.csv', FILE_IGNORE_NEW_LINES));
    $head = array_shift($rows);

    $bySource = [];

    foreach ($rows as $row) {
        $bySource[(int) $row[array_search('id', $head, true)]] = array_combine($head, $row);
    }

    // 7504 is a category item the owner never renamed.
    expect($bySource[7504]['label'])->toBe('Face Cleansers');
    expect($bySource[7504]['label_source'])->toBe('object');

    // 7506 is a product item he DID rename, and the typed label wins.
    expect($bySource[7506]['label'])->toBe('Our hero serum');
    expect($bySource[7506]['label_source'])->toBe('item');

    // And `classes` is a comma list rather than the serialised array WordPress
    // stores, so nothing downstream unserialises a file this shop did not write.
    expect($bySource[7503]['classes'])->toBe('menu-sale,menu-highlight');
    expect(str_contains($bySource[7503]['classes'], 'a:2:'))->toBeFalse('the serialised blob reached the CSV');
});

/*
|--------------------------------------------------------------------------
| The resolution rule
|--------------------------------------------------------------------------
*/

it('imports the plugin\'s navigation with nothing refused', function () {
    $report = mnImport();

    $rejections = [];

    foreach (['menus', 'menu-items'] as $entity) {
        foreach ($report->for($entity)->rejections() as $rejection) {
            $rejections[] = $entity.' line '.$rejection['line'].': '.$rejection['reason'];
        }
    }

    expect($rejections)->toBe([], implode(' | ', $rejections));

    // Rows in, rows out. 1 menu and 7 items in the export; 1 menu and 7 items
    // in the database, the whole point being that the parked one is IN.
    expect(Menu::query()->whereNotNull('source_term_id')->count())->toBe(1);
    expect(MenuItem::query()->whereNotNull('source_post_id')->count())->toBe(7);
});

it('resolves every kind of pointer to this shop\'s own address, through UrlScheme', function () {
    /*
     * THE TABLE. Each row is a different table on this shop and a different
     * address shape, and every expected address is DERIVED through
     * App\Support\UrlScheme rather than spelled — which is the assertion that
     * actually catches the hazard. The scheme moved in the last round
     * (`/product-category/` became `/collections/`), and UrlScheme's own header
     * names "two menu builders" among the ten writers of the old spelling that
     * it exists to replace. A test that spelled `/collections/skincare/` would
     * pass against a menu builder that had been left behind on the next move.
     *
     * MUTATION NOTE — RAN. Replacing UrlScheme::collection($category->path)
     * with UrlScheme::collection($category->slug) in MenuItemImporter fails the
     * `face-cleansers` line: the nested category's address is
     * /collections/skincare/face-cleansers/ and the slug alone gives
     * /collections/face-cleansers/, which 404s.
     */
    mnImport();

    $items = mnItems();

    $skincare = Category::query()->where('source_term_id', 15)->firstOrFail();
    $cleansers = Category::query()->where('source_term_id', 22)->firstOrFail();
    $brand = Brand::query()->where('source_term_id', 502)->firstOrFail();
    $product = Product::query()->where('wc_id', 4021)->firstOrFail();
    $article = Post::query()->where('source_post_id', 7001)->firstOrFail();

    // The nested one is the interesting one: its address is its PATH.
    expect($cleansers->path)->toBe('skincare/face-cleansers');

    $expected = [
        //  source     label                              url                                        target_type  target_id
        7501 => ['Skincare',                        UrlScheme::collection((string) $skincare->path),  'category', (int) $skincare->id],
        7504 => ['Face Cleansers',                  UrlScheme::collection((string) $cleansers->path), 'category', (int) $cleansers->id],
        7505 => ['Beauty of Joseon',                UrlScheme::brand((string) $brand->slug),          'brand',    (int) $brand->id],
        7506 => ['Our hero serum',                  UrlScheme::product((string) $product->slug),      'product',  (int) $product->id],
        7507 => ['How to layer a K-beauty routine', UrlScheme::article((string) $article->slug),      'article',  (int) $article->id],
        7503 => ['Sale',                            'https://kbeautybliss.com/super-sale/',           'custom',   null],
        // The one this shop refuses. See the parking tests below.
        7502 => ['About us',                        null,                                             MenuItemImporter::UNRESOLVED, null],
    ];

    foreach ($expected as $source => [$label, $url, $type, $targetId]) {
        expect(array_key_exists($source, $items))->toBeTrue('menu item '.$source.' is not in the database at all');

        $item = $items[$source];

        expect($item->label)->toBe($label, 'item '.$source.' label');
        expect($item->url)->toBe($url, 'item '.$source.' url');
        expect($item->target_type)->toBe($type, 'item '.$source.' target_type');
        expect($item->target_id === null ? null : (int) $item->target_id)->toBe($targetId, 'item '.$source.' target_id');
    }

    // The addresses this shop really serves, spelled once here so the table
    // above cannot be a tautology over a UrlScheme that has itself moved.
    expect($items[7504]->url)->toBe('/collections/skincare/face-cleansers/');
    expect($items[7505]->url)->toBe('/brands/beauty-of-joseon/');
    expect($items[7506]->url)->toBe('/product/serum-4021/');
    expect($items[7507]->url)->toBe('/blog/how-to-layer-a-k-beauty-routine/');
});

it('resolves a brand on the WordPress id and not on the name of its taxonomy', function () {
    /*
     * THE ASSERTION THE RESOLUTION RULE RESTS ON. BrandImporter's header: "this
     * shop's brands are not a brands taxonomy at all -- they are terms of the
     * `pa_brands` product ATTRIBUTE, 93 of them", and the taxonomy a given shop
     * keeps brands in is read off the shop rather than known. An importer
     * branching on the string `pa_brands` would place every brand item on this
     * fixture and none on a shop running `product_brand` or `yith_product_brand`
     * -- silently, because "no brand of that taxonomy" and "no brand" look
     * identical from the importer.
     *
     * A WordPress term id is unique across every taxonomy, so the id is exact
     * everywhere. This proves the branch does not read `object` by changing
     * ONLY `object` on a row that must still resolve.
     *
     * MUTATION NOTE — RAN. Adding `if ($object !== 'pa_brands') { return
     * $this->park(...); }` to the taxonomy branch of MenuItemImporter::resolve()
     * makes this test's second half park the item and fail.
     */
    mnImport();

    expect(mnItems()[7505]->target_type)->toBe('brand');

    // The same row, from a shop whose brands live in a taxonomy this importer
    // has never heard of. Everything else about it is unchanged.
    $dir = sys_get_temp_dir().'/kbb-mn-taxonomy-'.bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);

    foreach (glob(mnExportDir().'/*') as $file) {
        copy($file, $dir.'/'.basename($file));
    }

    $rewritten = str_replace('"pa_brands"', '"totally_made_up_brand_taxonomy"', (string) file_get_contents($dir.'/menu_items.csv'));
    expect(str_contains($rewritten, 'totally_made_up_brand_taxonomy'))->toBeTrue('the fixture no longer spells pa_brands the way this test rewrites it');
    file_put_contents($dir.'/menu_items.csv', $rewritten);

    MenuItem::query()->whereNotNull('source_post_id')->delete();

    mnImport(['directory' => $dir]);

    expect(mnItems()[7505]->target_type)->toBe('brand', 'the brand branch is reading the taxonomy name');
    expect(mnItems()[7505]->url)->toBe('/brands/beauty-of-joseon/');

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);
});

it('keeps the two levels, the order and the new-tab flag', function () {
    /*
     * `menu_items.parent_id` is a self-referencing key and a flat menu never
     * exercises it. `_menu_item_menu_item_parent` is a nav_menu_item POST id,
     * so it has to be translated the same way every other pointer is.
     *
     * MUTATION NOTE — RAN. Writing `$attributes['parent_id'] = $parentSourceId`
     * (the WordPress id, untranslated) in MenuItemImporter::import() fails here
     * with a foreign-key violation on MySQL and with three orphaned children on
     * SQLite — the children vanish from the tree entirely, which is exactly the
     * silent shape this asserts against.
     */
    mnImport();

    $items = mnItems();

    expect($items[7504]->parent_id)->toBe($items[7501]->id, 'Face Cleansers is not under Skincare');
    expect($items[7505]->parent_id)->toBe($items[7501]->id);
    expect($items[7506]->parent_id)->toBe($items[7501]->id);

    foreach ([7501, 7502, 7503, 7507] as $top) {
        expect($items[$top]->parent_id)->toBeNull('item '.$top.' should be top level');
    }

    // WordPress's menu_order, unchanged, so the header reads in the owner's
    // order rather than in id order.
    expect([$items[7501]->position, $items[7502]->position, $items[7507]->position, $items[7503]->position])
        ->toBe([1, 2, 3, 4]);
    expect([$items[7504]->position, $items[7505]->position, $items[7506]->position])->toBe([1, 2, 3]);

    // `_menu_item_target` = '_blank' is the one WordPress flag this schema has
    // a column for.
    expect((bool) $items[7503]->new_tab)->toBeTrue('the custom link should open in a new tab');
    expect((bool) $items[7501]->new_tab)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The third answer: imported, parked, and named
|--------------------------------------------------------------------------
*/

it('parks an item whose target this shop refuses, instead of dropping it or linking it', function () {
    /*
     * THE WHOLE QUESTION. "About us" points at WordPress page 7002, and
     * PostImporter refuses every WordPress page BY NAME because this shop ships
     * its own /about/. Dropping the row loses something the owner authored;
     * keeping it as a link puts a 404 in the header of every page of the shop.
     *
     * The third answer is that the row is written, complete, and is not
     * rendered. This asserts BOTH halves, because either one alone is one of
     * the two wrong answers.
     *
     * MUTATION NOTE — RAN. Returning `['url' => '/about-us/', 'target_type' =>
     * 'custom', 'target_id' => null]` from park() — the dead-link answer —
     * leaves the first half green and fails the second: the item appears in the
     * rendered tree pointing at an address this shop 404s.
     */
    $report = mnImport();

    $items = mnItems();

    // HALF ONE: the row is here, whole.
    expect(array_key_exists(7502, $items))->toBeTrue('the item the shop cannot place was dropped');
    expect($items[7502]->label)->toBe('About us');
    expect($items[7502]->position)->toBe(2);
    expect($items[7502]->menu_id)->toBe(Menu::query()->where('source_term_id', 950)->value('id'));
    expect($items[7502]->url)->toBeNull('a parked item must not carry a guessed address');
    expect($items[7502]->target_type)->toBe(MenuItemImporter::UNRESOLVED);

    // HALF TWO: and it is not in the menu anybody sees.
    mnMount(Menu::query()->where('source_term_id', 950)->firstOrFail());

    $labels = mnLabels(app(NavigationService::class)->menu('primary'));

    expect(in_array('Skincare', $labels, true))->toBeTrue('the imported menu is not being rendered at all');
    expect(in_array('About us', $labels, true))->toBeFalse('the parked item reached the header');

    // HALF THREE: and the owner is told, by name, with what it pointed at.
    $samples = mnAdjustmentSamples($report, 'menu-items');

    $named = array_values(array_filter($samples, fn (string $s): bool => str_contains($s, 'About us')));

    expect($named)->not->toBe([], 'the parked item is not named in the report: '.implode(' | ', $samples));
    expect(str_contains($named[0], 'page 7002'))->toBeTrue('the report does not say what it pointed at: '.$named[0]);
    expect(str_contains($named[0], 'about-us'))->toBeTrue('the report does not carry the old slug: '.$named[0]);
});

it('lands WordPress\'s Shop archive item on this shop\'s catalogue, instead of parking it', function () {
    /*
     * THE ITEM THE PREVIOUS ROUND COULD NOT PLACE, AND IT IS THE SHOP'S OWN
     * FRONT DOOR.
     *
     * WooCommerce's "Shop" menu entry is a `post_type_archive` item pointing at
     * the `product` post type. It is the ONE pointer in this export with no id
     * to resolve on -- `_menu_item_object_id` is 0, because there is no post
     * and no term behind an archive, only a post TYPE -- so it fell past the
     * taxonomy and post_type branches into custom(), found no
     * `_menu_item_url` either, and was PARKED. The owner imported his
     * navigation and the header came up without Shop on it, with the row
     * sitting on the Mega Menu screen waiting for him to type an address the
     * shop already knows.
     *
     * THE ADDRESS COMES FROM UrlScheme AND NOT A LITERAL, asserted here against
     * the constant, because a literal `/shop/` in an importer is a sixth writer
     * of an address the scheme class exists to own.
     *
     * The fixture has no archive row -- the plugin harness's menu is seven
     * items and none of them is Shop -- so the Shop row is written over the
     * "About us" line IN A COPY, the same way the brand-taxonomy case above
     * rewrites `pa_brands`. Everything else about the export is untouched.
     *
     * MUTATION NOTE -- RAN. Delete the `post_type_archive` branch from
     * MenuItemImporter::resolve() and this is red on the first expectation:
     * target_type is `unresolved` and url is NULL, which is the defect exactly.
     */
    $dir = sys_get_temp_dir().'/kbb-mn-archive-'.bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);

    foreach (glob(mnExportDir().'/*') as $file) {
        copy($file, $dir.'/'.basename($file));
    }

    $csv = (string) file_get_contents($dir.'/menu_items.csv');

    // The row WordPress writes for a Shop entry: type post_type_archive, object
    // the post type, and object_id 0 because an archive has no row behind it.
    $shop = '"7502","950","0","2","Shop","item","post_type_archive","product","0","","","","","","publish"';
    $rewritten = str_replace(
        '"7502","950","0","2","About us","item","post_type","page","7002","about-us","","","","","publish"',
        $shop,
        $csv,
    );

    expect($rewritten)->not->toBe($csv, 'the fixture no longer spells the About us row the way this test rewrites it');
    file_put_contents($dir.'/menu_items.csv', $rewritten);

    mnImport(['directory' => $dir]);

    $item = mnItems()[7502] ?? null;

    expect($item)->not->toBeNull('the Shop archive row was not imported at all');
    expect($item->target_type)->toBe('shop');
    expect($item->url)->toBe(UrlScheme::shop());
    expect($item->url)->toBe('/shop/', 'UrlScheme::shop() no longer spells the address this shop serves');
    expect($item->target_id)->toBeNull('an archive has no row to point at');

    // AND IT REACHES THE HEADER, which is the whole difference from parked.
    mnMount(Menu::query()->where('source_term_id', 950)->firstOrFail());

    expect(in_array('Shop', mnLabels(app(NavigationService::class)->menu('primary')), true))
        ->toBeTrue('the Shop item resolved but still does not render');

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);
});

it('keeps parking an archive of a post type this shop has no screen for', function () {
    /*
     * THE NARROWNESS IS THE POINT. `product` is WooCommerce's own post type --
     * registered by the plugin, the same four letters on every installation --
     * which is what makes matching on the NAME safe where the class header
     * forbids it for a taxonomy. An archive of anything else is a listing this
     * shop has no screen for, and guessing an address for it would put a 404 in
     * the header, which is the answer the parking mechanism exists to avoid.
     *
     * MUTATION NOTE -- RAN. Widening the branch to `$type === 'post_type_archive'`
     * with no check on $object makes this red: the portfolio archive resolves to
     * /shop/ and renders in the header.
     */
    $dir = sys_get_temp_dir().'/kbb-mn-archive2-'.bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);

    foreach (glob(mnExportDir().'/*') as $file) {
        copy($file, $dir.'/'.basename($file));
    }

    file_put_contents($dir.'/menu_items.csv', str_replace(
        '"7502","950","0","2","About us","item","post_type","page","7002","about-us","","","","","publish"',
        '"7502","950","0","2","Portfolio","item","post_type_archive","portfolio","0","","","","","","publish"',
        (string) file_get_contents($dir.'/menu_items.csv'),
    ));

    mnImport(['directory' => $dir]);

    expect(mnItems()[7502]->target_type)->toBe(MenuItemImporter::UNRESOLVED);
    expect(mnItems()[7502]->url)->toBeNull();

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);
});

it('un-parks an item the moment the owner gives it an address, with no second import', function () {
    /*
     * THE SELF-HEALING HALF, and the reason the gate is TWO conditions rather
     * than one. `MegaMenuApiController::update()` writes `url` and never writes
     * `target_type`, so gating on `target_type` alone would hide the item
     * forever after the owner had fixed it — on the one screen he was told to
     * fix it on. He would type an address, save, refresh, and see nothing.
     *
     * MUTATION NOTE — RAN. Dropping the `trim((string) $item->url) === ''` half
     * of NavigationService::isParked() fails this test: the item stays hidden
     * after the owner gives it an address.
     */
    mnImport();

    mnMount(Menu::query()->where('source_term_id', 950)->firstOrFail());

    $item = MenuItem::query()->where('source_post_id', 7502)->firstOrFail();

    // Exactly what the Mega Menu screen writes: the url, and nothing else.
    $item->update(['url' => '/about/']);

    app(NavigationService::class)->flush();

    expect(in_array('About us', mnLabels(app(NavigationService::class)->menu('primary')), true))
        ->toBeTrue('the item stayed hidden after it was given an address');
});

it('does not hide an item an admin created by hand with no address', function () {
    /*
     * THE OTHER SIDE OF THE SAME GATE. A mega-panel column HEADING is a real
     * menu item with children and no URL, and `MegaMenuApiController::store()`
     * accepts one (`'url' => ['nullable', …]`). Hiding every url-less item
     * would delete that heading from the header of a shop that never ran an
     * import — CLAUDE.md's first rule, broken by a feature it has nothing to do
     * with.
     *
     * `target_type` is the discriminator precisely because no screen in this
     * application has ever written it.
     *
     * MUTATION NOTE — RAN. Reducing NavigationService::isParked() to
     * `trim((string) $item->url) === ''` fails here: the hand-made heading and
     * its two children disappear from the rendered menu.
     */
    $menu = Menu::query()->create(['name' => 'Hand made', 'slug' => 'hand-made']);
    mnMount($menu);

    $heading = MenuItem::query()->create(['menu_id' => $menu->id, 'label' => 'Best sellers', 'position' => 0]);
    MenuItem::query()->create(['menu_id' => $menu->id, 'parent_id' => $heading->id, 'label' => 'Toners', 'url' => '/collections/toners/', 'position' => 0]);

    expect($heading->url)->toBeNull();
    expect($heading->target_type)->toBeNull();

    app(NavigationService::class)->flush();

    $labels = mnLabels(app(NavigationService::class)->menu('primary'));

    expect($labels)->toBe(['Best sellers', 'Toners']);
});

it('parks a menu item WordPress had not published, instead of putting it live', function () {
    /*
     * `menu_items` has no status column, so a WordPress DRAFT item has three
     * possible fates: refused (losing something half-typed), imported live
     * (publishing unfinished work on the owner's header), or parked. Parked is
     * the same answer this entity already gives an item with nowhere to point,
     * for the same reason — it is in the database and on the Mega Menu screen,
     * and it is not in the header until he says so.
     *
     * MUTATION NOTE — RAN. Removing the `$status === 'publish'` branch from
     * MenuItemImporter::import() imports the draft as a live category link and
     * fails the second assertion: "Skincare" appears in the rendered header.
     */
    $dir = sys_get_temp_dir().'/kbb-mn-draft-'.bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);

    foreach (glob(mnExportDir().'/*') as $file) {
        copy($file, $dir.'/'.basename($file));
    }

    $csv = (string) file_get_contents($dir.'/menu_items.csv');
    $drafted = str_replace('"skincare","","","","","publish"', '"skincare","","","","","draft"', $csv);

    expect($drafted)->not->toBe($csv, 'the fixture no longer spells the Skincare row the way this test rewrites it');
    file_put_contents($dir.'/menu_items.csv', $drafted);

    $report = mnImport(['directory' => $dir]);

    $item = MenuItem::query()->where('source_post_id', 7501)->firstOrFail();

    expect($item->label)->toBe('Skincare', 'the draft was dropped instead of parked');
    expect($item->url)->toBeNull();
    expect($item->target_type)->toBe(MenuItemImporter::UNRESOLVED);

    mnMount(Menu::query()->where('source_term_id', 950)->firstOrFail());

    expect(in_array('Skincare', mnLabels(app(NavigationService::class)->menu('primary')), true))
        ->toBeFalse('an item WordPress had not published reached the header');

    expect(str_contains(implode(' | ', mnAdjustmentSamples($report, 'menu-items')), 'draft'))
        ->toBeTrue('the unpublished item was not named in the report');

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);
});

it('resolves a parked item on a later pass once the thing it points at is imported', function () {
    /*
     * PARKING IS NOT A VERDICT, it is a state with a stated repair — the same
     * one `order_items.csv` already offers for a line whose product arrived
     * later. This is the case the Navigation group's dependency warning
     * describes in as many words: import the navigation without the catalogue
     * and every category item is parked; import the catalogue and run the file
     * again and every one of them resolves.
     *
     * MUTATION NOTE — RAN. Matching MenuItemImporter on `label` instead of
     * `source_post_id` fails here with 14 items in a table that should hold 7:
     * the second pass inserts a second copy of every row rather than repairing
     * the first.
     */
    $manifest = mnManifest();

    // The navigation, with no catalogue and no articles behind it.
    (new ImportRunner)->run(new ImportOptions(
        directory: mnExportDir(),
        sourceTimezone: $manifest['source']['timezone'],
        only: ['menus', 'menu-items'],
    ));

    $first = mnItems();

    expect(count($first))->toBe(7, 'every item should still be imported without a catalogue');
    expect($first[7501]->target_type)->toBe(MenuItemImporter::UNRESOLVED, 'a category item cannot resolve with no categories');
    expect($first[7501]->label)->toBe('Skincare', 'the label and the position survive regardless');
    expect($first[7506]->target_type)->toBe(MenuItemImporter::UNRESOLVED);

    // Now the rest of the export, navigation included, exactly as the warning
    // says to run it.
    mnImport();

    $second = mnItems();

    expect(count($second))->toBe(7, 'the second pass duplicated rows instead of repairing them');
    expect($second[7501]->id)->toBe($first[7501]->id, 'the repair created a new row instead of updating the old one');
    expect($second[7501]->target_type)->toBe('category');
    expect($second[7506]->target_type)->toBe('product');

    // And the one that can never resolve is still parked, still here.
    expect($second[7502]->target_type)->toBe(MenuItemImporter::UNRESOLVED);
});

/*
|--------------------------------------------------------------------------
| Idempotence, and the menu the owner typed
|--------------------------------------------------------------------------
*/

it('imports the same export twice and writes nothing the second time', function () {
    /*
     * "Unchanged" is the only evidence a second pass produces that it was
     * idempotent, and it is evidence the importer cannot fake: it comes from
     * Eloquent's own dirty check against the row as the database holds it (see
     * ImportContext::apply()).
     *
     * MUTATION NOTE — RAN. Adding `'show_desktop' => false` to MenuImporter's
     * update attributes — writing the slot back rather than leaving it out —
     * turns the menus line from 0/1 created/unchanged into 1 updated on every
     * run, and fails here.
     */
    mnImport();

    $before = mnItems();

    $second = mnImport();

    foreach (['menus' => 1, 'menu-items' => 7] as $entity => $rows) {
        $report = $second->for($entity);

        expect($report->created)->toBe(0, $entity.' created rows on a second pass over an unchanged export');
        expect($report->updated)->toBe(0, $entity.' rewrote rows on a second pass: '.$rows.' rows should be unchanged');
        expect($report->unchanged)->toBe($rows, $entity.' did not report every row unchanged');
    }

    // And the ids did not move, which is what "matched on source_post_id"
    // actually means to the rest of the shop: a translation of a menu label
    // keys on menu_items.id.
    foreach (mnItems() as $source => $item) {
        expect($item->id)->toBe($before[$source]->id, 'item '.$source.' is a different row after the second import');
    }

    /*
     * AND THE MATCH IS ON THE ID, NOT ON THE LABEL — which is the assertion
     * that was missing. Renaming an item in WordPress is the commonest edit
     * there is, and an importer matching on the label would file the renamed
     * one as a brand new row and leave the old one in the menu for ever.
     *
     * MUTATION NOTE — RAN, AND THE FIRST ATTEMPT SURVIVED. Matching on `label`
     * instead of `source_post_id` was green against an unchanged export,
     * because every label still matched. It is red here: 8 rows in a table that
     * should hold 7, with "Sale" still in the menu beside "Clearance".
     */
    $dir = sys_get_temp_dir().'/kbb-mn-renamed-'.bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);

    foreach (glob(mnExportDir().'/*') as $file) {
        copy($file, $dir.'/'.basename($file));
    }

    file_put_contents($dir.'/menu_items.csv', str_replace(
        '"Sale","item"',
        '"Clearance","item"',
        (string) file_get_contents($dir.'/menu_items.csv')
    ));

    mnImport(['directory' => $dir]);

    $renamed = mnItems();

    expect(count($renamed))->toBe(7, 'a renamed menu item was imported as a second row');
    expect($renamed[7503]->id)->toBe($before[7503]->id, 'a renamed item did not stay the same row');
    expect($renamed[7503]->label)->toBe('Clearance');

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);
});

it('leaves a menu the owner typed by hand completely alone, and does not mount its own', function () {
    /*
     * THE OWNER HAS BEEN RETYPING THIS MENU FOR WEEKS. An import that replaced
     * it, or that took the header slot, would destroy that work in one command
     * with no undo — and at the moment an import is most likely to be a
     * rehearsal.
     *
     * The rule is that the import is ADDITIVE and NEVER MOUNTS: matched on
     * `source_term_id` / `source_post_id`, which every row his screens create
     * has NULL in, so no query in either importer can select one; and the three
     * slot flags are written FALSE on create and never written again.
     *
     * MUTATION NOTE — RAN. Writing `'show_desktop' => true` on create in
     * MenuImporter fails the header assertion below: the imported menu steals
     * the slot and the owner's menu stops rendering.
     */
    Menu::query()->where('show_desktop', true)->update(['show_desktop' => false]);
    Menu::query()->where('show_mobile', true)->update(['show_mobile' => false]);

    $mine = Menu::query()->create([
        'name' => 'The one I typed', 'slug' => 'the-one-i-typed',
        'show_desktop' => true, 'show_mobile' => true,
    ]);

    $top = MenuItem::query()->create(['menu_id' => $mine->id, 'label' => 'My Skincare', 'url' => '/collections/skincare/', 'position' => 0]);
    MenuItem::query()->create(['menu_id' => $mine->id, 'parent_id' => $top->id, 'label' => 'My Toners', 'url' => '/collections/toners/', 'position' => 0]);

    $snapshot = MenuItem::query()->where('menu_id', $mine->id)->orderBy('id')->get()->toArray();

    mnImport();

    // His rows: byte for byte, including updated_at.
    expect(MenuItem::query()->where('menu_id', $mine->id)->orderBy('id')->get()->toArray())->toBe($snapshot);

    $mine->refresh();
    expect((bool) $mine->show_desktop)->toBeTrue('the import took the desktop slot');
    expect((bool) $mine->show_mobile)->toBeTrue('the import took the mobile slot');

    // The imported menu is here, and is switched off.
    $imported = Menu::query()->where('source_term_id', 950)->firstOrFail();

    expect((bool) $imported->show_desktop)->toBeFalse();
    expect((bool) $imported->show_mobile)->toBeFalse();
    expect((bool) $imported->show_footer)->toBeFalse();

    // So the header a shopper sees is still his.
    app(NavigationService::class)->flush();

    expect(mnLabels(app(NavigationService::class)->menu('primary')))->toBe(['My Skincare', 'My Toners']);
});

it('does not switch an imported menu back off once the owner has switched it on', function () {
    /*
     * THE OTHER HALF OF THE SAME RULE, and the one that only bites a week after
     * the cutover. Writing the three slot flags on UPDATE as well as on create
     * would mean the first delta pass takes the header down.
     *
     * MUTATION NOTE — RAN. Moving the three `show_*` writes in MenuImporter out
     * of the `if ($menu === null)` block fails here: the menu comes back with
     * show_desktop false and the header falls through to the hard-coded
     * fallback.
     */
    mnImport();

    $menu = Menu::query()->where('source_term_id', 950)->firstOrFail();
    $menu->update(['show_desktop' => true, 'show_mobile' => true]);

    mnImport();

    $menu->refresh();

    expect((bool) $menu->show_desktop)->toBeTrue('a second import unmounted a menu the owner had mounted');
    expect((bool) $menu->show_mobile)->toBeTrue();
});

it('does not write over what the owner set on the Mega Menu screen', function () {
    /*
     * WordPress has no badge, no icon, no highlight colour and no column count,
     * so those fields are absent from the attribute list rather than written as
     * null. Writing null would wipe the owner's own decoration on every delta
     * pass — silently, because the report would say "updated" and he would read
     * that as the import having done its job.
     *
     * MUTATION NOTE — RAN. Adding `'badge' => null, 'highlight_color' => null`
     * to MenuItemImporter's attribute list fails both assertions below.
     */
    mnImport();

    $item = MenuItem::query()->where('source_post_id', 7503)->firstOrFail();
    $item->update(['badge' => 'HOT', 'highlight_color' => '#E23A4E', 'visibility' => 'guest']);

    mnImport();

    $item->refresh();

    expect($item->badge)->toBe('HOT');
    expect($item->highlight_color)->toBe('#E23A4E');
    expect($item->visibility)->toBe('guest');
});

/*
|--------------------------------------------------------------------------
| The cache, the batch, and the columns MySQL enforces
|--------------------------------------------------------------------------
*/

it('flushes the five-minute navigation cache after an import', function () {
    /*
     * `NavigationService::menu()` caches `kbb.nav.primary`, `.mobile` and
     * `.footer` for FIVE MINUTES, shared across every visitor. Every admin
     * write in MegaMenuApiController already calls flush(); an import is a
     * write like any other.
     *
     * It cannot matter on a FIRST import, because nothing imported is mounted.
     * It matters on the second, onto a menu the owner has since switched on:
     * without it, five minutes of every visitor's header is the menu as it was
     * before the import — and the owner refreshes, sees no change, and imports
     * again.
     *
     * MUTATION NOTE — RAN, AND THE FIRST ATTEMPT SURVIVED. Deleting the
     * flush() from MenuItemImporter::finalise() ALONE leaves this green,
     * because MenuImporter::finalise() runs first and has already forgotten the
     * key — nothing re-warms it in between, so the reader still sees fresh
     * rows. Both calls are real (the menus one covers an import that carries
     * menus.csv and no items), and the mutation that reddens this is deleting
     * BOTH: the header then serves "Skincare" for five minutes after the import
     * renamed it.
     */
    mnImport();

    mnMount(Menu::query()->where('source_term_id', 950)->firstOrFail());

    // Warm it, the way a visitor does.
    expect(in_array('Skincare', mnLabels(app(NavigationService::class)->menu('primary')), true))->toBeTrue();
    expect(Cache::has('kbb.nav.primary'))->toBeTrue('the tree is not cached, so this test proves nothing');

    // A delta export in which the owner renamed the item in WordPress.
    $dir = sys_get_temp_dir().'/kbb-mn-delta-'.bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);

    foreach (glob(mnExportDir().'/*') as $file) {
        copy($file, $dir.'/'.basename($file));
    }

    file_put_contents(
        $dir.'/menu_items.csv',
        str_replace('"Skincare","item"', '"Skin care","item"', (string) file_get_contents($dir.'/menu_items.csv'))
    );

    mnImport(['directory' => $dir]);

    expect(Cache::has('kbb.nav.primary'))->toBeFalse('the import left the navigation cache warm');
    expect(in_array('Skin care', mnLabels(app(NavigationService::class)->menu('primary')), true))
        ->toBeTrue('the header is still serving the menu as it was before the import');

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);
});

it('imports the same rows at --batch=1, at --batch=500 and one row per request', function () {
    /*
     * A BATCH IS A SEPARATE TRANSACTION, and the parent link is the thing that
     * crosses one: `_menu_item_menu_item_parent` names a row that may be in the
     * previous batch, this batch, or a batch that has not been read yet. At
     * batch=500 the whole menu is one commit and the in-memory map answers
     * every lookup; at batch=1 each row commits alone and the map is rebuilt
     * from nothing each time. A parent resolution that only worked inside one
     * batch would be green on every default run and broken on the owner's
     * server, where a 7,000-row import is batched.
     *
     * CLAUDE.md carries the general form of this, from Lane IE: "a batch is a
     * separate HTTP request; anything tallied across batches names only the
     * last request's data on a live site."
     *
     * ── AND BATCH SIZE ALONE CANNOT BREAK IT, WHICH IS WORTH SAYING ───────
     *
     * Two mutations were run against this and BOTH SURVIVED — removing the
     * database half of `localItemId()`, and removing that AND finalise()'s
     * repair loop. The reason is the finding: `ImportContext`'s id map is per
     * RUN, not per batch, so at batch=1 the parent written by the previous
     * batch is still in memory. Batch size changes when rows COMMIT; it does
     * not change what the importer remembers.
     *
     * The boundary that does is a separate REQUEST, so this test now compares
     * three shapes rather than two: everything in one batch, one row per batch,
     * and one row per REQUEST — a fresh ImportRunner each time, the way Store →
     * Import steps it. The third is what makes this assert a defence rather
     * than a property.
     *
     * MUTATION NOTE — THREE RUN, ALL THREE SURVIVED, AND THAT IS THE FINDING
     * RATHER THAN A GAP. Removing the database half of `localItemId()`;
     * removing that AND finalise()'s repair loop; writing `null` into
     * `source_parent_post_id`. None of them reddens this, and the reason is
     * worth writing down: the id map is per RUN, and in THIS file every parent
     * precedes its children, so every shape above resolves at import time and
     * the deferred machinery is never reached.
     *
     * So this test asserts an AGREEMENT and not a defence, and that is what it
     * is for. The defence is the next test, `it nests a child imported in an
     * earlier request than its parent`, which reorders the file so a child
     * comes first — and the `source_parent_post_id` mutation IS red there. Two
     * tests, one property each, rather than one test that looks like it covers
     * both.
     */
    $shape = static function (): array {
        $out = [];

        foreach (MenuItem::query()->whereNotNull('source_post_id')->orderBy('source_post_id')->get() as $item) {
            $out[(int) $item->source_post_id] = [
                'label' => $item->label,
                'url' => $item->url,
                'target_type' => $item->target_type,
                'position' => (int) $item->position,
                // The parent by its SOURCE id, because the local ids differ
                // between two runs of a fresh database and the tree does not.
                'parent' => $item->parent_id === null
                    ? null
                    : (int) MenuItem::query()->whereKey($item->parent_id)->value('source_post_id'),
            ];
        }

        return $out;
    };

    $wipe = static function (): void {
        MenuItem::query()->whereNotNull('source_post_id')->delete();
        Menu::query()->whereNotNull('source_term_id')->delete();
    };

    mnImport(['batchSize' => 500]);
    $big = $shape();

    $wipe();

    mnImport(['batchSize' => 1]);
    $small = $shape();

    $wipe();

    // ONE ROW PER REQUEST. `limit` is what the import screen uses, and every
    // call is a fresh ImportRunner holding a fresh importer and a fresh id map
    // — which is the boundary batch size does not cross.
    $manifest = mnManifest();
    $key = 'mn-requests-'.bin2hex(random_bytes(4));

    for ($request = 0; $request < 40; $request++) {
        (new ImportRunner)->run(new ImportOptions(
            directory: mnExportDir(),
            sourceTimezone: $manifest['source']['timezone'],
            adoptBySlug: true,
            runKey: $key,
            limit: 1,
        ));

        if (MenuItem::query()->whereNotNull('source_post_id')->count() === 7) {
            break;
        }
    }

    $sliced = $shape();

    expect($small)->toBe($big, 'one row per batch produced a different menu from all of them at once');
    expect($sliced)->toBe($big, 'one row per REQUEST produced a different menu from all of them at once');
    expect(count($small))->toBe(7);
    expect($small[7504]['parent'])->toBe(7501);
});

it('nests a child imported in an earlier request than its parent', function () {
    /*
     * ══════════════════════════════════════════════════════════════════════
     * A BATCH IS A SEPARATE HTTP REQUEST, AND THIS IS THE ONE THAT BITES.
     * ══════════════════════════════════════════════════════════════════════
     *
     * `_menu_item_menu_item_parent` is a nav_menu_item POST id, so it has to be
     * translated, and a child can arrive before its parent. CategoryImporter
     * has the same problem and holds the unresolved ones in an instance array
     * that `finalise()` drains — and that is exactly what does NOT work here:
     * Store → Import steps an entity a slice at a time, and
     * `ImportRunner::runEntity()` calls finalise() on EVERY call, exhausted or
     * not. A slice holding the child and not the parent resolves nothing,
     * clears its array, and the link is gone: the later slice that imports the
     * parent has no idea anything was waiting on it.
     *
     * MEASURED, NOT ANTICIPATED. It was found by `AdminImportScreenTest > it
     * reaches the same database whether it is stepped in twos or done in one
     * go`, which reported one menu item updated — the sliced run left the child
     * at the top level and the single-pass run put it back. On the owner's
     * server every import is sliced, so the sliced answer is the one he gets.
     *
     * `menu_items.source_parent_post_id` is the fix: finalise() repairs from
     * the database rather than from memory.
     *
     * MUTATION NOTE — RAN. Dropping `source_parent_post_id` from
     * MenuItemImporter's attribute list (so finalise() has nothing to repair
     * from) fails this test with `Face Cleansers` at the top level, while the
     * unsliced import in every other test here stays green.
     */
    $manifest = mnManifest();

    $dir = sys_get_temp_dir().'/kbb-mn-sliced-'.bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);

    foreach (glob(mnExportDir().'/*') as $file) {
        copy($file, $dir.'/'.basename($file));
    }

    /*
     * The child BEFORE the parent, which is the order this fixture does not
     * have and a real export certainly can: WordPress renumbers nothing when
     * an item is dragged into a submenu.
     */
    $lines = file($dir.'/menu_items.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $head = array_shift($lines);

    usort($lines, static function (string $a, string $b): int {
        $rank = static fn (string $line): int => str_starts_with($line, '"7504"') ? 0 : 1;

        return $rank($a) <=> $rank($b);
    });

    expect(str_starts_with($lines[0], '"7504"'))->toBeTrue('the child is not first, so this test proves nothing');

    file_put_contents($dir.'/menu_items.csv', implode("\n", [$head, ...$lines])."\n");

    // Everything but the navigation, so the targets are there to resolve.
    mnImport(['directory' => $dir, 'only' => array_values(array_diff(ImportRunner::entityNames(), ['menus', 'menu-items']))]);

    // Now the navigation, ONE ROW PER REQUEST. `limit` is what the import
    // screen uses, and every call is a fresh ImportRunner with a fresh
    // importer — exactly as a fresh PHP process would be.
    $key = 'mn-sliced-'.bin2hex(random_bytes(4));

    for ($request = 0; $request < 30; $request++) {
        (new ImportRunner)->run(new ImportOptions(
            directory: $dir,
            sourceTimezone: $manifest['source']['timezone'],
            only: ['menus', 'menu-items'],
            runKey: $key,
            limit: 1,
        ));

        if (MenuItem::query()->whereNotNull('source_post_id')->count() === 7) {
            break;
        }
    }

    $items = mnItems();

    expect(count($items))->toBe(7, 'the sliced run did not finish');
    expect($items[7504]->parent_id)->toBe($items[7501]->id, 'a child imported before its parent was left at the top level');
    expect($items[7505]->parent_id)->toBe($items[7501]->id);
    expect($items[7506]->parent_id)->toBe($items[7501]->id);

    // And the parent link is not re-written on a later pass, which is what
    // makes the repair idempotent rather than a rewrite every run.
    $second = mnImport(['directory' => $dir]);

    expect($second->for('menu-items')->updated)->toBe(0, 'the repaired link is rewritten on every pass');

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);
});

it('cuts a URL and a label to what the column and the admin screen will take', function () {
    /*
     * MYSQL ENFORCES COLUMN WIDTHS AND SQLITE DISCARDS THEM. `menu_items.url`
     * is varchar(255) and `menu_items.label` is varchar(255), while a WordPress
     * custom URL has no such limit and `post_title` is TEXT. An over-long value
     * is SQLSTATE 22001 on the owner's MySQL — mid-import, taking the batch
     * with it — and silently fine on the suite's SQLite.
     *
     * The label is cut at SIXTY rather than 255, which is the Mega Menu
     * screen's own `max:60`: a longer one imports and is then unsaveable, so
     * the owner opens the item, changes nothing, presses Save and is refused
     * over a field he never typed.
     *
     * MUTATION NOTE — RAN. Deleting the fit() call on `url` in
     * MenuItemImporter::custom() fails this test under
     * `-c phpunit-mysql.xml` with SQLSTATE[22001] and passes on SQLite, which
     * is the whole reason the MySQL config is run before reporting.
     */
    $dir = sys_get_temp_dir().'/kbb-mn-wide-'.bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);

    foreach (glob(mnExportDir().'/*') as $file) {
        copy($file, $dir.'/'.basename($file));
    }

    $long = 'https://kbeautybliss.com/'.str_repeat('super-sale-', 40).'/';
    $label = str_repeat('Seasonal clearance ', 12);

    expect(mb_strlen($long))->toBeGreaterThan(255);
    expect(mb_strlen($label))->toBeGreaterThan(60);

    file_put_contents($dir.'/menu_items.csv', str_replace(
        '"Sale","item","custom","custom","0","","https://kbeautybliss.com/super-sale/"',
        '"'.$label.'","item","custom","custom","0","","'.$long.'"',
        (string) file_get_contents($dir.'/menu_items.csv')
    ));

    expect(str_contains((string) file_get_contents($dir.'/menu_items.csv'), $long))
        ->toBeTrue('the fixture no longer spells the custom row the way this test rewrites it');

    $report = mnImport(['directory' => $dir]);

    $item = MenuItem::query()->where('source_post_id', 7503)->firstOrFail();

    expect(mb_strlen((string) $item->url))->toBe(255);
    expect(mb_strlen((string) $item->label))->toBe(60);
    expect($report->for('menu-items')->rejections())->toBe([], 'an over-long value took the row down instead of being cut');

    // And both cuts are named, so the owner can repair them rather than
    // discovering a truncated address by clicking it.
    $samples = implode(' | ', mnAdjustmentSamples($report, 'menu-items'));

    expect(str_contains($samples, 'super-sale-super-sale'))->toBeTrue('the cut URL is not in the report: '.$samples);
    expect(str_contains($samples, 'Seasonal clearance'))->toBeTrue('the cut label is not in the report: '.$samples);

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);
});

it('refuses a menu address the storefront would not follow', function () {
    /*
     * /api/* is unauthenticated and this is not that — but a menu URL comes
     * from a database this shop did not author, which is the reason
     * App\Support\SafeUrl exists at all (its own header names the WordPress
     * import as the source that set its severity). `NavigationService::tree()`
     * already turns a `javascript:` row into `/`, so this is the SECOND
     * defence, and it is the one that tells the owner.
     *
     * Lane IE's own finding is the reason it is worth having both: a scheme
     * check deleted from an importer left a whole suite green, because the
     * export refused the value at source and no bad value ever reached it. This
     * one is exercised on a file the plugin did not write — which is the
     * ordinary case, since Store → Import takes an upload.
     *
     * MUTATION NOTE — RAN. Deleting the `SafeUrl::href($raw, '') === ''` branch
     * from MenuItemImporter::custom() stores `javascript:alert(1)` in
     * `menu_items.url` and fails the first assertion below.
     */
    $dir = sys_get_temp_dir().'/kbb-mn-scheme-'.bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);

    foreach (glob(mnExportDir().'/*') as $file) {
        copy($file, $dir.'/'.basename($file));
    }

    file_put_contents($dir.'/menu_items.csv', str_replace(
        'https://kbeautybliss.com/super-sale/',
        'javascript:alert(1)',
        (string) file_get_contents($dir.'/menu_items.csv')
    ));

    $report = mnImport(['directory' => $dir]);

    $item = MenuItem::query()->where('source_post_id', 7503)->firstOrFail();

    expect($item->url)->toBeNull('an executable address was stored on a menu row');
    expect($item->target_type)->toBe(MenuItemImporter::UNRESOLVED);

    expect(str_contains(implode(' | ', mnAdjustmentSamples($report, 'menu-items')), 'javascript:alert(1)'))
        ->toBeTrue('the refused address was not named in the report');

    // And the storefront's own gate still holds if one ever gets past here.
    mnMount(Menu::query()->where('source_term_id', 950)->firstOrFail());
    $item->update(['url' => 'javascript:alert(1)', 'target_type' => 'custom']);
    app(NavigationService::class)->flush();

    $urls = [];

    foreach (app(NavigationService::class)->menu('primary') as $node) {
        $urls[] = $node['url'];
    }

    expect(in_array('javascript:alert(1)', $urls, true))->toBeFalse('the rendered tree carried an executable address');

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);
});

/*
|--------------------------------------------------------------------------
| The two lists that have to agree
|--------------------------------------------------------------------------
*/

it('registers the navigation on both entity lists, in the same order', function () {
    /*
     * ImportWorkspace::ENTITIES and ImportRunner::entities() are two
     * hand-maintained lists that must agree. Its own comment records what it
     * cost the last time they did not: the SEO entity was registered on the
     * runner while the screen knew nothing about it, and every upload 500'd on
     * an undefined key rather than answering the 422 it had been answering.
     *
     * MUTATION NOTE — RAN. Removing the `menu-items` block from
     * ImportWorkspace::ENTITIES fails the first assertion here (and
     * PostImportTest's copy of it).
     */
    expect(ImportWorkspace::entities())->toBe(ImportRunner::entityNames());

    $names = ImportRunner::entityNames();

    expect(in_array('menus', $names, true))->toBeTrue();
    expect(in_array('menu-items', $names, true))->toBeTrue();

    // And in this order, which is a dependency: an item resolves against the
    // categories, brands, products and articles the entities before it wrote,
    // and lands in a menu the entity before it created.
    foreach (['categories', 'brands', 'products', 'posts', 'menus'] as $earlier) {
        expect(array_search($earlier, $names, true))
            ->toBeLessThan(array_search('menu-items', $names, true), $earlier.' must be imported before menu-items');
    }

    expect(array_search('menus', $names, true))->toBeLessThan(array_search('menu-items', $names, true));
});

it('leaves no file in the export that nothing opens', function () {
    /*
     * ImportRunner names every file in the folder that no entity claims, so the
     * owner can see what did not reach the database. Two new files that nothing
     * opened would have appeared there as a loss — which is what they WERE
     * before this lane, and the point is that they no longer are.
     *
     * MUTATION NOTE — RAN. Removing MenuImporter from ImportRunner::entities()
     * puts "menus.csv" back in the unread list and fails this test.
     */
    $report = mnImport();

    /*
     * THE FILE NAME IS IN `field`, NOT IN `before`. ImportRunner's unread-file
     * discard puts the name in `line` and in `field` and the ROW COUNT in
     * `before` — so a test reading `before` compares file names against
     * "1 data row, read by nothing" and passes whatever happens. Found by
     * running this test's own mutation: unregistering MenuImporter left it
     * green.
     */
    $named = [];

    foreach ($report->for('export')->discards() as $discard) {
        foreach ($discard['samples'] ?? [] as $sample) {
            $named[] = $sample['field'] ?? '';
        }
    }

    expect(in_array('menus.csv', $named, true))->toBeFalse('menus.csv is still a file nothing opens');
    expect(in_array('menu_items.csv', $named, true))->toBeFalse('menu_items.csv is still a file nothing opens');
});
