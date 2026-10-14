<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use App\Services\SettingsService;
use App\Support\CategoryOrder;

/*
 * 2.60.501 -- New Arrivals opens newest first; every other category keeps the
 * order the owner set by hand.
 *
 * The owner, on /collections/new-arrivals/: "in this category i need to view
 * the new added products first", then "keep the new added by default, only
 * for this category, in rest we re-ordered, don't touch them".
 */

function cnfProduct(string $slug, string $created): Product
{
    $p = Product::create([
        'slug' => $slug, 'name' => ucwords(str_replace('-', ' ', $slug)), 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'price' => 1000, 'stock_status' => 'instock',
    ]);
    $p->forceFill(['created_at' => $created])->save();

    return $p;
}

function cnfOrder(string $html, array $only): array
{
    preg_match_all('#/product/([a-z0-9-]+)/#', $html, $m);

    return array_values(array_filter(array_unique($m[1]), fn ($s) => in_array($s, $only, true)));
}

beforeEach(function () {
    SettingsService::forgetMemo();
});

it('opens New Arrivals newest first, shows Latest in Sort by, and still lets a shopper pick', function () {
    /*
     * MUTATION: drop $sortDefault from Facets::sort() in ShopController. RED:
     * the page opens on the hand-made order, the oldest product first.
     */
    $cat = Category::create(['name' => 'New Arrivals', 'slug' => 'new-arrivals']);
    $old = cnfProduct('na-old', '2026-01-01 10:00:00');
    $mid = cnfProduct('na-mid', '2026-06-01 10:00:00');
    $new = cnfProduct('na-new', '2026-10-10 10:00:00');
    foreach ([$old, $mid, $new] as $p) {
        $p->categories()->attach($cat->id);
    }

    // As the migration does: this category, and only this one.
    (require base_path('database/migrations/2027_10_31_600000_category_default_sort.php'))->up();
    expect($cat->fresh()->{CategoryOrder::COLUMN})->toBe('date');

    $html = $this->get('/collections/new-arrivals/')->assertOk()->getContent();
    expect(cnfOrder($html, ['na-old', 'na-mid', 'na-new']))->toBe(['na-new', 'na-mid', 'na-old'])
        ->and($html)->toMatch('~<option value="date"\s+selected~');

    // A shopper's own choice still wins.
    $byName = $this->get('/collections/new-arrivals/?orderby=name')->assertOk()->getContent();
    expect(cnfOrder($byName, ['na-old', 'na-mid', 'na-new']))->toBe(['na-mid', 'na-new', 'na-old']);
});

it('leaves every other category on the order set by hand', function () {
    /*
     * MUTATION: make CategoryOrder::defaultFor() answer 'date' for NULL. RED.
     */
    $admin = AdminUser::create(['name' => 'O', 'email' => 'cnf-'.uniqid().'@x.test', 'password' => 'secret-secret', 'role' => 'owner']);
    $other = Category::create(['name' => 'Toners', 'slug' => 'cnf-toners']);
    Category::create(['name' => 'New Arrivals', 'slug' => 'new-arrivals']);
    $a = cnfProduct('t-a', '2026-01-01 10:00:00');
    $b = cnfProduct('t-b', '2026-10-01 10:00:00');
    $c = cnfProduct('t-c', '2026-05-01 10:00:00');
    foreach ([$a, $b, $c] as $p) {
        $p->categories()->attach($other->id);
    }
    // The owner's hand-made order: a, c, b.
    $this->actingAs($admin, 'admin')
        ->postJson("/admin-api/catalog/reorder/category/{$other->id}/save-page", ['page' => 1, 'per_page' => 50, 'product_ids' => [$a->id, $c->id, $b->id]])
        ->assertOk();

    (require base_path('database/migrations/2027_10_31_600000_category_default_sort.php'))->up();

    expect($other->fresh()->{CategoryOrder::COLUMN})->toBeNull();
    $html = $this->get('/collections/cnf-toners/')->assertOk()->getContent();
    expect(cnfOrder($html, ['t-a', 't-b', 't-c']))->toBe(['t-a', 't-c', 't-b'])
        ->and($html)->toMatch('~<option value="featured"\s+selected~');
});

it('saves the choice from Catalog -> Categories -> Edit, and refuses anything else', function () {
    $admin = AdminUser::create(['name' => 'O', 'email' => 'cnf2-'.uniqid().'@x.test', 'password' => 'secret-secret', 'role' => 'owner']);
    $cat = Category::create(['name' => 'Toners', 'slug' => 'cnf-toners-2']);

    $this->actingAs($admin, 'admin')->putJson("/admin-api/categories/{$cat->id}", ['name' => 'Toners', 'slug' => 'cnf-toners-2', 'default_sort' => 'date'])->assertOk();
    expect($cat->fresh()->default_sort)->toBe('date');

    $this->actingAs($admin, 'admin')->putJson("/admin-api/categories/{$cat->id}", ['name' => 'Toners', 'slug' => 'cnf-toners-2', 'default_sort' => ''])->assertOk();
    expect($cat->fresh()->default_sort)->toBeNull();

    $this->actingAs($admin, 'admin')->putJson("/admin-api/categories/{$cat->id}", ['name' => 'Toners', 'slug' => 'cnf-toners-2', 'default_sort' => 'price; DROP'])->assertStatus(422);

    $listed = collect($this->actingAs($admin, 'admin')->getJson('/admin-api/categories')->assertOk()->json('categories'))->firstWhere('id', $cat->id);
    expect($listed)->toHaveKey('default_sort');
});
