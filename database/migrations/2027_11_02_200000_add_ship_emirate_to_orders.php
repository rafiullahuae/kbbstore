<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane EM: `orders.ship_emirate` -- the emirate an order went to, stored once
 * so Store -> Orders and the owner app can count and filter by it with one
 * GROUP BY on an indexed column. 'Dubai' ... 'Fujairah' and 'Al Ain' (its own
 * bucket, the owner's ALAIN), '' when no UAE emirate can be read, NULL when not
 * read yet.
 *
 * LITERALS AND PRE-EXISTING APIs ONLY. The updater runs this in a request where
 * opcache can still serve the OLD classes (2.60.512 rolled back on a constant
 * only the new class had), so the reading rule below is a literal copy of
 * App\Support\OrderEmirate::read() and the only class it calls is
 * App\Support\AddressRegions, which is on the server already and unchanged by
 * this package. OrderEmirateTest pins that the two copies agree and that no
 * migration names the new class.
 *
 * Safe to run twice: the column is added only when missing, and the backfill
 * only touches rows still NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        if (! Schema::hasColumn('orders', 'ship_emirate')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('ship_emirate', 32)->nullable();
                $table->index('ship_emirate', 'orders_ship_emirate_index');
            });
        }

        // Chunked by id; one UPDATE per distinct answer per chunk.
        $last = 0;
        $filled = 0;

        while (true) {
            $rows = DB::table('orders')
                ->where('id', '>', $last)
                ->whereNull('ship_emirate')
                ->orderBy('id')
                ->limit(1000)
                ->get(['id', 'shipping_address', 'billing_address']);

            if ($rows->isEmpty()) {
                break;
            }

            $groups = [];
            foreach ($rows as $row) {
                $groups[$this->emirateFor($row->shipping_address, $row->billing_address)][] = (int) $row->id;
                $last = (int) $row->id;
            }

            foreach ($groups as $value => $ids) {
                foreach (array_chunk($ids, 500) as $part) {
                    DB::table('orders')->whereIn('id', $part)->whereNull('ship_emirate')
                        ->update(['ship_emirate' => (string) $value]);
                }
            }

            $filled += $rows->count();
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Order emirates. Read {$filled} orders.\n";
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'ship_emirate')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex('orders_ship_emirate_index');
                $table->dropColumn('ship_emirate');
            });
        }
    }

    /**
     * The literal copy of OrderEmirate::read(). Public so the test can hold
     * the two side by side.
     */
    public function emirateFor(mixed $shipping, mixed $billing): string
    {
        $decode = static function (mixed $v): array {
            if (is_array($v)) {
                return $v;
            }
            if (is_object($v)) {
                return (array) $v;
            }
            if (! is_string($v) || trim($v) === '') {
                return [];
            }
            $d = json_decode($v, true);

            return is_array($d) ? $d : [];
        };
        $text = static function (array $a, string $k): string {
            $v = $a[$k] ?? '';

            return is_scalar($v) ? trim((string) $v) : '';
        };
        $country = static function (array $a) use ($text): string {
            $c = strtoupper($text($a, 'country'));

            return in_array($c, ['UAE', 'U.A.E', 'U.A.E.', 'UNITED ARAB EMIRATES'], true) ? 'AE' : $c;
        };

        $ship = $decode($shipping);
        $bill = $decode($billing);

        $c = $country($ship);
        if ($c === '') {
            $c = $country($bill);
        }
        if ($c !== '' && $c !== 'AE') {
            return '';
        }

        foreach ([$ship, $bill] as $a) {
            if ($a === [] || ! in_array($country($a), ['', 'AE'], true)) {
                continue;
            }

            $emirate = \App\Support\AddressRegions::guess('AE', $text($a, 'state'), $text($a, 'city'));

            if ($emirate === null || $emirate === 'Abu Dhabi') {
                foreach (['city', 'state', 'line2', 'line1'] as $field) {
                    if (preg_match('/(?<![\p{L}\p{N}])al[\s\-_]*ain(?![\p{L}\p{N}])|العين/iu', $text($a, $field)) === 1) {
                        return 'Al Ain';
                    }
                }
            }

            if ($emirate !== null) {
                return $emirate;
            }
        }

        return '';
    }
};
