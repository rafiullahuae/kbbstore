<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Services\FreeGifts;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * THE FREE GIFTS BOX, OPENED, ON THE THANK-YOU PAGE AND IN THE EMAILS. (Lane FGE)
 *
 * The owner, 24 October: "i want the gift box (opened) on the thank you page,
 * in the emails too, same design ... heading should be same from the checkout
 * page for gift box with same outlined, also include gift section/box in the
 * abandoned cart emails but write one line that depends on the availability".
 * Before: the thank-you box opened closed ("View all"), the order emails mixed
 * the gifts into "Your items" with a small tag, and the basket reminder said
 * nothing about gifts.
 *
 * Mutation notes: drop 'kbbFgOpen' => true from received-summary and the first
 * case is red; put the gift lines back into the item list and the second is;
 * drop the gift-box include from cart-recovery and the third is.
 */
beforeEach(fn () => DB::table('free_gift_ranges')->delete());

function fgeProduct(string $name): Product
{
    return Product::create(['slug' => Str::slug($name) . '-' . Str::random(5), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'price' => 2500, 'stock_status' => 'instock', 'manage_stock' => true, 'stock' => 50, 'gift_kind' => 'mask', 'image' => '/media/' . Str::slug($name) . '.webp']);
}

function fgeLive(): void
{
    app(SettingsService::class)->set(FreeGifts::KEY, ['mode' => 'live']);
    SettingsService::forgetMemo();
    Setting::flushMap();
    app(FreeGifts::class)->compile();
}

function fgeOrder(bool $gifts): Order
{
    $o = Order::create(['order_number' => 'FGE-' . Str::random(6), 'email' => 'fge@example.test', 'status' => 'processing', 'total' => 55200, 'subtotal' => 55200,
        'currency' => 'AED', 'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery', 'free_gift_count' => $gifts ? 4 : 0,
        'shipping_address' => ['first_name' => 'Aisha', 'country' => 'AE', 'city' => 'Dubai']]);
    OrderItem::create(['order_id' => $o->id, 'name' => 'Glow Serum', 'quantity' => 1, 'unit_price' => 55200, 'subtotal' => 55200, 'total' => 55200]);
    if ($gifts) {
        OrderItem::create(['order_id' => $o->id, 'name' => 'Tea Tree Sheet Mask', 'brand' => 'Mediheal', 'quantity' => 3, 'unit_price' => 0, 'subtotal' => 0, 'total' => 0, 'is_gift' => true]);
        OrderItem::create(['order_id' => $o->id, 'name' => 'Black Rice Toner Mini', 'brand' => 'Haruharu', 'quantity' => 1, 'unit_price' => 0, 'subtotal' => 0, 'total' => 0, 'is_gift' => true]);
    }

    return $o->fresh();
}

it('opens the gift box by default on the thank-you page', function () {
    $html = view('partials.free-gifts.box', ['kbbFgRows' => [['name' => 'Tea Tree Sheet Mask', 'brand' => 'Mediheal', 'image' => '', 'quantity' => 3, 'value' => 0]], 'kbbFgOpen' => true])->render();

    expect($html)->toContain('<div class="fgb-th" hidden>')->and($html)->toContain('<div class="fgb-ls">')
        ->and((string) file_get_contents(resource_path('views/partials/checkout/received-summary.blade.php')))->toContain("'kbbFgOpen' => true");

    // Everywhere else it still opens closed, byte for byte.
    $closed = view('partials.free-gifts.box', ['kbbFgRows' => [['name' => 'Tea Tree Sheet Mask', 'brand' => 'Mediheal', 'image' => '', 'quantity' => 3, 'value' => 0]]])->render();
    expect($closed)->toContain('<div class="fgb-th">')->and($closed)->toContain('<div class="fgb-ls" hidden>');
});

it('gives the order emails their own outlined gift box, out of the item list', function () {
    $html = (new \App\Mail\OrderConfirmation(fgeOrder(true)))->render();

    expect($html)->toContain('Free gifts added to your order')
        ->and(substr_count($html, 'Tea Tree Sheet Mask'))->toBe(1)
        ->and($html)->toMatch('#<b>3&times;&nbsp;</b>Tea Tree Sheet Mask#')
        ->and($html)->toContain('border:1.5px solid #2e8f62;border-top:0;border-radius:0 0 12px 12px');

    // An order without gifts has no box at all.
    expect((new \App\Mail\OrderConfirmation(fgeOrder(false)))->render())->not->toContain('Free gifts added to your order');
});

it('shows the basket reminder the gifts its total reaches, with the availability line', function () {
    $mask = fgeProduct('Gel Mask');
    $id = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 19400, 'max_fils' => null, 'label_en' => '1 Mask', 'label_ar' => 'قناع', 'sort' => 0, 'enabled' => true, 'need_mask' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $mask->id, 'quantity' => 1, 'kind' => 'mask', 'role' => 'primary', 'sort' => 0]);
    fgeLive();

    $mail = fn (int $price) => (new \App\Mail\CartRecoveryReminder('Your basket', 'Hi', [['name' => 'Glow Serum', 'slug' => 'glow', 'quantity' => 1, 'unit_price' => $price]], 'https://example.test/cart/', 'https://example.test/u'))->render();

    expect($mail(25000))->toContain('Free gifts for you')->toContain('Gel Mask')
        ->toContain('Added to your order when you place it, depending on availability.')
        // The owner's closing line, last in the box (24 October).
        ->toMatch('#depending on availability\.</td></tr>\s*<tr><td[^>]*font-weight:700;color:\#2e8f62;">Don’t miss out on your FREE GIFTS! 🎁 Grab yours before they are gone\.</td></tr>#u');
    // Below every range: no box, no line.
    expect($mail(5000))->not->toContain('Free gifts for you')->not->toContain('depending on availability')->not->toContain('Don’t miss out');
});

/*
 * "same for gift box on cart panel and checkout etc. so the customers will
 * take more interest in gifts" (owner, 24 October): full size first, then
 * minis, then masks, in the live box (cart panel, cart page, checkout -- all
 * FreeGifts::box()) and on an order's saved gifts (thank-you page, My account,
 * emails). Mutation note: drop fullFirst() from box() or orderRows() and this
 * is red.
 */
it('leads the gift box\'s small photos and its list with full size, then minis, then masks', function () {
    $mask = fgeProduct('Kind Mask');
    $mini = fgeProduct('Kind Mini'); $mini->update(['gift_kind' => 'mini']);
    $full = fgeProduct('Kind Full'); $full->update(['gift_kind' => 'full']);
    $id = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 19400, 'max_fils' => null, 'label_en' => 'x', 'label_ar' => 'x', 'sort' => 0, 'enabled' => true, 'need_mask' => 1, 'need_mini' => 1, 'need_full' => 1, 'created_at' => now(), 'updated_at' => now()]);
    foreach ([[$mask, 'mask'], [$mini, 'mini'], [$full, 'full']] as $i => [$p, $k]) {
        DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $p->id, 'quantity' => 1, 'kind' => $k, 'role' => 'primary', 'sort' => $i]);
    }
    fgeLive();

    $box = app(FreeGifts::class)->box(['subtotal' => 30000, 'item_count' => 1]);
    // The list leads with full size too (Lane GBX, 28 October) ...
    expect(array_column($box['kbbFgRows'], 'name'))->toBe(['Kind Full', 'Kind Mini', 'Kind Mask']);
    // ... as do the small photos shown by default.
    $html = view('partials.free-gifts.box', $box)->render();
    preg_match('#<div class="fgb-th">(.*?)</div>#s', $html, $th);
    preg_match_all('#alt="([^"]+)"#', $th[1], $alts);
    expect($alts[1])->toBe(['Kind Full', 'Kind Mini', 'Kind Mask']);

    $o = fgeOrder(false);
    $o->update(['free_gift_count' => 3]);
    foreach ([$mask, $mini, $full] as $p) {
        OrderItem::create(['order_id' => $o->id, 'product_id' => $p->id, 'name' => $p->name, 'quantity' => 1, 'unit_price' => 0, 'subtotal' => 0, 'total' => 0, 'is_gift' => true]);
    }
    $o = $o->fresh();
    expect(array_column(FreeGifts::orderRows($o), 'name'))->toBe(['Kind Full', 'Kind Mini', 'Kind Mask'])
        ->and(array_column(FreeGifts::orderThumbs($o), 'name'))->toBe(['Kind Full', 'Kind Mini', 'Kind Mask'])
        ->and(FreeGifts::orderRows($o)[0])->not->toHaveKey('kind');
});

/*
 * "in order email to customer, the heading should change. Gifts are added to
 * your order ... only for the successed order. not pending etc or abondant
 * emails" (owner, 24 October). Mutation notes: drop $kitGiftsAdded from the
 * confirmation, or let on-hold say "added", and this is red.
 */
it('says the gifts were added only in a successful order\'s emails', function () {
    $order = fgeOrder(true);

    $ok = (new \App\Mail\OrderConfirmation($order))->render();
    expect($ok)->toContain('Free gifts added to your order')->toContain('They arrive together with your order.')->not->toContain('Free gifts for you');

    foreach (['processing' => true, 'shipped' => true, 'completed' => true, 'onhold' => false, 'cancelled' => false, 'failed' => false] as $status => $added) {
        $html = (new \App\Mail\OrderStatusChanged($order, $status))->render();
        expect(str_contains($html, 'Free gifts added to your order'))->toBe($added, $status);
    }
});

/*
 * "don't break anything neither any alignment etc issues in the email" (owner,
 * 24 October). The heading sits on the box's top line through two corner
 * pieces built from table cells (Outlook keeps cell heights and borders, not
 * a div's margin), and in an Arabic email -- whose cells run right to left --
 * the pieces swap sides so each corner still meets its own side of the box.
 * Mutation note: hard-code 'left' for the first piece and the Arabic case is red.
 */
it('draws the box corners on the right sides in English and in Arabic', function () {
    $k = ['sans' => 'Arial', 'text' => '#111111'];
    $lines = [['img' => null, 'brand' => 'Mediheal', 'name' => 'Tea Tree Sheet Mask', 'qty' => 2]];
    $en = view('emails.kit.gift-box', ['k' => $k, 'lines' => $lines])->render();

    $corner = '#<td width="14" valign="top" style="width:14px;"><table[^>]*><tr><td height="11"[^>]*>&nbsp;</td></tr><tr><td height="11" style="height:11px;border-top:1.5px solid \#2e8f62;border-%s:1.5px solid \#2e8f62;border-top-%s-radius:12px;#';
    expect($en)->toMatch(sprintf($corner, 'left', 'left'))
        ->and($en)->toContain('align="right" valign="middle" style="width:64px;padding-left:12px;')
        ->and($en)->not->toContain('margin-top:11px');

    // An Arabic email is mirrored as a whole: the border AND its rounding
    // move to the other side together, so each corner still meets its edge.
    $ar = \App\Services\Mail\Kit\KitRtl::mirror($en);
    expect($ar)->toMatch(sprintf($corner, 'right', 'right'))
        ->and($ar)->toContain('align="left" valign="middle" style="width:64px;padding-right:12px;');
});
