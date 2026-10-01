<?php

/*
 * Lane KR -- the measuring half of tools/kr-kill-rehearsal.sh.
 *
 * Reads a file-SQLite database with nothing but PDO, so it does not boot the
 * application and cannot be influenced by anything the import left in a cache
 * or a static. Three jobs:
 *
 *   dump   <db> <out>     every row of every table, canonical: columns sorted by
 *                         name, rows ordered by primary key, one JSON line each.
 *                         Two of these are diffed to prove a killed-and-resumed
 *                         database equals an uninterrupted one. Columns whose
 *                         VALUE is the wall clock of the run (created_at and
 *                         friends stamped with now()) are found empirically by
 *                         diffing two clean runs -- see the rehearsal script --
 *                         and are masked with --mask=table.column,...
 *
 *   checks <db>           the measured invariants the brief names: row counts,
 *                         duplicate external ids, money sums, lines per order,
 *                         orphans. Printed as `key=value`, one per line, so two
 *                         runs diff cleanly.
 *
 *   state  <db>           what a kill left behind: is there a hot journal (a
 *                         write transaction was open when the process died), and
 *                         every checkpoint row. Run BEFORE anything else opens
 *                         the file, because the first open rolls the journal back.
 *
 * Nothing here writes to the database it is pointed at, except that SQLite
 * itself rolls back a hot journal on first read, which is the point.
 */

declare(strict_types=1);

[$self, $command, $db] = array_pad($argv, 3, null);

/*
 *   diffcols <dumpA> <dumpB>   which table.column pairs differ between two
 *                              dumps, and in how many rows. Prints `rows.<t>`
 *                              when a table's row count differs (which is
 *                              never noise) and `col.<t>.<c>=<n>` otherwise.
 *                              Empty output means byte-identical.
 */
if ($command === 'diffcols') {
    $read = static function (string $path): array {
        $tables = [];

        foreach (new SplFileObject($path) as $line) {
            if ($line === '' || str_starts_with($line, '#count')) {
                continue;
            }

            [$table, $json] = explode("\t", rtrim($line, "\n"), 2);
            $tables[$table][] = json_decode($json, true);
        }

        return $tables;
    };

    $a = $read($db);
    $b = $read($argv[3]);
    $found = [];

    foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $table) {
        $ra = $a[$table] ?? [];
        $rb = $b[$table] ?? [];

        if (count($ra) !== count($rb)) {
            $found["rows.$table"] = count($ra).'!='.count($rb);

            continue;
        }

        foreach ($ra as $i => $row) {
            foreach ($row as $column => $value) {
                if (($rb[$i][$column] ?? null) !== $value) {
                    $found["col.$table.$column"] = ($found["col.$table.$column"] ?? 0) + 1;
                }
            }
        }
    }

    ksort($found);

    foreach ($found as $key => $n) {
        echo "$key=$n\n";
    }

    exit(0);
}

if ($command === null || $db === null || ! is_file($db)) {
    fwrite(STDERR, "usage: php tools/kr-db-compare.php dump|checks|state <db.sqlite> [out] [--mask=t.c,...]\n");
    exit(2);
}

$mask = [];

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--mask=')) {
        foreach (array_filter(explode(',', substr($arg, 7))) as $tc) {
            $mask[$tc] = true;
        }
    }
}

if ($command === 'state') {
    // BEFORE the PDO open below: opening the file is what rolls a hot journal back.
    echo 'hot_journal='.(is_file($db.'-journal') && filesize($db.'-journal') > 0 ? 'yes' : 'no')."\n";
}

$pdo = new PDO('sqlite:'.$db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA busy_timeout = 60000');

$scalar = static fn (string $sql) => $pdo->query($sql)->fetchColumn();

/** @return list<string> */
$tables = static function () use ($pdo): array {
    return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
        ->fetchAll(PDO::FETCH_COLUMN);
};

switch ($command) {
    case 'state':
        foreach ($pdo->query('SELECT entity, processed, created_rows, updated_rows, unchanged_rows, rejected_rows, '
            .'finished_at IS NOT NULL AS finished FROM import_checkpoints ORDER BY id') as $r) {
            printf("checkpoint.%s=%d%s\n", $r['entity'], $r['processed'], $r['finished'] ? ' finished' : ' OPEN');

            if (in_array('--counters', $argv, true)) {
                printf("  counters.%s created=%d updated=%d unchanged=%d rejected=%d\n", $r['entity'],
                    $r['created_rows'], $r['updated_rows'], $r['unchanged_rows'], $r['rejected_rows']);
            }
        }

        foreach (['products', 'orders', 'order_items', 'customers', 'reviews', 'categories'] as $t) {
            echo "rows.$t=".$scalar("SELECT COUNT(*) FROM $t")."\n";
        }

        break;

    case 'dump':
        $out = $argv[3] ?? null;

        if ($out === null || str_starts_with($out, '--')) {
            fwrite(STDERR, "dump needs an output file\n");
            exit(2);
        }

        $fh = fopen($out, 'wb');

        foreach ($tables() as $table) {
            $columns = array_map(
                static fn (array $c): string => $c['name'],
                $pdo->query("PRAGMA table_info(\"$table\")")->fetchAll(PDO::FETCH_ASSOC),
            );
            sort($columns);

            $order = in_array('id', $columns, true) ? '"id"' : implode(', ', array_map(static fn ($c) => "\"$c\"", $columns));

            $count = 0;

            foreach ($pdo->query("SELECT * FROM \"$table\" ORDER BY $order") as $row) {
                $line = [];

                foreach ($columns as $column) {
                    $line[$column] = isset($mask[$table.'.'.$column]) || isset($mask['*.'.$column])
                        ? '<masked>'
                        : $row[$column];
                }

                fwrite($fh, $table."\t".json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
                $count++;
            }

            fwrite($fh, "#count\t$table\t$count\n");
        }

        fclose($fh);

        break;

    case 'checks':
        $count = static fn (string $t): int => (int) $scalar("SELECT COUNT(*) FROM $t");

        foreach (['categories', 'brands', 'products', 'product_variants', 'tags', 'attributes', 'attribute_values',
            'coupons', 'customers', 'addresses', 'orders', 'order_items', 'refunds', 'order_notes', 'reviews',
            'posts', 'menus', 'menu_items', 'category_product', 'product_tag', 'product_attribute_value',
            'product_variant_attribute_value', 'redirects'] as $t) {
            echo "count.$t=".$count($t)."\n";
        }

        // Duplicates on every external id the importer upserts on. 0 is the only right answer.
        foreach ([
            ['products', 'wc_id'], ['product_variants', 'wc_id'], ['orders', 'wc_order_id'],
            ['order_items', 'wc_item_id'], ['customers', 'wp_user_id'], ['coupons', 'wc_id'],
            ['categories', 'source_term_id'], ['brands', 'source_term_id'], ['tags', 'source_term_id'],
            ['reviews', 'source_id'], ['refunds', 'wc_refund_id'], ['order_notes', 'source_comment_id'],
            ['posts', 'source_post_id'], ['menu_items', 'source_post_id'], ['customers', 'email'],
        ] as [$t, $c]) {
            echo "dupes.$t.$c=".$scalar("SELECT COALESCE(SUM(n - 1), 0) FROM (SELECT COUNT(*) n FROM $t "
                ."WHERE $c IS NOT NULL GROUP BY $c HAVING n > 1)")."\n";
        }

        /*
         * The checkpoints, in the form that must agree. NOT created/updated/
         * unchanged separately: a resumed run re-reads every FINISHED entity from
         * row one (Checkpoint::open, by design -- it is what makes a delta work),
         * so those rows are `unchanged` on that pass where the clean run had them
         * `created`. Their SUM is the rows that landed, and it must match.
         */
        foreach ($pdo->query('SELECT entity, processed, rejected_rows, created_rows + updated_rows + unchanged_rows AS landed, '
            .'finished_at IS NOT NULL AS finished FROM import_checkpoints ORDER BY entity') as $r) {
            printf("checkpoint.%s=processed:%d landed:%d rejected:%d %s\n", $r['entity'], $r['processed'],
                $r['landed'], $r['rejected_rows'], $r['finished'] ? 'finished' : 'OPEN');
        }

        echo 'sum.orders.total='.$scalar('SELECT COALESCE(SUM(total), 0) FROM orders')."\n";
        echo 'sum.order_items.total='.$scalar('SELECT COALESCE(SUM(total), 0) FROM order_items')."\n";
        echo 'sum.order_items.quantity='.$scalar('SELECT COALESCE(SUM(quantity), 0) FROM order_items')."\n";
        echo 'sum.refunds.amount='.$scalar('SELECT COALESCE(SUM(amount), 0) FROM refunds')."\n";
        echo 'sum.products.review_count='.$scalar('SELECT COALESCE(SUM(review_count), 0) FROM products')."\n";
        echo 'sum.products.rating_x100='.$scalar('SELECT COALESCE(SUM(ROUND(rating * 100)), 0) FROM products')."\n";
        echo 'categories.with_parent='.$scalar('SELECT COUNT(*) FROM categories WHERE parent_id IS NOT NULL')."\n";
        echo 'categories.max_depth='.$scalar('SELECT COALESCE(MAX(depth), 0) FROM categories')."\n";

        // Lines per order, keyed by the EXPORT's order id so local ids cannot hide a misfiled line.
        $perOrder = $pdo->query('SELECT o.wc_order_id, COUNT(i.id) n, COALESCE(SUM(i.total), 0) s FROM orders o '
            .'LEFT JOIN order_items i ON i.order_id = o.id GROUP BY o.id ORDER BY o.wc_order_id')->fetchAll(PDO::FETCH_NUM);
        echo 'digest.lines_per_order='.md5(json_encode($perOrder))."\n";
        echo 'orders.without_lines='.count(array_filter($perOrder, static fn ($r) => (int) $r[1] === 0))."\n";

        // Orphans. SQLite enforces these foreign keys only when the pragma is on, so measure rather than trust.
        foreach ([
            'order_items.order' => 'SELECT COUNT(*) FROM order_items i LEFT JOIN orders o ON o.id = i.order_id WHERE o.id IS NULL',
            'order_items.product' => 'SELECT COUNT(*) FROM order_items i LEFT JOIN products p ON p.id = i.product_id WHERE i.product_id IS NOT NULL AND p.id IS NULL',
            'product_variants.product' => 'SELECT COUNT(*) FROM product_variants v LEFT JOIN products p ON p.id = v.product_id WHERE p.id IS NULL',
            'refunds.order' => 'SELECT COUNT(*) FROM refunds r LEFT JOIN orders o ON o.id = r.order_id WHERE o.id IS NULL',
            'order_notes.order' => 'SELECT COUNT(*) FROM order_notes n LEFT JOIN orders o ON o.id = n.order_id WHERE o.id IS NULL',
            'reviews.product' => 'SELECT COUNT(*) FROM reviews r LEFT JOIN products p ON p.id = r.product_id WHERE r.product_id IS NOT NULL AND p.id IS NULL',
            'orders.customer' => 'SELECT COUNT(*) FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.customer_id IS NOT NULL AND c.id IS NULL',
            'addresses.customer' => 'SELECT COUNT(*) FROM addresses a LEFT JOIN customers c ON c.id = a.customer_id WHERE a.customer_id IS NOT NULL AND c.id IS NULL',
            'category_product.product' => 'SELECT COUNT(*) FROM category_product x LEFT JOIN products p ON p.id = x.product_id WHERE p.id IS NULL',
            'categories.parent' => 'SELECT COUNT(*) FROM categories c LEFT JOIN categories p ON p.id = c.parent_id WHERE c.parent_id IS NOT NULL AND p.id IS NULL',
        ] as $name => $sql) {
            echo "orphans.$name=".$scalar($sql)."\n";
        }

        break;

    default:
        fwrite(STDERR, "unknown command $command\n");
        exit(2);
}
