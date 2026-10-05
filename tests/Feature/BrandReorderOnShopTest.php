<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;

/*
 * Reproduction: Catalog -> Reorder -> Brands -> a brand, save an order, then
 * the brand's own page must list its products in exactly that order.
 */
function brReorderSeed(int $n): array
{
    $b = Brand::create(['name' => 'Medicubetest', 'slug' => 'medicubetest']);
    $ids = [];
    for ($i = 1; $i <= $n; $i++) {
        $ids[] = Product::create([
            'slug' => "mct-$i", 'name' => sprintf('MCT product %02d', $i), 'type' => 'simple', 'status' => 'publish',
            'is_visible' => true, 'price' => 1000, 'stock_status' => 'instock', 'brand_id' => $b->id,
        ])->id;
    }

    return [$b, $ids];
}

function brNamesOnPage(string $html): array
{
    preg_match_all('#MCT product (\d\d)#', $html, $m);

    return array_values(array_unique($m[1]));
}

it('shows a brand page in exactly the order saved in Catalog -> Reorder', function () {
    [$b, $ids] = brReorderSeed(6);
    $admin = AdminUser::create(['name' => 'O', 'email' => 'o-'.uniqid().'@x.test', 'password' => 'secret-secret', 'role' => 'owner']);
    $want = array_reverse($ids);

    $this->actingAs($admin, 'admin')
        ->postJson("/admin-api/catalog/reorder/brand/{$b->id}/save-page", ['page' => 1, 'per_page' => 50, 'product_ids' => $want])
        ->assertOk();

    $html = $this->get('/brands/medicubetest/')->assertOk()->getContent();
    expect(brNamesOnPage($html))->toBe(['06', '05', '04', '03', '02', '01']);
});

it('keeps a featured product in the place Reorder gave it, on the shop, a category and the brand filter', function () {
    /*
     * The owner, 2.60.402: "on Medicube there's two products showing on
     * front-end on top of the list, but in the backend that's not #1 and #2".
     * Those two were FEATURED: the default sort put featured first, ahead of
     * the curated order. MUTATION: put orderByDesc('products.featured') back
     * before position in ShopController::applyDefaultSort -> '01' leads, red.
     */
    [$b, $ids] = brReorderSeed(4);
    $cat = \App\Models\Category::create(['name' => 'MCT Cat', 'slug' => 'mct-cat']);
    foreach ($ids as $i => $id) {
        Product::whereKey($id)->update(['position' => 10 + $i]);
        // The category's own number too (Lane SO): its page reads that one.
        $cat->products()->attach($id, ['category_position' => 10 + $i]);
    }
    // Product 01 is first by position already; make 04 featured and last.
    Product::whereKey($ids[3])->update(['featured' => true, 'position' => 99]);
    $cat->products()->updateExistingPivot($ids[3], ['category_position' => 99]);
    \App\Services\SettingsService::forgetMemo();

    foreach (['/shop/?filter_brands=medicubetest', '/collections/mct-cat/'] as $url) {
        expect(brNamesOnPage($this->get($url)->assertOk()->getContent()))->toBe(['01', '02', '03', '04'], $url);
    }
});

it('waits for Save: a pinned save bar, and a number for another page queued, never saved on its own', function () {
    // The owner: "when re-order done, there must be SAVE button. should not
    // apply the order/sorting directly." MUTATION: restore the immediate
    // '/move' fetch in reorderJumpToRank -> the queue assertion is red.
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    preg_match('/async function reorderJumpToRank\(productId, rank\)\{(.*?)\n\}/s', $app, $jump);

    expect($jump[1] ?? '')->not->toContain("'/move'")->toContain('reorderPending=')
        ->and($app)->toContain('position:sticky;bottom:0')
        ->and($app)->toContain('id="reDiscard"')
        ->and($app)->toContain("post('/move',{product_id:m.id, to:m.to})");
});

it('ships the migration that keeps brand pages on the brand and the curated order on', function () {
    $m = (string) file_get_contents(database_path('migrations/2027_08_26_100000_curated_order_first_and_brand_pages_stay_on_brand.php'));

    expect($m)->toContain("'layout_brand_cta', 'layout_brand_popular'")->toContain("'product_sorting'")->toContain("Cache::forget(\$key)");
});

it('gives every row four arrows: grey one place, red to the top or bottom of the whole list, all waiting for Save', function () {
    // The owner: "the arrow to bring top and down, should be 4, 2 grey and 2
    // red, the red arrows will jump to top or bottom of the whole list. and
    // grey will work as one row down or up." MUTATION: point data-rfirst at
    // reorderLocalMove(id, 0) (top of this PAGE only) -> red.
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect($app)->toContain('data-rup="${p.id}"')->toContain('data-rdown="${p.id}"')
        ->toContain('data-rfirst="${p.id}" class="re-jump"')->toContain('data-rlast="${p.id}" class="re-jump"')
        ->toContain("reorderJumpToRank(+b.dataset.rfirst,1)")
        ->toContain("reorderJumpToRank(+b.dataset.rlast,reorderData.total)")
        ->toContain('.ritem .mv button.re-jump{color:#d6336c;')
        ->not->toContain('data-rtop="${p.id}"');
});
