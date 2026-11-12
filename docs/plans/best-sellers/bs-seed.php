<?php
/* 2.60.661 preview seed: orders in the last 90 days for 12 products. Preview database only.
 *   GO_LANE=lane-bs GO_SEED=../docs/plans/best-sellers/bs-seed.php sh tools/go-preview.sh 9610 */
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../tools/go-seed.php';
$products = Product::query()->visible()->whereNotNull('image')->orderBy('id')->limit(12)->get();
foreach ($products as $i => $p) {
    $o = Order::create(['order_number' => 'BS-' . $i, 'email' => "bs$i@example.test", 'status' => 'completed', 'currency' => 'AED', 'total' => 9900]);
    DB::table('orders')->where('id', $o->id)->update(['created_at' => now()->subDays(3 + $i)]);
    OrderItem::create(['order_id' => $o->id, 'product_id' => $p->id, 'name' => $p->name, 'quantity' => 40 - $i * 3, 'unit_price' => 9900, 'subtotal' => 9900, 'total' => 9900]);
}
\App\Support\RecentBestSellers::forget();
echo 'bs: ', count(\App\Support\RecentBestSellers::ranked()), " ranked\n";
