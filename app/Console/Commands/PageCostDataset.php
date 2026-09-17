<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The catalogue the measurement runs against.
 *
 * WHY NOT THE DEMO SEEDER. DemoCatalogueSeeder writes 24 products across 8
 * brands, which is the right size for a test fixture and the wrong size for
 * this question entirely. At 24 rows MySQL reads the whole table for anything
 * you ask it, an index makes no measurable difference, and a page that sorts
 * the entire catalogue to return twenty-four cards is indistinguishable from
 * one that seeks. Every number that matters here only appears at volume.
 *
 * THE VOLUME IS THE LIVE SHOP'S, rounded up. The WooCommerce store this is a
 * port of carries 671 products across 93 brands (the figures quoted in
 * ShopController and in the Phase 0 schema), 3,712 customers and a WebToffee
 * invoice sequence in the thousands. The defaults here are 3,000 products,
 * 3,000 customers, 6,000 orders and roughly 30,000 order lines: comfortably
 * past today's catalogue, so an index that is merely break-even at 671 rows
 * still shows its shape, and small enough to rebuild in under a minute.
 *
 * NOT RANDOM WHERE RANDOMNESS WOULD MATTER. mt_srand() is pinned, so two runs
 * of --seed produce byte-identical tables and a before/after comparison is
 * comparing the same catalogue. Product names are drawn from a fixed noun list
 * that includes "Serum" roughly one time in nine, which is what gives the
 * search measurement a realistic number of hits instead of one or none.
 *
 * WRITTEN THROUGH THE QUERY BUILDER IN CHUNKS, not through Eloquent: 30,000
 * order lines through Model::create() is several minutes and 30,000 INSERT
 * statements, and nothing here needs a model event.
 */
final class PageCostDataset
{
    public const ADMIN_EMAIL = 'page-cost-admin@example.test';

    public const CUSTOMER_EMAIL = 'page-cost-shopper@example.test';

    public const PASSWORD = 'page-cost-secret';

    /** The product the "many images, many reviews" measurement uses. */
    public const HERO_SLUG = 'page-cost-hero-product';

    public const HERO_REVIEWS = 400;

    private const NOUNS = [
        'Serum', 'Cleanser', 'Toner', 'Essence', 'Cream', 'Mask',
        'Sunscreen', 'Ampoule', 'Balm',
    ];

    /** Filler for --translate. See arabicOf(); this is a byte fixture, not a translation. */
    private const ARABIC_WORDS = ['منتج', 'للبشرة', 'مرطب', 'يومي', 'لطيف', 'عناية', 'كوري', 'أصلي', 'تركيبة', 'حاجز'];

    private const ADJECTIVES = [
        'Hydrating', 'Brightening', 'Calming', 'Renewing', 'Barrier',
        'Glow', 'Clarifying', 'Nourishing',
    ];

    public function __construct(private Command $out) {}

    public function build(int $products, int $orders, int $reviews): void
    {
        mt_srand(20260917);

        $this->out->line('Seeding the measurement catalogue. This takes a minute.');

        $brandIds = $this->brands(93);
        $categoryIds = $this->categories(20);
        $productIds = $this->products($products, $brandIds, $categoryIds);
        $hero = $this->hero($brandIds, $categoryIds);
        $customerIds = $this->customers(3000);
        $this->orders($orders, $productIds, $customerIds);
        $this->reviews($reviews, $productIds, $customerIds);
        $this->heroReviews($hero, $customerIds);
        $this->refreshRatings();
        $this->admin();
        $this->basket($productIds);

        $this->out->line('Seeded.');
    }

    /* ------------------------------------------------------------ catalogue */

    /** @return list<int> */
    private function brands(int $target): array
    {
        $existing = DB::table('brands')->count();
        $rows = [];

        for ($i = $existing; $i < $target; $i++) {
            $rows[] = [
                'slug' => 'pc-brand-'.$i,
                'name' => 'Brand '.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'position' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->insert('brands', $rows);

        return DB::table('brands')->pluck('id')->all();
    }

    /** @return list<int> */
    private function categories(int $target): array
    {
        $existing = DB::table('categories')->count();
        $rows = [];

        for ($i = $existing; $i < $target; $i++) {
            $slug = 'pc-category-'.$i;
            $rows[] = [
                'slug' => $slug,
                'name' => 'Category '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'path' => $slug,
                'depth' => 0,
                'position' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->insert('categories', $rows);

        return DB::table('categories')->pluck('id')->all();
    }

    /** @return list<int> */
    private function products(int $count, array $brandIds, array $categoryIds): array
    {
        $rows = [];
        $pivot = [];
        $start = (int) DB::table('products')->max('id');

        for ($i = 1; $i <= $count; $i++) {
            $noun = self::NOUNS[$i % count(self::NOUNS)];
            $adjective = self::ADJECTIVES[$i % count(self::ADJECTIVES)];
            $price = 4900 + (($i * 137) % 45000);
            $onSale = $i % 7 === 0;

            $rows[] = [
                'slug' => 'pc-product-'.$i,
                'name' => $adjective.' '.$noun.' No. '.$i,
                'sku' => 'PC-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'brand_id' => $brandIds[$i % count($brandIds)],
                'category_id' => $categoryIds[$i % count($categoryIds)],
                'type' => 'simple',
                'status' => 'publish',
                'is_visible' => 1,
                'price' => $price,
                'sale_price' => $onSale ? (int) ($price * 0.7) : null,
                'stock_status' => $i % 11 === 0 ? 'outofstock' : 'instock',
                'short_description' => 'A '.strtolower($adjective).' '.strtolower($noun).' for daily use.',
                // Several KB of body copy on every row, because that is what a
                // real product carries and it is exactly what a listing must
                // not select. A fixture of empty descriptions cannot show the
                // cost of SELECT *.
                'description' => str_repeat('<p>'.$adjective.' '.$noun.' — formulated for the UAE climate. </p>', 40),
                // The other two prose tabs a product page draws, because they
                // are on Product::$translatable and so are part of what a
                // translated catalogue puts in the cached map.
                'ingredients' => '<p>Water, Glycerin, Niacinamide, Butylene Glycol, Centella Asiatica Extract, '
                    .'1,2-Hexanediol, Panthenol, Cellulose Gum, Ethylhexylglycerin, Sodium Hyaluronate.</p>',
                'how_to_use' => str_repeat('<p>After cleansing, apply to a cotton pad and sweep over the face. </p>', 3),
                'image' => '/wp-content/uploads/pc/'.$i.'-front.jpg',
                'images' => json_encode([
                    '/wp-content/uploads/pc/'.$i.'-texture.jpg',
                    '/wp-content/uploads/pc/'.$i.'-box.jpg',
                ]),
                'rating' => 0,
                'review_count' => 0,
                'total_sales' => ($i * 31) % 900,
                'featured' => $i % 40 === 0 ? 1 : 0,
                'position' => $i % 500,
                'created_at' => now()->subMinutes($count - $i),
                'updated_at' => now(),
            ];

            if (count($rows) >= 500) {
                $this->insert('products', $rows);
                $rows = [];
            }
        }

        $this->insert('products', $rows);

        $ids = DB::table('products')->where('id', '>', $start)->pluck('id')->all();

        foreach ($ids as $n => $id) {
            $pivot[] = ['category_id' => $categoryIds[$n % count($categoryIds)], 'product_id' => $id];

            if ($n % 3 === 0) {
                $pivot[] = ['category_id' => $categoryIds[($n + 5) % count($categoryIds)], 'product_id' => $id];
            }

            if (count($pivot) >= 1000) {
                $this->insert('category_product', $pivot);
                $pivot = [];
            }
        }

        $this->insert('category_product', $pivot);

        $this->out->line('  products: '.DB::table('products')->count());

        return DB::table('products')->pluck('id')->all();
    }

    /**
     * The product the "many images and many reviews" row of the table is about.
     *
     * Twelve gallery images and 400 approved reviews, which is the shape of the
     * worst product page a real catalogue contains. Measuring the median
     * product instead would report a page that costs nothing and say nothing
     * about the one that does.
     */
    private function hero(array $brandIds, array $categoryIds): int
    {
        DB::table('products')->where('slug', self::HERO_SLUG)->delete();

        $images = [];

        for ($i = 1; $i <= 12; $i++) {
            $images[] = '/wp-content/uploads/pc/hero-'.$i.'.jpg';
        }

        $id = DB::table('products')->insertGetId([
            'slug' => self::HERO_SLUG,
            'name' => 'Heartleaf Barrier Serum, the one everybody reviews',
            'sku' => 'PC-HERO-1',
            'brand_id' => $brandIds[0],
            'category_id' => $categoryIds[0],
            'type' => 'simple',
            'status' => 'publish',
            'is_visible' => 1,
            'price' => 18900,
            'sale_price' => 13900,
            'stock_status' => 'instock',
            'short_description' => 'The most reviewed product in the shop.',
            'description' => str_repeat('<p>Heartleaf, panthenol and a barrier everybody has an opinion about. </p>', 60),
            'image' => $images[0],
            'images' => json_encode(array_slice($images, 1)),
            'total_sales' => 9999,
            'featured' => 1,
            'created_at' => now()->subYear(),
            'updated_at' => now(),
        ]);

        DB::table('category_product')->insertOrIgnore([
            ['category_id' => $categoryIds[0], 'product_id' => $id],
            ['category_id' => $categoryIds[1], 'product_id' => $id],
        ]);

        return $id;
    }

    /* ------------------------------------------------------------ customers */

    /** @return list<int> */
    private function customers(int $count): array
    {
        $hash = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);

        DB::table('customers')->where('email', self::CUSTOMER_EMAIL)->delete();

        DB::table('customers')->insert([
            'name' => 'Page Cost Shopper',
            'first_name' => 'Page',
            'last_name' => 'Cost',
            'email' => self::CUSTOMER_EMAIL,
            'email_verified_at' => now(),
            'password' => $hash,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $existing = DB::table('customers')->count();
        $rows = [];

        for ($i = $existing; $i < $count; $i++) {
            $rows[] = [
                'name' => 'Customer '.$i,
                'first_name' => 'Customer',
                'last_name' => (string) $i,
                'email' => 'pc-customer-'.$i.'@example.test',
                'email_verified_at' => now(),
                'password' => $hash,
                'created_at' => now()->subDays($i % 700),
                'updated_at' => now(),
            ];

            if (count($rows) >= 500) {
                $this->insert('customers', $rows);
                $rows = [];
            }
        }

        $this->insert('customers', $rows);

        $this->out->line('  customers: '.DB::table('customers')->count());

        return DB::table('customers')->pluck('id')->all();
    }

    /* --------------------------------------------------------------- orders */

    private function orders(int $count, array $productIds, array $customerIds): void
    {
        $known = (int) DB::table('customers')->where('email', self::CUSTOMER_EMAIL)->value('id');
        $statuses = ['completed', 'processing', 'pending', 'cancelled', 'refunded', 'shipped'];
        $start = (int) DB::table('orders')->max('id');
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            // Every fiftieth order belongs to the shopper whose account pages
            // are measured, so that account has a list worth paginating rather
            // than a single row.
            $customer = $i % 50 === 0 ? $known : $customerIds[$i % count($customerIds)];
            $subtotal = 9900 + (($i * 313) % 120000);

            $rows[] = [
                'order_number' => 'PC-'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'customer_id' => $customer,
                'email' => 'pc-order-'.$i.'@example.test',
                'status' => $statuses[$i % count($statuses)],
                'currency' => 'AED',
                'billing_address' => json_encode(['first_name' => 'Page', 'city' => 'Dubai', 'country' => 'AE']),
                'shipping_address' => json_encode(['first_name' => 'Page', 'city' => 'Dubai', 'country' => 'AE']),
                'subtotal' => $subtotal,
                'shipping_total' => 1500,
                'total' => $subtotal + 1500,
                'shipping_method' => 'Flat rate',
                'payment_method' => $i % 2 ? 'cod' : 'stripe',
                'payment_method_title' => $i % 2 ? 'Cash on delivery' : 'Card',
                'paid_at' => $i % 3 ? now()->subDays($i % 400) : null,
                'created_at' => now()->subDays($i % 400)->subMinutes($i % 1440),
                'updated_at' => now()->subDays($i % 400),
            ];

            if (count($rows) >= 500) {
                $this->insert('orders', $rows);
                $rows = [];
            }
        }

        $this->insert('orders', $rows);

        $items = [];
        $lines = 0;

        foreach (DB::table('orders')->where('id', '>', $start)->select('id', 'created_at')->cursor() as $order) {
            $per = 1 + ($order->id % 8);

            for ($n = 0; $n < $per; $n++) {
                $productId = $productIds[($order->id * 7 + $n) % count($productIds)];
                $unit = 4900 + (($productId * 97) % 40000);
                $quantity = 1 + ($n % 3);

                $items[] = [
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'name' => 'Line '.$n.' of order '.$order->id,
                    'sku' => 'PC-'.$productId,
                    'quantity' => $quantity,
                    'unit_price' => $unit,
                    'subtotal' => $unit * $quantity,
                    'total' => $unit * $quantity,
                    'created_at' => $order->created_at,
                    'updated_at' => $order->created_at,
                ];
                $lines++;

                if (count($items) >= 1000) {
                    $this->insert('order_items', $items);
                    $items = [];
                }
            }
        }

        $this->insert('order_items', $items);

        // The derived customer columns the admin's customer list and the
        // account dashboard both read. Left at zero they would make every
        // aggregate on those screens trivially cheap and wrong.
        DB::statement('UPDATE customers c JOIN (
                SELECT customer_id, COUNT(*) n, SUM(total) t, MAX(created_at) last
                FROM orders WHERE customer_id IS NOT NULL AND deleted_at IS NULL GROUP BY customer_id
            ) o ON o.customer_id = c.id
            SET c.orders_count = o.n, c.total_spent = o.t, c.last_order_at = o.last');

        $this->out->line('  orders: '.DB::table('orders')->count().', order lines: '.$lines);
    }

    /* -------------------------------------------------------------- reviews */

    private function reviews(int $count, array $productIds, array $customerIds): void
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = $this->review(
                $productIds[($i * 13) % count($productIds)],
                $customerIds[$i % count($customerIds)],
                $i,
                // A real moderation queue is not entirely approved.
                $i % 12 === 0 ? 'pending' : 'approved',
            );

            if (count($rows) >= 1000) {
                $this->insert('reviews', $rows);
                $rows = [];
            }
        }

        $this->insert('reviews', $rows);
    }

    private function heroReviews(int $heroId, array $customerIds): void
    {
        $rows = [];

        for ($i = 1; $i <= self::HERO_REVIEWS; $i++) {
            $rows[] = $this->review($heroId, $customerIds[$i % count($customerIds)], $i, 'approved');

            if (count($rows) >= 500) {
                $this->insert('reviews', $rows);
                $rows = [];
            }
        }

        $this->insert('reviews', $rows);

        $this->out->line('  reviews: '.DB::table('reviews')->count().' ('.self::HERO_REVIEWS.' on the hero product)');
    }

    /**
     * `source` is deliberately NOT 'demo'.
     *
     * App\Support\DemoReviews::exclude() is applied by every storefront reader
     * of this table, so a fixture stamped 'demo' would be filtered out of the
     * product page entirely and the measurement would report the cost of
     * rendering nothing.
     */
    private function review(int $productId, int $customerId, int $n, string $status): array
    {
        return [
            'source' => 'wp_comment',
            'product_id' => $productId,
            'customer_id' => $customerId,
            'author_name' => 'Reviewer '.$n,
            'author_email' => 'pc-reviewer-'.$n.'@example.test',
            'rating' => 1 + ($n % 5),
            'title' => 'Review number '.$n,
            'content' => 'It arrived quickly and the texture is light. Review body number '.$n.'.',
            'status' => $status,
            'verified' => $n % 2,
            'helpful' => $n % 30,
            'ip' => '127.0.0.1',
            'created_at' => now()->subDays($n % 500),
            'updated_at' => now()->subDays($n % 500),
        ];
    }

    /** products.rating and products.review_count, from the rows that exist. */
    private function refreshRatings(): void
    {
        DB::statement("UPDATE products p JOIN (
                SELECT product_id, AVG(rating) a, COUNT(*) n FROM reviews
                WHERE status = 'approved' AND product_id IS NOT NULL
                GROUP BY product_id
            ) r ON r.product_id = p.id
            SET p.rating = ROUND(r.a, 1), p.review_count = r.n");
    }

    /* --------------------------------------------------------------- chrome */

    private function admin(): void
    {
        DB::table('admin_users')->where('email', self::ADMIN_EMAIL)->delete();

        DB::table('admin_users')->insert([
            'name' => 'Page Cost Owner',
            'email' => self::ADMIN_EMAIL,
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A guest basket of six lines, for the /cart and /checkout measurements. */
    private function basket(array $productIds): void
    {
        DB::table('carts')->where('status', 'active')->delete();

        $cartId = DB::table('carts')->insertGetId([
            'token' => (string) Str::uuid(),
            'currency' => 'AED',
            'status' => 'active',
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = [];

        foreach (array_slice($productIds, 0, 6) as $productId) {
            $rows[] = [
                'cart_id' => $cartId,
                'product_id' => $productId,
                'quantity' => 2,
                'unit_price' => 9900,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->insert('cart_items', $rows);
    }

    /* --------------------------------------------------- the second language */

    /**
     * Fill one locale's translations table to the brim, and switch Arabic on.
     *
     * WHY THIS IS SEPARATE FROM build(). The English measurement is the
     * baseline this lane compares against, and a baseline that carried a
     * translations table would not be a baseline. `--seed` builds the shop;
     * `--translate` translates it. Run the second on top of the first and the
     * only thing that moved is the second language.
     *
     * WHAT "FULL TRANSLATION" MEANS HERE, AND WHY IT IS THE HONEST CASE.
     * Every translatable field of every content row, plus every interface
     * string, PUBLISHED. That is the state the owner is working towards over
     * his ~55 hours, so it is the state the cached map has to be affordable in.
     * Measuring a shop with twenty-four translations measures nothing: it is
     * the SIZE OF THE MAP that is the open question, and twenty-four rows do
     * not have a size.
     *
     * ARABIC IS TWO BYTES PER LETTER in UTF-8 where English is one, and PHP
     * measures a string in bytes. So the Arabic written here is the same
     * CHARACTER length as the English it replaces — which is roughly what a
     * real translation is — and therefore about twice the bytes. A fixture of
     * ASCII placeholders would under-report the memory by half.
     */
    public function translations(string $locale, int $draftEvery = 0): void
    {
        mt_srand(20260918);

        $this->out->line('Translating the shop into '.$locale.'. This takes a minute.');

        $rows = [];
        $written = 0;
        $drafts = 0;
        $n = 0;

        $push = function (string $group, int $itemId, string $field, string $english, bool $draft)
            use (&$rows, &$written, &$drafts, $locale): void {
            $rows[] = [
                'locale' => $locale,
                'group' => $group,
                'item_id' => $itemId,
                'field' => strtolower($field),
                'value' => $this->arabicOf($english),
                'status' => $draft ? 'draft' : 'published',
                'source' => $draft ? 'machine' : 'manual',
                'source_hash' => sha1($english),
                'reviewed_at' => $draft ? null : now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $written++;
            $draft and $drafts++;

            if (count($rows) >= 500) {
                DB::table('translations')->insertOrIgnore($rows);
                $rows = [];
            }
        };

        foreach (\App\Services\Translation\InterfaceStrings::flat() as $key => $english) {
            $n++;
            $push('ui', 0, (string) $key, (string) $english, $draftEvery > 0 && $n % $draftEvery === 0);
        }

        foreach (\App\Services\Translation\TranslationEstimate::CONTENT as $class => $columns) {
            /** @var \Illuminate\Database\Eloquent\Model $model */
            $model = new $class;
            $table = $model->getTable();

            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                continue;
            }

            $present = array_values(array_filter(
                $columns,
                static fn (string $c): bool => \Illuminate\Support\Facades\Schema::hasColumn($table, $c)
            ));

            if ($present === []) {
                continue;
            }

            DB::table($table)->select(array_merge(['id'], $present))->orderBy('id')
                ->chunk(500, function ($page) use ($present, $table, $push, &$n, $draftEvery): void {
                    foreach ($page as $row) {
                        foreach ($present as $column) {
                            $english = $row->{$column} ?? null;

                            if (! is_string($english) || trim($english) === '') {
                                continue;
                            }

                            $n++;
                            $push($table, (int) $row->id, $column, $english, $draftEvery > 0 && $n % $draftEvery === 0);
                        }
                    }
                });
        }

        DB::table('translations')->insertOrIgnore($rows);

        // The master switch. Without it /ar/shop is a 404 and the measurement
        // would report a very fast page that nobody can reach.
        foreach ([\App\Support\Locale::SETTING_ENABLED, \App\Support\Locale::SETTING_RTL] as $key) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => json_encode(true), 'updated_at' => now(), 'created_at' => now()],
            );
        }

        $bytes = (int) DB::table('translations')->where('locale', $locale)
            ->selectRaw('SUM(LENGTH(value)) as b')->value('b');

        $this->out->line('  translations: '.number_format($written)
            .' rows ('.number_format($drafts).' drafts), '
            .number_format($bytes / 1048576, 2).' MB of value bytes on disk');
    }

    /**
     * Arabic of the same CHARACTER length as the English handed in.
     *
     * Not a translation and not pretending to be one — this is a memory and a
     * byte-count fixture. What has to be right is the length in characters
     * (so the map is the size a translated shop's map is) and the fact that
     * the letters are outside ASCII (so every one of them costs two bytes,
     * which is what makes an Arabic map bigger than an English one).
     *
     * Markup is kept as markup: a translated description is still HTML, and a
     * fixture that turned tags into letters would under-count the bytes.
     */
    private function arabicOf(string $english): string
    {
        // Tags are left exactly as they are and only the words between them are
        // replaced. A real Arabic description is still HTML: the markup stays
        // ASCII at one byte a character while the prose doubles, and a fixture
        // that turned <p> into Arabic letters would over-state the bytes by the
        // markup's share of the copy.
        if (! str_contains($english, '<')) {
            return $this->arabicWords(mb_strlen($english, 'UTF-8'));
        }

        return (string) preg_replace_callback(
            '/>[^<]+</u',
            fn (array $m): string => '>'.$this->arabicWords(mb_strlen(substr($m[0], 1, -1), 'UTF-8')).'<',
            $english,
        ) ?: $english;
    }

    /**
     * $n Arabic characters, spaces included.
     *
     * A class constant rather than a function-local static variable, which is
     * what this was first written as. StaticMemoIsolationTest counts any
     * process-level static under app/ as state that can outlive a test, and it
     * is right to — the rule does not get an exemption for a word list. Its
     * detector reads the file, so the spelling it looks for is avoided in this
     * comment too.
     */
    private function arabicWords(int $n): string
    {
        $words = self::ARABIC_WORDS;

        $out = '';
        $i = 0;

        while (mb_strlen($out, 'UTF-8') < $n) {
            $out .= ($out === '' ? '' : ' ').$words[$i++ % count($words)];
        }

        return mb_substr($out, 0, $n, 'UTF-8');
    }

    private function insert(string $table, array $rows): void
    {
        if ($rows !== []) {
            DB::table($table)->insert($rows);
        }
    }
}
