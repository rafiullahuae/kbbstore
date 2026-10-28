<?php

declare(strict_types=1);

/*
 * THE GIFT BOX'S ORDER (Lane GBX). The owner, 28 October:
 *
 *   "in the gift box on front-end any where we displayed the gift box. the
 *    line must be in this sequence, full size products, mini products and then
 *    mask ... and also when open the list, the list should also show products
 *    in this sequence."
 *
 * What it looked like on the shop: the sentence read "2 Masks + 1 Mini Product
 * + 1 Full Size Product" and the opened list started with the masks, while the
 * photo row above it started with the full size gift -- two orders in one box.
 * One rule now (FreeGifts::linesFor() ends in fullFirst()), so the box's list,
 * its photos, the homepage section, the banner row, the cart-recovery email
 * and every new order's gift lines all read full size, minis, masks.
 */

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Services\FreeGifts;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    DB::table('free_gift_ranges')->delete();
});

function gbxProduct(string $name, string $kind): Product
{
    return Product::create([
        'slug' => Str::slug($name) . '-' . Str::random(6), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'price' => 2500, 'stock_status' => 'instock', 'manage_stock' => true, 'stock' => 20, 'gift_kind' => $kind,
    ]);
}

/** A range the admin listed masks first (the old boxes' order), with or without counts. */
function gbxLines(?array $needs): array
{
    $mask = gbxProduct('Tea Tree Mask', 'mask');
    $mask2 = gbxProduct('Rice Mask', 'mask');
    $mini = gbxProduct('Toner Mini', 'mini');
    $full = gbxProduct('Senka Whip', 'full');
    $bk = gbxProduct('Backup Mask', 'mask');

    $id = DB::table('free_gift_ranges')->insertGetId([
        'min_fils' => 30000, 'max_fils' => 49900, 'label_en' => 'Old words', 'label_ar' => 'كلمات', 'sort' => 0, 'enabled' => true,
        'need_mask' => $needs['mask'] ?? null, 'need_mini' => $needs['mini'] ?? null, 'need_full' => $needs['full'] ?? null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ([[$mask, 1, 'mask', 'primary'], [$mini, 1, 'mini', 'primary'], [$mask2, 1, 'mask', 'primary'], [$full, 1, 'full', 'primary'], [$bk, 1, 'mask', 'backup']] as $i => [$p, $q, $kind, $role]) {
        DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $p->id, 'quantity' => $q, 'kind' => $kind, 'role' => $role, 'sort' => $i]);
    }

    app()->instance('request', Request::create('/'));
    SettingsService::forgetMemo();
    Setting::flushMap();
    $gifts = app(FreeGifts::class);
    $gifts->compile();

    return $gifts->linesFor($gifts->ranges()[0]);
}

it('gives the lines full size, then minis, then masks, each type in the admin\'s own order', function () {
    // MUTATION: drop the fullFirst() from linesFor()'s last return. RED -- the
    // masks come first again (KINDS' walk order), exactly the list the owner saw.
    $lines = gbxLines(['mask' => 2, 'mini' => 1, 'full' => 1]);
    expect(array_map(static fn (array $l) => $l['product']->name, $lines))->toBe(['Senka Whip', 'Toner Mini', 'Tea Tree Mask', 'Rice Mask'])
        // The box's list and its photos agree.
        ->and(array_column(FreeGifts::rows($lines), 'name'))->toBe(['Senka Whip', 'Toner Mini', 'Tea Tree Mask', 'Rice Mask']);
});

it('orders a range with no counts the same way, by each gift\'s own type', function () {
    // MUTATION: drop the fullFirst() from linesFor()'s early return. RED.
    expect(array_map(static fn (array $l) => $l['product']->name, gbxLines(null)))->toBe(['Senka Whip', 'Toner Mini', 'Tea Tree Mask', 'Rice Mask']);
});

it('writes the sentence full size first, in English and Arabic', function () {
    // MUTATION: put WORDS back in mask, mini, full order. RED.
    expect(FreeGifts::criteriaWording(['mask' => 2, 'mini' => 1, 'full' => 1]))
        ->toBe(['en' => '1 Full Size Product + 1 Mini Product + 2 Masks', 'ar' => 'منتج بالحجم الكامل + منتج صغير واحد + قناعان'])
        ->and(FreeGifts::sentence(gbxLines(['mask' => 2, 'mini' => 1, 'full' => 1]))['en'])->toBe('1 Full Size Product + 1 Mini Product + 2 Masks');
});

it('reads an order placed before this change in the same order, list and photos alike', function () {
    // An order whose gift lines were saved mask, mini, full (the old walk).
    $order = Order::create(['order_number' => 'GBX-' . Str::random(6), 'email' => 'gbx@example.test', 'status' => 'processing', 'total' => 40000, 'subtotal' => 40000, 'free_gift_count' => 3]);
    foreach ([gbxProduct('Old Mask', 'mask'), gbxProduct('Old Mini', 'mini'), gbxProduct('Old Full', 'full')] as $p) {
        $order->gifts()->create(['product_id' => $p->id, 'name' => $p->name, 'quantity' => 1, 'unit_price' => 0, 'subtotal' => 0, 'total' => 0, 'is_gift' => true]);
    }
    $order = Order::find($order->id);

    // MUTATION: return orderRows() unsorted. RED (Old Mask first).
    expect(array_column(FreeGifts::orderRows($order), 'name'))->toBe(['Old Full', 'Old Mini', 'Old Mask'])
        ->and(array_column(FreeGifts::orderThumbs($order), 'name'))->toBe(['Old Full', 'Old Mini', 'Old Mask']);
});

it('keeps the admin\'s "Use for wording" in the same order as the shop', function () {
    $screen = file_get_contents(resource_path('views/admin/partials/free-gifts-screen.blade.php'));

    expect(substr_count($screen, "['full', 'mini', 'mask'].forEach(function (k) {\n      var n = Math.max(0, Math.floor(Number(needs && needs[k]) || 0)), w = WORDS[k];"))->toBe(1);
});
