<?php

declare(strict_types=1);

/**
 * The researched ingredient lists into each product's Ingredients box.  (2.60.511)
 *
 * The owner: "apply the ingredients in all found ones. give me the patch for
 * that." What going wrong would look like on the shop: a list he typed by hand
 * overwritten, the wrong product given a list, or a fresh install (or this test
 * database) filled with lists for products it does not sell.
 *
 * MUTATIONS, RUN: drop the "box already holds anything" check -- the kept case
 * is red; drop the live-catalogue guard -- the fresh-install case is red.
 */

use App\Models\Product;
use Illuminate\Support\Facades\DB;

function riMigration(): object
{
    return require database_path('migrations/2027_11_01_600000_fill_researched_ingredients.php');
}

function riRows(): array
{
    $c = (new ReflectionClass(riMigration()))->getReflectionConstant('DATA')->getValue();

    return json_decode($c, true);
}

function riRun(): void
{
    ob_start();
    riMigration()->up();
    ob_end_clean();
}

function riCatalogue(): void
{
    $now = now();
    $have = DB::table('products')->pluck('slug')->all();
    $rows = [];
    foreach (riRows() as $r) {
        if (! in_array($r['slug'], $have, true)) {
            $rows[] = ['slug' => $r['slug'], 'name' => $r['slug'], 'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
                'price' => 5000, 'stock_status' => 'instock', 'created_at' => $now, 'updated_at' => $now];
        }
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('products')->insert($chunk);
    }
}

it('carries only clean, verbatim lists: every entry a slug and a body of paragraphs', function () {
    $rows = riRows();

    expect(count($rows))->toBe(339)
        ->and(count(array_unique(array_column($rows, 'slug'))))->toBe(339);

    foreach ($rows as $r) {
        expect($r['slug'])->toMatch('/^[a-z0-9_-]+$/')
            ->and($r['body'])->toStartWith('<p>')
            ->and($r['body'])->not->toMatch('/<(script|img|iframe|a)\b/i');
    }

    $all = implode('', array_column($rows, 'body'));
    // The three source typos the research notes spelled out are corrected.
    expect($all)->not->toContain('ACRYLOYLDI METHYLTAURATE/MP')
        ->and($all)->not->toContain('Copper, Tripeptide-1 Tripeptide-1')
        ->and($all)->not->toContain('Collagen Water (, Water');
});

it('fills an empty box, leaves a typed one alone, and draws the Ingredients tab', function () {
    riCatalogue();
    $rows = riRows();
    [$a, $b] = [$rows[0]['slug'], $rows[1]['slug']];
    DB::table('products')->where('slug', $b)->update(['ingredients' => '<p>Typed by the owner</p>']);

    riRun();

    expect(DB::table('products')->where('slug', $a)->value('ingredients'))->toBe($rows[0]['body'])
        ->and(DB::table('products')->where('slug', $b)->value('ingredients'))->toBe('<p>Typed by the owner</p>');

    $html = (string) $this->get('/product/'.$a.'/')->assertOk()->getContent();
    expect($html)->toContain('>Ingredients</button>');

    // Twice is the same as once.
    $before = DB::table('products')->pluck('ingredients', 'slug')->all();
    riRun();
    expect(DB::table('products')->pluck('ingredients', 'slug')->all())->toBe($before);
});

it('changes nothing on a shop that is not the live catalogue', function () {
    $before = DB::table('products')->pluck('ingredients', 'slug')->all();
    riRun();
    expect(DB::table('products')->pluck('ingredients', 'slug')->all())->toBe($before);
});
