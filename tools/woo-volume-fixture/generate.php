<?php

declare(strict_types=1);

/**
 * Generate a WooCommerce export at THIS SHOP'S REAL VOLUME.
 *
 *   php tools/woo-volume-fixture/generate.php <out-dir> [--seed=N]
 *
 * 671 products, 4,159 orders, 3,712 customers — the three numbers Phase 13
 * names — plus the categories, brands, coupons, line items, reviews and Yoast
 * rows that come with them, and four files nothing opens.
 *
 * WHY THIS EXISTS AND WHY IT IS CHECKED IN. tests/Fixtures/woo is nine rows of
 * deliberately nasty data and it proves the mappings. It cannot prove anything
 * about wall clock, peak memory, how many queries an order costs, whether a
 * killed run resumes cleanly, or whether the second pass really reports every
 * row unchanged — all of which are properties of VOLUME and none of which a
 * nine-row fixture can show. This writes the same shapes at the size the owner
 * will actually press the button on.
 *
 * DETERMINISTIC. A fixed seed and no calls to rand(): the same seed produces
 * byte-identical files, which is what makes a resume test meaningful (the
 * checkpoint fingerprint is over file content) and what lets two runs a week
 * apart be compared.
 *
 * The defects are deliberate and counted. `manifest.json` records exactly how
 * many rows of each kind were written, so a test can assert "671 products in,
 * 671 products out" against a number that was not read back out of the report
 * it is checking.
 */
final class VolumeFixture
{
    /**
     * Phase 13's three numbers, and the four that come with them.
     *
     * DEFAULTS ARE THE REAL SHOP. They are overridable only so the suite can
     * run the same generator at a size a test can afford — same columns, same
     * deliberate defects, same proportions, fewer rows. A test written against
     * a DIFFERENT generator would prove something about the test's fixture and
     * nothing about the one the migration is rehearsed with.
     */
    public const PRODUCTS = 671;

    public const ORDERS = 4159;

    public const CUSTOMERS = 3712;

    public const CATEGORIES = 58;

    public const BRANDS = 93;

    public const COUPONS = 40;

    public const REVIEWS = 2514;

    public readonly int $products;

    public readonly int $orders;

    public readonly int $customers;

    public readonly int $categories;

    public readonly int $brands;

    public readonly int $coupons;

    public readonly int $reviews;

    private int $state;

    /** @var array<string, int> */
    private array $counts = [];

    /**
     * @param  array<string, int>  $scale  any of products, orders, customers, categories, brands, coupons, reviews
     */
    private readonly int $seed;

    public function __construct(private readonly string $dir, int $seed = 20260917, array $scale = [])
    {
        $this->state = $seed;
        $this->seed = $seed;
        $this->products = max(1, $scale['products'] ?? self::PRODUCTS);
        $this->orders = max(1, $scale['orders'] ?? self::ORDERS);
        $this->customers = max(1, $scale['customers'] ?? self::CUSTOMERS);
        $this->categories = max(4, $scale['categories'] ?? self::CATEGORIES);
        $this->brands = max(1, $scale['brands'] ?? self::BRANDS);
        $this->coupons = max(1, $scale['coupons'] ?? self::COUPONS);
        $this->reviews = max(1, $scale['reviews'] ?? self::REVIEWS);
    }

    /** xorshift32 — deterministic, and not the platform's RNG. */
    private function next(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->state = $x & 0xFFFFFFFF;

        return $this->state;
    }

    private function pick(int $lo, int $hi): int
    {
        return $lo + ($this->next() % max(1, $hi - $lo + 1));
    }

    /** @param list<string> $of */
    private function one(array $of): string
    {
        return $of[$this->next() % count($of)];
    }

    private function count(string $key, int $by = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $by;
    }

    public function write(): void
    {
        if (! is_dir($this->dir) && ! mkdir($this->dir, 0777, true) && ! is_dir($this->dir)) {
            throw new RuntimeException('cannot create '.$this->dir);
        }

        $this->categories();
        $this->brands();
        $this->products();
        $this->coupons();
        $this->customers();
        $this->orders();
        $this->reviews();
        $this->seo();
        $this->unreadFiles();

        ksort($this->counts);

        $this->manifest();
    }

    /**
     * `manifest.json`, in the shape docs/WP-EXPORT-CONTRACT.md defines — Lane GF.
     *
     * IT USED TO BE THIS GENERATOR'S OWN FLAT MAP OF COUNTS, and that stopped
     * being a private arrangement the moment the contract gave the name
     * `manifest.json` a meaning. The importer reads one now: it takes `rows`
     * as the progress bar's denominator, `sha256` as the key the duplicate
     * guard matches a file on, and `export_id` as what it calls the export in
     * the record — and it REFUSES an import whose manifest carries a `format`
     * it does not speak, which is what a bare counts map was.
     *
     * So the generator writes the real thing. That is not a compatibility
     * patch, it is the fixture doing more: the volume rehearsal now exercises
     * the manifest path end to end, against a file whose counts were computed
     * by the writer rather than read back out of the reader.
     *
     * THE FLAT COUNTS ARE STILL THERE, under `counts`, which is where the
     * contract puts them, and they still carry the `unread.*` keys this
     * generator has always emitted for the files nothing opens.
     *
     * `export_id` is derived from the seed and the scale, so it is
     * DETERMINISTIC like everything else here — the same seed writes the same
     * export id, which is what lets a test import "the same export" twice and
     * a differently seeded one be a different export.
     */
    private function manifest(): void
    {
        $files = [];

        foreach (glob($this->dir.'/*.csv') ?: [] as $path) {
            $handle = fopen($path, 'rb');
            $rows = -1; // the header

            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($cells === [null]) {
                    continue;
                }

                $rows++;
            }

            fclose($handle);

            $files[basename($path)] = [
                'rows' => max(0, $rows),
                'bytes' => (int) filesize($path),
                'sha256' => hash_file('sha256', $path),
            ];
        }

        ksort($files);

        $fingerprint = hash('sha256', implode('|', [
            $this->seed, $this->products, $this->orders, $this->customers,
            $this->categories, $this->brands, $this->coupons, $this->reviews,
        ]));

        file_put_contents(
            $this->dir.'/manifest.json',
            json_encode([
                'format' => 'kbb-export/1',
                // A UUID-shaped id, deterministic in the seed.
                'export_id' => implode('-', [
                    substr($fingerprint, 0, 8), substr($fingerprint, 8, 4),
                    substr($fingerprint, 12, 4), substr($fingerprint, 16, 4),
                    substr($fingerprint, 20, 12),
                ]),
                // Fixed, not now(): a generated export must be byte-identical
                // for the same seed, and a timestamp would make it never be.
                'generated_at' => '2026-09-18T09:30:00+04:00',
                'source' => [
                    'site_url' => 'https://kbeautybliss.com',
                    'wp_version' => '6.5.2',
                    'woo_version' => '8.7.0',
                    'plugin_version' => 'woo-volume-fixture',
                ],
                'files' => $files,
                'counts' => $this->counts,
                'notes' => ['Generated by tools/woo-volume-fixture/generate.php — not a real export.'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    /** @param list<list<string>> $rows */
    private function csv(string $name, array $header, array $rows): void
    {
        $handle = fopen($this->dir.'/'.$name, 'wb');
        fputcsv($handle, $header);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
    }

    /* ------------------------------------------------------------ taxonomy */

    /** @var list<int> */
    private array $categoryIds = [];

    private function categories(): void
    {
        // Four deep, because production nests four deep and the cached path is
        // computed rather than derived.
        $tops = ['Skincare', 'Makeup', 'Haircare', 'Body', 'Sun Care', 'Tools'];
        $mid = ['Treatments', 'Cleansing', 'Moisturising', 'Masks'];
        $leaf = ['Serums', 'Ampoules', 'Essences', 'Toners', 'Cleansers', 'Creams', 'Oils', 'Peels'];

        $rows = [];
        $id = 1500;
        $seen = [];

        foreach ($tops as $i => $top) {
            $topId = $id++;
            $this->categoryIds[] = $topId;
            $rows[] = [$topId, $top, $this->slug($top, $seen), '', 'Everything for '.strtolower($top), $i + 1];

            foreach ($mid as $j => $m) {
                if (count($rows) >= $this->categories) {
                    break 2;
                }

                $midId = $id++;
                $this->categoryIds[] = $midId;
                $rows[] = [$midId, $m, $this->slug($top.' '.$m, $seen), $topId, '', $j + 1];

                foreach ($leaf as $k => $l) {
                    if (count($rows) >= $this->categories) {
                        break 3;
                    }

                    $leafId = $id++;
                    $this->categoryIds[] = $leafId;
                    // The first few leaves deliberately take the demo
                    // catalogue's own slugs — `serums`, `toners`, `cleansers`
                    // — which is the collision --adopt-by-slug exists for.
                    $slug = $i === 0 && $j === 0 ? $this->slug($l, $seen) : $this->slug($top.' '.$m.' '.$l, $seen);
                    $rows[] = [$leafId, $l, $slug, $midId, '', $k + 1];
                }
            }
        }

        // One category whose parent is not in the export: imported at the root
        // and reported, per the runbook.
        $rows[] = [$id++, 'Orphaned Shelf', 'orphaned-shelf', 999999, '', 99];
        $this->categoryIds[] = $id - 1;
        $this->count('categories.orphan_parent');

        $this->count('categories', count($rows));
        $this->csv('categories.csv', ['term_id', 'name', 'slug', 'parent', 'description', 'position'], $rows);
    }

    /** @var list<int> */
    private array $brandIds = [];

    private function brands(): void
    {
        $stems = ['COSRX', 'Beauty of Joseon', 'Some By Mi', 'Innisfree', 'Laneige', 'Etude', 'Missha',
            'Dr Jart', 'Klairs', 'Purito', 'Isntree', 'Round Lab', 'Torriden', 'Anua', 'Mixsoon',
            'Skin1004', 'Medicube', 'Numbuzin', 'Tirtir', 'Rovectin'];

        $rows = [];
        $seen = [];
        $id = 2500;

        for ($i = 0; $i < $this->brands; $i++) {
            $name = $stems[$i % count($stems)].($i < count($stems) ? '' : ' '.chr(65 + intdiv($i, count($stems))));
            $brandId = $id++;
            $this->brandIds[] = $brandId;
            $rows[] = [$brandId, $name, $this->slug($name, $seen), '', $i + 1];
        }

        $this->count('brands', count($rows));
        $this->csv('brands.csv', ['term_id', 'name', 'slug', 'description', 'position'], $rows);
    }

    /* ------------------------------------------------------------ products */

    /** @var list<int> */
    private array $productIds = [];

    /** @var array<int, string> */
    private array $productNames = [];

    private function products(): void
    {
        $kinds = ['Serum', 'Ampoule', 'Essence', 'Toner', 'Cleanser', 'Cream', 'Mask', 'Sunscreen', 'Oil', 'Mist'];
        $notes = ['Ginseng', 'Snail', 'Centella', 'Rice', 'Propolis', 'Mugwort', 'Heartleaf', 'Vitamin C',
            'Peptide', 'Ceramide', 'Hyaluronic', 'Green Tea', 'Birch', 'Tea Tree', 'Azelaic'];

        $rows = [];
        $seen = [];
        $id = 4000;

        for ($i = 0; $i < $this->products; $i++) {
            $wcId = $id++;
            $this->productIds[] = $wcId;
            $name = $this->one($notes).' '.$this->one($kinds).' '.($i + 1);
            $this->productNames[$wcId] = $name;

            $whole = $this->pick(19, 480);
            // Most prices are whole dirhams; a real export carries fils on a
            // minority, which the shop then PRINTS rounded. That is an
            // adjustment, not a rejection.
            $fils = $this->next() % 5 === 0 ? $this->one(['50', '25', '99', '75']) : '00';
            $regular = $whole.'.'.$fils;

            if ($fils !== '00') {
                $this->count('products.price_carries_fils');
            }

            $sale = '';

            if ($this->next() % 4 === 0) {
                $sale = ($whole - $this->pick(5, 15)).'.00';
            }

            $sku = 'KBB-'.$wcId;

            // Two products sharing a SKU — reported once per SKU, adjusted.
            if ($this->everyNth($i, 2, $this->products, 17)) {
                $sku = 'KBB-DUPLICATE';
                $this->count('products.duplicate_sku');
            }

            // A product with no SKU at all: in the shop with no warehouse handle.
            if ($this->everyNth($i, 2, $this->products, 42)) {
                $sku = '';
                $this->count('products.no_sku');
            }

            $status = 'publish';

            if ($this->everyNth($i, 7, $this->products, 11)) {
                $status = 'draft';
            }

            // In the WordPress trash: refused, by design.
            if ($this->everyNth($i, 2, $this->products, 5)) {
                $status = 'trash';
                $this->count('products.trashed_refused');
            }

            $brand = $this->brandIds[$this->next() % count($this->brandIds)];
            $catA = $this->categoryIds[$this->next() % count($this->categoryIds)];
            $catB = $this->categoryIds[$this->next() % count($this->categoryIds)];

            $description = 'A '.strtolower($name).'. '.str_repeat('Made for daily use. ', $this->pick(1, 6));

            // HTML the allowlist removes — a discard the owner has to see.
            if ($this->everyNth($i, 6, $this->products, 3)) {
                $description .= '<script>window.__wc=1;</script>';
                $this->count('products.script_stripped');
            }

            $rows[] = [
                $wcId,
                $name,
                $this->slug($name, $seen),
                $sku,
                $status,
                'simple',
                $regular,
                $sale,
                $this->next() % 9 === 0 ? 'outofstock' : 'instock',
                (string) $this->pick(0, 400),
                $brand,
                $catA.','.$catB,
                $i + 1,
                $this->stamp(2019 + ($i % 6), 1 + ($i % 12), 1 + ($i % 28), 10, 0, 0),
                'https://kbeautybliss.com/wp-content/uploads/'.$wcId.'.jpg',
                'https://kbeautybliss.com/wp-content/uploads/'.$wcId.'-2.jpg,https://kbeautybliss.com/wp-content/uploads/'.$wcId.'-3.jpg',
                $description,
                // A column nothing in this importer reads. Real exports are
                // full of them; this is the channel that names them.
                'Ring the bell twice',
            ];
        }

        $this->count('products', count($rows));
        $this->csv('products.csv', [
            'id', 'name', 'slug', 'sku', 'status', 'type', 'regular_price', 'sale_price', 'stock_status',
            'stock', 'brand_term_id', 'category_term_ids', 'position', 'date_created', 'image', 'images',
            'description', 'meta:_delivery_instructions',
        ], $rows);
    }

    /* ------------------------------------------------------------- coupons */

    /** @var list<string> */
    private array $couponCodes = [];

    private function coupons(): void
    {
        $rows = [];
        $id = 9000;

        for ($i = 0; $i < $this->coupons; $i++) {
            $code = 'KBB'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
            $this->couponCodes[] = $code;

            $type = $this->one(['percent', 'fixed_cart', 'fixed_cart', 'fixed_product']);

            if ($type === 'percent') {
                // Whole per cent mostly; a couple of halves, which is the
                // hundredths-of-a-per-cent case.
                $fractional = $this->everyNth($i, 4, $this->coupons, 4);
                $amount = $fractional ? '12.5' : (string) $this->pick(5, 40);

                if ($fractional) {
                    $this->count('coupons.fractional_percent');
                }
            } else {
                $whole = $this->pick(10, 200);
                // NOTHING in WooCommerce enforced whole dirhams. A fixed coupon
                // carrying fils is the one CouponService rounds UP at the till.
                $fils = $this->everyNth($i, 6, $this->coupons, 2);
                $amount = $fils ? $whole.'.50' : $whole.'.00';

                if ($fils) {
                    $this->count('coupons.fils_fixed_amount');
                }
            }

            $perUser = $this->everyNth($i, 8, $this->coupons, 1) ? (string) $this->pick(1, 3) : '';

            if ($perUser !== '') {
                $this->count('coupons.usage_limit_per_user_discarded');
            }

            $usedBy = $this->everyNth($i, 7, $this->coupons, 3) ? 'buyer'.$i.'@example.test,other'.$i.'@example.test' : '';

            if ($usedBy !== '') {
                $this->count('coupons.used_by_discarded');
            }

            // A bare date with no time: WooCommerce reads it end-of-day
            // inclusive, this schema compares against now().
            $expires = $this->everyNth($i, 14, $this->coupons, 0) ? sprintf('2027-%02d-%02d', 1 + ($i % 12), 1 + ($i % 27)) : '';

            if ($expires !== '') {
                $this->count('coupons.bare_expiry_date');
            }

            $restrict = '';

            if ($i % 8 === 5) {
                $restrict = implode(',', array_slice($this->productIds, $i, 3));
            }

            $rows[] = [
                $id++, $code, 'publish', 'Coupon '.$code, $type, $amount, $expires,
                (string) $this->pick(0, 900), (string) $this->pick(50, 2000), $perUser, '',
                $i % 9 === 0 ? 'yes' : 'no', $i % 4 === 0 ? 'yes' : 'no', 'no',
                $i % 5 === 0 ? (string) $this->pick(50, 200).'.00' : '', '',
                $restrict, '', '', '', $usedBy,
            ];
        }

        $this->count('coupons', count($rows));
        $this->csv('coupons.csv', [
            'id', 'code', 'post_status', 'description', 'discount_type', 'coupon_amount', 'date_expires',
            'usage_count', 'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items', 'free_shipping',
            'individual_use', 'exclude_sale_items', 'minimum_amount', 'maximum_amount', 'product_ids',
            'exclude_product_ids', 'product_categories', 'customer_email', 'used_by',
        ], $rows);
    }

    /* ----------------------------------------------------------- customers */

    /** @var list<array{id: int, email: string, name: string}> */
    private array $people = [];

    private function customers(): void
    {
        $first = ['Layla', 'Omar', 'Fatima', 'Yusuf', 'Aisha', 'Khalid', 'Noor', 'Zaid', 'Mariam', 'Hassan',
            'Sara', 'Ali', 'Huda', 'Tariq', 'Rania', 'Samir', 'Dina', 'Faisal', 'Lina', 'Nabil'];
        $last = ['Hassan', 'Rahman', 'Aziz', 'Farouk', 'Saleh', 'Nasser', 'Mansour', 'Haddad', 'Khoury', 'Ibrahim'];

        $rows = [];
        $id = 400;

        for ($i = 0; $i < $this->customers; $i++) {
            $userId = $id++;
            $name = $this->one($first).' '.$this->one($last);
            $email = 'shopper'.$userId.'@example.test';

            $this->people[] = ['id' => $userId, 'email' => $email, 'name' => $name];

            [$f, $l] = explode(' ', $name, 2);

            // A three-letter country code: the address is refused, the customer
            // still imports. addresses.country is varchar(2).
            $country = $this->everyNth($i, 2, $this->customers, 30) ? 'ARE' : 'AE';

            if ($country === 'ARE') {
                $this->count('customers.alpha3_country_address_refused');
            }

            $rows[] = [
                $userId, $email, $f, $l, '+9715'.str_pad((string) ($i % 100000000), 8, '0', STR_PAD_LEFT),
                $this->stamp(2018 + ($i % 7), 1 + ($i % 12), 1 + ($i % 28), 8, 0, 0),
                '$P$B'.str_repeat('x', 27).'0',
                $f, $l, $this->pick(1, 200).' Marina Walk', 'Dubai', 'Dubai', '00000', $country,
                '+9715'.str_pad((string) ($i % 100000000), 8, '0', STR_PAD_LEFT),
                $f, $l, $this->pick(1, 200).' Marina Walk', 'Dubai', 'Dubai', $country,
            ];
        }

        // Two WordPress users sharing an email — decision D2. The second is
        // refused by name, with both ids.
        $dup = $this->people[min(10, count($this->people) - 1)];
        $rows[] = [
            $id++, strtoupper($dup['email']), 'Duplicate', 'Account', '+971500000000',
            $this->stamp(2021, 6, 6, 8, 0, 0), '', 'Duplicate', 'Account', '1 Nowhere', 'Dubai', 'Dubai', '00000', 'AE',
            '', 'Duplicate', 'Account', '1 Nowhere', 'Dubai', 'Dubai', 'AE',
        ];
        $this->count('customers.email_collision_refused');

        $this->count('customers', count($rows));
        $this->csv('customers.csv', [
            'user_id', 'email', 'first_name', 'last_name', 'phone', 'registered', 'password_hash',
            'billing_first_name', 'billing_last_name', 'billing_address_1', 'billing_city', 'billing_state',
            'billing_postcode', 'billing_country', 'billing_phone', 'shipping_first_name', 'shipping_last_name',
            'shipping_address_1', 'shipping_city', 'shipping_state', 'shipping_country',
        ], $rows);
    }

    /* -------------------------------------------------------------- orders */

    private function orders(): void
    {
        $statuses = ['wc-completed', 'wc-completed', 'wc-completed', 'wc-processing', 'wc-cancelled',
            'wc-refunded', 'wc-on-hold', 'shipped', 'tamara-p-failed'];

        $orderRows = [];
        $itemRows = [];
        $orderId = 10000;
        $itemId = 50000;
        $items = 0;

        for ($i = 0; $i < $this->orders; $i++) {
            $wcOrder = $orderId++;
            $status = $this->one($statuses);

            // A guest order: no WordPress user, linked by billing email.
            $guest = $this->next() % 6 === 0;
            $customer = $this->people[$this->next() % count($this->people)];
            $userId = $guest ? '' : (string) $customer['id'];
            $email = $guest ? 'guest'.$wcOrder.'@example.test' : $customer['email'];

            if ($guest) {
                $this->count('orders.guest');
            }

            // An order with no email at all — D1, synthesised .invalid address.
            if ($this->everyNth($i, 11, $this->orders, 7)) {
                $email = '';
                $this->count('orders.no_email');
            }

            // An order naming a WordPress user who is not in the export.
            if ($this->everyNth($i, 9, $this->orders, 11)) {
                $userId = '99000'.$i;
                $this->count('orders.unknown_wp_user');
            }

            $lines = $this->pick(1, 4);
            $subtotalFils = 0;
            $pending = [];

            for ($l = 0; $l < $lines; $l++) {
                $product = $this->productIds[$this->next() % count($this->productIds)];
                $qty = $this->pick(1, 3);
                $unitWhole = $this->pick(19, 320);
                $lineFils = $unitWhole * 100 * $qty;

                // A unit price that does not divide evenly: three for AED 100.
                if ($this->next() % 23 === 0) {
                    $lineFils = 10000;
                    $qty = 3;
                    $this->count('order_items.uneven_unit_price');
                }

                $subtotalFils += $lineFils;
                $pending[] = [$product, $qty, $lineFils];
            }

            // A refund line with a negative quantity: order_items.quantity is
            // unsigned, so -1 clamps to 0 while the money stays negative.
            if ($status === 'wc-refunded' && $this->next() % 3 === 0) {
                $pending[] = [$this->productIds[$this->next() % count($this->productIds)], -1, -5000];
                $this->count('order_items.negative_quantity');
            }

            $shipping = $this->next() % 3 === 0 ? 0 : 1500;
            $discountFils = 0;
            $coupon = '';

            if ($this->next() % 7 === 0) {
                $coupon = $this->couponCodes[$this->next() % count($this->couponCodes)];
                $discountFils = intdiv($subtotalFils, 10);
            }

            $totalFils = $subtotalFils - $discountFils + $shipping;

            $currency = 'AED';

            // An order in another currency: every revenue SUM adds it at face
            // value, so it is an adjustment the owner has to see.
            if ($this->everyNth($i, 14, $this->orders, 13)) {
                $currency = 'USD';
                $this->count('orders.foreign_currency');
            }

            $y = 2019 + ($i % 7);
            $created = $this->stamp($y, 1 + ($i % 12), 1 + ($i % 28), $this->pick(0, 23), $this->pick(0, 59), 0);

            $orderRows[] = [
                $wcOrder,
                'KBB-'.(1000 + $i),
                $status,
                $currency,
                $userId,
                $email,
                $created,
                $created,
                $status === 'wc-completed' || $status === 'wc-processing' ? $created : '',
                $status === 'wc-completed' ? $created : '',
                $this->money($subtotalFils),
                $this->money($discountFils),
                $this->money($shipping),
                '0.00',
                '0.00',
                $this->money($totalFils),
                $this->one(['cod', 'stripe', 'tabby', 'tamara']),
                'Payment',
                'Standard',
                $coupon,
                $i % 50 === 0 ? 'Leave at reception' : '',
                'Buyer', 'Number'.$i, $this->pick(1, 200).' Marina Walk', 'Dubai', 'Dubai', '00000', 'AE',
                '+971500000000',
                'Buyer', 'Number'.$i, $this->pick(1, 200).' Marina Walk', 'Dubai', 'AE',
                // Two more columns nothing reads. Real money and real words.
                $status === 'wc-refunded' ? $this->money(intdiv($totalFils, 2)) : '',
                $i % 40 === 0 ? 'Customer called about delivery' : '',
            ];

            foreach ($pending as [$product, $qty, $lineFils]) {
                $itemRows[] = [
                    $itemId++,
                    $wcOrder,
                    $product,
                    $this->productNames[$product] ?? 'Item',
                    'KBB-'.$product,
                    '',
                    $qty,
                    $this->money($lineFils),
                    $this->money($lineFils),
                    '0.00',
                ];
                $items++;
            }
        }

        $this->count('orders', count($orderRows));
        $this->count('order_items', $items);

        $this->csv('orders.csv', [
            'order_id', 'order_number', 'status', 'currency', 'customer_id', 'billing_email', 'date_created',
            'date_modified', 'date_paid', 'date_completed', 'subtotal', 'discount_total', 'shipping_total',
            'fee_total', 'tax_total', 'total', 'payment_method', 'payment_method_title', 'shipping_method',
            'coupon_code', 'customer_note', 'billing_first_name', 'billing_last_name', 'billing_address_1',
            'billing_city', 'billing_state', 'billing_postcode', 'billing_country', 'billing_phone',
            'shipping_first_name', 'shipping_last_name', 'shipping_address_1', 'shipping_city',
            'shipping_country', 'refund_amount', 'order_notes',
        ], $orderRows);

        $this->csv('order_items.csv', [
            'item_id', 'order_id', 'product_id', 'name', 'sku', 'brand', 'quantity', 'subtotal', 'total', 'tax_total',
        ], $itemRows);
    }

    /* ------------------------------------------------------------- reviews */

    private function reviews(): void
    {
        $titles = ['Wonderful', 'Does what it says', 'Will buy again', 'Not for me', 'Gentle and light'];
        $rows = [];
        $id = 8000;

        for ($i = 0; $i < $this->reviews; $i++) {
            $product = $this->productIds[$this->next() % count($this->productIds)];
            $customer = $this->people[$this->next() % count($this->people)];

            $approved = $this->one(['1', '1', '1', '1', '0', 'spam', 'trash']);

            if ($approved === 'trash') {
                $this->count('reviews.trash_folded_to_spam');
            }

            $author = $this->everyNth($i, 15, $this->reviews, 9) ? '' : explode(' ', $customer['name'])[0];

            if ($author === '') {
                $this->count('reviews.anonymous_author');
            }

            // A review whose product is not in the export: refused rather than
            // published as a review of the shop.
            $post = $this->everyNth($i, 5, $this->reviews, 3) ? 777777 : $product;

            if ($post === 777777) {
                $this->count('reviews.orphan_product_refused');
            }

            $rows[] = [
                $id++, $post, 'review', $author, $customer['email'], $this->pick(1, 5),
                $this->one($titles), 'Used it for a month and the difference is real.', $approved,
                $this->stamp(2021 + ($i % 5), 1 + ($i % 12), 1 + ($i % 28), 10, 0, 0),
                $this->next() % 3 === 0 ? 'yes' : 'no', $customer['id'], '203.0.113.'.($i % 254),
            ];
        }

        $this->count('reviews', count($rows));
        $this->csv('reviews.csv', [
            'comment_id', 'comment_post_id', 'comment_type', 'author', 'email', 'rating', 'title', 'content',
            'comment_approved', 'comment_date', 'verified', 'user_id', 'ip',
        ], $rows);
    }

    private function seo(): void
    {
        $rows = [];

        foreach ($this->productIds as $wcId) {
            $rows[] = [
                $wcId,
                $this->productNames[$wcId].' %%sep%% %%sitename%%',
                'Buy '.$this->productNames[$wcId].' in the UAE.',
                '',
                'https://kbeautybliss.com/wp-content/uploads/'.$wcId.'-og.jpg',
                '',
                strtolower(explode(' ', $this->productNames[$wcId])[0]),
                (string) $this->pick(40, 90),
            ];
        }

        $this->count('seo', count($rows));
        $this->csv('seo.csv', [
            'id', '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_canonical',
            '_yoast_wpseo_opengraph-image', '_yoast_wpseo_meta-robots-noindex', '_yoast_wpseo_focuskw',
            '_yoast_wpseo_linkdex',
        ], $rows);
    }

    /**
     * Four files a real WooCommerce export carries that no entity here opens.
     *
     * They are the point of the unread-file channel: coupons.csv and reviews.csv
     * were exactly this, and were found only because something finally listed
     * the files nothing had opened.
     */
    private function unreadFiles(): void
    {
        $this->csv('refunds.csv', ['refund_id', 'order_id', 'amount', 'reason', 'date'], array_map(
            fn (int $n): array => [90000 + $n, 10000 + $n, '50.00', 'Returned', '2024-01-01 00:00:00'],
            range(1, max(4, intdiv($this->orders, 20))),
        ));
        $this->count('unread.refunds.csv', max(4, intdiv($this->orders, 20)));

        $this->csv('order_notes.csv', ['note_id', 'order_id', 'author', 'note', 'date'], array_map(
            fn (int $n): array => [70000 + $n, 10000 + $n, 'admin', 'Called the customer', '2024-01-01 00:00:00'],
            range(1, max(4, intdiv($this->orders, 3))),
        ));
        $this->count('unread.order_notes.csv', max(4, intdiv($this->orders, 3)));

        $this->csv('variations.csv', ['variation_id', 'parent_id', 'sku', 'price', 'attributes'], array_map(
            fn (int $n): array => [60000 + $n, 4000 + ($n % $this->products), 'VAR-'.$n, '99.00', 'size:50ml'],
            range(1, max(4, intdiv($this->products, 2))),
        ));
        $this->count('unread.variations.csv', max(4, intdiv($this->products, 2)));

        $this->csv('tags.csv', ['term_id', 'name', 'slug', 'count'], array_map(
            fn (int $n): array => [30000 + $n, 'Tag '.$n, 'tag-'.$n, (string) $n],
            range(1, max(4, intdiv($this->products, 9))),
        ));
        $this->count('unread.tags.csv', max(4, intdiv($this->products, 9)));
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * Is row $i one of the $wanted rows in $total that carry this defect?
     *
     * DEFECTS ARE A DENSITY, NOT A LIST OF ROW NUMBERS, so that the fixture
     * keeps the same SHAPE when it is generated smaller for the suite. Pinning
     * the trashed product at row 200 means a 40-row fixture has no trashed
     * product at all, and a test asserting that trashed products are refused
     * silently stops asserting anything — which is the exact class of defect
     * this whole file exists to rehearse.
     */
    private function everyNth(int $i, int $wanted, int $total, int $offset = 0): bool
    {
        $stride = max(1, intdiv($total, max(1, $wanted)));

        return $i % $stride === $offset % $stride;
    }

    /** @param array<string, true> $seen */
    private function slug(string $name, array &$seen): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? $name, '-'));
        $base = $slug;
        $n = 2;

        while (isset($seen[$slug])) {
            $slug = $base.'-'.$n++;
        }

        $seen[$slug] = true;

        return $slug;
    }

    private function stamp(int $y, int $m, int $d, int $h, int $i, int $s): string
    {
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $m, min($d, 28), $h, $i, $s);
    }

    /** Fils back out as the decimal string an export would carry. */
    private function money(int $fils): string
    {
        $sign = $fils < 0 ? '-' : '';
        $fils = abs($fils);

        return $sign.intdiv($fils, 100).'.'.str_pad((string) ($fils % 100), 2, '0', STR_PAD_LEFT);
    }
}

/*
 * The class above is the deliverable; the lines below are the command line for
 * it. Guarded because the suite `require`s this file to build the same export
 * at a size a test can afford — without the guard, requiring it would print a
 * usage message and exit(1) in the middle of a test run.
 */
if (! isset($argv) || realpath((string) ($argv[0] ?? '')) !== realpath(__FILE__)) {
    return;
}

$dir = $argv[1] ?? null;

if ($dir === null) {
    fwrite(STDERR, "usage: php generate.php <out-dir> [--seed=N] [--products=N] [--orders=N] [--customers=N]\n");
    exit(1);
}

$seed = 20260917;
$scale = [];

foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--seed=')) {
        $seed = (int) substr($arg, 7);

        continue;
    }

    foreach (['products', 'orders', 'customers', 'categories', 'brands', 'coupons', 'reviews'] as $key) {
        if (str_starts_with($arg, '--'.$key.'=')) {
            $scale[$key] = (int) substr($arg, strlen($key) + 3);
        }
    }
}

(new VolumeFixture($dir, $seed, $scale))->write();

echo 'wrote '.$dir."\n";
echo file_get_contents($dir.'/manifest.json');
