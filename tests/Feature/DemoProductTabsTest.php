<?php

declare(strict_types=1);

use App\Models\Product;
use App\Support\DemoProductDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * =============================================================================
 * THE DETAIL TABS HAD NOTHING TO SHOW, AND THE OWNER HAD NEVER SEEN THEM WORK
 * =============================================================================
 *                                                               (Lane PDP2, R4)
 *
 *   "also put some demo tabs on the product page, so i can see in action."
 *
 * ── WHAT THE DEFECT LOOKED LIKE ON THE SHOP ─────────────────────────────────
 *
 * Open any product on his shop and the tab row under "Product details" carried
 * ONE tab, "Description", with nothing beside it and nothing to click. It is
 * the product he screenshotted: short description "Demo product for layout
 * testing. Replaced by the WordPress migration."
 *
 * The cause is in the seeder rather than in the tabs. DemoCatalogueSeeder set
 * `short_description` and NOT ONE of `description`, `ingredients` or
 * `how_to_use` — which are the three columns App\Support\ProductTabs builds its
 * built-in entries from. ProductTabs drops any entry whose body is empty
 * (`trim(strip_tags($body)) !== ''`) and falls Description back to the short
 * description, so 24 of 24 demo products produced exactly one surviving tab.
 * DemoContent's own top-up would have added two more, but demo content is off
 * by default and always has been, so it never ran.
 *
 * ── AND WHY A MIGRATION IS HALF THE FIX ─────────────────────────────────────
 *
 * The seeder runs through firstOrCreate(). His shop is already seeded, so the
 * seeder edit reaches a FRESH install and nothing else: applying a package with
 * only that in it would have left him looking at the same single tab. The
 * backfill migration is what reaches the rows he has.
 *
 * ── THE FALSE GREEN THIS FILE REFUSES ───────────────────────────────────────
 *
 * "There is a tab row" is satisfied by a row with one empty tab in it, which is
 * exactly the state being fixed. So every case below counts tabs AND reads
 * their bodies: three buttons, three panels, three distinct non-empty bodies,
 * and the words that belong to each one found in the panel that should hold it.
 */
function demoProductRow(string $name, array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => Str::slug($name).'-'.Str::random(6),
        'wc_id' => null,
        'name' => $name,
        'sku' => DemoProductDetails::SKU_PREFIX.Str::upper(Str::random(4)),
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
        'short_description' => DemoProductDetails::SEEDED_SHORT_DESCRIPTION,
    ], $overrides));
}

/** Run the backfill migration's up() against the current database. */
function demoRunBackfill(): void
{
    $migration = require database_path('migrations/2027_06_15_000000_backfill_demo_product_details.php');
    $migration->up();
}

/* ─────────────────────────── what the page draws ──────────────────────────── */

it('draws three detail tabs on a demo product, each with its own body', function () {
    /*
     * MUTATION NOTE. Remove `...$this->details($name)` from
     * DemoCatalogueSeeder AND delete the backfill migration, and this goes red
     * with 1 tab instead of 3 — which is exactly what the owner was looking at.
     * RUN.
     */
    $product = demoProductRow('Heartleaf 77% Soothing Toner');

    demoRunBackfill();

    $html = $this->get('/product/'.$product->slug)->assertOk()->getContent();

    // THE TAB ROW. Three buttons, three panels, and the first one open.
    expect(substr_count($html, 'class="dtab on"'))->toBe(1);
    expect(preg_match_all('/<button class="dtab[^"]*" type="button" data-i="\d+">/', $html))->toBe(3);
    expect(preg_match_all('/<div class="dtabpanel[^"]*" data-panel="\d+">/', $html))->toBe(3);

    // THE WORDS. Each tab's title, and one phrase that can only be in its own
    // body — a tab that exists and is empty passes a count and fails this.
    foreach (['Description', 'Ingredients', 'How to use'] as $title) {
        expect($html)->toContain('>'.$title.'</button>');
    }

    expect($html)->toContain('Heartleaf 77% Soothing Toner</b> is a watery toner');
    expect($html)->toContain('A sample list, in the order an ingredients panel is normally written.');
    expect($html)->toContain('Use after cleansing, before serums and creams.');

    // And three DISTINCT bodies rather than the same one three times.
    preg_match_all('/<div class="dcontent clamp">(.*?)<\/div>/s', $html, $m);
    expect($m[1])->toHaveCount(3);
    expect(array_unique(array_map('trim', $m[1])))->toHaveCount(3);
    foreach ($m[1] as $body) {
        expect(trim(strip_tags($body)))->not->toBe('');
    }
});

it('gives a name it does not recognise three tabs as well', function () {
    // The `other` family is a complete entry, not a blank. A demo product added
    // later with an unfamiliar name still gets a working tab row.
    expect(DemoProductDetails::family('Widget Number Nine'))->toBe('other');

    $bodies = DemoProductDetails::for('Widget Number Nine');

    expect(array_keys($bodies))->toBe(['description', 'ingredients', 'how_to_use']);

    foreach ($bodies as $column => $body) {
        expect(trim(strip_tags($body)))->not->toBe('', "{$column} is empty, so its tab would be dropped");
    }
});

it('files a sun product on the sun shelf although its name also says rice or serum', function () {
    /*
     * Order inside DemoProductDetails::family() is the whole of that function,
     * and two of the seeder's own 24 names are the reason.
     *
     * MUTATION NOTE. Move the `toner` and `serum` rows above `sunscreen` in
     * that match list and this goes red on both. RUN.
     */
    expect(DemoProductDetails::family('Relief Sun Rice + Probiotics SPF50+'))->toBe('sunscreen');
    expect(DemoProductDetails::family('Hyaluronic Acid Watery Sun Gel'))->toBe('sunscreen');
    expect(DemoProductDetails::family('Rice Probiotics Toner'))->toBe('toner');
    expect(DemoProductDetails::family('Madagascar Centella Ampoule'))->toBe('serum');
});

it('states no figure and no claim anywhere in the demo copy', function () {
    /*
     * App\Services\DemoContent's rule, applied to this copy: a stand-in is
     * LAYOUT, and a percentage, a strength or a certification is a CLAIM, which
     * does not become true because a preview aid made it. The product's own
     * NAME is excluded from the sweep — "Heartleaf 77%" is the shop's name for
     * the thing, not something written here.
     */
    $offenders = [];

    foreach (['Heartleaf Soothing Toner', 'Barrier Repair Cream', 'Zinc Sunscreen', 'Overnight Sleeping Mask',
        'Gentle Foaming Cleanser', 'Ginseng Essence Water', 'Age-R Booster Pro Device', 'Widget Nine'] as $name) {
        foreach (DemoProductDetails::for($name) as $column => $body) {
            $text = strip_tags(str_replace($name, '', $body));

            if (preg_match('/\d/', $text)) {
                $offenders[] = "{$name}.{$column} states a figure";
            }

            foreach (['clinical', 'proven', 'dermatolog', 'certified', 'guarantee', 'cures', 'best '] as $word) {
                if (stripos($text, $word) !== false) {
                    $offenders[] = "{$name}.{$column} claims “{$word}”";
                }
            }
        }

        // And it says what it is, every time.
        expect(DemoProductDetails::for($name)['description'])->toContain(DemoProductDetails::DISCLOSURE);
    }

    expect($offenders)->toBe([]);
});

/* ───────────────────────── the migration's three promises ─────────────────── */

it('fills a demo product’s empty columns and overwrites none of its filled ones', function () {
    $product = demoProductRow('Green Tea Fresh Toner', [
        'description' => '<p>Written by the owner, by hand.</p>',
        'ingredients' => '',
        'how_to_use' => null,
    ]);

    demoRunBackfill();

    $product->refresh();

    // Kept, byte for byte.
    expect($product->description)->toBe('<p>Written by the owner, by hand.</p>');
    // Filled, because both were blank — and '' and null are both blank.
    expect($product->ingredients)->toBe(DemoProductDetails::for('Green Tea Fresh Toner')['ingredients']);
    expect($product->how_to_use)->toBe(DemoProductDetails::for('Green Tea Fresh Toner')['how_to_use']);
});

it('leaves a real product alone however much it looks like a demo one', function () {
    /*
     * THE ONE THAT MATTERS WHEN THE WORDPRESS IMPORT LANDS. Three marks are
     * required at once — wc_id IS NULL, sku LIKE 'DEMO-%', and the seeder's own
     * short_description — and a row that breaks any one of them is untouched
     * even when it breaks only that one.
     *
     * MUTATION NOTE. Drop the `->whereNull('wc_id')` from the migration and
     * the imported row below gains three columns and this goes red. Drop the
     * short_description clause and the "real copy, demo-shaped SKU" row does.
     * RUN: both, separately.
     */
    $cases = [
        // An imported product. Empty details, demo-shaped SKU, demo-shaped
        // blurb — and a wc_id, which is the mark the Migrator keys on.
        'imported' => demoProductRow('Imported Toner', ['wc_id' => 4211]),
        // Typed into the admin: no wc_id, no DEMO- SKU.
        'hand-made' => demoProductRow('Hand Made Toner', ['sku' => 'KBB-0001']),
        // Seeded long ago and since given a real blurb.
        'real blurb' => demoProductRow('Re-described Toner', [
            'short_description' => 'A real blurb, written by the owner.',
        ]),
    ];

    $before = [];

    foreach ($cases as $label => $row) {
        $before[$label] = DB::table('products')->where('id', $row->id)
            ->first(['description', 'ingredients', 'how_to_use', 'updated_at']);
    }

    demoRunBackfill();

    foreach ($cases as $label => $row) {
        $after = DB::table('products')->where('id', $row->id)
            ->first(['description', 'ingredients', 'how_to_use', 'updated_at']);

        expect((array) $after)->toBe((array) $before[$label], "the {$label} product was written to");
        // Not a false green: these rows really are still empty, so "unchanged"
        // is a statement about the migration rather than about nothing.
        expect(trim((string) $after->ingredients))->toBe('');
    }
});

it('writes nothing the second time it runs', function () {
    $product = demoProductRow('Ceramide Daily Moisturiser');

    demoRunBackfill();
    $once = DB::table('products')->where('id', $product->id)->first();

    demoRunBackfill();
    $twice = DB::table('products')->where('id', $product->id)->first();

    expect((array) $twice)->toBe((array) $once);
    expect(trim(strip_tags((string) $once->ingredients)))->not->toBe('');
});

it('writes the same words the seeder does, so the two cannot drift', function () {
    // One source, two readers. A second copy of this copy inside the migration
    // is the shape this project has paid for twice.
    $product = demoProductRow('Low pH Good Morning Gel Cleanser');

    demoRunBackfill();

    $product->refresh();
    $expected = DemoProductDetails::for('Low pH Good Morning Gel Cleanser');

    expect($product->description)->toBe($expected['description']);
    expect($product->ingredients)->toBe($expected['ingredients']);
    expect($product->how_to_use)->toBe($expected['how_to_use']);
});
