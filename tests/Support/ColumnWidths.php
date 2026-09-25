<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Width guard: refuse a string the column is not wide enough to hold.
 *
 * ── WHY THIS EXISTS ───────────────────────────────────────────────────────
 *
 * SQLite does not enforce VARCHAR or CHAR length AT ALL. It is not a warning
 * and it is not a truncation — the declared width is discarded by the grammar
 * before the table is ever created: Illuminate's SQLiteGrammar compiles
 * string(), char() AND uuid() to the bare word `varchar`, with no length on
 * it. MySQL compiles the same three to varchar(n)/char(n) and, under
 * STRICT_TRANS_TABLES, refuses an over-long value outright:
 *
 *     SQLSTATE[22001]: String data, right truncated: 1406
 *     Data too long for column 'token' at row 1
 *
 * So an over-wide write is invisible to the whole SQLite suite and fatal on
 * the live shop. That is not a hypothetical: five CartFooterTest cases wrote
 * Str::random(40) into `carts.token`, which is uuid() and therefore char(36),
 * and they were green here and red on phpunit-mysql.xml. They were fixture
 * values, so no shopper lost a basket — but nothing in the suite could tell
 * the difference between that and an over-wide write from the shop itself,
 * which is a 500 at checkout.
 *
 * ── HOW IT KNOWS THE WIDTHS ───────────────────────────────────────────────
 *
 * It cannot ask SQLite, because SQLite was never told. So WIDTHS below is a
 * checked-in fingerprint of the MySQL schema — every char/varchar column of a
 * freshly migrated database, as information_schema reports it.
 *
 * A checked-in fingerprint rots. This one cannot, because
 * tests/Feature/ColumnWidthGuardTest.php compares it against the live
 * information_schema whenever the suite runs on MySQL, in both directions: a
 * migration that widens a column, narrows one, adds one or drops one fails
 * that case on the MySQL config, which docs/MYSQL-PARITY.md records as a
 * required CI job. The engine that knows the widths checks the map; the engine
 * that does not know them uses it.
 *
 * ── WHERE IT RUNS ─────────────────────────────────────────────────────────
 *
 * tests/Pest.php installs watch() in the beforeEach every Feature test already
 * shares, and asserts in afterEach. So it covers the whole suite — fixtures,
 * factories, services and controllers alike — rather than only the endpoints
 * somebody remembered to point a guard at. That breadth is the point: the
 * defect it was written for was in a fixture.
 *
 * The listener costs one str_starts_with() on a statement that is not an
 * INSERT or an UPDATE, which is nearly all of them.
 *
 * ONE KNOWN BLIND SPOT, recorded rather than papered over: three files
 * (RoutineTaggingJobTest, RoutineTaggingBulkTest, RoutineStepTabsTest) count
 * queries by attaching their own DB::listen and then calling
 * getEventDispatcher()->forget(QueryExecuted::class), which drops EVERY
 * listener including this one. From that point to the end of that test the
 * guard is not watching. Left alone deliberately: making those three forget
 * only their own closure is a change to three other lanes' files for a
 * coverage gap of a few statements, and the afterEach still reports whatever
 * was seen before the forget.
 *
 * ── WHAT IT DELIBERATELY DOES NOT DO ──────────────────────────────────────
 *
 * It bails rather than guesses. An INSERT whose placeholder count is not a
 * whole multiple of its column count, an upsert, an UPDATE whose SET segment
 * holds a placeholder outside a plain `col = ?` assignment — all are skipped,
 * because a wrong binding-to-column mapping would fail somebody else's test
 * for a reason that is not true. A skipped statement is a missed check; a
 * mis-parsed one is a lie.
 *
 * Width is counted in CHARACTERS (mb_strlen), because MySQL counts varchar(n)
 * in characters and the connection is utf8mb4.
 */
final class ColumnWidths
{
    /**
     * Every char/varchar column of the migrated schema, and how wide it is.
     *
     * Generated from `information_schema.columns` on MySQL 8.0 and pinned by
     * ColumnWidthGuardTest. Do not hand-edit to make a test pass: run the
     * migration, run the MySQL config, and take what it tells you.
     *
     * @var array<string, array<string, int>>
     */
    public const WIDTHS = [
        'addresses' => [
            'city' => 255,
            'company' => 255,
            'country' => 2,
            'first_name' => 255,
            'label' => 16,
            'last_name' => 255,
            'line1' => 255,
            'line2' => 255,
            'phone' => 255,
            'postcode' => 255,
            'source_key' => 191,
            'state' => 255,
            'type' => 255,
        ],
        'admin_screen_layouts' => [
            'screen' => 64,
        ],
        'admin_users' => [
            'email' => 255,
            'name' => 255,
            'password' => 255,
            'remember_token' => 100,
            'role' => 255,
        ],
        'attribute_values' => [
            'name' => 255,
            'slug' => 255,
            'swatch_color' => 255,
            'swatch_image' => 255,
        ],
        'attributes' => [
            'name' => 255,
            'query_var' => 255,
            'slug' => 255,
        ],
        'audit_events' => [
            'actor_label' => 191,
            'actor_role' => 32,
            'event' => 64,
            'ip' => 45,
            'method' => 10,
            'path' => 191,
            'severity' => 16,
            'subject' => 191,
            'summary' => 255,
        ],
        'blocks' => [
            'name' => 255,
            'slug' => 255,
            'status' => 255,
        ],
        'brands' => [
            'logo' => 255,
            'name' => 255,
            'slug' => 255,
        ],
        'cache' => [
            'key' => 255,
        ],
        'cache_locks' => [
            'key' => 255,
            'owner' => 255,
        ],
        'cart_recoveries' => [
            'cancel_reason' => 20,
            'email' => 191,
            'source' => 20,
        ],
        'carts' => [
            'currency' => 3,
            'shipping_country' => 2,
            'shipping_state' => 255,
            'status' => 255,
            'token' => 36,
        ],
        'categories' => [
            'image' => 255,
            'name' => 255,
            'path' => 255,
            'slug' => 255,
        ],
        'category_redirects' => [
            'from_path' => 255,
            'reason' => 32,
        ],
        'coupon_redemptions' => [
            'email' => 255,
        ],
        'coupons' => [
            'code' => 255,
            'type' => 255,
        ],
        'customer_password_reset_tokens' => [
            'email' => 255,
            'token' => 255,
        ],
        'customers' => [
            'email' => 255,
            'first_name' => 255,
            'last_name' => 255,
            'legacy_password' => 255,
            'name' => 255,
            'password' => 255,
            'phone' => 255,
            'remember_token' => 100,
        ],
        'delivery_countries' => [
            'code' => 2,
            'eta' => 255,
        ],
        'demo_seed_log' => [
            'model' => 255,
            'type' => 255,
        ],
        'failed_jobs' => [
            'uuid' => 255,
        ],
        'import_checkpoints' => [
            'entity' => 64,
            'run_key' => 64,
            'source_fingerprint' => 64,
            'source_label' => 255,
        ],
        'import_history' => [
            'entity' => 32,
            'export_generated_at' => 40,
            'export_id' => 64,
            'file_name' => 64,
            'file_sha256' => 64,
            'manifest_sha256' => 64,
            'mode' => 16,
            'run_key' => 64,
            'run_uid' => 32,
            'source_site' => 255,
        ],
        'import_runs' => [
            'chain_token' => 64,
            'lock_token' => 32,
            'mode' => 16,
            'run_key' => 64,
            'run_uid' => 32,
            'status' => 16,
        ],
        'job_batches' => [
            'id' => 255,
            'name' => 255,
        ],
        'jobs' => [
            'queue' => 255,
        ],
        'mail_credentials' => [
            'id' => 255,
        ],
        'mail_deliveries' => [
            'kind' => 40,
            'message_id' => 191,
            'recipient' => 191,
            'status' => 10,
            'subject' => 255,
            'transport' => 20,
        ],
        'media' => [
            'alt' => 255,
            'filename' => 255,
            'mime' => 255,
            'original_name' => 255,
            'path' => 255,
        ],
        'media_sideload_items' => [
            'content_type' => 191,
            'host' => 253,
            'state' => 16,
            'target_path' => 500,
            'url_hash' => 64,
        ],
        'media_sideload_runs' => [
            'stopped_reason' => 500,
        ],
        'media_usages' => [
            'field' => 32,
            'owner_type' => 16,
        ],
        'menu_items' => [
            'badge' => 255,
            'highlight_color' => 9,
            'icon' => 255,
            'label' => 255,
            'target_type' => 255,
            'url' => 255,
            'visibility' => 10,
        ],
        'menus' => [
            'location' => 255,
            'name' => 255,
            'slug' => 255,
        ],
        'migrations' => [
            'migration' => 255,
        ],
        'module_settings' => [
            'key' => 255,
            'module' => 255,
        ],
        'module_toggles' => [
            'module' => 255,
        ],
        'not_found_log' => [
            'path' => 255,
            'referer' => 255,
        ],
        'order_items' => [
            'brand' => 255,
            'name' => 255,
            'name_localised' => 255,
            'sku' => 255,
        ],
        'order_notes' => [
            'author' => 255,
        ],
        'order_stock_claims' => [
            'released_reason' => 60,
            'shelf_table' => 32,
        ],
        'orders' => [
            'capture_ref' => 255,
            'coupon_code' => 255,
            'currency' => 3,
            'email' => 255,
            'ip_address' => 255,
            'locale' => 5,
            'order_number' => 255,
            'origin' => 255,
            'payment_method' => 255,
            'payment_method_title' => 255,
            'phone' => 255,
            'shipping_method' => 255,
            'status' => 255,
            'tax_basis' => 16,
            'transaction_id' => 255,
        ],
        'outbound_optouts' => [
            'email' => 191,
        ],
        'pages' => [
            'slug' => 255,
            'status' => 255,
            'template' => 255,
            'title' => 255,
        ],
        'password_reset_tokens' => [
            'email' => 255,
            'token' => 255,
        ],
        'payment_events' => [
            'external_id' => 255,
            'id' => 36,
            'payment_id' => 36,
            'provider' => 255,
            'type' => 255,
        ],
        'payment_providers' => [
            'id' => 255,
            'mode' => 255,
            'title' => 255,
        ],
        'payments' => [
            'currency' => 3,
            'failure_code' => 255,
            'id' => 36,
            'provider' => 255,
            'provider_ref' => 255,
            'status' => 255,
        ],
        'posts' => [
            'author' => 255,
            'cover' => 255,
            'slug' => 255,
            'status' => 255,
            'tag' => 255,
            'title' => 255,
        ],
        'product_variants' => [
            'image' => 255,
            'sku' => 255,
            'stock_status' => 255,
            'tag' => 40,
        ],
        'products' => [
            'gtin' => 14,
            'image' => 255,
            'name' => 255,
            'routine_role' => 24,
            'sku' => 255,
            'slug' => 255,
            'status' => 255,
            'stock_status' => 255,
            'type' => 255,
        ],
        'quiz_submissions' => [
            'age' => 255,
            'assigned_to' => 255,
            'budget' => 255,
            'email' => 255,
            'name' => 255,
            'phone' => 255,
            'routine_depth' => 255,
            'skin_type' => 255,
            'status' => 255,
        ],
        'reconciliation_checkpoints' => [
            'phase' => 40,
            'provider' => 40,
        ],
        'reconciliation_findings' => [
            'acknowledged_by' => 255,
            'currency' => 8,
            'fingerprint' => 100,
            'kind' => 48,
            'local_ref' => 120,
            'order_number' => 255,
            'provider' => 32,
            'remote_ref' => 120,
            'severity' => 16,
        ],
        'reconciliation_runs' => [
            'providers' => 255,
            'run_key' => 191,
            'started_by' => 255,
            'status' => 255,
        ],
        'reconciliation_sightings' => [
            'currency' => 3,
            'kind' => 12,
            'provider' => 32,
            'remote_key' => 120,
            'state' => 24,
        ],
        'redirect_decisions' => [
            'decided_by' => 191,
            'decision' => 16,
            'question' => 32,
            'rule' => 32,
            'source' => 255,
            'subject' => 255,
            'target' => 255,
        ],
        'redirects' => [
            'source' => 255,
            'target' => 255,
        ],
        'refunds' => [
            'failure_code' => 255,
            'idempotency_key' => 191,
            'provider' => 255,
            'provider_ref' => 255,
            'refunded_by' => 255,
            'status' => 255,
        ],
        'review_status_backfill' => [
            'from_status' => 255,
            'to_status' => 255,
        ],
        'reviews' => [
            'author_email' => 255,
            'author_name' => 255,
            'ip' => 60,
            'source' => 255,
            'status' => 255,
            'title' => 255,
        ],
        'routines' => [
            'concern' => 40,
            'coupon_code' => 255,
            'title' => 255,
        ],
        'search_terms' => [
            'term' => 60,
        ],
        'sessions' => [
            'id' => 255,
            'ip_address' => 45,
        ],
        'settings' => [
            'key' => 255,
        ],
        'shipping_methods' => [
            'title' => 255,
            'type' => 255,
        ],
        'shipping_zone_locations' => [
            'code' => 255,
            'type' => 255,
        ],
        'shipping_zones' => [
            'name' => 255,
        ],
        'stock_alerts' => [
            'email' => 191,
            'slot' => 32,
        ],
        'subscribers' => [
            'email' => 160,
            'source' => 40,
            'status' => 20,
        ],
        'tags' => [
            'name' => 255,
            'slug' => 255,
        ],
        'tax_rates' => [
            'country' => 2,
            'name' => 255,
            'state' => 255,
        ],
        'translations' => [
            'field' => 64,
            'group' => 32,
            'locale' => 5,
            'source' => 10,
            'source_hash' => 40,
            'status' => 10,
        ],
        'update_releases' => [
            'archive_path' => 255,
            'backup_id' => 255,
            'name' => 255,
            'status' => 255,
            'superseded_by' => 255,
            'version' => 255,
        ],
        'users' => [
            'email' => 255,
            'name' => 255,
            'password' => 255,
            'remember_token' => 100,
        ],
    ];

    /** @var list<string> */
    private static array $violations = [];

    private static bool $watching = false;

    /**
     * Attach the guard to the current connection.
     *
     * Called once per test from tests/Pest.php. Laravel builds a fresh
     * application for each test, so the listener does not accumulate.
     */
    public static function watch(): void
    {
        self::$violations = [];
        self::$watching = true;

        DB::listen(static function ($query): void {
            if (! self::$watching) {
                return;
            }

            foreach (self::inspect($query->sql, $query->bindings) as $problem) {
                self::$violations[] = $problem;
            }
        });
    }

    /** @return list<string> */
    public static function violations(): array
    {
        return array_values(array_unique(self::$violations));
    }

    /**
     * Forget what has been seen.
     *
     * tests/Pest.php calls this after asserting. A test that writes an
     * over-wide value ON PURPOSE calls it too, so its own demonstration does
     * not fail the run.
     */
    public static function reset(): void
    {
        self::$violations = [];
    }

    /**
     * Every over-wide string this statement writes.
     *
     * Pure, so ColumnWidthGuardTest can drive it with a statement it wrote by
     * hand and prove the rule asserts something without a database.
     *
     * @param  array<int, mixed>  $bindings
     * @return list<string>
     */
    public static function inspect(string $sql, array $bindings): array
    {
        $columns = self::columnsForBindings($sql);

        if ($columns === null) {
            return [];
        }

        [$table, $ordered] = $columns;

        $widths = self::WIDTHS[$table] ?? null;

        if ($widths === null) {
            return [];
        }

        $problems = [];

        foreach ($ordered as $position => $column) {
            $value = $bindings[$position] ?? null;

            if (! is_string($value)) {
                continue;
            }

            $width = $widths[$column] ?? null;

            if ($width === null) {
                continue;
            }

            $length = mb_strlen($value, 'UTF-8');

            if ($length <= $width) {
                continue;
            }

            $problems[] = sprintf(
                '%s.%s is %s(%d) and was written %d characters: MySQL answers '
                . 'SQLSTATE[22001] 1406 "Data too long", SQLite stores it whole',
                $table,
                $column,
                $width === 36 ? 'char' : 'varchar',
                $width,
                $length
            );
        }

        return $problems;
    }

    /**
     * Which column each binding lands in, or null when that cannot be known.
     *
     * @return array{0: string, 1: array<int, string>}|null
     */
    private static function columnsForBindings(string $sql): ?array
    {
        $head = ltrim($sql);

        $isInsert = stripos($head, 'insert') === 0;
        $isUpdate = stripos($head, 'update') === 0;

        if (! $isInsert && ! $isUpdate) {
            return null;
        }

        // An upsert's trailing assignments carry their own placeholders, and
        // working out how many is exactly the kind of guess this class refuses.
        if (stripos($head, 'on duplicate key update') !== false) {
            return null;
        }

        $placeholders = substr_count($head, '?');

        if ($placeholders === 0) {
            return null;
        }

        if ($isInsert) {
            if (preg_match('/^insert(?:\s+or\s+\w+)?(?:\s+ignore)?\s+into\s+[`"]?([a-z_0-9]+)[`"]?\s*\(([^)]*)\)/i', $head, $m) !== 1) {
                return null;
            }

            $table = $m[1];
            $names = self::names($m[2]);

            if ($names === [] || $placeholders % count($names) !== 0) {
                return null;
            }

            $ordered = [];

            for ($i = 0; $i < $placeholders; $i++) {
                $ordered[$i] = $names[$i % count($names)];
            }

            return [$table, $ordered];
        }

        if (preg_match('/^update\s+[`"]?([a-z_0-9]+)[`"]?\s+set\s+(.*)$/is', $head, $m) !== 1) {
            return null;
        }

        $table = $m[1];
        $set = $m[2];

        if (preg_match('/\swhere\s/i', $set, $w, PREG_OFFSET_CAPTURE) === 1) {
            $set = substr($set, 0, $w[0][1]);
        }

        $inSet = substr_count($set, '?');

        if ($inSet === 0) {
            return null;
        }

        if (preg_match_all('/[`"]([a-z_0-9]+)[`"]\s*=\s*\?/i', $set, $mm) !== $inSet) {
            // A placeholder somewhere other than a plain `col` = ? — an
            // expression, a JSON path, a CASE. The mapping is not knowable.
            return null;
        }

        $ordered = [];

        foreach ($mm[1] as $i => $name) {
            $ordered[$i] = $name;
        }

        return [$table, $ordered];
    }

    /**
     * The column names out of an INSERT's parenthesised list.
     *
     * @return list<string>
     */
    private static function names(string $list): array
    {
        $names = [];

        foreach (explode(',', $list) as $raw) {
            $name = trim($raw, " \t\n\r`\"[]");

            if ($name === '' || preg_match('/^[a-z_0-9]+$/i', $name) !== 1) {
                return [];
            }

            $names[] = $name;
        }

        return $names;
    }
}
