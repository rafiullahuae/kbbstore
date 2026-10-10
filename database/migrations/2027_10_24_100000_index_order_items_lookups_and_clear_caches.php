<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * order_items gets the two lookup indexes a fresh install has always had, on a
 * server that has NEITHER -- and nothing anywhere else.
 *
 * WHY A SERVER CAN BE WITHOUT THEM. The base schema creates order_items with
 * foreignId()->constrained() on order_id and product_id, and MySQL indexes a
 * foreign key's column for it. 2026_09_15_020000_repair_order_tables adds those
 * same columns one at a time, as plain unsignedBigInteger, wherever a table was
 * missing them. A table that came through the repair has the columns and no
 * index on either, and every "this product's orders" read then reads the whole
 * table.
 *
 * WHAT IT COST. Catalog → Reorder counted each product's orders with one such
 * read per product. Measured on MySQL 8.0 at 60,000 orders and 270,000 lines,
 * without these indexes: 11.9 s for a 205-product category, at any page size.
 * The controller no longer issues a read per product (one grouped read per page
 * now, 0.14 s without the index), so this is not the fix for that screen -- it
 * is the floor under it and under every other per-product or per-order lookup
 * (the product editor's sales line, an order's lines).
 *
 * GUARDED ON THE LEADING COLUMN, not on the index name: a server that already
 * has any index starting with order_id (or product_id) gets nothing for that
 * column, because a second index costs every order write and buys nothing. On
 * a fresh install, and on any server built from the base schema, this adds
 * nothing at all.
 *
 * Plain, single-column, named the way Laravel names them. InnoDB builds a
 * secondary index online; on the live shop's order history that is seconds.
 *
 * REVERSIBLE: down() drops only an index this migration's names identify.
 *
 * Also clears the compiled caches, as every package that changes a controller
 * does: this one changes CatalogReorderApiController and
 * CatalogProductsApiController, and a compiled cache is how a shipped change
 * comes to be served from yesterday's copy.
 */
return new class extends Migration
{
    /** @var array<string, string> index name => column */
    private const INDEXES = [
        'order_items_order_id_index' => 'order_id',
        'order_items_product_id_index' => 'product_id',
    ];

    public function up(): void
    {
        if (Schema::hasTable('order_items')) {
            foreach (self::INDEXES as $name => $column) {
                if (! Schema::hasColumn('order_items', $column)) {
                    continue;
                }

                if ($this->leadsAnIndex('order_items', $column)) {
                    $this->say("order_items ({$column}): already indexed, nothing added.");

                    continue;
                }

                Schema::table('order_items', fn ($t) => $t->index($column, $name));
                $this->say("Indexed order_items ({$column}) as {$name}.");
            }
        }

        $this->clearCompiled();
    }

    public function down(): void
    {
        if (! Schema::hasTable('order_items')) {
            return;
        }

        $present = array_column(Schema::getIndexes('order_items'), 'name');

        foreach (array_keys(self::INDEXES) as $name) {
            if (in_array($name, $present, true)) {
                Schema::table('order_items', fn ($t) => $t->dropIndex($name));
            }
        }
    }

    private function leadsAnIndex(string $table, string $column): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $index) {
                if (($index['columns'][0] ?? null) === $column) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            // An unreadable catalogue is not a reason to fail the package, and
            // not a reason to add an index blind either.
            $this->say("Could not read indexes on {$table}: ".$e->getMessage());

            return true;
        }
    }

    private function clearCompiled(): void
    {
        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/events.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        $this->say("Cleared {$cleared} compiled files.");
    }

    private function say(string $message): void
    {
        if (app()->runningInConsole()) {
            echo '  '.$message.PHP_EOL;
        }
    }
};
