<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\Review;
use Database\Seeders\DemoReviewsSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo Content — sample data an admin can import and remove freely while
 * exploring the panel, without ever touching real orders, customers, or
 * products. Every record a generator creates is logged in demo_seed_log
 * (type, model, id) the moment it's created, so removal deletes exactly
 * those rows by id — never a query that guesses at "demo-looking" data by
 * name or date, which could catch something real.
 *
 * Each of the seven types is deliberately self-contained: Demo Orders
 * creates its own demo customer and products rather than depending on
 * Demo Customers / Demo Products having been imported first, and Demo
 * Reviews creates its own product the same way. A little more data
 * created overall, but it means importing or removing any one type can
 * never leave another type half-broken, and the types can be clicked in
 * any order without a hidden dependency failing partway through.
 */
class DemoContentController extends Controller
{
    private const TYPES = ['customers', 'products', 'orders', 'pages', 'posts', 'reviews', 'menu', 'routines', 'videos'];

    /**
     * Self-healing rather than trusting the migration ran: if an update's
     * migration step was ever skipped or failed silently, every endpoint
     * here would otherwise throw on a missing table and — on production,
     * with APP_DEBUG off — Laravel returns an HTML error page, not JSON.
     * The frontend's response.json() call then throws its own parse error,
     * which surfaces as a generic "could not import" with the real cause
     * invisible. Checking and creating the table here removes that whole
     * failure mode outright, the same pattern already used in
     * MegaMenuApiController::ensureColumns().
     */
    private function ensureTable(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('demo_seed_log')) {
            \Illuminate\Support\Facades\Schema::create('demo_seed_log', function (\Illuminate\Database\Schema\Blueprint $t) {
                $t->id();
                $t->string('type');
                $t->string('model');
                $t->unsignedBigInteger('record_id');
                $t->timestamp('created_at')->useCurrent();
                $t->index(['type']);
                $t->index(['model', 'record_id']);
            });
        }
    }

    public function status(): JsonResponse
    {
        try {
            $this->ensureTable();
            $out = [];
            foreach (self::TYPES as $type) {
                $out[$type] = DB::table('demo_seed_log')->where('type', $type)->count();
            }

            return response()->json(['counts' => $out]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $this->friendlyError($e)], 500);
        }
    }

    public function import(string $type): JsonResponse
    {
        if (!in_array($type, self::TYPES, true)) {
            return response()->json(['ok' => false, 'message' => 'Unknown demo content type.'], 422);
        }

        try {
            $this->ensureTable();

            if (DB::table('demo_seed_log')->where('type', $type)->exists()) {
                return response()->json(['ok' => true, 'already' => true, 'count' => $this->countFor($type)]);
            }

            $count = DB::transaction(fn () => $this->{'seed' . ucfirst($type)}());

            return response()->json(['ok' => true, 'count' => $count]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $this->friendlyError($e)], 500);
        }
    }

    public function remove(string $type): JsonResponse
    {
        if (!in_array($type, self::TYPES, true)) {
            return response()->json(['ok' => false, 'message' => 'Unknown demo content type.'], 422);
        }

        try {
            $this->ensureTable();
            $removed = $this->removeType($type);

            return response()->json(['ok' => true, 'removed' => $removed]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $this->friendlyError($e)], 500);
        }
    }

    public function importAll(): JsonResponse
    {
        try {
            $this->ensureTable();
            $result = [];
            foreach (self::TYPES as $type) {
                if (DB::table('demo_seed_log')->where('type', $type)->exists()) {
                    $result[$type] = ['already' => true, 'count' => $this->countFor($type)];

                    continue;
                }

                $result[$type] = ['count' => DB::transaction(fn () => $this->{'seed' . ucfirst($type)}())];
            }

            return response()->json(['ok' => true, 'result' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $this->friendlyError($e)], 500);
        }
    }

    public function removeAll(): JsonResponse
    {
        try {
            $this->ensureTable();
            $result = [];
            foreach (self::TYPES as $type) {
                $result[$type] = $this->removeType($type);
            }

            return response()->json(['ok' => true, 'result' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $this->friendlyError($e)], 500);
        }
    }

    /**
     * A message safe to show in a toast — the real exception message when
     * it's short and plain, a generic fallback when it's a multi-line SQL
     * dump or a stack-trace-shaped string that would be useless in a toast
     * anyway. Never swallowed to a bare "failed" the way an uncaught
     * exception reaching Laravel's own handler would render as HTML.
     */
    private function friendlyError(\Throwable $e): string
    {
        $msg = $e->getMessage();
        if ($msg === '' || strlen($msg) > 200 || str_contains($msg, "\n")) {
            return 'Something went wrong (' . class_basename($e) . '). Check the server log for details.';
        }

        return $msg;
    }

    private function countFor(string $type): int
    {
        return DB::table('demo_seed_log')->where('type', $type)->count();
    }

    private function log(string $type, string $model, int $id): void
    {
        DB::table('demo_seed_log')->insert([
            'type' => $type, 'model' => $model, 'record_id' => $id, 'created_at' => now(),
        ]);
    }

    /**
     * Deletes exactly the rows this type's import logged — looked up by
     * model + id, not re-derived from any naming pattern — then clears
     * the log rows themselves so a later re-import starts clean.
     */
    private function removeType(string $type): int
    {
        $rows = DB::table('demo_seed_log')->where('type', $type)->get();
        // Report against what was actually logged, not how many delete() calls
        // ran — a handful of these models cascade at the database level (a
        // product's reviews disappear the moment the product does), so a
        // count built from delete-call results would silently under-report
        // even though every logged row is genuinely gone by the end.
        $expected = $rows->count();

        foreach ($rows->groupBy('model') as $model => $group) {
            if (!class_exists($model)) {
                continue;
            }

            // Demo content must actually disappear, not soft-delete — several
            // of these models (Customer, Order, Product) use SoftDeletes, and
            // a plain delete() would only set deleted_at, leaving the row's
            // unique columns (email, order_number, slug) still occupied and
            // breaking a later re-import with a constraint violation. Where
            // the model supports it, force a real removal; withTrashed()
            // first so an already-soft-deleted demo row is still reachable.
            $query = $model::query();
            if (method_exists($model, 'bootSoftDeletes')) {
                $query = $model::withTrashed();
            }

            foreach ($query->whereIn('id', $group->pluck('record_id'))->get() as $record) {
                method_exists($record, 'forceDelete') ? $record->forceDelete() : $record->delete();
            }
        }

        /*
         * The two demo media FILES are not rows, so the loop above cannot see
         * them. They are deleted through the same UgcMedia::forget() a real
         * clip uses, which re-checks the shape of the path before unlinking and
         * refuses anything that is not a single segment under /uploads/ugc/.
         *
         * A real upload can never collide with these: UgcMedia::place() names
         * every stored file `<kind>-<timestamp>-<10 random chars>.<ext>`, and
         * these two are the fixed names `demo-clip.webm` and `demo-poster.jpg`.
         */
        if ($type === 'videos') {
            $media = app(\App\Services\UgcMedia::class);
            $media->forget('/'.\App\Services\UgcMedia::DIR.'/demo-clip.webm');
            $media->forget('/'.\App\Services\UgcMedia::DIR.'/demo-poster.jpg');
        }

        DB::table('demo_seed_log')->where('type', $type)->delete();

        return $expected;
    }

    private function uniqueSlug(string $base, string $table): string
    {
        $slug = $base;
        $n = 2;
        while (DB::table($table)->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }

    // ---------------------------------------------------------------
    // Generators — each fully self-contained, logging every row it
    // creates immediately so a failure partway through still leaves a
    // fully-removable trail rather than an orphaned half-import.
    // ---------------------------------------------------------------

    /**
     * Two video sections and six clips, so the shoppable-video screens can be
     * understood by looking at them rather than by reading about them.
     *
     * ── WHY THIS TYPE EXISTS AT ALL ─────────────────────────────────────────
     *
     * The owner's words: "I really don't understand the videos rail section,
     * it's really confusing." Every one of those screens draws an empty state
     * on a shop that has never used them, and an empty state cannot show a
     * sections list, a section's clip list, or the per-clip editor -- which is
     * the whole shape he was asking about. Nothing was broken; there was
     * nothing to look at.
     *
     * ── PUBLISHED, AND THAT IS DELIBERATE ───────────────────────────────────
     *
     * These rows carry a real file, a real poster and `rights_status` granted,
     * so they satisfy UgcVideo::published() and a rail built from them actually
     * renders. A demo whose clips are all drafts would demonstrate the admin
     * list and nothing else -- and "see it in action" was the request.
     *
     * It is still inert on the shop as it ships, twice over: `Store -> Modules
     * -> Shoppable video` is OFF, and a rail appears only where a [kbb_videos]
     * shortcode has been written. So seeding this adds nothing to any page
     * until the owner does both of those on purpose.
     *
     * ── THE PRODUCTS ARE REAL ONES ──────────────────────────────────────────
     *
     * Tagged against whatever visible products this shop already has, taken in
     * id order, rather than invented. A demo tile linking to a product that
     * does not exist would 404 the moment he clicked it, which teaches him the
     * feature is broken.
     */
    private function seedVideos(): int
    {
        $media = \App\Support\UgcDemoMedia::materialise();

        if ($media === null) {
            throw new \RuntimeException(
                'Could not write into the uploads/ugc folder, so the demo clips have no video file. '
                .'Check that the web root is writable.'
            );
        }

        /*
         * Real, visible products, in id order, or an empty list. `take(12)` and
         * not all of them: a tile shows a handful, and a demo that tagged the
         * whole catalogue would make the editor's product list unreadable.
         */
        $products = Product::query()
            ->where('is_visible', true)
            ->orderBy('id')
            ->take(12)
            ->get();

        $clips = [
            ['Glass skin in 6 steps', '@layla.skin', 'The order that actually matters, and the two steps you can skip.'],
            ['Salon day: bonding mask', '@jumeirah.glow', 'Fifteen minutes, once a week. This is the one I keep rebuying.'],
            ['SPF that never stings', '@noor.routine', 'Reapplying over makeup without pilling — the trick is the pat, not the rub.'],
            ['Double cleanse, no drama', '@amira.beauty', 'Oil first, foam second. If it squeaks, it was too much.'],
            ['Barrier repair week', '@dxb.skincare', 'Everything off the shelf except three things, for seven days.'],
            ['Under-eye, honestly', '@sara.k.beauty', 'What actually moved the needle, and what did nothing at all.'],
        ];

        $count = 0;
        $videos = [];

        foreach ($clips as $i => [$title, $handle, $caption]) {
            $video = \App\Models\UgcVideo::create([
                'slug' => $this->uniqueSlug(Str::slug($title).'-demo', 'ugc_videos'),
                'title' => $title.' (Demo)',
                'caption' => $caption,
                'status' => 'publish',
                'file_path' => $media['clip'],
                'bytes' => \App\Support\UgcDemoMedia::clipBytes(),
                /*
                 * THE SAME FILE SERVES AS THE TEASER, and that is not a shortcut.
                 * UgcVideo::mediaState() answers MEDIA_POSTER_ONLY when there is
                 * a clip and a poster but no teaser, and a poster-only tile shows
                 * a STILL where a real one loops — so a demo without this would
                 * have demonstrated everything except the 2-3 second loop, which
                 * is the part the owner asked about first.
                 *
                 * Honest, because the clip genuinely IS a 2.7s silent loop at
                 * tile size. A real upload gets a purpose-cut teaser from
                 * UgcTranscoder; this one needs no cutting because it was
                 * recorded at teaser length to begin with.
                 */
                'teaser_path' => $media['clip'],
                'teaser_bytes' => \App\Support\UgcDemoMedia::clipBytes(),
                'poster_path' => $media['poster'],
                'poster_bytes' => \App\Support\UgcDemoMedia::posterBytes(),
                'width' => 270,
                'height' => 480,
                'duration_ms' => 2700,
                'source_platform' => 'upload',
                'creator_handle' => $handle,
                /*
                 * GRANTED, and it is honest: this footage is an abstract
                 * gradient this application generated, so the shop genuinely
                 * does hold the rights to it. A demo that faked a grant on
                 * someone else's clip would be teaching the owner to do the one
                 * thing UgcVideo's rights gate exists to stop.
                 */
                'rights_status' => 'granted',
                'rights_granted_at' => now(),
                'rights_evidence' => 'Demo content generated by this application. Replace with your own clips.',
            ]);

            $this->log('videos', \App\Models\UgcVideo::class, $video->id);
            $count++;
            $videos[] = $video;

            // Two or three products per clip, walking the real catalogue so no
            // two demo tiles look identical.
            if ($products->isNotEmpty()) {
                $attach = [];
                $take = 2 + ($i % 2);

                for ($n = 0; $n < $take; $n++) {
                    $product = $products[($i * 2 + $n) % $products->count()];
                    $attach[$product->id] = ['position' => $n, 'at_ms' => null];
                }

                $video->products()->sync($attach);
            }
        }

        $sections = [
            ['handle' => 'demo-shop-the-look', 'title' => 'Shop the look (Demo)',
             'heading' => 'Shop the look', 'subheading' => 'Real routines from the people who use them.',
             'slice' => [0, 4]],
            ['handle' => 'demo-this-week', 'title' => 'This week on video (Demo)',
             'heading' => 'This week', 'subheading' => 'Three short ones worth two minutes.',
             'slice' => [3, 3]],
        ];

        foreach ($sections as $position => $row) {
            $section = \App\Models\UgcSection::create([
                'handle' => $row['handle'],
                'title' => $row['title'],
                'heading' => $row['heading'],
                'subheading' => $row['subheading'],
                'status' => 'publish',
                'max_tiles' => 12,
                'position' => $position,
            ]);

            $this->log('videos', \App\Models\UgcSection::class, $section->id);
            $count++;

            /*
             * The two sections OVERLAP on purpose -- clips 4 and 5 are in both.
             * One clip belonging to several rails is a real property of this
             * data model and the least obvious one from an empty screen.
             */
            [$from, $len] = $row['slice'];
            $attach = [];

            foreach (array_slice($videos, $from, $len) as $n => $video) {
                $attach[$video->id] = ['position' => $n];
            }

            $section->videos()->sync($attach);
        }

        return $count;
    }

    private function seedCustomers(): int
    {
        $names = ['Amira Hassan', 'Lucia Ferraro', 'Noor Al Mansoori', 'Julia Kowalski', 'Sara Ahmadi', 'Priya Nair'];
        $count = 0;

        foreach ($names as $i => $name) {
            $c = Customer::create([
                'name' => $name,
                'first_name' => explode(' ', $name)[0],
                'last_name' => explode(' ', $name)[1] ?? '',
                'email' => 'demo.customer' . ($i + 1) . '@example.kbb',
                'password' => bcrypt(Str::random(24)),
            ]);
            $this->log('customers', Customer::class, $c->id);
            $count++;
        }

        return $count;
    }

    private function seedProducts(): int
    {
        $brand = Brand::firstOrCreate(['slug' => 'demo-brand'], ['name' => 'Demo Brand']);
        $this->log('products', Brand::class, $brand->id);

        $category = Category::firstOrCreate(['slug' => 'demo-category'], ['name' => 'Demo Category']);
        $this->log('products', Category::class, $category->id);

        $names = [
            'Rice Water Brightening Cream', 'Green Tea Calming Toner', 'Centella Repair Serum',
            'Snail Mucin Essence', 'Vitamin C Glow Ampoule', 'Ceramide Barrier Moisturiser',
            'Niacinamide 10% Serum', 'Hyaluronic Acid Water Gel', 'Propolis Soothing Mist',
            'Retinol Night Cream', 'SPF50 Daily Sunscreen', 'Collagen Sleeping Mask',
            'Tea Tree Blemish Gel', 'Peptide Eye Cream', 'Cica Recovery Balm',
            'Aloe Vera Soothing Gel', 'Lactic Acid Toner', 'Squalane Face Oil',
            'Clay Pore Mask', 'Beta Glucan Ampoule', 'Rose Water Mist',
            'Pore Minimising Serum', 'Brightening Sheet Mask', 'Deep Cleansing Oil',
        ];
        $count = 0;

        foreach ($names as $i => $name) {
            $price = random_int(4900, 29900);
            $onSale = $i % 4 === 0;
            $p = Product::create([
                'name' => $name,
                'slug' => $this->uniqueSlug(Str::slug('demo-' . $name), 'products'),
                'sku' => 'DEMO-P' . ($i + 1),
                'brand_id' => $brand->id,
                'price' => $price,
                'sale_price' => $onSale ? (int) round($price * 0.8) : null,
                'status' => 'publish',
                'is_visible' => true,
                'stock_status' => 'instock',
                'short_description' => 'A real, demo product used to preview catalogue pages before real products are imported.',
            ]);
            $p->categories()->attach($category->id);
            $this->log('products', Product::class, $p->id);
            $count++;
        }

        return $count;
    }

    private function seedOrders(): int
    {
        $brand = Brand::firstOrCreate(['slug' => 'demo-order-brand'], ['name' => 'Demo Brand']);
        $this->log('orders', Brand::class, $brand->id);

        $product = Product::create([
            'name' => 'Rice Water Brightening Cream', 'slug' => $this->uniqueSlug('demo-order-product', 'products'),
            'sku' => 'DEMO-ORD-P1', 'brand_id' => $brand->id, 'price' => 23900, 'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
        ]);
        $this->log('orders', Product::class, $product->id);

        $product2 = Product::create([
            'name' => 'Centella Repair Serum', 'slug' => $this->uniqueSlug('demo-order-product-2', 'products'),
            'sku' => 'DEMO-ORD-P2', 'brand_id' => $brand->id, 'price' => 15900, 'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
        ]);
        $this->log('orders', Product::class, $product2->id);

        $customer = Customer::create([
            'name' => 'Demo Shopper', 'first_name' => 'Demo', 'last_name' => 'Shopper',
            'email' => 'demo.order.customer@example.kbb', 'password' => bcrypt(Str::random(24)),
        ]);
        $this->log('orders', Customer::class, $customer->id);

        $statuses = ['pending', 'processing', 'processing', 'onhold', 'shipped', 'completed', 'cancelled', 'refunded'];
        $count = 0;

        foreach ($statuses as $i => $status) {
            $qty = random_int(1, 2);
            $subtotal = $product->price * $qty;

            $order = Order::create([
                'order_number' => $this->uniqueOrderNumber(),
                'customer_id' => $customer->id,
                'email' => $customer->email,
                'phone' => '05' . random_int(10000000, 99999999),
                'status' => $status,
                'currency' => 'AED',
                'subtotal' => $subtotal,
                'shipping_total' => $subtotal >= 20000 ? 0 : 1500,
                'discount_total' => 0,
                'fee_total' => 0,
                'tax_total' => 0,
                'total' => $subtotal + ($subtotal >= 20000 ? 0 : 1500),
                'payment_method' => 'cod',
                'payment_method_title' => 'Cash on Delivery',
                'shipping_method' => $subtotal >= 20000 ? 'Free shipping' : 'Standard delivery',
                'billing_address' => ['name' => $customer->name, 'line1' => 'Demo Street ' . ($i + 1), 'city' => 'Dubai'],
                'shipping_address' => ['name' => $customer->name, 'line1' => 'Demo Street ' . ($i + 1), 'city' => 'Dubai'],
                'created_at' => now()->subDays(8 - $i),
            ]);
            $order->items()->create([
                'product_id' => $product->id, 'name' => $product->name, 'brand' => $brand->name, 'sku' => $product->sku,
                'quantity' => $qty, 'unit_price' => $product->price, 'subtotal' => $subtotal, 'total' => $subtotal,
            ]);
            $this->log('orders', Order::class, $order->id);
            $count++;
        }

        return $count;
    }

    private function uniqueOrderNumber(): string
    {
        do {
            $n = 'DEMO-' . random_int(10000, 99999);
        } while (DB::table('orders')->where('order_number', $n)->exists());

        return $n;
    }

    private function seedPages(): int
    {
        $pages = [
            ['title' => 'About Us (Demo)', 'content' => '<p>A demo About page, so the page layout can be previewed before writing the real one.</p>'],
            ['title' => 'Shipping & Delivery (Demo)', 'content' => '<p>Demo shipping information — replace with your real delivery timelines and costs.</p>'],
            ['title' => 'Returns & Exchanges (Demo)', 'content' => '<p>Demo returns policy — replace with your real return window and process.</p>'],
            ['title' => 'FAQ (Demo)', 'content' => '<p>Demo frequently-asked questions — replace with your real ones.</p>'],
        ];
        $count = 0;

        foreach ($pages as $p) {
            $page = Page::create([
                'slug' => $this->uniqueSlug(Str::slug($p['title']), 'pages'),
                'title' => $p['title'],
                'content' => $p['content'],
                // Published, not draft — the whole point of this demo content is
                // to actually be visible so the page layout can be previewed; a
                // draft page 404s on the public site (PageController::show()
                // filters to status=published), which defeats the purpose.
                'status' => 'published',
            ]);
            $this->log('pages', Page::class, $page->id);
            $count++;
        }

        return $count;
    }

    private function seedPosts(): int
    {
        $posts = [
            ['title' => 'The 10-step routine, simplified (Demo)', 'tag' => 'Routine', 'excerpt' => 'A demo article — replace with your real content.'],
            ['title' => 'Centella vs. Cica: what calms skin (Demo)', 'tag' => 'Ingredients', 'excerpt' => 'A demo article — replace with your real content.'],
            ['title' => 'Why Korean sunscreens win for daily wear (Demo)', 'tag' => 'SPF', 'excerpt' => 'A demo article — replace with your real content.'],
            ['title' => 'Building a routine for sensitive skin (Demo)', 'tag' => 'Routine', 'excerpt' => 'A demo article — replace with your real content.'],
        ];
        $count = 0;

        foreach ($posts as $p) {
            $post = Post::create([
                'slug' => $this->uniqueSlug(Str::slug($p['title']), 'posts'),
                'title' => $p['title'],
                'excerpt' => $p['excerpt'],
                'body' => '<p>' . $p['excerpt'] . '</p>',
                'tag' => $p['tag'],
                // Published, not draft — same reasoning as seedPages(): a demo
                // blog post that 404s on the public /blog and /post/{slug}
                // pages (PageController::blog()/post() both filter to
                // status=published) isn't actually demonstrating anything.
                'status' => 'published',
                'published_at' => now(),
            ]);
            $this->log('posts', Post::class, $post->id);
            $count++;
        }

        return $count;
    }

    private function seedReviews(): int
    {
        $brand = Brand::firstOrCreate(['slug' => 'demo-review-brand'], ['name' => 'Demo Brand']);
        $this->log('reviews', Brand::class, $brand->id);

        $product = Product::create([
            'name' => 'Niacinamide 10% Serum', 'slug' => $this->uniqueSlug('demo-review-product', 'products'),
            'sku' => 'DEMO-REV-P1', 'brand_id' => $brand->id, 'price' => 12900, 'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
        ]);
        $this->log('reviews', Product::class, $product->id);

        $samples = [
            [5, 'Amira H.', 'Loved it', 'Genuinely worked within two weeks, very gentle.', 'approved'],
            [4, 'Lucia F.', 'Good, a bit sticky', 'Effective but takes a moment to absorb fully.', 'approved'],
            [5, 'Noor A.', 'Repurchasing', 'This is now a staple in my routine.', 'approved'],
            [3, 'Julia K.', 'Okay for the price', 'Did not see dramatic results but no irritation either.', 'approved'],
            [5, 'Sara A.', 'Great texture', 'Layers well under sunscreen, no pilling.', 'pending'],
        ];
        $count = 0;

        foreach ($samples as [$rating, $name, $title, $content, $status]) {
            /*
             * `verified` IS FALSE, AND `source` SAYS WHERE THE ROW CAME FROM.
             *
             * These rows were written with 'verified' => true. That flag is
             * what puts a "✓ Verified" tick beside a reviewer's name on the
             * product page — it is a statement that this named person bought
             * this product from this shop. The names are invented and the
             * addresses are @example.kbb. Nobody verified anything, so the
             * column says so.
             *
             * `source` is stamped to match DemoReviewsSeeder. Until now these
             * rows were recorded ONLY in `demo_seed_log`, while the seeder's
             * rows were marked ONLY by `source` — two provenance schemes, each
             * blind to the other's rows. App\Support\DemoReviews has to ask
             * both questions because rows already on the server predate this;
             * stamping it here means anything seeded from now on is knowable
             * from the `reviews` table alone.
             */
            $r = Review::create([
                'product_id' => $product->id, 'author_name' => $name, 'author_email' => Str::slug($name) . '@example.kbb',
                'rating' => $rating, 'title' => $title, 'content' => $content, 'status' => $status,
                'verified' => false, 'source' => DemoReviewsSeeder::SOURCE,
            ]);
            $this->log('reviews', Review::class, $r->id);
            $count++;
        }

        return $count;
    }

    private function seedMenu(): int
    {
        $before = DB::table('menus')->max('id') ?? 0;

        app(MegaMenuApiController::class)->loadDemo(request());

        $menu = DB::table('menus')->where('id', '>', $before)->first();

        if ($menu) {
            $this->log('menu', \App\Models\Menu::class, $menu->id);

            foreach (DB::table('menu_items')->where('menu_id', $menu->id)->pluck('id') as $itemId) {
                $this->log('menu', \App\Models\MenuItem::class, $itemId);
            }

            return 1;
        }

        return 0;
    }

    /**
     * Demo content for Phase 10 — Build my routine (Lane Q, round 3).
     *
     * ── WHY THIS EXISTS ────────────────────────────────────────────────────
     *
     * The owner asked for it in as many words: "for the routines okay, but also
     * give option to import demo data". Every routine on the storefront is
     * filled from products HE has tagged, so a shop that has not done the
     * tagging draws every step as "not stocked yet" — and the tagging is a
     * two-to-three hour job he is trying to decide whether to commit to. He
     * could not see the thing working before paying for it.
     *
     * ── THE ONE DECISION THAT MATTERS: NO CONCERNS ON ANY OF THEM ──────────
     *
     * These five rows carry a routine_role and NO routine_concerns, and that is
     * not laziness — it is the whole safety argument, and it is made out of
     * behaviour this shop already has rather than a new exclusion rule.
     *
     * An empty concern list means "suits any routine" (RoutineConcerns::clean's
     * docblock). So:
     *
     *   1. EVERY ROUTINE FILLS. All eight concerns draw all five steps from
     *      these, which is a better demonstration than tagging them for one
     *      concern would have been.
     *
     *   2. NO CONCERN PAGE CAN BE PUBLISHED BY DEMO DATA, BY CONSTRUCTION.
     *      App\Support\ConcernCollections::query() selects
     *      `routine_concerns LIKE '%"slug"%'` — EXPLICIT tags only — so a row
     *      with none can never match, never counts towards MIN_PRODUCTS, and
     *      can never take /concern/{slug}/ from 404 to 200. That matters more
     *      than it looks: a concern page published off demo data would enter
     *      the sitemap, be offered by the quiz hand-off, and then 404 the day
     *      he pressed Remove — teaching Google the shop has dead pages.
     *
     *   3. THE CONCERN COUNTDOWN ON CATALOG -> BUILD MY ROUTINE IS UNMOVED,
     *      for the same reason: it counts explicit tags only
     *      (BuildMyRoutine::concernPageProgress). He can import this, look at
     *      it, remove it, and the number that tells him how close he is to a
     *      real landing page never moved.
     *
     * The five STEP tabs do move, and should: that is what he is importing this
     * to see. Remove the demo and they go back to zero.
     *
     * ── AND IT STILL CANNOT REACH A SHOPPER BY ACCIDENT ────────────────────
     *
     * /routines and /routines/{concern} are behind `build_my_routine`, which
     * ships OFF and which this does not touch. If he switches the module on to
     * look, the Build my routine screen carries a standing banner naming these
     * rows for as long as they exist, and every one of them is called "Demo —"
     * on the shelf. A demo routine that reaches a shopper unannounced is worse
     * than no demo at all.
     */
    private function seedRoutines(): int
    {
        $brand = Brand::firstOrCreate(
            ['slug' => 'demo-routine-brand'],
            ['name' => 'Demo Routine Co.']
        );
        // Its own brand, NOT the one seedReviews() makes. Sharing that row would
        // mean removing the routine demo deletes a brand the review demo still
        // has products on.
        $this->log('routines', Brand::class, $brand->id);

        $steps = [
            ['cleanse', 'Demo — Low-pH Gel Cleanser', 'DEMO-RTN-1', 4500,
                'Water, Cocamidopropyl Betaine, Glycerin, Centella Asiatica Extract, Panthenol.'],
            ['tone', 'Demo — Hydrating Essence Toner', 'DEMO-RTN-2', 6900,
                'Water, Butylene Glycol, Hyaluronic Acid, Panthenol, Allantoin. Fragrance-free.'],
            ['treat', 'Demo — Centella Repair Ampoule', 'DEMO-RTN-3', 9900,
                'Water, Centella Asiatica Extract, Madecassoside, Niacinamide 5%, Squalane.'],
            ['moisturise', 'Demo — Ceramide Barrier Cream', 'DEMO-RTN-4', 11900,
                'Water, Glycerin, Ceramide NP, Cholesterol, Shea Butter, Panthenol.'],
            ['protect', 'Demo — Daily Mineral Sunscreen SPF 50', 'DEMO-RTN-5', 8900,
                'Water, Zinc Oxide 12%, Titanium Dioxide, Glycerin, Centella Asiatica Extract.'],
        ];

        $count = 0;

        foreach ($steps as [$role, $name, $sku, $price, $ingredients]) {
            $product = Product::create([
                'name' => $name,
                'slug' => $this->uniqueSlug(Str::slug($name), 'products'),
                'sku' => $sku,
                'brand_id' => $brand->id,
                'price' => $price,
                'status' => 'publish',
                'is_visible' => true,
                'stock_status' => 'instock',
                'routine_role' => $role,
                /*
                 * NULL, DELIBERATELY, AND THE DOCBLOCK ABOVE IS WHY. Not [] and
                 * not a concern list: NULL is the value an untouched row already
                 * carries, so these rows are indistinguishable from "suits every
                 * routine" to every reader of the column.
                 */
                'routine_concerns' => null,
                'ingredients' => $ingredients,
                'short_description' => 'Demo content for Build my routine. Remove it from Store → Demo Content.',
            ]);

            $this->log('routines', Product::class, $product->id);
            $count++;
        }

        return $count;
    }
}
