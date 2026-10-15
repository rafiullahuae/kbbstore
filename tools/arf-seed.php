<?php
/*
 * Lane ARF -- the live shop's state on top of the ARC live-shaped catalogue.
 *
 * PREVIEW DATABASE ONLY (tools/arf-preview.sh). Runs after tools/ar-seed.php
 * and tools/arc-seed.php (the latter with KBB_ARF_LIVE=1, so the shipped
 * Arabic drafts stay DRAFTS, as on live: the owner has not pressed "Approve
 * all"). Mirrors the settings the live English HTML shows (15 Oct 2026):
 *
 *   - store_name "K-Beauty Bliss UAE" (copyright line, og:site_name);
 *   - translation_highlight_missing ON (his screenshots show the markers).
 *
 * The footer, trust strip, header label and search placeholder on live are
 * the shipped interface defaults, word for word, so nothing else is set.
 *
 * Then Lane ARF's migrations' up(), which is what the package does on the
 * server. KBB_ARF_SKIP=1 stops before that, for the BEFORE measurement.
 */

use Illuminate\Support\Facades\DB;

$sv = app(\App\Services\SettingsService::class);
$sv->set('store_name', 'K-Beauty Bliss UAE');
$sv->set('translation_highlight_missing', true);

/* A signed-in customer with an address and one order, so the account pages,
   the order-received page and the checkout's address block have something in
   them. English data, as a real shopper's would be. */
$me = \App\Models\Customer::updateOrCreate(['email' => 'mariam@preview.test'], [
    'name' => 'Mariam Al Hashimi', 'first_name' => 'Mariam', 'last_name' => 'Al Hashimi',
    'password' => 'preview-pass-1', 'phone' => '+971501234567', 'email_verified_at' => now(),
]);
$me->addresses()->delete();
$home = $me->addresses()->create(['type' => 'shipping', 'label' => 'Home', 'is_default' => true, 'first_name' => 'Mariam', 'last_name' => 'Al Hashimi',
    'line1' => 'Villa 14, Street 23', 'line2' => 'Al Barsha 2', 'city' => 'Al Barsha', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971501234567']);
\App\Models\Order::query()->where('customer_id', $me->id)->delete();
$ids = DB::table('products')->where('is_visible', true)->orderBy('id')->limit(30)->pluck('id')->all();
$addr = ['first_name' => 'Mariam', 'last_name' => 'Al Hashimi', 'line1' => 'Villa 14, Street 23', 'line2' => 'Al Barsha 2',
    'city' => 'Al Barsha', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971501234567', 'email' => 'mariam@preview.test'];
$p = \App\Models\Product::find($ids[0]);
$unit = (int) ($p->sale_price ?: $p->price);
$o = \App\Models\Order::create(['order_number' => '10512', 'customer_id' => $me->id, 'email' => $me->email, 'phone' => $me->phone,
    'status' => 'processing', 'currency' => 'AED', 'billing_address' => $addr, 'shipping_address' => $addr,
    'subtotal' => $unit * 2, 'discount_total' => 0, 'shipping_total' => 2000, 'fee_total' => 0, 'tax_total' => 0, 'total' => $unit * 2 + 2000,
    'shipping_method' => 'Delivery Charges', 'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
    'locale' => 'ar', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
$o->items()->create(['product_id' => $p->id, 'name' => $p->name, 'brand' => optional($p->brand)->name, 'quantity' => 2,
    'unit_price' => $unit, 'subtotal' => $unit * 2, 'total' => $unit * 2, 'tax_total' => 0]);
\App\Models\Coupon::updateOrCreate(['code' => 'GLOW10'], ['type' => 'percent', 'amount' => 1000, 'description' => 'Preview coupon']);
@mkdir(base_path('storage/arf-logs'), 0777, true);
file_put_contents(base_path('storage/arf-logs/preview-ids.json'), json_encode([
    'ids' => $ids, 'order' => $o->id, 'order_number' => '10512', 'address' => $home->id,
    'product' => 'skin-1004-madagascar-centella-hyalu-cica-water-fit-sun-serum-50ml',
    'post' => DB::table('posts')->where('status', 'published')->value('slug'),
]));

$sv->flush();
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
\App\Services\Translation\TranslationStore::flush();

if (getenv('KBB_ARF_SKIP') !== '1') {
    foreach (glob(database_path('migrations/*_lane_arf_*.php')) as $file) {
        (require $file)->up();
    }
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();
    \App\Services\Translation\TranslationStore::flush();
}

echo 'arf-seed: ar published ', DB::table('translations')->where('locale', 'ar')->where('status', 'published')->count(),
    ', ar drafts ', DB::table('translations')->where('locale', 'ar')->where('status', 'draft')->count(),
    ', highlight ', var_export(DB::table('settings')->where('key', 'translation_highlight_missing')->value('value'), true), "\n";
