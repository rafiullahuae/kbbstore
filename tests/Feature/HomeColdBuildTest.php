<?php

declare(strict_types=1);

/**
 * A SWITCHED-OFF HOMEPAGE SECTION COSTS NOTHING TO BUILD.          (Lane PF)
 *
 * Row 55 switched thirteen old sections off and left HomeController reading
 * for all of them: on a cold cache (after every catalogue write, and every ten
 * minutes) the homepage built the Recommended, old Best sellers and Flash sale
 * rails, the routine builder's six steps (two queries each), the review wall
 * and the category circles, for a page that draws none of them. Measured on
 * the Lane PF preview fixture: 61 queries cold before, 36 after; warm, 1 and 1
 * (StorefrontQueryBudgetTest measures warm and did not move).
 *
 * Every case says what the defect looked like and how to turn it red.
 */

use App\Models\Product;
use App\Models\Review;
use App\Services\HomepageSections;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    app(SettingsService::class)->set('demo_content', false);
});

/** The SQL a cold homepage runs. */
function pfColdQueries(): array
{
    SettingsService::forgetMemo();
    Cache::flush();
    $q = [];
    DB::listen(function ($e) use (&$q) {
        $q[] = $e->sql;
    });
    test()->get('/')->assertOk();
    DB::flushQueryLog();

    return $q;
}

function pfSwitchOn(array $keys): void
{
    $rows = app(HomepageSections::class)->all();

    foreach ($keys as $k) {
        $rows[$k]['desktop'] = $rows[$k]['mobile'] = true;
    }

    app(HomepageSections::class)->save($rows);
    SettingsService::forgetMemo();
}

/** The fingerprints of the six reads that feed only switched-off sections. */
function pfOldReads(array $q): array
{
    // Both engines' quoting: SqlShape::portable() turns MySQL's backticks into
    // the double quotes the needles below are written with (SqlNeedleDialectGuardTest).
    $sql = \Tests\Support\SqlShape::portable(implode("\n", $q));

    return array_keys(array_filter([
        'recommended rail' => str_contains($sql, '"featured" = ?'),
        'flash sale rail' => str_contains($sql, '"sale_price" < "price"'),
        'review wall' => preg_match('/from [`"]reviews[`"]/', $sql) === 1,
        'routine steps' => preg_match('/select [`"]slug[`"] from [`"]categories[`"] where [`"]slug[`"] in/', $sql) === 1,
        'category circles' => str_contains($sql, 'select "id", "name", "slug", "path", (select count(*)'),
        'quiz figure' => preg_match_all('/select count\(\*\) as aggregate from [`"]products[`"]/', $sql) > 1,
    ]));
}

it('runs none of the old sections\' reads while those sections are off', function () {
    /*
     * THE DEFECT: a cold homepage paying for the routine builder, the review
     * wall, the category circles and three old rails it does not draw.
     *
     * MUTATION (RUN): in HomeController put `$reviews = Cache::remember(...)`
     * back without the `$draws('reviews') ?` → red, naming "review wall".
     */
    Product::create(['slug' => 'pf-cold', 'name' => 'Pf cold', 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'stock_status' => 'instock', 'price' => 5000, 'sale_price' => 3000, 'featured' => true]);

    expect(pfOldReads(pfColdQueries()))->toBe([]);
});

it('still builds each one, and draws it, the moment its section is switched back on', function () {
    /*
     * THE DEFECT the gate could introduce: a section switched back on from
     * Appearance → Homepage drawing empty, because its rows were never read.
     *
     * MUTATION (RUN): make `$draws` always answer false → red.
     */
    $p = Product::create(['slug' => 'pf-on', 'name' => 'Pf featured flash', 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'stock_status' => 'instock', 'price' => 5000, 'sale_price' => 3000, 'featured' => true]);
    Review::create(['product_id' => $p->id, 'author_name' => 'Amna', 'rating' => 5, 'content' => 'Lovely toner', 'status' => 'approved']);

    pfSwitchOn(['recommended', 'flash', 'reviews', 'routine', 'categories', 'quiz']);

    expect(pfOldReads(pfColdQueries()))->toBe(['recommended rail', 'flash sale rail', 'review wall', 'routine steps', 'category circles', 'quiz figure']);

    $html = test()->get('/')->getContent();
    expect($html)->toContain('Pf featured flash')
        ->and($html)->toContain('Lovely toner');
});

it('rebuilds a cached rail set that lacks a rail now drawn, instead of serving it short', function () {
    /*
     * THE DEFECT: the rails are ONE cache entry. Built while Recommended was
     * off, it holds no `recommended`; switching the section on (or showing it
     * in the live preview, which saves nothing and so forgets no cache) would
     * have drawn an empty Recommended for up to ten minutes.
     *
     * MUTATION (RUN): drop the `array_diff($need, …)` half of the rebuild
     * condition → red.
     */
    Product::create(['slug' => 'pf-feat', 'name' => 'Pf only featured', 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'stock_status' => 'instock', 'price' => 5000, 'featured' => true]);

    SettingsService::forgetMemo();
    Cache::flush();
    test()->get('/')->assertOk();
    expect(Cache::get('kbb.home.rails'))->toHaveKey('bundles')->not->toHaveKey('recommended');

    pfSwitchOn(['recommended']);

    // No Cache::flush() here: the entry above is still warm.
    expect(test()->get('/')->getContent())->toContain('Pf only featured');
    expect(Cache::get('kbb.home.rails'))->toHaveKey('recommended');
});
