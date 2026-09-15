<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give every table that receives imported data a key the importer can match on.
 *
 * WHY THIS EXISTS. The WooCommerce migration will not be one run. It will be a
 * full import, then a delta, then a cutover delta on the night itself, and then
 * however many re-runs it takes to get a mapping right. Every one of those
 * passes re-presents rows the previous pass already inserted. A table whose
 * rows cannot be matched back to their WordPress originals has exactly one
 * behaviour on a re-run: it doubles. So the external id is not bookkeeping, it
 * is the only thing that makes the import repeatable, and the only moment it
 * can be added for free is before any data exists.
 *
 * Phase 0 already did most of this — products.wc_id, product_variants.wc_id,
 * customers.wp_user_id, orders.wc_order_id, categories/brands/tags/menus
 * .source_term_id, order_notes.source_comment_id, refunds.wc_refund_id,
 * media.source_attachment_id, coupons.wc_id, shipping_zones.source_zone_id are
 * all present, nullable and unique. Five tables were missed, and they are the
 * five below. Three have no external-id column at all; two have one that is
 * merely indexed, which finds a row quickly and then lets a second copy of it
 * be inserted anyway. An index is not a constraint.
 *
 * WHAT IS ADDED
 *
 *  addresses.source_key      — a string, not an integer, and deliberately so.
 *                              WooCommerce has no addresses table: billing and
 *                              shipping live in wp_usermeta as loose keys, and
 *                              an order's addresses are order meta. There is no
 *                              integer that identifies an address, so an
 *                              unsignedBigInteger here would be a fiction the
 *                              importer had to invent a fake value for. A
 *                              composed key states the origin honestly:
 *                              "user:412:billing", "order:10233:shipping".
 *                              The composition rule is the importer's contract
 *                              and is written down in docs/IMPORT-READINESS.md.
 *
 *  order_items.wc_item_id    — this one IS a real integer. WooCommerce keeps
 *                              line items in woocommerce_order_items with an
 *                              order_item_id primary key. Without it a re-run
 *                              of the order import gives every order a second
 *                              set of lines and doubles its item revenue while
 *                              the order total stays right, which is the worst
 *                              possible failure shape: silently inconsistent
 *                              rather than loudly broken.
 *
 *  users.wp_user_id          — the admin/staff table, matching customers. The
 *                              owner intends to bring WordPress users across;
 *                              users.email is UNIQUE NOT NULL and there was no
 *                              other key, so a re-run would have collided on
 *                              email and aborted rather than updated.
 *
 *  attribute_values          — source_term_id existed but only ->index(). WP
 *                              term ids are globally unique, so the constraint
 *                              is simply correct, and without it a second pass
 *                              creates a duplicate "50ml" that variants then
 *                              split across.
 *
 *  reviews                   — source_id likewise only ->index(). The unique
 *                              key here is (source, source_id), NOT source_id
 *                              alone: this table intentionally holds two
 *                              origins, 'sorina' and 'wp_comment', whose id
 *                              spaces are unrelated and will overlap.
 *
 * MYSQL, WHICH IS WHAT PRODUCTION RUNS. Tests run SQLite and SQLite will accept
 * almost anything, so the MySQL rules are applied here by hand rather than
 * discovered on the server:
 *
 *  - Index names are given explicitly and kept short. MySQL caps an identifier
 *    at 64 characters and Laravel's generated names concatenate table and every
 *    column; "attribute_values_source_term_id_unique" is close enough to that
 *    ceiling to be worth not relying on.
 *  - source_key is varchar(191), the length this repo already uses for
 *    refunds.idempotency_key. Under utf8mb4 every character reserves 4 bytes in
 *    the index, so 191 x 4 = 764 bytes — comfortably inside InnoDB's 3072-byte
 *    key limit, and inside the 767-byte limit of an older row format too.
 *  - The reviews key is (varchar(255), bigint) = 1028 bytes, also inside 3072.
 *
 * NO ->after() ANYWHERE. An ALTER ... AFTER a column that does not exist is an
 * error on MySQL and ignored entirely on SQLite; wrapped in a hasColumn guard,
 * that error reads as a clean no-op and the migration records as run having
 * changed nothing. That is how this project lost checkout for a release, it is
 * why 2026_09_15_020000_repair_order_tables exists, and MigrationConventionTest
 * now fails any new migration that reaches for it. Column order is cosmetic.
 *
 * SAFE TO RUN TWICE, AND SAFE ON A DATABASE THAT ALREADY HAS THE COLUMN. Every
 * add is guarded on hasColumn and every index on its actual presence in the
 * schema, read back from the driver rather than assumed. The two indexes that
 * go onto already-populated columns first check that the data can satisfy them:
 * a unique index refused by duplicate rows would abort the whole migration and
 * take the rest of the package with it. If duplicates are found the index is
 * skipped and the duplicate is printed, because a named problem the owner can
 * fix beats a failed deployment.
 *
 * It echoes what it changed. Silence means the schema already had all of it —
 * which, on a server whose migrations went years without running, is worth
 * seeing stated rather than assumed.
 *
 * NO down(). These columns are the only link between a row here and the row it
 * came from in WordPress; dropping one discards the mapping and makes the next
 * import duplicate everything it already imported.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addColumns();
        $this->addIndexes();
    }

    public function down(): void {}

    /**
     * The three tables with no external-id column at all.
     *
     * @return void
     */
    private function addColumns(): void
    {
        /** @var array<string, array<string, callable(Blueprint): mixed>> $columns */
        $columns = [
            'addresses' => [
                // Composed, not native — see the header. Nullable because rows
                // created by the storefront's own address book have no
                // WordPress origin and never will.
                'source_key' => fn (Blueprint $t) => $t->string('source_key', 191)->nullable(),
            ],
            'order_items' => [
                'wc_item_id' => fn (Blueprint $t) => $t->unsignedBigInteger('wc_item_id')->nullable(),
            ],
            'users' => [
                'wp_user_id' => fn (Blueprint $t) => $t->unsignedBigInteger('wp_user_id')->nullable(),
            ],
        ];

        foreach ($columns as $table => $defs) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $missing = array_filter(
                $defs,
                static fn (string $column): bool => ! Schema::hasColumn($table, $column),
                ARRAY_FILTER_USE_KEY,
            );

            if ($missing === []) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($missing): void {
                foreach ($missing as $define) {
                    $define($t);
                }
            });

            $this->say('Added '.$table.': '.implode(', ', array_keys($missing)));
        }
    }

    /**
     * The unique constraints, including the two retro-fitted onto columns that
     * already carried a plain index.
     */
    private function addIndexes(): void
    {
        /** @var list<array{table: string, columns: list<string>, name: string}> $indexes */
        $indexes = [
            // New columns, added above.
            ['table' => 'addresses', 'columns' => ['source_key'], 'name' => 'addresses_source_key_unique'],
            ['table' => 'order_items', 'columns' => ['wc_item_id'], 'name' => 'order_items_wc_item_unique'],
            ['table' => 'users', 'columns' => ['wp_user_id'], 'name' => 'users_wp_user_unique'],

            // Existing columns whose constraint was only ever an index.
            ['table' => 'attribute_values', 'columns' => ['source_term_id'], 'name' => 'attribute_values_source_term_unique'],
            // Two id spaces share this table; only the pair is unique.
            ['table' => 'reviews', 'columns' => ['source', 'source_id'], 'name' => 'reviews_source_unique'],

            /*
             * And the ones that are unique in the Phase 0 schema but are NOT
             * unique on a server that got them from a repair migration.
             *
             * This is the part worth reading twice. repair_order_tables and
             * repair_customer_tables re-add columns a broken ->after() chain
             * had left missing, and they add them as bare nullable columns:
             *
             *     'wc_order_id' => fn ($t) => $t->unsignedBigInteger('wc_order_id')->nullable(),
             *     'wp_user_id'  => fn ($t) => $t->unsignedBigInteger('wp_user_id')->nullable(),
             *
             * with no ->unique(). That was the right call for those migrations
             * — their job was to stop the 500s, and a unique index on a
             * populated table is exactly the thing that can refuse to be added
             * — but it means the live MySQL can be carrying wc_order_id,
             * wp_user_id and product_variants.wc_id as ordinary nullable
             * columns with no constraint behind them at all. A schema check
             * that only asks "does the column exist?" says yes, the importer
             * matches on it happily, and the second pass inserts a complete
             * second copy of every order in the store.
             *
             * A fresh SQLite migrated from the Phase 0 schema has the unique
             * indexes, which is precisely why this cannot be found from the
             * test suite alone. Each one below is a no-op there and the whole
             * point of this migration on the server.
             */
            ['table' => 'orders', 'columns' => ['wc_order_id'], 'name' => 'orders_wc_order_unique'],
            ['table' => 'orders', 'columns' => ['order_number'], 'name' => 'orders_order_number_unique'],
            ['table' => 'orders', 'columns' => ['invoice_number'], 'name' => 'orders_invoice_number_unique'],
            ['table' => 'customers', 'columns' => ['wp_user_id'], 'name' => 'customers_wp_user_unique'],
            ['table' => 'product_variants', 'columns' => ['wc_id'], 'name' => 'product_variants_wc_unique'],
            ['table' => 'products', 'columns' => ['wc_id'], 'name' => 'products_wc_unique'],
            ['table' => 'categories', 'columns' => ['source_term_id'], 'name' => 'categories_source_term_unique'],
            ['table' => 'brands', 'columns' => ['source_term_id'], 'name' => 'brands_source_term_unique'],
        ];

        foreach ($indexes as $index) {
            $table = $index['table'];
            $columns = $index['columns'];
            $name = $index['name'];

            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $this->say('Skipped '.$name.': '.$table.'.'.$column.' is absent');

                    continue 2;
                }
            }

            if ($this->hasUniqueOn($table, $columns, $name)) {
                continue;
            }

            $duplicate = $this->firstDuplicate($table, $columns);

            if ($duplicate !== null) {
                // Loud, and not fatal. Adding the index would abort the whole
                // migration — and every later one in the same package — over
                // data the owner can clean up in a minute once told which row.
                $this->say(
                    'SKIPPED '.$name.': '.$table.' already holds duplicate '
                    .implode('+', $columns).' = '.$duplicate
                    .'. De-duplicate, then re-run this migration.'
                );

                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($columns, $name): void {
                $t->unique($columns, $name);
            });

            $this->say('Indexed '.$table.' ('.implode(', ', $columns).') as '.$name);
        }
    }

    /**
     * Whether these columns are ALREADY covered by a unique constraint.
     *
     * Matching on the index name alone is not enough, and getting that wrong
     * would have been the quiet bug in this migration. On a database built
     * from the Phase 0 schema, orders.wc_order_id is already unique under
     * Laravel's own generated name, `orders_wc_order_id_unique` — which is not
     * the short explicit name used here. A name-only check would therefore
     * find nothing, add a second unique index over the identical column, and
     * leave every fresh install carrying redundant indexes that slow down
     * exactly the writes an import does most of.
     *
     * So the question asked is the one that actually matters: is there any
     * unique index on precisely this column set? The name is checked too, so a
     * re-run recognises this migration's own work even on a driver whose
     * column introspection is thinner than MySQL's.
     *
     * Read back from the driver rather than inferred. Names come back
     * lower-cased on some drivers and not others, hence the case-insensitive
     * compare. A driver that cannot introspect at all is treated as "absent",
     * which is the safe direction: the duplicate scan still runs, and a truly
     * redundant index is a performance cost, not a correctness one.
     *
     * @param list<string> $columns
     */
    private function hasUniqueOn(string $table, array $columns, string $name): bool
    {
        $wanted = array_map('strtolower', $columns);
        sort($wanted);

        try {
            foreach (Schema::getIndexes($table) as $index) {
                if (strcasecmp((string) ($index['name'] ?? ''), $name) === 0) {
                    return true;
                }

                if (($index['unique'] ?? false) !== true) {
                    continue;
                }

                $have = array_map('strtolower', array_map('strval', (array) ($index['columns'] ?? [])));
                sort($have);

                if ($have === $wanted) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // Never silent. If introspection is unavailable the migration
            // proceeds as though the constraint were missing, which is the
            // right risk to take — a redundant index costs write speed, a
            // missing one costs a duplicated import — but the operator needs
            // to know that is what happened rather than reading the "Indexed"
            // line below as confirmation the index was absent.
            $this->say('WARNING: could not read indexes on '.$table.' ('.$e->getMessage().'); assuming '.$name.' is absent');

            return false;
        }

        return false;
    }

    /**
     * The first value that already appears twice, or null if the column (or
     * column pair) can carry a unique index as it stands.
     *
     * NULLs are excluded deliberately: both MySQL and SQLite allow any number
     * of NULLs in a unique index, and every one of these columns is nullable
     * precisely so that rows with no WordPress origin can exist alongside
     * imported ones.
     */
    private function firstDuplicate(string $table, array $columns): ?string
    {
        $query = DB::table($table)->select($columns)->selectRaw('COUNT(*) as n')->groupBy($columns);

        foreach ($columns as $column) {
            $query->whereNotNull($column);
        }

        $row = $query->havingRaw('COUNT(*) > 1')->first();

        if ($row === null) {
            return null;
        }

        $parts = [];

        foreach ($columns as $column) {
            $parts[] = (string) ($row->{$column} ?? '');
        }

        return implode('+', $parts);
    }

    private function say(string $line): void
    {
        if (app()->runningInConsole()) {
            echo $line."\n";
        }
    }
};
