<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Store back-office API. Session-guarded (auth:admin) and mounted in web.php,
 * NOT api.php — the stateless API guard can't see the admin web session.
 * Money is stored in fils; the admin UI works in whole AED, so we convert here.
 */
class AdminController extends Controller
{
    // statuses that count as real revenue (mirror the storefront's derived-value rule)
    private const REVENUE_STATUSES = ['processing', 'onhold', 'completed'];

    /** GET /admin-api/stats — dashboard KPIs + recent orders. */
    public function stats()
    {
        $since = now()->subDays(30)->toISOString();

        $revenue30 = (int) Order::whereIn('status', self::REVENUE_STATUSES)
            ->where('created_at', '>=', $since)
            ->sum('total');

        $ordersTotal   = Order::count();
        $customers     = Customer::count();
        $products      = Product::count();
        $lowStock      = Product::whereNotNull('stock')->where('stock', '>', 0)->where('stock', '<=', 15)->count();
        $outStock      = Product::whereNotNull('stock')->where('stock', '=', 0)->count();

        // simple conversion proxy: paid orders / total orders (only meaningful once traffic is tracked)
        $paid = Order::whereIn('status', self::REVENUE_STATUSES)->count();

        $recent = Order::orderByDesc('id')->limit(8)->get()->map(function (Order $o) {
            $c = Customer::find($o->customer_id);
            return [
                'id'         => $o->id,
                'customer'   => $c->name ?? 'Guest',
                'total_aed'  => (int) round(($o->total ?? 0) / 100),
                'status'     => $o->status,
                'created_at' => $o->created_at,
            ];
        });

        return response()->json([
            'revenue_30d_aed' => (int) round($revenue30 / 100),
            'orders'          => $ordersTotal,
            'customers'       => $customers,
            'products'        => $products,
            'paid_orders'     => $paid,
            'low_stock'       => $lowStock,
            'out_of_stock'    => $outStock,
            'recent'          => $recent,
        ]);
    }

    /** GET /admin-api/products — full catalog for the admin table. */
    public function products()
    {
        $rows = Product::orderBy('position')->orderBy('id')->get()->map(fn (Product $p) => [
            'id'         => $p->id,
            'name'       => $p->name,
            'brand'      => $p->brand,
            'sku'        => $p->sku,
            'category'   => $p->category,
            'price_aed'  => $p->price !== null ? (int) round($p->price / 100) : null,
            'sale_aed'   => $p->sale_price !== null ? (int) round($p->sale_price / 100) : null,
            'stock'      => $p->stock,
            'status'     => $p->status,
            'slug'       => $p->slug,
        ]);

        // category + brand rollups the Catalog tabs display
        $categories = Product::select('category', DB::raw('count(*) as n'))
            ->whereNotNull('category')->groupBy('category')->orderBy('category')->get()
            ->map(fn ($r) => ['name' => $r->category, 'count' => (int) $r->n]);

        $brands = Product::select('brand', DB::raw('count(*) as n'))
            ->whereNotNull('brand')->groupBy('brand')->orderByDesc('n')->get()
            ->map(fn ($r) => ['name' => $r->brand, 'count' => (int) $r->n]);

        return response()->json([
            'products'   => $rows,
            'categories' => $categories,
            'brands'     => $brands,
        ]);
    }

    /** GET /admin-api/products/{id} — full editable record for the product editor. */
    public function getProduct(int $id)
    {
        $p = Product::find($id);
        if (!$p) return response()->json(['error' => 'not_found'], 404);
        return response()->json([
            'id'                => $p->id,
            'name'              => $p->name,
            'brand'             => $p->brand,
            'category'          => $p->category,
            'sku'               => $p->sku,
            'description'       => $p->description,
            'short_description' => $p->short_description,
            'price_aed'         => $p->price !== null ? (int) round($p->price / 100) : null,
            'sale_aed'          => $p->sale_price !== null ? (int) round($p->sale_price / 100) : null,
            'stock'             => $p->stock,
            'status'            => $p->status,
            'slug'              => $p->slug,
            'seo'               => $p->seo_json ? json_decode($p->seo_json, true) : null,
        ]);
    }

    /** PUT /admin-api/products/{id} — persist edits (stock, price, name, status). */
    public function updateProduct(Request $request, int $id)
    {
        $product = Product::find($id);
        if (!$product) return response()->json(['error' => 'not_found'], 404);

        $data = $request->validate([
            'name'              => 'sometimes|nullable|string|max:255',
            'brand'             => 'sometimes|nullable|string|max:255',
            'category'          => 'sometimes|nullable|string|max:255',
            'sku'               => 'sometimes|nullable|string|max:255',
            'description'       => 'sometimes|nullable|string|max:20000',
            'short_description' => 'sometimes|nullable|string|max:5000',
            'stock'             => 'sometimes|nullable|integer|min:0|max:1000000',
            'price_aed'         => 'sometimes|nullable|integer|min:0|max:10000000',
            'sale_aed'          => 'sometimes|nullable|integer|min:0|max:10000000',
            'status'            => 'sometimes|string|in:active,draft,archived',
            'seo'               => 'sometimes|array',
        ]);

        if (array_key_exists('name', $data))              $product->name = $data['name'];
        if (array_key_exists('brand', $data))             $product->brand = $data['brand'];
        if (array_key_exists('category', $data))          $product->category = $data['category'];
        if (array_key_exists('sku', $data))               $product->sku = $data['sku'];
        if (array_key_exists('description', $data))       $product->description = $data['description'];
        if (array_key_exists('short_description', $data)) $product->short_description = $data['short_description'];
        if (array_key_exists('stock', $data))             $product->stock = $data['stock'];
        if (array_key_exists('status', $data))            $product->status = $data['status'];
        if (array_key_exists('price_aed', $data))         $product->price = $data['price_aed'] === null ? null : $data['price_aed'] * 100;
        if (array_key_exists('sale_aed', $data))          $product->sale_price = $data['sale_aed'] === null ? null : $data['sale_aed'] * 100;
        if (array_key_exists('seo', $data))               $product->seo_json = json_encode($data['seo']);

        $product->save();

        return response()->json(['ok' => true, 'id' => $product->id]);
    }

    /** POST /admin-api/inventory — bulk stock save from the Inventory tab. */
    public function saveInventory(Request $request)
    {
        $data = $request->validate([
            'changes'            => 'required|array|min:1',
            'changes.*.id'       => 'required|integer',
            'changes.*.stock'    => 'required|integer|min:0|max:1000000',
        ]);

        $n = 0;
        DB::transaction(function () use ($data, &$n) {
            foreach ($data['changes'] as $c) {
                $p = Product::find($c['id']);
                if ($p) { $p->stock = $c['stock']; $p->save(); $n++; }
            }
        });

        return response()->json(['ok' => true, 'saved' => $n]);
    }

    /** GET /admin-api/orders — orders list for the admin table. */
    public function orders()
    {
        $rows = Order::orderByDesc('id')->get()->map(function (Order $o) {
            $c = Customer::find($o->customer_id);
            return [
                'id'         => $o->id,
                'customer'   => $c->name ?? 'Guest',
                'email'      => $c->email ?? null,
                'items'      => OrderItem::where('order_id', $o->id)->count(),
                'subtotal_aed' => (int) round(($o->subtotal ?? 0) / 100),
                'total_aed'  => (int) round(($o->total ?? 0) / 100),
                'status'     => $o->status,
                'ship_method'=> $o->ship_method,
                'created_at' => $o->created_at,
            ];
        });

        return response()->json(['orders' => $rows]);
    }

    /** GET /admin-api/orders/{id} — single order with items + customer. */
    public function order(int $id)
    {
        $o = Order::find($id);
        if (!$o) return response()->json(['error' => 'not_found'], 404);

        $c = Customer::find($o->customer_id);
        $items = OrderItem::where('order_id', $o->id)->get()->map(fn (OrderItem $i) => [
            'name'       => $i->name,
            'brand'      => $i->brand,
            'qty'        => $i->qty,
            'unit_aed'   => (int) round(($i->unit_price ?? 0) / 100),
            'line_aed'   => (int) round((($i->unit_price ?? 0) * ($i->qty ?? 0)) / 100),
        ]);

        return response()->json([
            'id'           => $o->id,
            'status'       => $o->status,
            'customer'     => $c ? ['name' => $c->name, 'email' => $c->email, 'phone' => $c->phone, 'emirate' => $c->emirate, 'address' => $c->default_address] : null,
            'items'        => $items,
            'subtotal_aed' => (int) round(($o->subtotal ?? 0) / 100),
            'delivery_aed' => (int) round(($o->delivery ?? 0) / 100),
            'cod_fee_aed'  => (int) round(($o->cod_fee ?? 0) / 100),
            'total_aed'    => (int) round(($o->total ?? 0) / 100),
            'ship_method'  => $o->ship_method,
            'created_at'   => $o->created_at,
        ]);
    }

    /** GET /admin-api/analytics — sales metrics derived from real orders. */
    public function analytics()
    {
        $orders = Order::orderBy('id')->get();
        $paid   = $orders->whereIn('status', self::REVENUE_STATUSES);

        $revenueTotal = (int) $paid->sum('total');           // fils
        $paidCount    = $paid->count();
        $aov          = $paidCount ? intdiv($revenueTotal, $paidCount) : 0;

        // Daily revenue for the last 14 days (created_at is an ISO string → bucket by date).
        $days = [];
        for ($i = 13; $i >= 0; $i--) $days[now()->subDays($i)->format('Y-m-d')] = 0;
        foreach ($paid as $o) {
            $d = substr((string) $o->created_at, 0, 10);
            if (array_key_exists($d, $days)) $days[$d] += (int) $o->total;
        }
        $daily = [];
        foreach ($days as $d => $v) $daily[] = ['date' => $d, 'revenue_aed' => (int) round($v / 100)];

        // Status breakdown (all statuses present).
        $status = $orders->groupBy('status')->map(fn ($g) => $g->count());

        // Top products by revenue, from the paid orders' line items.
        $items = OrderItem::whereIn('order_id', $paid->pluck('id'))->get();
        $byName = [];
        foreach ($items as $it) {
            $k = $it->name;
            if (!isset($byName[$k])) $byName[$k] = ['name' => $k, 'brand' => $it->brand, 'units' => 0, 'revenue' => 0];
            $byName[$k]['units']   += (int) $it->qty;
            $byName[$k]['revenue'] += (int) $it->unit_price * (int) $it->qty;
        }
        usort($byName, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
        $top = array_map(fn ($p) => [
            'name'        => $p['name'],
            'brand'       => $p['brand'],
            'units'       => $p['units'],
            'revenue_aed' => (int) round($p['revenue'] / 100),
        ], array_slice($byName, 0, 8));

        return response()->json([
            'revenue_total_aed' => (int) round($revenueTotal / 100),
            'orders_total'      => $orders->count(),
            'paid_orders'       => $paidCount,
            'aov_aed'           => (int) round($aov / 100),
            'units_sold'        => (int) $items->sum('qty'),
            'daily'             => $daily,
            'status_breakdown'  => $status,
            'top_products'      => $top,
        ]);
    }

    /** GET /admin-api/users — list back-office accounts. */
    public function users()
    {
        $me = \Illuminate\Support\Facades\Auth::guard('admin')->id();
        $rows = \App\Models\AdminUser::orderBy('id')->get()->map(fn ($u) => [
            'id'         => $u->id,
            'name'       => $u->name,
            'email'      => $u->email,
            'role'       => $u->role,
            'is_self'    => $u->id === $me,
            'created_at' => $u->created_at,
        ]);
        return response()->json(['users' => $rows]);
    }

    /** POST /admin-api/users — create a back-office account. */
    public function createUser(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:admin_users,email',
            'password' => 'required|string|min:8|max:255',
            'role'     => 'required|string|in:owner,manager,support,editor',
        ]);
        $u = \App\Models\AdminUser::create($data);  // password hashed by model cast
        return response()->json(['ok' => true, 'id' => $u->id]);
    }

    /** PUT /admin-api/users/{id} — update name/role, optionally reset password. */
    public function updateUser(Request $request, int $id)
    {
        $u = \App\Models\AdminUser::find($id);
        if (!$u) return response()->json(['error' => 'not_found'], 404);

        $data = $request->validate([
            'name'     => 'sometimes|string|max:255',
            'role'     => 'sometimes|string|in:owner,manager,support,editor',
            'password' => 'sometimes|nullable|string|min:8|max:255',
        ]);

        // Don't allow removing the last owner (either by demotion here).
        if (isset($data['role']) && $u->role === 'owner' && $data['role'] !== 'owner'
            && \App\Models\AdminUser::where('role', 'owner')->count() <= 1) {
            return response()->json(['error' => 'last_owner', 'message' => 'You cannot demote the only owner.'], 422);
        }

        if (isset($data['name'])) $u->name = $data['name'];
        if (isset($data['role'])) $u->role = $data['role'];
        if (!empty($data['password'])) $u->password = $data['password'];  // hashed by cast
        $u->save();

        return response()->json(['ok' => true, 'id' => $u->id]);
    }

    /** DELETE /admin-api/users/{id} — remove an account (never self or the last owner). */
    public function deleteUser(int $id)
    {
        $me = \Illuminate\Support\Facades\Auth::guard('admin')->id();
        $u = \App\Models\AdminUser::find($id);
        if (!$u) return response()->json(['error' => 'not_found'], 404);
        if ($u->id === $me) return response()->json(['error' => 'self', 'message' => 'You cannot delete your own account.'], 422);
        if ($u->role === 'owner' && \App\Models\AdminUser::where('role', 'owner')->count() <= 1) {
            return response()->json(['error' => 'last_owner', 'message' => 'You cannot delete the only owner.'], 422);
        }
        $u->delete();
        return response()->json(['ok' => true]);
    }

    /** GET /admin-api/settings — flat { key: value } from store_settings. */
    public function settings()
    {
        return response()->json(['settings' => \App\Models\Setting::map()]);
    }

    /** PUT /admin-api/settings — upsert a whitelisted set of store settings. */
    public function updateSettings(Request $request)
    {
        // Only these keys are editable from the admin (values arrive as plain strings;
        // money keys — free_ship, delivery_flat, cod_fee — are already in fils, converted UI-side).
        $allowed = [
            'store_name', 'currency', 'vat_rate',
            'free_ship', 'delivery_flat', 'cod_fee',
            'meta_pixel', 'ga', 'google_site_verification', 'bing_site_verification', 'seo_json',
            // Search appearance
            'site_url', 'seo_site_name', 'seo_separator', 'seo_title_template',
            'seo_home_title', 'seo_home_description', 'seo_default_description',
            'robots_index', 'robots_follow',
            // Social
            'og_default_image', 'twitter_handle',
            // Organization / schema
            'org_name', 'org_logo', 'org_type',
            // Sitemap / robots
            'sitemap_enabled', 'robots_txt',
        ];

        $incoming = $request->input('settings', []);
        if (!is_array($incoming)) return response()->json(['error' => 'invalid'], 422);

        $saved = 0;
        foreach ($incoming as $key => $value) {
            if (!in_array($key, $allowed, true)) continue;
            \App\Models\Setting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
            $saved++;
        }

        return response()->json(['ok' => true, 'saved' => $saved]);
    }

    /** GET /admin-api/reviews?status= — moderation list (all, or by status). */
    public function reviews(Request $request)
    {
        $q = \App\Models\Review::query();
        if ($request->filled('status')) $q->where('status', $request->query('status'));

        // product-name lookup by slug (reviews key on product_slug)
        $names = Product::pluck('name', 'slug');

        $rows = $q->orderByDesc('id')->get()->map(function ($r) use ($names) {
            return [
                'id'           => $r->id,
                'product_slug' => $r->product_slug,
                'product'      => $names[$r->product_slug] ?? $r->product_slug,
                'author'       => $r->author,
                'rating'       => (int) $r->rating,
                'title'        => $r->title,
                'body'         => $r->body,
                'verified'     => (bool) $r->verified,
                'likes'        => (int) $r->likes,
                'reply'        => $r->reply,
                'status'       => $r->status,
                'created_at'   => $r->created_at,
            ];
        });

        $counts = [
            'all'      => \App\Models\Review::count(),
            'pending'  => \App\Models\Review::where('status', 'pending')->count(),
            'approved' => \App\Models\Review::where('status', 'approved')->count(),
            'rejected' => \App\Models\Review::where('status', 'rejected')->count(),
        ];

        return response()->json(['reviews' => $rows, 'counts' => $counts]);
    }

    /** PUT /admin-api/reviews/{id} — moderate one review (status and/or reply). */
    public function updateReview(Request $request, int $id)
    {
        $r = \App\Models\Review::find($id);
        if (!$r) return response()->json(['error' => 'not_found'], 404);

        $data = $request->validate([
            'status' => 'sometimes|string|in:pending,approved,rejected',
            'reply'  => 'sometimes|nullable|string|max:5000',
        ]);

        if (array_key_exists('status', $data)) {
            $r->status = $data['status'];
        }
        if (array_key_exists('reply', $data)) $r->reply = $data['reply'];
        $r->save();

        return response()->json(['ok' => true, 'id' => $r->id, 'status' => $r->status]);
    }

    /** POST /admin-api/reviews/bulk — approve / reject / delete many at once. */
    public function bulkReviews(Request $request)
    {
        $data = $request->validate([
            'action' => 'required|string|in:approve,reject,delete',
            'ids'    => 'required|array|min:1',
            'ids.*'  => 'integer',
        ]);

        $n = 0;
        foreach (\App\Models\Review::whereIn('id', $data['ids'])->get() as $r) {
            if ($data['action'] === 'delete') { $r->delete(); $n++; continue; }
            $r->status = $data['action'] === 'approve' ? 'approved' : 'rejected';
            $r->save(); $n++;
        }

        return response()->json(['ok' => true, 'affected' => $n]);
    }

    /** GET /admin-api/customers — customer list with order count + lifetime spend. */
    public function customers()
    {
        $agg = Order::select('customer_id', DB::raw('count(*) as n'), DB::raw('sum(total) as s'))
            ->groupBy('customer_id')->get()->keyBy('customer_id');

        $rows = Customer::orderByDesc('id')->get()->map(function (Customer $c) use ($agg) {
            $a = $agg->get($c->id);
            return [
                'id'         => $c->id,
                'name'       => $c->name,
                'email'      => $c->email,
                'phone'      => $c->phone,
                'emirate'    => $c->emirate,
                'orders'     => $a ? (int) $a->n : 0,
                'spent_aed'  => $a ? (int) round(($a->s ?? 0) / 100) : 0,
                'created_at' => $c->created_at,
            ];
        });

        return response()->json(['customers' => $rows]);
    }

    /** GET /admin-api/quiz-leads — skin-quiz submissions / leads. */
    public function quizLeads()
    {
        $decode = function ($v) {
            if (is_array($v)) return $v;
            if (is_string($v) && $v !== '') {
                $d = json_decode($v, true);
                if (is_array($d)) return $d;
                return array_values(array_filter(array_map('trim', explode(',', $v)), fn ($x) => $x !== ''));
            }
            return [];
        };

        $rows = \App\Models\QuizSubmission::orderByDesc('id')->get()->map(function ($q) use ($decode) {
            $concerns = $decode($q->concerns);
            $routines = $decode($q->recommended_routines);
            return [
                'id'          => $q->id,
                'name'        => $q->name,
                'email'       => $q->email,
                'phone'       => $q->phone,
                'skin_type'   => $q->skin_type,
                'concerns'    => $concerns,
                'recommended' => array_map(fn ($r) => is_array($r) ? ($r['name'] ?? ($r['title'] ?? '')) : $r, $routines),
                'expert'      => (bool) $q->expert_requested,
                'status'      => $q->status ?: 'new',
                'created_at'  => $q->created_at,
            ];
        });

        return response()->json(['leads' => $rows]);
    }

    /** PUT /admin-api/orders/{id}/status — change an order's status. */
    public function updateOrderStatus(Request $request, int $id)
    {
        $o = Order::find($id);
        if (!$o) return response()->json(['error' => 'not_found'], 404);

        $data = $request->validate([
            'status' => 'required|string|in:pending,processing,onhold,completed,cancelled,refunded,failed',
        ]);

        $o->status = $data['status'];
        $o->updated_at = now()->toISOString();
        $o->save();

        return response()->json(['ok' => true, 'id' => $o->id, 'status' => $o->status]);
    }
}
